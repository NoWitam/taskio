// workflowVariables — the PURE, testable catalog→editor adapter (§4.7). This module
// maps the server workflow-catalog to the shapes each variable-capable control needs,
// and owns the drift-critical logic:
//   • toEditorVariables(catalog, steps, position) → the MarkdownEditor's
//     VariableDefinition[]: system + field variables (id = path, type = editor
//     PRIMITIVE via the degrade rule) PLUS the POSITION-SCOPED earlier-step outputs,
//     with each step's real KEY substituted into the catalog's `steps.<TYPE>.*`
//     template paths (§4.7.3),
//   • resolveVariableType(path, catalog, steps) → the TRUE WorkflowVariableType for a
//     path (used by the add-on fields + read-side rendering to recover the real type
//     the identity-only directive dropped),
//   • variablesOfType(catalog, steps, position, type) → the add-on picker filters
//     (which variables are offered for a value-or-variable field of a given type),
//   • variableIcon(type) → the workflow-type → icon map (§7.5) for the ADD-ON chips
//     (the editor's own chips stay primitive-mapped; this covers date/enum/multi too),
//   • conditionSourceVariables(fields, variables) → the CONDITION builder's picker feed:
//     the catalog's condition FIELDS (the only accepted `source` paths) enriched with the
//     structured descriptors their form-field variables carry, so the shared tree picker
//     can group sections and mark nullable / array sources there too.
//
// Identity-only: a variable's `id` IS its `path` (the editor directive stores only
// `data.id`), so the real type is always recoverable from the catalog by path.
import type { IconName } from '../../ui/primitives/icons';
import { translate } from '../../app/i18n';
import { isSystemIdentifierPath } from '../../ui/variables/variableTree';
import type {
  VariableDefinition,
  VariableOption,
  VariablePrimitive,
} from '../../ui/editor/extensions/types';
import type {
  CatalogDescriptorField,
  CatalogField,
  CatalogVariable,
  CatalogVariableDescriptor,
  WorkflowCatalog,
  WorkflowStepType,
  WorkflowTriggerType,
  WorkflowVariableType,
} from './types';

/**
 * The minimum a step must expose for output scoping: its TYPE (the catalog keys
 * outputs by type) and its user-assigned KEY (substituted into the ref path). Both
 * the editor's StepDraft and a saved WorkflowStep satisfy this.
 */
export interface StepLike {
  type: WorkflowStepType | string;
  key: string;
}

// --- The editor primitive degrade rule (mirrors WorkflowVariableType::editorPrimitive) ---

/**
 * Degrade a real workflow type to the editor PRIMITIVE it serializes to inside a
 * markdown directive (§4.7): number → number, boolean → boolean, everything else
 * (text/date/enum/multi) → text. The real type stays recoverable from the catalog
 * by the directive's id (= path).
 */
export function editorPrimitive(type: WorkflowVariableType): VariablePrimitive {
  switch (type) {
    case 'number':
      return 'number';
    case 'boolean':
      return 'boolean';
    default:
      return 'text';
  }
}

// --- The catalog's step-output templates (§4.7.3) ---------------------------
//
// The live catalog lists step outputs as `steps.<TYPE>.<name>` TEMPLATE variables
// (source 'steps'), for every step TYPE the backend registers (a form-INDEPENDENT
// catalog carries them all). This is now the SINGLE source of step-output descriptors —
// the old static STEP_OUTPUTS mirror was DELETED. We derive the per-TYPE descriptor map
// from the catalog and substitute the user's <key> for <TYPE> when offering an earlier
// step's outputs.

/** One step-output descriptor (a name suffix + its workflow type), keyed by step TYPE. */
interface StepOutputDescriptor {
  name: string;
  type: WorkflowVariableType;
}

/**
 * Parse the catalog's `steps.<TYPE>.<name>` template variables into a per-step-TYPE
 * descriptor map. Replaces the deleted STEP_OUTPUTS mirror: the backend catalog is the
 * authoritative list of what each step type outputs. A null/absent catalog (or one with
 * no `steps.*` templates) yields an empty map.
 */
function catalogStepOutputs(
  catalog: WorkflowCatalog | null | undefined,
): Map<string, StepOutputDescriptor[]> {
  const byType = new Map<string, StepOutputDescriptor[]>();
  for (const variable of catalog?.variables ?? []) {
    if (variable.source !== 'steps') continue;
    // Template path shape: `steps.<TYPE>.<name>` → drop the `steps.` stem, take the TYPE
    // from the first segment, the remainder is the output name.
    const rest = variable.path.slice('steps.'.length);
    const dot = rest.indexOf('.');
    if (dot <= 0) continue;
    const stepType = rest.slice(0, dot);
    const name = rest.slice(dot + 1);
    const list = byType.get(stepType) ?? [];
    list.push({ name, type: variable.type });
    byType.set(stepType, list);
  }
  return byType;
}

/**
 * The step-output CatalogVariables for one earlier step, with its real `key`
 * substituted into the `steps.<key>.<name>` path (§4.7.3), using the catalog-derived
 * descriptors for the step's TYPE. A keyless step contributes nothing. The label is
 * `<key>.<name>` so the picker reads clearly.
 */
function stepOutputVariables(
  step: StepLike,
  descriptors: Map<string, StepOutputDescriptor[]>,
): CatalogVariable[] {
  const key = step.key.trim();
  if (!key) return []; // a keyless earlier step contributes no outputs yet
  const outputs = descriptors.get(step.type) ?? [];
  return outputs.map((output) => ({
    source: 'steps' as const,
    path: `steps.${key}.${output.name}`,
    name: `${key}.${output.name}`,
    type: output.type,
  }));
}

/**
 * The step-output variables available AT a position: the outputs of steps
 * 0..position-1 ONLY (position scoping, §4.7.2), each KEY-substituted from the catalog's
 * `steps.<TYPE>.*` templates. `position` is the current step's index; use a large number
 * (or steps.length) to include all. Exported so the parity spec can prove the catalog
 * derivation reproduces what the deleted STEP_OUTPUTS mirror provided.
 */
