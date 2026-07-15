<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Forms\Models\Form;
use App\Modules\Workflows\DTOs\WorkflowOperationArg;
use App\Modules\Workflows\Enums\ConditionTreeLimits;
use App\Modules\Workflows\Enums\WorkflowOperation;
use App\Modules\Workflows\Enums\WorkflowOperationArgType;
use App\Modules\Workflows\Enums\WorkflowVariableType;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;

/**
 * The ONE place the NEW condition-TREE shape ({ logic, children[] } of groups + conditions) is
 * write-validated — extracted from StoreWorkflowRequest exactly as WorkflowScheduleRulesValidator
 * was for the schedule descriptor, so the recursive structure/limits/type-flow rules live in one
 * cohesive place and the request stays thin. Errors are added to the request's Validator under the
 * caller's `conditions` prefix with INDEXED paths (conditions.children.0.pipeline.1.args.value …)
 * so the FE can map each message to the offending node.
 *
 * The LEGACY flat-clause shape is NOT handled here — StoreWorkflowRequest keeps validating that
 * inline (unchanged). This validator runs only when `conditions` is an object (associative) tree.
 *
 * What it enforces:
 *   - STRUCTURE + HARD LIMITS: group/condition kinds, logic ∈ {and, or}, depth ≤ 5 (root = 1),
 *     ≤ 10 children per group, ≤ 10 pipeline steps, non-empty groups (ConditionTreeLimits).
 *   - SOURCE: `source` must be a field in the form's condition catalog and `source_type` must equal
 *     that field's type. (Sources are the form's field descriptors — `fields.<id>` — matching the
 *     legacy condition contract and the runtime payload; system/step variables are out of scope.)
 *   - TYPE-FLOW: starting from `source_type`, each op must exist, accept the current type, and the
 *     pipeline must TERMINATE in boolean.
 *   - ARGS per descriptor: required presence + shape (scalar/list/map), sourceOption ∈ the source
 *     field's options, sourceOptions ⊆ options (non-empty), sourceMap keys ⊆ options with non-empty
 *     values (number/date targets parseable), literal date args strict Y-m-d, and no foreign arg keys.
 *   - CHOICE args (value pipelines targeting a destination field with a fixed option set, e.g. a task
 *     priority — the target options are injected per field, NOT in the static descriptor): the
 *     enum_to_choice mapping VALUES, and match_to_choice rule `then`/`fallback`, must all be ⊆ those
 *     target options; a value pipeline for such a field must END in a choice-producing op.
 *
 * When the form cannot be resolved (a missing/foreign form_id — already reported by the request's own
 * rule) the catalog-dependent checks (source existence/type + option membership) are SKIPPED; every
 * catalog-independent rule still runs.
 */
class WorkflowConditionTreeValidator
{
    public function __construct(
        private WorkflowVariableCatalogService $catalog,
    ) {}

    /**
     * Validate the tree under $prefix, adding granular errors to $validator. $form is the resolved
     * trigger form (or null when it could not be resolved — catalog checks are then skipped).
     *
     * @param  array<string, mixed>  $tree
     */
    public function validate(ValidatorContract $validator, array $tree, string $prefix, ?Form $form): void
    {
        $fields = $form !== null ? $this->fieldTable($form) : null;

        $this->validateGroup($validator, $tree, $prefix, $fields, 1);
    }

