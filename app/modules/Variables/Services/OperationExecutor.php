<?php

namespace App\Modules\Variables\Services;

use App\Modules\Variables\DTOs\OperationResult;
use App\Modules\Variables\Enums\Operation;
use App\Modules\Variables\Enums\PipelineLimits;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Support\CustomFunctionOperation;
use App\Modules\Variables\Support\FunctionScope;
use App\Modules\Variables\Support\ScopeRef;
use App\Modules\Variables\Support\UnresolvedArgument;
use App\Modules\Variables\Support\ValueOrVariable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Throwable;

/**
 * The ONE runtime engine for a variable-operation PIPELINE — the single source of truth for the 77
 * operations (mirroring standardOperations.ts). It was extracted from WorkflowConditionEngine so
 * every consumer shares identical semantics and can never drift:
 *
 *   - WorkflowConditionEngine feeds a condition's pipeline and reads the boolean terminal.
 *   - VariableResolver feeds a directive / if-block / value-or-variable pipeline and reads
 *     the transformed value (typed for structured fields, stringified for text fields).
 *
 * INPUT SHAPE: a pipeline is a list of steps `{op, args}`. For portability with the next editor's
 * serialization the op id is read from `op` OR `operationId` (the editor's VariablePipelineStep
 * carries `operationId` + `stepId` + `outputType`; the extra keys are ignored — the enum owns the
 * real input/output types, so a degraded/advisory `outputType` on the wire never overrides them).
 *
 * FAIL-CLOSED DOCTRINE (never throws): an unknown op, an op whose input type ≠ the running type, an
 * unparseable number/date, a divide-by-zero, an unmapped enum option, a non-array step, or a
 * pipeline over the hard cap → an OperationResult::failure(). A base value that cannot be normalized
 * to its declared type is likewise a failure. TOTALITY (safety-hardening, see the ADR-0022
 * amendment): the doctrine holds for EVERY VariableType, not just the 7 this engine has
 * semantics for — a DESCRIPTOR-ONLY base type (`time`/`object`) fails the pipeline closed instead of
 * raising an UnhandledMatchError (normalizeInput's default arm + the UNSUPPORTED_BASE check in
 * execute), and an ARRAY-shaped argument that is really an unresolved value-or-variable UNION is
 * REJECTED as malformed instead of being consumed as data (the arrayArg reader). The same reader also
 * rejects the UnresolvedArgument MARKER — a STRUCTURAL arg-variable that resolved to nothing — which
 * previously arrived as a null that `?? $whenAbsent` degraded into match_to_choice's "no rules", so an
 * unresolvable `rules` ref returned the fallback as a SUCCESS and OPENED a condition gate.
 *
 * PRESENCE FAMILY (append-only, phase-1b): coalesce/is_present/is_null/assert_present are the ONE
 * exception to the per-step type gate — they accept ANY running type and read emptiness (null/''/[]).
 * A leading presence op even consumes an unnormalizable/absent base (which a normal op fails closed
 * on). assert_present over an empty value returns an OperationResult::hardFailure() — still a failure
 * (so conditions stay false), but the ONE signal a value-producing caller re-raises as a run
 * step-failure. The executor itself STILL never throws.
 *
 * OPERATION SEMANTICS (the 77 ops):
 *   - text_to_number: non-numeric fails (never coerces to 0). text_substring: `start` 1-based,
 *     `length` 0 = to the end. contains/starts_with/ends_with with an empty needle are false.
 *   - num_divide by zero fails. num ops use float compare (=== on floats).
 *   - bool_to_number / bool_to_text yield the when_true/when_false arg.
 *   - enum_to_* map the option value to the entered target; an unmapped option (or an unparseable
 *     mapped number/date) fails. multi_to_text joins the SELECTED option VALUES with ", ".
 *   - CHOICE terminals (map a value into a destination field's option set, e.g. task priority):
 *     enum_to_choice is enum_to_* with a string (option) target — an unmapped source option fails
 *     closed. match_to_choice maps a TEXT value by the first rule whose `when` PIPELINE — a
 *     boolean-terminal pipeline run over the op's own TEXT input — evaluates TRUE, returning that
 *     rule's `then`, else a REQUIRED `fallback` option. Every way a `when` cannot be evaluated (a
 *     malformed rule / non-pipeline `when` / a choice op INSIDE a `when` / a failed sub-run / a
 *     non-boolean terminal) and a matched-rule non-scalar `then` fail the op CLOSED — it NEVER falls
 *     through to the fallback on an unevaluable condition (that would OPEN a gate). A missing/blank
 *     fallback also fails closed (the op is total over its input).
 *   - dates are STRICT ISO Y-m-d wall-clock (anything else fails); date_weekday is 0=Sunday..6.
 *     date_is_past/future/weekend compare against "today" in config('app.timezone').
 *   - coalesce → the input when present, else a `fallback` literal (normalized to the running type).
 *     is_present/is_null → boolean. assert_present → the input, else a HARD failure. date_format
 *     renders a date via a SAFE token pattern (YYYY/MM/DD/D/MMMM/MMM/HH/mm + a few separators) — a
 *     raw PHP format string is never honored; a bad pattern / non-date is fail-soft (never a throw).
 */
class OperationExecutor
{
    /** An operation/argument failure marker — collapses to an OperationResult::failure(), never an exception. */
    private const FAIL = "\0__workflow_operation_failed__\0";

    /**
     * A HARD failure marker (append-only) — collapses to OperationResult::hardFailure(), which a
     * value-producing caller re-raises as a run step-failure. Raised ONLY by assert_present over an
     * empty value; still not an exception (the executor stays throw-free).
     */
    private const HARD_FAIL = "\0__workflow_operation_hard_failed__\0";

    /**
     * A DECLARED BASE TYPE this engine has no runtime semantics for (a descriptor-only
     * VariableType — today `time`/`object`). Distinct from FAIL because it must NOT be read as
     * "an empty value" by the presence family: an unrepresentable TYPE fails the whole pipeline closed
     * (see execute), where an unrepresentable VALUE of a supported type still legitimately answers
     * is_null. Raised only by normalizeInput's default arm; never an exception.
     */
    private const UNSUPPORTED_BASE = "\0__workflow_operation_unsupported_base__\0";

    /** Distinct sentinel so a genuine null subfield is not read as "missing" (readElementSubfield). */
    private const MISSING = "\0__workflow_operation_missing__\0";

    /**
     * The file-element SUBFIELD aliases whose snapshot key differs from the subfield name (array-ops
     * wave 3). Only `type` aliases (→ `mime_type`); id/name/size/url are identity and resolve by a plain
     * read. Mirrors VariableResolver::FILE_SUBFIELDS (the single value-access mapping) over the
     * SAME key set — kept here too because the executor resolves an element pipeline's `element.<sub>`
     * scope refs off the raw snapshot itself, without a resolver/context handle.
     */
    private const FILE_SUBFIELD_KEYS = [
        'type' => 'mime_type',
    ];

    /**
     * The DATE_FORMAT safe-token whitelist (append-only): editor tokens → PHP date() tokens. Ordered
     * LONGEST-FIRST so the greedy matcher never takes a shorter prefix (MMMM before MMM before MM, DD
     * before D). This is the ONLY date-formatting surface — a raw PHP format string is NEVER honored.
     */
    private const DATE_FORMAT_TOKENS = [
        'YYYY' => 'Y',
        'MMMM' => 'F',
        'MMM' => 'M',
        'MM' => 'm',
        'DD' => 'd',
        'HH' => 'H',
        'mm' => 'i',
        'D' => 'j',
    ];

    /** Literal separators DATE_FORMAT allows between tokens (escaped through to date() verbatim). */
    private const DATE_FORMAT_SEPARATORS = [' ', '-', '/', ':', '.', ','];

    /**
     * The base LITERAL types a reduce SEED may declare (array-ops wave 2). A seed is a self-describing
     * `{type, value}` whose type is one of these four — an array/enum seed (which would need options /
     * element handling) is out of scope this wave and fails the op closed.
     */
    private const REDUCE_SEED_TYPES = [
        VariableType::TEXT,
        VariableType::NUMBER,
        VariableType::BOOLEAN,
        VariableType::DATE,
    ];

    /**
     * Resolves a step's op id to its definition — a built-in {@see Operation} OR a workspace custom
     * FUNCTION ({@see CustomFunctionOperation}) — so this pure engine can EXECUTE a `fn:<uuid>` op by
     * expansion (Phase 3b). The workspace's functions are NOT held here (that would make the executor
     * stateful/impure); they ride the run CONTEXT under {@see FunctionScope}, so every resolve() call is
     * fed the functions for the pipeline in flight. Defaulted so the many direct `new OperationExecutor`
     * unit sites (which drive only built-in pipelines) keep constructing with no args — a bare id then
     * resolves to a built-in and a `fn:` id to null (fail-closed), byte-identical to the old tryFrom.
     */
    private OperationResolver $operations;

    public function __construct(?OperationResolver $operations = null)
    {
        $this->operations = $operations ?? new OperationResolver;
    }

