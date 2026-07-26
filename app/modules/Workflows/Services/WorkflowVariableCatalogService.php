<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Forms\Enums\FormElementType;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Traits\InteractsWithFormSchema;
use App\Modules\Variables\Contracts\ElementScopeResolver;
use App\Modules\Variables\Enums\Operation;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Models\Constant;
use App\Modules\Variables\Models\CustomFunction;
use App\Modules\Variables\Support\CustomFunctionOperation;
use App\Modules\Workflows\Enums\WorkflowAiPersona;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Tenancy\TenantContext;

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
 *   - name    DISPLAY metadata only (the identity is `path`): the human label the picker/chips show.
 *             For a form field it is the ELEMENT CONFIG label — the same label the container
 *             descriptors' children carry — falling back to the field id. Nothing server-side ever
 *             matches on it (the reference index, runtime type map and condition-tree validator all
 *             key on `path` / `field_id`).
 *   - type    a VariableType — the LEGACY FLAT wire type (back-compat; TIME degrades to text).
 *   - descriptor the ADDITIVE structured type descriptor `{ base, nullable, array, options? }`
 *             (phase-1a). `time` gets its own base here even while `type` stays text; enum/multi
 *             carry `{key,label}` options with the REAL human labels from the form element config.
 *   - nullable true when the path is only present sometimes — the task snapshot on a
 *             task-attached submission, or an OPTIONAL form field (one whose element config
 *             carries no `required`, so a submission may simply not answer it). The FE badges it
 *             and offers the per-reference "default when empty" literal; the engine
 *             null-resolves it. Emitted on BOTH the flat `nullable` key and `descriptor.nullable`.
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
class WorkflowVariableCatalogService implements ElementScopeResolver
{
    use InteractsWithFormSchema;

    public function __construct(
        private WorkflowStepFactory $steps,
        private TenantContext $tenant,
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
        // Read ONCE (a DB read) and used twice: as catalog variables, and as condition sources.
        $globalVariables = $this->globalVariables();

        $variables = array_merge(
            $triggerType !== null ? $this->triggerSystemVariables($triggerType) : [],
            $fieldVariables,
            $this->stepOutputVariables(),
            // The workspace's GLOBALS — form-independent, so composed for every trigger type.
            $globalVariables,
        );

        return [
            'variables' => $variables,
            'fields' => array_merge($this->conditionFields($fieldVariables), $this->globalConditionFields($globalVariables)),
            // The built-in op catalog PLUS the workspace's custom functions, so the FE add-menu offers a
            // function like any other op (filtered by input type). ADDITIVE: built-ins are unchanged.
            'operations' => array_merge(Operation::catalog(), $this->functionCatalog()),
            'ai_personas' => WorkflowAiPersona::catalog(),
            'types' => $this->variableTypes(),
        ];
    }

    /**
     * The variable-TYPE list: every VariableType with the editor PRIMITIVE it degrades to
     * inside a directive and its condition operator set. Label-less (the FE localizes), mirroring
     * the operations / ai_personas descriptor pattern. Lets a form-LESS catalog describe the full
     * type vocabulary — and the degrade rule — the editor needs without a static FE mirror. Built
     * from the enum's existing accessors (the type system itself is unchanged).
     *
     * @return array<int, array{id: string, primitive: string, operators: array<int, string>}>
     */
    public function variableTypes(): array
    {
        return array_map(fn (VariableType $type): array => [
            'id' => $type->value,
            'primitive' => $type->editorPrimitive(),
            'operators' => $type->operators(),
        ], VariableType::cases());
    }

