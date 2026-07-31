<?php

namespace App\Modules\Workflows\Http\Requests;

use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Disk\Models\Folder;
use App\Modules\Forms\Models\Form;
use App\Modules\Generator\Contracts\SessionAuthorIdentityResolver;
use App\Modules\Generator\Models\Template;
use App\Modules\Labels\Models\Label;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\PipelineValidator;
use App\Modules\Variables\Services\VariableResolver;
use App\Modules\Workflows\Enums\WorkflowConditionOperator;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Services\WorkflowConditionTreeValidator;
use App\Modules\Workflows\Services\WorkflowScheduleRulesValidator;
use App\Modules\Workflows\Services\WorkflowVariableCatalogService;
use App\Rules\ScopedExists;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
    /** How many `generate_content` steps one workflow may contain — see validateGenerateContentBudget(). */
    private const GENERATE_CONTENT_MAX = 2;

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
            'conditions.*.field_type' => ['required', Rule::enum(VariableType::class)],
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
     * ARGUMENT VARIABLES (B6): a condition pipeline's operation arguments may reference a variable, so
     * the tree is validated against a REFERENCE INDEX built exactly like a step's — except with **no
     * prior steps**. That is the point, not an omission: the gate runs BEFORE any step executes, so
     * `steps.*` must never be referenceable in a condition (an attempt is a granular 422), while the
     * trigger's own variables and the workspace globals are. `fields_available` mirrors the step path:
     * a `trigger.fields.*` ref is only skippable when the form itself is unresolved.
     *
     * @param  array<string, mixed>  $tree
     */
    private function validateConditionTree(Validator $validator, array $tree): void
    {
        $formId = $this->input('trigger_config.form_id');
        $form = is_string($formId) && $formId !== '' ? Form::find($formId) : null;
        $catalog = app(WorkflowVariableCatalogService::class);

        $refCtx = [
            'index' => $catalog->referenceIndex(WorkflowTriggerType::FORM_SUBMITTED, $form, priorSteps: []),
            'fields_available' => $form !== null,
            // The reference-source roots a variable arg may name — supplied to the Variables pipeline
            // validator so it needs no back-dependency on VariableResolver (see ADR-0021).
            'sources' => VariableResolver::ROOTS,
            // The workspace's custom functions, so a condition pipeline referencing a `fn:<uuid>` op
            // type-checks (the pipeline validator resolves it) — a type-incompatible function is a 422.
            'functions' => $catalog->customFunctionOperations(),
        ];

        app(WorkflowConditionTreeValidator::class)->validate($validator, $tree, 'conditions', $form, $refCtx);
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

        $type = VariableType::tryFrom((string) ($condition['field_type'] ?? ''));
        $operator = WorkflowConditionOperator::tryFrom((string) ($condition['operator'] ?? ''));

        if ($type === null || $operator === null) {
            return; // base enum rules already reported the bad type/operator
        }

        if (!in_array($operator->value, $type->operators(), true)) {
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

        // The workspace's custom functions (workspace-constant — fetched once), so a step's value
        // pipeline referencing a `fn:<uuid>` op type-checks; a type-incompatible function is a 422.
        $functions = $catalog?->customFunctionOperations() ?? [];

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
                ? ['index' => $catalog->referenceIndex($triggerType, $form, $priorSteps), 'fields_available' => $form !== null, 'sources' => VariableResolver::ROOTS, 'functions' => $functions]
                : null;

            match ($type) {
                WorkflowStepType::CREATE_TASK => $this->validateCreateTaskConfig($validator, $prefix, $config, $refCtx),
                WorkflowStepType::CREATE_FORM_REPORT => $this->validateCreateFormReportConfig($validator, $prefix, $config, $refCtx),
                WorkflowStepType::GENERATE_CONTENT => $this->validateGenerateContentConfig($validator, $prefix, $config, $refCtx),
            };

            $this->rejectForeignStepKeys($validator, $prefix, $config, $this->allowedStepKeys($type));
            $priorSteps[] = $step;
        }

        $this->validateGenerateContentBudget($validator, $steps);
    }

    /**
     * At most {@see GENERATE_CONTENT_MAX} generate_content steps per workflow. Each one is a WHOLE AI
     * generation run (text + images), by far the most expensive thing a workflow can do, and it PARKS the
     * run while it settles — so a definition that chains several multiplies both the spend and the wall
     * clock of a single trigger. The cap is a definition-time budget guard, deliberately in the same spirit
     * as `workflows.max_runs_per_month`; the third and later step is reported individually so the author
     * sees which one to remove.
     *
     * @param  array<int, mixed>  $steps
     */
    private function validateGenerateContentBudget(Validator $validator, array $steps): void
    {
        $seen = 0;

        foreach ($steps as $index => $step) {
            $step = is_array($step) ? $step : [];

            if (($step['type'] ?? null) !== WorkflowStepType::GENERATE_CONTENT->value) {
                continue;
            }

            if (++$seen > self::GENERATE_CONTENT_MAX) {
                $validator->errors()->add(
                    'steps.' . $index . '.type',
                    'A workflow may contain at most ' . self::GENERATE_CONTENT_MAX . ' generate_content steps.',
                );
            }
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
                if ($this->isVariablePipeline($config[$field] ?? null)) {
                    return true;
                }
            }

            // generate_content's slot map is a FREE-FORM set of unions (one per template slot), so it is
            // scanned by value rather than by a fixed key list.
            foreach (is_array($config['slots'] ?? null) ? $config['slots'] : [] as $value) {
                if ($this->isVariablePipeline($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whether a structured field is a variable union carrying a non-empty pipeline. */
    private function isVariablePipeline(mixed $value): bool
    {
        return is_array($value) && ($value['kind'] ?? null) === 'variable' && !empty($value['pipeline']);
    }

    /**
     * create_task config: title (required string), description (nullable string), priority
     * (nullable literal-in-TaskPriority OR the {kind,…} union), deadline (nullable date string
     * OR union), labels (nullable array of workspace Label uuids), assignee_type∈user|bot +
     * assignee_id uuid both-or-neither, form_id / approval_pipeline_id nullable scoped uuids.
     *
     * @param  array<string, mixed>  $config
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
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
            [VariableType::ENUM],
            TaskPriority::ids(),
        );

        $this->validateUnionOrLiteral(
            $validator,
            $prefix . '.deadline',
            $config['deadline'] ?? null,
            fn (mixed $value) => $this->isParsableDate($value),
            'a valid date',
            $refCtx,
            [VariableType::DATE],
        );

        $this->validateLabelIds($validator, $prefix, $config['labels'] ?? null);
        $this->validateAssignee($validator, $prefix, $config);
        $this->validateScopedUuid($validator, $prefix . '.form_id', $config['form_id'] ?? null, Form::class);
        $this->validateScopedUuid($validator, $prefix . '.approval_pipeline_id', $config['approval_pipeline_id'] ?? null, ApprovalPipeline::class);

        // attachments is a FILE union: a literal is a file uuid (or a list of them — a Disk pick),
        // a variable must resolve to a FILE terminal (e.g. a submission's file field). The
        // concrete files are re-resolved and copied at run time, so this only pins the SHAPE;
        // an unresolvable id is skipped there rather than 422'd here.
        $this->validateUnionOrLiteral(
            $validator,
            $prefix . '.attachments',
            $config['attachments'] ?? null,
            fn (mixed $value) => $this->isFileUuidLiteral($value),
            'a file id (or a list of file ids)',
            $refCtx,
            [VariableType::FILE],
        );
    }

    /**
     * A create_task attachments literal: a single file uuid, or a list of them (single-file is
     * the common case, but a list degrades cleanly). An empty list is allowed (no attachment).
     */
    private function isFileUuidLiteral(mixed $value): bool
    {
        $ids = is_array($value) ? $value : [$value];

        foreach ($ids as $id) {
            if (!is_string($id) || !Str::isUuid($id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * create_form_report config: form_id (required scoped Form uuid), name (required string),
     * guidelines (nullable string), sources (nullable subset of [task,form]), submissions_from
     * / submissions_to (nullable date string OR union).
     *
     * @param  array<string, mixed>  $config
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
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
                [VariableType::DATE],
            );
        }
    }

    /**
     * generate_content config: template_id (required, workspace-scoped Template uuid), slots (a map of the
     * template's DECLARED slot names to a literal or a variable union), folder_id (nullable, workspace-
     * scoped Disk Folder uuid — where the produced images are exported), name (nullable string, the
     * session's display name; defaults to the template's), bot_id (nullable, workspace-scoped author uuid —
     * the bot the generated session is delegated to; see {@see validateAuthorId}).
     *
     * The template is RESOLVED here so the slot map can be checked against the recipe the author actually
     * picked. Two granular, per-slot rules:
     *   - every NON-NULLABLE declared slot must be mapped (a descriptor has no `required` key — required is
     *     `nullable !== true`), because generating from a half-filled recipe spends AI money on content
     *     nobody asked for, and the step hard-fails at run time anyway. Better a 422 at authoring time.
     *   - a mapped name the template does NOT declare is rejected — almost always a typo, and the same typo
     *     is what leaves the intended slot unmapped, so reporting both points straight at the mistake.
     *   - a COMPOSITE slot a workflow cannot supply is rejected outright ({@see isUnsuppliableSlot}), because
     *     the mapped value would be silently discarded at run time.
     * All three land under `steps.<i>.config.slots.<name>` so the editor can highlight the exact row.
     *
     * A slot's VALUE is only shape-checked ({@see validateSlotValue}): its literal is re-validated against
     * the slot DESCRIPTOR at run time by the shared ConstantTypeValidator (the same authority a human fill
     * uses), so duplicating per-descriptor literal rules here would be a second, driftable implementation.
     *
     * @param  array<string, mixed>  $config
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     */
    private function validateGenerateContentConfig(Validator $validator, string $prefix, array $config, ?array $refCtx = null): void
    {
        $templateId = $config['template_id'] ?? null;
        $template = null;

        if (!is_string($templateId) || $templateId === '') {
            $validator->errors()->add($prefix . '.template_id', 'The generate_content step requires a template_id.');
        } else {
            $this->validateScopedUuid($validator, $prefix . '.template_id', $templateId, Template::class);
            // Tenant-scoped, so a foreign id resolves to null here exactly as ScopedExists rejects it.
            $template = $this->isUuid($templateId) ? Template::find($templateId) : null;
        }

        if (($config['name'] ?? null) !== null && !is_string($config['name'])) {
            $validator->errors()->add($prefix . '.name', 'The name must be a string.');
        }

        $this->validateScopedUuid($validator, $prefix . '.folder_id', $config['folder_id'] ?? null, Folder::class);
        $this->validateAuthorId($validator, $prefix . '.bot_id', $config['bot_id'] ?? null);

        $slots = $config['slots'] ?? null;

        if ($slots !== null && !is_array($slots)) {
            $validator->errors()->add($prefix . '.slots', 'The slots must be a map of slot name to value.');

            return;
        }

        if ($template === null) {
            return; // the template error is already reported, and the per-slot rules need its declarations
        }

        $this->validateTemplateSlotMapping($validator, $prefix, $template, is_array($slots) ? $slots : [], $refCtx);
    }

    /**
     * The generate_content step's optional AUTHOR: the bot whose voice and face the produced session is
     * generated in. Null/absent means "no author" (the pre-existing behavior, byte for byte).
     *
     * WHY THIS IS NOT A `ScopedExists` RULE — the one place in this class that departs from it. Every other
     * scoped reference here names its model directly, but an AUTHOR belongs to the Bot module, and Workflows
     * may not name a peer module (pinned, module-wide, by
     * {@see \Tests\Feature\WorkflowsGeneratorBoundaryTest::test_no_workflows_file_ever_names_bot}). So the
     * check is delegated to the same inverted seam the STEP itself will use at run time — the Generator's
     * {@see SessionAuthorIdentityResolver} — which applies the identical workspace predicate a
     * `ScopedExists` would, from the module that is allowed to know what an author is.
     *
     * Asking the RUN-TIME authority is also what makes the two verdicts impossible to drift apart: a save
     * cannot accept an id the run would then refuse (a definition that always fails is worse than a 422),
     * and it cannot reject one the run would have accepted.
     *
     * IT ASKS THE EXISTENCE QUESTION, NOT THE IDENTITY ONE. `knowsAuthor` is that seam's cheap probe — the
     * same id + workspace predicate, and nothing after it. This used to call `identityFor`, which COMPOSES
     * the whole author (voice, frozen look, and the likeness bytes read out of Storage) and then folds every
     * failure, infrastructure included, into one `null`. That is the right posture for a RUN — it refuses to
     * publish under an author it could not assemble — but as a write-side check it made a save do two things
     * it must not: pay for bytes it has no use for (the run composes its own, later, from the world as it is
     * THEN), and, when that composition failed for any reason, tell the author "this bot is not available in
     * this workspace" about a bot that was perfectly fine. A save now only ever says that when it is true;
     * a broken database surfaces as a broken database, not as a rejected author.
     *
     * THE WORKSPACE IS PINNED EXPLICITLY, and it is null in own-database mode ON PURPOSE. The seam's
     * predicate is a `workspace_id` column that tenant tables DO NOT HAVE — there the dedicated connection
     * is the boundary — so handing it the ambient workspace id in that mode would query a column that does
     * not exist. `isOwn()` is the same mode test `TenantAware` routes connections by.
     */
    private function validateAuthorId(Validator $validator, string $key, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $tenant = app(TenantContext::class);

        if (!$this->isUuid($value)
            || !app(SessionAuthorIdentityResolver::class)->knowsAuthor(
                (string) $value,
                $tenant->isOwn() ? null : $tenant->id(),
            )) {
            $validator->errors()->add($key, __('workflows.steps.generate_content.bot_invalid'));
        }
    }

    /**
     * The per-slot half of generate_content validation (see validateGenerateContentConfig for the rules).
     *
     * @param  array<string, mixed>  $mapped
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     */
    private function validateTemplateSlotMapping(Validator $validator, string $prefix, Template $template, array $mapped, ?array $refCtx): void
    {
        $declared = [];

        foreach (is_array($template->slots) ? $template->slots : [] as $slot) {
            if (is_array($slot) && is_string($slot['name'] ?? null) && is_array($slot['descriptor'] ?? null)) {
                $declared[$slot['name']] = $slot['descriptor'];
            }
        }

        foreach (array_keys($mapped) as $name) {
            if (!array_key_exists((string) $name, $declared)) {
                $validator->errors()->add(
                    $prefix . '.slots.' . $name,
                    'The ' . $name . ' slot is not declared by the chosen template.',
                );
            }
        }

        foreach ($declared as $name => $descriptor) {
            $key = $prefix . '.slots.' . $name;
            $isMapped = array_key_exists($name, $mapped);
            $value = $isMapped ? $mapped[$name] : null;
            $isRequired = ($descriptor['nullable'] ?? false) !== true;

            if ($this->isUnsuppliableSlot($descriptor)) {
                // A REQUIRED one is an error whether it is mapped or not — the recipe cannot be driven by a
                // workflow at all, so say so instead of accepting a step that would fail every single run.
                // A NULLABLE one is only an error when the author actually mapped it: leaving it out is a
                // legitimate "generate with this slot empty".
                if ($isRequired) {
                    $validator->errors()->add(
                        $key,
                        'The required ' . $name . ' slot of the chosen template is a composite value (an object, a list of objects, or a list of files) that a workflow step cannot supply yet, so this template cannot be driven by a workflow.',
                    );
                } elseif ($isMapped) {
                    $validator->errors()->add(
                        $key,
                        'The ' . $name . ' slot of the chosen template is a composite value (an object, a list of objects, or a list of files) that a workflow step cannot supply yet, so it cannot be mapped. Leave it unmapped to generate with it empty.',
                    );
                }

                continue;
            }

            if ($isRequired && (!$isMapped || $value === null || $value === '')) {
                $validator->errors()->add($key, 'The required ' . $name . ' slot of the chosen template must be mapped.');

                continue;
            }

            if ($isMapped) {
                $this->validateSlotValue($validator, $key, $value, $descriptor, $refCtx);
            }
        }
    }

    /**
     * Whether a DECLARED slot is a composite the `generate_content` step cannot supply — refused at
     * AUTHORING time rather than discovered at run time.
     *
     * THREE shapes, two distinct reasons, one outcome:
     *   - `object` (any shape). The step resolves every mapped value through the shared resolver at the
     *     slot's own type, and that resolver has NO object coercion — an object literal, bare or wrapped in
     *     a `{kind:'literal'}` union, resolves to NULL. The value the author wrote can therefore never reach
     *     the session.
     *   - `array<object>` and `array<file>`. Deferred composites that
     *     {@see \App\Modules\Generator\Enums\SlotScopePolicy::accepts} refuses outright, so the fill drops
     *     them as out-of-scope.
     * Either way the mapped value is discarded, and the two ways that surfaced were both bad: a REQUIRED
     * slot saved cleanly and then hard-failed EVERY run (leaving an orphan draft each time), and a NULLABLE
     * one silently generated with an empty slot — the author's mapping quietly thrown away.
     *
     * A plain SCALAR `file` slot is deliberately NOT here: it is the owner-approved D4 automation path, it
     * resolves, and it works. Same for every scalar and its `array` form.
     *
     * @param  array<string, mixed>  $descriptor
     */
    private function isUnsuppliableSlot(array $descriptor): bool
    {
        $base = $descriptor['base'] ?? null;

        if ($base === VariableType::OBJECT->value) {
            return true;
        }

        return $base === VariableType::FILE->value && ($descriptor['array'] ?? false) === true;
    }

    /**
     * ONE mapped slot value. A `{kind:'variable'}` union gets the shared reference + pipeline checks, typed
     * by the SLOT's own accepted terminals ({@see slotPipelineTerminals}), so a pipeline that cannot end in
     * one of them is a 422. A literal (bare or `{kind:'literal'}`) is intentionally NOT type-checked here —
     * see validateGenerateContentConfig.
     *
     * @param  array<string, mixed>  $descriptor
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     */
    private function validateSlotValue(Validator $validator, string $key, mixed $value, array $descriptor, ?array $refCtx): void
    {
        if (!is_array($value) || !isset($value['kind'])) {
            return; // a bare literal (a scalar, or a list for an array slot)
        }

        if ($value['kind'] === 'variable') {
            $this->validateVariableRef($validator, $key, $value['ref'] ?? null);
            $this->validateVariablePipeline($validator, $key, $value, $refCtx, $this->slotPipelineTerminals($descriptor));

            return;
        }

        if ($value['kind'] !== 'literal') {
            $validator->errors()->add($key, 'The ' . $key . ' kind must be literal or variable.');
        }
    }

    /**
     * The pipeline TERMINALS a slot accepts, recovered from its stored descriptor.
     *
     * A SCALAR slot accepts exactly its own type — unchanged, and safe, because a cast operation always
     * exists to reach it (the same precedent as `create_task.deadline`).
     *
     * An ARRAYED slot (`{base:<scalar|enum>, array:true}` → MULTI) accepts its own MULTI **or** its plain
     * ELEMENT type, because demanding MULTI alone was an unreachable dead end: NO operation produces a
     * `multi` from a scalar (only `array_map` / `array_filter` / `array_sort` do, and those need an array
     * INPUT), so every non-identity pipeline over a scalar source 422'd with no way out — the author picked
     * a text variable for an `array<text>` slot, added `text_uppercase`, and could never save. An EMPTY
     * pipeline was already accepted (validateVariablePipeline returns early), so the scalar terminal was
     * the only thing missing. The runtime already WRAPS it — {@see \App\Modules\Variables\Services\VariableResolver::coerce}
     * does `MULTI => is_array($value) ? array_values($value) : [$value]` — so what this admits is exactly
     * what the resolver can already deliver. `WorkflowStepCard.vue`'s `slotResultTypes` mirrors this pair;
     * the two must not drift.
     *
     * `object` / `array<file>` descriptors never reach here — {@see isUnsuppliableSlot} refuses them first.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<int, VariableType>
     */
    private function slotPipelineTerminals(array $descriptor): array
    {
        $slotType = VariableType::fromDescriptor($descriptor);

        if ($slotType !== VariableType::MULTI) {
            return [$slotType];
        }

        return [$slotType, VariableType::fromDescriptor(['base' => $descriptor['base'] ?? null, 'array' => false])];
    }

    /**
     * A structured field that is EITHER a bare literal OR the {kind: literal|variable, …}
     * union. A null/absent value is fine (optional). A literal value (bare or {kind:literal})
     * is checked by $literalValid. A {kind:variable} value must carry a well-formed ref
     * (source∈trigger|steps, path string, type∈VariableType) — its runtime value can't
     * be known at write time, so only the ref SHAPE is validated.
     *
     * @param  callable(mixed): bool  $literalValid  predicate the resolved literal must satisfy
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     * @param  array<int, VariableType>  $allowedTerminals  the field's accepted pipeline output types
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
     * VariableType set. This is the ONLY thing checkable for a variable at write time
     * (its resolved value is a run-time property of the trigger/step context).
     */
    private function validateVariableRef(Validator $validator, string $key, mixed $ref): void
    {
        if (!is_array($ref)) {
            $validator->errors()->add($key, 'A variable reference requires a ref object.');

            return;
        }

        // Single source of truth for the reference whitelist (see ADR-0021): a new root added to
        // VariableResolver::ROOTS is automatically accepted by this write-side validator too. The
        // superset carries `slots` (a template root, inert here — no workflow context populates it, so a
        // `slots` ref resolves to nothing at runtime), so it is nominally accepted but never produced.
        $source = $ref['source'] ?? null;
        if (!in_array($source, VariableResolver::ROOTS, true)) {
            $validator->errors()->add($key . '.ref.source', 'The reference source must be one of: ' . implode(', ', VariableResolver::ROOTS) . '.');
        }

        $path = $ref['path'] ?? null;
        if (!is_string($path) || trim($path) === '') {
            $validator->errors()->add($key . '.ref.path', 'The reference path is required.');
        }

        $type = $ref['type'] ?? null;
        if (!in_array($type, VariableType::ids(), true)) {
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
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
     * @param  array<int, VariableType>  $allowedTerminals
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
        $sourceType = VariableType::tryFrom((string) ($ref['type'] ?? ''));

        if ($sourceType === null) {
            return; // validateVariableRef already reported the bad ref.type
        }

        $sourceEnumOptions = $this->resolveRefEnumOptions($validator, $key, $ref, $refCtx);

        // The source's REAL descriptor (array-ops wave 3): an array<object> (repeater) / array<file> ref
        // degrades its wire type to text, so ONLY the reference-index descriptor still carries its
        // array-ness + element `fields`. Seed the descriptor-tracking walker with it so an array op over a
        // repeater roots at the true array descriptor rather than the degraded scalar. Null (flat seed) for
        // every scalar/enum/multi source (a provable no-op).
        $fullPath = $refCtx !== null ? $this->refFullPath($ref) : null;
        $sourceDescriptor = $fullPath !== null ? ($refCtx['index'][$fullPath]['descriptor'] ?? null) : null;

        // Pass $refCtx (reference index + form availability) so an op's value-typed ARGUMENT may itself
        // be a variable, validated against the same reference index; argDepth 0 = this top-level pipeline.
        app(PipelineValidator::class)->validateValuePipeline(
            $validator,
            $pipeline,
            $key . '.pipeline',
            $sourceType,
            $allowedTerminals,
            $sourceEnumOptions,
            $targetOptions,
            $refCtx,
            0,
            null,
            $sourceDescriptor,
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
     * @param  array{index: array<string, array{type: VariableType, enumOptions: array<int, string>|null}>, fields_available: bool}|null  $refCtx
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
                'assignee_type', 'assignee_id', 'form_id', 'approval_pipeline_id', 'attachments',
            ],
            WorkflowStepType::CREATE_FORM_REPORT => [
                'form_id', 'name', 'guidelines', 'sources', 'submissions_from', 'submissions_to',
            ],
            // `slots` is the ONE free-form map in a step config (its keys are the chosen template's slot
            // names, not a fixed vocabulary) — the shallowest-key guard therefore allows it wholesale, and
            // its keys are checked against the template's declarations instead (validateTemplateSlotMapping).
            WorkflowStepType::GENERATE_CONTENT => [
                'template_id', 'slots', 'folder_id', 'name', 'bot_id',
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
