<?php

namespace App\Modules\Variables\Services;

use App\Modules\Variables\Contracts\ElementScopeResolver;
use App\Modules\Variables\Contracts\OperationDefinition;
use App\Modules\Variables\DTOs\OperationArg;
use App\Modules\Variables\Enums\Operation;
use App\Modules\Variables\Enums\OperationArgType;
use App\Modules\Variables\Enums\PipelineLimits;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Support\ScopeRef;
use App\Modules\Variables\Support\ValueOrVariable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;

/**
 * The ONE place a variable-operation PIPELINE is write-validated — the type-flow engine extracted from
 * Workflows' WorkflowConditionTreeValidator into the Variables module. The tree validator keeps the
 * condition-TREE shape ({ logic, children[] } of groups + conditions) and CALLS this per leaf pipeline;
 * this class owns everything BELOW a pipeline: the descriptor-tracking op walk, per-op input gating +
 * output descriptors, argument validation (literals, options, source maps, choice rules), argument
 * VARIABLES (value-or-variable unions against a reference index), and the higher-order array ops'
 * element pipelines. Errors are added to the caller's Validator under INDEXED paths
 * (…pipeline.1.args.value) so the FE can map each message to the offending node.
 *
 * What it enforces:
 *   - HARD LIMITS: ≤ MAX_PIPELINE_STEPS steps, arg-variable / element-pipeline depth caps, and the
 *     array iteration cap (PipelineLimits).
 *   - TYPE-FLOW: starting from a source type/descriptor, each op must exist, accept the current
 *     (descriptor-tracked) type, and the pipeline must terminate in the caller's required type — boolean
 *     for a condition (validateConditionPipeline), the field's accepted terminals for a value pipeline
 *     (validateValuePipeline).
 *   - ARGS per descriptor: required presence + shape (scalar/list/map), sourceOption ∈ the source
 *     field's options, sourceOptions ⊆ options (non-empty), sourceMap keys ⊆ options with non-empty
 *     values (number/date targets parseable), literal date args strict Y-m-d, and no foreign arg keys.
 *   - CHOICE args (value pipelines targeting a destination field with a fixed option set, e.g. a task
 *     priority — the target options are injected per field, NOT in the static descriptor): the
 *     enum_to_choice mapping VALUES, and match_to_choice rule `then`/`fallback`, must all be ⊆ those
 *     target options; a value pipeline for such a field must END in a choice-producing op.
 *   - CONDITION DEFAULT: a condition's optional scalar `default` is type-checked against its source type
 *     exactly like a literal arg (validateDefault) — the one condition-level check kept here because it
 *     reuses the same literal/option machinery.
 *   - ARGUMENT VARIABLES: any op argument may itself be a value-or-variable union, validated against the
 *     reference index the caller threads in $refCtx. `$refCtx['sources']` carries the run-context roots a
 *     ref may name (trigger/steps/globals — supplied by the Workflows callers), so the type system needs
 *     NO back-dependency on WorkflowVariableResolver.
 *
 * The element-scope subfield descent an array<object>/array<file> element pipeline needs is inverted
 * through ElementScopeResolver (implemented by the Workflows catalog) so the dependency stays one-way:
 * Variables imports nothing from Workflows.
 */
class PipelineValidator
{
    /**
     * Fallback reference-source roots a variable arg may name, used ONLY when a caller does not thread
     * its own `$refCtx['sources']`. Production always threads them (StoreWorkflowRequest passes
     * WorkflowVariableResolver::ROOTS — the single source), so this is exercised only by focused unit
     * tests that drive a pipeline directly; it MUST mirror WorkflowVariableResolver::ROOTS. Kept as plain
     * strings (not an import) so the type system carries no back-dependency on Workflows.
     *
     * @var array<int, string>
     */
    private const DEFAULT_REFERENCE_SOURCES = ['trigger', 'steps', 'globals'];

    public function __construct(
        private ElementScopeResolver $elementScope,
        private OperationResolver $operations,
    ) {}

    /**
     * The OPTIONAL per-condition `default` (B6): the literal the runtime substitutes when the source
     * value is missing / null / '' — the opt-in escape from the missing-path-is-false doctrine.
     *
     * Only validated when the KEY is present (its absence is the unchanged default behaviour, not an
     * empty default), and it must be a SCALAR that is type-compatible with `source_type`, reusing the
     * SAME per-type checks a literal operation argument passes (is_numeric / is_bool / strict Y-m-d /
     * membership in the source's own option list), so "what a number means" cannot drift between an
     * argument and a default. A list-shaped source (multi/file) takes a single scalar the runtime
     * normalizes into a one-element set, exactly as its base value would be.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, string>|null  $enumOptions
     */
    public function validateDefault(ValidatorContract $validator, array $node, string $prefix, VariableType $type, ?array $enumOptions): void
    {
        if (!array_key_exists('default', $node)) {
            return;
        }

        $key = $prefix . '.default';
        $value = $node['default'];

        if (!is_scalar($value)) {
            $validator->errors()->add($key, 'The default must be a single value.');

            return;
        }

        match ($type) {
            VariableType::NUMBER => $this->require($validator, is_numeric($value), $key, 'The default must be a number.'),
            VariableType::BOOLEAN => $this->require($validator, is_bool($value), $key, 'The default must be a boolean.'),
            VariableType::DATE => $this->require($validator, $this->isYmd($value), $key, 'The default must be a Y-m-d date.'),
            VariableType::ENUM, VariableType::MULTI => $this->validateOption($validator, $value, $key, $enumOptions),
            // text / file — and, defensively, any type this match has no stricter rule for: a scalar is
            // all the runtime can consume, and it was already checked above.
            default => $this->require($validator, is_string($value), $key, 'The default must be text.'),
        };
    }

    /**
     * Walk a CONDITION's pipeline from $sourceType and require a boolean terminal. Called by the tree
     * validator per leaf condition. $refCtx (B6) is the reference index its operation ARGUMENTS may
     * reference (its `sources` = the roots a ref may name); null keeps args literal-only.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, string>|null  $enumOptions
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool, sources: array<int, string>}|null  $refCtx
     * @param  array<string, mixed>|null  $sourceDescriptor  the source's REAL descriptor (F2), seeding the walk so an array<object>/array<file> condition keeps its array-ness + element `fields`
     */
    public function validateConditionPipeline(ValidatorContract $validator, array $node, string $prefix, VariableType $sourceType, ?array $enumOptions, ?array $refCtx = null, ?array $sourceDescriptor = null): void
    {
        $pipeline = $node['pipeline'] ?? null;

        if (!is_array($pipeline)) {
            $validator->errors()->add($prefix . '.pipeline', 'A condition requires a pipeline of operations.');

            return;
        }

        $terminal = $this->walkPipeline($validator, $pipeline, $prefix . '.pipeline', $sourceType, $enumOptions, refCtx: $refCtx, sourceDescriptor: $sourceDescriptor);

        if ($terminal !== null && $terminal !== VariableType::BOOLEAN) {
            $validator->errors()->add($prefix . '.pipeline', 'A condition pipeline must end in a boolean (it ends in ' . $terminal->value . ').');
        }
    }

    /**
     * Validate a VALUE-OR-VARIABLE field's optional pipeline (SB1): walk from the ref's declared
     * $sourceType and require the terminal to be one of the target field's $allowedTerminals
     * (deadline / submissions_* → date). Errors land under indexed keys (…pipeline.M.op /
     * …pipeline.M.args.KEY) exactly like a condition pipeline, so the FE can map each message. Args
     * are checked identically to conditions, with the source variable's own option list
     * ($sourceEnumOptions) driving sourceOption/sourceMap membership.
     *
     * CHOICE FIELDS: when $targetOptions is non-null the destination field carries a fixed option set
     * (e.g. a task priority → TaskPriority::ids()). The pipeline must then be NON-EMPTY and END in a
     * choice-producing op (producesChoice()), and every choice arg's option value is checked ⊆
     * $targetOptions (threaded through the walk). $targetOptions is null for a plain value field.
     *
     * ARGUMENT VARIABLES (phase-4b): when $refCtx is supplied (the value-pipeline path always is; the
     * CONDITION path does too since B6) ANY of an op's arguments may itself be a variable union,
     * validated against the SAME reference index the top-level ref uses. The gate is per arg CONTROL
     * (ArgVariablePolicy): a VALUE arg keeps the strict type match, an OPTION arg accepts enum|text
     * (multi for sourceOptions) with membership deferred to runtime. A STRUCTURAL container
     * (sourceMap/choiceRules) is NOT a whole-arg variable — each of its ENTRIES may be a value-or-variable
     * union, validated PER ENTRY against the entry's TARGET type (validateEntryVariable, Defect-3). Every
     * sub-pipeline is validated recursively and the depth cap ($argDepth) is enforced. A null $refCtx keeps
     * args LITERAL-only (a variable there fails the literal checks).
     *
     * @param  array<int, mixed>  $pipeline
     * @param  array<int, VariableType>  $allowedTerminals
     * @param  array<int, string>|null  $sourceEnumOptions
     * @param  array<int, string>|null  $targetOptions
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     * @param  array<string, mixed>|null  $sourceDescriptor  the source's REAL structured descriptor (array-ops wave 3), seeding the walker so an array<object>/array<file> source keeps its array-ness + element `fields`; null falls back to $sourceType->descriptor()
     */
    public function validateValuePipeline(
        ValidatorContract $validator,
        array $pipeline,
        string $prefix,
        VariableType $sourceType,
        array $allowedTerminals,
        ?array $sourceEnumOptions,
        ?array $targetOptions = null,
        ?array $refCtx = null,
        int $argDepth = 0,
        ?array $scopeVars = null,
        ?array $sourceDescriptor = null,
    ): void {
        $terminal = $this->walkPipeline($validator, $pipeline, $prefix, $sourceType, $sourceEnumOptions, $targetOptions, $refCtx, $argDepth, $scopeVars, 0, $sourceDescriptor);

        // A choice field demands a mapping pipeline that ENDS in a choice-producing op.
        if ($targetOptions !== null) {
            $last = $this->lastOperation($pipeline, $refCtx['functions'] ?? []);

            if ($last === null || !$last->producesChoice()) {
                $validator->errors()->add($prefix, 'The pipeline must map the value to a valid choice.');
            }

            return;
        }

        if ($terminal !== null && !in_array($terminal, $allowedTerminals, true)) {
            $expected = implode(' or ', array_map(fn (VariableType $t) => $t->value, $allowedTerminals));
            $validator->errors()->add($prefix, 'The pipeline must produce a ' . $expected . ' value (it produces ' . $terminal->value . ').');
        }
    }