export function positionScopedStepOutputs(
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  position: number,
): CatalogVariable[] {
  const descriptors = catalogStepOutputs(catalog);
  const upto = Math.min(Math.max(position, 0), steps.length);
  const out: CatalogVariable[] = [];
  for (let i = 0; i < upto; i += 1) {
    out.push(...stepOutputVariables(steps[i], descriptors));
  }
  return out;
}

// --- Trigger SYSTEM variables (now sourced from the live catalog) -----------
//
// WorkflowVariableCatalogService::triggerSystemVariables exposes the non-field variables
// a trigger type ALWAYS resolves at runtime (e.g. `trigger.scheduled_at` for schedule; the
// submission / form / source / timestamp vars for form_submitted). These now arrive INSIDE
// the live catalog: the editor fetches a FORM-INDEPENDENT catalog per trigger type
// (`GET /workflows/catalog?trigger_type=`), so `catalog.variables` (source 'trigger')
// already carries them — for schedule AND form-less form_submitted alike. The old static
// `TRIGGER_SYSTEM_VARIABLES` mirror was DELETED; the catalog is the single source, and the
// `triggerType` params below are retained only for call-site stability (now inert — the
// catalog is fetched per trigger type and is authoritative).

// --- isIdVariable (SF3.2 — drop SYSTEM identifiers from the OFFERED lists) ---
//
// A SYSTEM IDENTITY variable (`trigger.submission.id`, `trigger.form.id`,
// `trigger.task.id`, and the step outputs `task_id` / `report_id`) is a machine key
// the engine generates, not something a human wants to drop into a title, a priority,
// or a deadline. SF3.2 stops OFFERING them: they are stripped from every "which
// variables can I insert / pick" list. They are NOT stripped from the RESOLVING side
// (resolveVariableType / resolveVariable / stripVariableDirectives), so a SAVED flow
// that already references an id still hydrates + renders correctly.
//
// A USER-AUTHORED form field named `numer_id` / `order_id` is NOT one of those — it is
// an ordinary variable and stays offered everywhere. That scoping lives in ONE place,
// the shared variable model (`ui/variables/variableTree`), and this is the workflows-side
// alias of it, so the markdown `{`-insert feed and the tree picker can never disagree.
/**
 * Whether a variable `path` is a SYSTEM identity path. Re-exported from the shared model
 * (`isSystemIdentifierPath`) — do NOT re-implement the suffix test here.
 */
export const isIdVariable = isSystemIdentifierPath;

/**
 * The trigger SYSTEM variables the catalog carries, OFFERED for insertion: the catalog's
 * `source:'trigger'` variables that are NOT form fields (`trigger.fields.*`) and NOT
 * identifiers (`*.id` / `*_id`, stripped by SF3.2). Derived straight from the live catalog
 * (the static backend mirror was deleted); it documents the system-var subset and anchors
 * the parity spec. A null/absent catalog yields [].
 */
export function triggerSystemVariables(
  catalog: WorkflowCatalog | null | undefined,
): CatalogVariable[] {
  return (catalog?.variables ?? []).filter(
    (v) => v.source === 'trigger' && !v.path.startsWith('trigger.fields.') && !isIdVariable(v.path),
  );
}

/**
 * The catalog's NON-step variables (trigger-system + form-field vars). The live catalog is
 * now form-independent and authoritative — it already carries the trigger-system variables
 * for its trigger type — so this is simply its `source !== 'steps'` slice (the old
 * static-mirror merge is gone). A null/absent catalog yields [].
 */
function nonStepVariables(
  catalog: WorkflowCatalog | null | undefined,
): CatalogVariable[] {
  return (catalog?.variables ?? []).filter((v) => v.source !== 'steps');
}

// --- Structural-descriptor expansion (phase-2c) -----------------------------
//
// The catalog now carries STRUCTURAL descriptors (phase-2a/2b backend): a FILE composite
// advertises its {id,name,type,size,url} subfields, a SECTION is an `object` and a REPEATER an
// `array<object>`. This is where the flat catalog variable list is EXPANDED into the
// editor-pickable variables those descriptors imply, so a file subfield flows through the
// EXISTING ref machinery (a composed `<file>.<key>` path + a scalar type) with no new insertion
// code. Every variable-offering feed (toEditorVariables[Typed] + variablesOfType) routes through
// `expandVariables`, so the picker, the `{`-insert list and the value-or-variable pickers agree.
//
//   • FILE     → the whole-file entry (unchanged) PLUS one pickable per subfield. The subfields
//                intentionally BYPASS the SF3.2 id-strip rule (a file's `.id` IS meant to be
//                pickable — see the file-subfield contract).
//   • REPEATER (object + array:true) → ONE entry, relabelled as a list/collection (no per-element
//                subfields — per-element access is deferred to the R2 loop).
//   • SECTION  (object, array:false) → the EXPAND-ONLY container entry. Its leaves keep riding as
//                flat top-level variables — the container merely GROUPS them in the tree picker; it
//                is never selectable (a whole object resolves to a map). Since B4 EVERY feed
//                carries it, the markdown `{` list included: they all render through the shared
//                browser, which enforces the never-selectable rule structurally.
//   • anything else → itself (subject to the SF3.2 id-strip rule) — descriptor-less variables and
//                the locally-synthesised step outputs pass straight through (a no-op).

/**
 * Map a structured descriptor's BASE to the FE `WorkflowVariableType` a subfield/leaf carries —
 * the FE mirror of the backend degrade rule (WorkflowVariableType::flatType + descriptorBase):
 * `time`/`object` degrade to `text`, an `enum` base with `array:true` is a `multi`, and
 * file/date/number/boolean/text are themselves. The default arm means a NEW descriptor base can
 * never fall through a closed match (phase-2c tolerance).
 */
