<?php

namespace App\Modules\Generator\Services;

use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\VariableCatalog;
use App\Modules\Variables\Support\FunctionScope;

/**
 * The TEMPLATE variable catalog: it layers a template's DECLARED `slots.<name>` typed variables OVER
 * the shared, form-independent authoring surface (globals, custom functions, the operation catalog,
 * the type list) that {@see VariableCatalog} owns. This is the Generator counterpart to the workflow
 * catalog — the two build their OWN domain variables (a workflow: trigger/step/form vars; a template:
 * its declared slots) and DELEGATE the shared pieces to the same VariableCatalog, so they can never
 * disagree on what a global / function / type IS.
 *
 * A slot is a DECLARED typed placeholder ({name, description?, descriptor}), so — unlike a form field
 * whose type is derived from a schema — its catalog variable is built straight from the stored
 * descriptor, exactly as VariableCatalog builds a global's variable from its constant descriptor. The
 * slot variables serve three consumers, all keyed on the `slots.<name>` path: the editor catalog
 * (forSlots), the write-side reference index (referenceIndex) and the runtime type map (typeMap).
 *
 * SUBFIELD scope: the reference index + type map ALSO enumerate the object/file SUBFIELD paths a slot's
 * or global's descriptor declares (`slots.product.name`, `slots.image.url`, `globals.company.city`) via
 * the SHARED descent (VariableCatalog::descriptorSubfieldTypeMap) — the SAME source the workflow catalog
 * uses, never a fork. So a `@[variable]` over a known subfield type-flows from that subfield's REAL base,
 * an UNKNOWN subfield of a known object/file slot is rejected at write (TemplateSlotValidator), and the
 * runtime resolves it by a plain whitelisted Arr::get / file-snapshot collapse. The catalog `variables`
 * carry each slot/global's full `descriptor` (with its `fields`), so the FE variable-tree — the SAME one
 * the workflow editor uses — expands the subfield picker nodes with no extra flat entries. A REPEATER
 * (array<object>) subfield stays deferred (per-element access is the R2 loop), fail-closed at write.
 */
class TemplateVariableCatalog
{
    public function __construct(
        private VariableCatalog $catalog,
    ) {}

    /**
     * The catalog the template editor consumes for a set of DECLARED slots: the `slots.<name>` variables
     * MERGED with the workspace globals, plus the shared operation catalog (built-ins ∪ custom functions)
     * and the variable-type list. Draft-friendly — malformed slots are skipped (fail-soft), so an
     * in-progress template still gets a usable catalog.
     *
     * PART-AWARE (Phase A, cross-part context): when the caller scopes the request to a specific content-type
     * part (passing the ordered keys of the parts declared BEFORE it), the EARLIER parts are also offered as
     * `parts.<earlierKey>` TEXT variables — so an author can reference an earlier part's generated output.
     * The set is EARLIER-ONLY (a self/forward reference is never offered → rejected at write), keeping the
     * cross-part graph acyclic by construction. An empty list (the default) offers no `parts.*` — unchanged
     * behavior for callers that don't scope to a part.
     *
     * @param  array<int, mixed>  $slots  the declared slot definitions ([{name, descriptor, description?}])
     * @param  array<int, string>  $earlierPartKeys  the keys of the parts declared BEFORE the current one
     * @return array{variables: array<int, array<string, mixed>>, operations: array<int, array<string, mixed>>, types: array<int, array<string, mixed>>}
     */
    public function forSlots(array $slots, array $earlierPartKeys = []): array
    {
        return [
            'variables' => array_merge(
                $this->slotVariables($slots),
                $this->catalog->globalVariables(),
                $this->partVariables($earlierPartKeys),
            ),
            'operations' => $this->catalog->operations(),
            'types' => $this->catalog->variableTypes(),
        ];
    }

    /**
     * The `parts.<key>` catalog variables for the EARLIER content-type parts (cross-part context, Phase A).
     * Each is a TEXT variable (a part's flattened output — cross-part refs are TEXT-ONLY) whose source EQUALS
     * the root (`parts`, mirroring globals/slots), so the structured-ref path builder and the write-side
     * source whitelist accept it with no special-casing. Carries no descriptor (no structured subfields).
     *
     * @param  array<int, string>  $earlierPartKeys
     * @return array<int, array<string, mixed>>
     */
    public function partVariables(array $earlierPartKeys): array
    {
        $variables = [];

        foreach ($earlierPartKeys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $variables[] = [
                'source' => 'parts',
                'path' => 'parts.' . $key,
                'name' => $key,
                'type' => VariableType::TEXT->value,
            ];
        }

        return $variables;
    }

