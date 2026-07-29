<?php

namespace App\Modules\Variables\Services;

use App\Modules\Variables\Enums\Operation;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Models\Constant;
use App\Modules\Variables\Models\CustomFunction;
use App\Modules\Variables\Support\CustomFunctionOperation;
use App\Tenancy\TenantContext;

/**
 * The FORM-INDEPENDENT half of a variable catalog — the shared authoring surface every catalog builds
 * ON TOP of its own domain variables: the variable-TYPE vocabulary, the workspace GLOBALS (both as
 * catalog variables and as the injectable `{key:value}` map), the workspace custom FUNCTIONS (both the
 * runtime operation VOs and the label-carrying add-menu entries), and the merged OPERATION catalog.
 *
 * It owns NO domain derivation: a workflow catalog layers trigger/step/form-field variables over this,
 * and (R2) a template catalog layers its declared `slots.<name>` variables over this — each delegating
 * the pieces here so the two can never disagree on what a global/function/type IS. This is the DOWN
 * move that lets both Workflows and Generator depend on Variables and never on each other (extracted
 * from the workflow catalog in R2 PR 1a — byte-identical output).
 *
 * Every DB read is workspace-scoped (WorkspaceScope / the tenant connection), and the two that collapse
 * rows into one map (globalValues / the function reads) guard on an ACTIVE workspace so no console /
 * queue read can leak a foreign workspace's constants or functions.
 */
class VariableCatalog
{
    public function __construct(
        private TenantContext $tenant,
    ) {}

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
     * The built-in OPERATION catalog PLUS the workspace's custom functions, so the FE add-menu offers a
     * function like any other op (filtered by input type). ADDITIVE: the built-ins are unchanged and the
     * functions append after them (functionCatalog). This is the merge a domain catalog exposes as its
     * `operations` wire field.
     *
     * @return array<int, array<string, mixed>>
     */
    public function operations(): array
    {
        return array_merge(Operation::catalog(), $this->functionCatalog());
    }

    /**
     * The workspace's GLOBAL variables — user-created LITERAL constants — as catalog variables. A
     * `globals` source (form-independent: composed for every context), each entry `globals.<key>`
     * carrying the stored `descriptor` (the authoritative type), the degraded flat wire `type` (via
     * flatType, recovered from the descriptor), and — for an enum base — the option key list. Read
     * through the model, so WorkspaceScope / the tenant connection isolate the active workspace in both
     * db_modes; ordered by name for a stable catalog.
     *
     * Kept a clean ADDITIONAL source: it does NOT touch any domain container walk, condition field, or
     * existing variable. A global is not a condition source (a domain catalog decides that), matching a
     * step output.
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
     * The workspace's globals as a `{<key>: <literal value>}` map — the payload the step runner injects
     * into the run context under the `globals` root, and the tier the trigger gate reads a `globals.*`
     * condition source from. Values are the STORED literals (scalar, list, object, or null); the resolver
     * reads them by a plain whitelisted dotted lookup.
     *
     * NO ACTIVE WORKSPACE ⇒ NO GLOBALS (security hardening). WorkspaceScope deliberately leaves a query
     * UNCONSTRAINED when no workspace context is set (queue jobs, console, login), and this method
     * COLLAPSES its rows into one `key => value` map — so without this guard every workspace's globals
     * would flatten into a single map (last row wins) and a run / a gate could read a FOREIGN workspace's
     * constant. There is no tenant to read globals FOR in that state, so the honest answer is none. Both
     * db_modes are covered: `hasWorkspace()` (not `isShared()`) is the question, because an own-DB
     * workspace is isolated by its connection and a shared one by the scope, and neither is true with no
     * workspace at all.
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
     * run-context FunctionScope, and into a write-validator's refCtx (so a pipeline referencing a function
     * type-checks). Mirrors globalValues()'s workspace guard: NO active workspace ⇒ NONE (a queue/console
     * read must never leak a foreign workspace's functions), ordered by name for stability.
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
     * The back-compat FLAT `type` wire value for a variable. TIME and OBJECT (phase-2a) degrade to TEXT
     * — the same LOUD tripwire: the variable resolver, condition evaluator, and operation executor each
     * dispatch on an EXHAUSTIVE match over the legacy 7 types (no default arm), and the FE mirrors a
     * closed 7-member union, so surfacing `time`/`object` on the flat wire would be a runtime
     * UnhandledMatchError / FE break. The structured `descriptor` carries the real base instead (`time`,
     * or `object` with its `fields`). Every other type is itself. Retiring these shims is a deferred
     * runtime-semantics slice (and, for OBJECT, R2-Generator's per-element loop execution).
     *
     * Public so a domain catalog degrades its OWN variables through the SAME single rule.
     */
    public function flatType(VariableType $type): VariableType
    {
        return match ($type) {
            VariableType::TIME, VariableType::OBJECT => VariableType::TEXT,
            default => $type,
        };
    }

    /**
     * The flat option-KEY list an ENUM-based descriptor carries (null for any other base) — the same
     * `enumOptions` wire shape globalVariable() emits, shared so a domain catalog reads a global/slot's
     * option keys through one rule.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<int, string>|null
     */
    public function descriptorOptionKeys(array $descriptor): ?array
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
     * Every SUBFIELD path a variable's type + descriptor makes referenceable, as `path => flat type` — the
     * ONE source both a write-validation reference index and a runtime type map enumerate from, so the two
     * can never disagree (nor drift from what the resolver actually reads):
     *   - a FILE composite's fixed {id,name,type,size,url} (fileSubfieldTypeMap),
     *   - an OBJECT container's declared `fields`, recursively (objectSubfieldTypeMap).
     * Anything else adds nothing.
     *
     * This is the SHARED descent (R2): the workflow catalog enumerates a form-field / object-global's
     * subfields from here, and the template catalog enumerates an object/file SLOT's + object-global's
     * subfields from here — one home, so a `slots.product.name` / `globals.company.city` / `<file>.url`
     * reference type-flows from its subfield's real base in EITHER module without a forked descent.
     *
     * @param  array<string, mixed>|null  $descriptor
     * @return array<string, VariableType>
     */
    public function descriptorSubfieldTypeMap(string $path, VariableType $type, ?array $descriptor): array
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
    public function fileSubfieldTypeMap(string $path, VariableType $type): array
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
     * This is what makes an object GLOBAL's subfield (`globals.address.city`) — and an object SLOT's
     * subfield (`slots.product.name`) — a KNOWN reference: unlike a form section, whose leaves are ALSO
     * emitted flat, a self-contained object's interior existed only inside the descriptor. The runtime
     * already resolves these paths (a plain whitelisted Arr::get over the injected map), so this closes the
     * write-side gap without touching the resolver. Each child's type is the flat wire type recovered from
     * its own descriptor (fromDescriptor → flatType), so a nested container degrades to text exactly like
     * its parent and nothing downstream can ever receive `object`.
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
    public function objectSubfieldTypeMap(string $path, ?array $descriptor): array
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
    public function isObjectContainer(?array $descriptor): bool
    {
        return $descriptor !== null
            && ($descriptor['base'] ?? null) === VariableType::OBJECT->value
            && ($descriptor['array'] ?? false) !== true
            && is_array($descriptor['fields'] ?? null);
    }

    /**
     * The workspace's custom functions as label-carrying operation-catalog entries, MERGED into the
     * operations catalog operations() exposes so the FE add-menu offers them. Each is
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
     * (fromDescriptor → flatType), so an array/object global degrades exactly like a domain catalog's own
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
}
