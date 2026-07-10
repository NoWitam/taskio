// Workflow (automation) domain types for the isolated "next" frontend (Etap 5.1).
//
// REVISION 2 (B7a) — these MIRROR the VERIFIED B1–B5 backend contract 1:1, no
// invented fields:
//   • WorkflowListResource  (GET /workflows — cursorPaginate(8), created_at desc)
//   • WorkflowResource      (GET /workflows/{id}, POST, PUT, restore, status)
//   • WorkflowRunResource   (GET /workflows/{id}/runs — cursorPaginate(15))
//   • WorkflowRunStepResource (the run-detail step timeline rows)
//   • StoreWorkflowRequest / UpdateWorkflowRequest (the write body + trigger_config)
//   • RunWorkflowRequest    ({ target_id? } → 202 run)
//   • WorkflowScheduleFamily::paramDescriptors() (the 12 families + bounds + lt)
//   • WorkflowVariableType (the 6 types + the 18 typed operators)
//   • WorkflowVariableCatalogService / WorkflowScheduleFamilyCatalog / -AssistService
//
// Response wrapping: Workflow resources declare no `data` key, so Laravel's default
// wrapper applies — EVERY body is `{ data: ... }`. Single resource → `res.data`;
// collection → `res.data` + `res.meta` (cursor fields ONLY — NO `total`).
//
// Self-contained: NO import from the legacy `resources/js/`.
//
// ── B7a → B7e scope note ────────────────────────────────────────────────────
// The 5.1 re-scope NARROWS trigger types (5→2) and step types (4→2) and REPLACES
// the flat condition/trigger_config shapes with typed ones. Every editor + read
// slice (B7b steps + schedule, B7c step cards, B7d trigger/drawer/model, B7e
// detail overview + run-now) now consumes the typed shapes directly. The last
// legacy-compat aliases (`LegacyWorkflowTriggerType` / `LegacyWorkflowStepType`) were
// DELETED by B7e once TargetPickerModal + WorkflowDetailView + workflowMeta stopped
// leaning on the widened enums — this file now mirrors the strict 2+2 unions only.

// --- Enums (mirror the backend enums verbatim) -----------------------------

/**
 * A workflow's operational status — a two-state toggle. A workflow is either live
 * (`active`, its trigger may fire) or off (`inactive`). Status is NEVER sent on
 * create/update; it is toggled through `PATCH /workflows/{id}/status`.
 */
export type WorkflowStatus = 'active' | 'inactive';

/**
 * The TWO trigger types (5.1). Each owns a distinct `trigger_config` shape (§4.4).
 * The three task/approval triggers of REV 1 are GONE.
 */
export type WorkflowTriggerType = 'form_submitted' | 'schedule';

/**
 * The TWO step types (ordered actions). `assign_bot`/`attach_form`/`start_approval`
 * are GONE — form + pipeline attach live INSIDE `create_task`; assignment is a
 * first-class `create_task` field (§4.6).
 */
export type WorkflowStepType = 'create_task' | 'create_form_report';

/**
 * The canonical TYPE of a workflow variable / condition field (mirrors
 * `WorkflowVariableType`). The editor primitive vocabulary is narrower
 * (text|number|boolean); date/enum/multi degrade to text inside a directive.
 */
export type WorkflowVariableType = 'text' | 'number' | 'boolean' | 'date' | 'enum' | 'multi';

/** The editor PRIMITIVE a workflow type serializes to inside a markdown directive. */
export type WorkflowVariablePrimitive = 'text' | 'number' | 'boolean';

/**
 * The 18 TYPED condition operators (mirrors `WorkflowConditionOperator`). Every
 * operator belongs to exactly one field type's allow-list (§4.8).
 */
export type WorkflowConditionOperator =
  // text
  | 'equals'
  | 'not_equals'
  | 'contains'
  // number
  | 'eq'
  | 'neq'
  | 'gt'
  | 'gte'
  | 'lt'
  | 'lte'
  // date
  | 'before'
  | 'after'
  | 'on'
  | 'between'
  // enum
  | 'is'
  | 'is_not'
  | 'in'
  // multi
  | 'includes'
  | 'excludes'
  // boolean (value-less)
  | 'is_true'
  | 'is_false';

/**
 * The 16 schedule cadence FAMILIES (mirrors `WorkflowScheduleFamily`). The FE never
 * hard-codes a family's inputs — it renders from the /meta descriptors — but the
 * closed union is the vocabulary describeSchedule + the builder tier grouping use.
 * (B4 added `every_n_months`, `nth_weekday_of_month`, `last_weekday_of_month`,
 * `last_working_day_of_month`; `weekly` changed from a scalar `weekday` to a
 * `weekdays` list.)
 */