    /**
     * The form's condition-field descriptors keyed by their `fields.<id>` path — the set of valid
     * condition sources, each carrying its type and (for enum/multi) its option values.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fieldTable(Form $form): array
    {
        $table = [];

        foreach ($this->catalog->conditionFieldsFor($form) as $field) {
            $table[$field['path']] = $field;
        }

        return $table;
    }

    /**
     * A group node: kind (optional on the root, else `group`), logic ∈ {and, or}, a non-empty and
     * bounded child list, and the depth cap. Each child recurses one level deeper.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>|null  $fields
     */
    private function validateGroup(ValidatorContract $validator, array $node, string $prefix, ?array $fields, int $depth): void
    {
        if ($depth > ConditionTreeLimits::MAX_DEPTH) {
            $validator->errors()->add($prefix, 'The conditions are nested too deeply (max ' . ConditionTreeLimits::MAX_DEPTH . ' levels).');

            return;
        }

        if (array_key_exists('kind', $node) && $node['kind'] !== 'group') {
            $validator->errors()->add($prefix . '.kind', 'A group node kind must be group.');
        }

        if (!in_array($node['logic'] ?? null, ['and', 'or'], true)) {
            $validator->errors()->add($prefix . '.logic', 'A group requires a logic of and or or.');
        }

        $children = $node['children'] ?? null;

        if (!is_array($children) || $children === []) {
            $validator->errors()->add($prefix . '.children', 'A group requires at least one child.');

            return;
        }

        if (count($children) > ConditionTreeLimits::MAX_CHILDREN) {
            $validator->errors()->add($prefix . '.children', 'A group may hold at most ' . ConditionTreeLimits::MAX_CHILDREN . ' children.');
        }

        foreach ($children as $index => $child) {
            $this->validateChild($validator, $child, $prefix . '.children.' . $index, $fields, $depth + 1);
        }
    }

    /**
     * Dispatch a child by kind: a condition leaf or a nested group. Anything else is rejected.
     *
     * @param  array<string, array<string, mixed>>|null  $fields
     */
    private function validateChild(ValidatorContract $validator, mixed $child, string $prefix, ?array $fields, int $depth): void
    {
        if (!is_array($child)) {
            $validator->errors()->add($prefix, 'A condition node must be an object.');

            return;
        }

        match ($child['kind'] ?? null) {
            'condition' => $this->validateCondition($validator, $child, $prefix, $fields),
            'group' => $this->validateGroup($validator, $child, $prefix, $fields, $depth),
            default => $validator->errors()->add($prefix . '.kind', 'A node kind must be group or condition.'),
        };
    }

    /**
     * A leaf condition: a catalog source + matching source_type, and a typed pipeline that flows from
     * source_type through valid ops to a boolean.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>|null  $fields
     */
    private function validateCondition(ValidatorContract $validator, array $node, string $prefix, ?array $fields): void
    {
        $type = WorkflowVariableType::tryFrom((string) ($node['source_type'] ?? ''));

        if ($type === null) {
            $validator->errors()->add($prefix . '.source_type', 'The source_type is not a valid variable type.');
        }

        $enumOptions = $this->validateSource($validator, $node, $prefix, $fields, $type);

        if ($type === null) {
            return; // cannot type-flow the pipeline without a valid source type
        }

        $this->validatePipeline($validator, $node, $prefix, $type, $enumOptions);
    }

    /**
     * Check `source` against the catalog (when available) and that `source_type` equals the field's
     * type. Returns the source field's option values for the pipeline's option-arg checks (null when
     * the catalog is unavailable or the source has no options).
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>|null  $fields
     * @return array<int, string>|null
     */
    private function validateSource(ValidatorContract $validator, array $node, string $prefix, ?array $fields, ?WorkflowVariableType $type): ?array
    {
        $source = $node['source'] ?? null;

        if (!is_string($source) || $source === '') {
            $validator->errors()->add($prefix . '.source', 'A condition requires a source path.');

            return null;
        }

        if ($fields === null) {
            return null; // form unresolved — the request already reported form_id; skip catalog checks
        }

        $descriptor = $fields[$source] ?? null;

        if ($descriptor === null) {
            $validator->errors()->add($prefix . '.source', 'The source is not a condition field of the selected form.');

            return null;
        }

        if ($type !== null && ($descriptor['type'] ?? null) !== $type->value) {
            $validator->errors()->add($prefix . '.source_type', 'The source_type does not match the field type in the catalog.');
        }

        $options = $descriptor['enumOptions'] ?? null;

        return is_array($options) ? array_values(array_map('strval', $options)) : null;
    }

