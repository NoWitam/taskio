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
import type { VariableDefinition, VariablePrimitive } from '../../ui/editor/extensions/types';
import type {
  CatalogVariable,
  WorkflowCatalog,
  WorkflowStepType,
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
 *                 trigger / no form) — then only the step outputs are offered.
 * @param steps    the full ordered step list (type + key).
 * @param position the field's own step index (earlier-only scoping).
 */
export function toEditorVariables(
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
  position: number,
): VariableDefinition[] {
  const catalogVariables = catalog?.variables ?? [];

  // System + field variables carry full paths already; drop the catalog's own
  // template step outputs (source 'steps') — the live KEY-substituted ones replace them.
  const nonStep = catalogVariables.filter((v) => v.source !== 'steps');

  const stepOutputs = positionScopedStepOutputs(steps, position);

  return [...nonStep, ...stepOutputs].map((variable) => ({
    id: variable.path, // identity-only: id === path
    name: variable.name,
    type: editorPrimitive(variable.type),
  }));
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
): WorkflowVariableType | null {
  const inCatalog = (catalog?.variables ?? []).find((v) => v.path === path);
  if (inCatalog) return inCatalog.type;

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
): CatalogVariable | null {
  const inCatalog = (catalog?.variables ?? []).find((v) => v.path === path);
  if (inCatalog) return inCatalog;
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
): CatalogVariable[] {
  const accepted = new Set(Array.isArray(types) ? types : [types]);

  const nonStep = (catalog?.variables ?? []).filter((v) => v.source !== 'steps');
  const stepOutputs = positionScopedStepOutputs(steps, position);

  return [...nonStep, ...stepOutputs].filter((v) => accepted.has(v.type));
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
 */
export function stripVariableDirectives(
  text: string | null | undefined,
  catalog: WorkflowCatalog | null | undefined,
  steps: StepLike[],
): string {
  if (!text) return '';
  return text.replace(DIRECTIVE_RE, (_match, escaped: string) => {
    const payload = parseDirectivePayload(escaped);
    if (!payload) return ''; // an unparseable directive collapses to nothing, never bytes
    // Prefer the catalog's authoritative name (by the directive id = path).
    if (payload.id) {
      const resolved = resolveVariable(payload.id, catalog, steps);
      if (resolved) return resolved.name;
    }
    return payload.name ?? '';
  });
}