export function descriptorBaseToType(descriptor: CatalogVariableDescriptor): WorkflowVariableType {
  switch (descriptor.base) {
    case 'number':
      return 'number';
    case 'boolean':
      return 'boolean';
    case 'date':
      return 'date';
    case 'enum':
      return descriptor.array ? 'multi' : 'enum';
    case 'file':
      return 'file';
    default:
      // 'time' + 'object' are DESCRIPTOR-ONLY bases (they degrade to text on the flat wire, exactly
      // like the backend); 'text' is itself — so the closed FE type-union never receives them.
      return 'text';
  }
}

/**
 * One pickable editor variable for a file composite's subfield: path `<file>.<key>`, a qualified
 * `<parent> › <sub>` display name (the sub label localized for the known {id,name,type,size,url}
 * keys, else the backend field label), and the subfield's scalar type. Carries the child
 * descriptor so downstream code (icons, option lists) stays uniform.
 */
function fileSubfieldVariable(parent: CatalogVariable, field: CatalogDescriptorField): CatalogVariable {
  const subLabel = translate(`workflows.variable.fileSubfield.${field.key}`, field.label);
  return {
    source: parent.source,
    path: `${parent.path}.${field.key}`,
    name: translate('workflows.variable.qualifier', `${parent.name} › ${subLabel}`, {
      parent: parent.name,
      sub: subLabel,
    }),
    type: descriptorBaseToType(field.descriptor),
    descriptor: field.descriptor,
  };
}

/**
 * The whole-repeater entry, relabelled as a list/collection so a user sees the collection exists
 * (its per-element fields are NOT pickable — deferred to R2). Keeps the flat text type + the object
 * descriptor (so the element shape stays inspectable downstream).
 */
function repeaterListVariable(variable: CatalogVariable): CatalogVariable {
  return {
    ...variable,
    name: translate('workflows.variable.collection', `${variable.name} (list)`, { name: variable.name }),
  };
}

/** The dotted root every workspace global lives under (`globals.<key>`). */
const GLOBALS_ROOT = 'globals';

/**
 * The "Globals" GROUP node: one expand-only container at the `globals` root that every global
 * nests under by its own dotted path (`globals.<key>`), replacing the per-entry "Globals ›" text
 * prefix with a real tree node (B3; B4 removed that prefix — and its i18n key — for good, since
 * EVERY feed is a tree now). It carries NO `descriptor.fields`, so its children can only ever be
 * the REAL offered globals — never a synthesized path — exactly like a form section container.
 * Being a non-array object it is NEVER selectable, and `buildVariableTree` prunes it when the feed
 * ends up carrying no global at all.
 */
function globalsGroupVariable(): CatalogVariable {
  return {
    source: 'globals',
    path: GLOBALS_ROOT,
    name: translate('workflows.variable.globalsGroup', 'Globals'),
    type: 'text',
    descriptor: { base: 'object', nullable: false, array: false },
  };
}

/**
 * A form SECTION as an EXPAND-ONLY container entry: the container itself with its
 * `descriptor.fields` DROPPED. A section's children are its OWN flat leaf variables (the backend
 * emits every leaf top-level as `<section>.<leaf>`), which nest under it by dotted path in
 * `buildVariableTree` — so dropping the descriptor fields is exactly what guarantees each leaf
 * appears ONCE and that every SELECTABLE node under the section is a REAL offered catalog entry
 * (already type-filtered by the caller), never a synthesized path the feed did not offer. Mirrors
 * `conditionContainerVariable` below, for the same reason.
 */
function sectionContainerVariable(variable: CatalogVariable): CatalogVariable {
  return {
    ...variable,
    descriptor: { base: 'object', nullable: variable.descriptor?.nullable ?? false, array: false },
  };
}

/**
 * Expand a flat catalog variable list into the editor-pickable variables its structural
 * descriptors imply (see the section header). Descriptor-less variables — the older/simpler
 * shape AND the locally-synthesised step outputs — pass through unchanged, so this is a no-op for
 * every non-structural catalog. Applies the SF3.2 id-strip rule to top-level variables here (file
 * subfields are exempt, being generated below it).
 *
 * ONE feed shape for EVERY surface (B4). This used to take an `includeContainers` option, off by
 * default, because the markdown `{`-insert list was rendered by a FLAT list where every row was
 * insertable — a whole-section row there would have let a user drop a directive that resolves to an
 * object MAP into their text. That list now renders through `buildVariableTree` + `VariableBrowser`
 * like every other picker, where a non-array object is EXPAND-ONLY and can never be inserted, so
 * the exclusion (and the per-entry "Globals ›" text prefix it forced) is gone: containers ride in
 * all feeds, and no surface can disagree about what is offered.
 */
function expandVariables(variables: CatalogVariable[]): CatalogVariable[] {
  const out: CatalogVariable[] = [];
  // The "Globals" group node is emitted ONCE, in front of the first global, so the feed's own
  // ordering (globals wherever the catalog put them) is preserved.
  let globalsGrouped = false;
  for (const variable of variables) {
    const descriptor = variable.descriptor;
    const base = descriptor?.base;

    // GLOBAL: the workspace's user-authored literal constants. They ride AS THEMSELVES under the
    // "Globals" GROUP node, which their dotted path nests them beneath. A SCALAR global is a
    // selectable leaf; an OBJECT global keeps its `descriptor.fields` and therefore becomes an
    // EXPANDABLE, never-selectable container of its own fields — the same treatment a form section
    // gets. Globals paths never trip the SF3.2 id-strip (they are user-authored).
    if (variable.source === 'globals') {
      if (!globalsGrouped) {
        globalsGrouped = true;
        out.push(globalsGroupVariable());
      }
      out.push(variable);
      continue;
    }

    // FILE composite: the whole-file entry + one pickable per subfield.
    if (base === 'file' && descriptor?.fields?.length) {
      if (!isIdVariable(variable.path)) out.push(variable);
      for (const field of descriptor.fields) {
        out.push(fileSubfieldVariable(variable, field)); // subfields bypass the id-strip rule
      }
      continue;
    }

    // OBJECT container: a REPEATER (array) → one list entry; a SECTION (non-array) → the
    // expand-only container entry that groups its own flat leaves.
    if (base === 'object') {
      if (isIdVariable(variable.path)) continue;
      if (descriptor?.array) out.push(repeaterListVariable(variable));
      else out.push(sectionContainerVariable(variable));
      continue;
    }

    // Everything else: itself, subject to the SF3.2 id-strip rule.
    if (!isIdVariable(variable.path)) out.push(variable);
  }
  return out;
}

