<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Forms\Enums\FormElementType;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Traits\InteractsWithFormSchema;
use App\Modules\Workflows\Enums\WorkflowAiPersona;
use App\Modules\Workflows\Enums\WorkflowOperation;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Enums\WorkflowVariableType;
use App\Modules\Workflows\Models\Workflow;

/**
 * Builds the TYPED variable catalog the workflow editor (B6/B7) and AI-assist (B5) consume: the
 * reference-able variables plus the per-field CONDITION descriptors, for one form_submitted +
 * Form pairing (or for the trigger system variables alone). This is the single contract the FE
 * reads to render variable chips and the condition builder, and it CANNOT drift from what the
 * engine actually exposes because every path here is one the trigger payload / step output map
 * really carries.
 *
 * A variable is `{ source, path, name, type, descriptor, enumOptions?, nullable? }`:
 *   - source  'trigger' | 'steps'
 *   - path    the full dotted path a reference resolves against ('trigger.fields.abc',
 *             'steps.<key>.task_id') — identical in both serializations.
 *   - type    a WorkflowVariableType — the LEGACY FLAT wire type (back-compat; TIME degrades to text).
 *   - descriptor the ADDITIVE structured type descriptor `{ base, nullable, array, options? }`
 *             (phase-1a). `time` gets its own base here even while `type` stays text; enum/multi
 *             carry `{key,label}` options with the REAL human labels from the form element config.
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
     * The full catalog for a form_submitted trigger + a selected Form. A thin FORM_SUBMITTED
     * specialization of forContext() kept for the form-bound route + its existing callers: it
     * layers the form's field vars onto the form-independent catalog (see forContext()).
     *
     * @return array{variables: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>, operations: array<int, array<string, mixed>>, ai_personas: array<int, array{id: string}>, types: array<int, array<string, mixed>>}
     */
    public function forForm(Form $form): array
    {
        return $this->forContext(WorkflowTriggerType::FORM_SUBMITTED, $form);
    }

    /**
     * The composable catalog for a workflow CONTEXT, assembled from sources and FORM-INDEPENDENT.
     * It always carries the structural metadata every editor needs regardless of any form:
     *   - the trigger-system vars for $triggerType (none when null),
     *   - the `steps.<TYPE>.*` step-output template vars,
     *   - the label-less operation catalog + ai-text persona catalog (SB2),
     *   - the variable-type list (id + editor primitive + operators).
     * Per-form FIELD variables — and ONLY those, plus their condition field descriptors — are
     * added when (and only when) a $form is present.
     *
     * A form-LESS call (a schedule trigger, or a form_submitted workflow with no form yet chosen)
     * therefore still yields a real catalog, so the editor no longer needs a static mirror of the
     * trigger-system vars / step outputs. This is the prerequisite for deleting the FE mirrors.
     *
     * @return array{variables: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>, operations: array<int, array<string, mixed>>, ai_personas: array<int, array{id: string}>, types: array<int, array<string, mixed>>}
     */
    public function forContext(?WorkflowTriggerType $triggerType, ?Form $form = null): array
    {
        $fieldVariables = $form !== null ? $this->formFieldVariables($form) : [];

        $variables = array_merge(
            $triggerType !== null ? $this->triggerSystemVariables($triggerType) : [],
            $fieldVariables,
            $this->stepOutputVariables(),
        );

        return [
            'variables' => $variables,
            'fields' => $this->conditionFields($fieldVariables),
            'operations' => WorkflowOperation::catalog(),
            'ai_personas' => WorkflowAiPersona::catalog(),
            'types' => $this->variableTypes(),
        ];
    }

    /**
     * The variable-TYPE list: every WorkflowVariableType with the editor PRIMITIVE it degrades to
     * inside a directive and its condition operator set. Label-less (the FE localizes), mirroring
     * the operations / ai_personas descriptor pattern. Lets a form-LESS catalog describe the full
     * type vocabulary — and the degrade rule — the editor needs without a static FE mirror. Built
     * from the enum's existing accessors (the type system itself is unchanged).
     *
     * @return array<int, array{id: string, primitive: string, operators: array<int, string>}>
     */
    public function variableTypes(): array
    {
        return array_map(fn (WorkflowVariableType $type): array => [
            'id' => $type->value,
            'primitive' => $type->editorPrimitive(),
            'operators' => $type->operators(),
        ], WorkflowVariableType::cases());
    }

    /**
     * The CONDITION field descriptors for a form — the valid condition sources ({path, field_id,
     * label, type, operators, enumOptions?}). Exposed for the condition-tree write-validator, which
     * checks a condition's `source`/`source_type` against this same set (so the builder and the
     * validator can never disagree on which fields are conditionable).
     *
     * @return array<int, array<string, mixed>>
     */
    public function conditionFieldsFor(Form $form): array
    {
        return $this->conditionFields($this->formFieldVariables($form));
    }

    /**
     * The RUNTIME path → WorkflowVariableType map the step runner hands the resolver so a directive
     * or if-block pipeline executes against each reference's REAL type (recovered here, never from
     * the degraded editor primitive on the wire). It covers the trigger's system variables, its
     * form-field variables (when a form_submitted workflow selected a resolvable form), and every
     * step's outputs keyed by the workflow's actual step KEYS (`steps.<key>.<output>`).
     *
     * @return array<string, WorkflowVariableType>
     */
    public function runtimeTypeMap(Workflow $workflow): array
    {
        $map = [];
        $triggerType = $workflow->trigger_type;

        if ($triggerType instanceof WorkflowTriggerType) {
            foreach ($this->triggerSystemVariables($triggerType) as $variable) {
                $map[$variable['path']] = WorkflowVariableType::from($variable['type']);
            }

            if ($triggerType === WorkflowTriggerType::FORM_SUBMITTED) {
                $triggerConfig = is_array($workflow->trigger_config) ? $workflow->trigger_config : [];
                $form = $this->resolveForm($triggerConfig['form_id'] ?? null);

                if ($form !== null) {
                    foreach ($this->formFieldVariables($form) as $variable) {
                        $map[$variable['path']] = WorkflowVariableType::from($variable['type']);
                    }
                }
            }
        }

        return array_merge($map, $this->stepOutputTypeMap($workflow->steps ?? []));
    }

    /**
     * The reference INDEX a value-or-variable ref is write-validated against: full path →
     * {type, enumOptions?}. Trigger system vars + (when the form resolves) its field vars + the
     * outputs of the given PRIOR steps (so a step may only reference earlier steps). Mirrors the
     * runtime map but carries option lists for the pipeline's sourceOption/sourceMap arg checks.
     *
     * @param  array<int, array<string, mixed>>  $priorSteps
     * @return array<string, array{type: WorkflowVariableType, enumOptions: array<int, string>|null}>
     */
    public function referenceIndex(?WorkflowTriggerType $triggerType, ?Form $form, array $priorSteps): array
    {
        $index = [];

        if ($triggerType !== null) {
            foreach ($this->triggerSystemVariables($triggerType) as $variable) {
                $index[$variable['path']] = [
                    'type' => WorkflowVariableType::from($variable['type']),
                    'enumOptions' => $variable['enumOptions'] ?? null,
                ];
            }
        }

        if ($form !== null) {
            foreach ($this->formFieldVariables($form) as $variable) {
                $index[$variable['path']] = [
                    'type' => WorkflowVariableType::from($variable['type']),
                    'enumOptions' => $variable['enumOptions'] ?? null,
                ];
            }
        }

        foreach ($this->stepOutputTypeMap($priorSteps) as $path => $type) {
            $index[$path] = ['type' => $type, 'enumOptions' => null];
        }

        return $index;
    }

    /**
     * Step-output variables keyed by the workflow's ACTUAL step keys (`steps.<key>.<output>` → type),
     * from each step class's static output descriptors. A step with an unknown type / missing key is
     * skipped. This is the runtime/validation counterpart to stepOutputVariables() (which keys by
     * step TYPE for the editor catalog).
     *
     * @param  array<int, mixed>  $steps
     * @return array<string, WorkflowVariableType>
     */
    public function stepOutputTypeMap(array $steps): array
    {
        $map = [];

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $key = $step['key'] ?? null;
            $type = WorkflowStepType::tryFrom((string) ($step['type'] ?? ''));

            if (!is_string($key) || $key === '' || $type === null) {
                continue;
            }

            foreach ($this->steps->stepClass($type)::outputDescriptors() as $descriptor) {
                $map['steps.' . $key . '.' . $descriptor['name']] = $descriptor['type'];
            }
        }

        return $map;
    }

    /** Resolve a (tenant-scoped) Form from a raw id, or null when absent/foreign. */
    private function resolveForm(mixed $formId): ?Form
    {
        return is_string($formId) && $formId !== '' ? Form::find($formId) : null;
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
        // Human option labels live in the ELEMENT CONFIG, not the JSON schema (which keeps only the
        // option VALUES). Resolve them once per form (no N+1), keyed by the same field path.
        $optionLabels = $this->schemaOptionLabels($form);

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
                descriptorOptions: $optionLabels[$info['path']] ?? null,
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
     *   string+format:file → file · string+format:date → date · string+format:time → time ·
     *   enum(single) → enum · array → multi · number → number · boolean → boolean · everything
     *   else (incl. url/unknown) → text (defensive).
     *
     * NOTE the TIME base is recovered here (a real type), but the variable's FLAT `type` wire value
     * degrades it back to text (see flatType) — only the structured descriptor keeps `time` in this
     * slice, so the resolver/evaluator/FE (which don't yet understand it) stay untouched.
     *
     * @param  array<string, mixed>  $schema
     */
    private function mapSchemaToVariableType(array $schema): WorkflowVariableType
    {
        $jsonType = $schema['type'] ?? 'string';

        // A file input carries format:'file'. Checked FIRST so it wins over every shape below —
        // a future multi-file field could arrive as an array and must still read as FILE, never
        // as a plain multi-select.
        if (($schema['format'] ?? null) === 'file') {
            return WorkflowVariableType::FILE;
        }

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

        // A time-of-day input (the form TIME element) carries format:'time'.
        if (($schema['format'] ?? null) === 'time') {
            return WorkflowVariableType::TIME;
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
     * Build one variable entry. `type` is the LEGACY FLAT wire type (back-compat) — degraded via
     * flatType so a TIME field still reads as text; `descriptor` is the ADDITIVE structured type
     * (phase-1a), carrying the real base (incl. `time`) plus `{key,label}` options for enum/multi.
     * `enumOptions` is emitted only when present; `nullable` only when true; `field_id` is carried
     * for field variables so conditionFields can key on it (it is dropped from the public response
     * shape by the Resource-less controller — see below). `$descriptorOptions` are the resolved
     * `{key,label}` option list (form-config labels); absent, the descriptor falls back to labelling
     * each flat `enumOptions` value with itself (system/step enum vars have no human labels).
     *
     * @param  array<int, string>|null  $enumOptions
     * @param  array<int, array{key: string, label: string}>|null  $descriptorOptions
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
        ?array $descriptorOptions = null,
    ): array {
        $variable = [
            'source' => $source,
            'path' => $path,
            'name' => $name,
            'type' => $this->flatType($type)->value,
            'descriptor' => $type->descriptor(
                $descriptorOptions ?? $this->optionsFromValues($enumOptions),
                $nullable,
            ),
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

    /**
     * The back-compat FLAT `type` wire value for a variable. TIME degrades to TEXT — its historical
     * representation: the variable resolver, condition evaluator, and operation executor each dispatch
     * on an EXHAUSTIVE match over the legacy 7 types (no default arm), and the FE mirrors a closed
     * 7-member union, so surfacing `time` on the flat wire would be a runtime UnhandledMatchError / FE
     * break. The structured `descriptor` carries the real `time` base instead. Every other type is
     * itself. Retiring this shim (letting TIME flow flat) is the deferred runtime-semantics slice.
     */
    private function flatType(WorkflowVariableType $type): WorkflowVariableType
    {
        return $type === WorkflowVariableType::TIME ? WorkflowVariableType::TEXT : $type;
    }

    /**
     * The per-path descriptor OPTION LABELS for a form's option-bearing inputs (select / checklist),
     * read from the ELEMENT CONFIG — the only place human labels survive (FormElementType::toJsonSchema
     * emits the option VALUES into the schema `enum` and DROPS the labels). ONE pass over the content
     * tree, mirroring buildJsonSchema's path shape (sections nest under their id, grids flatten,
     * repeaters are excluded), so each `path` aligns with an extractFieldPaths field path. No DB work.
     *
     * @return array<string, array<int, array{key: string, label: string}>>
     */
    private function schemaOptionLabels(Form $form): array
    {
        $labels = [];
        $this->collectOptionLabels(is_array($form->content) ? $form->content : [], '', $labels);

        return $labels;
    }

    /**
     * Recurse the element tree collecting `path => [{key,label}]` for every option-bearing input.
     *
     * @param  array<int, mixed>  $elements
     * @param  array<string, array<int, array{key: string, label: string}>>  $labels
     */
    private function collectOptionLabels(array $elements, string $prefix, array &$labels): void
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $type = FormElementType::tryFrom((string) ($element['type'] ?? ''));
            $id = $element['id'] ?? null;
            $config = is_array($element['config'] ?? null) ? $element['config'] : [];

            // Option-bearing inputs: record path => [{key,label}].
            if (in_array($type, [FormElementType::SELECT, FormElementType::CHECKLIST], true) && is_string($id) && $id !== '') {
                $options = $this->labeledOptions($config['options'] ?? null);

                if ($options !== []) {
                    $labels[$this->joinPath($prefix, $id)] = $options;
                }

                continue;
            }

            // Section: children nest under the section id.
            if ($type === FormElementType::SECTION && is_array($config['children'] ?? null) && is_string($id) && $id !== '') {
                $this->collectOptionLabels($config['children'], $this->joinPath($prefix, $id), $labels);

                continue;
            }

            // Grid: columns flatten to the SAME path level as the grid.
            if ($type === FormElementType::GRID && is_array($config['columns'] ?? null)) {
                foreach ($config['columns'] as $column) {
                    if (is_array($column['element'] ?? null)) {
                        $this->collectOptionLabels([$column['element']], $prefix, $labels);
                    }
                }
            }

            // Repeater: EXCLUDED — its answers are arrays-of-objects (no scalar variable is emitted).
        }
    }

    /** Join a dotted-path prefix with a segment (segment-only when the prefix is empty). */
    private function joinPath(string $prefix, string $segment): string
    {
        return $prefix === '' ? $segment : $prefix . '.' . $segment;
    }

    /**
     * Normalize a form element's `config.options` ({value,label} entries, or bare scalars) into the
     * descriptor's `{key,label}` list: the key is the option VALUE (string-normalized, matching the
     * schema `enum` + the runtime answer), the label is the human `label` falling back to the value.
     *
     * @return array<int, array{key: string, label: string}>
     */
    private function labeledOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $labeled = [];

        foreach ($options as $option) {
            if (is_array($option)) {
                $value = $option['value'] ?? null;

                if (!is_scalar($value)) {
                    continue;
                }

                $key = (string) $value;
                $label = isset($option['label']) && is_string($option['label']) && trim($option['label']) !== ''
                    ? $option['label']
                    : $key;
            } elseif (is_scalar($option)) {
                $key = (string) $option;
                $label = $key;
            } else {
                continue;
            }

            $labeled[] = ['key' => $key, 'label' => $label];
        }

        return $labeled;
    }

    /**
     * The descriptor `{key,label}` options for a source whose only option data is a flat VALUE list
     * (the trigger-system enum vars, and any enum/multi field lacking config labels): label = value.
     *
     * @param  array<int, string>|null  $values
     * @return array<int, array{key: string, label: string}>
     */
    private function optionsFromValues(?array $values): array
    {
        if ($values === null) {
            return [];
        }

        return array_values(array_map(fn ($value) => [
            'key' => (string) $value,
            'label' => (string) $value,
        ], $values));
    }
}