    /**
     * Run $pipeline over $baseValue (declared as $baseType), returning the transformed value + its
     * terminal type, or a fail-closed failure. $context is reserved for the evaluation environment
     * (e.g. a pinned "now"); the relative date predicates currently read Carbon's clock in the app
     * timezone directly, so it is accepted for forward-compat and passed through untouched.
     *
     * $elementDepth is the PER-ELEMENT-PIPELINE nesting level (0 at a top-level call); a collection op
     * re-enters execute() one level deeper per element, and a run beyond MAX_ELEMENT_PIPELINE_DEPTH fails
     * that op closed. External callers never pass it.
     *
     * @param  array<int, mixed>  $pipeline  list of {op|operationId, args} steps
     * @param  array<string, mixed>  $context
     */
    public function execute(mixed $baseValue, VariableType $baseType, array $pipeline, array $context = [], int $elementDepth = 0): OperationResult
    {
        if (count($pipeline) > PipelineLimits::MAX_PIPELINE_STEPS) {
            return OperationResult::failure();
        }

        $value = $this->normalizeInput($baseValue, $baseType);

        // A base type this engine has NO runtime semantics for collapses the WHOLE pipeline, ahead of
        // the presence bypass below: an unrepresentable TYPE must never be reported as "empty" (which
        // would let is_null answer boolean TRUE and OPEN a condition gate on a type the engine cannot
        // even read). Fail-soft — the caller sees an ordinary failure, never an exception.
        if ($value === self::UNSUPPORTED_BASE) {
            return OperationResult::failure();
        }

        // A LEADING presence op (coalesce/is_present/is_null/assert_present) reads EMPTINESS, which
        // legitimately includes the null/'' base a normal op fails closed on — so DEFER the fail-closed
        // return for it (it consumes the raw emptiness below). Every OTHER leading op keeps the exact
        // legacy behavior: an unnormalizable base is still an immediate failure.
        if ($value === self::FAIL && !$this->leadsWithPresenceOp($pipeline)) {
            return OperationResult::failure();
        }

        $currentType = $baseType;

        // The custom-function execution state (workspace functions + expansion depth + active-function
        // chain + the current body frame) for this run, read ONCE off the context. Empty (no functions)
        // for every external caller that threads none — a `fn:` id then resolves to null → fail closed.
        $scope = FunctionScope::fromContext($context);

        foreach ($pipeline as $step) {
            if (!is_array($step)) {
                return OperationResult::failure();
            }

            // Resolve through the OperationResolver (built-ins ∪ the context's custom functions) rather
            // than a bare Operation::tryFrom, so a `fn:<uuid>` step resolves to its function at runtime.
            $op = $this->operations->resolve((string) ($step['op'] ?? $step['operationId'] ?? ''), $scope->functions);

            if ($op === null) {
                return OperationResult::failure();
            }

            $args = is_array($step['args'] ?? null) ? $step['args'] : [];

            // A CUSTOM FUNCTION is executed BY EXPANSION — bind {input + args} into a scope frame and
            // re-enter the executor on its body, one level deeper. Handled BEFORE the built-in-only paths
            // (presence/collection/apply all match on the Operation enum). It honours the same input gate
            // as a generic op: a FAIL base, or an input type that is not the running type, fails closed.
            if ($op instanceof CustomFunctionOperation) {
                if ($value === self::FAIL || $op->inputType() !== $currentType) {
                    return OperationResult::failure();
                }

                $expanded = $this->expandFunction($op, $value, $currentType, $args, $scope, $elementDepth);

                if ($expanded === self::FAIL) {
                    return OperationResult::failure();
                }

                [$value, $currentType] = $expanded;

                continue;
            }

            // Every remaining op is a built-in enum; narrow defensively so the enum-only paths below are
            // sound (the resolver only ever returns an Operation or a CustomFunctionOperation).
            if (!$op instanceof Operation) {
                return OperationResult::failure();
            }

            // Presence-family ops accept ANY running type (they inspect presence, not shape) — handled
            // before the strict type gate. They preserve the running type (coalesce/assert_present) or
            // yield boolean (is_present/is_null); assert_present over an empty value is the ONE HARD
            // failure a value-producing caller escalates to a run step-failure.
            if ($op->isPresenceOp()) {
                $applied = $this->applyPresence($op, $value, $currentType, $args);

                if ($applied === self::HARD_FAIL) {
                    return OperationResult::hardFailure();
                }

                if ($applied === self::FAIL) {
                    return OperationResult::failure();
                }

                [$value, $currentType] = $applied;

                continue;
            }

            // A HIGHER-ORDER array op (map/filter/sort/reduce) runs a PER-ELEMENT PIPELINE — it needs the
            // run $context (for the scoped element/index overlay) and the element-depth, neither of which
            // the flat apply() carries, so it is dispatched here BEFORE the generic per-op path. It still
            // honours the array input gate (any array of this wave = a normalized MULTI string[]).
            if ($op->isCollectionOp()) {
                if ($value === self::FAIL || $op->inputType() !== $currentType) {
                    return OperationResult::failure();
                }

                $applied = $this->applyCollection($op, is_array($value) ? $value : [], $args, $context, $elementDepth);

                if ($applied === self::FAIL) {
                    return OperationResult::failure();
                }

                [$value, $currentType] = $applied;

                continue;
            }

            // Any non-presence op meeting a DEFERRED base failure, or a type-flow mismatch, is the
            // legacy fail-closed dead end.
            if ($value === self::FAIL || $op->inputType() !== $currentType) {
                return OperationResult::failure();
            }

            $value = $this->apply($op, $value, $args, $context);

            if ($value === self::FAIL) {
                return OperationResult::failure();
            }

            $currentType = $this->stepOutputType($op, $currentType);
        }

        return OperationResult::success($value, $currentType);
    }

    // ---- input normalization --------------------------------------------------

    /**
     * Coerce a raw value into the canonical PHP shape for its declared type
     * (text/enum → string, number → float, boolean → bool, date → CarbonImmutable, multi → string[]),
     * or FAIL when it cannot be represented as that type. UNSUPPORTED_BASE when the TYPE ITSELF has no
     * runtime representation here (the default arm).
     */
    private function normalizeInput(mixed $value, VariableType $type): mixed
    {
        return match ($type) {
            VariableType::TEXT, VariableType::ENUM => is_scalar($value) ? (string) $value : self::FAIL,
            VariableType::NUMBER => is_numeric($value) ? (float) $value : self::FAIL,
            VariableType::BOOLEAN => $this->toBool($value),
            VariableType::DATE => $this->parseDate($value) ?? self::FAIL,
            VariableType::MULTI => $this->toStringList($value),
            VariableType::FILE => $this->toFileList($value),
            // TOTALITY over the enum — do NOT remove. VariableType carries DESCRIPTOR-ONLY cases
            // past this closed 7-type core (`time`, `object`); the catalog degrades their flat wire type
            // to text, so they are unreachable through a normally-authored workflow. A LEGACY /
            // hand-written / imported row is not: WorkflowConditionEngine::evaluateCondition tryFroms a
            // stored `source_type` RAW, and this match previously raised an UnhandledMatchError straight
            // out of a gate whose contract is that it NEVER throws (a 500 during form submission). It now
            // fails SOFT and the condition collapses to false. Write-time type-safety
            // (WorkflowConditionTreeValidator) remains the PRIMARY guard; this is the backstop.
            // See ADR-0022 (amendment: the tripwire was reachable).
            default => self::UNSUPPORTED_BASE,
        };
    }

    /**
     * A file value as a canonical list of snapshots ({id,name,mime_type,size}). Tolerates the
     * shapes a legacy or hand-written payload can hold — a bare id, a single snapshot — so a
     * pipeline degrades to a sane answer rather than failing the whole condition.
     *
     * @return array<int, array<string, mixed>>
     */
    private function toFileList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $isSnapshot = fn (mixed $item): bool => is_array($item) && array_key_exists('id', $item);

        $items = is_array($value) && !$isSnapshot($value) ? $value : [$value];