    /**
     * Validate a custom FUNCTION BODY (Phase 3a): a pipeline over {input + args} that must TERMINATE in
     * the declared RETURN type. It is a value pipeline walked from $inputType with the function's SCOPE
     * FRAME in scope — $scopeVars = {input: input_type, <argName>: argType, …}, whose keys become the
     * referenceable scope roots (via the generalized ScopeRef) — so the body may read its input and args
     * as variables and NOTHING else:
     *   - a SCOPE-ONLY reference index (empty, like validateScopeRootedElementPipeline) rejects any
     *     globals / trigger / steps reference at write time (a body is pure over its input + args);
     *   - the workspace's other $functions ride the context so a body op may reference ANOTHER function
     *     (a `fn:<uuid>` step), resolved through the OperationResolver in the walk (nesting).
     * A function produces no choice, so $targetOptions is never set — the terminal is simply required to
     * equal $returnType (an empty body is the identity function, valid only when input == return).
     *
     * @param  array<int, mixed>  $body
     * @param  array<string, array{type: VariableType, enumOptions: array<int, string>|null}>  $scopeVars
     * @param  iterable<OperationDefinition>  $functions
     */
    public function validateFunctionBody(
        ValidatorContract $validator,
        array $body,
        string $prefix,
        VariableType $inputType,
        VariableType $returnType,
        array $scopeVars,
        iterable $functions,
    ): void {
        $refCtx = [
            'index' => [],
            'fields_available' => true,
            'sources' => self::DEFAULT_REFERENCE_SOURCES,
            'functions' => $functions,
        ];

        $this->validateValuePipeline(
            $validator,
            $body,
            $prefix,
            $inputType,
            [$returnType],
            null,
            null,
            $refCtx,
            0,
            $scopeVars,
            null,
        );
    }

    /**
     * The LAST operation of a pipeline (null when empty or the last step's op is unknown) — used by
     * the choice terminal rule, which requires a value pipeline to END in a choice-producing op. Resolved
     * through the OperationResolver (built-ins ∪ $functions) so no direct Operation::tryFrom survives here;
     * a custom function is never a choice terminal (producesChoice() is false), so a fn-terminal choice
     * pipeline is correctly rejected.
     *
     * @param  array<int, mixed>  $pipeline
     * @param  iterable<OperationDefinition>  $functions
     */
    private function lastOperation(array $pipeline, iterable $functions = []): ?OperationDefinition
    {
        if ($pipeline === []) {
            return null;
        }

        $last = end($pipeline);

        return is_array($last) ? $this->operations->resolve((string) ($last['op'] ?? ''), $functions) : null;
    }

    /**
     * Walk a pipeline of ops from $sourceType, validating bounded length, each op known + accepting
     * the running type, and per-op args. Returns the TERMINAL type, or null when a structural failure
     * already added an error (so the caller skips its terminal check). Shared by the condition and
     * value-pipeline paths — the only difference is which terminal type the caller demands.
     *
     * @param  array<int, mixed>  $pipeline
     * @param  array<int, string>|null  $enumOptions
     * @param  array<int, string>|null  $targetOptions
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     */
    private function walkPipeline(ValidatorContract $validator, array $pipeline, string $prefix, VariableType $sourceType, ?array $enumOptions, ?array $targetOptions = null, ?array $refCtx = null, int $argDepth = 0, ?array $scopeVars = null, int $elementDepth = 0, ?array $sourceDescriptor = null): ?VariableType
    {
        $descriptor = $this->walkPipelineDescriptor($validator, $pipeline, $prefix, $sourceType, $enumOptions, $targetOptions, $refCtx, $argDepth, $scopeVars, $elementDepth, $sourceDescriptor);

        return $descriptor === null ? null : VariableType::fromDescriptor($descriptor);
    }

    /**
     * The descriptor-returning core of walkPipeline: walk the pipeline tracking a running DESCRIPTOR and
     * return the TERMINAL descriptor (null when a structural failure already added an error). walkPipeline
     * is the thin flat-type wrapper; callers that need the precise terminal TYPING (an element pipeline
     * feeding map/reduce's outputDescriptor) read the descriptor directly.
     *
     * $scopeVars (array-ops wave 2) is the synthetic `element`/`index` scope injected ONLY while walking an
     * element pipeline — a `{leaf: {type, enumOptions}}` map validateArgVariableRef consults IN ADDITION to
     * the global ROOTS. $elementDepth is the per-element-pipeline nesting level (a nested element pipeline
     * beyond MAX_ELEMENT_PIPELINE_DEPTH is rejected).
     *
     * @param  array<int, mixed>  $pipeline
     * @param  array<int, string>|null  $enumOptions
     * @param  array<int, string>|null  $targetOptions
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     * @param  array<string, array{type: VariableType, enumOptions: array<int, string>|null}>|null  $scopeVars
     * @param  bool  $mapTerminal  whether this pipeline is a MAP element pipeline (F4): map forces a non-null
     *                             terminal, so a LAST-step array_at is treated as non-terminal and must carry a default
     * @return array<string, mixed>|null
     */
    private function walkPipelineDescriptor(ValidatorContract $validator, array $pipeline, string $prefix, VariableType $sourceType, ?array $enumOptions, ?array $targetOptions = null, ?array $refCtx = null, int $argDepth = 0, ?array $scopeVars = null, int $elementDepth = 0, ?array $sourceDescriptor = null, bool $mapTerminal = false): ?array
    {
        if (count($pipeline) > PipelineLimits::MAX_PIPELINE_STEPS) {
            $validator->errors()->add($prefix, 'A pipeline may hold at most ' . PipelineLimits::MAX_PIPELINE_STEPS . ' steps.');
        }

        // The running state is a structured DESCRIPTOR ({base, nullable, array, options?, …}), not a flat
        // VariableType — so an ARRAY op can gate on the value being an array of ANY element base
        // (which a flat type cannot express) while every legacy op keeps its exact flat gate via
        // fromDescriptor(). This is a PROVABLE no-op for legacy ops: the seed descriptor inverts back to
        // $sourceType, each legacy op's terminal is $op->outputType()->descriptor() (default arm) which
        // fromDescriptor() inverts back to $op->outputType() — byte-identical to the old
        // `$currentType = $op->outputType()` flow.
        //
        // $sourceDescriptor (array-ops wave 3) SEEDS the walk with the source's REAL structured descriptor
        // when the caller knows it — the ONE way an array<object> (repeater) / array<file> source keeps its
        // array-ness + element `fields` past its degraded flat wire type (which is text, not multi, so
        // $sourceType->descriptor() alone would be a non-array scalar and every array op would be rejected).
        // Absent, it falls back to the flat seed (every scalar/enum/multi source — a provable no-op, since
        // their descriptor IS $sourceType->descriptor()).
        $currentDescriptor = $sourceDescriptor ?? $sourceType->descriptor();

        foreach ($pipeline as $index => $step) {
            $sp = $prefix . '.' . $index;

            if (!is_array($step)) {
                $validator->errors()->add($sp, 'A pipeline step must be an object.');

                return null;
            }

            // Resolve the op through the OperationResolver (built-ins ∪ the context's custom functions)
            // rather than a bare Operation::tryFrom, so a body/pipeline may reference a `fn:<uuid>` step.
            // With no functions threaded (every Workflows caller) this is byte-identical to tryFrom.
            $op = $this->operations->resolve((string) ($step['op'] ?? ''), $refCtx['functions'] ?? []);

            if ($op === null) {
                $validator->errors()->add($sp . '.op', 'The operation is unknown.');

                return null;
            }

            // WRITE-BACKSTOP (adversarial review): an option-membership op's `sourceOption`/`sourceOptions`
            // arg is MEANINGLESS over an array whose ELEMENT is STRUCTURAL (object/file — it carries no
            // option set). Reported under the arg key BEFORE the op-accept gate, so an array<file> element
            // (which reads as `multi` via fromDescriptor and would PASS opAcceptsDescriptor) is caught rather
            // than validating an always-open `!in_array('', [snapshots], true)` gate, and an array<object>
            // element is flagged here too (in addition to its op-accept type mismatch). See
            // rejectStructuralArrayOptionArgs — a no-op for every element-agnostic op (array_count/array_at,
            // multi_count/multi_is_empty) and for a REAL enum MULTI (checklist) element.
            $this->rejectStructuralArrayOptionArgs($validator, $op, $sp . '.args', $currentDescriptor);

            if (!$this->opAcceptsDescriptor($op, $currentDescriptor)) {
                $currentType = VariableType::fromDescriptor($currentDescriptor);
                $expected = $op->isArrayOp() ? 'an array' : 'a ' . $op->inputType()->value;
                $validator->errors()->add(
                    $sp . '.op',
                    'The ' . $op->id() . ' operation expects ' . $expected . ' input but the value is ' . $currentType->value . '.',
                );

                return null;
            }

            $args = is_array($step['args'] ?? null) ? $step['args'] : [];

            // A HIGHER-ORDER array op (map/filter/sort/reduce) carries a PER-ELEMENT PIPELINE whose terminal
            // is gated per op; its output descriptor depends on that pipeline's TERMINAL descriptor, which
            // validateCollectionArgs computes by recursively walking the element pipeline from the element
            // descriptor. Every other op keeps the flat arg validation + outputDescriptor. The CURRENT
            // $scopeVars is threaded as the ENCLOSING frame so a function body's element pipeline unions the
            // function frame ({input, <args>}) with its element scope — mirroring the runtime frame stack.
            if ($op->isCollectionOp()) {
                $terminalDescriptor = $this->validateCollectionArgs($validator, $op, $args, $sp . '.args', $currentDescriptor, $enumOptions, $refCtx, $argDepth, $elementDepth, $scopeVars);
                $currentDescriptor = $op->outputDescriptor($currentDescriptor, $args, $terminalDescriptor);

                continue;
            }

            $this->validateArgs($validator, $op, $args, $sp . '.args', $enumOptions, $targetOptions, $refCtx, $argDepth, $scopeVars);

            // array_at's typed DEFAULT (F4) is validated HERE, where the pipeline POSITION + the running
            // array descriptor are both known: REQUIRED when array_at is not the pipeline terminal (a
            // following op would consume its nullable element), OR when it terminates a MAP element pipeline
            // (map forces a non-null element base). The literal is type-checked against the element base.
            if ($op === Operation::ARRAY_AT) {
                $required = $index !== array_key_last($pipeline) || $mapTerminal;
                $this->validateElementDefault($validator, $args, $sp . '.args.default', $currentDescriptor, $required);
            }

            $currentDescriptor = $op->outputDescriptor($currentDescriptor, $args);
        }

        return $currentDescriptor;
    }

