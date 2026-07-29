// workflowEditorModel — the PURE, testable core of the Workflows editor drawer.
//
// Extracted from WorkflowEditorDrawer so the highest-risk logic — step
// add/remove/reorder invariants, unique key suggestion, per-index error routing,
// duplicate-key detection, and the EXACT per-type step-config wire emission (§4.6)
// — is unit-testable WITHOUT mounting the drawer.
//
// ── 5.1 rebuild (B7c steps + B7d trigger) ─────────────────────────────────────
// The step side is the typed 5.1 world: `create_task`, `create_form_report` and — since
// R2 sub-stage 5 — `generate_content`, each with its full field set. `emptyStepConfig`
// seeds each type's editable draft shape and `buildStepConfig` projects a draft onto the
// EXACT backend `config` wire (empty-string optionals STRIPPED; value-or-variable unions
// passed through untouched; assignee emitted both-or-neither; the generate_content SLOT
// MAP emitted entry-by-entry with empty entries dropped so "unmapped" stays unmapped).
//
// The trigger side is now the typed 5.1 world too (B7d): a `FormTriggerDraft`
// ({form_id, source, anonymous}) + the schedule builder's `ScheduleDraft`
// (workflowSchedule.ts). The trigger_config wire is built INLINE in the drawer
// (per-type, emit-discipline) — the drawer owns the whole payload assembly now, so
// the legacy `TriggerDraft`/`buildTriggerConfig`/`buildWorkflowPayload`/
// `ConditionDraft`/`WorkflowDraft` bridges are GONE. Conditions are the typed §4.8
// `WorkflowCondition[]`; `sanitizeConditions` drops incomplete rows on save.
import type {
  WorkflowCondition,
  WorkflowFieldValue,
  WorkflowStepType,
} from './types';

/**
 * The client-side ceiling on steps (mirrors the backend `steps` max:50 rule). The add
 * affordance disables at this count so the user never trips a 422 (§4.6).
 */
export const MAX_STEPS = 50;

/**
 * The client-side ceiling on `generate_content` steps in ONE workflow (mirrors the backend
 * `StoreWorkflowRequest::GENERATE_CONTENT_MAX`). Each one is a whole AI generation run —
 * by far the most expensive thing a workflow can do — AND it parks the run while it
 * settles, so chaining several multiplies both the spend and the wall clock of a single
 * trigger. The add affordance disables at this count so the author never trips the 422.
 */
export const MAX_GENERATE_CONTENT_STEPS = 2;

/** How many steps of `type` the draft list already holds (drives the per-type add gate). */
export function countStepsOfType(steps: StepDraft[], type: WorkflowStepType): number {
  return steps.filter((step) => step.type === type).length;
}

/** The step key charset — path-safe (a dot/space would break `steps.<key>.<name>` refs). */
export const STEP_KEY_RE = /^[A-Za-z0-9_]+$/;

/** Strip every character a step key may not contain (used to NORMALIZE typed input). */
export function sanitizeStepKey(value: string): string {
  return value.replace(/[^A-Za-z0-9_]/g, '');
}

/** One local step draft. `uid` is a stable local key so reorder remounts cleanly. */
export interface StepDraft {
  uid: string;
  type: WorkflowStepType;
  key: string;
  /** Type-specific config (each field a literal, a value-or-variable union, or an id). */
  config: Record<string, unknown>;
}

/**
 * The `form_submitted` trigger's editable draft (§4.4). `form_id` null = "any form".
 * `source` is the checked subset of ['manual','task'] ([] = "any"); `anonymous` is
 * the tri-state null|true|false (null = "any"). The drawer projects this onto the
 * exact wire shape (source emitted only when non-empty; anonymous only when not-any).
 */
export interface FormTriggerDraft {
  form_id: string | null;
  source: Array<'manual' | 'task'>;
  anonymous: boolean | null;
}

/** A fresh empty form-submitted draft (any form / any source / any anonymity). */
export function emptyFormTriggerDraft(): FormTriggerDraft {
  return { form_id: null, source: [], anonymous: null };
}

// --- Empty shapes ----------------------------------------------------------