// --- toEditorVariables (§4.7.1) ---------------------------------------------

/**
 * Build the MarkdownEditor's `VariableDefinition[]` for a field at `position`:
 * the catalog's system + field variables (which already carry full paths) PLUS the
 * position-scoped, KEY-substituted earlier-step outputs. Each definition is
 * identity-only — `id` = the variable's `path`, `type` = the editor PRIMITIVE (the
 * real type is re-resolved from the catalog by id where it matters). The catalog's
 * own `steps.<TYPE>.*` template variables are DROPPED here (they carry no user key);
 * the live per-position step outputs replace them.
 *
 * @param catalog  the workflow-catalog (variables + fields). Now form-INDEPENDENT and
 *                 authoritative: it always carries the trigger-system vars (source
 *                 'trigger') + the `steps.<TYPE>.*` templates for its trigger type.
 * @param steps    the full ordered step list (type + key).
 * @param position the field's own step index (earlier-only scoping).
 * @param triggerType retained for call-site stability; now INERT (the catalog is fetched
 *                 per trigger type and carries the trigger-system vars directly).
 */
export function toEditorVariables(
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  position: number,
  triggerType?: WorkflowTriggerType | null,
): VariableDefinition[] {
  // System + field variables carry full paths already; drop the catalog's own template
  // step outputs (source 'steps') — the live KEY-substituted ones replace them.
  const nonStep = nonStepVariables(catalog);

  const stepOutputs = positionScopedStepOutputs(catalog, steps, position);

  // Expand structural descriptors (file subfields + repeater list; section containers group their
  // flat leaves) and strip SF3.2 identifiers, then degrade each to the editor PRIMITIVE.
  return expandVariables([...nonStep, ...stepOutputs]).map((variable) => {
    const definition: VariableDefinition = {
      id: variable.path, // identity-only: id === path
      name: variable.name,
      type: editorPrimitive(variable.type),
    };
    // An OBJECT container degrades to the `text` flat type; carrying the base keeps it a
    // never-selectable branch when this flat feed is promoted back into the shared tree.
    if (variable.descriptor?.base === 'object') definition.base = 'object';
    return definition;
  });
}

// --- toEditorVariablesTyped (§4.9 — the TRUE-type + options editor feed) -----

/**
 * The choice options for a catalog variable as the editor's `VariableOption[]`
 * (`{label,value}`), PREFERRING the structured `descriptor.options` (`{key,label}` — show
 * the human `label`, keep the `key` as the stored/emitted wire VALUE) and FALLING BACK to
 * the flat `enumOptions` (label = value) when no descriptor is present (older / label-less
 * responses, and existing fixtures). Empty / absent → undefined so a non-choice definition
 * carries no `options` key. This is the single place the FE turns a variable's choices into
 * human labels, so the variable picker / pipeline enum args / chip echo all agree.
 */
export function variableOptionList(variable: CatalogVariable): VariableOption[] | undefined {
  const descriptorOptions = variable.descriptor?.options;
  if (descriptorOptions && descriptorOptions.length > 0) {
    return descriptorOptions.map((option) => ({ label: option.label, value: option.key }));
  }
  if (variable.enumOptions && variable.enumOptions.length > 0) {
    return variable.enumOptions.map((value) => ({ label: value, value }));
  }
  return undefined;
}

/**
 * The TYPED variant of `toEditorVariables` (SF1): the SAME identity-only definitions
 * (id = path) + position-scoped KEY-substituted step outputs, but carrying the
 * variable's TRUE `WorkflowVariableType` (NOT the degraded editor primitive) and its
 * enum `options`. The editor's variable vocabulary is the same 6-member union
 * (`VariablePrimitive` ≡ `WorkflowVariableType` after B1), so a chip built from these
 * definitions offers the RIGHT operations (enum/date/multi) in its pipeline modal and
 * feeds enum options into `sourceOption(s)` args.
 *
 * This is a SEPARATE path from `toEditorVariables` (which stays the degrade-to-primitive
 * feed other read/summary code relies on) so nothing depending on the primitive shape
 * breaks. Use this for step MARKDOWN fields where the full operations pipeline is offered.
 */
export function toEditorVariablesTyped(
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  position: number,
  triggerType?: WorkflowTriggerType | null,
): VariableDefinition[] {
  const nonStep = nonStepVariables(catalog);
  const stepOutputs = positionScopedStepOutputs(catalog, steps, position);

  // Expand structural descriptors (file subfields become pickable at their scalar type; a repeater
  // is one list entry; a section / object global / the "Globals" group ride as EXPAND-ONLY
  // containers) and strip SF3.2 identifiers. B4: this IS the markdown `{`-insert feed, and it now
  // renders through the shared browser, so it carries exactly what every other feed carries.
  return expandVariables([...nonStep, ...stepOutputs]).map((variable) => {
    const options = variableOptionList(variable);
    const definition: VariableDefinition = {
      id: variable.path, // identity-only: id === path
      name: variable.name,
      // The TRUE workflow type (VariablePrimitive ≡ WorkflowVariableType) — not degraded.
      type: variable.type as VariablePrimitive,
    };
    if (options) definition.options = options;
    // Type-icon MODIFIERS (§refinement 3): carry the descriptor's nullable / array flags so the
    // editor chip can mark an optional / list variable. Emit-or-omit keeps definitions lean.
    if (variable.descriptor?.nullable) definition.nullable = true;
    if (variable.descriptor?.array) definition.array = true;
    // The STRUCTURAL base of an OBJECT container (B4): it degrades to the `text` flat type, and
    // the `{` browser must still know it is a container — an EXPAND-ONLY, never-insertable row.
    if (variable.descriptor?.base === 'object') definition.base = 'object';
    return definition;
  });
}