    /**
     * Validate array_at's typed DEFAULT arg `{type, value}` (F4). $required is true when the default MUST be
     * present (array_at is not the pipeline terminal, or it terminates a MAP element pipeline). A present
     * default must be a self-describing `{type, value}` literal whose `type` is LOCKED to the array's ELEMENT
     * base and whose `value` is type-compatible (+ enum option membership for an enum element). An
     * absent-and-not-required default is legal (array_at's element is legitimately nullable as a terminal).
     * A wrong / missing-when-required default is write-rejected (owner directive: enforce at write). An
     * OBJECT/FILE element cannot take a scalar default — array_at over it is only path-accessible.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $inputDescriptor  the array descriptor array_at consumes
     */
    private function validateElementDefault(ValidatorContract $validator, array $args, string $key, array $inputDescriptor, bool $required): void
    {
        $default = $args['default'] ?? null;

        // "Not entered" = the default is ABSENT, or its `value` is BLANK (null / empty string). An empty
        // string is NOT a value (owner directive: require ENTERING a default), so a non-terminal array_at
        // carrying `{type:'text', value:''}` is still "no default" → the required-check fails exactly as if
        // the key were missing, and the executor makes NO substitution. Number 0 / boolean false ARE values
        // and pass through to the type checks below.
        if ($default === null || (is_array($default) && $this->isBlankDefaultValue($default['value'] ?? null))) {
            if ($required) {
                $validator->errors()->add($key, 'A default value is required unless array_at is the final step of the pipeline.');
            }

            return;
        }

        if (!is_array($default)) {
            $validator->errors()->add($key, 'The default must be a { type, value } value.');

            return;
        }

        $elementType = $this->collectionElementType($inputDescriptor);
        $declaredType = VariableType::tryFrom((string) ($default['type'] ?? ''));

        if ($declaredType !== $elementType) {
            $validator->errors()->add($key . '.type', 'The default type must match the array element type (' . $elementType->value . ').');

            return;
        }

        $value = $default['value'] ?? null;

        if (!is_scalar($value)) {
            $validator->errors()->add($key . '.value', 'The default value must be a single value.');

            return;
        }

        match ($elementType) {
            VariableType::NUMBER => $this->require($validator, is_numeric($value), $key . '.value', 'The default value must be a number.'),
            VariableType::BOOLEAN => $this->require($validator, is_bool($value), $key . '.value', 'The default value must be a boolean.'),
            VariableType::DATE => $this->require($validator, $this->isYmd($value), $key . '.value', 'The default value must be a Y-m-d date.'),
            VariableType::ENUM => $this->validateOption($validator, $value, $key . '.value', $this->elementOptionKeys($inputDescriptor)),
            VariableType::TEXT => $this->require($validator, is_string($value), $key . '.value', 'The default value must be text.'),
            // An OBJECT / FILE / TIME element cannot take a scalar default (array_at over it is only
            // downstream-usable via path access, never a following op).
            default => $validator->errors()->add($key, 'A default value is not available for this array.'),
        };
    }