        return array_values(array_filter(array_map(
            fn (mixed $item) => match (true) {
                $isSnapshot($item) => $item,
                // A bare id still counts as "a file is here", it just has no name to read.
                is_string($item) && $item !== '' => ['id' => $item],
                default => null,
            },
            $items,
        )));
    }

    /** Lenient truthiness (mirrors the legacy evaluator so 'true'/'1'/1 all read true). */
    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    /**
     * A multi value as a list of ELEMENTS: scalar options become strings; a NON-scalar element (an
     * array<object> repeater row, an array<file> snapshot — array-ops wave 3) is PRESERVED as-is so the
     * higher-order collection ops can iterate the real objects (a scalar-only array is byte-identical to
     * before, when non-scalars degraded to ''). A non-array present value is wrapped as a single-element
     * set; a null/scalar-less value becomes the empty set. Scalar-consuming multi ops (multi_to_text,
     * multi_includes…) stay robust: they stringify only scalars and never match a preserved object.
     *
     * @return array<int, mixed>
     */
    private function toStringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(fn ($v) => is_scalar($v) ? (string) $v : $v, $value));
        }

        return is_scalar($value) ? [(string) $value] : [];
    }

    // ---- operation dispatch ---------------------------------------------------

    /**
     * Apply one operation to the current canonical value, returning the next canonical value or FAIL.
     * $context is threaded through so match_to_choice's re-entrant `when` sub-run inherits the same
     * FunctionScope (a rule condition may itself call a custom function); every other op ignores it.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context
     */
    private function apply(Operation $op, mixed $value, array $args, array $context = []): mixed
    {
        return match ($op->inputType()) {
            VariableType::TEXT => $this->applyText($op, (string) $value, $args, $context),
            VariableType::NUMBER => $this->applyNumber($op, (float) $value, $args),
            VariableType::BOOLEAN => $this->applyBoolean($op, (bool) $value, $args),
            VariableType::DATE => $this->applyDate($op, $value, $args),
            VariableType::ENUM => $this->applyEnum($op, (string) $value, $args),
            VariableType::MULTI => $this->applyMulti($op, is_array($value) ? $value : [], $args),
            VariableType::FILE => $this->applyFile($op, is_array($value) ? $value : [], $args),
        };
    }

    /**
     * The running type AFTER a generic (non-presence, non-collection) op. For an O(1) ARRAY op —
     * array_count / array_at, the two isArrayOp members that do NOT run a per-element pipeline — the
     * output type is DERIVED from the input array's descriptor via the SAME outputDescriptor() the
     * write-validator and the FE walker use, then inverted with fromDescriptor(). This is the fix for the
     * descriptor-drift the review found (Finding A): array_at's STATIC outputType() is TEXT, but the
     * walker/FE type it as the input array's ELEMENT base — ENUM for a MULTI. Deriving it here makes the
     * runtime AGREE by construction, so a DIRECT `<multi source> |> array_at |> enum_is` both validates
     * and RUNS (the running type stays ENUM), where before it type-mismatched at runtime (ENUM op meets a
     * TEXT-typed value) and silently failed closed. array_count stays NUMBER; every non-array op keeps its
     * exact static outputType() (a provable no-op — outputDescriptor's default arm IS outputType()).
     *
     * KNOWN LIMITATION (deferred, honest fail-CLOSED): the executor collapses EVERY array to a normalized
     * MULTI/string[] and cannot recover a NON-ENUM scalar element base produced MID-PIPELINE by `map`
     * (e.g. map → array<text>). So `map |> array_at |> text_op` stays typed ENUM here and mismatches the
     * walker's TEXT — it fails CLOSED at runtime rather than mis-running. The global enum/text type gate
     * is deliberately NOT relaxed to paper over this (that would change existing semantics); only the
     * DIRECT multi-source case is made correct. Recovering a map-produced scalar element base at runtime
     * is a follow-up (it needs descriptor tracking the pure executor does not carry).
     */
    private function stepOutputType(Operation $op, VariableType $inputType): VariableType
    {
        if ($op->isArrayOp()) {
            return VariableType::fromDescriptor($op->outputDescriptor($inputType->descriptor()));
        }

        return $op->outputType();
    }

    // ---- presence family (append-only) ----------------------------------------

    /** Whether the pipeline OPENS with a presence-family op (which tolerates an empty/absent base). */
    private function leadsWithPresenceOp(array $pipeline): bool
    {
        $first = $pipeline[0] ?? null;

        if (!is_array($first)) {
            return false;
        }

        // A presence op is always a BUILT-IN, so no functions are needed to recognize one (resolving
        // against the empty set keeps a leading `fn:` id null → not a presence op → no empty-base defer,
        // which is correct: a function cannot run over an unnormalizable base). Routed through the
        // resolver so the engine holds ZERO direct Operation::tryFrom (the completeness gate).
        $op = $this->operations->resolve((string) ($first['op'] ?? $first['operationId'] ?? ''), []);

        return $op !== null && $op->isPresenceOp();
    }

    /**
     * A presence-family op. It reads the running value's EMPTINESS — null, '', [] or an unrepresentable
     * base (the FAIL marker) all count as empty, matching the FILLED/EMPTY condition semantics — and
     * accepts ANY running type:
     *   - is_present / is_null → boolean.
     *   - coalesce  → the value when present, else the `fallback` literal normalized to the running type.
     *   - assert_present → the value when present, else HARD_FAIL (the one opt-in "force a value").
     * Returns [value, type] on success, or the FAIL / HARD_FAIL sentinel.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: mixed, 1: VariableType}|string
     */
    private function applyPresence(Operation $op, mixed $value, VariableType $currentType, array $args): array|string
    {
        $empty = $this->isEmptyValue($value);

        return match ($op) {
            Operation::IS_PRESENT => [!$empty, VariableType::BOOLEAN],
            Operation::IS_NULL => [$empty, VariableType::BOOLEAN],
            Operation::ASSERT_PRESENT => $empty ? self::HARD_FAIL : [$value, $currentType],
            Operation::COALESCE => $empty ? $this->coalesceFallback($args, $currentType) : [$value, $currentType],
            default => self::FAIL,
        };
    }

    /** Emptiness for the presence family: null, '', [] or an unrepresentable base (the FAIL marker). */
    private function isEmptyValue(mixed $value): bool
    {
        return $value === self::FAIL || $value === null || $value === '' || $value === [];
    }

    /**
     * The coalesce `fallback` (a literal) normalized to the running type, so a downstream typed op sees
     * a proper T. A non-scalar or unrepresentable fallback fails closed.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: mixed, 1: VariableType}|string
     */
    private function coalesceFallback(array $args, VariableType $currentType): array|string
    {
        $fallback = $args['fallback'] ?? null;

        if (!is_scalar($fallback)) {
            return self::FAIL;
        }

        $normalized = $this->normalizeInput($fallback, $currentType);

        // $currentType is always a runtime-supported type here (execute() rejects an unsupported BASE
        // up front and every op's outputType is one of the core seven), so UNSUPPORTED_BASE is
        // belt-and-braces: a sentinel must never escape into a value.
        if ($normalized === self::FAIL || $normalized === self::UNSUPPORTED_BASE) {
            return self::FAIL;
        }

        return [$normalized, $currentType];
    }

    /**
     * File ops. Two boolean terminals plus two converters that hand the value to the existing
     * text/number vocabulary — so "is it a PDF" is file_name -> text_ends_with, and "more than
     * one" is file_count -> num_gt, with no file-specific comparison ops to maintain.
     *
     * @param  array<int, array<string, mixed>>  $files  canonical snapshot list
     * @param  array<string, mixed>  $args
     */
    private function applyFile(Operation $op, array $files, array $args): mixed
    {
        return match ($op) {
            Operation::FILE_IS_EMPTY => $files === [],
            Operation::FILE_IS_NOT_EMPTY => $files !== [],
            Operation::FILE_COUNT => (float) count($files),
            // The names, comma-joined — mirrors multi_to_text's shape for a set-valued source.
            Operation::FILE_NAME => implode(', ', array_values(array_filter(array_map(
                fn (array $file) => (string) ($file['name'] ?? ''),
                $files,
            ), fn (string $name) => $name !== ''))),
            default => self::FAIL,
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context  threaded to match_to_choice's `when` sub-run (FunctionScope)
     */
    private function applyText(Operation $op, string $v, array $args, array $context = []): mixed
    {
        return match ($op) {
            Operation::TEXT_UPPERCASE => mb_strtoupper($v),
            Operation::TEXT_LOWERCASE => mb_strtolower($v),
            Operation::TEXT_TRIM => trim($v),
            Operation::TEXT_LENGTH => (float) mb_strlen($v),
            Operation::TEXT_TO_NUMBER => is_numeric($v) ? (float) $v : self::FAIL,
            Operation::TEXT_IS_EMPTY => $v === '',
            Operation::TEXT_IS_NOT_EMPTY => $v !== '',
            Operation::TEXT_SUBSTRING => $this->textSubstring($v, $args),
            Operation::TEXT_REPLACE => $this->textReplace($v, $args),
            Operation::TEXT_APPEND => $this->withStringArg($args, 'value', fn (string $s) => $v . $s),
            Operation::TEXT_PREPEND => $this->withStringArg($args, 'value', fn (string $s) => $s . $v),
            Operation::TEXT_EQUALS => $this->withStringArg($args, 'value', fn (string $s) => $v === $s),
            Operation::TEXT_NOT_EQUALS => $this->withStringArg($args, 'value', fn (string $s) => $v !== $s),
            Operation::TEXT_CONTAINS => $this->withStringArg($args, 'value', fn (string $s) => $s !== '' && str_contains($v, $s)),
            Operation::TEXT_STARTS_WITH => $this->withStringArg($args, 'value', fn (string $s) => $s !== '' && str_starts_with($v, $s)),
            Operation::TEXT_ENDS_WITH => $this->withStringArg($args, 'value', fn (string $s) => $s !== '' && str_ends_with($v, $s)),
            Operation::MATCH_TO_CHOICE => $this->matchToChoice($v, $args, $context),
            default => self::FAIL,
        };
    }

    /**
     * Map a text value to a target CHOICE by the FIRST rule whose `when` PIPELINE evaluates TRUE,
     * returning that rule's `then`, else the REQUIRED `fallback` option. Each rule's `when` is a
     * pipeline `{op, args}[]` run over THIS op's own TEXT input `$v` (its variable-shaped op-args were
     * already pre-resolved to literals by the resolver, so this re-entrant run needs no context) and
     * MUST terminate in BOOLEAN.
     *
     * FAIL-CLOSED, symmetric with the enumMap/`then` doctrine — an unevaluable condition NEVER falls
     * through to the fallback (which would OPEN a gate — the Defect-3 / C1 fail-open class):
     *   - a non-array `rules` (union / unresolved-arg marker) fails;
     *   - a non-array rule, or a rule whose `when` is not a pipeline, fails;
     *   - a `when` containing ANY producesChoice op fails (defence-in-depth: it can never re-enter
     *     matchToChoice, so the re-entrant execute() is bounded even for an INVALID config the
     *     validator should have rejected);
     *   - a `when` sub-run that FAILED (unknown op / type mismatch / unresolvable / assert_present hard
     *     fail) or whose terminal is NON-boolean fails;
     *   - a MATCHED rule whose `then` is NON-scalar (only ever an UNRESOLVABLE per-rule variable — a
     *     literal null `then` is rejected at write time) fails, exactly as before.
     * A missing/blank fallback fails closed (the op must be total over its input). Output is a choice
     * string (an option value of the destination field).
     *
     * The re-entrant `when` sub-run inherits the caller's FunctionScope (via $context) so a rule condition
     * may itself call a custom function — resolved + expanded under the SAME active-function chain + depth
     * cap, never a loop. With no functions threaded this is byte-identical to a context-less sub-run.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context
     */
    private function matchToChoice(string $v, array $args, array $context = []): mixed
    {
        $fallback = $args['fallback'] ?? null;

        if (!is_string($fallback) || $fallback === '') {
            return self::FAIL;
        }

        // A LITERAL absent / null `rules` still means "no rules" (the fallback is then total over the
        // input — the semantic ADR-0022 pins). A UNION-shaped one, or the marker a structural
        // arg-variable that resolved to NOTHING now carries, is malformed and fails closed rather than
        // silently yielding the fallback (which would OPEN a condition gate).
        $rules = $this->arrayArg($args, 'rules', whenAbsent: []);

        if ($rules === self::FAIL) {
            return self::FAIL;
        }

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                return self::FAIL; // malformed rule → fail CLOSED (never a silent skip that opens the gate)
            }

            $when = $rule['when'] ?? null;
            $then = $rule['then'] ?? null;

            if (!is_array($when)) {
                return self::FAIL; // `when` must be a boolean-terminal pipeline → fail CLOSED
            }

            // Defence-in-depth: a choice op inside a `when` would let the sub-run re-enter
            // matchToChoice (unbounded recursion) on an INVALID config. Reject BEFORE running it so the
            // re-entrant execute() below is bounded even when the validator was bypassed.
            if ($this->pipelineHasChoiceOp($when)) {
                return self::FAIL;
            }

            // Sub-run over the op's own TEXT input; the `when` ops' args are already literals. It carries
            // ONLY the FunctionScope (a fresh context — no trigger/steps/globals/element scope), so a `fn:`
            // op in a rule condition resolves + expands under the caller's depth/visited chain, while a
            // function-less `when` behaves exactly as a context-less sub-run.
            $res = $this->execute($v, VariableType::TEXT, $when, FunctionScope::fromContext($context)->writeInto([]));

            if ($res->failed) {
                return self::FAIL; // unevaluable condition (incl. assert_present hard fail) → fail CLOSED
            }

            if ($res->type !== VariableType::BOOLEAN) {
                return self::FAIL; // non-boolean terminal → fail CLOSED
            }

            if ($res->value === true) {
                // First TRUE rule wins. A MATCHED rule whose `then` is NON-scalar fails the op CLOSED
                // (symmetric with enumMap), never falling through to the fallback: a non-scalar `then`
                // reaching the executor is ONLY ever an UNRESOLVABLE per-rule variable (a literal null
                // `then` is rejected at write time), so failing closed cannot break any valid literal
                // config and prevents an unanswered optional source from OPENING a condition gate.
                return is_scalar($then) ? (string) $then : self::FAIL;
            }
            // false → next rule
        }

        return $fallback;
    }

    /**
     * Whether any step of $pipeline is a CHOICE-producing op (match_to_choice / enum_to_choice). Reads
     * the op id from `op` OR the editor's `operationId` (like execute). Used by matchToChoice to reject
     * a `when` that could re-enter the choice machinery — the ONE guard that keeps the re-entrant
     * sub-run bounded for an invalid config the validator should have rejected. Defence in depth.
     *
     * @param  array<int, mixed>  $pipeline
     */
    private function pipelineHasChoiceOp(array $pipeline): bool
    {
        foreach ($pipeline as $step) {
            if (!is_array($step)) {
                continue;
            }

            // A choice-producing op is always a BUILT-IN (a custom function never producesChoice), so no
            // functions are needed here (a `fn:` id resolves null → not a choice op → left for the bounded
            // sub-run). Routed through the resolver so no direct Operation::tryFrom survives in the engine.
            $op = $this->operations->resolve((string) ($step['op'] ?? $step['operationId'] ?? ''), []);

            if ($op !== null && $op->producesChoice()) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $args */
    private function textSubstring(string $v, array $args): mixed
    {
        $start = $args['start'] ?? null;
        $length = $args['length'] ?? null;

        if (!is_numeric($start) || !is_numeric($length)) {
            return self::FAIL;
        }

        $offset = max(0, (int) $start - 1); // `start` is 1-based
        $len = (int) $length;

        return $len > 0 ? mb_substr($v, $offset, $len) : mb_substr($v, $offset); // 0 = to the end
    }

    /** @param array<string, mixed> $args */
    private function textReplace(string $v, array $args): mixed
    {
        $search = $args['search'] ?? null;
        $replace = $args['replace'] ?? null;

        if (!is_scalar($search) || !is_scalar($replace)) {
            return self::FAIL;
        }

        return str_replace((string) $search, (string) $replace, $v);
    }

    /** @param array<string, mixed> $args */
    private function applyNumber(Operation $op, float $v, array $args): mixed
    {
        return match ($op) {
            Operation::NUM_ABS => abs($v),
            Operation::NUM_FLOOR => floor($v),
            Operation::NUM_CEIL => ceil($v),
            Operation::NUM_TO_TEXT => $this->floatToText($v),
            Operation::NUM_ROUND => $this->withNumberArg($args, 'precision', fn (float $p) => round($v, (int) $p)),
            Operation::NUM_ADD => $this->withNumberArg($args, 'value', fn (float $n) => $v + $n),
            Operation::NUM_SUBTRACT => $this->withNumberArg($args, 'value', fn (float $n) => $v - $n),
            Operation::NUM_MULTIPLY => $this->withNumberArg($args, 'value', fn (float $n) => $v * $n),
            Operation::NUM_DIVIDE => $this->withNumberArg($args, 'value', fn (float $n) => $n === 0.0 ? self::FAIL : $v / $n),
            Operation::NUM_EQ => $this->withNumberArg($args, 'value', fn (float $n) => $v === $n),
            Operation::NUM_NEQ => $this->withNumberArg($args, 'value', fn (float $n) => $v !== $n),
            Operation::NUM_GT => $this->withNumberArg($args, 'value', fn (float $n) => $v > $n),
            Operation::NUM_GTE => $this->withNumberArg($args, 'value', fn (float $n) => $v >= $n),
            Operation::NUM_LT => $this->withNumberArg($args, 'value', fn (float $n) => $v < $n),
            Operation::NUM_LTE => $this->withNumberArg($args, 'value', fn (float $n) => $v <= $n),
            Operation::NUM_BETWEEN => $this->withNumberArg(
                $args,
                'from',
                fn (float $from) => $this->withNumberArg($args, 'to', fn (float $to) => $v >= $from && $v <= $to),
            ),
            default => self::FAIL,
        };
    }

    /** Render a float as text without a trailing `.0` for whole numbers (e.g. 42.0 → "42"). */
    private function floatToText(float $v): string
    {
        return $v == (int) $v && is_finite($v) ? (string) (int) $v : (string) $v;
    }

    /** @param array<string, mixed> $args */
    private function applyBoolean(Operation $op, bool $v, array $args): mixed
    {
        return match ($op) {
            Operation::BOOL_NOT => !$v,
            Operation::BOOL_TO_NUMBER => $this->withNumberArg($args, $v ? 'when_true' : 'when_false', fn (float $n) => $n),
            Operation::BOOL_TO_TEXT => $this->withStringArg($args, $v ? 'when_true' : 'when_false', fn (string $s) => $s),
            default => self::FAIL,
        };
    }

    /** @param array<string, mixed> $args */
    private function applyDate(Operation $op, mixed $v, array $args): mixed
    {
        if (!$v instanceof CarbonImmutable) {
            return self::FAIL;
        }

        return match ($op) {
            Operation::DATE_ADD_DAYS => $this->withNumberArg($args, 'value', fn (float $n) => $v->addDays((int) $n)),
            Operation::DATE_SUBTRACT_DAYS => $this->withNumberArg($args, 'value', fn (float $n) => $v->subDays((int) $n)),
            Operation::DATE_ADD_MONTHS => $this->withNumberArg($args, 'value', fn (float $n) => $v->addMonths((int) $n)),
            Operation::DATE_ADD_YEARS => $this->withNumberArg($args, 'value', fn (float $n) => $v->addYears((int) $n)),
            Operation::DATE_START_OF_MONTH => $v->startOfMonth(),
            Operation::DATE_END_OF_MONTH => $v->endOfMonth()->startOfDay(),
            Operation::DATE_DAY => (float) $v->day,
            Operation::DATE_MONTH => (float) $v->month,
            Operation::DATE_YEAR => (float) $v->year,
            Operation::DATE_WEEKDAY => (float) $v->dayOfWeek, // 0=Sunday..6=Saturday
            Operation::DATE_TO_TEXT => $v->format('Y-m-d'),
            Operation::DATE_BEFORE => $this->withDateArg($args, 'value', fn (CarbonImmutable $d) => $v->lessThan($d)),
            Operation::DATE_AFTER => $this->withDateArg($args, 'value', fn (CarbonImmutable $d) => $v->greaterThan($d)),
            Operation::DATE_ON => $this->withDateArg($args, 'value', fn (CarbonImmutable $d) => $v->isSameDay($d)),
            Operation::DATE_BETWEEN => $this->withDateArg(
                $args,
                'from',
                fn (CarbonImmutable $from) => $this->withDateArg(
                    $args,
                    'to',
                    fn (CarbonImmutable $to) => $v->greaterThanOrEqualTo($from) && $v->lessThanOrEqualTo($to),
                ),
            ),
            Operation::DATE_IS_WEEKEND => $v->isWeekend(),
            Operation::DATE_IS_PAST => $v->lessThan($this->today()),
            Operation::DATE_IS_FUTURE => $v->greaterThan($this->today()),
            Operation::DATE_FORMAT => $this->dateFormat($v, $args),
            default => self::FAIL,
        };
    }

    /**
     * Format a date via a SAFE TOKEN pattern — NEVER a raw PHP date() format string. The `pattern` is a
     * sequence of whitelisted tokens (YYYY MM DD D MMMM MMM HH mm) + a few literal separators; anything
     * else fails closed (→ FAIL, the module's fail-soft: the caller yields '' / coerced null). $v is a
     * CarbonImmutable (applyDate already guarded a non-date), so this never throws.
     *
     * @param  array<string, mixed>  $args
     */
    private function dateFormat(CarbonImmutable $v, array $args): mixed
    {
        $pattern = $args['pattern'] ?? null;

        if (!is_string($pattern) || $pattern === '') {
            return self::FAIL;
        }

        $format = $this->compileDatePattern($pattern);

        return $format === null ? self::FAIL : $v->format($format);
    }

    /**
     * Compile a safe-token pattern to a PHP date() format string, or null when it carries any byte
     * outside the whitelist. Tokens are matched greedily longest-first (MMMM beats MMM beats MM); a
     * literal separator is BACKSLASH-escaped so date() emits it verbatim rather than interpreting it.
     */
    private function compileDatePattern(string $pattern): ?string
    {
        $out = '';
        $i = 0;
        $len = strlen($pattern);

        while ($i < $len) {
            $token = $this->matchDateToken($pattern, $i);

            if ($token !== null) {
                $out .= self::DATE_FORMAT_TOKENS[$token];
                $i += strlen($token);

                continue;
            }

            $char = $pattern[$i];

            if (!in_array($char, self::DATE_FORMAT_SEPARATORS, true)) {
                return null; // any non-token, non-separator byte rejects the whole pattern
            }

            $out .= '\\' . $char; // escape the literal so date() does not interpret it
            $i++;
        }

        return $out;
    }

    /** The whitelisted token starting at $offset (longest-first), or null when none matches. */
    private function matchDateToken(string $pattern, int $offset): ?string
    {
        foreach (self::DATE_FORMAT_TOKENS as $token => $_php) {
            if (substr($pattern, $offset, strlen($token)) === $token) {
                return $token;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $args */
    private function applyEnum(Operation $op, string $v, array $args): mixed
    {
        return match ($op) {
            Operation::ENUM_IS => $this->withStringArg($args, 'value', fn (string $s) => $v === $s),
            Operation::ENUM_IS_NOT => $this->withStringArg($args, 'value', fn (string $s) => $v !== $s),
            Operation::ENUM_IN => $this->memberOfArg($v, $args),
            Operation::ENUM_TO_TEXT => $this->enumMap($v, $args, VariableType::TEXT),
            Operation::ENUM_TO_NUMBER => $this->enumMap($v, $args, VariableType::NUMBER),
            Operation::ENUM_TO_DATE => $this->enumMap($v, $args, VariableType::DATE),
            // The choice output is an option string, so it reuses enumMap's default (stringify) branch;
            // an unmapped source option fails closed exactly like the other enum_to_* ops.
            Operation::ENUM_TO_CHOICE => $this->enumMap($v, $args, VariableType::ENUM),
            default => self::FAIL,
        };
    }

    /** Whether the enum value is one of the `values` option list. @param array<string, mixed> $args */
    private function memberOfArg(string $v, array $args): mixed
    {
        $values = $this->arrayArg($args, 'values');

        if ($values === self::FAIL) {
            return self::FAIL;
        }

        foreach ($values as $candidate) {
            if (is_scalar($candidate) && (string) $candidate === $v) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map an enum option value to its per-option target (text/number/date). An unmapped option or an
     * unparseable target fails.
     *
     * @param  array<string, mixed>  $args
     */
    private function enumMap(string $v, array $args, VariableType $target): mixed
    {
        $mapping = $this->arrayArg($args, 'mapping');

        if ($mapping === self::FAIL || !array_key_exists($v, $mapping)) {
            return self::FAIL;
        }

        $mapped = $mapping[$v];

        return match ($target) {
            VariableType::NUMBER => is_numeric($mapped) ? (float) $mapped : self::FAIL,
            VariableType::DATE => $this->parseDate($mapped) ?? self::FAIL,
            default => is_scalar($mapped) ? (string) $mapped : self::FAIL,
        };
    }

    /**
     * @param  array<int, string>  $v
     * @param  array<string, mixed>  $args
     */
    private function applyMulti(Operation $op, array $v, array $args): mixed
    {
        return match ($op) {
            Operation::MULTI_INCLUDES => $this->withStringArg($args, 'value', fn (string $s) => in_array($s, $v, true)),
            Operation::MULTI_EXCLUDES => $this->withStringArg($args, 'value', fn (string $s) => !in_array($s, $v, true)),
            Operation::MULTI_INCLUDES_ANY => $this->multiOverlap($v, $args, requireAll: false),
            Operation::MULTI_INCLUDES_ALL => $this->multiOverlap($v, $args, requireAll: true),
            Operation::MULTI_COUNT => (float) count($v),
            Operation::MULTI_IS_EMPTY => count($v) === 0,
            Operation::MULTI_TO_TEXT => implode(', ', array_map(fn ($x) => is_scalar($x) ? (string) $x : '', $v)),
            // ARRAY transforms (wave 1) run over MULTI values this wave — the array is already normalized
            // to string[] above (normalizeInput → toStringList).
            Operation::ARRAY_COUNT => (float) count($v),
            Operation::ARRAY_AT => $this->arrayAt($v, $args),
            default => self::FAIL,
        };
    }

    /**
     * `at`: return the element at a 1-based SIGNED index, CLAMPED to the nearest end. Fail-closed and
     * total over its input (never throws):
     *   - a missing / non-numeric `index` arg → FAIL (the index is required).
     *   - an EMPTY array (or a null element) → a valid typed `default` when present (F4), else null (the
     *     element is legitimately absent — `at`'s output is nullable when it is the pipeline terminal).
     *   - index 0 → the FIRST element.
     *   - a positive index past the end → the LAST element (clamp).
     *   - a negative index counts from the end (-1 = last, -2 = second-last …).
     *   - a negative index past the start → the FIRST element (clamp).
     * The element is a string (a scalar/enum list element), or — array-ops wave 3 — the raw OBJECT/FILE
     * snapshot of an array<object>/array<file>, or null. NOTE an object/file returned by `at` is only
     * downstream-usable via PATH access (the resolver reads its subfields); its runtime type degrades to
     * the element base, so chaining a further pipeline OP onto it fails CLOSED — the compelling value of
     * these arrays is the element PIPELINES (map/filter/sort/reduce), not `at`.
     *
     * @param  array<int, mixed>  $v
     * @param  array<string, mixed>  $args
     */
    private function arrayAt(array $v, array $args): mixed
    {
        $index = $args['index'] ?? null;

        if (!is_numeric($index)) {
            return self::FAIL;
        }

        $element = $this->elementAt($v, (int) $index);

        // F4: a null/absent element (an empty array, a clamped-to-nothing index, or a null member) is
        // substituted by the step's valid typed DEFAULT when it carries one — so a FOLLOWING op sees a real
        // value instead of the null it fail-closes on. Without a (valid) default the raw null stays, which
        // stays a legal terminal (`at`'s output is nullable). Never throws.
        return $element === null ? $this->elementDefault($args) : $element;
    }

    /**
     * The element of a normalized array at a 1-based SIGNED index, clamped to the nearest end (see arrayAt
     * for the clamp matrix). null for an empty array.
     *
     * @param  array<int, mixed>  $v
     */
    private function elementAt(array $v, int $i): mixed
    {
        $n = count($v);

        if ($n === 0) {
            return null;
        }

        $pos = match (true) {
            $i > 0 => min($i, $n) - 1,   // 1-based; overflow clamps to the last
            $i < 0 => max($n + $i, 0),   // -1 = last; underflow clamps to the first
            default => 0,                // 0 → first
        };

        return $v[$pos];
    }

    /**
     * The normalized typed element DEFAULT (F4) for array_at, or null when the step carries none / an
     * invalid one. A self-describing `{type, value}` literal normalized to its declared base (reduceSeed
     * -style normalizeInput), so a following op sees a proper T. Fail-soft: a missing/unrepresentable
     * default yields null (the raw null the pipeline terminal legitimately allows).
     *
     * A BLANK value (null or the empty string) counts as NOT ENTERED (owner directive: an empty string is
     * not a value) — no substitution, the raw null stays, so a following op fail-closes exactly as without a
     * default. This keeps runtime consistent with the write validator, which rejects a blank default. Number
     * 0 / boolean false ARE values and substitute normally.
     *
     * @param  array<string, mixed>  $args
     */
    private function elementDefault(array $args): mixed
    {
        $default = $args['default'] ?? null;

        if (!is_array($default)) {
            return null;
        }

        $type = VariableType::tryFrom((string) ($default['type'] ?? ''));
        $value = $default['value'] ?? null;

        if ($type === null || $value === null || $value === '') {
            return null;
        }

        $normalized = $this->normalizeInput($value, $type);

        return $normalized === self::FAIL || $normalized === self::UNSUPPORTED_BASE ? null : $normalized;
    }

    /**
     * Whether the selection overlaps the `values` arg — ANY match (requireAll:false) or EVERY value
     * present (requireAll:true). A non-array/empty `values` fails.
     *
     * @param  array<int, string>  $v
     * @param  array<string, mixed>  $args
     */
    private function multiOverlap(array $v, array $args, bool $requireAll): mixed
    {
        $values = $this->arrayArg($args, 'values');

        if ($values === self::FAIL || $values === []) {
            return self::FAIL;
        }

        foreach ($values as $candidate) {
            $present = is_scalar($candidate) && in_array((string) $candidate, $v, true);

            if ($requireAll && !$present) {
                return false;
            }

            if (!$requireAll && $present) {
                return true;
            }
        }

        return $requireAll;
    }

    // ---- higher-order array transforms (wave 2): per-element re-entry ----------

    /**
     * Dispatch a HIGHER-ORDER array op (map/filter/sort/reduce) over the normalized array $items,
     * returning [value, terminalType] or the FAIL sentinel. TWO fail-closed caps apply to EVERY op
     * before a single element runs:
     *   - MAX_ARRAY_ITERATIONS: an array longer than the cap fails the op CLOSED (a hostile/huge value).
     *   - MAX_ELEMENT_PIPELINE_DEPTH: a per-element re-entry beyond the cap fails the op CLOSED (a
     *     pathologically nested element pipeline). Each element sub-run happens at $elementDepth + 1.
     * Never throws — every dead end is the FAIL sentinel the caller collapses to a failure result.
     *
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context
     * @return array{0: mixed, 1: VariableType}|string
     */
    private function applyCollection(Operation $op, array $items, array $args, array $context, int $elementDepth): array|string
    {
        if (count($items) > PipelineLimits::MAX_ARRAY_ITERATIONS) {
            return self::FAIL;
        }

        if ($elementDepth + 1 > PipelineLimits::MAX_ELEMENT_PIPELINE_DEPTH) {
            return self::FAIL;
        }

        $items = array_values($items);

        return match ($op) {
            Operation::ARRAY_MAP => $this->arrayMap($items, $args, $context, $elementDepth),
            Operation::ARRAY_FILTER => $this->arrayFilter($items, $args, $context, $elementDepth),
            Operation::ARRAY_SORT => $this->arraySort($items, $args, $context, $elementDepth),
            Operation::ARRAY_REDUCE => $this->arrayReduce($items, $args, $context, $elementDepth),
            default => self::FAIL,
        };
    }

    /**
     * map: run the element pipeline per element and COLLECT the terminals into an array<U>. An element
     * whose sub-run FAILs fails the WHOLE op closed (output length must match input length — dropping a
     * failed element would silently shorten the result), never opening a downstream gate on garbage.
     *
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, mixed>, 1: VariableType}|string
     */
    private function arrayMap(array $items, array $args, array $context, int $elementDepth): array|string
    {
        $runner = $this->elementRunner($args, 'pipeline');

        if ($runner === self::FAIL) {
            return self::FAIL;
        }

        $out = [];

        foreach ($items as $i => $element) {
            $res = $this->runElement($runner, $element, $i + 1, $context, $elementDepth);

            if ($res->failed) {
                return self::FAIL; // fail the whole map CLOSED — no drop-and-continue (length matters)
            }

            $out[] = $res->value;
        }

        return [$out, VariableType::MULTI];
    }

    /**
     * filter: keep an element IFF its element pipeline terminates in boolean TRUE. A FAILED sub-run (or a
     * non-boolean terminal) DROPS the element — fail-closed, so an unevaluable predicate can never keep
     * an element it should have excluded (nor, downstream, open a gate).
     *
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, mixed>, 1: VariableType}|string
     */
    private function arrayFilter(array $items, array $args, array $context, int $elementDepth): array|string
    {
        $runner = $this->elementRunner($args, 'pipeline');

        if ($runner === self::FAIL) {
            return self::FAIL;
        }

        $out = [];

        foreach ($items as $i => $element) {
            $res = $this->runElement($runner, $element, $i + 1, $context, $elementDepth);

            if (!$res->failed && $res->type === VariableType::BOOLEAN && $res->value === true) {
                $out[] = $element; // keep the ORIGINAL element, not the boolean
            }
        }

        return [array_values($out), VariableType::MULTI];
    }

    /**
     * sort: order the elements ASCENDING by a numeric key each element pipeline produces. A FAILED sub-run
     * (or a non-number terminal) yields a null key that sorts the element LAST; ties — and all failed
     * keys — keep their original order (stable). The elements themselves are unchanged.
     *
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context
     * @return array{0: array<int, mixed>, 1: VariableType}|string
     */
    private function arraySort(array $items, array $args, array $context, int $elementDepth): array|string
    {
        $runner = $this->elementRunner($args, 'pipeline');

        if ($runner === self::FAIL) {
            return self::FAIL;
        }

        $keyed = [];

        foreach ($items as $i => $element) {
            $res = $this->runElement($runner, $element, $i + 1, $context, $elementDepth);
            $key = (!$res->failed && $res->type === VariableType::NUMBER && is_numeric($res->value))
                ? (float) $res->value
                : null; // a failed / non-numeric key sorts LAST, stable

            $keyed[] = ['element' => $element, 'key' => $key, 'seq' => $i];
        }

        usort($keyed, function (array $a, array $b): int {
            if ($a['key'] === null || $b['key'] === null) {
                // null (failed) sorts after a real key; two nulls keep original order.
                return ($a['key'] === null ? 1 : 0) <=> ($b['key'] === null ? 1 : 0) ?: $a['seq'] <=> $b['seq'];
            }

            return $a['key'] <=> $b['key'] ?: $a['seq'] <=> $b['seq'];
        });

        return [array_map(fn (array $e): mixed => $e['element'], $keyed), VariableType::MULTI];
    }

    /**
     * reduce: fold a typed SEED across the elements. Per element the reducer pipeline is run over the
     * current ACCUMULATOR (base type = the seed base U), with the element + 1-based index in scope, and
     * its terminal (which the write-validator forces to U) becomes the next accumulator. A FAILED sub-run
     * — or one whose terminal is not U — KEEPS the prior accumulator (fail-closed, no type corruption).
     * The result is the final accumulator, a base value U.
     *
     * @param  array<int, mixed>  $items
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $context
     * @return array{0: mixed, 1: VariableType}|string
     */
    private function arrayReduce(array $items, array $args, array $context, int $elementDepth): array|string
    {
        $seed = $this->reduceSeed($args);

        if ($seed === self::FAIL) {
            return self::FAIL;
        }

        [$acc, $seedType] = $seed;

        $reducer = $this->elementPipelineArg($args, 'reducer');

        if ($reducer === self::FAIL) {
            return self::FAIL;
        }

        foreach ($items as $i => $element) {
            // The reducer's BASE value is the accumulator (type U); the element/index ride the scope
            // overlay. A scalar element carries no first-class type here (the reducer roots at U, not the
            // element), so the scope `element` ref is served ENUM-style (a string), coerced by each op's
            // own arg reader — sufficient for the identity refs this wave targets.
            $res = $this->runReducer($acc, $seedType, $element, VariableType::ENUM, $reducer, $i + 1, $context, $elementDepth);

            if (!$res->failed && $res->type === $seedType) {
                $acc = $res->value; // fold
            }
            // else: fail-closed — keep the prior accumulator (never corrupt its type).
        }

        return [$acc, $seedType];
    }

    /**
     * Resolve a map/filter/sort element-pipeline arg into a RUNNER `{ref, steps}` — the two shapes an
     * element pipeline may take:
     *   - a bare `{op,args}[]` LIST (scalar/enum element arrays, wave 2) → rooted at the element itself
     *     (`ref` null); or
     *   - a value-or-variable UNION rooted at a scope `element.<subfield>` (array<object>/array<file>,
     *     wave 3) → `ref` is the scope subfield ref, `steps` its own `pipeline` (the transform).
     * FAIL when it is absent, not a list/union, or a NON-scope union (a whole-array union is malformed).
     *
     * @param  array<string, mixed>  $args
     * @return array{ref: array<string, mixed>|null, steps: array<int, mixed>}|string
     */
    private function elementRunner(array $args, string $key): array|string
    {
        $raw = $args[$key] ?? null;

        if (ValueOrVariable::isVariable($raw)) {
            $ref = is_array($raw['ref'] ?? null) ? $raw['ref'] : [];

            if ($this->scopeLeaf($ref) === null) {
                return self::FAIL; // a union root that is not a scope element/subfield ref is malformed
            }

            $steps = $raw['pipeline'] ?? [];

            if (!is_array($steps) || !array_is_list($steps)) {
                return self::FAIL;
            }

            return ['ref' => $ref, 'steps' => $steps];
        }

        if (!is_array($raw) || !array_is_list($raw)) {
            return self::FAIL;
        }

        return ['ref' => null, 'steps' => $raw];
    }

    /**
     * Run one element pipeline for iteration $index (1-based), with the scoped `{element, index}` overlay
     * (carrying the object/file element snapshot so `element.<subfield>` refs resolve) woven into the
     * context for THIS iteration only. The BASE is the element itself (a bare-list pipeline) or the scope
     * `element.<subfield>` the union roots at (an object/file pipeline). Returns the sub-run's
     * OperationResult; an unresolvable scope reference fails the sub-run closed.
     *
     * @param  array{ref: array<string, mixed>|null, steps: array<int, mixed>}  $runner
     * @param  array<string, mixed>  $context
     */
    private function runElement(array $runner, mixed $element, int $index, array $context, int $elementDepth): OperationResult
    {
        $overlay = $this->scopeOverlay($context, $element, $index);
        $elementType = $this->elementType($runner['steps'], $overlay);

        [$base, $baseType] = $runner['ref'] !== null
            ? $this->scopeBase($this->scopeLeaf($runner['ref']), $element, $elementType, $index, $runner['ref'], $overlay)
            : [$element, $elementType];

        $resolved = $this->resolveScopePipeline($runner['steps'], $element, $elementType, $index, $overlay, $elementDepth + 1);

        if ($resolved === self::FAIL) {
            return OperationResult::failure();
        }

        return $this->execute($base, $baseType, $resolved, $overlay, $elementDepth + 1);
    }

    /**
     * Run a reducer pipeline over the ACCUMULATOR (base type U = $accType), with `element`/`index` in
     * scope (the element served as $elementType). Mirrors runElementPipeline but roots at the accumulator
     * rather than the element.
     *
     * @param  array<int, mixed>  $reducer
     * @param  array<string, mixed>  $context
     */
    private function runReducer(mixed $acc, VariableType $accType, mixed $element, VariableType $elementType, array $reducer, int $index, array $context, int $elementDepth): OperationResult
    {
        $overlay = $this->scopeOverlay($context, $element, $index);
        $resolved = $this->resolveScopePipeline($reducer, $element, $elementType, $index, $overlay, $elementDepth + 1);

        if ($resolved === self::FAIL) {
            return OperationResult::failure();
        }

        return $this->execute($acc, $accType, $resolved, $overlay, $elementDepth + 1);
    }

    // ---- custom functions (Phase 3b): execution by expansion --------------------

    /**
     * Execute a custom FUNCTION op by EXPANSION over the current running value, returning [value, type] or
     * the FAIL sentinel. The mechanism mirrors the array-element re-entry (frame + resolveScopePipeline +
     * re-enter execute):
     *   1. FAIL-CLOSED gates (both non-negotiable): beyond MAX_FUNCTION_EXPANSION_DEPTH, or a cycle the
     *      write-time acyclic check never saw (the function is already on the active chain — a corrupted /
     *      raced row), the op fails CLOSED. It NEVER loops.
     *   2. Bind {input: <running value>, <argName>: <pre-resolved literal arg>…} into a fresh scope FRAME
     *      (functionFrame), installed on a scope entered one level deeper (depth + 1, uuid appended).
     *   3. A body is PURE over {input + args}: a FRESH context carrying only that scope (dropping trigger/
     *      steps/globals + any enclosing element scope) so a body op can reference nothing else.
     *   4. Pre-resolve the body's top-level scope args (`input`/`<argName>`) to literals against the frame,
     *      then re-enter execute() on the body (base = the input value, base type = the input type).
     *   5. VERIFY the body terminal against the declared RETURN type — a mismatch (only ever a corrupted
     *      row; the write-validator forces equality) fails CLOSED rather than coercing a wrong value that
     *      could OPEN a gate. A failed body sub-run (unknown op, cycle hit, assert_present hard fail) also
     *      fails CLOSED.
     *
     * @param  array<string, mixed>  $args  the op's args (variable-shaped ones already pre-resolved to literals)
     * @return array{0: mixed, 1: VariableType}|string
     */
    private function expandFunction(CustomFunctionOperation $op, mixed $input, VariableType $inputType, array $args, FunctionScope $scope, int $elementDepth): array|string
    {
        // Fail CLOSED on over-depth or a re-entered function (a cycle that bypassed write validation). The
        // two backstops are independent: the depth cap bounds legitimate nesting AND any cycle; the
        // visited-set catches a corrupted cycle before it can even reach the cap.
        if ($scope->overDepth() || $scope->hasVisited($op->id())) {
            return self::FAIL;
        }

        $entered = $scope->enter($op->id(), $this->functionFrame($op, $input, $inputType, $args));

        // A body reads ONLY its own {input + args} — a fresh context carrying just the entered scope, so a
        // stray trigger/steps/globals/outer-element ref inside a (corrupted) body resolves to nothing.
        $bodyContext = $entered->writeInto([]);

        // Pre-resolve the body's top-level `input`/`<argName>` scope refs to literals for THIS call (the
        // frame rides $bodyContext, so scopeBase reads the bindings). $inputType is the base element type
        // — irrelevant to the input/arg roots, which resolve off the frame, not the (absent) loop element.
        $body = $this->resolveScopePipeline($op->body(), null, $inputType, 0, $bodyContext, $elementDepth);

        if ($body === self::FAIL) {
            return self::FAIL;
        }

        $result = $this->execute($input, $inputType, $body, $bodyContext, $elementDepth);

        // A failed body (incl. an assert_present HARD failure, which is still ->failed) fails the op closed;
        // a HARD failure does NOT escalate past a function boundary (the function absorbs it into a plain
        // fail-closed, so a boolean-returning function used in a condition gate simply reads false).
        if ($result->failed || $result->type !== $op->outputType()) {
            return self::FAIL;
        }

        return [$result->value, $op->outputType()];
    }

    /**
     * The scope FRAME a function body reads: `input` bound to the running value (at the function's declared
     * input type) plus each named arg bound to its pre-resolved literal (at the arg's declared type). A
     * missing arg (only ever a corrupted config — the write-validator requires every declared arg) binds
     * null, which the body's consuming op then fails closed on. Each entry is `{value, type}` so a scope
     * ref recovers the real type (scopeBase / frameBase).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, array{value: mixed, type: VariableType}>
     */
    private function functionFrame(CustomFunctionOperation $op, mixed $input, VariableType $inputType, array $args): array
    {
        $frame = ['input' => ['value' => $input, 'type' => $inputType]];

        foreach ($op->argTypes() as $name => $type) {
            $frame[$name] = ['value' => $args[$name] ?? null, 'type' => $type];
        }

        return $frame;
    }

    /**
     * The per-iteration run context PLUS the scoped `{element, index}` overlay (index 1-based, as a
     * float). The overlay is a SEPARATE `scope` root so an `element`/`index` reference resolves ONLY
     * inside this per-element sub-run — it is never merged into the global whitelist, so a reference
     * anywhere OUTSIDE an element pipeline cannot reach it (fail-soft null → gate false).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function scopeOverlay(array $context, mixed $element, int $index): array
    {
        return ['scope' => ['element' => $element, 'index' => (float) $index]] + $context;
    }

    /**
     * Pre-resolve every SCOPE-rooted variable argument (`element`/`index`) of every op in $pipeline for
     * THIS iteration, returning a new pipeline with those args replaced by their resolved literal — or the
     * FAIL sentinel when any scope reference cannot resolve (so the caller fails the element sub-run
     * closed). NON-scope arguments are already literals here (the resolver pre-resolved trigger/steps/
     * globals references before the executor ever ran); a residual NON-scope union is malformed and fails.
     *
     * @param  array<int, mixed>  $pipeline
     * @param  array<string, mixed>  $overlay
     * @return array<int, mixed>|string
     */
    private function resolveScopePipeline(array $pipeline, mixed $element, VariableType $elementType, int $index, array $overlay, int $elementDepth): array|string
    {
        $out = [];

        foreach ($pipeline as $step) {
            if (!is_array($step)) {
                $out[] = $step;

                continue;
            }

            // Resolve through the OperationResolver (built-ins ∪ the context's functions) so a `fn:<uuid>`
            // op inside a body's element pipeline is recognized and its (scope-ref) args pre-resolved.
            $op = $this->operations->resolve((string) ($step['op'] ?? $step['operationId'] ?? ''), FunctionScope::fromContext($overlay)->functions);

            if ($op === null) {
                $out[] = $step;

                continue;
            }

            $args = is_array($step['args'] ?? null) ? $step['args'] : [];

            foreach ($op->argDescriptors() as $descriptor) {
                if (!array_key_exists($descriptor->id, $args) || !ValueOrVariable::isVariable($args[$descriptor->id])) {
                    continue; // a plain literal, or a nested element-pipeline list — leave untouched
                }

                $resolved = $this->resolveScopeArg($args[$descriptor->id], $element, $elementType, $index, $overlay, $elementDepth);

                if ($resolved === self::FAIL) {
                    return self::FAIL; // an unresolvable scope reference fails the whole element sub-run closed
                }

                $args[$descriptor->id] = $resolved;
            }

            $step['args'] = $args;
            $out[] = $step;
        }

        return $out;
    }

    /**
     * Resolve ONE scope-rooted variable argument to its literal for this iteration. Recognizes only the
     * `element` / `index` leaves (source `scope`); ANY other residual variable union is malformed (the
     * resolver should have pre-resolved it) and fails closed. An identity reference returns the raw scope
     * value; a reference carrying a sub-pipeline runs it via a bounded execute() re-entry over the scope
     * value's type, failing closed on a sub-run failure.
     *
     * @param  array<string, mixed>  $overlay
     */
    private function resolveScopeArg(mixed $arg, mixed $element, VariableType $elementType, int $index, array $overlay, int $elementDepth): mixed
    {
        $ref = is_array($arg['ref'] ?? null) ? $arg['ref'] : [];
        // The scope ROOTS are the element/index default UNION the enclosing function body's roots
        // (input + arg names), read off the frame in the overlay — so a body's `input`/`<argName>` ref
        // inside an element pipeline resolves against the frame stack, while a plain element pipeline
        // (no function frame) keeps the byte-identical element/index roots.
        $scopePath = ScopeRef::leaf($ref, $this->scopeRoots($overlay));

        if ($scopePath === null) {
            return self::FAIL; // not a scope reference — a residual non-scope union is malformed
        }

        [$base, $baseType] = $this->scopeBase($scopePath, $element, $elementType, $index, $ref, $overlay);

        $pipeline = $arg['pipeline'] ?? null;

        if (is_array($pipeline) && $pipeline !== []) {
            $res = $this->execute($base, $baseType, $pipeline, $overlay, $elementDepth);

            return $res->failed ? self::FAIL : $res->value;
        }

        return $base;
    }

    /**
     * The base value + type a scope PATH resolves to for THIS iteration (array-ops wave 2/3):
     *   - `index`          → the 1-based iteration index (number).
     *   - `element`        → the whole element (its element type).
     *   - `element.<sub>`  → the object/file element's subfield value (read off the snapshot), typed by the
     *                        ref's declared subfield type — the array<object>/array<file> element access.
     *
     * @param  array<string, mixed>  $ref
     * @return array{0: mixed, 1: VariableType}
     */
    private function scopeBase(string $scopePath, mixed $element, VariableType $elementType, int $index, array $ref, array $context = []): array
    {
        if ($scopePath === 'index') {
            return [(float) $index, VariableType::NUMBER];
        }

        if ($scopePath === 'element') {
            return [$element, $elementType];
        }

        if (str_starts_with($scopePath, 'element.')) {
            $sub = substr($scopePath, strlen('element.'));
            $type = VariableType::tryFrom((string) ($ref['type'] ?? '')) ?? VariableType::TEXT;

            return [$this->readElementSubfield($element, $sub), $type];
        }

        // A FUNCTION-body scope root (`input` / `<argName>` / `input.<sub>`), read off the bound frame.
        // Unreachable for a plain element pipeline (ScopeRef only ever yields index/element/element.<sub>
        // there), so the element path stays byte-identical.
        return $this->frameBase($scopePath, $ref, $context);
    }

    /**
     * The base value + type a FUNCTION-body scope path resolves to, read off the current frame in $context
     * (installed when the body was entered): `input` / `<argName>` → the bound {value, type}; a structured
     * `input.<sub>` reads the subfield off the bound value (typed by the ref) exactly like `element.<sub>`.
     * Fail-soft: an unbound root (a corrupted body) → [null, TEXT], which the consuming op fails closed on.
     *
     * @param  array<string, mixed>  $ref
     * @param  array<string, mixed>  $context
     * @return array{0: mixed, 1: VariableType}
     */
    private function frameBase(string $scopePath, array $ref, array $context): array
    {
        $root = explode('.', $scopePath, 2)[0];
        $entry = FunctionScope::fromContext($context)->frame[$root] ?? null;
        $value = is_array($entry) ? ($entry['value'] ?? null) : null;
        $type = ($entry['type'] ?? null) instanceof VariableType ? $entry['type'] : VariableType::TEXT;

        if (str_contains($scopePath, '.')) {
            $sub = substr($scopePath, strlen($root) + 1);
            $subType = VariableType::tryFrom((string) ($ref['type'] ?? '')) ?? VariableType::TEXT;

            return [$this->readElementSubfield($value, $sub), $subType];
        }

        return [$value, $type];
    }

    /**
     * The scope ROOTS a reference may address in the current context: the DEFAULT element/index UNION the
     * roots of the enclosing function body's frame (`input` + arg names). When no function frame is in play
     * (every plain element pipeline) this is exactly ScopeRef's default, so the element path is unchanged.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function scopeRoots(array $context): array
    {
        $frameRoots = FunctionScope::fromContext($context)->frameRoots();

        return $frameRoots === []
            ? ScopeRef::DEFAULT_ROOTS
            : array_values(array_unique([...ScopeRef::DEFAULT_ROOTS, ...$frameRoots]));
    }

    /**
     * Read an `element.<subfield>` value off the element snapshot (array-ops wave 3). A plain dotted read
     * serves an array<object> row's fields and the identity file subfields (id/name/size/url); a FILE
     * element's `type` aliases the snapshot's `mime_type` (FILE_SUBFIELD_KEYS). Fail-soft: a non-array
     * element or an unknown subfield resolves to null (the sub-run then fails/soft-resolves closed).
     */
    private function readElementSubfield(mixed $element, string $sub): mixed
    {
        if (!is_array($element)) {
            return null;
        }

        $direct = Arr::get($element, $sub, self::MISSING);

        if ($direct !== self::MISSING) {
            return $direct;
        }

        $snapshotKey = self::FILE_SUBFIELD_KEYS[$sub] ?? null;

        return $snapshotKey !== null ? Arr::get($element, $snapshotKey) : null;
    }

    /**
     * The scope leaf (`element` / `index`) a reference addresses, or null when it is not a scope
     * reference. Delegates to ScopeRef — the ONE source-aware definition shared with the write-validator
     * and the resolver — so a real global / trigger / step ref whose path leaf merely happens to be
     * `element` / `index` (source ≠ `scope`) is NOT resolved against the loop overlay (wrong-value
     * substitution); only a genuine `source:'scope'` ref resolves per iteration here.
     *
     * @param  array<string, mixed>  $ref
     */
    private function scopeLeaf(array $ref): ?string
    {
        return ScopeRef::leaf($ref);
    }

    /**
     * The reduce SEED: a self-describing typed literal `{type, value}` normalized to [value, type], or
     * FAIL. `type` must be one of the four base LITERAL types (text/number/boolean/date) — an array/enum
     * seed is rejected — and `value` must normalize to it. This is both the accumulator's initial value
     * and the type the reducer pipeline must terminate in (enforced at write time).
     *
     * @param  array<string, mixed>  $args
     * @return array{0: mixed, 1: VariableType}|string
     */
    private function reduceSeed(array $args): array|string
    {
        $seed = $args['seed'] ?? null;

        if (!is_array($seed)) {
            return self::FAIL;
        }

        $type = VariableType::tryFrom((string) ($seed['type'] ?? ''));

        if ($type === null || !in_array($type, self::REDUCE_SEED_TYPES, true)) {
            return self::FAIL;
        }

        $normalized = $this->normalizeInput($seed['value'] ?? null, $type);

        if ($normalized === self::FAIL || $normalized === self::UNSUPPORTED_BASE) {
            return self::FAIL;
        }

        return [$normalized, $type];
    }

    /**
     * A required ELEMENT-PIPELINE argument — a plain list of `{op, args}` steps — or FAIL when it is
     * absent, not a list, or a value-or-variable union (a union in this slot is malformed).
     *
     * @param  array<string, mixed>  $args
     * @return array<int, mixed>|string
     */
    private function elementPipelineArg(array $args, string $key): array|string
    {
        $pipeline = $args[$key] ?? null;

        if (!is_array($pipeline) || !array_is_list($pipeline) || ValueOrVariable::isVariable($pipeline)) {
            return self::FAIL;
        }

        return $pipeline;
    }

    /**
     * The ELEMENT base type an element pipeline runs over, recovered from its FIRST op's declared input
     * type (the pipeline was authored against the real element type — the same recovery the resolver uses
     * for a piped reference). Falls back to ENUM, the natural element of a normalized MULTI (string[]).
     *
     * @param  array<int, mixed>  $pipeline
     */
    private function elementType(array $pipeline, array $context = []): VariableType
    {
        $first = $pipeline[0] ?? null;

        if (is_array($first)) {
            // Resolve through the OperationResolver (built-ins ∪ the context's functions) so an element
            // pipeline that LEADS with a `fn:<uuid>` recovers the function's input type as the element base.
            $op = $this->operations->resolve((string) ($first['op'] ?? $first['operationId'] ?? ''), FunctionScope::fromContext($context)->functions);

            if ($op !== null) {
                return $op->inputType();
            }
        }

        return VariableType::ENUM;
    }

    // ---- arg readers ----------------------------------------------------------

    /**
     * A required ARRAY-shaped argument — an option list (`values`), a per-option map (`mapping`), or a
     * rule list (`rules`) — or FAIL when it is absent, is not an array, is the UNRESOLVED-ARGUMENT
     * marker, or is structurally an unresolved VALUE-OR-VARIABLE UNION. $whenAbsent preserves each
     * caller's existing absent-arg semantics (match_to_choice tolerates a missing/null `rules` as "no
     * rules"; the others require it).
     *
     * This is the ONE reader all FOUR array-shaped arguments funnel through (memberOfArg /
     * multiOverlap / enumMap / matchToChoice), so its two guards can not drift between them:
     *
     *   1. THE UNION guard (safety batch B0.5 — see ValueOrVariable, the one place the shape is
     *      defined). The SCALAR readers below reject a union for free via is_scalar/is_numeric; an
     *      array-shaped one could not, and consumed the union's own keys/values as data instead —
     *      enum_in read the literal string `variable` (the `kind` VALUE) as a candidate option,
     *      enum_to_* read `kind` as a source option, and match_to_choice returned its fallback for
     *      garbage rules — which could OPEN a condition gate. A union reaching the executor at all is
     *      malformed by definition: the resolver pre-resolves every variable-shaped argument into a
     *      literal BEFORE the (pure, context-less) executor runs, so this is defence in depth for
     *      legacy / hand-written rows that bypass pre-resolution.
     *   2. THE UNRESOLVED-ARGUMENT guard (see UnresolvedArgument). A STRUCTURAL arg-variable that
     *      resolved to nothing used to arrive as `null`, which `?? $whenAbsent` silently turned into
     *      match_to_choice's "no rules" — so a `rules` ref to an UNANSWERED optional field yielded the
     *      fallback as a SUCCESS and OPENED the gate. It now arrives as a distinct non-array marker.
     *      Checked EXPLICITLY (not merely caught by !is_array) so the intent survives any future
     *      widening of this reader or of a caller's $whenAbsent.
     *
     * @param  array<string, mixed>  $args
     * @param  array<array-key, mixed>|null  $whenAbsent
     * @return array<array-key, mixed>|string the array, or the FAIL sentinel
     */
    private function arrayArg(array $args, string $key, ?array $whenAbsent = null): array|string
    {
        $value = $args[$key] ?? $whenAbsent;

        if (UnresolvedArgument::is($value) || !is_array($value) || ValueOrVariable::isVariable($value)) {
            return self::FAIL;
        }

        return $value;
    }

    /**
     * Run $fn with a required scalar arg cast to string, or FAIL when the arg is absent/non-scalar.
     *
     * @param  array<string, mixed>  $args
     * @param  callable(string): mixed  $fn
     */
    private function withStringArg(array $args, string $key, callable $fn): mixed
    {
        $value = $args[$key] ?? null;

        return is_scalar($value) ? $fn((string) $value) : self::FAIL;
    }

    /**
     * Run $fn with a required numeric arg cast to float, or FAIL when the arg is absent/non-numeric.
     *
     * @param  array<string, mixed>  $args
     * @param  callable(float): mixed  $fn
     */
    private function withNumberArg(array $args, string $key, callable $fn): mixed
    {
        $value = $args[$key] ?? null;

        return is_numeric($value) ? $fn((float) $value) : self::FAIL;
    }

    /**
     * Run $fn with a required strict-Y-m-d arg parsed to a date, or FAIL when it is absent/unparseable.
     *
     * @param  array<string, mixed>  $args
     * @param  callable(CarbonImmutable): mixed  $fn
     */
    private function withDateArg(array $args, string $key, callable $fn): mixed
    {
        $date = $this->parseDate($args[$key] ?? null);

        return $date !== null ? $fn($date) : self::FAIL;
    }

    /** "Today" (start of day) in the evaluation timezone, for the relative date predicates. */
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    /**
     * Parse a STRICT ISO Y-m-d wall-clock date in the evaluation timezone — returns null for any
     * non-string, wrong format, zero-padding drift, or calendar roll-over (e.g. 2026-02-30). Never throws.
     */
    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        // Reject anything that did not round-trip exactly (roll-over / missing zero-padding).
        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