// --- resolveVariableType (the catalog-by-path type recovery) ----------------

/**
 * Recover the TRUE WorkflowVariableType for a path (§4.7 identity-only nuance).
 * Checks the catalog's system + field variables first, then the position-agnostic
 * KEY-substituted step outputs (all steps). Returns null when the path is unknown
 * (a stale ref — the caller degrades gracefully).
 */
export function resolveVariableType(
  path: string,
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  triggerType?: WorkflowTriggerType | null,
): WorkflowVariableType | null {
  // The catalog is authoritative: it carries the trigger-system vars (INCLUDING the id
  // vars, which resolve even though SF3.2 never OFFERS them) + form fields.
  const inCatalog = (catalog?.variables ?? []).find((v) => v.path === path);
  if (inCatalog) return inCatalog.type;

  // Step outputs (all steps, any position — type recovery is position-agnostic).
  const stepOutput = positionScopedStepOutputs(catalog, steps, steps.length).find((v) => v.path === path);
  return stepOutput?.type ?? null;
}

/**
 * Recover the full CatalogVariable for a path (system/field/step output), or null.
 * Useful for read-side rendering that needs the name/enumOptions too, not just the
 * type. Step outputs are position-agnostic (all steps).
 */
export function resolveVariable(
  path: string,
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  triggerType?: WorkflowTriggerType | null,
): CatalogVariable | null {
  const inCatalog = (catalog?.variables ?? []).find((v) => v.path === path);
  if (inCatalog) return inCatalog;
  return positionScopedStepOutputs(catalog, steps, steps.length).find((v) => v.path === path) ?? null;
}

/**
 * Recover the structured DESCRIPTOR for a path (§4.7, phase-1a) — the `{base,nullable,
 * array,options?}` a catalog variable now carries — or null when the path is unknown or
 * the (older) catalog carried no descriptor. Mirrors `resolveVariable` but returns just the
 * descriptor: the by-path source of an enum's human option LABELS for read-side rendering.
 * Locally-synthesized step outputs carry no descriptor (text outputs, no options), so this
 * returns null for them and callers fall back to `enumOptions`.
 */
export function resolveVariableDescriptor(
  path: string,
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  triggerType?: WorkflowTriggerType | null,
): CatalogVariableDescriptor | null {
  return resolveVariable(path, catalog, steps, triggerType)?.descriptor ?? null;
}

// --- variablesOfType (the add-on picker filters, §4.9) ----------------------

/**
 * The shared body of every OFFERED-variable feed: the catalog's non-step variables + the
 * position-scoped, KEY-substituted step outputs, structurally expanded, then filtered to the
 * accepted flat type(s).
 *
 * A CONTAINER carries the degraded `text` flat type, so a type-filtered feed keeps only the
 * containers a text-accepting field could group leaves under — and `buildVariableTree` prunes any
 * that end up with no offered leaf, so a filter can never leave a dead branch behind.
 */
function offeredVariables(
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  position: number,
  types: WorkflowVariableType | WorkflowVariableType[],
): CatalogVariable[] {
  const accepted = new Set(Array.isArray(types) ? types : [types]);
  const nonStep = nonStepVariables(catalog);
  const stepOutputs = positionScopedStepOutputs(catalog, steps, position);

  return expandVariables([...nonStep, ...stepOutputs]).filter((v) => accepted.has(v.type));
}

/**
 * The variables offered to a value-or-variable ADD-ON field at `position`, filtered
 * to the compatible types. A `ValueOrVariableField` over an enum literal offers
 * enum + text variables; a `DateOrVariableField` offers date variables; etc. Pass
 * the accepted type(s); the result is the position-scoped catalog + step-output
 * variables of those types (full CatalogVariables so the picker shows names + the
 * add-on can emit the TRUE type into the ref).
 *
 * @param types the accepted workflow type(s) (e.g. ['enum','text'] for priority).
 */
export function variablesOfType(
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  position: number,
  types: WorkflowVariableType | WorkflowVariableType[],
  triggerType?: WorkflowTriggerType | null,
): CatalogVariable[] {
  // Expand structural descriptors then filter to the accepted type(s). `expandVariables` applies
  // the SF3.2 id-strip to top-level vars (file subfields — incl. `<file>.id` — are exempt); a file
  // subfield surfaces at its scalar type (text/number), so a `.name` reaches a text/priority field
  // and a `.size` a number field, while the whole-file entry (type `file`) still feeds a file pick.
  return offeredVariables(catalog, steps, position, types);
}

/** Every type a value-or-variable field may reference. */
const ALL_VALUE_TYPES: WorkflowVariableType[] = ['text', 'number', 'boolean', 'date', 'enum', 'multi', 'file'];

/**
 * ALL value-or-variable-referenceable variables at `position` — the SF "show-all"
 * picker feed. A value-or-variable field no longer PRE-FILTERS its picker to the
 * field's own type(s); the user picks any variable and COERCES it with operations to
 * the field's terminal (a text → a date via ops, an enum → a choice via enum_to_choice,
 * etc.). Identifiers (`*.id` / `*_id`) stay stripped (SF3.2).
 *
 * Like every other feed (B4) it carries the OBJECT CONTAINERS — a form SECTION, an object GLOBAL,
 * and the "Globals" GROUP node every global hangs under: they are rendered by `buildVariableTree` +
 * `VariableBrowser`, where a container is an EXPANDABLE, never-selectable row that groups its own
 * leaves. Nothing new becomes pickable — the leaves ride as they always did and are merely nested —
 * so every emitted ref stays byte-identical.
 */
export function allValueVariables(
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  position: number,
  triggerType?: WorkflowTriggerType | null,
): CatalogVariable[] {
  return offeredVariables(catalog, steps, position, ALL_VALUE_TYPES);
}

// --- variableIcon (§7.5 — the workflow-type → icon map for the add-on chips) -

/**
 * The per-type icon for an ADD-ON variable chip (§7.5). The editor's own chips reuse
 * `getVariableIconName` which maps PRIMITIVES only (so date/enum/multi would all fall
 * to the text glyph); the add-on chips need the full map: text → type, number →
 * hash, boolean → check-circle (mirroring the editor), date → calendar, enum → list,
 * multi → list-checks.
 */
