<?php

namespace App\Modules\Workflows\Http\Requests;

use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Forms\Models\Form;
use App\Modules\Labels\Models\Label;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Workflows\Enums\WorkflowConditionOperator;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Enums\WorkflowVariableType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Services\WorkflowConditionTreeValidator;
use App\Modules\Workflows\Services\WorkflowScheduleRulesValidator;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use App\Rules\ScopedExists;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Validates a workflow DEFINITION on create.
 *
 * Per-trigger-type strategy (chosen approach): the base rules are constant, and
 * trigger_config is validated by the sub-rule set that BELONGS to the submitted
 * trigger_type (see triggerConfigRules()). Cross-type nonsense (e.g. a form_id on a
 * schedule trigger, or a schedule block on a form_submitted trigger) is rejected in
 * withValidator(): once the trigger_type is a known enum value, any trigger_config.* key
 * outside that type's allow-list fails validation. This keeps the config strict without a
 * per-type request subclass, and the DTO can persist the validated trigger_config verbatim.
 *
 * PER-STEP-TYPE config (same shape, applied per step): each step's `config` is validated by
 * the rule set that belongs to its OWN `type` (create_task vs create_form_report). Because
 * `steps` is an indexed list of heterogeneous types, the per-step rules cannot be expressed
 * as flat `steps.*.config.*` rules (those would apply to every step regardless of type); they
 * run in withValidator() over each step against its type's allow-list, and a config key
 * outside that list is rejected exactly like a foreign trigger_config key. Structured
 * literal|variable union fields (create_task priority/deadline, report window dates) are
 * validated by one reusable shape check.
 *
 * status is NOT accepted here — a workflow is created inactive and toggled via
 * PATCH /workflows/{workflow}/status only.
 */