    /**
     * Walk a CONDITION's pipeline from $sourceType and require a boolean terminal.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, string>|null  $enumOptions
     */
    private function validatePipeline(ValidatorContract $validator, array $node, string $prefix, WorkflowVariableType $sourceType, ?array $enumOptions): void
    {
        $pipeline = $node['pipeline'] ?? null;

        if (!is_array($pipeline)) {
            $validator->errors()->add($prefix . '.pipeline', 'A condition requires a pipeline of operations.');

            return;
        }

        $terminal = $this->walkPipeline($validator, $pipeline, $prefix . '.pipeline', $sourceType, $enumOptions);

        if ($terminal !== null && $terminal !== WorkflowVariableType::BOOLEAN) {
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
     * @param  array<int, mixed>  $pipeline
     * @param  array<int, WorkflowVariableType>  $allowedTerminals
     * @param  array<int, string>|null  $sourceEnumOptions
     * @param  array<int, string>|null  $targetOptions
     */
    public function validateValuePipeline(
        ValidatorContract $validator,
        array $pipeline,
        string $prefix,
        WorkflowVariableType $sourceType,
        array $allowedTerminals,
        ?array $sourceEnumOptions,
        ?array $targetOptions = null,
    ): void {
        $terminal = $this->walkPipeline($validator, $pipeline, $prefix, $sourceType, $sourceEnumOptions, $targetOptions);

        // A choice field demands a mapping pipeline that ENDS in a choice-producing op.
        if ($targetOptions !== null) {
            $last = $this->lastOperation($pipeline);

            if ($last === null || !$last->producesChoice()) {
                $validator->errors()->add($prefix, 'The pipeline must map the value to a valid choice.');
            }

            return;
        }

        if ($terminal !== null && !in_array($terminal, $allowedTerminals, true)) {
            $expected = implode(' or ', array_map(fn (WorkflowVariableType $t) => $t->value, $allowedTerminals));
            $validator->errors()->add($prefix, 'The pipeline must produce a ' . $expected . ' value (it produces ' . $terminal->value . ').');
        }
    }

    /**
     * The LAST operation of a pipeline (null when empty or the last step's op is unknown) — used by
     * the choice terminal rule, which requires a value pipeline to END in a choice-producing op.
     *
     * @param  array<int, mixed>  $pipeline
     */
    private function lastOperation(array $pipeline): ?WorkflowOperation
    {
        if ($pipeline === []) {
            return null;
        }

        $last = end($pipeline);

        return is_array($last) ? WorkflowOperation::tryFrom((string) ($last['op'] ?? '')) : null;
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
     */
    private function walkPipeline(ValidatorContract $validator, array $pipeline, string $prefix, WorkflowVariableType $sourceType, ?array $enumOptions, ?array $targetOptions = null): ?WorkflowVariableType
    {
        if (count($pipeline) > ConditionTreeLimits::MAX_PIPELINE_STEPS) {
            $validator->errors()->add($prefix, 'A pipeline may hold at most ' . ConditionTreeLimits::MAX_PIPELINE_STEPS . ' steps.');
        }

        $currentType = $sourceType;

        foreach ($pipeline as $index => $step) {
            $sp = $prefix . '.' . $index;

            if (!is_array($step)) {
                $validator->errors()->add($sp, 'A pipeline step must be an object.');

                return null;
            }

            $op = WorkflowOperation::tryFrom((string) ($step['op'] ?? ''));

            if ($op === null) {
                $validator->errors()->add($sp . '.op', 'The operation is unknown.');

                return null;
            }

            if ($op->inputType() !== $currentType) {
                $validator->errors()->add(
                    $sp . '.op',
                    'The ' . $op->value . ' operation expects a ' . $op->inputType()->value . ' input but the value is ' . $currentType->value . '.',
                );

                return null;
            }

            $this->validateArgs($validator, $op, is_array($step['args'] ?? null) ? $step['args'] : [], $sp . '.args', $enumOptions, $targetOptions);

            $currentType = $op->outputType();
        }

        return $currentType;
    }

    /**
     * Validate every declared arg of $op and reject any foreign arg key (descriptor-driven, like the
     * rest of the module).
     *
     * @param  array<string, mixed>  $args
     * @param  array<int, string>|null  $enumOptions
     * @param  array<int, string>|null  $targetOptions
     */
    private function validateArgs(ValidatorContract $validator, WorkflowOperation $op, array $args, string $prefix, ?array $enumOptions, ?array $targetOptions = null): void
    {
        $allowed = [];

        foreach ($op->argDescriptors() as $arg) {
            $allowed[] = $arg->id;
            $this->validateArg($validator, $arg, $args, $prefix . '.' . $arg->id, $enumOptions, $targetOptions);
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
     * @param  array<string, mixed>  $args
     * @param  array<int, string>|null  $enumOptions
     * @param  array<int, string>|null  $targetOptions
     */
    private function validateArg(ValidatorContract $validator, WorkflowOperationArg $arg, array $args, string $key, ?array $enumOptions, ?array $targetOptions = null): void
    {
        $value = $args[$arg->id] ?? null;

        match ($arg->type) {
            WorkflowOperationArgType::NUMBER => $this->require($validator, is_numeric($value), $key, 'The ' . $arg->id . ' must be a number.'),
            WorkflowOperationArgType::TEXT, WorkflowOperationArgType::SELECT => $this->require($validator, is_string($value), $key, 'The ' . $arg->id . ' must be text.'),
            WorkflowOperationArgType::BOOLEAN => $this->require($validator, is_bool($value), $key, 'The ' . $arg->id . ' must be a boolean.'),
            WorkflowOperationArgType::DATE => $this->require($validator, $this->isYmd($value), $key, 'The ' . $arg->id . ' must be a Y-m-d date.'),
            WorkflowOperationArgType::SOURCE_OPTION => $this->validateOption($validator, $value, $key, $enumOptions),
            WorkflowOperationArgType::SOURCE_OPTIONS => $this->validateOptions($validator, $value, $key, $enumOptions),
            WorkflowOperationArgType::SOURCE_MAP => $this->validateSourceMap($validator, $value, $key, $arg->mapType, $enumOptions, $targetOptions),
            WorkflowOperationArgType::CHOICE_RULES => $this->validateChoiceRules($validator, $value, $key, $targetOptions),
            WorkflowOperationArgType::CHOICE_FALLBACK => $this->validateChoiceFallback($validator, $value, $key, $targetOptions),
        };
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
     * A per-option map: keys ⊆ the source options, values non-empty (and number/date targets
     * parseable for those mapType kinds). For an ENUM mapType (enum_to_choice) each mapped VALUE
     * must additionally be one of the destination field's $targetOptions (when supplied).
     *
     * @param  array<int, string>|null  $enumOptions
     * @param  array<int, string>|null  $targetOptions
     */
    private function validateSourceMap(ValidatorContract $validator, mixed $value, string $key, ?WorkflowVariableType $mapType, ?array $enumOptions, ?array $targetOptions = null): void
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

            if ($target === null || $target === '') {
                $validator->errors()->add($entryKey, 'The mapping value for this option is required.');

                continue;
            }

            if ($mapType === WorkflowVariableType::NUMBER && !is_numeric($target)) {
                $validator->errors()->add($entryKey, 'The mapping value must be a number.');
            } elseif ($mapType === WorkflowVariableType::DATE && !$this->isYmd($target)) {
                $validator->errors()->add($entryKey, 'The mapping value must be a Y-m-d date.');
            } elseif ($mapType === WorkflowVariableType::ENUM && $targetOptions !== null && !in_array($target, $targetOptions, true)) {
                $validator->errors()->add($entryKey, "The mapping value must be one of the target field's options.");
            }
        }
    }

    /**
     * The `rules` arg of match_to_choice: a list of {when, then} rules. `when` is the text value the
     * source is compared against; `then` is a TARGET option (∈ the destination field's option set,
     * checked when $targetOptions is supplied). An empty list is allowed — the required fallback keeps
     * the op total. A non-array `rules` (or a malformed entry) is a granular error under the arg path.
     *
     * @param  array<int, string>|null  $targetOptions
     */
    private function validateChoiceRules(ValidatorContract $validator, mixed $value, string $key, ?array $targetOptions): void
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

            if (!is_string($rule['when'] ?? null)) {
                $validator->errors()->add($entryKey . '.when', 'The rule when must be text.');
            }

            $then = $rule['then'] ?? null;

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