    /**
     * Whether an element-default VALUE is BLANK — null or the empty string. An empty string is "not entered"
     * (owner directive), never a real default; number 0 / boolean false are meaningful values and NOT blank.
     * The write-side twin of Operation::hasValidElementDefault (the nullable flip) and the executor's
     * elementDefault reader, so "no default" means the same at write and at runtime.
     */
    private function isBlankDefaultValue(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * The ENUM option KEYS of an array descriptor's element (F4) — the option list a MULTI / array<enum>
     * carries, for the array_at default's enum membership check. Null for a non-enum element OR when the
     * option set is UNKNOWN (an empty/absent list — e.g. an element-subfield array whose real options are
     * not threaded into the scope walk): membership is then DEFERRED (the same fail-soft the arg-option
     * controls use when the set is unverifiable at write time), so only the declared-type match is enforced.
     *
     * @param  array<string, mixed>  $inputDescriptor
     * @return array<int, string>|null
     */
    private function elementOptionKeys(array $inputDescriptor): ?array
    {
        $element = is_array($inputDescriptor['elementDescriptor'] ?? null) ? $inputDescriptor['elementDescriptor'] : $inputDescriptor;
        $options = $element['options'] ?? null;

        if (!is_array($options) || $options === []) {
            return null;
        }

        return array_values(array_map(fn ($option): string => (string) (is_array($option) ? ($option['key'] ?? '') : $option), $options));
    }

    /**
     * Whether $op accepts the running $currentDescriptor as input. An ARRAY op (isArrayOp) gates ONLY on
     * the value being an array (any element base) — this is the whole reason the walker tracks a
     * descriptor rather than a flat type. Every other op keeps the flat gate: the descriptor's flat
     * identity (fromDescriptor) must equal the op's declared inputType. Legacy ops are unaffected — for
     * them this is exactly the old `$op->inputType() === $currentType` check.
     *
     * @param  array<string, mixed>  $currentDescriptor
     */
    private function opAcceptsDescriptor(OperationDefinition $op, array $currentDescriptor): bool
    {
        if ($op->isArrayOp()) {
            return ($currentDescriptor['array'] ?? false) === true;
        }

        return VariableType::fromDescriptor($currentDescriptor) === $op->inputType();
    }

    /**
     * Reject $op's option-membership ARGS (sourceOption / sourceOptions) when the RUNNING descriptor is an
     * ARRAY whose ELEMENT is STRUCTURAL — an array<object> (repeater) or array<file>, whose element carries
     * NO selectable option set. Over such a source the membership ops (multi_includes / _excludes /
     * _includes_any / _includes_all; enum_is / _is_not / _in) are meaningless: a stored `multi_excludes ''`
     * would evaluate `!in_array('', [rowObjects], true)` to TRUE unconditionally — an author-buildable
     * always-open gate. The error lands under the ARG key so the FE maps it to the offending control. A no-op
     * for the element-AGNOSTIC ops (array_count / array_at / multi_count / multi_is_empty carry no option
     * arg) and for a REAL enum MULTI (checklist), whose element is an ENUM, not a structural container.
     *
     * @param  array<string, mixed>  $descriptor  the running value's descriptor the op consumes
     */
    private function rejectStructuralArrayOptionArgs(ValidatorContract $validator, OperationDefinition $op, string $prefix, array $descriptor): void
    {
        if (!$this->isStructuralElementArray($descriptor)) {
            return;
        }

        foreach ($op->argDescriptors() as $arg) {
            if (in_array($arg->type, [OperationArgType::SOURCE_OPTION, OperationArgType::SOURCE_OPTIONS], true)) {
                $validator->errors()->add($prefix . '.' . $arg->id, 'This operation needs a list with selectable options.');
            }
        }
    }

    /**
     * Whether $descriptor is an ARRAY whose ELEMENT is STRUCTURAL (object/file) — a repeater (array<object>)
     * or array<file>, whose element has no option set. Reuses collectionElementType (the ONE element-typing
     * definition the walker uses), so this can never drift from how the walk types an element.
     *
     * @param  array<string, mixed>  $descriptor
     */
    private function isStructuralElementArray(array $descriptor): bool
    {
        if (($descriptor['array'] ?? false) !== true) {
            return false;
        }

        return in_array($this->collectionElementType($descriptor), [
            VariableType::OBJECT,
            VariableType::FILE,
        ], true);
    }

    // ---- higher-order array transforms (wave 2): element-pipeline validation ---

    /**
     * Validate a HIGHER-ORDER array op's arguments (map/filter/sort/reduce) and RETURN its element
     * pipeline's TERMINAL descriptor (used by the op's outputDescriptor). Rejects foreign arg keys, then:
     *   - map/filter/sort  ONE element pipeline rooted at the array's ELEMENT descriptor, terminal gated
     *                      (map any base — no array<array>; filter boolean; sort number).
     *   - reduce           a typed SEED literal + a reducer pipeline rooted at the SEED base U, terminal
     *                      forced to U, with element/index ALSO in scope.
     * Terminal type is thereby enforced BY CONSTRUCTION — a wrong-terminal pipeline can never be stored.
     *
     * $enclosingScopeVars is the caller's active scope (a function body's frame, or an outer element
     * pipeline's merged scope, or null) — threaded down so the element pipeline can union the enclosing
     * FUNCTION frame into its element scope (see validateElementPipeline).
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>  $inputDescriptor
     * @param  array<int, string>|null  $enumOptions
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     * @param  array<string, array{type: VariableType, enumOptions: array<int, string>|null}>|null  $enclosingScopeVars
     * @return array<string, mixed>
     */
    private function validateCollectionArgs(ValidatorContract $validator, Operation $op, array $args, string $prefix, array $inputDescriptor, ?array $enumOptions, ?array $refCtx, int $argDepth, int $elementDepth, ?array $enclosingScopeVars = null): array
    {
        $allowed = array_map(fn (OperationArg $arg): string => $arg->id, $op->argDescriptors());

        foreach (array_keys($args) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $validator->errors()->add($prefix . '.' . $key, 'The ' . $key . ' argument is not allowed for this operation.');
            }
        }

        $elementType = $this->collectionElementType($inputDescriptor);
        // The element's SUBFIELD scope (array-ops wave 3): `element.<field>` for an array<object>
        // (repeater) / array<file>, empty for a scalar/enum element. Merged into the element pipeline's
        // $scopeVars so an object/file subfield validates as a scope arg-variable / pipeline root and is
        // rejected everywhere else (the catalog owns the descent — see elementScopeSubfields).
        $elementSubfields = $this->elementScope->elementScopeSubfields($inputDescriptor);

        if ($op === Operation::ARRAY_REDUCE) {
            $seedType = $this->validateReduceSeed($validator, $args['seed'] ?? null, $prefix . '.seed');

            // The reducer roots at the SEED type (the accumulator U); element/index (+ subfields) are ALSO
            // in scope. Its terminal must be U (when the seed typed OK — otherwise structural-only). A reduce
            // reducer NEVER roots at a scope subfield (it roots at the accumulator), so scope-root is off.
            $this->validateElementPipeline(
                $validator,
                $args['reducer'] ?? null,
                $prefix . '.reducer',
                $seedType ?? VariableType::TEXT,
                null,
                $elementType,
                $enumOptions,
                $seedType,
                false,
                $refCtx,
                $argDepth,
                $elementDepth,
                $elementSubfields,
                false,
                $enclosingScopeVars,
            );

            return ($seedType ?? VariableType::TEXT)->descriptor();
        }

        // map/filter/sort: one element pipeline rooted at the element, terminal gated per op.
        $required = match ($op) {
            Operation::ARRAY_FILTER => VariableType::BOOLEAN,
            Operation::ARRAY_SORT => VariableType::NUMBER,
            default => null, // map — any base terminal (the array-terminal reject is handled below)
        };

        return $this->validateElementPipeline(
            $validator,
            $args['pipeline'] ?? null,
            $prefix . '.pipeline',
            $elementType,
            $enumOptions,
            $elementType,
            $enumOptions,
            $required,
            $op === Operation::ARRAY_MAP,
            $refCtx,
            $argDepth,
            $elementDepth,
            $elementSubfields,
            true,
            $enclosingScopeVars,
        );
    }

    /**
     * Validate ONE element pipeline and return its TERMINAL descriptor. Walks from $rootType (the element
     * for map/filter/sort, the seed for reduce) with the synthetic `element`/`index` scope injected — so a
     * scope reference resolves inside this pipeline and is REJECTED anywhere else — then enforces the
     * per-op terminal: $requiredTerminal (filter boolean / sort number / reduce seed base) when supplied,
     * or the map array-reject ($isMap) when not. A nested element pipeline beyond MAX_ELEMENT_PIPELINE_DEPTH
     * is rejected. Returns $rootType's descriptor as a safe fallback when a structural failure short-circuits.
     *
     * FRAME STACK (A1): when this element pipeline sits inside a custom FUNCTION body, $enclosingScopeVars
     * carries the caller's active scope; its FUNCTION-frame part ({input, <argNames>}) is UNIONED into the
     * element scope so the body may combine each element with its input/args — EXACTLY the runtime scope
     * (OperationExecutor::scopeRoots unions the element roots with the FunctionScope frame). See
     * enclosingFrameOnly for why only the frame — never an OUTER element's scope — rides along.
     *
     * @param  array<int, string>|null  $rootOptions
     * @param  array<int, string>|null  $elementOptions
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     * @param  array<string, array{type: VariableType, enumOptions: array<int, string>|null}>|null  $enclosingScopeVars
     * @return array<string, mixed>
     */
    private function validateElementPipeline(ValidatorContract $validator, mixed $pipeline, string $key, VariableType $rootType, ?array $rootOptions, VariableType $elementType, ?array $elementOptions, ?VariableType $requiredTerminal, bool $isMap, ?array $refCtx, int $argDepth, int $elementDepth, array $elementSubfields = [], bool $allowScopeRoot = false, ?array $enclosingScopeVars = null): array
    {
        if ($elementDepth + 1 > PipelineLimits::MAX_ELEMENT_PIPELINE_DEPTH) {
            $validator->errors()->add($key, 'The element pipelines are nested too deeply (max ' . PipelineLimits::MAX_ELEMENT_PIPELINE_DEPTH . ' levels).');

            return $rootType->descriptor();
        }

        // The synthetic scope for this walk — element (the array's element type) + index (number) +
        // (array-ops wave 3) the element's `element.<subfield>` fields for an array<object>/array<file> —
        // MERGED OVER the enclosing FUNCTION frame ({input, <argNames>}, empty for a plain workflow/condition
        // array-op). This mirrors OperationExecutor::scopeRoots, which unions the element roots with the
        // FunctionScope frame, so the WRITE scope is EXACTLY the runtime scope. Reserved names
        // (input/element/index cannot be arg names — FunctionDefinitionValidator) guarantee no key collision.
        // validateArgVariableRef consults this in ADDITION to the ROOTS; with no frame AND no element scope
        // it is absent in every non-element pipeline, so a stored element/index/element.* reference is
        // rejected there.
        $scopeVars = array_merge($this->enclosingFrameOnly($enclosingScopeVars), [
            'element' => ['type' => $elementType, 'enumOptions' => $elementOptions],
            'index' => ['type' => VariableType::NUMBER, 'enumOptions' => null],
        ]);

        foreach ($elementSubfields as $subPath => $subType) {
            $scopeVars[$subPath] = ['type' => $subType, 'enumOptions' => null];
        }

        // ARRAY<OBJECT>/ARRAY<FILE> ROOT (wave 3): a map/filter/sort element pipeline over an object/file
        // element cannot root at the whole element (no op consumes an object), so it is a value-or-variable
        // UNION rooted at a scope `element.<subfield>` — the FE's "pick a subfield, then transform" shape.
        // Only map/filter/sort allow it ($allowScopeRoot); a reduce reducer roots at the accumulator, so a
        // union there stays malformed and falls through to the bare-list path (which rejects it).
        if ($allowScopeRoot && $this->isVariableArg($pipeline)) {
            return $this->validateScopeRootedElementPipeline($validator, $pipeline, $key, $scopeVars, $requiredTerminal, $isMap, $refCtx, $argDepth, $elementDepth);
        }

        if (!is_array($pipeline)) {
            $validator->errors()->add($key, 'An element pipeline of operations is required.');

            return $rootType->descriptor();
        }

        $terminalDescriptor = $this->walkPipelineDescriptor(
            $validator,
            $pipeline,
            $key,
            $rootType,
            $rootOptions,
            null,
            $refCtx,
            $argDepth,
            $scopeVars,
            $elementDepth + 1,
            mapTerminal: $isMap,
        );

        if ($terminalDescriptor === null) {
            return $rootType->descriptor(); // a structural error was already added
        }

        $terminal = VariableType::fromDescriptor($terminalDescriptor);

        if ($requiredTerminal !== null) {
            if ($terminal !== $requiredTerminal) {
                $validator->errors()->add($key, 'The element pipeline must end in ' . $requiredTerminal->value . ' (it ends in ' . $terminal->value . ').');
            }
        } elseif ($isMap && ($terminalDescriptor['array'] ?? false) === true) {
            // map may end in ANY base, but not another array (no array<array>).
            $validator->errors()->add($key, 'A map element pipeline must end in a single value, not an array.');
        }

        return $terminalDescriptor;
    }