const VARIABLE_ICONS: Record<WorkflowVariableType | 'object', IconName> = {
  text: 'type',
  number: 'hash',
  boolean: 'check-circle',
  date: 'calendar',
  enum: 'list',
  multi: 'list-checks',
  file: 'file-text',
  // The structural OBJECT base (a file composite / section / object global) — it degrades to the
  // `text` flat type, so its icon is keyed OFF the descriptor base, not the flat type (§refinement 5).
  object: 'braces',
};

/**
 * The per-type icon for a variable chip / picker row (§7.5). Accepts the `object` DESCRIPTOR base in
 * addition to the closed `WorkflowVariableType` union so an object-shaped variable (which degrades to
 * `text` on the flat wire) can still carry a distinct braces glyph.
 */
export function variableIcon(type: WorkflowVariableType | 'object'): IconName {
  return VARIABLE_ICONS[type] ?? 'type';
}

/**
 * The icon for a whole catalog variable — prefers the structured `object` base (braces) over the
 * degraded flat `text` type, so a file composite / section / object global reads as a container. Every
 * other base falls through to the flat-type icon (`file` → file-text, `enum` → list, …).
 */
export function variableNodeIcon(
  variable: { type: WorkflowVariableType; descriptor?: CatalogVariableDescriptor },
): IconName {
  if (variable.descriptor?.base === 'object') return variableIcon('object');
  return variableIcon(variable.type);
}

// --- Variable PICKER tree (§refinement 5) -----------------------------------
//
// The structured field's Variable picker presents its offered variables as an EXPANDABLE TREE rather
// than a flat qualified list: an object-shaped entry (a `file` composite, an `object` global, a form
// section) renders as an expandable NODE whose children are its subfields; picking a leaf child emits a
// ref at the composed `<parent>.<key>` path with the child's type (the SAME ref the flat list emitted).
// A repeater (`object` + `array:true`) stays a single, non-expandable LIST entry (per-element picking is
// deferred). This is PRESENTATION ONLY — the emitted ref shape is byte-identical.
//
// The builder works on the SAME flat `CatalogVariable[]` the host already feeds the picker (post
// type-filter, `allValueVariables` / `variablesOfType`), reconstructing the hierarchy TWO ways so the
// host's filtering is always respected:
//   • PATH-PREFIX nesting — a file composite's subfields already ride in the list as flat `<file>.<key>`
//     entries (expandVariables), so they nest under the whole-file entry by their dotted path. A file
//     with NO offered subfields (e.g. a file-only picker) stays a selectable leaf.
//   • DESCRIPTOR expansion — a SELF-CONTAINED object (an `object` global) rides as ONE entry with no
//     flat children, so its `descriptor.fields` are expanded into composed child nodes.
// A form section the flat feed dropped keeps its leaves flat (no regression); were it present it would
// nest/expand the same way (non-selectable, since a whole object resolves to a map).

export interface VariablePickerNode {
  /**
   * The variable this node represents — a real catalog entry OR a synthesized descriptor child. Its
   * `{source, path, type}` is the ref a pick emits; its descriptor drives the icon + markers + options.
   */
  variable: CatalogVariable;
  /**
   * True when picking THIS node emits a ref. A non-array `object` container from a NON-global source (a
   * form section) resolves to a map, so it only expands — it is never itself pickable.
   */
  selectable: boolean;
  /** Child nodes; present ⇒ the node is expandable. */
  children?: VariablePickerNode[];
}

/** Whether a descriptor is a non-array OBJECT container (a section / object global — expandable). */
function isObjectContainer(descriptor: CatalogVariableDescriptor | undefined): boolean {
  return descriptor?.base === 'object' && !descriptor.array;
}

/** Whether a descriptor is a REPEATER (an array<object> — a single non-expandable list entry). */
function isRepeater(descriptor: CatalogVariableDescriptor | undefined): boolean {
  return descriptor?.base === 'object' && descriptor.array === true;
}

/** Selectable unless it is a non-array object container from a non-globals source (a form section). */
function nodeSelectable(variable: CatalogVariable): boolean {
  return !(isObjectContainer(variable.descriptor) && variable.source !== 'globals');
}

/**
 * One child node synthesized from a container descriptor's `{key, label, descriptor}` field — used for a
 * SELF-CONTAINED object whose children are not flat entries (an object global). Composes the
 * `<parent>.<key>` path, localizes a file's system subfield labels, and recurses into nested containers.
 */
function descriptorChildNode(parent: CatalogVariable, field: CatalogDescriptorField): VariablePickerNode {
  const isFileParent = parent.descriptor?.base === 'file';
  const child: CatalogVariable = {
    source: parent.source,
    path: `${parent.path}.${field.key}`,
    name: isFileParent
      ? translate(`workflows.variable.fileSubfield.${field.key}`, field.label)
      : field.label,
    type: descriptorBaseToType(field.descriptor),
    descriptor: field.descriptor,
  };
  const node: VariablePickerNode = { variable: child, selectable: nodeSelectable(child) };
  const grandchildren = field.descriptor.fields;
  if ((isObjectContainer(field.descriptor) || field.descriptor.base === 'file') && grandchildren?.length) {
    node.children = grandchildren.map((f) => descriptorChildNode(child, f));
  }
  return node;
}

/** The node whose path is the LONGEST strict dotted prefix of `path`, or null (top-level). */
function longestPrefixParent(
  path: string,
  byPath: Map<string, VariablePickerNode>,
): VariablePickerNode | null {
  let best: VariablePickerNode | null = null;
  for (const [candidatePath, node] of byPath) {
    if (candidatePath !== path && path.startsWith(`${candidatePath}.`)) {
      if (!best || candidatePath.length > best.variable.path.length) best = node;
    }
  }
  return best;
}