export type WorkflowScheduleFamily =
  | 'every_n_minutes'
  | 'hourly'
  | 'hourly_at'
  | 'every_n_hours'
  | 'daily'
  | 'twice_daily'
  | 'weekly'
  | 'monthly'
  | 'twice_monthly'
  | 'last_day_of_month'
  | 'quarterly'
  | 'yearly'
  | 'every_n_months'
  | 'nth_weekday_of_month'
  | 'last_weekday_of_month'
  | 'last_working_day_of_month';

/** A form-submission source (form_submitted trigger's `source.in[]`). */
export type SubmissionSource = 'manual' | 'task';

/** A form-report source (create_form_report step's `sources[]`) — NOTE: task|form. */
export type FormReportSource = 'task' | 'form';

/**
 * A run's lifecycle state. `waiting` + `cancelled` are RESERVED (not produced by
 * the MVP engine) but declared so the frontend badge map is exhaustive.
 */
export type WorkflowRunState =
  | 'pending'
  | 'running'
  | 'waiting'
  | 'completed'
  | 'failed'
  | 'cancelled';

/**
 * The six run states in a stable order (used by the Runs filter SegmentedControl,
 * §5.2). `waiting` + `cancelled` are RESERVED (may show zero rows) but rendered so
 * the IA is visible.
 */
export const RUN_STATES = [
  'pending',
  'running',
  'waiting',
  'completed',
  'failed',
  'cancelled',
] as const;

/**
 * Why a run began. `event` corresponds to a form-submission-triggered run in 5.1
 * (event triggers are gone; the wire value is carried unchanged, §9-note-1).
 */
export type WorkflowRunOrigin = 'event' | 'schedule' | 'manual';

/** The three origins in a stable order (used by the Runs filter SegmentedControl). */
export const RUN_ORIGINS = ['event', 'schedule', 'manual'] as const;

/** The status of a single executed step within a run (audit row). */
export type WorkflowRunStepStatus = 'pending' | 'succeeded' | 'failed';

/** A tone string the backend attaches to a run state / step status. */
export type WorkflowTone = 'neutral' | 'info' | 'warning' | 'success' | 'danger';

// --- Value-or-variable union (§4.9 — mirrors validateUnionOrLiteral) --------

/**
 * A variable reference (the `{kind:'variable'}` arm). `path` is the full dotted
 * path (e.g. `trigger.fields.abc`, `steps.<key>.task_id`); `type` is the TRUE
 * workflow type (NOT the editor primitive). Mirrors the backend's ref shape.
 */
export interface WorkflowVariableRef {
  source: 'trigger' | 'steps';
  path: string;
  type: WorkflowVariableType;
}

/**
 * A structured field that is EITHER a literal OR a variable reference (§4.9). Bare
 * scalars are also accepted as literals by the backend; this is the canonical
 * emitted union.
 */
export type WorkflowFieldValue<T = unknown> =
  | { kind: 'literal'; value: T }
  | { kind: 'variable'; ref: WorkflowVariableRef };

// --- trigger_config (per-type wire shapes, §4.4) ---------------------------

/**
 * Schedule exclusions (B4). Every array is OPTIONAL + emit-or-omit: omit a key when
 * empty. `months` int[1..12] (max 11); `weekdays` int[0..6], 0=Sunday (max 6);
 * `dates` 'YYYY-MM-DD' strings (max 50); all values unique. When all present
 * exclusions strip every occurrence the server rejects the save (422).
 */
export interface WorkflowScheduleExclusions {
  months?: number[];
  weekdays?: number[];
  dates?: string[];
}

/** The `schedule.*` sub-object (schedule trigger only, §4.4/§4.5). */
export interface WorkflowScheduleConfig {
  family: WorkflowScheduleFamily;
  /** Only the selected family's descriptor params (int → number, time → "HH:mm", weekday(_list) → 0..6). */
  params: Record<string, number | string | number[]>;
  /** Optional IANA timezone; defaults to UTC server-side. */
  tz?: string | null;
  /**
   * OPTIONAL multi-time list (B4): 1..6 unique 'HH:mm', ONLY for families carrying a
   * `time` param, MUTUALLY EXCLUSIVE with `params.time` (never both on the wire).
   */
  times?: string[];
  /** OPTIONAL exclusions (B4); omit entirely when nothing is excluded. */
  exclusions?: WorkflowScheduleExclusions;
}