    /**
     * Validate a map/filter/sort element pipeline that is ROOTED at a scope `element.<subfield>` (array-ops
     * wave 3): the value-or-variable UNION an array<object>/array<file> element pipeline carries, since no
     * op can consume the whole object/file element. The union's REF picks the root subfield (validated
     * against $scopeVars, so an unknown/foreign root is rejected with a granular error), its `pipeline`
     * transforms it, and the per-op terminal is gated exactly like a bare-list element pipeline (filter
     * boolean / sort number / map any non-array base). $scopeVars (already unioned with the enclosing
     * FUNCTION frame by validateElementPipeline) stays in scope through the walk, so an op inside may
     * reference OTHER `element.<field>` subfields — and, inside a function body, the frame's input/args —
     * as arg-variables. Returns the terminal descriptor (a safe fallback when a structural failure
     * short-circuits).
     *
     * @param  array<string, mixed>  $field  the value-or-variable union
     * @param  array<string, array{type: VariableType, enumOptions: array<int, string>|null}>  $scopeVars
     * @param  array{index: array<string, array<string, mixed>>, fields_available: bool}|null  $refCtx
     * @return array<string, mixed>
     */
    private function validateScopeRootedElementPipeline(ValidatorContract $validator, array $field, string $key, array $scopeVars, ?VariableType $requiredTerminal, bool $isMap, ?array $refCtx, int $argDepth, int $elementDepth): array
    {
        $ref = is_array($field['ref'] ?? null) ? $field['ref'] : [];
        $scopeLeaf = $this->scopeRefLeaf($ref, $scopeVars);

        if ($scopeLeaf === null) {
            $validator->errors()->add($key . '.ref', 'An element pipeline over an object or file array must be rooted at an element field.');

            return VariableType::TEXT->descriptor();
        }

        // FAIL-CLOSED validator/runtime SYMMETRY: inside a scope-rooted object/file element pipeline the
        // ONLY variable references the RUNTIME can resolve are the element.<subfield>/index SCOPE refs — the
        // whole union is never pre-resolved (WorkflowVariableResolver::resolveDescriptorArg short-circuits on
        // a scope variable, and OperationExecutor::resolveScopePipeline fails CLOSED on any non-scope
        // union), so a globals.*/trigger.*/steps.* arg-variable here would fail the entire element sub-run
        // closed. Reject it at WRITE by validating this union's inner arg-variables against a SCOPE-ONLY
        // reference index (empty), so a non-scope ref can never be stored — matching the fail-closed runtime.
        // Scope refs are consulted via $scopeVars (never the index), so they still resolve. (The bare-list
        // scalar-element path keeps the full $refCtx — there inner globals ARE legitimately pre-resolved.)
        // `sources` is carried forward so a stray non-scope ref still reports the real root list, and
        // `functions` so a nested `fn:<uuid>` op inside this element pipeline still resolves.
        $refCtx = [
            'index' => [],
            'fields_available' => true,
            'sources' => $refCtx['sources'] ?? self::DEFAULT_REFERENCE_SOURCES,
            'functions' => $refCtx['functions'] ?? [],
        ];
        $refType = $this->validateArgVariableRef($validator, $key, $ref, $refCtx, $scopeVars);

        if ($refType === null) {
            return VariableType::TEXT->descriptor(); // an unknown scope subfield was reported
        }

        $pipeline = $field['pipeline'] ?? [];

        if (!is_array($pipeline)) {
            $validator->errors()->add($key . '.pipeline', 'The pipeline must be an array of operations.');

            return $refType->descriptor();
        }

        $terminalDescriptor = $this->walkPipelineDescriptor(
            $validator,
            $pipeline,
            $key . '.pipeline',
            $refType,
            $scopeVars[$scopeLeaf]['enumOptions'] ?? null,
            null,
            $refCtx,
            $argDepth,
            $scopeVars,
            $elementDepth + 1,
            mapTerminal: $isMap,
        );

        if ($terminalDescriptor === null) {
            return $refType->descriptor(); // a structural error was already added
        }

        $terminal = VariableType::fromDescriptor($terminalDescriptor);

        if ($requiredTerminal !== null) {
            if ($terminal !== $requiredTerminal) {
                $validator->errors()->add($key, 'The element pipeline must end in ' . $requiredTerminal->value . ' (it ends in ' . $terminal->value . ').');
            }
        } elseif ($isMap && ($terminalDescriptor['array'] ?? false) === true) {
            $validator->errors()->add($key, 'A map element pipeline must end in a single value, not an array.');
        } elseif ($isMap && !$this->isScalarTerminal($terminal)) {
            // A map must terminate in a BASE SCALAR. A bare `element` union (the whole object/file, ref.type
            // object/file, empty inner pipeline) walks to a NON-scalar terminal — rejected here, symmetric
            // with the array reject above (map → any BASE, never object/file/array).
            $validator->errors()->add($key, 'A map element pipeline must end in a single base value, not an object.');
        }

        return $terminalDescriptor;
    }

    /**
     * Whether a map element-pipeline TERMINAL is a BASE SCALAR (text/number/boolean/date/enum) — the only
     * terminals a map may produce (map → any base). MULTI is excluded (an array, rejected by the array
     * check that precedes this one), as are the structural OBJECT / FILE / TIME bases: a map must end in a
     * single base value, never an object/file/array.
     */
    private function isScalarTerminal(VariableType $terminal): bool
    {
        return in_array($terminal, [
            VariableType::TEXT,
            VariableType::NUMBER,
            VariableType::BOOLEAN,
            VariableType::DATE,
            VariableType::ENUM,
        ], true);
    }

    /**
     * The ELEMENT flat type of an array descriptor: an explicit `elementDescriptor`'s base when present,
     * else the array descriptor collapsed to a single item (a MULTI `{base:enum, array:true}` → ENUM; an
     * `array<scalar>` → that scalar). Wave 2 handles scalar/enum elements only.
     *
     * @param  array<string, mixed>  $descriptor
     */
    private function collectionElementType(array $descriptor): VariableType
    {
        $element = is_array($descriptor['elementDescriptor'] ?? null) ? $descriptor['elementDescriptor'] : $descriptor;

        return VariableType::fromDescriptor([
            'base' => is_string($element['base'] ?? null) ? $element['base'] : VariableType::TEXT->value,
            'array' => false,
        ]);
    }

    /**
     * Validate a reduce SEED `{type, value}` and return its declared base type (null on any failure, with
     * the error added). `type` must be one of the four base LITERAL types (text/number/boolean/date) and
     * `value` must be a scalar type-compatible with it — the SAME per-type checks a literal argument passes.
     */
    private function validateReduceSeed(ValidatorContract $validator, mixed $seed, string $key): ?VariableType
    {
        if (!is_array($seed)) {
            $validator->errors()->add($key, 'A reduce seed { type, value } is required.');

            return null;
        }

        $type = VariableType::tryFrom((string) ($seed['type'] ?? ''));

        if ($type === null || !in_array($type, [VariableType::TEXT, VariableType::NUMBER, VariableType::BOOLEAN, VariableType::DATE], true)) {
            $validator->errors()->add($key . '.type', 'The seed type must be text, number, boolean or date.');

            return null;
        }

        $value = $seed['value'] ?? null;

        if (!is_scalar($value)) {
            $validator->errors()->add($key . '.value', 'The seed value must be a single value.');

            return null;
        }

        match ($type) {
            VariableType::NUMBER => $this->require($validator, is_numeric($value), $key . '.value', 'The seed value must be a number.'),
            VariableType::BOOLEAN => $this->require($validator, is_bool($value), $key . '.value', 'The seed value must be a boolean.'),
            VariableType::DATE => $this->require($validator, $this->isYmd($value), $key . '.value', 'The seed value must be a Y-m-d date.'),
            default => $this->require($validator, is_string($value), $key . '.value', 'The seed value must be text.'),
        };

        return $type;
    }

