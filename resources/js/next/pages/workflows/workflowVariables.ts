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

// --- The catalog's step-output template stems (§4.7.3) ----------------------
//
// The catalog lists step outputs as `steps.<TYPE>.<name>` templates. B7a keeps this
// as a static mirror of the backend's step output descriptors (create_task →
// {task_id,title}, create_form_report → {report_id,report_name}); B7b re-points it at
// the catalog's real step-output names when the editor consumes the live catalog.
// The outputs are grouped by step TYPE; the FE substitutes the user's <key> for
// <TYPE> when offering them.

/** The output descriptors a step TYPE exposes, as (nameSuffix, workflow type) pairs. */
const STEP_OUTPUTS: Record<string, Array<{ name: string; type: WorkflowVariableType }>> = {
  create_task: [
    { name: 'task_id', type: 'text' },
    { name: 'title', type: 'text' },
  ],
  create_form_report: [
    { name: 'report_id', type: 'text' },
    { name: 'report_name', type: 'text' },
  ],
};

/**
 * The step-output CatalogVariables for one earlier step, with its real `key`
 * substituted into the `steps.<key>.<name>` path (§4.7.3). A keyless step
 * contributes nothing. The label is `<key>.<name>` so the picker reads clearly.
 */
function stepOutputVariables(step: StepLike): CatalogVariable[] {
  const key = step.key.trim();
  if (!key) return []; // a keyless earlier step contributes no outputs yet
  const outputs = STEP_OUTPUTS[step.type] ?? [];
  return outputs.map((output) => ({
    source: 'steps' as const,
    path: `steps.${key}.${output.name}`,
    name: `${key}.${output.name}`,
    type: output.type,
  }));
}

/**
 * The step-output variables available AT a position: the outputs of steps
 * 0..position-1 ONLY (position scoping, §4.7.2), each KEY-substituted. `position` is
 * the current step's index; use a large number (or steps.length) to include all.
 */
function positionScopedStepOutputs(steps: StepLike[], position: number): CatalogVariable[] {
  const upto = Math.min(Math.max(position, 0), steps.length);
  const out: CatalogVariable[] = [];
  for (let i = 0; i < upto; i += 1) {
    out.push(...stepOutputVariables(steps[i]));
  }
  return out;
}

// --- Trigger SYSTEM variables (mirror of the backend — keep in sync) --------
//
// WorkflowVariableCatalogService::triggerSystemVariables exposes the non-field
// variables a trigger type ALWAYS resolves at runtime, INDEPENDENT of any form/catalog.
// The editor only fetches a `catalog` for a form_submitted trigger WITH a form selected;
// a schedule trigger (or "any form") gets a NULL catalog, so those system variables
// would otherwise be invisible in the step editors even though the engine resolves them
// (e.g. `trigger.scheduled_at` for a schedule workflow). This static mirror lets the
// editors offer them BY TRIGGER TYPE when the catalog is absent.
//
// LUSTRO BACKENDU — trzymać w zgodzie z
// WorkflowVariableCatalogService::triggerSystemVariables(). The `name`s mirror the
// backend's (un-localized) catalog names so a chip inserted here reads identically to
// one the catalog would have produced when a form IS selected.
const TRIGGER_SYSTEM_VARIABLES: Record<WorkflowTriggerType, CatalogVariable[]> = {
  schedule: [
    { source: 'trigger', path: 'trigger.scheduled_at', name: 'Scheduled at', type: 'date' },
  ],
  form_submitted: [
    { source: 'trigger', path: 'trigger.submission.id', name: 'Submission ID', type: 'text' },
    { source: 'trigger', path: 'trigger.form.id', name: 'Form ID', type: 'text' },
    { source: 'trigger', path: 'trigger.form.name', name: 'Form name', type: 'text' },
    { source: 'trigger', path: 'trigger.source', name: 'Source', type: 'enum', enumOptions: ['manual', 'task'] },
    { source: 'trigger', path: 'trigger.submitted_at', name: 'Submitted at', type: 'date' },
    { source: 'trigger', path: 'trigger.task.id', name: 'Task ID', type: 'text', nullable: true },
  ],
};

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
 * The RAW static trigger SYSTEM variables (the unfiltered backend mirror), used by
 * the RESOLVING helpers so a saved id ref still recovers its type/name. The OFFERED
 * `triggerSystemVariables` below strips identifiers from this.
 */
