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
use App\Modules\Workflows\Models\WorkflowGlobal;

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
 * grid columns flatten, repeaters are flagged). Its LEAF variables stay scalar/flat-set only — a
 * repeater has no comparable flat leaf, so it emits none. STRUCTURAL container variables (phase-2a)
 * are layered ADDITIVELY on top by containerVariables(): a SECTION also surfaces as an `object` and a
 * REPEATER as an `array<object>`, so the editor sees the whole form structure (form-coverage), while
 * every existing flat leaf (`section.subfield`) keeps resolving unchanged. A container is a grouping
 * node — REPRESENTATION ONLY (no per-element execution) — degraded to a text flat wire type and not a
 * condition source. Every field variable maps to a real key in the whitelisted submission answer map.
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
            // The workspace's GLOBALS — form-independent, so composed for every trigger type.
            $this->globalVariables(),
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
                $this->addTypeMapEntry($map, $variable['path'], WorkflowVariableType::from($variable['type']));
            }

            if ($triggerType === WorkflowTriggerType::FORM_SUBMITTED) {
                $triggerConfig = is_array($workflow->trigger_config) ? $workflow->trigger_config : [];
                $form = $this->resolveForm($triggerConfig['form_id'] ?? null);

                if ($form !== null) {
                    foreach ($this->formFieldVariables($form) as $variable) {
                        $this->addTypeMapEntry($map, $variable['path'], WorkflowVariableType::from($variable['type']));
                    }
                }
            }
        }

        foreach ($this->stepOutputTypeMap($workflow->steps ?? []) as $path => $type) {
            $this->addTypeMapEntry($map, $path, $type);
        }

        // The workspace GLOBALS resolve in every workflow, so their path → type entries are always
        // present (form-independent). Lets a directive / if-block pipeline on a `globals.<key>`
        // recover the global's REAL base type (not the degraded editor primitive on the wire).
        foreach ($this->globalVariables() as $variable) {
            $this->addTypeMapEntry($map, $variable['path'], WorkflowVariableType::from($variable['type']));
        }

        return $map;
    }

    /**
     * Add one path → type entry to the runtime type map, PLUS a FILE variable's composite subfield paths
     * (phase-2b), so a directive / if-block pipeline on a `<file>.name` / `.size` recovers the SUBFIELD's
     * real base type by path (not the whole-file `file`, nor the first-op fallback). Built from the SAME
     * source as the write-side reference index (fileSubfieldTypeMap), keeping the two maps aligned — as
     * they already are for the container paths.
     *
     * @param  array<string, WorkflowVariableType>  $map
     */
    private function addTypeMapEntry(array &$map, string $path, WorkflowVariableType $type): void
    {
        $map[$path] = $type;

        foreach ($this->fileSubfieldTypeMap($path, $type) as $subPath => $subType) {
            $map[$subPath] = $subType;
        }
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
                $this->addReferenceEntry($index, $variable['path'], WorkflowVariableType::from($variable['type']), $variable['enumOptions'] ?? null);
            }
        }

        if ($form !== null) {
            foreach ($this->formFieldVariables($form) as $variable) {
                $this->addReferenceEntry($index, $variable['path'], WorkflowVariableType::from($variable['type']), $variable['enumOptions'] ?? null);
            }
        }

        foreach ($this->stepOutputTypeMap($priorSteps) as $path => $type) {
            $this->addReferenceEntry($index, $path, $type, null);
        }

        // The workspace GLOBALS are referenceable in every step (form-independent), so a value-or-
        // variable pipeline targeting a `globals.<key>` write-validates against the global's type +
        // option list — the same gate a trigger/step reference passes.
        foreach ($this->globalVariables() as $variable) {
            $this->addReferenceEntry($index, $variable['path'], WorkflowVariableType::from($variable['type']), $variable['enumOptions'] ?? null);
        }

        return $index;
    }

    /**
     * Add one variable's reference-index entry {type, enumOptions} at $path, PLUS — for a FILE variable
     * — its composite subfield leaf entries (phase-2b, append-only). A `<file>.{id,name,type,size,url}`
     * subfield is a plain scalar (text, size=number) referenceable in its own right, so a pipeline-bearing
     * subfield ref (e.g. a text op on `<file>.name`) write-validates and type-flows from the SUBFIELD's
     * type instead of being rejected as an unknown path. The container entry itself is unchanged. A
     * non-file type adds no subfields; REPEATER element subfields are intentionally NOT enumerated
     * (per-element access is the deferred R2 loop), so a repeater-element ref stays unknown → rejected.
     *
     * @param  array<string, array{type: WorkflowVariableType, enumOptions: array<int, string>|null}>  $index
     * @param  array<int, string>|null  $enumOptions
     */
    private function addReferenceEntry(array &$index, string $path, WorkflowVariableType $type, ?array $enumOptions): void
    {
        $index[$path] = ['type' => $type, 'enumOptions' => $enumOptions];

        foreach ($this->fileSubfieldTypeMap($path, $type) as $subPath => $subType) {
            $index[$subPath] = ['type' => $subType, 'enumOptions' => null];
        }
    }

    /**
     * The `<file>.<subfield>` PATH → type entries for a FILE variable at $path — empty for any non-file
     * type. The subfield SET + types come from the single source WorkflowVariableType::fileSubfieldTypes
     * (id/name/type/url=text, size=number), so the catalog descriptor, the write-validation reference
     * index and the runtime type map can never disagree. NOT emitted for a REPEATER (a text `object`
     * container here): per-element subfield access is the deferred R2 loop.
     *
     * @return array<string, WorkflowVariableType>
     */
    private function fileSubfieldTypeMap(string $path, WorkflowVariableType $type): array
    {
        if ($type !== WorkflowVariableType::FILE) {
            return [];
        }

        $map = [];

        foreach (WorkflowVariableType::fileSubfieldTypes() as $subfield => $subType) {
            $map[$path . '.' . $subfield] = $subType;
        }

        return $map;
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
     * `trigger.fields.<fieldPath>` LEAF variable typed by its element kind. Repeaters have no flat leaf
     * and are skipped in this pass. The human name is the field's schema label (fallback: the path).
     *
     * ADDITIVE (phase-2a): the STRUCTURAL container variables (a SECTION as an `object`, a REPEATER as
     * an `array<object>`) are appended by containerVariables() — the flat leaves above are UNCHANGED, so
     * every existing `section.subfield` reference keeps resolving. See containerVariables().
     *
     * @return array<int, array<string, mixed>>
     */
    public function formFieldVariables(Form $form): array
    {
        $variables = [];
        $schema = $form->getJsonSchema();
        // Human option labels live in the ELEMENT CONFIG, not the JSON schema (which keeps only the
        // option VALUES). Resolve them once per form (no N+1), keyed by the same field path.
        $optionLabels = $this->schemaOptionLabels($form);

        foreach ($this->extractFieldPaths($schema) as $info) {
            if (($info['type'] ?? null) === 'repeater') {
                continue; // no flat leaf; the repeater is emitted as an array<object> container below.
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

        return array_merge($variables, $this->containerVariables($form, $schema));
    }

    /**
     * The STRUCTURAL container variables for a form (phase-2a, additive): one grouping entry per
     * top-level SECTION (an `object`) and per REPEATER (an `array<object>`), each carrying an ordered
     * `fields` descriptor list of its children. A container is REPRESENTATION ONLY — its flat wire
     * `type` degrades to text and it is NOT a condition source (conditionFields skips it); per-element
     * loop execution is out of scope (deferred to R2). Existing flat leaf variables are unaffected.
     *
     * The container STRUCTURE + child types come from the form JSON $schema (real typed fragments,
     * reusing mapSchemaToVariableType); the human labels (field names + enum option labels, INCLUDING
     * inside repeaters) come from the element config tree — the JSON schema drops both. The shared
     * InteractsWithFormSchema trait is deliberately left untouched (Forms depend on its exact contract).
     *
     * @param  array<string, mixed>  $schema  the form's JSON schema (passed in so it is built once)
     * @return array<int, array<string, mixed>>
     */
    private function containerVariables(Form $form, array $schema): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        $fieldLabels = [];
        $optionLabels = [];
        $this->collectContainerLabels(is_array($form->content) ? $form->content : [], '', $fieldLabels, $optionLabels);

        $variables = [];

        foreach ($properties as $key => $childSchema) {
            if (!is_array($childSchema)) {
                continue;
            }

            $path = (string) $key;
            $descriptor = $this->containerDescriptorFor($childSchema, $path, $fieldLabels, $optionLabels);

            if ($descriptor === null) {
                continue; // a plain scalar/enum/multi leaf — already emitted by the leaf pass above.
            }

            $variables[] = $this->containerVariable(
                'trigger.fields.' . $path,
                $fieldLabels[$path] ?? $path,
                $descriptor,
            );
        }

        return $variables;
    }

    /**
     * The object DESCRIPTOR for a schema fragment when it is a CONTAINER — a SECTION (`type:object`) or
     * a REPEATER (`type:array` whose `items` are objects) — else null (a scalar/enum/multi leaf, whose
     * descriptor the leaf pass owns). A repeater yields `array:true`, a section `array:false`; both
     * carry an ordered `fields` list built recursively from the child schema fragments + the label maps.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, string>  $fieldLabels
     * @param  array<string, array<int, array{key: string, label: string}>>  $optionLabels
     * @return array<string, mixed>|null
     */
    private function containerDescriptorFor(array $schema, string $path, array $fieldLabels, array $optionLabels): ?array
    {
        // SECTION → an object whose `properties` are the children.
        if (($schema['type'] ?? null) === 'object' && is_array($schema['properties'] ?? null)) {
            return WorkflowVariableType::OBJECT->descriptor(
                nullable: false,
                fields: $this->buildContainerFields($schema['properties'], $path, $fieldLabels, $optionLabels),
                array: false,
            );
        }

        // REPEATER → an array whose `items` are an object; the element fields live under items.properties.
        if (($schema['type'] ?? null) === 'array'
            && ($schema['items']['type'] ?? null) === 'object'
            && is_array($schema['items']['properties'] ?? null)
        ) {
            return WorkflowVariableType::OBJECT->descriptor(
                nullable: false,
                fields: $this->buildContainerFields($schema['items']['properties'], $path, $fieldLabels, $optionLabels),
                array: true,
            );
        }

        return null;
    }

    /**
     * The ordered `{key, label, descriptor}` field list for a container's children. Each child's
     * descriptor is built recursively — a scalar/enum/multi child via mapSchemaToVariableType + its
     * config option labels, a nested container (a section-in-repeater edge) via another object
     * descriptor. Order follows the schema's property order (which mirrors the form element order).
     *
     * @param  array<string, mixed>  $properties  child schema fragments keyed by field key
     * @param  array<string, string>  $fieldLabels
     * @param  array<string, array<int, array{key: string, label: string}>>  $optionLabels
     * @return array<int, array{key: string, label: string, descriptor: array<string, mixed>}>
     */
    private function buildContainerFields(array $properties, string $prefix, array $fieldLabels, array $optionLabels): array
    {
        $fields = [];

        foreach ($properties as $key => $childSchema) {
            if (!is_array($childSchema)) {
                continue;
            }

            $childKey = (string) $key;
            $childPath = $this->joinPath($prefix, $childKey);

            $descriptor = $this->containerDescriptorFor($childSchema, $childPath, $fieldLabels, $optionLabels)
                ?? $this->leafDescriptor($childSchema, $childPath, $optionLabels);

            $fields[] = [
                'key' => $childKey,
                'label' => $fieldLabels[$childPath] ?? $childKey,
                'descriptor' => $descriptor,
            ];
        }

        return $fields;
    }

    /**
     * The scalar/enum/multi DESCRIPTOR for a leaf child schema fragment: reuses mapSchemaToVariableType
     * for the base + array flag, and the config-sourced option labels (falling back to the raw value as
     * its own label when the element config carried none).
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, array<int, array{key: string, label: string}>>  $optionLabels
     * @return array<string, mixed>
     */
    private function leafDescriptor(array $schema, string $path, array $optionLabels): array
    {
        $type = $this->mapSchemaToVariableType($schema);
        $options = $optionLabels[$path] ?? $this->optionsFromValues($this->enumOptions($type, $schema));

        return $type->descriptor($options, false);
    }

    /**
     * Build one CONTAINER variable entry: a structural grouping node whose flat wire `type` degrades to
     * text (like a scalar — so the resolver/evaluator/executor + FE never see `object`), carrying the
     * pre-built object `descriptor`. It has NO `field_id` (a container is not a condition source —
     * conditionFields skips it) and NO flat `enumOptions`.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<string, mixed>
     */
    private function containerVariable(string $path, string $name, array $descriptor): array
    {
        return [
            'source' => 'trigger',
            'path' => $path,
            'name' => $name,
            'type' => $this->flatType(WorkflowVariableType::OBJECT)->value,
            'descriptor' => $descriptor,
        ];
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
     * The workspace's GLOBAL variables — user-created LITERAL constants — as catalog variables. A
     * NEW `globals` source (form-independent: composed for every trigger type), each entry
     * `globals.<key>` carrying the stored `descriptor` (the authoritative type), the degraded flat
     * wire `type` (via flatType, recovered from the descriptor), and — for an enum base — the option
     * key list. Read through the model, so WorkspaceScope / the tenant connection isolate the active
     * workspace in both db_modes; ordered by name for a stable catalog.
     *
     * Kept a clean ADDITIONAL source: it does NOT touch the form container walk, the condition
     * fields, or any existing variable. A global is not a condition source (conditionFields ignores
     * the `globals` source), matching a step output.
     *
     * @return array<int, array<string, mixed>>
     */
    public function globalVariables(): array
    {
        return WorkflowGlobal::query()
            ->orderBy('name')
            ->get()
            ->map(fn (WorkflowGlobal $global): array => $this->globalVariable($global))
            ->all();
    }

    /**
     * The workspace's globals as a `{<key>: <literal value>}` map — the payload the step runner
     * injects into the run context under the `globals` root. Values are the STORED literals (scalar,
     * list, object, or null); the resolver reads them by a plain whitelisted dotted lookup.
     *
     * @return array<string, mixed>
     */
    public function globalValues(): array
    {
        return WorkflowGlobal::query()
            ->get()
            ->mapWithKeys(fn (WorkflowGlobal $global): array => [$global->key => $global->value])
            ->all();
    }

    /**
     * One global catalog variable `{source:'globals', path:'globals.<key>', name, type, descriptor,
     * enumOptions?}`. `type` is the flat wire type recovered from the stored descriptor
     * (fromDescriptor → flatType), so an array/object global degrades exactly like the catalog's own
     * variables and the closed FE union / exhaustive match sites never receive an unknown. The
     * authoritative type stays in `descriptor`. The source EQUALS the root (`globals`) — mirroring
     * trigger/steps — so the structured-ref path builder and the write-side source whitelist both
     * accept it with no special-casing.
     *
     * @return array<string, mixed>
     */
    private function globalVariable(WorkflowGlobal $global): array
    {
        $descriptor = is_array($global->descriptor) ? $global->descriptor : [];
        $type = WorkflowVariableType::fromDescriptor($descriptor);

        $variable = [
            'source' => 'globals',
            'path' => 'globals.' . $global->key,
            'name' => $global->name,
            'type' => $this->flatType($type)->value,
            'descriptor' => $descriptor,
        ];

        // Surface the enum option KEYS on the flat wire (mirrors a form enum/multi variable) only
        // when the descriptor is actually enum-based — a plain array<text> global carries none.
        if (($descriptor['base'] ?? null) === WorkflowVariableType::ENUM->value) {
            $variable['enumOptions'] = array_values(array_map(
                fn ($option): string => (string) (is_array($option) ? ($option['key'] ?? '') : $option),
                is_array($descriptor['options'] ?? null) ? $descriptor['options'] : [],
            ));
        }

        return $variable;
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
        // Container (object / array<object>) grouping entries are NOT condition sources — their flat
        // `type` degrades to text, so leaving them in would mis-advertise them as text-conditionable
        // (and they carry no field_id). Drop them before mapping to condition field descriptors.
        $conditionable = array_values(array_filter(
            $fieldVariables,
            fn (array $variable): bool => ($variable['descriptor']['base'] ?? null) !== 'object',
        ));

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
        }, $conditionable);
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
     * The back-compat FLAT `type` wire value for a variable. TIME and OBJECT (phase-2a) degrade to TEXT
     * — the same LOUD tripwire: the variable resolver, condition evaluator, and operation executor each
     * dispatch on an EXHAUSTIVE match over the legacy 7 types (no default arm), and the FE mirrors a
     * closed 7-member union, so surfacing `time`/`object` on the flat wire would be a runtime
     * UnhandledMatchError / FE break. The structured `descriptor` carries the real base instead (`time`,
     * or `object` with its `fields`). Every other type is itself. Retiring these shims is a deferred
     * runtime-semantics slice (and, for OBJECT, R2-Generator's per-element loop execution).
     */
    private function flatType(WorkflowVariableType $type): WorkflowVariableType
    {
        return match ($type) {
            WorkflowVariableType::TIME, WorkflowVariableType::OBJECT => WorkflowVariableType::TEXT,
            default => $type,
        };
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

    /**
     * Collect, for a form's element tree, the human FIELD labels (config.label/name) and the enum/multi
     * OPTION labels — keyed by full dotted path and INCLUDING container interiors (sections AND
     * repeaters). Separate from collectOptionLabels (which powers the flat leaf variables and
     * deliberately excludes repeaters), so neither the leaf behaviour nor the shared trait changes. The
     * path shape mirrors buildJsonSchema (sections/repeaters nest under their id, grids flatten), so
     * each key aligns with the JSON-schema property path the container walk reads. No DB work.
     *
     * @param  array<int, mixed>  $elements
     * @param  array<string, string>  $fieldLabels
     * @param  array<string, array<int, array{key: string, label: string}>>  $optionLabels
     */
    private function collectContainerLabels(array $elements, string $prefix, array &$fieldLabels, array &$optionLabels): void
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            $type = FormElementType::tryFrom((string) ($element['type'] ?? ''));
            $id = $element['id'] ?? null;
            $config = is_array($element['config'] ?? null) ? $element['config'] : [];

            // Grid: columns flatten to the SAME path level as the grid (a grid has no id of its own).
            if ($type === FormElementType::GRID && is_array($config['columns'] ?? null)) {
                foreach ($config['columns'] as $column) {
                    if (is_array($column['element'] ?? null)) {
                        $this->collectContainerLabels([$column['element']], $prefix, $fieldLabels, $optionLabels);
                    }
                }

                continue;
            }

            if (!is_string($id) || $id === '') {
                continue;
            }

            $path = $this->joinPath($prefix, $id);

            // Section / repeater: record the container's own label, then recurse into its children
            // (unlike collectOptionLabels, a REPEATER is recursed too — its element fields need labels).
            if (in_array($type, [FormElementType::SECTION, FormElementType::REPEATER], true) && is_array($config['children'] ?? null)) {
                $fieldLabels[$path] = $this->elementLabel($config, $id);
                $this->collectContainerLabels($config['children'], $path, $fieldLabels, $optionLabels);

                continue;
            }

            // Input field: its human label + (for select/checklist) its option labels.
            if ($type !== null && $type->isInputElement()) {
                $fieldLabels[$path] = $this->elementLabel($config, $id);

                if (in_array($type, [FormElementType::SELECT, FormElementType::CHECKLIST], true)) {
                    $options = $this->labeledOptions($config['options'] ?? null);

                    if ($options !== []) {
                        $optionLabels[$path] = $options;
                    }
                }
            }
        }
    }

    /** The human label for an element (config.label, then config.name), falling back to its id/key. */
    private function elementLabel(array $config, string $id): string
    {
        foreach (['label', 'name'] as $key) {
            $value = $config[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return $id;
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