/**
 * Request body for `POST /workflows/meta/schedule-preview` (B4). `count` is
 * optional (1..12, server default 6).
 */
export interface SchedulePreviewRequest {
  schedule: WorkflowScheduleConfig;
  count?: number;
}

/**
 * `POST /workflows/meta/schedule-preview` response (B4). `occurrences` are ISO8601
 * UTC ascending. `empty:true` (occurrences=[]) means the schedule never fires (NOT
 * a 422). `approximate:true` ONLY for `every_n_minutes` (phase is measured from
 * activation, so the dates are indicative).
 */
export interface SchedulePreviewResponse {
  occurrences: string[];
  count: number;
  empty: boolean;
  approximate: boolean;
}

/**
 * `trigger_config` for the `form_submitted` trigger. `form_id` null = "any form".
 * `source` omitted or null = "any source"; `anonymous` null = "any".
 */
export interface FormSubmittedTriggerConfig {
  form_id: string | null;
  source?: { in: SubmissionSource[] } | null;
  anonymous?: boolean | null;
}

/** `trigger_config` for the `schedule` trigger. */
export interface ScheduleTriggerConfig {
  schedule: WorkflowScheduleConfig;
}

/**
 * The full `trigger_config` map (read side). The backend rejects any key outside
 * the selected type's allow-list, so on WRITE the payload builder emits ONLY the
 * shape for the chosen type; on READ the UI tolerates whichever keys are present.
 */
export interface WorkflowTriggerConfig {
  /** form_submitted. */
  form_id?: string | null;
  source?: { in: SubmissionSource[] } | null;
  anonymous?: boolean | null;
  /** schedule. */
  schedule?: WorkflowScheduleConfig;
}

// --- Conditions + steps ----------------------------------------------------

/**
 * One TYPED gate condition (§4.8). `field` is a `fields.<id>` path; `field_type`
 * drives the operator set + value shape; `value` is shaped by (field_type,
 * operator) — a scalar, `[from,to]` for between, `string[]` for in, omitted for
 * is_true/is_false. Mirrors `{field, field_type, operator, value}`.
 */
export interface WorkflowCondition {
  field: string;
  field_type: WorkflowVariableType;
  operator: WorkflowConditionOperator;
  value?: unknown;
}

/**
 * One ordered step. `key` is the distinct reference id used by later steps
 * (`steps.<key>.…`). `config` is a type-specific map (see the per-type configs).
 */
export interface WorkflowStep {
  type: WorkflowStepType;
  key: string;
  config?: Record<string, unknown> | null;
}

// --- Step config shapes (§4.6, mirror StoreWorkflowRequest::allowedStepKeys) --

/** `create_task` config (§4.6.1). Outputs: steps.<key>.{task_id,title}. */
export interface CreateTaskStepConfig {
  /** Required. May carry variable directives (markdown string). */
  title: string;
  description?: string | null;
  /** Literal enum (low|medium|high|urgent) OR a variable ref. */
  priority?: WorkflowFieldValue<string> | string | null;
  /** Literal date string OR a variable ref. */
  deadline?: WorkflowFieldValue<string> | string | null;
  /** Literal label ids only (no variable). */
  labels?: string[] | null;
  /** Both-or-neither with assignee_id. */
  assignee_type?: 'user' | 'bot' | null;
  assignee_id?: string | null;
  form_id?: string | null;
  approval_pipeline_id?: string | null;
}

/** `create_form_report` config (§4.6.2). Outputs: steps.<key>.{report_id,report_name}. */
export interface CreateFormReportStepConfig {
  /** Required scoped Form uuid. */
  form_id: string;
  /** Required. May carry variable directives. */
  name: string;
  guidelines?: string | null;
  /** Subset of ['task','form'] (NOT manual/task) — omit ⇒ server defaults. */
  sources?: FormReportSource[] | null;
  /** Literal date string OR a variable ref (optional; server defaults apply). */
  submissions_from?: WorkflowFieldValue<string> | string | null;
  submissions_to?: WorkflowFieldValue<string> | string | null;
}

// --- Schedule descriptors (GET /workflows/meta/schedule-families, §4.5) ----

/**
 * A single param descriptor's type vocabulary. `weekday_list` (B4) is a non-empty
 * unique array of int 0..6 (0=Sunday) — used by the new `weekly` shape.
 */
export type ScheduleParamType = 'int' | 'time' | 'weekday' | 'weekday_list';

