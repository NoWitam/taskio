<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Variables\DTOs\OperationArg;
use App\Modules\Variables\Enums\OperationArgType;
use App\Modules\Variables\Enums\PipelineLimits;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\OperationExecutor;
use App\Modules\Variables\Services\OperationResolver;
use App\Modules\Variables\Support\FunctionScope;
use App\Modules\Variables\Support\ScopeRef;
use App\Modules\Variables\Support\ValueOrVariable;
use App\Modules\Workflows\Enums\WorkflowAiPersona;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Resolves workflow variable references in a step's config against the run context, EXECUTES the
 * transformations those references carry (SB1: directive pipelines, if-block branches, and
 * value-or-variable pipelines) through the shared OperationExecutor, and — new in SB2 —
 * EXECUTES `@[ai-text]` directives (generating field text through WorkflowAiTextService). It
 * understands every serialization of the ONE canonical variable identity plus the legacy flat
 * `{{...}}` tokens.
 *
 * AI-TEXT (SB2): a text field may carry `@[ai-text]("<json>")` where `<json>` =
 * `{"v":1,"data":{id, personaId, prompt, labels}}`. `data.prompt` is markdown that MAY itself
 * contain nested `@[variable]` / if-block references (and even a nested `@[ai-text]`). The prompt
 * is resolved through THIS resolver FIRST (same context/typeMap), so form values land in it before
 * the resolved prompt is sent to the AI; the generated string replaces the directive span. Nested
 * ai-text is capped at depth 3 (beyond → ''). Every path is fail-closed to '' (WorkflowAiTextService
 * never throws), so a blank REQUIRED title still hard-fails the run while a blank body is just empty.
 * The ai-text pass runs BEFORE the variable/flat passes and its OUTPUT is masked out of them, so a
 * generated string is inserted verbatim and never re-interpreted as a reference.
 *
 * A variable identity is always `{ source: trigger|steps|globals, path, type }`, serialized two ways:
 *
 *   1. TEXT / markdown fields carry the next editor's VARIABLE DIRECTIVE:
 *
 *        @[variable]("<json>")   where <json> = {"v":1,"data":{id,name,type,locked,pipeline,resultType}}
 *      `data.id` is the full path and the identity; `data.pipeline` is a list of editor pipeline
 *      steps `{stepId, operationId, args, outputType}`. When the pipeline is NON-EMPTY the base
 *      value is transformed and the (typed) result is STRINGIFIED into the text (a directive only
 *      ever lives in a text field). When it is empty the behavior is the legacy identity resolution.
 *      A directive's REAL base type is recovered from the run TYPE MAP by path (the directive's own
 *      `data.type` is a degraded editor primitive); with no map entry the pipeline's first op's
 *      input type is trusted (the editor authored the pipeline against the real type).
 *   2. NON-TEXT (structured) fields carry the UNION handled by resolveValueOrVariable():
 *        { kind: 'literal', value } | { kind: 'variable', ref: {source,path,type}, pipeline?: [{op,args}], default? }
 *      A present `pipeline` transforms the ref value from `ref.type`; the TYPED result is coerced to
 *      the field's expected type (a pipeline failure soft-resolves like an unresolved ref).
 *
 * DEFAULTS + ASSERT (phase-1b, append-only): a reference in EITHER serialization may carry an optional
 * literal `default` (directive `data.default`; union `default`). When the looked-up value is null or
 * '' the default is substituted BEFORE the pipeline runs (so it can then be formatted/piped); it
 * enters through the SAME NUL-mask path a resolved value does, so a default holding `{{…}}` / `@[…]`
 * bytes is never re-interpreted. A pipeline may end in the opt-in assert_present op: over an empty
 * value the executor returns a HARD failure this resolver re-raises as the run's standard step-failure
 * (a RuntimeException the runner records) — the one place a variable pipeline is NOT fail-soft.
 *
 * ARGUMENT VARIABLES (phase-4b, append-only): ANY of an operation's ARGUMENTS may itself be a value-or-
 * variable union (`{kind:'variable', ref, pipeline?, default?}`) instead of a constant literal —
 * recursively transformable. The executor STAYS A PURE TRANSFORMER: BEFORE it runs each op, THIS resolver
 * pre-resolves every variable-shaped argument to a LITERAL (via the SAME resolveValueOrVariable
 * machinery), then hands the executor literal args exactly as before. The per-arg gate is the arg
 * control's ArgVariablePolicy (the SINGLE source shared with the write-validator): a VALUE arg coerces to
 * its one type, an OPTION arg to a string (enum|text — option-set membership deferred to runtime
 * fail-soft), and a multi-OPTION arg to an array.
 *
 * STRUCTURAL CONTAINERS (sourceMap/choiceRules, Defect-3): these controls are NOT a single whole-structure
 * variable — the container is a plain map / rule list whose ENTRIES may EACH be a value-or-variable union.
 * THIS resolver walks the container and pre-resolves every union ENTRY to a LITERAL of the entry's expected
 * TARGET type (a sourceMap value → the op's mapType; a choiceRules `then` → the target choice), leaving
 * scalar entries byte-identical; the executor still reads a plain {option: scalar} map / rule list exactly
 * as before (see resolveStructuralArgEntries). A choiceRules rule's `when` is now a boolean-terminal
 * condition PIPELINE, not a scalar: its ops' variable-shaped ARGUMENTS are pre-resolved to literals HERE
 * (resolvePipelineArgs), but the pipeline STRUCTURE is left intact — the executor RUNS it at execution time
 * over the op's TEXT input (it depends on the op's runtime value, so it cannot be pre-computed). A per-entry
 * union that fails to resolve fails that ENTRY closed: a sourceMap value resolves to a null target that
 * enumMap treats as unmapped → FAIL; a matched choiceRules `then` that resolves non-scalar makes
 * matchToChoice fail the op closed (NOT fall through to the fallback) — symmetric with enumMap. Either way
 * an unresolvable entry never OPENS the gate.
 *
 * There are NO cycles (an arg-variable references CONTEXT DATA, never another arg definition), so the
 * only unboundedness is nesting depth, hard-capped by PipelineLimits::MAX_ARG_VARIABLE_DEPTH
 * (beyond it an arg/entry resolves fail-soft to null/empty). A resolved arg value carrying reference-/
 * directive-like bytes is used LITERALLY by the pure executor and its output rides the SAME NUL-mask
 * path a resolved value does, so it is never re-interpreted (see resolvePipelineArgs).
 *
 * IF-BLOCKS: a text field may contain fenced `if-block` containers whose branches each carry a
 * boolean condition `{variableId, pipeline}`. resolveString evaluates the branches in order, resolves
 * the FIRST true branch's body recursively (nested directives / if-blocks included), and drops the
 * rest — falling to ELSE, or to '' when nothing matches. A failing condition is fail-closed to false.
 *
 * TRANSITIONAL flat tokens `{{trigger.*}}` / `{{steps.<key>.*}}` are still resolved (a plain
 * whitelisted dotted lookup) — NOT an expression engine. Values substituted by an EARLIER pass
 * (a directive's looked-up value) are masked before the flat pass, so an (untrusted) form value
 * that literally contains a `{{…}}` token can not be re-interpreted as a second-order reference.
 *
 * WHITELIST: only the roots `trigger`, `steps`, and `globals` (the {@see self::ROOTS} constant — the
 * single source) are readable in EVERY serialization; any other root (env, config, __proto__, …) is
 * not a reference, so no context/env exfiltration is possible.
 */
class WorkflowVariableResolver
{
    /**
     * Roots a reference may read from — anything else is not a reference. THE single source of truth
     * for the reference whitelist: the write-side validator ({@see \App\Modules\Workflows\Http\Requests\StoreWorkflowRequest})
     * reads this too, so adding a root (a new catalog source) is a one-place change here (per ADR-0021).
     *
     * `globals` is the workspace's user-created LITERAL constants, injected into the run context as a
     * `{<key>: <value>}` map by the step runner. Because the values are stored literals, a global ref
     * is a plain whitelisted dotted lookup (no graph, no cycles) — and a global VALUE that contains
     * reference-/directive-like bytes rides the SAME NUL-mask path a resolved value does, so it is
     * never re-interpreted as a reference (see resolveReferences / applyDefault).
     */
    public const ROOTS = ['trigger', 'steps', 'globals'];

    /** A whole string that is EXACTLY one flat token: {{ path }}. */
    private const FLAT_STANDALONE = '/^\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}$/';

    /** A flat token anywhere inside a larger string. */
    private const FLAT_EMBEDDED = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

    /** A whole string that is EXACTLY one variable directive: @[variable]("…"). */
    private const DIRECTIVE_STANDALONE = '/^@\[variable\]\("(.*)"\)$/s';

    /** A variable directive anywhere inside a larger string (payload may contain \" escapes). */
    private const DIRECTIVE_EMBEDDED = '/@\[variable\]\("((?:\\\\.|[^"\\\\])*)"\)/s';

    /**
     * The literal an ai-text directive opens with (`@[ai-text]("`) — a cheap presence guard before
     * the candidate-scan extraction. Its payload can NEST directives, whose double-escaped `\\")`
     * defeats a simple escape-walking regex, so ai-text is extracted by scanning for the correct
     * terminator, not by regex (see extractAiTextPayload — mirrors the FE editor's parser).
     */
    private const AI_TEXT_OPEN = '@[ai-text]("';

    /** Distinct from null so a genuine null context value is not read as "missing". */
    private const MISSING = "\0__workflow_resolver_missing__\0";

    /**
     * The composite SUBFIELDS a `<file>.<subfield>` reference may address, mapped to their key in the
     * file snapshot object. `type` is the human-facing alias for the snapshot's `mime_type`; the rest
     * are identity. These are exactly the fields VariableType's file descriptor advertises
     * (phase-2b). Resolving one collapses the single-file snapshot LIST to its element (multi-file →
     * first, fail-soft — true per-element iteration is the deferred R2 loop) then reads the mapped key.
     */
    private const FILE_SUBFIELDS = [
        'id' => 'id',
        'name' => 'name',
        'type' => 'mime_type',
        'size' => 'size',
        'url' => 'url',
    ];

    /** Recursion cap for nested if-blocks (margin over the editor's default maxDepth=3). */
    private const IF_BLOCK_MAX_DEPTH = 6;

    /** Recursion cap for nested ai-text (an ai-text prompt containing another). Beyond → ''. */
    private const AI_TEXT_MAX_DEPTH = 3;

    /** The run step-failure message an opt-in assert_present raises when its value resolves empty. */
    private const ASSERT_FAILED_MESSAGE = 'A required workflow value (assert_present) resolved empty.';

    private OperationResolver $operations;

    public function __construct(
        private OperationExecutor $executor,
        private WorkflowAiTextService $ai,
        ?OperationResolver $operations = null,
    ) {
        // Defaulted so the direct-`new` unit sites keep constructing with two args; the resolver is
        // stateless, so a fresh instance is safe. Threaded so a step-config pipeline may pre-resolve a
        // `fn:<uuid>` op's arguments — the functions ride the run context under FunctionScope.
        $this->operations = $operations ?? new OperationResolver;
    }

    /**
     * Resolve every reference in $value against $context. Scalars resolve directly, arrays
     * recursively. Non-string scalars (int/bool/null) pass through untouched. $typeMap is the
     * run's path → VariableType map (from WorkflowVariableCatalogService::runtimeTypeMap),
     * used to recover a directive/if-block variable's REAL base type; it is optional (an empty map
     * falls back to the pipeline's first-op input type).
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    public function resolve(mixed $value, array $context, array $typeMap = []): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->resolve($item, $context, $typeMap), $value);
        }

        if (is_string($value)) {
            return $this->resolveString($value, $context, $typeMap);
        }

        return $value;
    }

    /**
     * Resolve a single string that may contain fenced if-blocks, editor directives and/or flat
     * tokens. If-blocks collapse FIRST (their winning branch is resolved recursively), then the
     * remaining inline references resolve.
     *
     * A STANDALONE reference with no pipeline returns the TYPED looked-up value; a directive whose
     * pipeline is non-empty (or any embedded reference) is stringified into the surrounding text.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    public function resolveString(string $value, array $context, array $typeMap = []): mixed
    {
        return $this->resolveStringAt($value, $context, $typeMap, 0);
    }

    /**
     * resolveString carrying the current ai-text nesting depth (0 at a field's top level). Threaded
     * so a nested ai-text prompt — resolved recursively through here — can be depth-capped.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    private function resolveStringAt(string $value, array $context, array $typeMap, int $aiDepth): mixed
    {
        if (str_contains($value, '```if-block')) {
            $value = $this->resolveIfBlocks($value, $context, $typeMap, 1, $aiDepth);
        }

        return $this->resolveInline($value, $context, $typeMap, $aiDepth);
    }

    /**
     * The inline layer only (no if-block handling): ai-text directives, then standalone/embedded
     * variable directives + flat tokens. Extracted so an if-block branch body can be resolved
     * without re-running the fence pass.
     *
     * AI-TEXT runs FIRST and is MASKED to inert placeholders (so the variable/flat passes never
     * corrupt a raw ai-text payload — its prompt may contain `{{…}}` — nor re-interpret the AI
     * OUTPUT as a reference); the placeholders are restored to their generated strings at the end.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    private function resolveInline(string $value, array $context, array $typeMap, int $aiDepth): mixed
    {
        $aiStash = [];

        if (str_contains($value, self::AI_TEXT_OPEN)) {
            $value = $this->maskAiTextDirectives($value, $context, $typeMap, $aiDepth, $aiStash);
        }

        $result = $this->resolveReferences($value, $context, $typeMap);

        // Restore only affects the string (embedded) path; a masked field is never a standalone
        // typed value, so a non-string result can carry no placeholder.
        return $aiStash === [] || !is_string($result) ? $result : strtr($result, $aiStash);
    }

    /**
     * The variable-reference layer (unchanged from SB1): standalone/embedded directives + flat
     * tokens. Runs on a string in which any ai-text directive has already been masked to a
     * placeholder, so it sees only variable references.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    private function resolveReferences(string $value, array $context, array $typeMap): mixed
    {
        // Standalone directive → typed identity value OR a stringified pipeline result.
        if (preg_match(self::DIRECTIVE_STANDALONE, $value, $m) === 1) {
            return $this->resolveStandaloneDirective($m[1], $context, $typeMap);
        }

        // Standalone flat token → typed value (transitional).
        if (preg_match(self::FLAT_STANDALONE, $value, $m) === 1) {
            return $this->isReference($m[1]) ? $this->readContext($context, $m[1]) : $value;
        }

        // Embedded: replace directives first, then flat tokens, stringifying each. Each RESOLVED
        // directive value is MASKED to a NUL-delimited placeholder before the flat pass, so a form
        // value that itself contains a `{{…}}` token can never be re-scanned and resolved a second
        // time (second-order injection); the placeholders restore afterwards. Form values can't
        // carry NUL (Postgres rejects it), so a value can never forge a placeholder. Malformed /
        // non-reference directives keep their ORIGINAL bytes (unmasked) so a literal `@[…]` that
        // isn't a reference round-trips untouched.
        $stash = [];
        $value = preg_replace_callback(self::DIRECTIVE_EMBEDDED, function (array $m) use ($context, $typeMap, &$stash): string {
            $resolved = $this->resolveEmbeddedDirective($m[1], $m[0], $context, $typeMap);

            if ($resolved === $m[0]) {
                return $resolved; // untouched literal — safe to leave in the flat scan
            }

            $key = "\0var:" . count($stash) . "\0";
            $stash[$key] = $resolved;

            return $key;
        }, $value);

        $value = preg_replace_callback(self::FLAT_EMBEDDED, function (array $m) use ($context): string {
            if (!$this->isReference($m[1])) {
                return $m[0]; // non-reference flat token: leave literal
            }

            return $this->stringify($this->readContext($context, $m[1]));
        }, $value);

        return $stash === [] ? $value : strtr($value, $stash);
    }

    /**
     * Resolve the STRUCTURED union used by non-text fields, coercing the result to the expected type:
     *   { kind: 'literal',  value } → the value coerced to $expectedType.
     *   { kind: 'variable', ref, pipeline? } → the ref value, optionally piped through the ops
     *     executor (from ref.type), then coerced.
     * A non-union value is treated as a literal (so a bare scalar in a structured slot works).
     * A variable whose ref root is not whitelisted, any unresolvable path, or a pipeline FAILURE →
     * the coerced null (the field's soft default), never an exception.
     *
     * $argDepth is the ARG-VARIABLE nesting level of THIS field (0 at a top-level slot); it is carried
     * so that a variable used as an operation ARGUMENT — resolved recursively through here — can be
     * depth-capped (see resolvePipelineArgs). External callers never pass it.
     *
     * @param  array<string, mixed>  $context
     */
    public function resolveValueOrVariable(mixed $field, array $context, VariableType $expectedType, int $argDepth = 0): mixed
    {
        if (!is_array($field)) {
            return $this->coerce($field, $expectedType);
        }

        if (($field['kind'] ?? null) === 'variable') {
            return $this->resolveVariableUnion($field, $context, $expectedType, $argDepth);
        }

        // literal (default): coerce the literal value.
        return $this->coerce($field['value'] ?? null, $expectedType);
    }

    /**
     * A `{ kind: 'variable', ref, pipeline? }` field. Without a pipeline it is the legacy coerced
     * ref lookup; with one the ref value is transformed from `ref.type` through the executor and the
     * typed result coerced to $expectedType (a failure → coerced null). Each op's variable-shaped
     * ARGUMENTS are pre-resolved to literals here (resolvePipelineArgs) so the executor stays a pure
     * transformer; $argDepth carries this field's arg-variable nesting level to enforce the depth cap.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $context
     */
    private function resolveVariableUnion(array $field, array $context, VariableType $expectedType, int $argDepth): mixed
    {
        $ref = $field['ref'] ?? null;
        $path = $this->refPath($ref);
        $raw = $path !== null && $this->isReference($path) ? $this->readContext($context, $path) : null;
        $raw = $this->applyDefault($raw, $field['default'] ?? null);

        $pipeline = $field['pipeline'] ?? null;

        if (is_array($pipeline) && $pipeline !== []) {
            $refType = VariableType::tryFrom((string) (is_array($ref) ? ($ref['type'] ?? '') : '')) ?? $expectedType;
            $refType = $this->arrayPipelineBaseType($raw, $refType, $pipeline);
            $result = $this->executor->execute($raw, $refType, $this->resolvePipelineArgs($pipeline, $context, $argDepth), $context);

            if ($result->hard) {
                throw new RuntimeException(self::ASSERT_FAILED_MESSAGE);
            }

            return $result->failed ? $this->coerce(null, $expectedType) : $this->coerce($result->value, $expectedType);
        }

        return $this->coerce($raw, $expectedType);
    }

    /**
     * The base type a value-or-variable pipeline runs FROM, recovered for an ARRAY source (array-ops
     * wave 3). An array<object> (repeater) / array<file> reference DEGRADES its wire `ref.type` to a scalar
     * (text/file), so a pipeline that LEADS with an array op (count/at/map/filter/sort/reduce) would hand
     * the executor a scalar base and fail the array op closed — even though the runtime VALUE is a real
     * list. When the raw value IS a list AND the pipeline leads with an array op, recover MULTI (the
     * executor's array type) so the transform runs. Guarded on the value actually being a list, so a
     * genuine scalar (an invalid leading-array-op config the validator rejects) still fails closed exactly
     * as before. Every non-array source keeps its declared type — a provable no-op.
     *
     * @param  array<int, mixed>  $pipeline
     */
    private function arrayPipelineBaseType(mixed $raw, VariableType $refType, array $pipeline): VariableType
    {
        if ($refType === VariableType::MULTI || !is_array($raw) || !array_is_list($raw)) {
            return $refType;
        }

        // Only a leading ARRAY op (a built-in) triggers the MULTI recovery; a custom function is never one,
        // so the empty function set suffices here. Routed through the resolver so no direct Operation::tryFrom
        // survives in the resolver (the completeness gate).
        $first = $pipeline[0] ?? null;
        $op = is_array($first) ? $this->operations->resolve((string) ($first['op'] ?? $first['operationId'] ?? ''), []) : null;

        return $op !== null && $op->isArrayOp() ? VariableType::MULTI : $refType;
    }

    // ---- argument variables (phase-4a) ----------------------------------------

    /**
     * Pre-resolve a pipeline's variable-shaped op ARGUMENTS for a caller that runs the (pure)
     * OperationExecutor ITSELF instead of going through resolveValueOrVariable — today the
     * trigger gate (WorkflowConditionEngine, B6), whose base value is a condition SOURCE rather than a
     * ref. It is a thin, deliberately narrow window onto the private resolvePipelineArgs at a top-level
     * field's arg depth (0), so what an argument MEANS can never drift between the gate and the step
     * runtime — the whole reason the gate does not grow its own argument reader.
     *
     * $context is the standard run context (`{trigger, steps, globals}`), so an argument ref uses the
     * module-wide FULL path exactly as it does in a step config.
     *
     * LIKE EVERY OTHER RESOLUTION PATH this may THROW the run's standard assert_present step-failure
     * (see resolveVariableUnion); the gate, which must never throw, catches it into its fail-closed
     * false.
     *
     * @param  array<int, mixed>  $pipeline
     * @param  array<string, mixed>  $context
     * @return array<int, mixed>
     */
    public function resolveArgsForPipeline(array $pipeline, array $context): array
    {
        return $this->resolvePipelineArgs($pipeline, $context, 0);
    }

    /**
     * Pre-resolve every VARIABLE-shaped argument of every op in $pipeline into a LITERAL, returning a
     * NEW pipeline the (pure) executor can run with literal args exactly as before. Each op is matched
     * to its Operation so only DECLARED args (per argDescriptors) are considered; a foreign key,
     * a non-array step, or an unknown op is left untouched (the executor fail-closes on it as today).
     *
     * $argDepth is the nesting level of the pipeline's OWNING field; an argument therefore sits at level
     * $argDepth + 1 (see resolveArgVariable's cap check). A plain literal / map / list argument is not a
     * variable union, so it passes through byte-identically — literal args behave EXACTLY as before.
     *
     * INJECTION SAFETY: a resolved argument value is a literal handed to the PURE executor, which never
     * scans for references; the executor's OUTPUT then rides the SAME NUL-mask path a resolved directive
     * value does (resolveReferences' stash / the standalone return), so an argument value carrying
     * `{{…}}` / `@[…]` bytes is never re-interpreted as a second reference.
     *
     * @param  array<int, mixed>  $pipeline
     * @param  array<string, mixed>  $context
     * @return array<int, mixed>
     */
    private function resolvePipelineArgs(array $pipeline, array $context, int $argDepth): array
    {
        return array_map(fn ($step) => $this->resolveStepArgs($step, $context, $argDepth), $pipeline);
    }

    /**
     * Resolve one pipeline step's variable-shaped arguments. Reads the op id from either the `op` or the
     * editor's `operationId` key (like the executor), then rewrites only the args declared by the op's
     * descriptors that are a variable union — every other key (stepId/outputType/foreign) is preserved.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveStepArgs(mixed $step, array $context, int $argDepth): mixed
    {
        if (!is_array($step)) {
            return $step;
        }

        // Resolve through the OperationResolver (built-ins ∪ the context's custom functions) so a
        // `fn:<uuid>` op's variable-shaped arguments are pre-resolved to literals for the pure executor,
        // exactly like a built-in op's. The functions ride the run context under FunctionScope; with none
        // threaded a `fn:` id resolves null and the step is left untouched (byte-identical to before).
        $op = $this->operations->resolve((string) ($step['op'] ?? $step['operationId'] ?? ''), FunctionScope::fromContext($context)->functions);

        if ($op === null) {
            return $step;
        }

        $args = is_array($step['args'] ?? null) ? $step['args'] : [];

        foreach ($op->argDescriptors() as $descriptor) {
            if (array_key_exists($descriptor->id, $args)) {
                $args[$descriptor->id] = $this->resolveDescriptorArg($args[$descriptor->id], $context, $descriptor, $argDepth);
            }
        }

        $step['args'] = $args;

        return $step;
    }

    /**
     * Resolve ONE declared operation argument to the literal(s) the PURE executor consumes. Two shapes:
     *   - STRUCTURAL container (sourceMap / choiceRules): a plain map / rule list whose ENTRIES may EACH be
     *     a value-or-variable union. The container itself is never a variable — each union entry is walked
     *     and pre-resolved to a literal of the entry's TARGET type (resolveStructuralArgEntries), leaving
     *     scalar entries byte-identical.
     *   - EVERY OTHER control: the WHOLE argument may be a value-or-variable union (resolveArgVariable); a
     *     plain literal passes through untouched.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveDescriptorArg(mixed $value, array $context, OperationArg $descriptor, int $argDepth): mixed
    {
        // A SCOPE reference (element/index, array-ops wave 2) is deliberately LEFT untouched: it resolves
        // per-iteration inside the executor's element sub-run against the scoped overlay, and it must NEVER
        // resolve here against the global context (its root is not whitelisted — pre-resolving it would
        // fail-soft it to null and defeat the whole element pipeline).
        if ($this->isScopeVariable($value)) {
            return $value;
        }

        if ($descriptor->type->argVariablePolicy()->isStructural()) {
            return $this->resolveStructuralArgEntries($value, $context, $descriptor, $argDepth);
        }

        if ($this->isVariableArg($value)) {
            return $this->resolveArgVariable($value, $context, $descriptor, $argDepth);
        }

        return $value;
    }

    /**
     * Resolve ONE WHOLE variable-shaped argument (a VALUE / OPTION / OPTIONS control) to the literal the
     * PURE executor's arg reader consumes, driven by the arg's ArgVariablePolicy (the SINGLE source shared
     * with the write-validator). Reads the ref (+ optional sub-pipeline) via resolveValueOrVariable and
     * coerces to the policy's coerceTo (a scalar for value/option, an array for options). An out-of-set
     * option value is a RUNTIME concern — the op's reader fail-softs on it (not-found / fallback), never a
     * crash. Over the nesting cap → fail-soft (the coerced-null empty arg the op then fail-closes on).
     * STRUCTURAL containers never reach here — their entries are walked in resolveStructuralArgEntries.
     *
     * DATE ARGUMENTS are narrowed one step further (argWireDate) — see that method: coerce() speaks the
     * ISO-8601 instant a step FIELD stores, while an operation ARGUMENT is read by the executor's strict
     * Y-m-d reader.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $context
     */
    private function resolveArgVariable(array $field, array $context, OperationArg $descriptor, int $argDepth): mixed
    {
        $policy = $descriptor->type->argVariablePolicy();

        // This argument sits one level below the pipeline it lives in. Because there are NO cycles, the
        // cap only bites pathological nesting; beyond it the argument resolves fail-soft (the coerced-null
        // empty arg the op fail-closes on).
        if ($argDepth + 1 > PipelineLimits::MAX_ARG_VARIABLE_DEPTH) {
            return $this->coerce(null, $policy->coerceTo);
        }

        $resolved = $this->resolveValueOrVariable($field, $context, $policy->coerceTo, $argDepth + 1);

        return $policy->coerceTo === VariableType::DATE ? $this->argWireDate($resolved) : $resolved;
    }

    // ---- structural containers: per-entry value-or-variable (Defect-3) --------

    /**
     * Pre-resolve the ENTRIES of a STRUCTURAL container argument (sourceMap / choiceRules). The container
     * is a plain map / rule list — NEVER itself a variable; a non-array (or a legacy whole-arg union) is
     * left as-is for the executor's array reader to fail closed. Each entry is a LITERAL scalar (left
     * byte-identical) OR a value-or-variable union pre-resolved to a literal of the entry's TARGET type.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveStructuralArgEntries(mixed $value, array $context, OperationArg $descriptor, int $argDepth): mixed
    {
        if (!is_array($value) || $this->isVariableArg($value)) {
            return $value;
        }

        return match ($descriptor->type) {
            OperationArgType::SOURCE_MAP => $this->resolveSourceMapEntries($value, $context, $descriptor->mapType ?? VariableType::TEXT, $argDepth),
            OperationArgType::CHOICE_RULES => $this->resolveChoiceRulesEntries($value, $context, $argDepth),
            // An ELEMENT PIPELINE (map/filter/sort/reduce, wave 2): pre-resolve its ops' NON-scope arg-
            // variables to literals (exactly like a choiceRules `when`), leaving the pipeline STRUCTURE and
            // any element/index SCOPE references intact — the executor runs the pipeline and resolves the
            // scope references per element. A reduce SEED (`{type, value}`) is a plain literal: untouched.
            OperationArgType::ELEMENT_PIPELINE => is_array($value) && array_is_list($value)
                ? $this->resolvePipelineArgs($value, $context, $argDepth)
                : $value,
            default => $value,
        };
    }

    /**
     * A sourceMap `{optionValue: Entry}` map: resolve each entry to a literal of the map's TARGET type
     * ($entryType = the op's mapType — text/number/date, or enum for enum_to_choice), preserving the option
     * keys. A scalar entry is untouched.
     *
     * @param  array<array-key, mixed>  $map
     * @param  array<string, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function resolveSourceMapEntries(array $map, array $context, VariableType $entryType, int $argDepth): array
    {
        $out = [];

        foreach ($map as $option => $entry) {
            $out[$option] = $this->resolveStructuralEntry($entry, $context, $entryType, $argDepth);
        }

        return $out;
    }

    /**
     * A choiceRules `[{when, then}]` list. Each rule's `when` is now a boolean-terminal condition
     * PIPELINE (run at execution time by the executor over the op's TEXT input) — its ops' variable-
     * shaped ARGUMENTS are pre-resolved to literals HERE (resolvePipelineArgs, exactly as a directive /
     * if-block / value pipeline is), leaving the pipeline STRUCTURE intact for the executor to run.
     * Each rule's `then` is resolved to a literal CHOICE (enum) value (resolveStructuralEntry). A
     * malformed rule (non-array) passes through for the executor's matchToChoice reader to fail closed.
     *
     * @param  array<int, mixed>  $rules
     * @param  array<string, mixed>  $context
     * @return array<int, mixed>
     */
    private function resolveChoiceRulesEntries(array $rules, array $context, int $argDepth): array
    {
        return array_map(function ($rule) use ($context, $argDepth) {
            if (!is_array($rule)) {
                return $rule;
            }

            if (is_array($rule['when'] ?? null)) {
                $rule['when'] = $this->resolvePipelineArgs($rule['when'], $context, $argDepth);
            }

            if (array_key_exists('then', $rule)) {
                $rule['then'] = $this->resolveStructuralEntry($rule['then'], $context, VariableType::ENUM, $argDepth);
            }

            return $rule;
        }, $rules);
    }

    /**
     * Resolve ONE structural-container entry. A LITERAL scalar returns byte-identical (the wire is a bare
     * scalar, exactly as before). A value-or-variable union is pre-resolved via the SAME machinery a
     * top-level arg-variable uses (resolveValueOrVariable), coerced to the entry's expected type and, for a
     * DATE target, narrowed to the executor's strict Y-m-d wire (argWireDate) — one arg-variable level
     * deeper, depth-capped by MAX_ARG_VARIABLE_DEPTH. A union that fails to resolve leaves a null (non-scalar)
     * target that fails THIS ENTRY closed: enumMap treats a sourceMap null as unmapped → FAIL; matchToChoice,
     * when the enclosing rule's `when` MATCHES but this `then` is non-scalar, fails the op closed rather than
     * returning the fallback — so a per-entry union that cannot resolve never OPENS the gate. INJECTION: the
     * resolved literal is handed to the PURE executor and its output rides
     * the SAME NUL-mask path, so entry bytes like `{{…}}` / `@[…]` are used literally, never re-interpreted.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveStructuralEntry(mixed $entry, array $context, VariableType $entryType, int $argDepth): mixed
    {
        if (!$this->isVariableArg($entry)) {
            return $entry;
        }

        if ($argDepth + 1 > PipelineLimits::MAX_ARG_VARIABLE_DEPTH) {
            return null;
        }

        $resolved = $this->resolveValueOrVariable($entry, $context, $entryType, $argDepth + 1);

        return $entryType === VariableType::DATE ? $this->argWireDate($resolved) : $resolved;
    }

    /**
     * Narrow a resolved DATE argument to the module's canonical WIRE date (strict `Y-m-d`).
     *
     * coerce(DATE) yields an ISO-8601 INSTANT (`2026-02-01T00:00:00+00:00`) — right for a step FIELD,
     * which stores a timestamp, but not for an operation ARGUMENT: every date arg is read by
     * OperationExecutor::parseDate, which accepts ONLY a strict `Y-m-d` (the exact bytes a
     * LITERAL date argument carries). Without this narrowing a date-typed arg-variable coerced to a
     * shape its own reader rejects, so it ALWAYS failed the operation closed — silently, in both the
     * step runtime and (since B6) the trigger gate. Fail-soft: an unparseable/absent value stays null,
     * which is the same empty argument the reader already fail-closes on.
     */
    private function argWireDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether a value is a variable-union argument (`{kind:'variable', …}`) rather than a literal.
     * Delegates to ValueOrVariable — the ONE definition of the union shape, shared with the write
     * validator and with the executor's defensive rejection of a union that bypassed pre-resolution.
     */
    private function isVariableArg(mixed $value): bool
    {
        return ValueOrVariable::isVariable($value);
    }

    /**
     * Whether $value is a SCOPE reference (array-ops wave 2): a variable union whose ref addresses the
     * synthetic `element` / `index` scope. Delegates to ScopeRef — the ONE source-aware definition shared
     * with the write-validator and the executor — so an ordinary global / trigger / step ref whose path
     * leaf merely HAPPENS to be `element` / `index` (source ≠ `scope`) is NOT mis-flagged as scope and is
     * pre-resolved against the real context here, instead of leaking the loop element/index into its slot.
     * A true scope reference is never pre-resolved here — it resolves per element inside the executor's
     * element sub-run — so this predicate keeps it out of the standard context-based resolution paths.
     */
    private function isScopeVariable(mixed $value): bool
    {
        return ScopeRef::isScopeUnion($value);
    }

    // ---- directive resolution -------------------------------------------------

    /**
     * A standalone directive (the whole field is one directive). Identity (empty pipeline) returns
     * the typed value unchanged; a non-empty pipeline transforms and STRINGIFIES the result (a
     * directive only ever lives in a text field). A malformed / non-reference directive resolves null.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    private function resolveStandaloneDirective(string $rawPayload, array $context, array $typeMap): mixed
    {
        $directive = $this->decodeDirective($rawPayload);

        if ($directive === null || !$this->isReference($directive['id'])) {
            return null;
        }

        $raw = $this->applyDefault($this->readContext($context, $directive['id']), $directive['default']);

        if ($directive['pipeline'] === []) {
            // A directive ONLY ever lives in a text FIELD (never a structured value-or-variable
            // slot — those use resolveValueOrVariable). So an identity chip that IS the whole
            // field stringifies its looked-up value: a bare non-text variable (multi/number/
            // boolean) becomes text, not the raw array/scalar that would otherwise reach
            // requireString('title') and hard-fail the run with a misleading "requires a title".
            return $this->stringify($raw);
        }

        return $this->applyDirectivePipeline($raw, $directive, $context, $typeMap);
    }

    /**
     * An embedded directive (inside surrounding text). Always stringifies; identity stringifies the
     * looked-up value, a pipeline stringifies the transformed result. Malformed / non-reference
     * directives stay literal so the surrounding text is untouched.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    private function resolveEmbeddedDirective(string $rawPayload, string $original, array $context, array $typeMap): string
    {
        $directive = $this->decodeDirective($rawPayload);

        if ($directive === null || !$this->isReference($directive['id'])) {
            return $original;
        }

        $raw = $this->applyDefault($this->readContext($context, $directive['id']), $directive['default']);

        if ($directive['pipeline'] === []) {
            return $this->stringify($raw);
        }

        return $this->applyDirectivePipeline($raw, $directive, $context, $typeMap);
    }

    /**
     * Run a directive's pipeline over its base value and stringify the result. A pipeline failure
     * yields '' (fail-closed), leaving the field's own doctrine to react — a blank required title
     * hard-fails the run, a blank description/name/guidelines is simply empty. The ONE exception is an
     * opt-in assert_present that resolved empty: it re-raises as a run step-failure ("force a value").
     *
     * @param  array{id: string, pipeline: array<int, mixed>, type: ?string, default: mixed}  $directive
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    private function applyDirectivePipeline(mixed $raw, array $directive, array $context, array $typeMap): string
    {
        $baseType = $this->pipelineBaseType($directive['id'], $directive['pipeline'], $typeMap)
            ?? VariableType::tryFrom((string) ($directive['type'] ?? ''))
            ?? VariableType::TEXT;

        // A directive lives at a text field's top level (arg-variable depth 0); pre-resolve any
        // variable-shaped op arguments to literals so the executor stays a pure transformer.
        $result = $this->executor->execute($raw, $baseType, $this->resolvePipelineArgs($directive['pipeline'], $context, 0), $context);

        if ($result->hard) {
            throw new RuntimeException(self::ASSERT_FAILED_MESSAGE);
        }

        return $result->failed ? '' : $this->stringifyResult($result->value, $result->type);
    }

    /**
     * Decode a variable directive payload to its identity + pipeline. Tolerates malformed JSON
     * (returns null). The payload is the legacy byte-format: the inner JSON with each `"` written as
     * `\"`. Returns `{ id, pipeline, type, default }` where `type` is the directive's degraded
     * primitive and `default` is the optional per-reference literal (null when absent/non-scalar).
     *
     * @return array{id: string, pipeline: array<int, mixed>, type: ?string, default: mixed}|null
     */
    private function decodeDirective(string $rawPayload): ?array
    {
        try {
            $decoded = json_decode(str_replace('\\"', '"', $rawPayload), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $data = $decoded['data'] ?? $decoded;

        if (!is_array($data)) {
            return null;
        }

        $id = $data['id'] ?? null;

        if (!is_string($id) || $id === '') {
            return null;
        }

        return [
            'id' => $id,
            'pipeline' => is_array($data['pipeline'] ?? null) ? $data['pipeline'] : [],
            'type' => is_string($data['type'] ?? null) ? $data['type'] : null,
            // Optional per-reference DEFAULT (append-only): a LITERAL scalar substituted for a null/''
            // lookup BEFORE the pipeline runs. Absent / non-scalar → null (the reference is unchanged).
            'default' => is_scalar($data['default'] ?? null) ? $data['default'] : null,
        ];
    }

    // ---- ai-text --------------------------------------------------------------

    /**
     * Replace every `@[ai-text]("…")` span in $value with an inert placeholder, stashing the
     * GENERATED string per placeholder (restored by resolveInline after the variable/flat passes).
     * Each directive's payload is extracted by the candidate-terminator scan (extractAiTextPayload)
     * so a NESTED directive inside the prompt cannot end the span early. A malformed occurrence is
     * left literal (the `@[` is emitted and scanning resumes past it), never throwing.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     * @param  array<string, string>  $stash  placeholder → generated text (populated here)
     */
    private function maskAiTextDirectives(string $value, array $context, array $typeMap, int $aiDepth, array &$stash): string
    {
        $out = '';
        $cursor = 0;
        $openLen = strlen(self::AI_TEXT_OPEN);

        while (true) {
            $start = strpos($value, self::AI_TEXT_OPEN, $cursor);

            if ($start === false) {
                return $out . substr($value, $cursor);
            }

            // The opening quote is the LAST char of AI_TEXT_OPEN.
            $extracted = $this->extractAiTextPayload($value, $start + $openLen - 1);

            if ($extracted === null) {
                // Not a well-formed directive — keep `@[` literal and resume just past it.
                $out .= substr($value, $cursor, $start - $cursor + 2);
                $cursor = $start + 2;

                continue;
            }

            $out .= substr($value, $cursor, $start - $cursor);

            $token = "\0ai:" . count($stash) . "\0";
            $stash[$token] = $this->resolveAiText($extracted['payload'], $context, $typeMap, $aiDepth);
            $out .= $token;
            $cursor = $extracted['nextIndex'];
        }
    }

    /**
     * Extract an ai-text directive's `"…"` payload starting at the opening quote. Mirrors the FE
     * editor's parser (markdown.ts extractDirectivePayload): a `")` is the real terminator only when
     * the prefix before it — after unescaping `\"`→`"` — parses as a JSON object. A nested directive
     * produces a SHORTER prefix that is NOT valid JSON, so we scan the candidate `")` terminators
     * from LAST to FIRST and take the first (outermost) decodable one. Returns the raw payload and
     * the index just past the closing `")`, or null when no candidate decodes.
     *
     * @return array{payload: string, nextIndex: int}|null
     */
    private function extractAiTextPayload(string $text, int $quoteIndex): ?array
    {
        $start = $quoteIndex + 1;
        $len = strlen($text);
        $candidates = [];

        for ($i = $start; $i < $len - 1; $i++) {
            if ($text[$i] === '"' && $text[$i + 1] === ')') {
                $candidates[] = $i;
            }
        }

        for ($c = count($candidates) - 1; $c >= 0; $c--) {
            $end = $candidates[$c];
            $payload = substr($text, $start, $end - $start);

            if ($this->isDecodableAiTextPayload($payload)) {
                return ['payload' => $payload, 'nextIndex' => $end + 2];
            }
        }

        return null;
    }

    /** True when a raw ai-text payload unescapes (`\"`→`"`) and JSON-parses to an object/array. */
    private function isDecodableAiTextPayload(string $payload): bool
    {
        $decoded = json_decode(str_replace('\\"', '"', $payload), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    }

    /**
     * Generate the text for one ai-text directive: recursively resolve its `prompt` (SAME context /
     * typeMap, one ai-depth deeper — so nested variables/if-blocks/ai-text all resolve before the
     * AI sees it), then hand the resolved prompt + persona to WorkflowAiTextService. Over the nesting
     * cap → '' with NO AI call. The service is fail-closed, so this always returns a string.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    private function resolveAiText(string $payload, array $context, array $typeMap, int $aiDepth): string
    {
        $thisDepth = $aiDepth + 1;

        if ($thisDepth > self::AI_TEXT_MAX_DEPTH) {
            return '';
        }

        $directive = $this->decodeAiTextDirective($payload);

        if ($directive === null) {
            return '';
        }

        $resolvedPrompt = $this->resolveStringAt($directive['prompt'], $context, $typeMap, $thisDepth);
        $resolvedPrompt = is_string($resolvedPrompt) ? $resolvedPrompt : $this->stringify($resolvedPrompt);

        return $this->ai->generate($resolvedPrompt, WorkflowAiPersona::fromNullable($directive['personaId']));
    }

    /**
     * Decode an ai-text payload to `{ prompt, personaId }`, tolerating malformed JSON (null). The
     * payload is the same byte-format as a variable directive (inner JSON with `"`→`\"`); after
     * json_decode the `prompt` carries any nested `@[variable]("…")` in its normal single-escaped
     * form the reference passes expect. `labels` is intentionally not consumed by the runtime.
     *
     * @return array{prompt: string, personaId: ?string}|null
     */
    private function decodeAiTextDirective(string $payload): ?array
    {
        try {
            $decoded = json_decode(str_replace('\\"', '"', $payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $data = is_array($decoded) ? ($decoded['data'] ?? $decoded) : null;

        if (!is_array($data)) {
            return null;
        }

        return [
            'prompt' => is_string($data['prompt'] ?? null) ? $data['prompt'] : '',
            'personaId' => is_string($data['personaId'] ?? null) ? $data['personaId'] : null,
        ];
    }

    // ---- if-blocks ------------------------------------------------------------

    /**
     * Resolve every top-level fenced if-block in $value, replacing each with its winning branch's
     * fully-resolved text. Line-scanned with fence-depth tracking so nested if-blocks are carried
     * intact into a branch body and re-parsed only when that branch wins.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    private function resolveIfBlocks(string $value, array $context, array $typeMap, int $depth, int $aiDepth): string
    {
        $lines = explode("\n", $value);
        $out = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            if (preg_match('/^\s*```if-block\b/', $lines[$i]) === 1) {
                [$body, $next] = $this->collectIfBlockBody($lines, $i + 1, $n);
                $out[] = $this->resolveOneIfBlock($body, $context, $typeMap, $depth, $aiDepth);
                $i = $next;

                continue;
            }

            $out[] = $lines[$i];
            $i++;
        }

        return implode("\n", $out);
    }

    /**
     * Collect the body lines of one if-block from $start until its matching closing fence, tracking
     * nested `if-block` fences so an inner block's close does not end the outer one. Returns the body
     * lines and the index just past the closing fence.
     *
     * @param  array<int, string>  $lines
     * @return array{0: array<int, string>, 1: int}
     */
    private function collectIfBlockBody(array $lines, int $start, int $n): array
    {
        $body = [];
        $fenceDepth = 1;
        $i = $start;

        for (; $i < $n; $i++) {
            $trimmed = trim($lines[$i]);

            if (preg_match('/^```if-block\b/', $trimmed) === 1) {
                $fenceDepth++;
                $body[] = $lines[$i];

                continue;
            }

            if ($trimmed === '```') {
                $fenceDepth--;

                if ($fenceDepth === 0) {
                    $i++;
                    break;
                }

                $body[] = $lines[$i];

                continue;
            }

            $body[] = $lines[$i];
        }

        return [$body, $i];
    }

    /**
     * Pick and resolve one if-block's winning branch: the first IF/ELSE_IF whose condition is true,
     * else ELSE, else ''. Over the recursion cap resolves to '' (never loops).
     *
     * @param  array<int, string>  $bodyLines
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    private function resolveOneIfBlock(array $bodyLines, array $context, array $typeMap, int $depth, int $aiDepth): string
    {
        if ($depth > self::IF_BLOCK_MAX_DEPTH) {
            return '';
        }

        $elseBody = null;

        foreach ($this->splitIfBranches($bodyLines) as $branch) {
            if ($branch['kind'] === 'else') {
                $elseBody ??= $branch['body'];

                continue;
            }

            if ($this->evaluateBranchCondition($branch['condition'], $context, $typeMap)) {
                return $this->resolveBranchBody($branch['body'], $context, $typeMap, $depth, $aiDepth);
            }
        }

        return $elseBody !== null ? $this->resolveBranchBody($elseBody, $context, $typeMap, $depth, $aiDepth) : '';
    }

    /**
     * Split an if-block body into ordered branches at its `[[IF]]` / `[[ELSE_IF]]` / `[[ELSE]]`
     * markers, tracking nested fences so a nested if-block's markers are NOT read as branches.
     *
     * @param  array<int, string>  $lines
     * @return array<int, array{kind: string, condition: array<string, mixed>|null, body: array<int, string>}>
     */
    private function splitIfBranches(array $lines): array
    {
        $branches = [];
        $current = null;
        $fenceDepth = 0;

        foreach ($lines as $raw) {
            $trimmed = trim($raw);

            if (preg_match('/^```if-block\b/', $trimmed) === 1) {
                $fenceDepth++;
                $this->appendBranchLine($current, $raw);

                continue;
            }

            if ($trimmed === '```' && $fenceDepth > 0) {
                $fenceDepth--;
                $this->appendBranchLine($current, $raw);

                continue;
            }

            if ($fenceDepth === 0 && preg_match('/^\[\[(IF|ELSE_IF|ELSE)(.*)?\]\]$/', $trimmed, $m) === 1) {
                if ($current !== null) {
                    $branches[] = $current;
                }

                $kind = $this->branchKind($m[1]);
                $current = [
                    'kind' => $kind,
                    'condition' => $kind === 'else' ? null : $this->parseBranchCondition($m[2] ?? ''),
                    'body' => [],
                ];

                continue;
            }

            $this->appendBranchLine($current, $raw);
        }

        if ($current !== null) {
            $branches[] = $current;
        }

        return $branches;
    }

    /**
     * Append a body line to the branch under construction (ignored before the first marker).
     *
     * @param  array{kind: string, condition: array<string, mixed>|null, body: array<int, string>}|null  $current
     */
    private function appendBranchLine(?array &$current, string $line): void
    {
        if ($current !== null) {
            $current['body'][] = $line;
        }
    }

    /** Map a branch keyword to its kind. */
    private function branchKind(string $keyword): string
    {
        return match ($keyword) {
            'IF' => 'if',
            'ELSE_IF' => 'else-if',
            default => 'else',
        };
    }

    /**
     * Parse a branch marker's JSON meta to its condition object (`{variableId, pipeline, …}`),
     * tolerating the two shapes the editor emits (a wrapping `{id, condition}` or the bare
     * condition). Malformed JSON → null (the branch is then fail-closed to false).
     *
     * @return array<string, mixed>|null
     */
    private function parseBranchCondition(string $meta): ?array
    {
        $meta = trim($meta);

        if ($meta === '') {
            return null;
        }

        try {
            $decoded = json_decode($meta, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $condition = $decoded['condition'] ?? $decoded;

        return is_array($condition) ? $condition : null;
    }

    /**
     * Evaluate an if-branch condition: read `variableId` off the context (missing / non-reference →
     * false), flow it through the executor as its real base type, and require a boolean-true terminal.
     * Any executor failure is fail-closed to false.
     *
     * @param  array<string, mixed>|null  $condition
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    private function evaluateBranchCondition(?array $condition, array $context, array $typeMap): bool
    {
        if ($condition === null) {
            return false;
        }

        $variableId = $condition['variableId'] ?? null;

        if (!is_string($variableId) || $variableId === '' || !$this->isReference($variableId)) {
            return false;
        }

        $raw = $this->readContext($context, $variableId, self::MISSING);

        if ($raw === self::MISSING) {
            return false;
        }

        $pipeline = is_array($condition['pipeline'] ?? null) ? $condition['pipeline'] : [];
        $baseType = $this->pipelineBaseType($variableId, $pipeline, $typeMap) ?? VariableType::BOOLEAN;

        // An if-block condition is a top-level pipeline (arg-variable depth 0); pre-resolve its ops'
        // variable-shaped arguments to literals before the pure executor evaluates the branch.
        $result = $this->executor->execute($raw, $baseType, $this->resolvePipelineArgs($pipeline, $context, 0), $context);

        return !$result->failed
            && $result->type === VariableType::BOOLEAN
            && $result->value === true;
    }

    /**
     * Resolve a chosen branch body: nested if-blocks first (depth + 1), then the inline layer. The
     * result is always a string (a lone typed directive is stringified into the branch text).
     *
     * @param  array<int, string>  $bodyLines
     * @param  array<string, mixed>  $context
     * @param  array<string, VariableType|string>  $typeMap
     */
    private function resolveBranchBody(array $bodyLines, array $context, array $typeMap, int $depth, int $aiDepth): string
    {
        $joined = trim(implode("\n", $bodyLines));

        if ($joined === '') {
            return '';
        }

        if (str_contains($joined, '```if-block')) {
            $joined = $this->resolveIfBlocks($joined, $context, $typeMap, $depth + 1, $aiDepth);
        }

        $resolved = $this->resolveInline($joined, $context, $typeMap, $aiDepth);

        return is_string($resolved) ? $resolved : $this->stringify($resolved);
    }

    // ---- shared helpers -------------------------------------------------------

    /**
     * The REAL base type of a piped reference: the run type map by path, else the first op's declared
     * input type (the editor authored the pipeline against the real type), else null.
     *
     * @param  array<int, mixed>  $pipeline
     * @param  array<string, VariableType|string>  $typeMap
     */
    private function pipelineBaseType(string $path, array $pipeline, array $typeMap): ?VariableType
    {
        $mapped = $typeMap[$path] ?? null;

        if ($mapped instanceof VariableType) {
            return $mapped;
        }

        if (is_string($mapped)) {
            $type = VariableType::tryFrom($mapped);

            if ($type !== null) {
                return $type;
            }
        }

        $first = $pipeline[0] ?? null;

        if (is_array($first)) {
            // Routed through the resolver (built-ins only — the run type map is the authoritative base-type
            // source for a real ref, so this first-op fallback need not resolve a leading custom function)
            // so no direct Operation::tryFrom survives in the resolver.
            $op = $this->operations->resolve((string) ($first['op'] ?? $first['operationId'] ?? ''), []);

            if ($op !== null) {
                return $op->inputType();
            }
        }

        return null;
    }

    /**
     * The dotted path of a structured `variable` ref. Prefers an explicit `source` + `path` pair
     * (`trigger` + `fields.abc` → `trigger.fields.abc`); a `path` that already carries a whitelisted
     * root is accepted as-is (tolerant of either shape).
     */
    private function refPath(mixed $ref): ?string
    {
        if (!is_array($ref)) {
            return null;
        }

        $path = $ref['path'] ?? null;

        if (!is_string($path) || $path === '') {
            return null;
        }

        $source = $ref['source'] ?? null;

        if (is_string($source) && $source !== '' && !str_starts_with($path, $source . '.') && $path !== $source) {
            return $source . '.' . $path;
        }

        return $path;
    }

    /** Whether a dotted path is rooted at a whitelisted context root. */
    private function isReference(string $path): bool
    {
        return in_array(explode('.', $path, 2)[0], self::ROOTS, true);
    }

    /**
     * Read a whitelisted dotted PATH off the run context. Ordinary paths resolve through Arr::get
     * exactly as before; the ONE addition (append-only, phase-2b) is FILE-SUBFIELD access — a path
     * whose tail is `…<file>.name` / `.url` / `.id` / `.size` / `.type` that Arr::get can NOT resolve
     * directly (a file answer is a snapshot LIST, so the subfield is a level down) is served by
     * collapsing the parent snapshot to its single file and reading the mapped key. $default is
     * returned when neither the direct nor the subfield lookup finds anything (callers pass the MISSING
     * sentinel when they must tell an absent path from a genuine null). The FILE resolver branch
     * (coerce/stringify of a WHOLE file) is untouched — this only widens PATH lookup, and only the
     * caller's own whitelist gate decides which paths ever reach here (never a new root).
     */
    private function readContext(array $context, string $path, mixed $default = null): mixed
    {
        $direct = Arr::get($context, $path, self::MISSING);

        if ($direct !== self::MISSING) {
            return $direct;
        }

        return $this->readFileSubfield($context, $path, $default);
    }

    /**
     * The FILE-SUBFIELD fallback for readContext: split the path at its LAST segment; when that segment
     * is a known file subfield AND the parent resolves to a file snapshot (collapsed to a single file),
     * return the mapped snapshot key (even when its stored value is null — array_key_exists, so a null
     * mime reads as null, not "absent"). Anything else → $default. NEVER throws: a non-file parent, an
     * empty list, or an unknown tail all degrade to $default (fail-soft).
     */
    private function readFileSubfield(array $context, string $path, mixed $default): mixed
    {
        $dot = strrpos($path, '.');

        if ($dot === false) {
            return $default;
        }

        $snapshotKey = self::FILE_SUBFIELDS[substr($path, $dot + 1)] ?? null;

        if ($snapshotKey === null) {
            return $default;
        }

        $file = $this->collapseFileSnapshot(Arr::get($context, substr($path, 0, $dot)));

        if ($file === null || !array_key_exists($snapshotKey, $file)) {
            return $default;
        }

        return $file[$snapshotKey];
    }

    /**
     * Collapse a file answer to a SINGLE snapshot object: a bare snapshot passes through; a snapshot
     * LIST yields its first element (single-file semantics — a multi-file field takes the first,
     * fail-soft, with true per-element iteration deferred to the R2 loop). A non-snapshot → null.
     *
     * @return array<string, mixed>|null
     */
    private function collapseFileSnapshot(mixed $value): ?array
    {
        if ($this->isFileSnapshot($value)) {
            return $value;
        }

        if (is_array($value) && $this->isFileSnapshot($value[0] ?? null)) {
            return $value[0];
        }

        return null;
    }

    /**
     * Substitute a per-reference DEFAULT for a lookup that came back null or '' (empty). The default is
     * a LITERAL scalar entering the value stream exactly where a resolved value would, so the existing
     * embedded-directive NUL-mask (resolveReferences' $stash) protects it from being re-scanned as a
     * `{{…}}` / `@[…]` reference; a STANDALONE / structured slot is never re-scanned at all. A null /
     * absent / non-scalar default is a no-op (the reference behaves exactly as before).
     */
    private function applyDefault(mixed $raw, mixed $default): mixed
    {
        if (($raw === null || $raw === '') && $default !== null && is_scalar($default)) {
            return $default;
        }

        return $raw;
    }

    /** Stringify a resolved value for embedding in surrounding text. */
    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        // A file in TEXT means its NAME. This lives here rather than in a typed branch because
        // most embeds stringify WITHOUT knowing the type (an identity chip is resolved by
        // path) — and a snapshot object would otherwise render as an empty string.
        if ($this->isFileSnapshot($value)) {
            return $this->fileSnapshotLabel($value);
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => match (true) {
                $this->isFileSnapshot($v) => $this->fileSnapshotLabel($v),
                is_scalar($v) => (string) $v,
                default => '',
            }, $value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Whether $value is a FILE snapshot ({id, name, mime_type, size}).
     *
     * Deliberately strict — `mime_type` is the discriminator. The payload carries other
     * snapshots that also have id/name (the form, the task), and those must keep their
     * existing stringify behaviour rather than start rendering as a name.
     */
    private function isFileSnapshot(mixed $value): bool
    {
        return is_array($value)
            && array_key_exists('id', $value)
            && array_key_exists('name', $value)
            && array_key_exists('mime_type', $value);
    }

    private function fileSnapshotLabel(array $snapshot): string
    {
        return (string) ($snapshot['name'] ?? '');
    }

    /**
     * Stringify a typed executor RESULT for a text field. A date terminal (a CarbonImmutable) renders
     * as Y-m-d; everything else routes through stringify (numbers render naturally, booleans as
     * 'true'/'false' — the same coercion the identity embed uses, kept for consistency).
     */
    private function stringifyResult(mixed $value, ?VariableType $type): string
    {
        if ($type === VariableType::DATE && $value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        return $this->stringify($value);
    }

    /**
     * Coerce a resolved value into the expected workflow type. A value that cannot be coerced
     * becomes null (date parse fail, non-numeric to number) — the step decides how to react.
     *   date    → ISO-8601 string (via Carbon) | null
     *   number  → int|float | null
     *   boolean → bool
     *   enum/text → string | null
     *   multi   → array (a scalar is wrapped)
     *   file    → array of file IDS (the consumers — attachments — need ids, not snapshots)
     */
    private function coerce(mixed $value, VariableType $type): mixed
    {
        if ($value === null) {
            return match ($type) {
                VariableType::MULTI, VariableType::FILE => [],
                default => null,
            };
        }

        return match ($type) {
            VariableType::DATE => $this->coerceDate($value),
            VariableType::NUMBER => is_numeric($value) ? $value + 0 : null,
            VariableType::BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            VariableType::MULTI => is_array($value) ? array_values($value) : [$value],
            VariableType::FILE => $this->coerceFileIds($value),
            VariableType::ENUM, VariableType::TEXT => is_scalar($value) ? (string) $value : null,
            // TOTALITY over the enum — do NOT remove. A DESCRIPTOR-ONLY type (`time`/`object`) has no
            // runtime representation, so an expected type this resolver cannot express coerces to the
            // same soft null an uncoercible VALUE does, instead of raising an UnhandledMatchError out
            // of a step run. Unreachable today (the catalog degrades both to text on the flat wire and
            // every caller passes a core type), which is exactly what made the sibling gap in
            // OperationExecutor::normalizeInput look unreachable too — see ADR-0022's amendment.
            default => null,
        };
    }

    /**
     * A file variable carries a snapshot LIST ({id,name,mime_type,size}) — coercing it yields
     * the IDS, which is what every consumer wants (attaching a file needs its id, not its
     * name). Tolerates a bare id / a single snapshot / a list of either, so a legacy or
     * hand-written payload degrades instead of exploding.
     *
     * @return array<int, string>
     */
    private function coerceFileIds(mixed $value): array
    {
        // The type is already known to be FILE here, so any array carrying an `id` is a
        // snapshot — no need for the strict discriminator stringify() has to use.
        $isSnapshot = fn (mixed $item): bool => is_array($item) && array_key_exists('id', $item);

        $items = is_array($value) && !$isSnapshot($value) ? $value : [$value];

        $ids = [];

        foreach ($items as $item) {
            $id = match (true) {
                is_string($item) => $item,
                $isSnapshot($item) => (string) $item['id'],
                default => null,
            };

            if ($id !== null && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Coerce to an ISO-8601 string, or null on any parse failure (never throws). */
    private function coerceDate(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