/**
 * The empty `config` map for a freshly-added step of `type` (§4.6). Only the fields
 * the step type owns are seeded so the card renders the right controls immediately
 * with a correctly-shaped config. Free text = `''`, value-or-variable = `null`,
 * multi = `[]`, ids = `null`; the assignee pair is seeded as a null pair.
 */
export function emptyStepConfig(type: WorkflowStepType): Record<string, unknown> {
  switch (type) {
    case 'create_task':
      return {
        title: '',
        description: '',
        priority: null,
        deadline: null,
        labels: [],
        attachments: null,
        assignee_type: null,
        assignee_id: null,
        form_id: null,
        approval_pipeline_id: null,
      };
    case 'create_form_report':
      return {
        form_id: null,
        name: '',
        guidelines: '',
        sources: null,
        submissions_from: null,
        submissions_to: null,
      };
    case 'generate_content':
      // `slots` starts EMPTY (a map of the chosen template's declared slot names →
      // value-or-variable). It is only ever populated once a template is picked, and it
      // is NEVER auto-reset from under the author on hydration (template drift is WARNED
      // about, not silently overwritten).
      return {
        template_id: null,
        slots: {},
        folder_id: null,
        name: '',
      };
    default:
      return {};
  }
}

// --- Unique key suggestion -------------------------------------------------

/** The base key prefix suggested for a new step of `type` (create_task → `task`). */
const STEP_KEY_BASE: Record<WorkflowStepType, string> = {
  create_task: 'task',
  create_form_report: 'report',
  generate_content: 'content',
};

/**
 * Suggest a UNIQUE step key for a new `type`, given the keys already in use. Starts
 * from the type's base prefix and appends `_2`, `_3`, … until unique so the
 * client-side distinct check passes for a freshly-added card.
 */
export function suggestStepKey(type: WorkflowStepType, existingKeys: string[]): string {
  const base = STEP_KEY_BASE[type] ?? 'step';
  const used = new Set(existingKeys.filter((k) => k && k.length > 0));
  if (!used.has(base)) return base;
  let i = 2;
  while (used.has(`${base}_${i}`)) i += 1;
  return `${base}_${i}`;
}

// --- Step list invariants (add / remove / reorder) -------------------------

let uidSeq = 0;
/** A process-unique local uid for a draft row (never sent to the server). */
export function nextUid(prefix = 'row'): string {
  uidSeq += 1;
  return `${prefix}-${uidSeq}`;
}

/** Build a fresh step draft of `type` with a suggested unique key + empty config. */
export function makeStepDraft(type: WorkflowStepType, existingKeys: string[]): StepDraft {
  return {
    uid: nextUid('step'),
    type,
    key: suggestStepKey(type, existingKeys),
    config: emptyStepConfig(type),
  };
}

/**
 * Move the step at `index` by `dir` (-1 up, +1 down). Returns a NEW array (or the
 * same reference when the move is a no-op at an edge) so callers can assign it.
 */
export function moveStep(steps: StepDraft[], index: number, dir: -1 | 1): StepDraft[] {
  const target = index + dir;
  if (target < 0 || target >= steps.length) return steps;
  const next = steps.slice();
  const [moved] = next.splice(index, 1);
  next.splice(target, 0, moved);
  return next;
}

/**
 * Remove the step at `index`, but NEVER below one step (min:1 backend rule).
 * Returns a NEW array, or the same reference when the removal is prevented.
 */
export function removeStep(steps: StepDraft[], index: number): StepDraft[] {
  if (steps.length <= 1) return steps;
  const next = steps.slice();
  next.splice(index, 1);
  return next;
}

// --- Duplicate-key detection (client-side distinct) ------------------------

/**
 * The set of step UIDs whose `key` collides with another step's key (case-
 * sensitive, trimmed). Used to surface a duplicate-key error on BOTH offending
 * cards (the backend `distinct` rule rejects duplicates).
 */