/**
 * Build the picker TREE for a flat, already-filtered `CatalogVariable[]` (see the section header).
 * Descriptor-less variables + step outputs pass straight through as flat leaves, so this is a no-op for
 * every non-structural catalog.
 */
export function variablePickerTree(variables: CatalogVariable[]): VariablePickerNode[] {
  const nodes: VariablePickerNode[] = variables.map((variable) => ({
    variable,
    selectable: nodeSelectable(variable),
  }));

  // 1. PATH-PREFIX nesting: attach each node under the entry that is its LONGEST strict dotted prefix,
  //    so flat `<file>.<key>` subfields nest under the whole-file entry. A repeater never receives
  //    children (per-element access is deferred).
  const byPath = new Map<string, VariablePickerNode>();
  for (const node of nodes) byPath.set(node.variable.path, node);
  const roots: VariablePickerNode[] = [];
  for (const node of nodes) {
    const parent = longestPrefixParent(node.variable.path, byPath);
    if (parent && !isRepeater(parent.variable.descriptor)) {
      (parent.children ??= []).push(node);
    } else {
      roots.push(node);
    }
  }

  // 2. DESCRIPTOR expansion: a self-contained object container (an object global) has no flat children,
  //    so surface its `descriptor.fields` as composed child nodes. Files rely on the flat nesting above
  //    (a file with no offered subfields stays a selectable leaf).
  for (const node of nodes) {
    if (node.children) continue;
    const descriptor = node.variable.descriptor;
    if (isObjectContainer(descriptor) && descriptor?.fields?.length) {
      node.children = descriptor.fields.map((f) => descriptorChildNode(node.variable, f));
    }
  }

  return roots;
}

// --- Condition SOURCE variables (the condition builder's picker feed) --------
//
// The condition builder picks its source from the catalog's `fields` (`CatalogField`:
// `{path:'fields.<id>', field_id, label, type, enumOptions?, operators}`) — and ONLY those paths are
// accepted as a condition source (WorkflowConditionTreeValidator checks `source` against
// `conditionFieldsFor($form)`). Those descriptors carry NO structured `descriptor`, so on their own
// they cannot drive the shared VariableBrowser, which reads `descriptor` for the object tree and
// for the nullable / array markers.
//
// This adapter closes that gap WITHOUT inventing data and WITHOUT widening the accepted source set.
// Each condition field is re-shaped into a `CatalogVariable` that KEEPS the field's own `path` (the
// emitted contract), `label`, `type` and `enumOptions`, and BORROWS the structured `descriptor` from
// the catalog variable describing the SAME form field: the backend builds both from one form-field
// variable (`'fields.' . $field_id` vs `'trigger.fields.' . $field_id`), so the mapping
// `fields.<id>` ↔ `trigger.fields.<id>` is exact. A field with no matching variable (an older /
// variable-less catalog) stays descriptor-less — the picker then renders exactly the flat glyph it
// rendered before.
//
// It additionally re-surfaces a form SECTION (an `object`, non-array container) as a NON-selectable
// GROUP node so the flat `fields.<section>.<leaf>` entries nest under it (the condition field list
// drops containers — a container is not conditionable). A section is emitted ONLY when it actually
// holds an offered leaf, and WITHOUT its `descriptor.fields`, so the tree can never synthesize a
// child that is not itself an offered condition field: every SELECTABLE node stays exactly one
// `fields.<id>` path the write-validator accepts. A REPEATER (`array:true`) is never surfaced — its
// per-element fields are not conditionable (they have no flat leaf).

/** The root a FORM condition source resolves under — `fields.<id>` lives at `trigger.fields.<id>`. */
const CONDITION_SOURCE_ROOT = 'trigger' as const;

/** The condition SOURCE root a field belongs to — `globals.*` is a workspace global, else the form. */
function conditionFieldSource(field: CatalogField): CatalogVariable['source'] {
  return field.source ?? (field.path.startsWith('globals.') || field.path === 'globals' ? 'globals' : 'trigger');
}

/**
 * One condition FIELD as a picker variable: the field's own contract (`path` / `label` / `type` /
 * `enumOptions`) plus the structured `descriptor` (and `nullable` flag) of the catalog variable for
 * the same field, when the catalog carries one. `source` is the field's real catalog root (B6:
 * `trigger` for a form field, `globals` for a workspace global) so a pick emits the correct root.
 */
function conditionLeafVariable(
  field: CatalogField,
  variable: CatalogVariable | undefined,
  source: CatalogVariable['source'],
): CatalogVariable {
  const leaf: CatalogVariable = {
    source,
    path: field.path,
    name: field.label,
    type: field.type,
  };
  if (field.enumOptions) leaf.enumOptions = field.enumOptions;
  if (variable?.descriptor) leaf.descriptor = variable.descriptor;
  if (variable?.nullable) leaf.nullable = true;
  return leaf;
}

/**
 * A non-selectable GROUP node (a form SECTION at `fields.<section>`, or an object GLOBAL at
 * `globals.<key>`) its nested condition leaves hang under. Keeps the object base (so it reads with the
 * braces glyph) but DROPS `descriptor.fields` — the tree must nest only the real, offered condition
 * leaves under it, never synthesized children.
 */
function conditionContainerVariable(path: string, variable: CatalogVariable, source: CatalogVariable['source']): CatalogVariable {
  return {
    source,
    path,
    name: variable.name,
    type: variable.type,
    descriptor: { base: 'object', nullable: variable.descriptor?.nullable ?? false, array: false },
  };
}

/**
 * The container prefixes a condition field path sits under, OUTERMOST first, EXCLUDING the root
 * segment (`fields.a.b.c` → `fields.a`, `fields.a.b`; `globals.a.b.c` → `globals.a`, `globals.a.b`).
 * A top-level field (`fields.x` / `globals.x`) has none — the shared "Globals" group node covers the
 * globals root, and form fields nest under the tree's implicit roots.
 */
function conditionContainerPaths(fieldPath: string): string[] {
  const segments = fieldPath.split('.');
  const paths: string[] = [];
  for (let i = 2; i < segments.length; i += 1) paths.push(segments.slice(0, i).join('.'));
  return paths;
}