class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Workflow::class);
    }

    public function rules(): array
    {
        return array_merge($this->baseRules(), $this->triggerConfigRules());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'steps.*.key.regex' => 'A step key may contain only letters, digits and underscores.',
        ];
    }

    /**
     * Definition rules shared by every trigger type: identity, the ordered step list
     * (with distinct keys for {{steps.<key>.*}} references) and optional conditions.
     */
    private function baseRules(): array
    {
        return array_merge([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2500'],
            'icon' => ['nullable', 'string', 'max:100'],

            'trigger_type' => ['required', Rule::enum(WorkflowTriggerType::class)],

            // Ordered actions. Each step's `key` is a distinct identifier used for
            // {{steps.<key>.*}} references from later steps, so it must be unique.
            'steps' => ['required', 'array', 'min:1', 'max:50'],
            'steps.*.type' => ['required', Rule::enum(WorkflowStepType::class)],
            // Safe charset: the key is substituted into `steps.<key>.<output>` dotted paths and read
            // via Arr::get (a dot means nesting), so a dot/space would silently break every reference
            // to the step. Mirrors the FE `sanitizeStepKey` guard for non-FE / API callers.
            'steps.*.key' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[A-Za-z0-9_]+$/'],
            'steps.*.config' => ['nullable', 'array'],

            // Optional gate conditions — polymorphic (see withValidator::validateConditions):
            //   LEGACY: a flat LIST of {field, field_type, operator, value} clauses.
            //   NEW:    a logic TREE object {logic, children[]} of groups + typed pipelines.
            // A null/absent/empty conditions is no gate. The per-clause LIST rules are added only
            // for the list shape (flatConditionRules) so they never fire on the tree object; the
            // tree's recursive shape is validated in withValidator via WorkflowConditionTreeValidator.
            'conditions' => ['nullable', 'array', 'max:50'],
        ], $this->flatConditionRules());
    }

    /**
     * The per-clause rules for the LEGACY flat conditions LIST — included ONLY when the submitted
     * `conditions` is a non-empty list. Omitting them for the tree object keeps `conditions.*.field`
     * (and friends) from firing spuriously against the tree's {logic, children} keys.
     *
     * @return array<string, array<int, mixed>>
     */
    private function flatConditionRules(): array
    {
        $conditions = $this->input('conditions');

        if (!is_array($conditions) || $conditions === [] || !array_is_list($conditions)) {
            return [];
        }

        return [
            'conditions.*.field' => ['required', 'string', 'max:255'],
            'conditions.*.field_type' => ['required', Rule::enum(WorkflowVariableType::class)],
            'conditions.*.operator' => ['required', Rule::enum(WorkflowConditionOperator::class)],
            'conditions.*.value' => ['nullable'],
        ];
    }

    /**
     * trigger_config rules for the SUBMITTED trigger_type only. An unknown/absent
     * trigger_type yields no config rules (the base trigger_type rule fails first).
     */
    private function triggerConfigRules(): array
    {
        $type = WorkflowTriggerType::tryFrom((string) $this->input('trigger_type'));

        return match ($type) {
            WorkflowTriggerType::FORM_SUBMITTED => $this->formSubmittedRules(),
            WorkflowTriggerType::SCHEDULE => $this->scheduleRules(),
            default => [],
        };
    }

    /**
     * form_submitted: an optional single tenant-scoped form filter (null = any form), an
     * optional source subset (['manual','task']), and an optional anonymous-form flag.
     */
    private function formSubmittedRules(): array
    {
        return [
            'trigger_config' => ['nullable', 'array'],
            'trigger_config.form_id' => ['nullable', 'uuid', new ScopedExists(Form::class)],
            'trigger_config.source' => ['nullable', 'array'],
            'trigger_config.source.in' => ['nullable', 'array'],
            'trigger_config.source.in.*' => [Rule::in(['manual', 'task'])],
            'trigger_config.anonymous' => ['nullable', 'boolean'],
        ];
    }

    /**
     * schedule: a required v2 compositional cadence of shape { time, day?, month?, tz?, exclusions? }.
     * The base per-key rules are built by WorkflowScheduleRulesValidator (the ONE place this block is
     * validated, shared with the AI schedule-assist re-validation) under this request's
     * `trigger_config.schedule` prefix, so the accepted shape can never drift from what the compiler
     * understands. Cross-field invariants (mode-dependent required/forbidden fields, from<to windows,
     * the last_working_day time.mode=at restriction) are enforced in withValidator() via the same
     * shared validator, where the whole descriptor is visible.
     */
    private function scheduleRules(): array
    {
        return array_merge(
            ['trigger_config' => ['required', 'array']],
            app(WorkflowScheduleRulesValidator::class)->baseRules('trigger_config.schedule'),
        );
    }

    /**
     * Reject cross-type trigger_config: once trigger_type is a known enum value, any
     * trigger_config key outside that type's allow-list is nonsense (e.g. a form_id on a
     * schedule trigger, or a schedule block on a form_submitted trigger) and fails validation.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Step-config validation is independent of the trigger type — run it first so a
            // bad step surfaces even when the trigger_type is missing/unknown.
            $this->validateSteps($validator);

            $type = WorkflowTriggerType::tryFrom((string) $this->input('trigger_type'));

            if ($type === null) {
                return;
            }

            $this->validateConditions($validator, $type);

            $config = $this->input('trigger_config');

            if (!is_array($config)) {
                return;
            }

            foreach ($this->foreignConfigKeys($config) as $key) {
                $validator->errors()->add(
                    'trigger_config.' . $key,
                    'The trigger_config.' . $key . ' field is not allowed for this trigger type.'
                );
            }

            if ($type === WorkflowTriggerType::SCHEDULE) {
                $this->validateScheduleParams($validator, $config);
            }
        });
    }

    /**
     * Second-pass condition checks the per-clause rules can't express. The shared gates apply to
     * BOTH shapes, then validation forks by shape:
     *   - TRIGGER GATE: conditions are ONLY valid for form_submitted. A schedule run has no
     *     field source in the MVP, so any condition on a schedule trigger is rejected.
     *   - FORM REQUIRED: field conditions read one form's answer map, so when any condition is
     *     present `trigger_config.form_id` MUST be set (accepted decision Q2).
     *   - LEGACY LIST: `field` is a `fields.<id>` path (NOT checked against the form schema — a
     *     stale/wrong id is handled honestly at evaluation time), operator∈field_type's allow-list,
     *     and the value shape per type (see validateConditionClause).
     *   - NEW TREE: the recursive {logic, children} structure, its hard limits, source/type against
     *     the form's condition catalog, and the pipeline type-flow are delegated to
     *     WorkflowConditionTreeValidator (see validateConditionTree).
     */
    private function validateConditions(Validator $validator, WorkflowTriggerType $type): void
    {
        $conditions = $this->input('conditions');

        if (!is_array($conditions) || $conditions === []) {
            return;
        }

        if ($type !== WorkflowTriggerType::FORM_SUBMITTED) {
            $validator->errors()->add('conditions', 'Conditions are only supported for the form_submitted trigger.');

            return;
        }

        if ($this->input('trigger_config.form_id') === null) {
            $validator->errors()->add(
                'trigger_config.form_id',
                'A form must be selected (trigger_config.form_id) when conditions are present.',
            );
        }

        // NEW logic-TREE object → the shared recursive validator.
        if (!array_is_list($conditions)) {
            $this->validateConditionTree($validator, $conditions);

            return;
        }

        // LEGACY flat clause LIST.
        foreach ($conditions as $index => $condition) {
            $this->validateConditionClause($validator, (int) $index, is_array($condition) ? $condition : []);
        }
    }

    /**
     * Delegate the NEW logic-tree shape to WorkflowConditionTreeValidator, resolving the trigger form
     * so the validator can check each condition's source/source_type + option args against the form's
     * condition catalog. A missing/foreign form (already reported on trigger_config.form_id) resolves
     * to null, and the validator then validates every catalog-independent rule and skips the rest.
     *
     * @param  array<string, mixed>  $tree
     */
    private function validateConditionTree(Validator $validator, array $tree): void
    {
        $formId = $this->input('trigger_config.form_id');
        $form = is_string($formId) && $formId !== '' ? Form::find($formId) : null;

        app(WorkflowConditionTreeValidator::class)->validate($validator, $tree, 'conditions', $form);
    }

    /**
     * One clause: `field` prefix, operator∈type, and per-type value shape. Skips clauses whose
     * field_type/operator failed the base enum rules (their errors already surfaced).
     *
     * @param  array<string, mixed>  $condition
     */
    private function validateConditionClause(Validator $validator, int $index, array $condition): void
    {
        $prefix = 'conditions.' . $index;

        $field = $condition['field'] ?? null;

        if (is_string($field) && !str_starts_with($field, 'fields.')) {
            $validator->errors()->add($prefix . '.field', 'The condition field must be a fields.<id> path.');
        }

        $type = WorkflowVariableType::tryFrom((string) ($condition['field_type'] ?? ''));
        $operator = WorkflowConditionOperator::tryFrom((string) ($condition['operator'] ?? ''));

        if ($type === null || $operator === null) {
            return; // base enum rules already reported the bad type/operator
        }

        if (!in_array($operator, $type->operatorCases(), true)) {
            $validator->errors()->add(
                $prefix . '.operator',
                'The ' . $operator->value . ' operator is not valid for a ' . $type->value . ' field.',
            );

            return;
        }

        $this->validateConditionValue($validator, $prefix, $operator, $condition);
    }

    /**
     * The value shape for a clause under its operator. A value-less operator (is_true/is_false)
     * needs no value; an array operator (between/in) needs the right array shape; everything
     * else needs a present scalar type-checked against the field type via the operator.
     *
     * @param  array<string, mixed>  $condition
     */
    private function validateConditionValue(
        Validator $validator,
        string $prefix,
        WorkflowConditionOperator $operator,
        array $condition,
    ): void {
        if ($operator->isValueless()) {
            return; // is_true / is_false ignore the value
        }

        $value = $condition['value'] ?? null;
        $key = $prefix . '.value';

        if ($operator === WorkflowConditionOperator::BETWEEN) {
            if (!is_array($value) || count($value) !== 2) {
                $validator->errors()->add($key, 'A between condition requires a [from, to] array of two dates.');

                return;
            }

            foreach ($value as $date) {
                if (!$this->isParsableDate($date)) {
                    $validator->errors()->add($key, 'Both between values must be valid dates.');

                    return;
                }
            }

            return;
        }

        if ($operator === WorkflowConditionOperator::IN) {
            if (!is_array($value) || $value === [] || !$this->allStrings($value)) {
                $validator->errors()->add($key, 'An in condition requires a non-empty array of string values.');
            }

            return;
        }

        if ($value === null || $value === '') {
            $validator->errors()->add($key, 'A value is required for this operator.');

            return;
        }

        // Date operators (before/after/on): the single value must be a parseable date.
        if (in_array($operator, [
            WorkflowConditionOperator::BEFORE,
            WorkflowConditionOperator::AFTER,
            WorkflowConditionOperator::ON,
        ], true) && !$this->isParsableDate($value)) {
            $validator->errors()->add($key, 'The value must be a valid date.');

            return;
        }

        // Numeric operators: the value must be numeric.
        if (in_array($operator, [
            WorkflowConditionOperator::GT,
            WorkflowConditionOperator::GTE,
            WorkflowConditionOperator::LT,
            WorkflowConditionOperator::LTE,
            WorkflowConditionOperator::EQ,
            WorkflowConditionOperator::NEQ,
        ], true) && !is_numeric($value)) {
            $validator->errors()->add($key, 'The value must be a number.');
        }
    }

    /** Whether a value parses as a date (a string/number Carbon accepts). */
    private function isParsableDate(mixed $value): bool
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        try {
            Carbon::parse($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Whether every element of an array is a string. */
    private function allStrings(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                return false;
            }
        }

        return true;
    }

    // ---- per-step-type config write-validation -------------------------------

    /**
     * Validate every step's `config` against the rule set that belongs to its OWN type. Skips
     * a step whose type failed the base enum rule (its error already surfaced). For a known
     * type, each declared config field is type/shape-checked and any config key outside that
     * type's allow-list is rejected as foreign (mirroring the trigger_config foreign-key guard),
     * so a create_form_report field on a create_task step (or vice-versa) is a 422.
     */
    private function validateSteps(Validator $validator): void
    {
        $steps = $this->input('steps');

        if (!is_array($steps)) {
            return; // base rules already reported a missing/invalid steps array
        }

        // A value-or-variable pipeline is validated against the variable catalog (ref type/existence
        // + the source's option list). Building it costs one Form lookup + schema walk, so we do it
        // ONLY when some step actually carries such a pipeline — the common (pipeline-less) create
        // path stays exactly as cheap as before.
        [$catalog, $triggerType, $form] = $this->referenceCatalogContext($steps);

        $priorSteps = [];

        foreach ($steps as $index => $step) {
            $step = is_array($step) ? $step : [];
            $type = WorkflowStepType::tryFrom((string) ($step['type'] ?? ''));

            if ($type === null) {
                $priorSteps[] = $step; // keep the slot so later step-output scope stays aligned

                continue; // base steps.*.type enum rule already reported it
            }

            $config = is_array($step['config'] ?? null) ? $step['config'] : [];
            $prefix = 'steps.' . $index . '.config';

            // The references THIS step may target: trigger vars, form fields, and PRIOR steps' outputs.
            $refCtx = $catalog !== null
                ? ['index' => $catalog->referenceIndex($triggerType, $form, $priorSteps), 'fields_available' => $form !== null]
                : null;

            match ($type) {
                WorkflowStepType::CREATE_TASK => $this->validateCreateTaskConfig($validator, $prefix, $config, $refCtx),
                WorkflowStepType::CREATE_FORM_REPORT => $this->validateCreateFormReportConfig($validator, $prefix, $config, $refCtx),
            };

            $this->rejectForeignStepKeys($validator, $prefix, $config, $this->allowedStepKeys($type));
            $priorSteps[] = $step;
        }
    }

    /**
     * The catalog + resolved trigger form used to write-validate value-or-variable pipelines — or a
     * null catalog when NO step carries such a pipeline (so no work is done on the common path).
     *
     * @param  array<int, mixed>  $steps
     * @return array{0: ?WorkflowVariableCatalogService, 1: ?WorkflowTriggerType, 2: ?Form}
     */
    private function referenceCatalogContext(array $steps): array
    {
        if (!$this->stepsHaveValuePipelines($steps)) {
            return [null, null, null];
        }

        $triggerType = WorkflowTriggerType::tryFrom((string) $this->input('trigger_type'));
        $form = null;

        if ($triggerType === WorkflowTriggerType::FORM_SUBMITTED) {
            $formId = $this->input('trigger_config.form_id');
            $form = is_string($formId) && $formId !== '' ? Form::find($formId) : null;
        }

        return [app(WorkflowVariableCatalogService::class), $triggerType, $form];
    }

    /**
     * Whether any step's structured value-or-variable field carries a non-empty pipeline — the
     * trigger for building the (otherwise skipped) reference catalog.
     *
     * @param  array<int, mixed>  $steps
     */
    private function stepsHaveValuePipelines(array $steps): bool
    {
        foreach ($steps as $step) {
            $config = is_array($step) ? ($step['config'] ?? null) : null;

            if (!is_array($config)) {
                continue;
            }

            foreach (['priority', 'deadline', 'submissions_from', 'submissions_to'] as $field) {
                $value = $config[$field] ?? null;

                if (is_array($value) && ($value['kind'] ?? null) === 'variable' && !empty($value['pipeline'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * create_task config: title (required string), description (nullable string), priority
     * (nullable literal-in-TaskPriority OR the {kind,…} union), deadline (nullable date string
     * OR union), labels (nullable array of workspace Label uuids), assignee_type∈user|bot +
     * assignee_id uuid both-or-neither, form_id / approval_pipeline_id nullable scoped uuids.
     *
     * @param  array<string, mixed>  $config
     * @param  array{index: array<string, array{type: WorkflowVariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     */
    private function validateCreateTaskConfig(Validator $validator, string $prefix, array $config, ?array $refCtx = null): void
    {
        $title = $config['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            $validator->errors()->add($prefix . '.title', 'The create_task step requires a non-empty title.');
        }

        if (($config['description'] ?? null) !== null && !is_string($config['description'])) {
            $validator->errors()->add($prefix . '.description', 'The description must be a string.');
        }

        // priority is a CHOICE field: a literal must be a TaskPriority, and a variable must run a
        // pipeline that maps the value into the priority option set (targetOptions = TaskPriority::ids()).
        // The target options are injected here per-field — they are NOT part of the static op descriptor.
        $this->validateUnionOrLiteral(
            $validator,
            $prefix . '.priority',
            $config['priority'] ?? null,
            fn (mixed $value) => is_scalar($value) && TaskPriority::tryFrom((string) $value) !== null,
            'a valid priority (' . implode(', ', TaskPriority::ids()) . ')',
            $refCtx,
            [WorkflowVariableType::ENUM],
            TaskPriority::ids(),
        );

        $this->validateUnionOrLiteral(
            $validator,
            $prefix . '.deadline',
            $config['deadline'] ?? null,
            fn (mixed $value) => $this->isParsableDate($value),
            'a valid date',
            $refCtx,
            [WorkflowVariableType::DATE],
        );

        $this->validateLabelIds($validator, $prefix, $config['labels'] ?? null);
        $this->validateAssignee($validator, $prefix, $config);
        $this->validateScopedUuid($validator, $prefix . '.form_id', $config['form_id'] ?? null, Form::class);
        $this->validateScopedUuid($validator, $prefix . '.approval_pipeline_id', $config['approval_pipeline_id'] ?? null, ApprovalPipeline::class);
    }

    /**
     * create_form_report config: form_id (required scoped Form uuid), name (required string),
     * guidelines (nullable string), sources (nullable subset of [task,form]), submissions_from
     * / submissions_to (nullable date string OR union).
     *
     * @param  array<string, mixed>  $config
     * @param  array{index: array<string, array{type: WorkflowVariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     */
    private function validateCreateFormReportConfig(Validator $validator, string $prefix, array $config, ?array $refCtx = null): void
    {
        $formId = $config['form_id'] ?? null;
        if (!is_string($formId) || $formId === '') {
            $validator->errors()->add($prefix . '.form_id', 'The create_form_report step requires a form_id.');
        } else {
            $this->validateScopedUuid($validator, $prefix . '.form_id', $formId, Form::class);
        }

        $name = $config['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            $validator->errors()->add($prefix . '.name', 'The create_form_report step requires a non-empty name.');
        }

        if (($config['guidelines'] ?? null) !== null && !is_string($config['guidelines'])) {
            $validator->errors()->add($prefix . '.guidelines', 'The guidelines must be a string.');
        }

        $this->validateSources($validator, $prefix, $config['sources'] ?? null);

        foreach (['submissions_from', 'submissions_to'] as $key) {
            $this->validateUnionOrLiteral(
                $validator,
                $prefix . '.' . $key,
                $config[$key] ?? null,
                fn (mixed $value) => $this->isParsableDate($value),
                'a valid date',
                $refCtx,
                [WorkflowVariableType::DATE],
            );
        }
    }

    /**
     * A structured field that is EITHER a bare literal OR the {kind: literal|variable, …}
     * union. A null/absent value is fine (optional). A literal value (bare or {kind:literal})
     * is checked by $literalValid. A {kind:variable} value must carry a well-formed ref
     * (source∈trigger|steps, path string, type∈WorkflowVariableType) — its runtime value can't
     * be known at write time, so only the ref SHAPE is validated.
     *
     * @param  callable(mixed): bool  $literalValid  predicate the resolved literal must satisfy
     * @param  array{index: array<string, array{type: WorkflowVariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     * @param  array<int, WorkflowVariableType>  $allowedTerminals  the field's accepted pipeline output types
     * @param  array<int, string>|null  $targetOptions  a CHOICE field's allowed option VALUES (null for a plain value field)
     */
    private function validateUnionOrLiteral(
        Validator $validator,
        string $key,
        mixed $field,
        callable $literalValid,
        string $expectation,
        ?array $refCtx = null,
        array $allowedTerminals = [],
        ?array $targetOptions = null,
    ): void {
        if ($field === null) {
            return;
        }

        // Union shape.
        if (is_array($field) && isset($field['kind'])) {
            $kind = $field['kind'];

            if ($kind === 'variable') {
                $this->validateVariableRef($validator, $key, $field['ref'] ?? null);
                $this->validateVariablePipeline($validator, $key, $field, $refCtx, $allowedTerminals, $targetOptions);

                return;
            }

            if ($kind === 'literal') {
                if (!$literalValid($field['value'] ?? null)) {
                    $validator->errors()->add($key, 'The ' . $key . ' literal must be ' . $expectation . '.');
                }

                return;
            }

            $validator->errors()->add($key, 'The ' . $key . ' kind must be literal or variable.');

            return;
        }

        // Bare literal (not the union) — a scalar in a structured slot is treated as a literal.
        if (is_array($field) || !$literalValid($field)) {
            $validator->errors()->add($key, 'The ' . $key . ' must be ' . $expectation . ' or a variable reference.');
        }
    }

    /**
     * A {kind:variable} ref: source∈trigger|steps, a non-empty string path, and a type in the
     * WorkflowVariableType set. This is the ONLY thing checkable for a variable at write time
     * (its resolved value is a run-time property of the trigger/step context).
     */
    private function validateVariableRef(Validator $validator, string $key, mixed $ref): void
    {
        if (!is_array($ref)) {
            $validator->errors()->add($key, 'A variable reference requires a ref object.');

            return;
        }

        $source = $ref['source'] ?? null;
        if (!in_array($source, ['trigger', 'steps'], true)) {
            $validator->errors()->add($key . '.ref.source', 'The reference source must be trigger or steps.');
        }

        $path = $ref['path'] ?? null;
        if (!is_string($path) || trim($path) === '') {
            $validator->errors()->add($key . '.ref.path', 'The reference path is required.');
        }

        $type = $ref['type'] ?? null;
        if (!in_array($type, WorkflowVariableType::ids(), true)) {
            $validator->errors()->add($key . '.ref.type', 'The reference type is invalid.');
        }
    }

    /**
     * Validate a value-or-variable field's OPTIONAL pipeline (SB1): type-flow from the ref's declared
     * type through the ops to the target field's accepted type ($allowedTerminals), with args checked
     * exactly like a condition pipeline. The source variable's option list (for sourceOption/sourceMap
     * args) and its catalog type are resolved from $refCtx; errors land under indexed
     * `<key>.pipeline.M...` keys. An absent pipeline / invalid ref.type is a no-op (already handled).
     *
     * CHOICE FIELDS ($targetOptions !== null, e.g. priority → TaskPriority::ids()): an identity ref
     * (no/empty pipeline) is REJECTED — a choice field can only be set by a mapping pipeline that ends
     * in a choice-producing op — and $targetOptions is threaded to the value-pipeline validator so the
     * choice args' option values are checked against the destination field's own option set.
     *
     * @param  array<string, mixed>  $field
     * @param  array{index: array<string, array{type: WorkflowVariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     * @param  array<int, WorkflowVariableType>  $allowedTerminals
     * @param  array<int, string>|null  $targetOptions
     */
    private function validateVariablePipeline(Validator $validator, string $key, array $field, ?array $refCtx, array $allowedTerminals, ?array $targetOptions = null): void
    {
        $pipeline = $field['pipeline'] ?? null;

        // An identity/empty pipeline is fine for a plain value field, but a CHOICE field must map the
        // value into its option set — so a choice field with no pipeline is a granular error.
        if ($pipeline === null || $pipeline === []) {
            if ($targetOptions !== null) {
                $validator->errors()->add($key . '.pipeline', 'A choice field requires a mapping pipeline.');
            }

            return;
        }

        if (!is_array($pipeline)) {
            $validator->errors()->add($key . '.pipeline', 'The pipeline must be an array of operations.');

            return;
        }

        if ($allowedTerminals === []) {
            return; // no target types means no field opted in
        }

        $ref = is_array($field['ref'] ?? null) ? $field['ref'] : [];
        $sourceType = WorkflowVariableType::tryFrom((string) ($ref['type'] ?? ''));

        if ($sourceType === null) {
            return; // validateVariableRef already reported the bad ref.type
        }

        $sourceEnumOptions = $this->resolveRefEnumOptions($validator, $key, $ref, $refCtx);

        app(WorkflowConditionTreeValidator::class)->validateValuePipeline(
            $validator,
            $pipeline,
            $key . '.pipeline',
            $sourceType,
            $allowedTerminals,
            $sourceEnumOptions,
            $targetOptions,
        );
    }

    /**
     * Resolve the source variable's option list for the pipeline's option-arg checks, and — as a side
     * effect — reject a ref whose type disagrees with the catalog or that points at an unknown
     * variable. Form-field membership is skipped when the trigger form is unresolved (already
     * reported); step-output and trigger-system refs are always checked (so a step can only target a
     * real, earlier reference).
     *
     * @param  array<string, mixed>  $ref
     * @param  array{index: array<string, array{type: WorkflowVariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     * @return array<int, string>|null
     */
    private function resolveRefEnumOptions(Validator $validator, string $key, array $ref, ?array $refCtx): ?array
    {
        if ($refCtx === null) {
            return null;
        }

        $fullPath = $this->refFullPath($ref);

        if ($fullPath === null) {
            return null; // validateVariableRef already reported the missing path
        }

        if (array_key_exists($fullPath, $refCtx['index'])) {
            $descriptor = $refCtx['index'][$fullPath];
            $refType = $ref['type'] ?? null;

            if (is_string($refType) && $descriptor['type']->value !== $refType) {
                $validator->errors()->add($key . '.ref.type', 'The reference type does not match the variable type in the catalog.');
            }

            return $descriptor['enumOptions'];
        }

        // Unknown reference. A form field is only skippable when the form itself is unresolved.
        if (str_starts_with($fullPath, 'trigger.fields.') && !$refCtx['fields_available']) {
            return null;
        }

        $validator->errors()->add($key . '.ref.path', 'The reference is not a known variable for this step.');

        return null;
    }

    /**
     * The full dotted path a ref resolves against (`trigger` + `fields.abc` → `trigger.fields.abc`),
     * mirroring the resolver — a path already carrying its root is used as-is.
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
     * labels: nullable array of workspace-scoped Label uuids. Each id is checked through
     * ScopedExists so a foreign-workspace label is rejected at write time.
     */
    private function validateLabelIds(Validator $validator, string $prefix, mixed $labels): void
    {
        if ($labels === null) {
            return;
        }

        if (!is_array($labels)) {
            $validator->errors()->add($prefix . '.labels', 'The labels must be an array of ids.');

            return;
        }

        foreach ($labels as $i => $id) {
            $this->validateScopedUuid($validator, $prefix . '.labels.' . $i, $id, Label::class);
        }
    }

    /**
     * assignee_type + assignee_id: both present or both absent. When present, assignee_type ∈
     * user|bot and assignee_id is a uuid (the polymorphic target's existence is intentionally
     * NOT scope-checked here — mirroring how the trigger form_id is the only scoped ref and the
     * step tolerates a stale assignee; the id is a plain uuid contract for the FE).
     *
     * @param  array<string, mixed>  $config
     */
    private function validateAssignee(Validator $validator, string $prefix, array $config): void
    {
        $type = $config['assignee_type'] ?? null;
        $id = $config['assignee_id'] ?? null;

        $hasType = $type !== null && $type !== '';
        $hasId = $id !== null && $id !== '';

        if ($hasType !== $hasId) {
            $validator->errors()->add($prefix . '.assignee_id', 'assignee_type and assignee_id must be provided together.');
        }

        if ($hasType && !in_array($type, ['user', 'bot'], true)) {
            $validator->errors()->add($prefix . '.assignee_type', 'The assignee_type must be user or bot.');
        }

        if ($hasId && !$this->isUuid($id)) {
            $validator->errors()->add($prefix . '.assignee_id', 'The assignee_id must be a uuid.');
        }
    }

    /**
     * sources: nullable array whose values are a subset of [task, form].
     */
    private function validateSources(Validator $validator, string $prefix, mixed $sources): void
    {
        if ($sources === null) {
            return;
        }

        if (!is_array($sources)) {
            $validator->errors()->add($prefix . '.sources', 'The sources must be an array.');

            return;
        }

        foreach ($sources as $i => $source) {
            if (!in_array($source, ['task', 'form'], true)) {
                $validator->errors()->add($prefix . '.sources.' . $i, 'Each source must be task or form.');
            }
        }
    }

    /**
     * A nullable scoped-uuid config value: null passes, otherwise it must be a uuid that exists
     * in the active workspace for $modelClass (via ScopedExists).
     *
     * @param  class-string  $modelClass
     */
    private function validateScopedUuid(Validator $validator, string $key, mixed $value, string $modelClass): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!$this->isUuid($value)) {
            $validator->errors()->add($key, 'The ' . $key . ' must be a uuid.');

            return;
        }

        (new ScopedExists($modelClass))->validate($key, $value, function (string $message) use ($validator, $key) {
            $validator->errors()->add($key, $message);
        });
    }

    /**
     * The config keys a step type accepts. A key outside this list is rejected as foreign
     * (mirroring trigger_config's foreign-key guard). Derived per type so the FE/AI contract
     * lives in one place.
     *
     * @return array<int, string>
     */
    private function allowedStepKeys(WorkflowStepType $type): array
    {
        return match ($type) {
            WorkflowStepType::CREATE_TASK => [
                'title', 'description', 'priority', 'deadline', 'labels',
                'assignee_type', 'assignee_id', 'form_id', 'approval_pipeline_id',
            ],
            WorkflowStepType::CREATE_FORM_REPORT => [
                'form_id', 'name', 'guidelines', 'sources', 'submissions_from', 'submissions_to',
            ],
        };
    }

    /**
     * Reject any TOP-LEVEL config key not in the type's allow-list. Only the shallowest key is
     * checked (the union/array internals of an allowed key are validated by their own rules), so
     * `priority.kind` is fine but a whole foreign `guidelines` on a create_task step is a 422.
     *
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $allowed
     */
    private function rejectForeignStepKeys(Validator $validator, string $prefix, array $config, array $allowed): void
    {
        foreach (array_keys($config) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $validator->errors()->add(
                    $prefix . '.' . $key,
                    'The ' . $key . ' field is not allowed for this step type.',
                );
            }
        }
    }

    /** Whether a value is a well-formed uuid string. */
    private function isUuid(mixed $value): bool
    {
        return is_string($value) && \Illuminate\Support\Str::isUuid($value);
    }

    /**
     * Second-pass schedule checks the per-key rules cannot express on their own — mode-dependent
     * required/foreign fields, from<to windows and the last_working_day restriction — delegated to
     * the shared WorkflowScheduleRulesValidator under this request's `trigger_config.schedule`
     * prefix, so the human write path and the AI schedule-assist re-validation run identical logic
     * with identical error keys/messages.
     *
     * @param  array<string, mixed>  $config
     */
    private function validateScheduleParams(Validator $validator, array $config): void
    {
        app(WorkflowScheduleRulesValidator::class)->secondPass(
            $validator,
            is_array($config['schedule'] ?? null) ? $config['schedule'] : [],
            'trigger_config.schedule',
        );
    }

    /**
     * Dot-notation trigger_config keys present in the payload that do NOT belong to the
     * current trigger type. The allow-list is derived from the type's own rule keys so it
     * stays in one place (triggerConfigRules).
     *
     * @return array<int, string>
     */
    private function foreignConfigKeys(array $config): array
    {
        $allowed = collect(array_keys($this->triggerConfigRules()))
            ->filter(fn (string $rule) => str_starts_with($rule, 'trigger_config.'))
            ->map(fn (string $rule) => substr($rule, strlen('trigger_config.')))
            ->reject(fn (string $key) => str_contains($key, '*'))
            ->values()
            ->all();

        return collect(array_keys(Arr::dot($config)))
            ->reject(fn (string $key) => $this->keyIsAllowed($key, $allowed))
            // Report the shallowest foreign segment (the whole offending object/array),
            // not each leaf/index — so `source.in.0` surfaces once as `source.in` and
            // `schedule.time` surfaces once as `schedule`.
            ->map(fn (string $key) => $this->shallowestForeignKey($key, $allowed))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Walk a foreign dot-key from the root and return the first prefix that is NOT an
     * ancestor of an allowed key — i.e. the topmost segment that has no business being in
     * this trigger type's config. Numeric indices are skipped so arrays report by name.
     *
     * @param  array<int, string>  $allowed
     */
    private function shallowestForeignKey(string $key, array $allowed): string
    {
        $prefix = [];

        foreach (explode('.', $key) as $segment) {
            $prefix[] = $segment;
            $candidate = implode('.', $prefix);

            if (is_numeric($segment)) {
                continue;
            }

            if (!$this->keyIsAllowed($candidate, $allowed)) {
                return $candidate;
            }
        }

        return $key;
    }

    /**
     * A payload key is allowed when it equals an allow-listed key or is an ANCESTOR of one
     * (a container object on the path to an allowed leaf — e.g. `source` when `source.in` is
     * allowed). It is NOT allowed merely for being a descendant of an allowed container:
     * trigger_config has no free-form maps, so every leaf must be explicitly allow-listed and
     * a foreign leaf (e.g. a `schedule` block on a form_submitted trigger) is rejected.
     * Numeric array indices are stripped so `source.in.0` normalizes to `source.in`.
     *
     * @param  array<int, string>  $allowed
     */
    private function keyIsAllowed(string $key, array $allowed): bool
    {
        $normalized = collect(explode('.', $key))
            ->reject(fn (string $segment) => is_numeric($segment))
            ->implode('.');

        foreach ($allowed as $allow) {
            if ($normalized === $allow
                || str_starts_with($allow, $normalized . '.')) {
                return true;
            }
        }

        return false;
    }
}