export function duplicateKeyUids(steps: StepDraft[]): Set<string> {
  const byKey = new Map<string, string[]>();
  steps.forEach((s) => {
    const k = s.key.trim();
    if (!k) return;
    const list = byKey.get(k) ?? [];
    list.push(s.uid);
    byKey.set(k, list);
  });
  const dupes = new Set<string>();
  byKey.forEach((uids) => {
    if (uids.length > 1) uids.forEach((u) => dupes.add(u));
  });
  return dupes;
}

// --- Step config builder (§4.6 — the EXACT per-type wire) -------------------

/** A trimmed string, or undefined when empty (so an empty optional is OMITTED). */
function trimmedOrOmit(value: unknown): string | undefined {
  const s = String(value ?? '').trim();
  return s === '' ? undefined : s;
}

/**
 * A value-or-variable union passed through UNTOUCHED, or undefined when empty.
 * A `{kind:'literal', value:null}` (the add-on's cleared state) collapses to
 * undefined so it is omitted; a real literal or variable ref is emitted verbatim. The
 * variable arm's OPTIONAL operations `pipeline` (SF1) rides through untouched when
 * non-empty; a stray EMPTY pipeline array is dropped so an identity ref stays lean.
 */
function unionOrOmit(value: unknown): WorkflowFieldValue | undefined {
  if (value == null) return undefined;
  if (typeof value === 'object' && 'kind' in (value as Record<string, unknown>)) {
    const union = value as WorkflowFieldValue;
    if (union.kind === 'literal') {
      const v = union.value;
      // An empty literal (null / '') is "not set" → omit.
      if (v == null || v === '') return undefined;
      return union;
    }
    // A variable ref — always meaningful. Strip an empty pipeline so an identity ref
    // never emits `pipeline: []` (which the backend treats as identity anyway).
    if (Array.isArray(union.pipeline) && union.pipeline.length === 0) {
      return { kind: 'variable', ref: union.ref };
    }
    return union;
  }
  // A bare scalar in a structured slot is a literal the backend accepts as-is.
  if (value === '') return undefined;
  return { kind: 'literal', value } as WorkflowFieldValue;
}

/** A non-empty string[] passed through, or undefined (so an empty array is OMITTED). */
function arrayOrOmit(value: unknown): string[] | undefined {
  if (!Array.isArray(value) || value.length === 0) return undefined;
  return value.map((v) => String(v));
}

/** A non-empty id string, or undefined (so a null/empty id is OMITTED). */
function idOrOmit(value: unknown): string | undefined {
  const s = value == null ? '' : String(value).trim();
  return s === '' ? undefined : s;
}

/**
 * Set `key` on `out` only when `value` is defined — the omit discipline every
 * optional step-config field follows (an absent key = "use the server default").
 */
function put(out: Record<string, unknown>, key: string, value: unknown): void {
  if (value !== undefined) out[key] = value;
}

/**
 * Project the `generate_content` SLOT MAP onto the wire (R2 sub-stage 5): `<slot name>`
 * → the same value-or-variable union every other structured field emits, with EMPTY
 * entries DROPPED (`unionOrOmit`).
 *
 * Dropping an empty entry is load-bearing, not cosmetic: the backend's per-slot rules
 * key on PRESENCE (`array_key_exists`). An unmapped required slot is reported as
 * "must be mapped" on its own `steps.<i>.config.slots.<name>` row, and an unmapped
 * NULLABLE slot is the legitimate "generate with it empty" — which an emitted
 * `{kind:'literal', value:''}` would turn into a mapped-but-blank value instead.
 *
 * `false` / `0` / `[]` are REAL values and survive (only null / '' collapse).
 *
 * Returns undefined when nothing is mapped, so `slots` itself is OMITTED (the backend
 * treats an absent map exactly like an empty one).
 */
function slotMapOrOmit(value: unknown): Record<string, unknown> | undefined {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return undefined;
  const out: Record<string, unknown> = {};
  for (const [name, raw] of Object.entries(value as Record<string, unknown>)) {
    const emitted = unionOrOmit(raw);
    if (emitted !== undefined) out[name] = emitted;
  }
  return Object.keys(out).length > 0 ? out : undefined;
}