    /**
     * The `slots.<name>` catalog variables built from the DECLARED slot descriptors — the template's own
     * domain source. Each entry mirrors a global's shape exactly (VariableCatalog::globalVariable): a
     * `slots` source (source EQUALS root, like trigger/steps/globals), the flat wire `type` recovered from
     * the descriptor (fromDescriptor → flatType, so an array/object slot degrades like every other
     * variable and the closed FE union / exhaustive match sites never receive an unknown), the authoritative
     * `descriptor`, and the enum option KEYS when the descriptor is enum-based. A malformed slot is skipped.
     *
     * @param  array<int, mixed>  $slots
     * @return array<int, array<string, mixed>>
     */
    public function slotVariables(array $slots): array
    {
        $variables = [];

        foreach ($this->normalizeSlots($slots) as $slot) {
            $variables[] = $this->slotVariable($slot['name'], $slot['descriptor'], $slot['description']);
        }

        return $variables;
    }

    /**
     * Build the resolver EXECUTION context (+ its runtime typeMap) for a set of DECLARED slots and their
     * filled values — the SINGLE builder BOTH the template PREVIEW ({@see TemplateRenderService::render})
     * and a generation SESSION ({@see GenerationSessionExecutor}) resolve against, so the two can never
     * diverge on what a slot / global / custom function resolves to (the contract's "do NOT duplicate the
     * context-building"). The `{slots, globals}` roots carry the workspace custom functions written in
     * under {@see FunctionScope} so a `fn:<uuid>` op EXECUTES; the typeMap flows each reference (and its
     * declared subfields) through its REAL base type.
     *
     * @param  array<int, mixed>  $slots  the declared slot definitions ([{name, descriptor, description?}])
     * @param  array<string, mixed>  $slotValues  the filled values keyed by slot name
     * @return array{context: array<string, mixed>, typeMap: array<string, VariableType>}
     */
    public function executionContext(array $slots, array $slotValues): array
    {
        $context = ['slots' => $slotValues, 'globals' => $this->globalValues()];
        $functions = $this->customFunctionOperations();
        $context = $functions === [] ? $context : FunctionScope::forFunctions($functions)->writeInto($context);

        return ['context' => $context, 'typeMap' => $this->typeMap($slots)];
    }

    /**
     * The reference INDEX a template's directive/pipeline references are write-validated against: full
     * path → {type, enumOptions, descriptor}. The template's `slots.<name>` entries PLUS the workspace
     * globals (`globals.<key>`), so a `@[variable]` directive over a slot or a global type-flows and an
     * unknown `slots.<name>` is rejected. Mirrors the workflow reference index shape (so PipelineValidator
     * reads it identically), minus the form/step sources a template has none of.
     *
     * PART-AWARE (Phase A): when $earlierPartKeys is non-empty the index ALSO carries a `parts.<earlierKey>`
     * TEXT entry per earlier part, so a body's `@[variable]` over an EARLIER part type-checks and a
     * FORWARD/UNKNOWN part reference (absent from the index) is rejected at write (TemplateSlotValidator).
     * The set is cumulative EARLIER-ONLY, so the cross-part graph the validator accepts is acyclic.
     *
     * @param  array<int, mixed>  $slots
     * @param  array<int, string>  $earlierPartKeys  the keys of the parts declared BEFORE the current one
     * @return array<string, array{type: VariableType, enumOptions: array<int, string>|null, descriptor: array<string, mixed>|null}>
     */
    public function referenceIndex(array $slots, array $earlierPartKeys = []): array
    {
        $index = [];

        foreach ($this->partVariables($earlierPartKeys) as $variable) {
            $index[$variable['path']] = ['type' => VariableType::TEXT, 'enumOptions' => null, 'descriptor' => null];
        }

        foreach (array_merge($this->slotVariables($slots), $this->catalog->globalVariables()) as $variable) {
            $path = $variable['path'];
            $type = VariableType::from($variable['type']);
            $descriptor = $variable['descriptor'] ?? null;

            $index[$path] = [
                'type' => $type,
                'enumOptions' => $variable['enumOptions'] ?? null,
                'descriptor' => $descriptor,
            ];

            // The SUBFIELD leaves the variable's descriptor declares (an object slot/global's `fields`, a
            // file slot's fixed {id,name,type,size,url}) — the SHARED descent, so a `slots.product.name` /
            // `globals.company.city` / `slots.image.url` ref is a KNOWN reference that type-flows from the
            // subfield's own base. A subfield NEVER overwrites a top-level entry (??=), mirroring the
            // workflow reference index exactly (WorkflowVariableCatalogService::addReferenceEntry).
            foreach ($this->catalog->descriptorSubfieldTypeMap($path, $type, $descriptor) as $subPath => $subType) {
                $index[$subPath] ??= ['type' => $subType, 'enumOptions' => null, 'descriptor' => null];
            }
        }

        return $index;
    }

