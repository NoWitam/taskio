<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Traits\InteractsWithFormSchema;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Enums\WorkflowVariableType;

/**
 * Builds the TYPED variable catalog the workflow editor (B6/B7) and AI-assist (B5) consume: the
 * reference-able variables plus the per-field CONDITION descriptors, for one form_submitted +
 * Form pairing (or for the trigger system variables alone). This is the single contract the FE
 * reads to render variable chips and the condition builder, and it CANNOT drift from what the
 * engine actually exposes because every path here is one the trigger payload / step output map
 * really carries.
 *
 * A variable is `{ source, path, name, type, enumOptions?, nullable? }`:
 *   - source  'trigger' | 'steps'
 *   - path    the full dotted path a reference resolves against ('trigger.fields.abc',
 *             'steps.<key>.task_id') — identical in both serializations.
 *   - type    a WorkflowVariableType.
 *   - nullable true when the path is only present sometimes (the task snapshot on a
 *             task-attached submission) — the FE can badge it and the engine null-resolves it.
 *
 * A field descriptor is `{ path, field_id, label, type, enumOptions?, operators }`: the subset
 * of form-field variables usable in the CONDITION builder, each with its per-type operator set.
 *
 * SCHEMA RECURSION is REUSED from InteractsWithFormSchema::extractFieldPaths (sections recurse,
 * grid columns flatten, repeaters are flagged). Repeaters are EXCLUDED here: their answers are
 * arrays-of-objects (JSONB) that `fields.<id>` cannot resolve to a comparable scalar/flat-set,
 * so emitting a variable for one would be a dead path. Every field variable maps to a real
 * `fields.<path>` key in the whitelisted submission answer map.
 */
class WorkflowVariableCatalogService
{
    use InteractsWithFormSchema;

    public function __construct(
        private WorkflowStepFactory $steps,
    ) {}

    /**
     * The full catalog for a form_submitted trigger + a selected Form: system trigger vars,
     * per-form field vars, and step-output vars, plus the condition field descriptors.
     *
     * @return array{variables: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>}
     */
    public function forForm(Form $form): array
    {
        $fieldVariables = $this->formFieldVariables($form);

        $variables = array_merge(
            $this->triggerSystemVariables(WorkflowTriggerType::FORM_SUBMITTED),
            $fieldVariables,
            $this->stepOutputVariables(),
        );

        return [
            'variables' => $variables,
            'fields' => $this->conditionFields($fieldVariables),
        ];
    }

    /**
     * The SYSTEM (non-field) variables a trigger type always exposes. schedule carries only
     * scheduled_at; form_submitted carries the submission/form ids, source, timestamp, and the
     * task id (nullable — present only for a task-attached submission).
     *
     * @return array<int, array<string, mixed>>
     */
    public function triggerSystemVariables(WorkflowTriggerType $type): array
    {
        return match ($type) {
            WorkflowTriggerType::SCHEDULE => [
                $this->variable('trigger', 'trigger.scheduled_at', 'Scheduled at', WorkflowVariableType::DATE),
            ],
            WorkflowTriggerType::FORM_SUBMITTED => [
                $this->variable('trigger', 'trigger.submission.id', 'Submission ID', WorkflowVariableType::TEXT),
                $this->variable('trigger', 'trigger.form.id', 'Form ID', WorkflowVariableType::TEXT),
                $this->variable('trigger', 'trigger.form.name', 'Form name', WorkflowVariableType::TEXT),
                $this->variable('trigger', 'trigger.source', 'Source', WorkflowVariableType::ENUM, enumOptions: ['manual', 'task']),
                $this->variable('trigger', 'trigger.submitted_at', 'Submitted at', WorkflowVariableType::DATE),
                $this->variable('trigger', 'trigger.task.id', 'Task ID', WorkflowVariableType::TEXT, nullable: true),
            ],
        };
    }

    /**
     * Per-form FIELD variables derived from the form's JSON schema. Each usable input becomes a
     * `trigger.fields.<fieldPath>` variable typed by its element kind. Repeaters are skipped
     * (see class docblock). The human name is the field's schema label (fallback: the path).
     *
     * @return array<int, array<string, mixed>>
     */
    public function formFieldVariables(Form $form): array
    {
        $variables = [];

        foreach ($this->extractFieldPaths($form->getJsonSchema()) as $info) {
            if (($info['type'] ?? null) === 'repeater') {
                continue; // arrays-of-objects can't resolve to a comparable variable — omitted.
            }

            $fieldSchema = $info['field'] ?? [];
            $type = $this->mapSchemaToVariableType($fieldSchema);
            $enumOptions = $this->enumOptions($type, $fieldSchema);

            $variables[] = $this->variable(
                'trigger',
                'trigger.fields.' . $info['path'],
                $this->labelFor($fieldSchema, $info['path']),
                $type,
                enumOptions: $enumOptions,
                fieldId: $info['path'],
            );
        }

        return $variables;
    }