/**
 * Project one step draft onto its EXACT wire `config` (§4.6). The required fields
 * (`create_task.title`, `create_form_report.form_id`/`name`) are always present
 * (trimmed for strings); every OPTIONAL is STRIPPED when empty; value-or-variable
 * unions (priority/deadline/report windows) pass through; the assignee pair is
 * BOTH-OR-NEITHER (dropped entirely when no target is picked).
 */
export function buildStepConfig(step: StepDraft): Record<string, unknown> {
  const c = step.config ?? {};
  const out: Record<string, unknown> = {};

  switch (step.type) {
    case 'create_task': {
      // title is required — always emitted (trimmed, possibly empty; the client
      // pre-validation + the server both reject an empty title).
      out.title = String(c.title ?? '').trim();
      put(out, 'description', trimmedOrOmit(c.description));
      put(out, 'priority', unionOrOmit(c.priority));
      put(out, 'deadline', unionOrOmit(c.deadline));
      put(out, 'labels', arrayOrOmit(c.labels));
      put(out, 'attachments', unionOrOmit(c.attachments));

      // Assignee: both-or-neither. Emit the pair only when BOTH a type and an id
      // are present; otherwise drop both keys (the FormRequest requires them
      // together and rejects a half-filled pair).
      const assigneeType = idOrOmit(c.assignee_type);
      const assigneeId = idOrOmit(c.assignee_id);
      if (assigneeType !== undefined && assigneeId !== undefined) {
        out.assignee_type = assigneeType;
        out.assignee_id = assigneeId;
      }

      put(out, 'form_id', idOrOmit(c.form_id));
      put(out, 'approval_pipeline_id', idOrOmit(c.approval_pipeline_id));
      return out;
    }
    case 'create_form_report': {
      // form_id + name are required — always emitted.
      out.form_id = idOrOmit(c.form_id) ?? '';
      out.name = String(c.name ?? '').trim();
      put(out, 'guidelines', trimmedOrOmit(c.guidelines));
      put(out, 'sources', arrayOrOmit(c.sources));
      put(out, 'submissions_from', unionOrOmit(c.submissions_from));
      put(out, 'submissions_to', unionOrOmit(c.submissions_to));
      return out;
    }
    case 'generate_content': {
      // template_id is required — always emitted (the client pre-validation + the server
      // both reject an empty one, so an empty string surfaces as the granular
      // `steps.<i>.config.template_id` error rather than a silently absent key).
      out.template_id = idOrOmit(c.template_id) ?? '';
      put(out, 'slots', slotMapOrOmit(c.slots));
      // Both optionals are emit-or-OMIT: never `""`, never `null` — an absent folder_id
      // means the Disk root, an absent name means "use the template's".
      put(out, 'folder_id', idOrOmit(c.folder_id));
      put(out, 'name', trimmedOrOmit(c.name));
      return out;
    }
    default:
      return out;
  }
}

// --- Conditions (§4.8 — typed clause sanitation) ----------------------------

/**
 * Whether a typed condition row is COMPLETE enough to send (§4.8). A row needs a
 * `fields.<id>` path, a field_type and an operator; the value is required for every
 * operator except the value-less booleans (is_true/is_false). Between needs a
 * two-entry array; `in` needs a non-empty array. Incomplete rows are dropped on save
 * so a half-filled row never becomes a 422.
 */
export function isConditionComplete(condition: WorkflowCondition): boolean {
  if (!condition.field || !condition.field.trim()) return false;
  if (!condition.field_type || !condition.operator) return false;

  const op = condition.operator;
  if (op === 'is_true' || op === 'is_false') return true; // value-less

  if (op === 'between') {
    return Array.isArray(condition.value) && condition.value.length === 2
      && condition.value.every((v) => v != null && v !== '');
  }
  if (op === 'in') {
    return Array.isArray(condition.value) && condition.value.length > 0;
  }

  const v = condition.value;
  return v != null && v !== '';
}

/**
 * Drop incomplete condition rows and trim the field path — the typed §4.8 array the
 * payload sends (an empty result = "always runs", which the backend accepts).
 */
export function sanitizeConditions(conditions: WorkflowCondition[]): WorkflowCondition[] {
  return conditions
    .filter(isConditionComplete)
    .map((c) => ({ ...c, field: c.field.trim() }));
}