/**
 * One per-family param descriptor (mirrors `paramDescriptors()`): `{name, type,
 * required, min?, max?, lt?}`. `lt` names ANOTHER param this one must be strictly
 * less than (the ordering invariant).
 */
export interface ScheduleParamDescriptor {
  name: string;
  type: ScheduleParamType;
  required: boolean;
  min?: number;
  max?: number;
  lt?: string;
}

/** One family entry from the meta endpoint: `{family, params}`. */
export interface ScheduleFamilyDescriptor {
  family: WorkflowScheduleFamily;
  params: ScheduleParamDescriptor[];
}

/** The meta envelope `{ data: ScheduleFamilyDescriptor[] }`. */
export interface ScheduleFamiliesResponse {
  data: ScheduleFamilyDescriptor[];
}

// --- Schedule assist (POST /workflows/schedule-assist, §4.5.5) --------------

/** The re-validated schedule config the assist returns (same shape as the wire). */
export interface ScheduleConfig {
  family: WorkflowScheduleFamily;
  params: Record<string, number | string | number[]>;
  tz?: string | null;
  times?: string[];
  exclusions?: WorkflowScheduleExclusions;
}

/** The assist request body. */
export interface ScheduleAssistRequest {
  prompt: string;
  tz?: string | null;
}

/**
 * The four-state assist envelope (§4.5.5): feasible+config (apply), infeasible+
 * alternative, infeasible, or (HTTP-level) throttle/failure. All model text
 * (`explanation`/`unsupported[]`/`note`) is PLAIN TEXT (untrusted — never HTML).
 */
export interface ScheduleAssistEnvelope {
  feasible: boolean;
  config: ScheduleConfig | null;
  unsupported: string[];
  alternative: { config: ScheduleConfig; note: string } | null;
  explanation: string;
}

/** The assist response wrapper `{ data: ScheduleAssistEnvelope }`. */
export interface ScheduleAssistResponse {
  data: ScheduleAssistEnvelope;
}

// --- Variable catalog (GET /forms/{form}/workflow-catalog, §4.7) -----------

/**
 * One reference-able variable (mirrors WorkflowVariableCatalogService::variable):
 * `{source, path, name, type, enumOptions?, nullable?}`.
 */
export interface CatalogVariable {
  source: 'trigger' | 'steps';
  path: string;
  name: string;
  type: WorkflowVariableType;
  enumOptions?: string[];
  nullable?: boolean;
}

/**
 * One condition FIELD descriptor (mirrors conditionFields): `{path, field_id,
 * label, type, enumOptions?, operators}`. `path` is `fields.<id>`; `operators`
 * equals WorkflowVariableType.operators() for the field's type.
 */
export interface CatalogField {
  path: string;
  field_id: string;
  label: string;
  type: WorkflowVariableType;
  enumOptions?: string[];
  operators: string[];
}

/** The catalog payload `{variables, fields}`. */
export interface WorkflowCatalog {
  variables: CatalogVariable[];
  fields: CatalogField[];
}

/** The catalog response wrapper `{ data: WorkflowCatalog }`. */
export interface WorkflowCatalogResponse {
  data: WorkflowCatalog;
}

// --- List + detail resources -----------------------------------------------

/** A workflow LIST row (WorkflowListResource). */
export interface WorkflowListItem {
  id: string;
  name: string;
  status: WorkflowStatus;
  description: string | null;
  /** General-info icon identifier (nullable). */
  icon: string | null;
  trigger_type: WorkflowTriggerType;
  /** Number of steps (server-computed count). */
  step_count: number;
  /** Next scheduled run (schedule trigger; null otherwise / until armed). */
  next_due_at: string | null;
  is_owner: boolean;
  created_at: string | null;
}

/** The FULL workflow (WorkflowResource) — the complete definition + capability flags. */
export interface WorkflowDetail {
  id: string;
  name: string;
  status: WorkflowStatus;
  description: string | null;
  icon: string | null;
  trigger_type: WorkflowTriggerType;
  trigger_config: WorkflowTriggerConfig;
  conditions: WorkflowCondition[];
  steps: WorkflowStep[];
  /** Scheduling — populated by the scheduler; may be null. */
  last_scheduled_run_at: string | null;
  next_due_at: string | null;
  /** `whenLoaded('creator')`. */
  creator?: { id: string | number; name: string; email?: string | null; avatar?: string | null } | null;
  // Capability flags — ALWAYS present as booleans (never absent).
  is_owner: boolean;
  can_be_edited: boolean;
  can_be_deleted: boolean;
  can_change_status: boolean;
  can_run: boolean;
  created_at: string | null;
  updated_at: string | null;
}