    /**
     * Step-output variables: for every registered step type, its declared outputs become
     * `steps.<type>.<name>` variables. The `key` a user assigns a step is per-workflow, so the
     * catalog keys by step TYPE as the reference stem the FE substitutes; each output's type
     * comes from the step class's static descriptors (so B4 adds outputs on the step only).
     *
     * @return array<int, array<string, mixed>>
     */
    public function stepOutputVariables(): array
    {
        $variables = [];

        foreach (WorkflowStepType::cases() as $type) {
            $class = $this->steps->stepClass($type);

            foreach ($class::outputDescriptors() as $descriptor) {
                $variables[] = $this->variable(
                    'steps',
                    'steps.' . $type->value . '.' . $descriptor['name'],
                    $type->label() . ' · ' . $descriptor['name'],
                    $descriptor['type'],
                );
            }
        }

        return $variables;
    }

    /**
     * The CONDITION field descriptors: the subset of form-field variables usable in the
     * condition builder, each carrying its field_id, label, type, options, and the operator set
     * valid for its type (WorkflowVariableType::operators — the same set the validator enforces).
     *
     * @param  array<int, array<string, mixed>>  $fieldVariables
     * @return array<int, array<string, mixed>>
     */
    private function conditionFields(array $fieldVariables): array
    {
        return array_map(function (array $variable): array {
            $type = WorkflowVariableType::from($variable['type']);

            $field = [
                'path' => 'fields.' . $variable['field_id'],
                'field_id' => $variable['field_id'],
                'label' => $variable['name'],
                'type' => $type->value,
                'operators' => $type->operators(),
            ];

            if (isset($variable['enumOptions'])) {
                $field['enumOptions'] = $variable['enumOptions'];
            }

            return $field;
        }, $fieldVariables);
    }

    /**
     * Map a form field's JSON-schema fragment to a workflow variable type. Uses the schema's
     * shape (string/number/boolean/array + format/enum) so it stays aligned with what
     * FormElementType::toJsonSchema emits:
     *   string+format:date → date · enum(single) → enum · array → multi · number → number ·
     *   boolean → boolean · everything else (incl. url/time/unknown) → text (defensive).
     *
     * @param  array<string, mixed>  $schema
     */
    private function mapSchemaToVariableType(array $schema): WorkflowVariableType
    {
        $jsonType = $schema['type'] ?? 'string';

        if ($jsonType === 'array') {
            return WorkflowVariableType::MULTI; // multi-select / checklist
        }

        if ($jsonType === 'number' || $jsonType === 'integer') {
            return WorkflowVariableType::NUMBER;
        }

        if ($jsonType === 'boolean') {
            return WorkflowVariableType::BOOLEAN;
        }

        if (($schema['format'] ?? null) === 'date') {
            return WorkflowVariableType::DATE;
        }

        // A single-select carries an enum of allowed values on a string schema.
        if (!empty($schema['enum']) && is_array($schema['enum'])) {
            return WorkflowVariableType::ENUM;
        }

        return WorkflowVariableType::TEXT; // short/long text, url, time, image, unknown
    }

    /**
     * The option list for an enum/multi field (from the schema's `enum`, or an array items'
     * `enum`), string-normalized. null for non-optioned types.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, string>|null
     */
    private function enumOptions(WorkflowVariableType $type, array $schema): ?array
    {
        if ($type === WorkflowVariableType::ENUM && !empty($schema['enum']) && is_array($schema['enum'])) {
            return array_values(array_map(fn ($v) => (string) $v, $schema['enum']));
        }

        if ($type === WorkflowVariableType::MULTI) {
            $itemEnum = $schema['items']['enum'] ?? null;

            if (is_array($itemEnum) && $itemEnum !== []) {
                return array_values(array_map(fn ($v) => (string) $v, $itemEnum));
            }
        }

        return null;
    }

    /**
     * The human label for a field. extractFieldPaths seeds `label` from the field name; the
     * schema's own `title`/`description` are preferred when present. Falls back to the path.
     *
     * @param  array<string, mixed>  $schema
     */
    private function labelFor(array $schema, string $path): string
    {
        foreach (['title', 'label', 'description'] as $key) {
            $value = $schema[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return $path;
    }

    /**
     * Build one variable descriptor. `enumOptions` is emitted only when present; `nullable` only
     * when true; `field_id` is carried for field variables so conditionFields can key on it (it
     * is dropped from the public response shape by the Resource-less controller — see below).
     *
     * @param  array<int, string>|null  $enumOptions
     * @return array<string, mixed>
     */
    private function variable(
        string $source,
        string $path,
        string $name,
        WorkflowVariableType $type,
        ?array $enumOptions = null,
        bool $nullable = false,
        ?string $fieldId = null,
    ): array {
        $variable = [
            'source' => $source,
            'path' => $path,
            'name' => $name,
            'type' => $type->value,
        ];

        if ($enumOptions !== null) {
            $variable['enumOptions'] = $enumOptions;
        }

        if ($nullable) {
            $variable['nullable'] = true;
        }

        if ($fieldId !== null) {
            $variable['field_id'] = $fieldId;
        }

        return $variable;
    }
}