    /**
     * Validate every declared arg of $op and reject any foreign arg key (descriptor-driven, like the
     * rest of the module).
     *
     * @param  array<string, mixed>  $args
     * @param  array<int, string>|null  $enumOptions
     * @param  array<int, string>|null  $targetOptions
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     */
    private function validateArgs(ValidatorContract $validator, OperationDefinition $op, array $args, string $prefix, ?array $enumOptions, ?array $targetOptions = null, ?array $refCtx = null, int $argDepth = 0, ?array $scopeVars = null): void
    {
        $allowed = [];

        foreach ($op->argDescriptors() as $arg) {
            $allowed[] = $arg->id;
            $this->validateArg($validator, $arg, $args, $prefix . '.' . $arg->id, $enumOptions, $targetOptions, $refCtx, $argDepth, $scopeVars);
        }

        foreach (array_keys($args) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $validator->errors()->add($prefix . '.' . $key, 'The ' . $key . ' argument is not allowed for this operation.');
            }
        }
    }

    /**
     * One argument by its control type. $enumOptions drives SOURCE-side membership (the source
     * variable's options); $targetOptions drives CHOICE-side membership (the destination field's
     * options), applied to the enum_to_choice mapping VALUES and to match_to_choice rules/fallback.
     *
     * ARGUMENT VARIABLES (phase-4a): when $refCtx is supplied AND the value is a variable union, the arg
     * is validated as an arg-variable (validateArgVariable) instead of a literal. When $refCtx is null a
     * variable union falls through to the literal checks below and is rejected there. Since B6 the
     * CONDITION path supplies one too — built with NO prior steps, so a gate's argument can reference
     * the trigger's variables and the workspace globals but never a `steps.*` output.
     *
     * @param  array<string, mixed>  $args
     * @param  array<int, string>|null  $enumOptions
     * @param  array<int, string>|null  $targetOptions
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     */
    private function validateArg(ValidatorContract $validator, OperationArg $arg, array $args, string $key, ?array $enumOptions, ?array $targetOptions = null, ?array $refCtx = null, int $argDepth = 0, ?array $scopeVars = null): void
    {
        $value = $args[$arg->id] ?? null;

        // A WHOLE-arg variable union is valid only for NON-structural controls; a STRUCTURAL container
        // (sourceMap/choiceRules) carries a plain map/list whose ENTRIES may be variables — validated per
        // entry inside validateSourceMap / validateChoiceRules below (Defect-3). A scope reference
        // (element/index) is a variable too, gated here against $scopeVars in addition to the ROOTS.
        if ($refCtx !== null && $this->isVariableArg($value) && !$arg->type->argVariablePolicy()->isStructural()) {
            $this->validateArgVariable($validator, $arg, $value, $key, $refCtx, $argDepth, $scopeVars);

            return;
        }

        match ($arg->type) {
            OperationArgType::NUMBER => $this->require($validator, is_numeric($value), $key, 'The ' . $arg->id . ' must be a number.'),
            OperationArgType::TEXT, OperationArgType::SELECT => $this->require($validator, is_string($value), $key, 'The ' . $arg->id . ' must be text.'),
            OperationArgType::BOOLEAN => $this->require($validator, is_bool($value), $key, 'The ' . $arg->id . ' must be a boolean.'),
            OperationArgType::DATE => $this->require($validator, $this->isYmd($value), $key, 'The ' . $arg->id . ' must be a Y-m-d date.'),
            OperationArgType::SOURCE_OPTION => $this->validateOption($validator, $value, $key, $enumOptions),
            OperationArgType::SOURCE_OPTIONS => $this->validateOptions($validator, $value, $key, $enumOptions),
            OperationArgType::SOURCE_MAP => $this->validateSourceMap($validator, $value, $key, $arg->mapType, $enumOptions, $targetOptions, $refCtx, $argDepth),
            OperationArgType::CHOICE_RULES => $this->validateChoiceRules($validator, $value, $key, $targetOptions, $refCtx, $argDepth),
            OperationArgType::CHOICE_FALLBACK => $this->validateChoiceFallback($validator, $value, $key, $targetOptions),
            // A typed element DEFAULT (array_at, F4) is validated in the pipeline WALK (validateElementDefault),
            // where the pipeline position + running array descriptor are known — a no-op here so array_at's
            // args pass through the generic validateArgs (index + foreign-key reject) without an unhandled arm.
            OperationArgType::ELEMENT_DEFAULT => null,
        };
    }

    /**
     * Validate ONE WHOLE variable-union ARGUMENT (a VALUE / OPTION / OPTIONS control). The gate is driven
     * by the arg's ArgVariablePolicy (the SINGLE source shared with the runtime resolver). The ref must be
     * a known, whitelisted variable in $refCtx['index']; a present sub-pipeline is validated recursively
     * (its own arg-variables one level deeper), and the depth cap is enforced so a config nested beyond
     * MAX_ARG_VARIABLE_DEPTH is rejected with a clear error. Per category:
     *   - VALUE                    ref.type + terminal must equal the arg's one value type (strict).
     *   - single/multi OPTION      ref.type + terminal must be one of the policy's accepted types
     *                              (enum|text, resp. multi); option-set membership is a RUNTIME concern.
     * STRUCTURAL controls never reach here — their entries are validated per entry (validateEntryVariable).
     *
     * @param  array<string, mixed>  $field
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}  $refCtx
     */
    private function validateArgVariable(ValidatorContract $validator, OperationArg $arg, array $field, string $key, array $refCtx, int $argDepth, ?array $scopeVars = null): void
    {
        $policy = $arg->type->argVariablePolicy();

        // Write-time depth cap: this arg-variable sits one level below the pipeline it lives in. Reject a
        // config nested beyond the cap (the runtime resolver fail-softs at the same boundary). Checked
        // FIRST so the DEEPEST offending arg reports the cap error, matching the runtime boundary exactly.
        if ($argDepth + 1 > PipelineLimits::MAX_ARG_VARIABLE_DEPTH) {
            $validator->errors()->add($key, 'The argument variables are nested too deeply (max ' . PipelineLimits::MAX_ARG_VARIABLE_DEPTH . ' levels).');

            return;
        }

        $ref = is_array($field['ref'] ?? null) ? $field['ref'] : [];
        $refType = $this->validateArgVariableRef($validator, $key, $ref, $refCtx, $scopeVars);

        if ($refType === null) {
            return; // a malformed / unknown ref was reported
        }

        // A scope (element/index/input/arg) reference draws its option list from $scopeVars; every other
        // ref reads the reference index (a scope path is never in the index).
        $scopeLeaf = $this->scopeRefLeaf($ref, $scopeVars);
        $sourceEnumOptions = $scopeLeaf !== null
            ? ($scopeVars[$scopeLeaf]['enumOptions'] ?? null)
            : ($refCtx['index'][$this->refFullPath($ref)]['enumOptions'] ?? null);
        // A repeater / array<file> arg-variable degrades its wire type but carries its real descriptor in
        // the reference index (array-ops wave 3); seed its sub-pipeline walk with it so an array op over
        // the ref roots at the true array descriptor. A scope ref has no index descriptor (null → flat).
        $sourceDescriptor = $scopeLeaf === null
            ? ($refCtx['index'][$this->refFullPath($ref)]['descriptor'] ?? null)
            : null;
        $pipeline = $field['pipeline'] ?? null;

        if ($pipeline === null || $pipeline === []) {
            // Identity arg-variable: the ref type must be one the arg control accepts (VALUE = its one
            // type; single OPTION = enum|text; multi OPTION = multi).
            if (!in_array($refType, $policy->refTypes, true)) {
                $validator->errors()->add($key, 'The ' . $arg->id . ' variable must be ' . $this->refTypesLabel($policy->refTypes) . ' (it is ' . $refType->value . ').');
            }

            return;
        }

        if (!is_array($pipeline)) {
            $validator->errors()->add($key . '.pipeline', 'The pipeline must be an array of operations.');

            return;
        }

        // The sub-pipeline must type-flow from the ref type to one of the arg's accepted terminals; its
        // own arg-variables are validated one level deeper (argDepth + 1), enforcing the cap recursively.
        // $scopeVars stays in scope so a scope ref's sub-pipeline can reference element/index again.
        $this->validateValuePipeline(
            $validator,
            $pipeline,
            $key . '.pipeline',
            $refType,
            $policy->refTypes,
            $sourceEnumOptions,
            null,
            $refCtx,
            $argDepth + 1,
            $scopeVars,
            $sourceDescriptor,
        );
    }

    /**
     * Validate ONE structural-container ENTRY that is a value-or-variable union (Defect-3): a sourceMap
     * mapping VALUE or a match_to_choice rule `then`. The entry has a fixed expected TARGET type
     * ($entryType — the map/rule target) validated against the SAME reference index a top-level
     * arg-variable uses. When $targetOptions is supplied (a CHOICE target — enum_to_choice value /
     * match_to_choice then) the union must MAP into that option set exactly like a top-level choice field:
     * an identity ref is rejected (membership is unknowable for a bare ref at write time) and a pipeline
     * must END in a choice-producing op ($targetOptions threaded through the walk). Otherwise it is a plain
     * VALUE entry: an identity ref must declare $entryType, a pipeline must type-flow from the ref to
     * $entryType. The depth cap is enforced identically to a whole-arg variable.
     *
     * @param  array<string, mixed>  $field
     * @param  array<int, string>|null  $targetOptions
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}  $refCtx
     */
    private function validateEntryVariable(ValidatorContract $validator, array $field, string $key, VariableType $entryType, ?array $targetOptions, array $refCtx, int $argDepth): void
    {
        if ($argDepth + 1 > PipelineLimits::MAX_ARG_VARIABLE_DEPTH) {
            $validator->errors()->add($key, 'The argument variables are nested too deeply (max ' . PipelineLimits::MAX_ARG_VARIABLE_DEPTH . ' levels).');

            return;
        }

        $ref = is_array($field['ref'] ?? null) ? $field['ref'] : [];
        $refType = $this->validateArgVariableRef($validator, $key, $ref, $refCtx);

        if ($refType === null) {
            return;
        }

        $sourceEnumOptions = $refCtx['index'][$this->refFullPath($ref)]['enumOptions'] ?? null;
        $pipeline = $field['pipeline'] ?? null;

        if ($pipeline === null || $pipeline === []) {
            // Identity entry-variable (a bare ref).
            if ($targetOptions !== null) {
                $validator->errors()->add($key, "The entry variable must map the value into the target field's options.");

                return;
            }

            if ($refType !== $entryType) {
                $validator->errors()->add($key, 'The entry variable must be ' . $entryType->value . ' (it is ' . $refType->value . ').');
            }

            return;
        }

        if (!is_array($pipeline)) {
            $validator->errors()->add($key . '.pipeline', 'The pipeline must be an array of operations.');

            return;
        }

        $this->validateValuePipeline(
            $validator,
            $pipeline,
            $key . '.pipeline',
            $refType,
            [$entryType],
            $sourceEnumOptions,
            $targetOptions,
            $refCtx,
            $argDepth + 1,
        );
    }

    /**
     * Validate an arg-variable's ref against the reference index and return its declared
     * VariableType (null when malformed / unknown, with the error already added). Mirrors the
     * top-level variable-ref checks in StoreWorkflowRequest, reading the SAME reference index threaded
     * through the walk. A `trigger.fields.*` path is only skippable when the trigger form is unresolved
     * (fields_available false — its form_id error is already reported); every other unknown ref is a
     * granular error, so an argument can only reference a real, earlier variable. The strict flat-type
     * equality (ref.type == the catalog type) always applies — every variable argument/entry is typed.
     *
     * @param  array<string, mixed>  $ref
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}  $refCtx
     */
    private function validateArgVariableRef(ValidatorContract $validator, string $key, array $ref, array $refCtx, ?array $scopeVars = null): ?VariableType
    {
        // A SCOPE reference (element/index, array-ops wave 2) is valid ONLY while an element pipeline is
        // being walked ($scopeVars carries that pipeline's element+index). Outside one — the ONLY place
        // $scopeVars is null — it is REJECTED at write (fail-closed), so a stored element/index reference
        // can never live in a normal condition/value pipeline. This is the write-side of the runtime
        // guarantee that element/index resolve inside the sub-run and nowhere else.
        $scopeLeaf = $this->scopeRefLeaf($ref, $scopeVars);

        if ($scopeLeaf !== null) {
            if ($scopeVars === null || !isset($scopeVars[$scopeLeaf])) {
                $validator->errors()->add($key . '.ref.source', 'The element and index references are only available inside an element pipeline.');

                return null;
            }

            $type = VariableType::tryFrom((string) ($ref['type'] ?? ''));

            if ($type === null) {
                $validator->errors()->add($key . '.ref.type', 'The reference type is invalid.');

                return null;
            }

            if ($type !== $scopeVars[$scopeLeaf]['type']) {
                $validator->errors()->add($key . '.ref.type', 'The ' . $scopeLeaf . ' reference must be ' . $scopeVars[$scopeLeaf]['type']->value . ' (it is ' . $type->value . ').');

                return null;
            }

            return $type;
        }

        $sources = $refCtx['sources'] ?? self::DEFAULT_REFERENCE_SOURCES;

        if (!in_array($ref['source'] ?? null, $sources, true)) {
            $validator->errors()->add($key . '.ref.source', 'The reference source must be one of: ' . implode(', ', $sources) . '.');

            return null;
        }

        $fullPath = $this->refFullPath($ref);

        if ($fullPath === null) {
            $validator->errors()->add($key . '.ref.path', 'The reference path is required.');

            return null;
        }

        $type = VariableType::tryFrom((string) ($ref['type'] ?? ''));

        if ($type === null) {
            $validator->errors()->add($key . '.ref.type', 'The reference type is invalid.');

            return null;
        }

        $descriptor = $refCtx['index'][$fullPath] ?? null;

        if ($descriptor === null) {
            // A form field is only skippable when the form itself is unresolved (already reported).
            if (str_starts_with($fullPath, 'trigger.fields.') && !$refCtx['fields_available']) {
                return $type;
            }

            $validator->errors()->add($key . '.ref.path', 'The reference is not a known variable for this step.');

            return null;
        }

        if ($descriptor['type']->value !== $type->value) {
            $validator->errors()->add($key . '.ref.type', 'The reference type does not match the variable type in the catalog.');

            return null;
        }

        return $type;
    }

    /**
     * A human "a or b" label of an arg control's accepted variable ref types, for the identity mismatch
     * error (e.g. "enum or text"). Never called for a structural control (its refTypes is null).
     *
     * @param  array<int, VariableType>  $types
     */
    private function refTypesLabel(array $types): string
    {
        return implode(' or ', array_map(fn (VariableType $type) => $type->value, $types));
    }

    /**
     * The scope leaf (`element` / `index` — or a function body's `input` / `<argName>`) a reference
     * addresses, or null when it is not a scope reference. Delegates to ScopeRef — the ONE source-aware
     * definition shared with the resolver and the executor, so the write gate and the runtime resolution
     * can never drift (an ordinary `globals.index` global is NOT mistaken for the loop scope).
     *
     * The scope ROOTS are DERIVED from $scopeVars (each key's first path segment), so the SAME predicate
     * serves an element pipeline (`element`/`index`) and a function body (`input` + arg names) without a
     * parallel root list that could drift from the scope itself. When there is NO scope in play
     * ($scopeVars null — a normal condition/value pipeline) the DEFAULT element/index roots apply, so a
     * stored element/index ref is still DETECTED here and then rejected as out-of-scope (byte-identical to
     * the pre-generalization behaviour).
     *
     * @param  array<string, mixed>  $ref
     * @param  array<string, array<string, mixed>>|null  $scopeVars
     */
    private function scopeRefLeaf(array $ref, ?array $scopeVars = null): ?string
    {
        return ScopeRef::leaf($ref, $this->scopeRoots($scopeVars));
    }

    /**
     * The scope ROOTS for the current context: the distinct FIRST path segments of $scopeVars' keys
     * (`element`, `index`, `element.price` → element, index; a body's `input`, `suffix` → input, suffix),
     * falling back to ScopeRef's default (element/index) when there is no scope frame.
     *
     * @param  array<string, array<string, mixed>>|null  $scopeVars
     * @return array<int, string>
     */
    private function scopeRoots(?array $scopeVars): array
    {
        if ($scopeVars === null) {
            return ScopeRef::DEFAULT_ROOTS;
        }

        $roots = [];

        foreach (array_keys($scopeVars) as $key) {
            $roots[] = explode('.', (string) $key, 2)[0];
        }

        return array_values(array_unique($roots));
    }

    /**
     * The ENCLOSING FUNCTION FRAME within a caller's active scope — its `input` / arg-name entries, with the
     * ELEMENT-scope keys (`element` / `index` / `element.<sub>`) stripped out. This is what an element
     * pipeline unions into its own element scope (validateElementPipeline), and it EXACTLY mirrors the
     * runtime: the function frame rides the FunctionScope context key and PERSISTS across element nesting,
     * while the `element`/`index` overlay is REPLACED per element pipeline (OperationExecutor::scopeOverlay's
     * `['scope' => …] + $context`). So a nested element pipeline sees the CURRENT element/index UNION only
     * the enclosing function frame — NEVER an OUTER element's scope — keeping the write scope EXACTLY the
     * runtime scope (no fail-open on a nested element pipeline). Empty (no frame) for every plain workflow/
     * condition array-op, so those element scopes stay byte-identical. Reserved names guarantee `input` and
     * arg names never collide with the element-scope keys this strips.
     *
     * @param  array<string, array{type: VariableType, enumOptions: array<int, string>|null}>|null  $scopeVars
     * @return array<string, array{type: VariableType, enumOptions: array<int, string>|null}>
     */
    private function enclosingFrameOnly(?array $scopeVars): array
    {
        if ($scopeVars === null) {
            return [];
        }

        return array_filter(
            $scopeVars,
            fn (string $key): bool => $key !== 'element' && $key !== 'index' && !str_starts_with($key, 'element.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The full dotted path a ref resolves against (`trigger` + `fields.abc` → `trigger.fields.abc`),
     * mirroring the resolver — a path already carrying its root is used as-is; null when no path.
     *
     * @param  array<string, mixed>  $ref
     */
    private function refFullPath(array $ref): ?string
    {
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

    /**
     * Whether an argument value is a variable union (`{kind:'variable', …}`) rather than a literal.
     * Delegates to ValueOrVariable — the ONE definition of the union shape, shared with the runtime
     * resolver (which pre-resolves it) and the executor (which rejects one that reached it anyway).
     */
    private function isVariableArg(mixed $value): bool
    {
        return ValueOrVariable::isVariable($value);
    }

    /** Add $message under $key unless $ok. */
    private function require(ValidatorContract $validator, bool $ok, string $key, string $message): void
    {
        if (!$ok) {
            $validator->errors()->add($key, $message);
        }
    }

    /** A single source option: a string that is one of the source field's option values. @param array<int, string>|null $enumOptions */
    private function validateOption(ValidatorContract $validator, mixed $value, string $key, ?array $enumOptions): void
    {
        if (!is_string($value)) {
            $validator->errors()->add($key, 'The value must be one of the source options.');

            return;
        }

        if ($enumOptions !== null && !in_array($value, $enumOptions, true)) {
            $validator->errors()->add($key, 'The value is not an option of the source field.');
        }
    }

    /** A non-empty subset of the source field's option values. @param array<int, string>|null $enumOptions */
    private function validateOptions(ValidatorContract $validator, mixed $value, string $key, ?array $enumOptions): void
    {
        if (!is_array($value) || $value === []) {
            $validator->errors()->add($key, 'The values must be a non-empty list of source options.');

            return;
        }

        foreach ($value as $index => $option) {
            if (!is_string($option)) {
                $validator->errors()->add($key . '.' . $index, 'Each value must be a source option.');

                continue;
            }

            if ($enumOptions !== null && !in_array($option, $enumOptions, true)) {
                $validator->errors()->add($key . '.' . $index, 'The value is not an option of the source field.');
            }
        }
    }

    /**
     * A per-option map: keys ⊆ the source options; each ENTRY value is a LITERAL target OR a value-or-
     * variable union (Defect-3). A literal must be non-empty (and number/date targets parseable for those
     * mapType kinds); for an ENUM mapType (enum_to_choice) it must additionally be one of the destination
     * field's $targetOptions (when supplied). A union ENTRY is validated against the reference index as an
     * arg-variable of the map's TARGET type ($mapType), with $targetOptions threaded for an ENUM (choice)
     * target so it must map into them — see validateEntryVariable. When $refCtx is null a union entry falls
     * to the literal checks and is rejected there (args are literal-only without a reference index).
     *
     * @param  array<int, string>|null  $enumOptions
     * @param  array<int, string>|null  $targetOptions
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     */
    private function validateSourceMap(ValidatorContract $validator, mixed $value, string $key, ?VariableType $mapType, ?array $enumOptions, ?array $targetOptions = null, ?array $refCtx = null, int $argDepth = 0): void
    {
        if (!is_array($value)) {
            $validator->errors()->add($key, 'The mapping must be a value-per-option object.');

            return;
        }

        foreach ($value as $option => $target) {
            $option = (string) $option;
            $entryKey = $key . '.' . $option;

            if ($enumOptions !== null && !in_array($option, $enumOptions, true)) {
                $validator->errors()->add($entryKey, 'The mapping key is not an option of the source field.');
            }

            // A per-entry VARIABLE: validate as an arg-variable of the map's TARGET type. A CHOICE target
            // (enum_to_choice, mapType ENUM) threads $targetOptions so the entry must map into them.
            if ($refCtx !== null && $this->isVariableArg($target)) {
                $entryType = $mapType ?? VariableType::TEXT;
                $entryTargets = $entryType === VariableType::ENUM ? $targetOptions : null;
                $this->validateEntryVariable($validator, $target, $entryKey, $entryType, $entryTargets, $refCtx, $argDepth);

                continue;
            }

            if ($target === null || $target === '') {
                $validator->errors()->add($entryKey, 'The mapping value for this option is required.');

                continue;
            }

            if ($mapType === VariableType::NUMBER && !is_numeric($target)) {
                $validator->errors()->add($entryKey, 'The mapping value must be a number.');
            } elseif ($mapType === VariableType::DATE && !$this->isYmd($target)) {
                $validator->errors()->add($entryKey, 'The mapping value must be a Y-m-d date.');
            } elseif ($mapType === VariableType::ENUM && $targetOptions !== null && !in_array($target, $targetOptions, true)) {
                $validator->errors()->add($entryKey, "The mapping value must be one of the target field's options.");
            }
        }
    }

    /**
     * The `rules` arg of match_to_choice: a list of {when, then} rules. `when` is now a boolean-terminal
     * condition PIPELINE run over the op's TEXT input (was a scalar-equality string) — validated by
     * walkPipeline from TEXT and required to END in a boolean, exactly like a condition pipeline; a
     * choice-producing op inside a `when` is rejected (no destination option set in a condition context,
     * and it would let the runtime sub-run re-enter the choice machinery). `then` is a LITERAL target
     * option OR a value-or-variable union (Defect-3) — UNCHANGED: a literal `then` is a TARGET option (∈
     * the destination field's option set, checked when $targetOptions is supplied); a union `then` is
     * validated as a CHOICE arg-variable into $targetOptions (see validateEntryVariable). An empty list is
     * allowed — the required fallback keeps the op total. A non-array `rules` (or a malformed entry) is a
     * granular error under the arg path. When $refCtx is null a union `then` falls to the literal check
     * and is rejected there.
     *
     * @param  array<int, string>|null  $targetOptions
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     */
    private function validateChoiceRules(ValidatorContract $validator, mixed $value, string $key, ?array $targetOptions, ?array $refCtx = null, int $argDepth = 0): void
    {
        if (!is_array($value)) {
            $validator->errors()->add($key, 'The rules must be a list of match rules.');

            return;
        }

        foreach ($value as $index => $rule) {
            $entryKey = $key . '.' . $index;

            if (!is_array($rule)) {
                $validator->errors()->add($entryKey, 'Each rule must be a when/then object.');

                continue;
            }

            $this->validateChoiceRuleWhen($validator, $rule['when'] ?? null, $entryKey . '.when', $refCtx, $argDepth);

            $then = $rule['then'] ?? null;

            // A per-entry VARIABLE `then`: validate as a CHOICE arg-variable that maps into $targetOptions.
            if ($refCtx !== null && $this->isVariableArg($then)) {
                $this->validateEntryVariable($validator, $then, $entryKey . '.then', VariableType::ENUM, $targetOptions, $refCtx, $argDepth);

                continue;
            }

            if (!is_string($then)) {
                $validator->errors()->add($entryKey . '.then', 'The rule then must be a target option.');

                continue;
            }

            if ($targetOptions !== null && !in_array($then, $targetOptions, true)) {
                $validator->errors()->add($entryKey . '.then', "The rule then must be one of the target field's options.");
            }
        }
    }

    /**
     * Validate ONE rule's `when` — a boolean-terminal condition PIPELINE run over the op's TEXT input.
     * It must be an array (a pipeline), must NOT contain a choice-producing op (there is no destination
     * option set in a condition context, and it would let the runtime sub-run re-enter the choice
     * machinery — mirrors the executor's pipelineHasChoiceOp defence), and must END in a boolean
     * (mirrors validatePipeline's boolean-terminal error). Arg-variables inside the `when` ops are
     * validated against $refCtx exactly like any other pipeline's args.
     *
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     */
    private function validateChoiceRuleWhen(ValidatorContract $validator, mixed $when, string $key, ?array $refCtx, int $argDepth): void
    {
        if (!is_array($when)) {
            $validator->errors()->add($key, 'The rule condition requires a pipeline of operations.');

            return;
        }

        if ($this->pipelineHasChoiceOp($when)) {
            $validator->errors()->add($key, 'A rule condition cannot contain a choice operation.');

            return;
        }

        $terminal = $this->walkPipeline($validator, $when, $key, VariableType::TEXT, null, refCtx: $refCtx, argDepth: $argDepth);

        if ($terminal !== null && $terminal !== VariableType::BOOLEAN) {
            $validator->errors()->add($key, 'A rule condition must end in a boolean (it ends in ' . $terminal->value . ').');
        }
    }

    /**
     * Whether any step of $pipeline is a CHOICE-producing op (match_to_choice / enum_to_choice) — the
     * guard that keeps a choice op out of a `when` condition pipeline (mirrors the executor's defence).
     *
     * @param  array<int, mixed>  $pipeline
     */
    private function pipelineHasChoiceOp(array $pipeline): bool
    {
        foreach ($pipeline as $step) {
            if (!is_array($step)) {
                continue;
            }

            // Read BOTH op-key spellings the executor accepts (`op` OR the editor's `operationId`) so
            // this write-side choice-op reject can never drift from the runtime guard it mirrors. A
            // choice-producing op is always a built-in, so the empty function set suffices; routed through
            // the resolver so no direct Operation::tryFrom survives in the engine (the completeness gate).
            $op = $this->operations->resolve((string) ($step['op'] ?? $step['operationId'] ?? ''), []);

            if ($op !== null && $op->producesChoice()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `fallback` arg of match_to_choice: a REQUIRED target option (∈ the destination field's
     * option set when $targetOptions is supplied). It is what the op yields when no rule matched.
     *
     * @param  array<int, string>|null  $targetOptions
     */
    private function validateChoiceFallback(ValidatorContract $validator, mixed $value, string $key, ?array $targetOptions): void
    {
        if (!is_string($value) || $value === '') {
            $validator->errors()->add($key, 'A fallback option is required.');

            return;
        }

        if ($targetOptions !== null && !in_array($value, $targetOptions, true)) {
            $validator->errors()->add($key, "The fallback must be one of the target field's options.");
        }
    }

    /** Whether a value is a STRICT ISO Y-m-d date string (rejecting roll-over / missing padding). */
    private function isYmd(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