// --- List query / envelopes ------------------------------------------------

/**
 * The list-screen filter state. Mirrors the `/workflows` query params 1:1 — the
 * server filters are `search` (name/description) and `status`. The page owns this;
 * the store serializes it.
 */
export interface WorkflowFilters {
  search?: string;
  status?: WorkflowStatus;
}

/** Cursor-paginated list envelope. Meta carries cursor fields ONLY (no `total`). */
export interface WorkflowListMeta {
  next_cursor: string | null;
}
export interface WorkflowListResponse {
  data: WorkflowListItem[];
  meta: WorkflowListMeta;
}

/** Detail envelope from `GET /workflows/{id}` + create/update/status/restore. */
export interface WorkflowDetailResponse {
  data: WorkflowDetail;
}

// --- Write payload (Store/Update request) ----------------------------------

/**
 * The workflow write body. Mirrors the FormRequest 1:1: name (req ≤255),
 * description (nullable ≤2500), icon (nullable ≤100), trigger_type (req),
 * trigger_config (per-type shape), conditions ([] ≤50), steps (req ≥1 ≤50, each
 * `{ type, key, config? }`). `status` is NEVER sent here (toggled via the status
 * endpoint).
 */
export interface WorkflowWritePayload {
  name: string;
  description?: string | null;
  icon?: string | null;
  trigger_type: WorkflowTriggerType;
  trigger_config?: WorkflowTriggerConfig;
  conditions?: WorkflowCondition[];
  steps: WorkflowStep[];
}

/** Body for `PATCH /workflows/{id}/status`. */
export interface WorkflowStatusPayload {
  status: WorkflowStatus;
}

/** Body for `POST /workflows/{id}/run` — the optional per-trigger target. */
export interface WorkflowRunPayload {
  target_id?: string | null;
}

// --- Runs (WorkflowRunResource, cursor-paginated 15/page) ------------------

/**
 * One workflow RUN. Three shapes share this type:
 *   • the 202 manual-run response (core + timing),
 *   • the run INDEX row (core + `steps_count` + `duration_seconds`),
 *   • the run SHOW detail (adds `trigger_payload` + the ordered `steps` timeline).
 * `steps_count` is present only on the index; `trigger_payload` / `steps` only on
 * the show — callers must treat both as optional.
 */
export interface WorkflowRun {
  id: string;
  state: WorkflowRunState;
  /** Server-provided label (the FE prefers its own i18n key, falls back to this). */
  state_label: string;
  /** Tone → the FE derives the Badge variant from this. */
  state_tone: WorkflowTone;
  origin: WorkflowRunOrigin;
  trigger_type: WorkflowTriggerType;
  /** >0 for a run spawned by another run. */
  depth: number;
  origin_run_id: string | null;
  error: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string | null;
  /** Wall-clock seconds; null unless the run both started and finished. */
  duration_seconds: number | null;
  /** Index only (withCount). */
  steps_count?: number;
  /** Detail only. */
  trigger_payload?: Record<string, unknown> | null;
  /** Detail only — the ordered per-step audit timeline. */
  steps?: WorkflowRunStep[];
}

/** One executed step within a run (audit row) — the run-detail timeline. */
export interface WorkflowRunStep {
  id: string;
  position: number;
  type: WorkflowStepType;
  key: string;
  status: WorkflowRunStepStatus;
  status_label: string;
  status_tone: WorkflowTone;
  payload: Record<string, unknown> | null;
  error: string | null;
  created_at: string | null;
}

/** The runs list-screen filter state. Mirrors `/runs` query params (both optional). */
export interface WorkflowRunFilters {
  state?: WorkflowRunState;
  origin?: WorkflowRunOrigin;
}

/** Cursor-paginated runs envelope. */
export interface WorkflowRunListResponse {
  data: WorkflowRun[];
  meta: { next_cursor: string | null };
}

/** Detail envelope from `GET /workflows/{id}/runs/{run}` (+ the 202 run response). */
export interface WorkflowRunResponse {
  data: WorkflowRun;
}

// The pre-5.1 LEGACY-COMPAT aliases (`LegacyWorkflowTriggerType` /
// `LegacyWorkflowStepType`) were DELETED by B7e — the last consumers (TargetPickerModal,
// WorkflowDetailView, workflowMeta) were rebuilt to the strict 2+2 unions above.