    /**
     * The CONDITION SOURCE descriptors for a form — every valid condition `source` ({source, path,
     * field_id?, label, type, operators, enumOptions?}). Exposed for the condition-tree write-validator,
     * which checks a condition's `source`/`source_type` against this same set (so the builder and the
     * validator can never disagree on what is conditionable).
     *
     * TWO VOCABULARIES, one table (B6): the form's own fields keep the legacy UNPREFIXED `fields.<id>`
     * path (what every stored row and the flat clause list carry), while a workspace GLOBAL uses its
     * full catalog path `globals.<key>` — its catalog source IS its root, so no new spelling is
     * invented. Both are keyed by `path`, which is the identity the validator matches on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function conditionFieldsFor(Form $form): array
    {
        return array_merge(
            $this->conditionFields($this->formFieldVariables($form)),
            $this->globalConditionFields($this->globalVariables()),
        );
    }

    /**
     * The RUNTIME path → VariableType map the step runner hands the resolver so a directive
     * or if-block pipeline executes against each reference's REAL type (recovered here, never from
     * the degraded editor primitive on the wire). It covers the trigger's system variables, its
     * form-field variables (when a form_submitted workflow selected a resolvable form), and every
     * step's outputs keyed by the workflow's actual step KEYS (`steps.<key>.<output>`).
     *
     * @return array<string, VariableType>
     */
    public function runtimeTypeMap(Workflow $workflow): array
    {
        $map = [];
        $triggerType = $workflow->trigger_type;

        if ($triggerType instanceof WorkflowTriggerType) {
            foreach ($this->triggerSystemVariables($triggerType) as $variable) {
                $this->addTypeMapEntry($map, $variable['path'], VariableType::from($variable['type']), $variable['descriptor'] ?? null);
            }

            if ($triggerType === WorkflowTriggerType::FORM_SUBMITTED) {
                $triggerConfig = is_array($workflow->trigger_config) ? $workflow->trigger_config : [];
                $form = $this->resolveForm($triggerConfig['form_id'] ?? null);

                if ($form !== null) {
                    foreach ($this->formFieldVariables($form) as $variable) {
                        $this->addTypeMapEntry($map, $variable['path'], VariableType::from($variable['type']), $variable['descriptor'] ?? null);
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
            $this->addTypeMapEntry($map, $variable['path'], VariableType::from($variable['type']), $variable['descriptor'] ?? null);
        }

        return $map;
    }

    /**
     * Add one path → type entry to the runtime type map, PLUS the variable's DESCRIPTOR subfield paths
     * (a FILE composite's `<file>.name` / `.size`, an OBJECT container's declared fields — see
     * descriptorSubfieldTypeMap), so a directive / if-block pipeline on a subfield recovers the
     * SUBFIELD's real base type by path (not the degraded container type, nor the first-op fallback).
     * Built from the SAME source as the write-side reference index, keeping the two maps aligned — as
     * they already are for the container paths.
     *
     * A subfield NEVER overwrites an entry an earlier (flat) variable already owns: a form section's
     * leaves are emitted flat by formFieldVariables and keep their own authoritative entry.
     *
     * @param  array<string, VariableType>  $map
     * @param  array<string, mixed>|null  $descriptor  the variable's structured descriptor, when it has one
     */
    private function addTypeMapEntry(array &$map, string $path, VariableType $type, ?array $descriptor = null): void
    {
        $map[$path] = $type;

        foreach ($this->descriptorSubfieldTypeMap($path, $type, $descriptor) as $subPath => $subType) {
            $map[$subPath] ??= $subType;
        }
    }

    /**
     * The reference INDEX a value-or-variable ref is write-validated against: full path →
     * {type, enumOptions?}. Trigger system vars + (when the form resolves) its field vars + the
     * outputs of the given PRIOR steps (so a step may only reference earlier steps). Mirrors the
     * runtime map but carries option lists for the pipeline's sourceOption/sourceMap arg checks.
     *
     * @param  array<int, array<string, mixed>>  $priorSteps
     * @return array<string, array{type: VariableType, enumOptions: array<int, string>|null}>
     */
    public function referenceIndex(?WorkflowTriggerType $triggerType, ?Form $form, array $priorSteps): array
    {
        $index = [];

        if ($triggerType !== null) {
            foreach ($this->triggerSystemVariables($triggerType) as $variable) {
                $this->addReferenceEntry($index, $variable['path'], VariableType::from($variable['type']), $variable['enumOptions'] ?? null, $variable['descriptor'] ?? null);
            }
        }

        if ($form !== null) {
            foreach ($this->formFieldVariables($form) as $variable) {
                $this->addReferenceEntry($index, $variable['path'], VariableType::from($variable['type']), $variable['enumOptions'] ?? null, $variable['descriptor'] ?? null);
            }
        }

        foreach ($this->stepOutputTypeMap($priorSteps) as $path => $type) {
            $this->addReferenceEntry($index, $path, $type, null);
        }

        // The workspace GLOBALS are referenceable in every step (form-independent), so a value-or-
        // variable pipeline targeting a `globals.<key>` write-validates against the global's type +
        // option list — the same gate a trigger/step reference passes.
        foreach ($this->globalVariables() as $variable) {
            $this->addReferenceEntry($index, $variable['path'], VariableType::from($variable['type']), $variable['enumOptions'] ?? null, $variable['descriptor'] ?? null);
        }

        return $index;
    }

    /**
     * Add one variable's reference-index entry {type, enumOptions} at $path, PLUS the SUBFIELD leaf
     * entries its DESCRIPTOR declares (append-only — see descriptorSubfieldTypeMap): a FILE composite's
     * `<file>.{id,name,type,size,url}` (phase-2b) and an OBJECT container's own `fields` (phase-2c).
     * Each is a referenceable value in its own right, so a subfield ref — plain, pipeline-bearing, or as
     * an op ARGUMENT — write-validates and type-flows from the SUBFIELD's type instead of being rejected
     * as an unknown path. The container entry itself is unchanged.
     *
     * A subfield NEVER overwrites an entry an earlier (flat) variable already owns: a form SECTION emits
     * its leaves as flat `section.leaf` variables in the pass BEFORE its container entry, so those keep
     * their own richer entry (their enumOptions) and are not re-emitted from the descriptor.
     *
     * @param  array<string, array{type: VariableType, enumOptions: array<int, string>|null}>  $index
     * @param  array<int, string>|null  $enumOptions
     * @param  array<string, mixed>|null  $descriptor  the variable's structured descriptor, when it has one
     */
    private function addReferenceEntry(array &$index, string $path, VariableType $type, ?array $enumOptions, ?array $descriptor = null): void
    {
        // The variable's OWN structured descriptor rides the entry (array-ops wave 3): an array<object>
        // (repeater) / array<file> reference degrades its flat wire `type` to text, so ONLY the descriptor
        // still carries its array-ness + element `fields`. The write-validator seeds the descriptor-tracking
        // walker with it (validateValuePipeline/validateArgVariable), so an array op over a repeater roots at
        // the real array descriptor rather than the degraded scalar. Null for a source with no descriptor.
        $index[$path] = ['type' => $type, 'enumOptions' => $enumOptions, 'descriptor' => $descriptor];

        foreach ($this->descriptorSubfieldTypeMap($path, $type, $descriptor) as $subPath => $subType) {
            $index[$subPath] ??= ['type' => $subType, 'enumOptions' => null, 'descriptor' => null];
        }
    }

    /**
     * Every SUBFIELD path a variable's type + descriptor makes referenceable, as `path => flat type` —
     * the ONE source both the write-validation reference index and the runtime type map enumerate from,
     * so the two can never disagree (nor drift from what the resolver actually reads):
     *   - a FILE composite's fixed {id,name,type,size,url} (fileSubfieldTypeMap),
     *   - an OBJECT container's declared `fields`, recursively (objectSubfieldTypeMap).
     * Anything else adds nothing.
     *
     * @param  array<string, mixed>|null  $descriptor
     * @return array<string, VariableType>
     */
    private function descriptorSubfieldTypeMap(string $path, VariableType $type, ?array $descriptor): array
    {
        return $this->fileSubfieldTypeMap($path, $type) + $this->objectSubfieldTypeMap($path, $descriptor);
    }

    /**
     * The `<file>.<subfield>` PATH → type entries for a FILE variable at $path — empty for any non-file
     * type. The subfield SET + types come from the single source VariableType::fileSubfieldTypes
     * (id/name/type/url=text, size=number), so the catalog descriptor, the write-validation reference
     * index and the runtime type map can never disagree. NOT emitted for a REPEATER (a text `object`
     * container here): per-element subfield access is the deferred R2 loop.
     *
     * @return array<string, VariableType>
     */
    private function fileSubfieldTypeMap(string $path, VariableType $type): array
    {
        if ($type !== VariableType::FILE) {
            return [];
        }

        $map = [];

        foreach (VariableType::fileSubfieldTypes() as $subfield => $subType) {
            $map[$path . '.' . $subfield] = $subType;
        }

        return $map;
    }

    /**
     * The `<object>.<key>` PATH → flat-type entries an OBJECT container's descriptor declares, RECURSING
     * into nested containers (phase-2c). Mirrors fileSubfieldTypeMap exactly: it only widens which PATHS
     * are known — no new catalog `variables[]` entry is emitted (the editor already builds its picker
     * children from `descriptor.fields`).
     *
     * This is what makes an object GLOBAL's subfield (`globals.address.city`) a KNOWN reference: unlike a
     * form section — whose leaves are ALSO emitted flat by formFieldVariables — a global object is
     * self-contained, so its interior existed only inside the descriptor. The runtime already resolves
     * these paths (a plain whitelisted Arr::get over the injected `globals` map), so this closes the
     * write-side gap without touching the resolver. Each child's type is the flat wire type recovered
     * from its own descriptor (fromDescriptor → flatType), so a nested container degrades to text exactly
     * like its parent and nothing downstream can ever receive `object`.
     *
     * NOT recursed for a REPEATER (`object` + `array:true`): per-element access is the deferred R2 loop,
     * so a repeater's element subfield stays unknown → rejected at write, at every nesting level. The
     * repeater's own path is still enumerated (as a degraded text container), like every other child.
     * Fail-soft on a malformed stored descriptor: a field without a safe key / child descriptor is
     * skipped rather than throwing.
     *
     * @param  array<string, mixed>|null  $descriptor
     * @return array<string, VariableType>
     */
    private function objectSubfieldTypeMap(string $path, ?array $descriptor): array
    {
        if (!$this->isObjectContainer($descriptor)) {
            return [];
        }

        $map = [];

        foreach ($descriptor['fields'] as $field) {
            $key = is_array($field) ? ($field['key'] ?? null) : null;
            $childDescriptor = is_array($field) && is_array($field['descriptor'] ?? null) ? $field['descriptor'] : null;

            if (!is_string($key) || $key === '' || $childDescriptor === null) {
                continue;
            }

            $childPath = $path . '.' . $key;
            $childType = $this->flatType(VariableType::fromDescriptor($childDescriptor));

            $map[$childPath] = $childType;
            $map += $this->descriptorSubfieldTypeMap($childPath, $childType, $childDescriptor);
        }

        return $map;
    }

    /**
     * Whether a descriptor is a NON-ARRAY object container carrying `fields` — the only shape whose
     * interior is referenceable (an `array:true` object is a repeater: per-element access is deferred).
     * Matches the editor's own picker-tree rule (isObjectContainer in workflowVariables.ts), so what the
     * tree offers is exactly what the index accepts.
     *
     * @param  array<string, mixed>|null  $descriptor
     */
    private function isObjectContainer(?array $descriptor): bool
    {
        return $descriptor !== null
            && ($descriptor['base'] ?? null) === VariableType::OBJECT->value
            && ($descriptor['array'] ?? false) !== true
            && is_array($descriptor['fields'] ?? null);
    }

    /**
     * The ELEMENT-SCOPE subfield index for an array<object>/array<file> descriptor (array-ops wave 3):
     * `element.<subfield> => flat type`, the synthetic scope an element pipeline exposes so its ops can
     * reference `element.<field>` (a repeater row's fields, a file's {id,name,type,size,url}).
     *
     * This is the ONE place descriptorSubfieldTypeMap is allowed to DESCEND into a repeater — and it does
     * so cleanly, because the repeater's ELEMENT descriptor is a NON-array object (`array:false`), which
     * objectSubfieldTypeMap already descends exactly like a form section. The descent is scoped to the
     * synthetic `element` root, ONLY for this pipeline's write-validation + resolution — a repeater's
     * subfield is STILL not a global reference (the reference index / condition table refuse it, unchanged).
     * A scalar/enum element array has no subfields and yields none. Nested repeaters inside the element
     * stay fail-closed (objectSubfieldTypeMap refuses an `array:true` child), matching the doctrine.
     *
     * @param  array<string, mixed>  $arrayDescriptor  the ARRAY variable's descriptor ({base, array:true, fields|elementDescriptor})
     * @return array<string, VariableType>
     */
    public function elementScopeSubfields(array $arrayDescriptor): array
    {
        $element = is_array($arrayDescriptor['elementDescriptor'] ?? null)
            ? $arrayDescriptor['elementDescriptor']
            : array_merge($arrayDescriptor, ['array' => false]);

        $base = $element['base'] ?? null;

        if ($base === VariableType::OBJECT->value && is_array($element['fields'] ?? null)) {
            return $this->objectSubfieldTypeMap('element', $element);
        }

        if ($base === VariableType::FILE->value) {
            return $this->fileSubfieldTypeMap('element', VariableType::FILE);
        }

        return [];
    }

    /**
     * Step-output variables keyed by the workflow's ACTUAL step keys (`steps.<key>.<output>` → type),
     * from each step class's static output descriptors. A step with an unknown type / missing key is
     * skipped. This is the runtime/validation counterpart to stepOutputVariables() (which keys by
     * step TYPE for the editor catalog).
     *
     * @param  array<int, mixed>  $steps
     * @return array<string, VariableType>
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
                $this->variable('trigger', 'trigger.scheduled_at', 'Scheduled at', VariableType::DATE),
            ],
            WorkflowTriggerType::FORM_SUBMITTED => [
                $this->variable('trigger', 'trigger.submission.id', 'Submission ID', VariableType::TEXT),
                $this->variable('trigger', 'trigger.form.id', 'Form ID', VariableType::TEXT),
                $this->variable('trigger', 'trigger.form.name', 'Form name', VariableType::TEXT),
                $this->variable('trigger', 'trigger.source', 'Source', VariableType::ENUM, enumOptions: ['manual', 'task']),
                $this->variable('trigger', 'trigger.submitted_at', 'Submitted at', VariableType::DATE),
                $this->variable('trigger', 'trigger.task.id', 'Task ID', VariableType::TEXT, nullable: true),
            ],
        };
    }

    /**
     * Per-form FIELD variables derived from the form's JSON schema. Each usable input becomes a
     * `trigger.fields.<fieldPath>` LEAF variable typed by its element kind. Repeaters have no flat leaf
     * and are skipped in this pass.
     *
     * The human `name` is the element CONFIG label — read from the SAME content walk (contentMetadata)
     * the container descriptors' child labels come from, so one form no longer shows two naming styles
     * (raw field ids on the flat leaves, real labels on the container children). The JSON schema keeps
     * no per-field label — extractFieldPaths seeds its `label` with the field KEY — so labelFor() is
     * now only the FALLBACK for a path the content walk did not record. DISPLAY-only: the identity is
     * `path`, and nothing server-side matches on `name`.
     *
     * ADDITIVE (phase-2a): the STRUCTURAL container variables (a SECTION as an `object`, a REPEATER as
     * an `array<object>`) are appended by containerVariables() — the flat leaves above are UNCHANGED, so
     * every existing `section.subfield` reference keeps resolving. See containerVariables().
     *
     * NULLABILITY comes from the element CONTENT, not the schema: an OPTIONAL field (no
     * `config.required`) may be absent/empty in a submission, so its variable is `nullable` — the flag
     * that makes the editor offer a per-reference "default when empty" literal. See contentMetadata().
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
        // The rest of the config-only metadata (labels + per-path REQUIRED-ness), one walk per form.
        $metadata = $this->contentMetadata($form);

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
                $metadata['labels'][$info['path']] ?? $this->labelFor($fieldSchema, $info['path']),
                $type,
                enumOptions: $enumOptions,
                nullable: $this->isNullableField($metadata, $info['path']),
                fieldId: $info['path'],
                descriptorOptions: $optionLabels[$info['path']] ?? null,
            );
        }

        return array_merge($variables, $this->containerVariables($schema, $metadata));
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
     * inside repeaters) and the per-field REQUIRED-ness come from the element config tree — the JSON
     * schema keeps neither in a per-field-path form. The shared InteractsWithFormSchema trait is
     * deliberately left untouched (Forms depend on its exact contract).
     *
     * @param  array<string, mixed>  $schema  the form's JSON schema (passed in so it is built once)
     * @param  array{labels: array<string, string>, options: array<string, array<int, array{key: string, label: string}>>, required: array<string, bool>}  $metadata
     * @return array<int, array<string, mixed>>
     */
    private function containerVariables(array $schema, array $metadata): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        $variables = [];

        foreach ($properties as $key => $childSchema) {
            if (!is_array($childSchema)) {
                continue;
            }

            $path = (string) $key;
            $descriptor = $this->containerDescriptorFor($childSchema, $path, $metadata);

            if ($descriptor === null) {
                continue; // a plain scalar/enum/multi leaf — already emitted by the leaf pass above.
            }

            $variables[] = $this->containerVariable(
                'trigger.fields.' . $path,
                $metadata['labels'][$path] ?? $path,
                $descriptor,
            );
        }

        return $variables;
    }

    /**
     * The object DESCRIPTOR for a schema fragment when it is a CONTAINER — a SECTION (`type:object`) or
     * a REPEATER (`type:array` whose `items` are objects) — else null (a scalar/enum/multi leaf, whose
     * descriptor the leaf pass owns). A repeater yields `array:true`, a section `array:false`; both
     * carry an ordered `fields` list built recursively from the child schema fragments + the metadata.
     *
     * A container's OWN `nullable` stays FALSE by design: a grouping node is not an answerable field —
     * `config.required` is a per-INPUT flag, a section/repeater has none, and the container is always
     * structurally present (an unanswered section is an empty object, an empty repeater an empty list).
     * "Default when empty" is a per-LEAF affordance, so the nullability that matters lives on the
     * children (each built with its own required-ness below).
     *
     * @param  array<string, mixed>  $schema
     * @param  array{labels: array<string, string>, options: array<string, array<int, array{key: string, label: string}>>, required: array<string, bool>}  $metadata
     * @return array<string, mixed>|null
     */
    private function containerDescriptorFor(array $schema, string $path, array $metadata): ?array
    {
        // SECTION → an object whose `properties` are the children.
        if (($schema['type'] ?? null) === 'object' && is_array($schema['properties'] ?? null)) {
            return VariableType::OBJECT->descriptor(
                nullable: false,
                fields: $this->buildContainerFields($schema['properties'], $path, $metadata),
                array: false,
            );
        }

        // REPEATER → an array whose `items` are an object; the element fields live under items.properties.
        if (($schema['type'] ?? null) === 'array'
            && ($schema['items']['type'] ?? null) === 'object'
            && is_array($schema['items']['properties'] ?? null)
        ) {
            return VariableType::OBJECT->descriptor(
                nullable: false,
                fields: $this->buildContainerFields($schema['items']['properties'], $path, $metadata),
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
     * @param  array{labels: array<string, string>, options: array<string, array<int, array{key: string, label: string}>>, required: array<string, bool>}  $metadata
     * @return array<int, array{key: string, label: string, descriptor: array<string, mixed>}>
     */
    private function buildContainerFields(array $properties, string $prefix, array $metadata): array
    {
        $fields = [];

        foreach ($properties as $key => $childSchema) {
            if (!is_array($childSchema)) {
                continue;
            }

            $childKey = (string) $key;
            $childPath = $this->joinPath($prefix, $childKey);

            $descriptor = $this->containerDescriptorFor($childSchema, $childPath, $metadata)
                ?? $this->leafDescriptor($childSchema, $childPath, $metadata);

            $fields[] = [
                'key' => $childKey,
                'label' => $metadata['labels'][$childPath] ?? $childKey,
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
     * `nullable` is the SAME per-path required-ness the flat leaf variables use, so a section child
     * reads identically whether the editor reaches it through the flat `section.leaf` variable or
     * through the container descriptor's `fields` tree (a repeater's element fields, which exist ONLY
     * here, get the same honest flag).
     *
     * @param  array<string, mixed>  $schema
     * @param  array{labels: array<string, string>, options: array<string, array<int, array{key: string, label: string}>>, required: array<string, bool>}  $metadata
     * @return array<string, mixed>
     */
    private function leafDescriptor(array $schema, string $path, array $metadata): array
    {
        $type = $this->mapSchemaToVariableType($schema);
        $options = $metadata['options'][$path] ?? $this->optionsFromValues($this->enumOptions($type, $schema));

        return $type->descriptor($options, $this->isNullableField($metadata, $path));
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
            'type' => $this->flatType(VariableType::OBJECT)->value,
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
        return Constant::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Constant $global): array => $this->globalVariable($global))
            ->all();
    }

    /**
     * The workspace's globals as a `{<key>: <literal value>}` map — the payload the step runner
     * injects into the run context under the `globals` root, and the tier the trigger gate
     * (WorkflowConditionEngine) reads a `globals.*` condition source from. Values are the STORED
     * literals (scalar, list, object, or null); the resolver reads them by a plain whitelisted dotted
     * lookup.
     *
     * NO ACTIVE WORKSPACE ⇒ NO GLOBALS (security hardening). WorkspaceScope deliberately leaves a
     * query UNCONSTRAINED when no workspace context is set (queue jobs, console, login), and this
     * method COLLAPSES its rows into one `key => value` map — so without this guard every workspace's
     * globals would flatten into a single map (last row wins) and a run / a gate could read a FOREIGN
     * workspace's constant. There is no tenant to read globals FOR in that state, so the honest answer
     * is none. Both db_modes are covered: `hasWorkspace()` (not `isShared()`) is the question, because
     * an own-DB workspace is isolated by its connection and a shared one by the scope, and neither is
     * true with no workspace at all.
     *
     * @return array<string, mixed>
     */
    public function globalValues(): array
    {
        if (!$this->tenant->hasWorkspace()) {
            return [];
        }

        return Constant::query()
            ->get()
            ->mapWithKeys(fn (Constant $global): array => [$global->key => $global->value])
            ->all();
    }

    /**
     * The workspace's custom FUNCTIONS as pipeline OPERATIONS (CustomFunctionOperation VOs) — the runtime
     * + write-validation view. Threaded into the OperationExecutor (so a `fn:<uuid>` op EXECUTES) via the
     * run-context FunctionScope, and into the workflow write-validator's refCtx (so a workflow pipeline
     * referencing a function type-checks). Mirrors globalValues()'s workspace guard: NO active workspace ⇒
     * NONE (a queue/console read must never leak a foreign workspace's functions), ordered by name for
     * stability.
     *
     * @return array<int, CustomFunctionOperation>
     */
    public function customFunctionOperations(): array
    {
        if (!$this->tenant->hasWorkspace()) {
            return [];
        }

        return CustomFunction::query()
            ->orderBy('name')
            ->get()
            ->map(fn (CustomFunction $function): CustomFunctionOperation => CustomFunctionOperation::fromModel($function))
            ->all();
    }

    /**
     * The workspace's custom functions as label-carrying operation-catalog entries, MERGED into the
     * operations catalog forContext() exposes so the FE add-menu offers them. Each is
     * `{id:'fn:<uuid>', input:input_type, output:return_type, args:[{id:argName, type:argType}], label:
     * name, description}` — label/description are ADDITIVE, function-only wire fields (a built-in has no
     * label: the FE localizes those, but reads a user function's own name/description verbatim).
     * Workspace-guarded like globalValues().
     *
     * @return array<int, array<string, mixed>>
     */
    private function functionCatalog(): array
    {
        if (!$this->tenant->hasWorkspace()) {
            return [];
        }

        return CustomFunction::query()
            ->orderBy('name')
            ->get()
            ->map(fn (CustomFunction $function): array => [
                'id' => 'fn:' . $function->getKey(),
                'input' => (VariableType::tryFrom((string) $function->input_type) ?? VariableType::TEXT)->value,
                'output' => (VariableType::tryFrom((string) $function->return_type) ?? VariableType::TEXT)->value,
                'args' => $this->functionArgWire($function),
                'label' => $function->name,
                'description' => $function->description,
            ])
            ->all();
    }

    /**
     * The wire arg descriptors for a function op — `[{id: argName, type: argType}]`, the arg's DECLARED
     * value type (not a runtime control) so the FE renders each arg's input by its real type. A malformed
     * arg entry is skipped, mirroring CustomFunctionOperation::fromModel.
     *
     * @return array<int, array{id: string, type: string}>
     */
    private function functionArgWire(CustomFunction $function): array
    {
        $args = [];

        foreach (is_array($function->args) ? $function->args : [] as $arg) {
            if (!is_array($arg) || !is_string($arg['name'] ?? null)) {
                continue;
            }

            $type = VariableType::tryFrom((string) ($arg['type'] ?? '')) ?? VariableType::TEXT;
            $args[] = ['id' => $arg['name'], 'type' => $type->value];
        }

        return $args;
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
    private function globalVariable(Constant $global): array
    {
        $descriptor = is_array($global->descriptor) ? $global->descriptor : [];
        $type = VariableType::fromDescriptor($descriptor);

        $variable = [
            'source' => 'globals',
            'path' => 'globals.' . $global->key,
            'name' => $global->name,
            'type' => $this->flatType($type)->value,
            'descriptor' => $descriptor,
        ];

        // Surface the enum option KEYS on the flat wire (mirrors a form enum/multi variable) only
        // when the descriptor is actually enum-based — a plain array<text> global carries none.
        $enumOptions = $this->descriptorOptionKeys($descriptor);

        if ($enumOptions !== null) {
            $variable['enumOptions'] = $enumOptions;
        }

        return $variable;
    }

    /**
     * The FORM half of the condition-source table: the subset of form-field variables usable in the
     * condition builder, each carrying its field_id, label, type, options, and the operator set
     * valid for its type (VariableType::operators — the same set the validator enforces).
     * The GLOBALS half is built by globalConditionFields(); both go through conditionField().
     *
     * `label` is the variable's own `name`, so the condition builder and the variable picker always
     * show one form field under ONE name. Display-only: the condition-tree validator keys this table
     * by `path` and checks `type`/`enumOptions`, never the label.
     *
     * @param  array<int, array<string, mixed>>  $fieldVariables
     * @return array<int, array<string, mixed>>
     */
    private function conditionFields(array $fieldVariables): array
    {
        // A NON-ARRAY object container (a form SECTION) is NOT a condition source — it has no meaningful
        // operator set and its flat `type` degrades to text (mis-advertising it as text-conditionable).
        // But an ARRAY container — a repeater (array<object>) or an array<file> field — IS conditionable
        // (F2): an array op gates on the value being an array, so the pipeline builder can filter/count/map
        // it. Drop only the non-array object containers; every flat leaf and every array carrier stays.
        $conditionable = array_values(array_filter(
            $fieldVariables,
            fn (array $variable): bool => !$this->isNonArrayObjectContainer($variable['descriptor'] ?? null),
        ));

        return array_map(fn (array $variable): array => $this->fieldConditionField($variable), $conditionable);
    }

    /**
     * One FORM-FIELD condition source from a catalog variable (F2). An ARRAY-of-* source — a repeater
     * (array<object>), an array<file>, a checklist (array<enum>/multi) — rides the `multi` wire type (the
     * ONE array-carrying flat type, so the legacy `source_type === entry.type` equality and the validator's
     * flat rooting keep working) and threads its FULL descriptor so the condition-pipeline walk can gate
     * array ops on `array===true` and validate element pipelines + `element.<subfield>` refs. A scalar/enum/
     * date leaf keeps its own flat wire type and carries no descriptor (unchanged).
     *
     * @param  array<string, mixed>  $variable
     * @return array<string, mixed>
     */
    private function fieldConditionField(array $variable): array
    {
        $descriptor = is_array($variable['descriptor'] ?? null) ? $variable['descriptor'] : [];
        $isArray = ($descriptor['array'] ?? false) === true;
        // A container variable carries no `field_id` of its own — derive it from the catalog path
        // (`trigger.fields.<id>` → `<id>`), the SAME unprefixed id a flat leaf and the runtime payload use.
        $fieldId = $variable['field_id'] ?? $this->fieldIdFromPath((string) ($variable['path'] ?? ''));

        return $this->conditionField(
            'trigger',
            'fields.' . $fieldId,
            $variable['name'],
            $isArray ? VariableType::MULTI : VariableType::from($variable['type']),
            $variable['enumOptions'] ?? null,
            fieldId: $fieldId,
            descriptor: $isArray ? $descriptor : null,
        );
    }

    /**
     * Whether a descriptor is a NON-ARRAY object container (a form SECTION) — the ONE shape dropped from the
     * condition sources (no operator set, degraded flat type). An `array:true` object is a repeater, which
     * F2 keeps as a source.
     *
     * @param  array<string, mixed>|null  $descriptor
     */
    private function isNonArrayObjectContainer(?array $descriptor): bool
    {
        return is_array($descriptor)
            && ($descriptor['base'] ?? null) === VariableType::OBJECT->value
            && ($descriptor['array'] ?? false) !== true;
    }

    /** The unprefixed field id of a `trigger.fields.<id>` catalog path (the container-variable case). */
    private function fieldIdFromPath(string $path): string
    {
        $prefix = 'trigger.fields.';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /**
     * The workspace GLOBALS as CONDITION SOURCES (B6) — the second half of the condition-source table,
     * so a workflow can be gated on a workspace constant and not only on the submitted form.
     *
     * A global is addressed by its FULL catalog path (`globals.<key>`), unlike a form field's legacy
     * unprefixed `fields.<id>` — see conditionFieldsFor for the one vocabulary rule. A NON-array OBJECT
     * global is not itself conditionable (an object has no meaningful operator set — the same reason
     * conditionFields drops a form section container), so it is RECURSED into and each declared leaf becomes
     * its own `globals.<key>.<field>` source. An `array<object>` global (F2), like a form repeater, IS
     * conditionable via the pipeline builder — emitted as a source riding `multi` + its full descriptor. An
     * `array<scalar>` global rides `multi` exactly like a checklist field and IS conditionable.
     *
     * @param  array<int, array<string, mixed>>  $globalVariables  the already-read globalVariables()
     * @return array<int, array<string, mixed>>
     */
    private function globalConditionFields(array $globalVariables): array
    {
        $fields = [];

        foreach ($globalVariables as $variable) {
            $this->collectGlobalConditionFields(
                (string) ($variable['path'] ?? ''),
                (string) ($variable['name'] ?? ''),
                is_array($variable['descriptor'] ?? null) ? $variable['descriptor'] : [],
                $fields,
            );
        }

        return $fields;
    }

    /**
     * Append the condition sources one global (or one of its object leaves) offers. An ARRAY<object> global
     * is emitted as ONE `multi` source carrying its full descriptor (F2). A NON-array OBJECT container
     * offers none of its own and recurses into its declared `fields` — reusing isObjectContainer, so exactly
     * the interiors the reference index already knows are conditionable. A child's label is qualified with
     * its parent's ("Firma · Miasto") so a flat picker still reads unambiguously. Fail-soft on a malformed
     * stored descriptor.
     *
     * @param  array<string, mixed>  $descriptor
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function collectGlobalConditionFields(string $path, string $label, array $descriptor, array &$fields): void
    {
        if (($descriptor['base'] ?? null) === VariableType::OBJECT->value) {
            // An ARRAY<object> global (repeater-like) IS conditionable via the pipeline builder (F2) — emit
            // it as a source riding the `multi` wire type + its FULL descriptor, exactly like a form repeater,
            // so the condition-pipeline walk gates array ops on `array===true` and validates its element
            // pipelines / `element.<subfield>` refs. A NON-array object (section-like) has no operator set of
            // its own, so recurse into its declared leaves (each a scalar condition source).
            if (($descriptor['array'] ?? false) === true) {
                $fields[] = $this->conditionField('globals', $path, $label, VariableType::MULTI, null, descriptor: $descriptor);

                return;
            }

            if (!$this->isObjectContainer($descriptor)) {
                return; // a malformed object with no declared `fields` — nothing conditionable
            }

            foreach ($descriptor['fields'] as $field) {
                $key = is_array($field) ? ($field['key'] ?? null) : null;
                $childDescriptor = is_array($field) && is_array($field['descriptor'] ?? null) ? $field['descriptor'] : null;

                if (!is_string($key) || $key === '' || $childDescriptor === null) {
                    continue;
                }

                $childLabel = is_array($field) && is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : $key;

                $this->collectGlobalConditionFields($path . '.' . $key, $label . ' · ' . $childLabel, $childDescriptor, $fields);
            }

            return;
        }

        $fields[] = $this->conditionField(
            'globals',
            $path,
            $label,
            $this->flatType(VariableType::fromDescriptor($descriptor)),
            $this->descriptorOptionKeys($descriptor),
        );
    }

    /**
     * One CONDITION SOURCE descriptor. ONE builder for both vocabularies so a form field and a global
     * can never grow different shapes: `source` names which catalog root the `path` belongs to
     * (`trigger` = the form's own fields, `globals` = a workspace constant), `path` is the identity the
     * write-validator matches, and `field_id` stays a FORM-only key (a global has no field id).
     *
     * @param  array<int, string>|null  $enumOptions
     * @param  array<string, mixed>|null  $descriptor  the source's REAL structured descriptor (F2) — threaded
     *                                                 for an array-of-* source so the condition-pipeline walk
     *                                                 keeps its array-ness + element `fields`; null otherwise
     * @return array<string, mixed>
     */
    private function conditionField(
        string $source,
        string $path,
        string $label,
        VariableType $type,
        ?array $enumOptions,
        ?string $fieldId = null,
        ?array $descriptor = null,
    ): array {
        $field = ['path' => $path];

        if ($fieldId !== null) {
            $field['field_id'] = $fieldId;
        }

        $field['label'] = $label;
        $field['type'] = $type->value;
        $field['operators'] = $type->operators();

        if ($enumOptions !== null) {
            $field['enumOptions'] = $enumOptions;
        }

        $field['source'] = $source;

        if ($descriptor !== null) {
            $field['descriptor'] = $descriptor;
        }

        return $field;
    }

    /**
     * The flat option-KEY list an ENUM-based descriptor carries (null for any other base) — the same
     * `enumOptions` wire shape globalVariable() emits, reused for a global's condition descriptor and
     * for its object LEAVES (which have no catalog variable of their own to read it from).
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<int, string>|null
     */
    private function descriptorOptionKeys(array $descriptor): ?array
    {
        if (($descriptor['base'] ?? null) !== VariableType::ENUM->value) {
            return null;
        }

        return array_values(array_map(
            fn ($option): string => (string) (is_array($option) ? ($option['key'] ?? '') : $option),
            is_array($descriptor['options'] ?? null) ? $descriptor['options'] : [],
        ));
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
    private function mapSchemaToVariableType(array $schema): VariableType
    {
        $jsonType = $schema['type'] ?? 'string';

        // A file input carries format:'file'. Checked FIRST so it wins over every shape below —
        // a future multi-file field could arrive as an array and must still read as FILE, never
        // as a plain multi-select.
        if (($schema['format'] ?? null) === 'file') {
            return VariableType::FILE;
        }

        if ($jsonType === 'array') {
            return VariableType::MULTI; // multi-select / checklist
        }

        if ($jsonType === 'number' || $jsonType === 'integer') {
            return VariableType::NUMBER;
        }

        if ($jsonType === 'boolean') {
            return VariableType::BOOLEAN;
        }

        if (($schema['format'] ?? null) === 'date') {
            return VariableType::DATE;
        }

        // A time-of-day input (the form TIME element) carries format:'time'.
        if (($schema['format'] ?? null) === 'time') {
            return VariableType::TIME;
        }

        // A single-select carries an enum of allowed values on a string schema.
        if (!empty($schema['enum']) && is_array($schema['enum'])) {
            return VariableType::ENUM;
        }

        return VariableType::TEXT; // short/long text, url, time, image, unknown
    }

    /**
     * The option list for an enum/multi field (from the schema's `enum`, or an array items'
     * `enum`), string-normalized. null for non-optioned types.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, string>|null
     */
    private function enumOptions(VariableType $type, array $schema): ?array
    {
        if ($type === VariableType::ENUM && !empty($schema['enum']) && is_array($schema['enum'])) {
            return array_values(array_map(fn ($v) => (string) $v, $schema['enum']));
        }

        if ($type === VariableType::MULTI) {
            $itemEnum = $schema['items']['enum'] ?? null;

            if (is_array($itemEnum) && $itemEnum !== []) {
                return array_values(array_map(fn ($v) => (string) $v, $itemEnum));
            }
        }

        return null;
    }

    /**
     * The SCHEMA-derived label for a field — the FALLBACK used only when the content walk recorded no
     * label for the path (see formFieldVariables, which prefers the element config label). The JSON
     * schema carries no real per-field label: extractFieldPaths seeds `label` with the field KEY, so
     * this effectively yields the field id; the schema's own `title`/`description` win when present.
     * Falls back to the path.
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
        VariableType $type,
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
    private function flatType(VariableType $type): VariableType
    {
        return match ($type) {
            VariableType::TIME, VariableType::OBJECT => VariableType::TEXT,
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
     * The per-path metadata that lives ONLY in a form's element CONTENT — one walk, three maps:
     *   - `labels`   the human field/container labels (config.label/name),
     *   - `options`  the enum/multi option `{key,label}` lists,
     *   - `required` the per-INPUT `config.required` flag (the source of variable NULLABILITY).
     * Keyed by the full dotted field path (see collectContentMetadata). No DB work.
     *
     * @return array{labels: array<string, string>, options: array<string, array<int, array{key: string, label: string}>>, required: array<string, bool>}
     */
    private function contentMetadata(Form $form): array
    {
        $labels = [];
        $options = [];
        $required = [];

        $this->collectContentMetadata(
            is_array($form->content) ? $form->content : [],
            '',
            $labels,
            $options,
            $required,
        );

        return ['labels' => $labels, 'options' => $options, 'required' => $required];
    }

    /**
     * Collect, for a form's element tree, the human FIELD labels (config.label/name), the enum/multi
     * OPTION labels, and each input's REQUIRED flag — keyed by full dotted path and INCLUDING container
     * interiors (sections AND repeaters). Separate from collectOptionLabels (which powers the flat leaf
     * variables' option labels and deliberately excludes repeaters), so neither the leaf option
     * behaviour nor the shared trait changes. The path shape mirrors buildJsonSchema (sections/repeaters
     * nest under their id, grids flatten), so each key aligns with the JSON-schema property path both
     * the leaf pass (extractFieldPaths) and the container walk read. No DB work.
     *
     * REQUIRED-ness is read here — from `config.required` — because the JSON schema keeps it only as a
     * per-OBJECT-level `required` name list (Illuminate\JsonSchema\Serializer derives it from the child
     * types), never on the field fragment itself, and extractFieldPaths does not carry the parent level
     * down. Reading the same config key FormElementType::toJsonSchema reads keeps one source of truth.
     *
     * @param  array<int, mixed>  $elements
     * @param  array<string, string>  $fieldLabels
     * @param  array<string, array<int, array{key: string, label: string}>>  $optionLabels
     * @param  array<string, bool>  $requiredPaths
     */
    private function collectContentMetadata(array $elements, string $prefix, array &$fieldLabels, array &$optionLabels, array &$requiredPaths): void
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
                        $this->collectContentMetadata([$column['element']], $prefix, $fieldLabels, $optionLabels, $requiredPaths);
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
            // A container carries NO required flag of its own (see containerDescriptorFor).
            if (in_array($type, [FormElementType::SECTION, FormElementType::REPEATER], true) && is_array($config['children'] ?? null)) {
                $fieldLabels[$path] = $this->elementLabel($config, $id);
                $this->collectContentMetadata($config['children'], $path, $fieldLabels, $optionLabels, $requiredPaths);

                continue;
            }

            // Input field: its human label, its REQUIRED flag, + (for select/checklist) its option labels.
            if ($type !== null && $type->isInputElement()) {
                $fieldLabels[$path] = $this->elementLabel($config, $id);
                // The SAME truthy read FormElementType::toJsonSchema applies when marking the schema
                // property required, so the catalog and the submission validator agree per field.
                $requiredPaths[$path] = (bool) ($config['required'] ?? false);

                if (in_array($type, [FormElementType::SELECT, FormElementType::CHECKLIST], true)) {
                    $options = $this->labeledOptions($config['options'] ?? null);

                    if ($options !== []) {
                        $optionLabels[$path] = $options;
                    }
                }
            }
        }
    }

    /**
     * Whether a form field PATH is NULLABLE: an optional field (no `config.required`) may be absent or
     * empty in a submission, which is exactly when a per-reference "default when empty" literal matters.
     * A REQUIRED field is never nullable.
     *
     * FAIL-SOFT: a path the content walk did not record (an unexpected content shape — the two walks
     * derive their paths the same way, so this should not happen) is treated as optional, mirroring JSON
     * Schema's own rule that a property absent from `required` is optional. The cost of that direction is
     * a harmless unused default control; the opposite direction would silently re-hide the affordance.
     *
     * @param  array{labels: array<string, string>, options: array<string, array<int, array{key: string, label: string}>>, required: array<string, bool>}  $metadata
     */
    private function isNullableField(array $metadata, string $path): bool
    {
        return ($metadata['required'][$path] ?? false) !== true;
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