    /**
     * The runtime path → VariableType map the render service hands the resolver so a directive / if-block
     * pipeline executes against each reference's REAL (flat) type — the `slots.<name>` entries plus the
     * workspace globals. Built from the SAME source as the reference index, keeping the two aligned.
     *
     * @param  array<int, mixed>  $slots
     * @return array<string, VariableType>
     */
    public function typeMap(array $slots): array
    {
        $map = [];

        foreach (array_merge($this->slotVariables($slots), $this->catalog->globalVariables()) as $variable) {
            $path = $variable['path'];
            $type = VariableType::from($variable['type']);
            $map[$path] = $type;

            // The SAME descriptor SUBFIELD paths the reference index enumerates, so a preview pipeline over
            // a subfield executes against the subfield's REAL base type (not the container's degraded type).
            // A subfield never overwrites a top-level entry (??=), mirroring the workflow runtime type map.
            foreach ($this->catalog->descriptorSubfieldTypeMap($path, $type, $variable['descriptor'] ?? null) as $subPath => $subType) {
                $map[$subPath] ??= $subType;
            }
        }

        return $map;
    }

    /**
     * The workspace's custom FUNCTIONS as pipeline OPERATIONS — DELEGATED to the shared VariableCatalog
     * (workspace-guarded). Threaded into the write-validator's refCtx (so a template pipeline referencing
     * a `fn:<uuid>` function type-checks) and into the render context's FunctionScope (so it EXECUTES).
     *
     * @return array<int, \App\Modules\Variables\Support\CustomFunctionOperation>
     */
    public function customFunctionOperations(): array
    {
        return $this->catalog->customFunctionOperations();
    }

    /**
     * The workspace's globals as a `{<key>: <literal value>}` map — DELEGATED to the shared VariableCatalog
     * (workspace-guarded). The payload the render service injects into the preview context under the
     * `globals` root.
     *
     * @return array<string, mixed>
     */
    public function globalValues(): array
    {
        return $this->catalog->globalValues();
    }

    /**
     * One `slots.<name>` catalog variable. The display `name` prefers the slot's own description (its
     * human label) and falls back to the identifier; the identity is always the `path`.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<string, mixed>
     */
    private function slotVariable(string $name, array $descriptor, ?string $description): array
    {
        $type = VariableType::fromDescriptor($descriptor);

        $variable = [
            'source' => 'slots',
            'path' => 'slots.' . $name,
            'name' => $description !== null && $description !== '' ? $description : $name,
            'type' => $this->catalog->flatType($type)->value,
            'descriptor' => $descriptor,
        ];

        $enumOptions = $this->catalog->descriptorOptionKeys($descriptor);

        if ($enumOptions !== null) {
            $variable['enumOptions'] = $enumOptions;
        }

        return $variable;
    }

    /**
     * The well-shaped subset of declared slots — {name (non-empty string), descriptor (array), description
     * (string|null)} — skipping any malformed entry so every consumer (catalog / index / type map) reads a
     * clean list without re-checking. This is a SHAPE filter only; the WRITE path validates the descriptor
     * itself (TemplateSlotValidator), and a slot rejected there never reaches persistence.
     *
     * @param  array<int, mixed>  $slots
     * @return array<int, array{name: string, descriptor: array<string, mixed>, description: string|null}>
     */
    private function normalizeSlots(array $slots): array
    {
        $normalized = [];

        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $name = $slot['name'] ?? null;
            $descriptor = $slot['descriptor'] ?? null;

            if (!is_string($name) || $name === '' || !is_array($descriptor)) {
                continue;
            }

            $description = $slot['description'] ?? null;

            $normalized[] = [
                'name' => $name,
                'descriptor' => $descriptor,
                'description' => is_string($description) ? $description : null,
            ];
        }

        return $normalized;
    }
}