function rawTriggerSystemVariables(
  triggerType: WorkflowTriggerType | null | undefined,
): CatalogVariable[] {
  if (!triggerType) return [];
  return TRIGGER_SYSTEM_VARIABLES[triggerType] ?? [];
}

/**
 * The static trigger SYSTEM variables OFFERED for a trigger type (a mirror of the
 * backend), or [] when the type is unknown/absent — with identifier variables
 * (`*.id` / `*_id`) stripped (SF3.2). Supplements a NULL catalog so a schedule (or
 * any-form form_submitted) editor still offers the trigger's system variables (§4.7).
 */
export function triggerSystemVariables(
  triggerType: WorkflowTriggerType | null | undefined,
): CatalogVariable[] {
  return rawTriggerSystemVariables(triggerType).filter((v) => !isIdVariable(v.path));
}

/**
 * The catalog's non-step variables MERGED with the static trigger system variables for
 * `triggerType`, deduped by path (the catalog is authoritative — its copy wins). When a
 * catalog is present it already carries the trigger system vars, so the static mirror
 * only fills the gap for a NULL catalog (schedule / any-form form_submitted).
 */
function nonStepVariables(
  catalog: WorkflowCatalog | null | undefined,
  triggerType: WorkflowTriggerType | null | undefined,
): CatalogVariable[] {
  const catalogNonStep = (catalog?.variables ?? []).filter((v) => v.source !== 'steps');
  const seen = new Set(catalogNonStep.map((v) => v.path));
  // Use the RAW mirror for the merge; the OFFERING helpers below apply the id filter
  // once on the combined list (so both a catalog id var and a system id var drop).
  const systemExtras = rawTriggerSystemVariables(triggerType).filter((v) => !seen.has(v.path));
  return [...catalogNonStep, ...systemExtras];
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
 * @param catalog  the workflow-catalog (variables + fields); may be null (schedule
 *                 trigger / no form) — then the trigger SYSTEM variables (by
 *                 `triggerType`) + the step outputs are offered.
 * @param steps    the full ordered step list (type + key).
 * @param position the field's own step index (earlier-only scoping).
 * @param triggerType the workflow's trigger type — supplements a null catalog with the
 *                 trigger's system variables (e.g. `trigger.scheduled_at` for schedule).
 */
export function toEditorVariables(
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  position: number,
  triggerType?: WorkflowTriggerType | null,
): VariableDefinition[] {
  // System + field variables carry full paths already; drop the catalog's own template
  // step outputs (source 'steps') — the live KEY-substituted ones replace them — and
  // merge the static trigger system vars so a null catalog still offers them.
  const nonStep = nonStepVariables(catalog, triggerType);

  const stepOutputs = positionScopedStepOutputs(steps, position);

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
  const nonStep = nonStepVariables(catalog, triggerType);
  const stepOutputs = positionScopedStepOutputs(steps, position);

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
  const inCatalog = (catalog?.variables ?? []).find((v) => v.path === path);
  if (inCatalog) return inCatalog.type;

  // A trigger SYSTEM variable (RAW mirror — NOT the offered/filtered list, so a
  // saved id ref like `trigger.form.id` still recovers its type, SF3.2).
  const inSystem = rawTriggerSystemVariables(triggerType).find((v) => v.path === path);
  if (inSystem) return inSystem.type;

  // Step outputs (all steps, any position — type recovery is position-agnostic).
  const stepOutput = positionScopedStepOutputs(steps, steps.length).find((v) => v.path === path);
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
  // RAW mirror (unfiltered) so a saved id ref still resolves its full descriptor.
  const inSystem = rawTriggerSystemVariables(triggerType).find((v) => v.path === path);
  if (inSystem) return inSystem;
  return positionScopedStepOutputs(steps, steps.length).find((v) => v.path === path) ?? null;
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

  const nonStep = nonStepVariables(catalog, triggerType);
  const stepOutputs = positionScopedStepOutputs(steps, position);

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
