<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Forms\Models\Form;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\PipelineValidator;
use App\Modules\Workflows\Enums\ConditionTreeLimits;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;

/**
 * The ONE place the NEW condition-TREE shape ({ logic, children[] } of groups + conditions) is
 * write-validated — extracted from StoreWorkflowRequest exactly as WorkflowScheduleRulesValidator
 * was for the schedule descriptor, so the recursive structure/limits rules live in one cohesive place
 * and the request stays thin. Errors are added to the request's Validator under the caller's
 * `conditions` prefix with INDEXED paths (conditions.children.0.pipeline.1.args.value …) so the FE can
 * map each message to the offending node.
 *
 * The LEGACY flat-clause shape is NOT handled here — StoreWorkflowRequest keeps validating that
 * inline (unchanged). This validator runs only when `conditions` is an object (associative) tree.
 *
 * TREE structure lives here; the per-leaf PIPELINE type-flow (op walk, args, argument variables,
 * element pipelines, choice mapping) lives in the Variables module's PipelineValidator, which this
 * class CALLS per condition. What THIS class enforces:
 *   - STRUCTURE + HARD LIMITS: group/condition kinds, logic ∈ {and, or}, depth ≤ 5 (root = 1),
 *     ≤ 10 children per group, non-empty groups (ConditionTreeLimits).
 *   - SOURCE: `source` must be an entry of the condition-source catalog
 *     (WorkflowVariableCatalogService::conditionFieldsFor) and `source_type` must equal that entry's
 *     type. TWO VOCABULARIES, one table: a FORM FIELD keeps the legacy unprefixed `fields.<id>` path
 *     (matching the flat clause contract and the runtime payload), while a workspace GLOBAL uses its
 *     full catalog path `globals.<key>[.<sub>]` (its catalog source IS its root). Only a global's
 *     SCALAR leaves are offered — an object global, like a form container, is not conditionable.
 *     Trigger system vars and `steps.*` are NOT condition sources.
 *   - Then it hands each condition's `default` + `pipeline` (seeded with the source's real descriptor)
 *     to PipelineValidator for the type-flow / literal / argument-variable checks.
 *
 * When the form cannot be resolved (a missing/foreign form_id — already reported by the request's own
 * rule) the catalog-dependent checks (source existence/type + option membership) are SKIPPED; every
 * catalog-independent rule still runs.
 */
class WorkflowConditionTreeValidator
{
    public function __construct(
        private WorkflowVariableCatalogService $catalog,
        private PipelineValidator $pipeline,
    ) {}

    /**
     * Validate the tree under $prefix, adding granular errors to $validator. $form is the resolved
     * trigger form (or null when it could not be resolved — catalog checks are then skipped).
     *
     * $refCtx is the reference index an operation ARGUMENT may reference (B6). The caller
     * (StoreWorkflowRequest) builds it with NO prior steps, so a gate can reference the trigger's
     * variables and the workspace globals but never a `steps.*` output — nothing has run yet. Its
     * `sources` list is the roots a ref may name (VariableResolver::ROOTS), which the caller
     * supplies so the Variables pipeline validator needs no back-dependency on the resolver. Null keeps
     * the pre-B6 behaviour (arguments are literal-only).
     *
     * @param  array<string, mixed>  $tree
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool, sources: array<int, string>}|null  $refCtx
     */
    public function validate(ValidatorContract $validator, array $tree, string $prefix, ?Form $form, ?array $refCtx = null): void
    {
        $fields = $form !== null ? $this->fieldTable($form) : null;

        $this->validateGroup($validator, $tree, $prefix, $fields, 1, $refCtx);
    }

    /**
     * The condition-SOURCE descriptors keyed by their path — the set of valid condition sources, each
     * carrying its type and (for enum/multi) its option values. Two vocabularies live in this one
     * table: the form's fields as `fields.<id>` and the workspace globals as `globals.<key>` (see the
     * class docblock and WorkflowVariableCatalogService::conditionFieldsFor).
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
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool, sources: array<int, string>}|null  $refCtx
     */
    private function validateGroup(ValidatorContract $validator, array $node, string $prefix, ?array $fields, int $depth, ?array $refCtx = null): void
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
            $this->validateChild($validator, $child, $prefix . '.children.' . $index, $fields, $depth + 1, $refCtx);
        }
    }

    /**
     * Dispatch a child by kind: a condition leaf or a nested group. Anything else is rejected.
     *
     * @param  array<string, array<string, mixed>>|null  $fields
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool, sources: array<int, string>}|null  $refCtx
     */
    private function validateChild(ValidatorContract $validator, mixed $child, string $prefix, ?array $fields, int $depth, ?array $refCtx = null): void
    {
        if (!is_array($child)) {
            $validator->errors()->add($prefix, 'A condition node must be an object.');

            return;
        }

        match ($child['kind'] ?? null) {
            'condition' => $this->validateCondition($validator, $child, $prefix, $fields, $refCtx),
            'group' => $this->validateGroup($validator, $child, $prefix, $fields, $depth, $refCtx),
            default => $validator->errors()->add($prefix . '.kind', 'A node kind must be group or condition.'),
        };
    }

    /**
     * A leaf condition: a catalog source + matching source_type, then its type-compatible `default`
     * and its typed pipeline are handed to PipelineValidator (the pipeline flows from source_type
     * through valid ops to a boolean).
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, array<string, mixed>>|null  $fields
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool, sources: array<int, string>}|null  $refCtx
     */
    private function validateCondition(ValidatorContract $validator, array $node, string $prefix, ?array $fields, ?array $refCtx = null): void
    {
        $type = VariableType::tryFrom((string) ($node['source_type'] ?? ''));

        if ($type === null) {
            $validator->errors()->add($prefix . '.source_type', 'The source_type is not a valid variable type.');
        }

        $enumOptions = $this->validateSource($validator, $node, $prefix, $fields, $type);

        if ($type === null) {
            return; // cannot type-flow the pipeline without a valid source type
        }

        $this->pipeline->validateDefault($validator, $node, $prefix, $type, $enumOptions);
        $this->pipeline->validateConditionPipeline($validator, $node, $prefix, $type, $enumOptions, $refCtx, $this->sourceDescriptor($fields, $node));
    }

    /**
     * The REAL structured descriptor of a condition source (array-ops F2), read off the field table by the
     * node's `source` path — the ONE way an array<object> (repeater) / array<file> condition source keeps
     * its array-ness + element `fields` when the pipeline walk seeds (its degraded flat wire type is `multi`,
     * so the flat seed alone would expose no element subfields). Null for a scalar/enum leaf (no threaded
     * descriptor — the flat seed then applies, a provable no-op).
     *
     * @param  array<string, array<string, mixed>>|null  $fields
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>|null
     */
    private function sourceDescriptor(?array $fields, array $node): ?array
    {
        $source = $node['source'] ?? null;

        if ($fields === null || !is_string($source)) {
            return null;
        }

        $descriptor = $fields[$source]['descriptor'] ?? null;

        return is_array($descriptor) ? $descriptor : null;
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
    private function validateSource(ValidatorContract $validator, array $node, string $prefix, ?array $fields, ?VariableType $type): ?array
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
            $validator->errors()->add($prefix . '.source', 'The source is not a condition field of the selected form or a workspace global.');

            return null;
        }

        if ($type !== null && ($descriptor['type'] ?? null) !== $type->value) {
            $validator->errors()->add($prefix . '.source_type', 'The source_type does not match the field type in the catalog.');
        }

        $options = $descriptor['enumOptions'] ?? null;

        return is_array($options) ? array_values(array_map('strval', $options)) : null;
    }
}