/**
 * The condition builder's picker feed: the catalog's condition SOURCES (form FIELDS + workspace
 * GLOBALS, B6) enriched with their structured descriptors and grouped in the shared tree — form
 * sections nest their leaves, and every global hangs under ONE "Globals" GROUP node exactly like every
 * other variable surface. The emitted `source` path contract is unchanged (`fields.<id>` for a form
 * field, `globals.<key>[.<sub>]` for a global); this only carries the real ROOT source + the structure
 * and type markers the shared tree picker needs.
 *
 * A GLOBAL leaf's descriptor lookup keys on its FULL catalog path (`globals.<key>` — its catalog source
 * IS its root); a form field's keys on `trigger.<path>`. Either falling back to descriptor-less (the
 * plain glyph) when the catalog carries no matching variable.
 */
export function conditionSourceVariables(
  fields: CatalogField[],
  variables: CatalogVariable[] | null | undefined,
): CatalogVariable[] {
  const byPath = new Map((variables ?? []).map((variable) => [variable.path, variable]));
  const out: CatalogVariable[] = [];
  const emitted = new Set<string>();
  let globalsGrouped = false;

  for (const field of fields) {
    const source = conditionFieldSource(field);

    // GLOBALS half (B6): a single "Globals" GROUP node (emitted once, before the first global) that
    // every `globals.<key>` leaf nests beneath by its dotted path — the SAME grouping the value / markdown
    // feeds use. The container's descriptor lookup uses the full catalog path directly.
    if (source === 'globals') {
      if (!globalsGrouped) {
        globalsGrouped = true;
        out.push(globalsGroupVariable());
      }
      for (const containerPath of conditionContainerPaths(field.path)) {
        if (emitted.has(containerPath)) continue;
        const container = byPath.get(containerPath);
        if (!container || container.descriptor?.base !== 'object' || container.descriptor.array) continue;
        emitted.add(containerPath);
        out.push(conditionContainerVariable(containerPath, container, 'globals'));
      }
      out.push(conditionLeafVariable(field, byPath.get(field.path), 'globals'));
      continue;
    }

    // FORM half: a section is emitted just BEFORE its first offered leaf, so the tree's root order
    // follows the form's own field order.
    for (const containerPath of conditionContainerPaths(field.path)) {
      if (emitted.has(containerPath)) continue;
      const container = byPath.get(`${CONDITION_SOURCE_ROOT}.${containerPath}`);
      if (!container || container.descriptor?.base !== 'object' || container.descriptor.array) continue;
      emitted.add(containerPath);
      out.push(conditionContainerVariable(containerPath, container, 'trigger'));
    }

    out.push(conditionLeafVariable(field, byPath.get(`${CONDITION_SOURCE_ROOT}.${field.path}`), 'trigger'));
  }

  return out;
}

/** Flatten a picker tree to every node in pre-order (for path→node lookup + selection resolution). */
export function flattenPickerNodes(nodes: VariablePickerNode[]): VariablePickerNode[] {
  const out: VariablePickerNode[] = [];
  const walk = (list: VariablePickerNode[]): void => {
    for (const node of list) {
      out.push(node);
      if (node.children) walk(node.children);
    }
  };
  walk(nodes);
  return out;
}

// --- stripVariableDirectives (§3.2 read-side echo) --------------------------
//
// The detail Steps panel echoes a step's `title` / report `name` as a one-line
// summary. Those strings may carry the editor's `@[variable]("…")` directives, and
// the `MarkdownViewer` renders BASE markdown only — it does NOT render directives as
// chips; it would print the raw `@[variable](…)` bytes. §3.2 mandates the fallback:
// "degrade to the plain text with the directive stripped to its variable name". This
// pure helper replaces each variable directive with its variable NAME — resolved from
// the catalog by the directive's `id` (= the variable path) when available, else the
// directive's own embedded `name`. Non-variable directives (mentions/ai-text) collapse
// to their own embedded name/label so no raw directive bytes ever surface.

/** The `@[keyword]("<escaped-json-payload>")` directive matcher (global). */
const DIRECTIVE_RE = /@\[[a-z-]+\]\("((?:\\.|[^"\\])*)"\)/g;

/** Parse a directive's escaped-JSON payload → `{ id, name }` (best-effort; null on failure). */
function parseDirectivePayload(escaped: string): { id?: string; name?: string } | null {
  try {
    // The payload is JSON with every `"` escaped as `\"` (legacy FORMAT byte-format).
    const json = escaped.replace(/\\"/g, '"');
    const parsed = JSON.parse(json) as { data?: { id?: string; name?: string; label?: string } };
    const data = parsed?.data;
    if (!data) return null;
    return { id: data.id, name: data.name ?? data.label };
  } catch {
    return null;
  }
}

/**
 * Replace every editor directive in `text` with its variable NAME for a faithful
 * read-only echo (§3.2). A `variable` directive's name is re-resolved from the
 * `catalog` by its `id` (= the path) so a stored name that drifted from the catalog
 * still reads correctly; when the catalog is unavailable (or the path is unknown),
 * the directive's own embedded name is used. Plain text is returned unchanged.
 *
 * @param text    the raw title/name string (may contain directives).
 * @param catalog the workflow-catalog (may be null — then the embedded name is used).
 * @param steps   the ordered steps (for resolving step-output variable paths).
 * @param triggerType the trigger type — resolves system-variable names for a null catalog.
 */
export function stripVariableDirectives(
  text: string | null | undefined,
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  triggerType?: WorkflowTriggerType | null,
): string {
  if (!text) return '';
  return text.replace(DIRECTIVE_RE, (_match, escaped: string) => {
    const payload = parseDirectivePayload(escaped);
    if (!payload) return ''; // an unparseable directive collapses to nothing, never bytes
    // Prefer the catalog's authoritative name (by the directive id = path).
    if (payload.id) {
      const resolved = resolveVariable(payload.id, catalog, steps, triggerType);
      if (resolved) return resolved.name;
    }
    return payload.name ?? '';
  });
}
