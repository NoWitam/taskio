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
//     (the editor's own chips stay primitive-mapped; this covers date/enum/multi too).
//
// Identity-only: a variable's `id` IS its `path` (the editor directive stores only
// `data.id`), so the real type is always recoverable from the catalog by path.
import type { IconName } from '../../ui/primitives/icons';
import type {
  VariableDefinition,
  VariableOption,
  VariablePrimitive,
} from '../../ui/editor/extensions/types';
import type {
  CatalogVariable,
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

// --- isIdVariable (SF3.2 — drop identifiers from the OFFERED lists) ----------
//
// An IDENTIFIER variable (a path ending in `.id` — trigger.submission.id,
// trigger.form.id, trigger.task.id — or in `_id` — the step outputs task_id /
// report_id) is a machine key, not something a human wants to drop into a title,
// a priority, or a deadline. SF3.2 stops OFFERING them: they are stripped from
// every "which variables can I insert / pick" list. They are NOT stripped from the
// RESOLVING side (resolveVariableType / resolveVariable / stripVariableDirectives),
// so a SAVED flow that already references an id still hydrates + renders correctly.
/**
 * Whether a variable `path` is an identifier (ends with `.id` or `_id`) — the
 * dot / underscore boundary avoids false positives (`fields.valid`, `fields.paid`).
 */
export function isIdVariable(path: string): boolean {
  return path.endsWith('.id') || path.endsWith('_id');
}

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

  // SF3.2: identifiers (`*.id` / `*_id`) are never OFFERED for insertion.
  return [...nonStep, ...stepOutputs]
    .filter((variable) => !isIdVariable(variable.path))
    .map((variable) => ({
      id: variable.path, // identity-only: id === path
      name: variable.name,
      type: editorPrimitive(variable.type),
    }));
}

// --- toEditorVariablesTyped (§4.9 — the TRUE-type + options editor feed) -----

/**
 * Map a catalog variable's enum options to the editor's `VariableOption[]` (label =
 * value; the catalog carries option VALUES only, labels are a UI concept). Empty /
 * absent → undefined so a non-enum definition carries no `options` key.
 */
function toVariableOptions(enumOptions: string[] | undefined): VariableOption[] | undefined {
  if (!enumOptions || enumOptions.length === 0) return undefined;
  return enumOptions.map((value) => ({ label: value, value }));
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

  // SF3.2: identifiers (`*.id` / `*_id`) are never OFFERED for insertion.
  return [...nonStep, ...stepOutputs]
    .filter((variable) => !isIdVariable(variable.path))
    .map((variable) => {
    const options = toVariableOptions(variable.enumOptions);
    const definition: VariableDefinition = {
      id: variable.path, // identity-only: id === path
      name: variable.name,
      // The TRUE workflow type (VariablePrimitive ≡ WorkflowVariableType) — not degraded.
      type: variable.type as VariablePrimitive,
    };
    if (options) definition.options = options;
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

// --- variablesOfType (the add-on picker filters, §4.9) ----------------------

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
  const accepted = new Set(Array.isArray(types) ? types : [types]);

  const nonStep = nonStepVariables(catalog);
  const stepOutputs = positionScopedStepOutputs(catalog, steps, position);

  // SF3.2: identifiers (`*.id` / `*_id`) are never OFFERED for a value-or-variable pick.
  return [...nonStep, ...stepOutputs].filter(
    (v) => accepted.has(v.type) && !isIdVariable(v.path),
  );
}

/** Every type a value-or-variable field may reference. */
const ALL_VALUE_TYPES: WorkflowVariableType[] = ['text', 'number', 'boolean', 'date', 'enum', 'multi', 'file'];

/**
 * ALL value-or-variable-referenceable variables at `position` — the SF "show-all"
 * picker feed. A value-or-variable field no longer PRE-FILTERS its picker to the
 * field's own type(s); the user picks any variable and COERCES it with operations to
 * the field's terminal (a text → a date via ops, an enum → a choice via enum_to_choice,
 * etc.). Identifiers (`*.id` / `*_id`) stay stripped (SF3.2 — via `variablesOfType`).
 */
export function allValueVariables(
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  position: number,
  triggerType?: WorkflowTriggerType | null,
): CatalogVariable[] {
  return variablesOfType(catalog, steps, position, ALL_VALUE_TYPES, triggerType);
}

// --- variableIcon (§7.5 — the workflow-type → icon map for the add-on chips) -

/**
 * The per-type icon for an ADD-ON variable chip (§7.5). The editor's own chips reuse
 * `getVariableIconName` which maps PRIMITIVES only (so date/enum/multi would all fall
 * to the text glyph); the add-on chips need the full map: text → type, number →
 * hash, boolean → check-circle (mirroring the editor), date → calendar, enum → list,
 * multi → list-checks.
 */
const VARIABLE_ICONS: Record<WorkflowVariableType, IconName> = {
  text: 'type',
  number: 'hash',
  boolean: 'check-circle',
  date: 'calendar',
  enum: 'list',
  multi: 'list-checks',
  file: 'file-text',
};

export function variableIcon(type: WorkflowVariableType): IconName {
  return VARIABLE_ICONS[type] ?? 'type';
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
