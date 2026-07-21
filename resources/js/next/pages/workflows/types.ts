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

// The polymorphic `creator` union (user | workflow_run | bot) is shared across
// every resource that emits it — imported, never redefined.
import type { Creator } from '../../ui/patterns/creator';

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
export type WorkflowVariableType = 'text' | 'number' | 'boolean' | 'date' | 'enum' | 'multi' | 'file';

/** The editor PRIMITIVE a workflow type serializes to inside a markdown directive. */
export type WorkflowVariablePrimitive = 'text' | 'number' | 'boolean';

/**
 * The 20 TYPED condition operators (mirrors `WorkflowConditionOperator`). Every
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
  | 'is_false'
  // file (value-less): a file field is either answered or not; richer questions
  // (its name, how many) are pipeline ops in the condition tree.
  | 'filled'
  | 'empty';

// REVISION 4 (Phase 4a) — the 16-family model is RETIRED. The `schedule` trigger is
// now a COMPOSITIONAL descriptor v2 (§4.5.1): a TIME axis × a DAY axis × a MONTH
// axis (AND-semantics), minus `exclusions`, in a `tz`. The wire shapes below mirror
// the VERIFIED backend contract EXACTLY (WorkflowScheduleRulesValidator + the
// Schedule{Time,Day,Month,DaySpecial,Limits} enums): the wire is FLAT — `time.minutes`
// / `time.hours` (not `n`), a flat `from`/`to` window (not a nested `window`), and
// `day.special` as a STRING enum (not an object). The FE's richer nested `ScheduleDraft`
// (workflowSchedule.ts) adapts to/from these via `draftToConfig`/`configToDraft`.

/** The DAY axis's `special` rule (mirrors `ScheduleDaySpecial`). */
export type ScheduleDaySpecialKind = 'last_day' | 'last_working_day' | 'nth_weekday' | 'last_weekday';

/**
 * The TIME axis wire (mirrors `ScheduleTimeMode` + the validator's per-mode keys).
 * The ONLY required axis. `at` = 1..6 'HH:mm'; `every_minutes` = a minute step
 * (`minutes` 1..59) with an OPTIONAL HH:mm window; `every_hours` = an hour step
 * (`hours` 1..23) at `minute` (0..59) with an OPTIONAL whole-hour (0..23) window.
 */
export type ScheduleTimeConfig =
  | { mode: 'at'; at: string[] }
  | { mode: 'every_minutes'; minutes: number; from?: string; to?: string }
  | { mode: 'every_hours'; hours: number; minute?: number; from?: number; to?: number };

/**
 * The DAY axis wire (mirrors `ScheduleDayMode`; optional, default `every_day`).
 * `every_n_days` = a day-of-month step (`n` 1..31) with an OPTIONAL 1..31 window;
 * `weekdays` / `month_days` = non-empty sets; `special` names a month-anchored rule
 * with FLAT `ordinal` (1..5) / `weekday` (0..6) params.
 */
export type ScheduleDayConfig =
  | { mode: 'every_day' }
  | { mode: 'every_n_days'; n: number; from?: number; to?: number }
  | { mode: 'weekdays'; weekdays: number[] }
  | { mode: 'month_days'; days: number[] }
  | { mode: 'special'; special: ScheduleDaySpecialKind; ordinal?: number; weekday?: number };

/**
 * The MONTH axis wire (mirrors `ScheduleMonthMode`; optional, default `every_month`).
 * `every_n_months` = a month step (`n` 1..12) with an OPTIONAL 1..12 window;
 * `months` = a non-empty set 1..12.
 */
export type ScheduleMonthConfig =
  | { mode: 'every_month' }
  | { mode: 'every_n_months'; n: number; from?: number; to?: number }
  | { mode: 'months'; months: number[] };

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
 * One operation on a value-or-variable field's OPTIONAL pipeline (§4.9 / SB1). The
 * wire is `{op, args}` — the SAME shape a condition pipeline uses
 * (`WireConditionPipelineStep`). The editor's richer `VariablePipelineStep`
 * (stepId/operationId/args/outputType) is projected onto this on save and rehydrated
 * from it on load (ValueOrVariableField). The backend type-flows the ops from the
 * ref's declared type to the field's accepted terminals (priority → enum|text;
 * deadline / submission windows → date) and 422s under `<field>.pipeline.M…`.
 */
export interface WorkflowFieldPipelineStep {
  op: string;
  args: Record<string, unknown>;
}

/**
 * A structured field that is EITHER a literal OR a variable reference (§4.9). Bare
 * scalars are also accepted as literals by the backend; this is the canonical
 * emitted union. The variable arm may carry an OPTIONAL operations `pipeline` that
 * reshapes the referenced value at run time (omitted for a plain identity ref) AND an
 * OPTIONAL literal `default` (phase-1b): the value the backend substitutes when the
 * referenced value resolves null/'' . Emit-or-OMIT — a ref with no default serializes
 * byte-identically to today (the key is absent).
 */
export type WorkflowFieldValue<T = unknown> =
  | { kind: 'literal'; value: T }
  | { kind: 'variable'; ref: WorkflowVariableRef; pipeline?: WorkflowFieldPipelineStep[]; default?: T };

// --- trigger_config (per-type wire shapes, §4.4) ---------------------------

/**
 * Schedule exclusions (v2). Every array is OPTIONAL + emit-or-omit: omit a key when
 * empty. `months` int[1..12] (max 11); `weekdays` int[0..6], 0=Sunday (max 6);
 * `dates` 'YYYY-MM-DD' strings (max 50); all values unique. When all present
 * exclusions strip every occurrence the server rejects the save (422).
 */
export interface WorkflowScheduleExclusions {
  months?: number[];
  weekdays?: number[];
  dates?: string[];
}

/**
 * The `schedule` trigger_config sub-object — the compositional descriptor v2
 * (§4.5.1). Wire shape mirrors the VERIFIED backend contract: `time` REQUIRED;
 * `day` / `month` OPTIONAL (default `every_day` / `every_month`); `exclusions` a
 * post-filter; `tz` an optional IANA zone (omit ⇒ UTC server-side).
 */
export interface WorkflowScheduleConfig {
  time: ScheduleTimeConfig;
  day?: ScheduleDayConfig;
  month?: ScheduleMonthConfig;
  exclusions?: WorkflowScheduleExclusions;
  tz?: string | null;
}

/**
 * Request body for `POST /workflows/meta/schedule-preview`. `count` optional
 * (1..12, server default 6); `anchor` optional (ISO-8601) centres the projection so
 * `occurrences[0]` is the occurrence AT-OR-BEFORE it (prev-or-at) and the rest
 * ascend after it — the strip pages FORWARD by re-calling with `anchor` = the last
 * shown occurrence (§4.5.4).
 */
export interface SchedulePreviewRequest {
  schedule: WorkflowScheduleConfig;
  count?: number;
  anchor?: string;
}

/**
 * `POST /workflows/meta/schedule-preview` response — FLAT (no `data` wrapper).
 * `occurrences` are ISO8601 UTC ascending. `empty:true` (occurrences=[]) means the
 * schedule never fires (NOT a 422). `approximate` is ALWAYS `false` in v2 (the clock
 * grid makes every projection exact — REV3's indicative note is retired) but is kept
 * in the shape for contract fidelity.
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
 * One TYPED gate condition (§4.8, LEGACY flat shape). `field` is a `fields.<id>`
 * path; `field_type` drives the operator set + value shape; `value` is shaped by
 * (field_type, operator) — a scalar, `[from,to]` for between, `string[]` for in,
 * omitted for is_true/is_false. Mirrors `{field, field_type, operator, value}`.
 *
 * B3 SUPERSEDES this on WRITE with the condition TREE (`WireConditionGroup`) — the
 * backend still accepts the flat list, so a saved OLD workflow reads back as either
 * shape (see `WorkflowDetail.conditions`). The editor converts a flat list to a tree
 * on hydration (`wireToDraft`, workflowConditions.ts).
 */
export interface WorkflowCondition {
  field: string;
  field_type: WorkflowVariableType;
  operator: WorkflowConditionOperator;
  value?: unknown;
}

// --- Condition TREE wire (B3 — groups of AND/OR + pipeline conditions) ---------

/** How a group combines its children (mirrors the backend `logic` enum). */
export type ConditionLogic = 'and' | 'or';

/**
 * One pipeline step on the wire: an operation `op` (a standardOperations id) plus
 * its `args` map. NOTE the WIRE keys are `op`/`args` — the editor's richer
 * `VariablePipelineStep` (stepId/operationId/args/outputType) is projected onto this
 * on save and rehydrated from it on load (workflowConditions.ts).
 */
export interface WireConditionPipelineStep {
  op: string;
  args: Record<string, unknown>;
}

/**
 * A leaf condition: a form-field `source` (`fields.<id>`) of `source_type`, run
 * through a `pipeline` that MUST end on a boolean. Mirrors the backend
 * `{kind:'condition', source, source_type, pipeline}`.
 */
export interface WireCondition {
  kind: 'condition';
  source: string;
  source_type: WorkflowVariableType;
  pipeline: WireConditionPipelineStep[];
}

/**
 * A group node: `logic` (and/or) over `children` (min 1). The ROOT is emitted
 * WITHOUT `kind` (the backend treats the top node as the group root); nested groups
 * carry `kind:'group'`. Depth ≤ 5 (root = 1), children ≤ 10 per group.
 */
export interface WireConditionGroup {
  kind?: 'group';
  logic: ConditionLogic;
  children: WireConditionNode[];
}

export type WireConditionNode = WireCondition | WireConditionGroup;

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

// The REV3 schedule-descriptor types (`ScheduleParamType`, `ScheduleParamDescriptor`,
// `ScheduleFamilyDescriptor`, `ScheduleFamiliesResponse`) were REMOVED in Phase 4a —
// v2 has NO `GET /workflows/meta/schedule-families` endpoint. The FE owns every label
// (§4.5.12) and mirrors the numeric bounds as constants (workflowSchedule.ts).

// --- Schedule assist (POST /workflows/schedule-assist, §4.5.9) --------------

/**
 * The re-validated schedule config the assist returns — the SAME v2 wire shape the
 * write path accepts.
 */
export type ScheduleConfig = WorkflowScheduleConfig;

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

// --- Variable catalog (§4.7) -----------------------------------------------
//
// TWO endpoints serve the SAME `WorkflowCatalog` shape:
//   • GET /forms/{form}/workflow-catalog             — the form-bound catalog (back-compat).
//   • GET /workflows/catalog?trigger_type=&form_id=  — the FORM-INDEPENDENT catalog: the
//     trigger-system vars for `trigger_type` + the `steps.<TYPE>.*` step-output templates
//     + operations + ai_personas + types (empty `fields` / no tenant rows without a
//     form_id). An OPTIONAL `form_id` layers that form's field vars in, matching forForm.
// The FE editor now sources its catalog from the form-independent endpoint so a schedule
// (or any form-less) workflow gets a REAL catalog instead of falling back to a mirror.

/**
 * One structured-descriptor option: the human `label` for a stored option `key`. The
 * `key` is the UNCHANGED wire value (⊆ the flat `enumOptions`); the `label` is the real
 * human label the JSON schema drops (it lives in the form element config). Mirrors the
 * backend descriptor's `{key,label}`.
 */
export interface CatalogDescriptorOption {
  key: string;
  label: string;
}

/**
 * The ADDITIVE structured type descriptor a catalog variable now ALSO carries (phase-1a)
 * alongside the unchanged flat `type`. Mirrors `WorkflowVariableType::descriptor`:
 *   - `base`     the REAL scalar base — incl. `time` (whose flat `type` still degrades to
 *                `text`) and `enum` (a MULTI is `base:'enum'` + `array:true`).
 *   - `nullable` the path is only sometimes present.
 *   - `array`    true for a multi (an array of the enum base).
 *   - `options`  present ONLY for an enum base (enum/multi); carries the REAL `{key,label}`
 *                human labels. The FE shows the `label`, stores/emits the `key`.
 * Optional on `CatalogVariable` so older / label-less responses (and existing fixtures)
 * that omit it still parse — consumers fall back to `enumOptions` (values) for choices.
 */
export interface CatalogVariableDescriptor {
  base: 'text' | 'number' | 'boolean' | 'date' | 'enum' | 'time' | 'file';
  nullable: boolean;
  array: boolean;
  options?: CatalogDescriptorOption[];
}

/**
 * One reference-able variable (mirrors WorkflowVariableCatalogService::variable):
 * `{source, path, name, type, descriptor?, enumOptions?, nullable?}`. `descriptor` is the
 * ADDITIVE structured type (phase-1a) — the source of an enum's human option LABELS.
 */
export interface CatalogVariable {
  source: 'trigger' | 'steps';
  path: string;
  name: string;
  type: WorkflowVariableType;
  descriptor?: CatalogVariableDescriptor;
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

/**
 * The control type of one operation argument (mirrors the editor's
 * `VariableOperationArgumentType`). `sourceOption(s)` / `sourceMap` draw their choices
 * from the SOURCE field's options; the CHOICE-producing kinds (`choiceRules` /
 * `choiceFallback`, and a `sourceMap` with `mapType:'enum'`) draw from the DESTINATION
 * field's option set (a value-or-variable "choice"/enum field).
 */
export type CatalogOperationArgType =
  | 'text'
  | 'number'
  | 'boolean'
  | 'date'
  | 'select'
  | 'sourceOption'
  | 'sourceOptions'
  | 'sourceMap'
  | 'choiceRules'
  | 'choiceFallback';

/** One operation-argument descriptor (backend `operations[].args[]`). */
export interface CatalogOperationArg {
  id: string;
  type: CatalogOperationArgType;
  mapType?: 'text' | 'number' | 'date' | 'enum';
}

/**
 * One operation DESCRIPTOR from the catalog (B2 added `operations[]`): the id + its
 * single `input` type, `output` type and `args`. These 77 ids are the authoritative
 * SET the backend condition engine implements; the FE attaches human labels by id
 * from `standardOperationsCatalog()` (a descriptor without a known label falls back
 * to its id). NO labels ship on the wire.
 */
export interface CatalogOperation {
  id: string;
  input: WorkflowVariableType;
  output: WorkflowVariableType;
  args: CatalogOperationArg[];
}

/**
 * One AI-text persona descriptor (SB2). Label-LESS on the wire (`{id}`), mirroring
 * the `operations` "descriptors without labels" pattern — the FE localizes the label
 * from `workflows.aiPersona.<id>`. The closed set is neutral|friendly|formal|concise.
 */
export interface CatalogAiPersona {
  id: string;
}

/**
 * One variable-TYPE descriptor (form-independent catalog `types[]`): the type `id`
 * (a WorkflowVariableType), the editor `primitive` it degrades to inside a directive
 * (mirrors `WorkflowVariableType::editorPrimitive`), and its condition `operators`.
 * Label-less (the FE localizes), mirroring the operations / ai_personas pattern. Lets a
 * form-less catalog describe the full type vocabulary + the degrade rule without a static
 * FE mirror. Phase 0 captures it on the contract; wiring the editor's type resolution to
 * it is a LATER (type-descriptor) phase — the type system itself is unchanged.
 */
export interface CatalogType {
  id: WorkflowVariableType;
  primitive: WorkflowVariablePrimitive;
  operators: string[];
}

/**
 * The catalog payload `{variables, fields, operations?, ai_personas?, types?}`.
 * `operations` (B3) + `ai_personas` (SB2) + `types` (form-independent catalog) are
 * additive — older responses (the form-bound route) may omit them, so the FE falls back
 * to the full standard catalog / the closed persona set respectively.
 */
export interface WorkflowCatalog {
  variables: CatalogVariable[];
  fields: CatalogField[];
  operations?: CatalogOperation[];
  ai_personas?: CatalogAiPersona[];
  types?: CatalogType[];
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
  /**
   * Either the LEGACY flat list (older workflows) or the B3 condition TREE
   * (`WireConditionGroup`). Read consumers must branch on the shape; the editor
   * normalizes both via `wireToDraft`.
   */
  conditions: WorkflowCondition[] | WireConditionGroup;
  steps: WorkflowStep[];
  /** Scheduling — populated by the scheduler; may be null. */
  last_scheduled_run_at: string | null;
  next_due_at: string | null;
  /** `whenLoaded('creator')` — polymorphic (Phase 3): user | workflow_run | bot | null. */
  creator?: Creator | null;
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
  /**
   * Bucket toggle (the Active/Deleted tabs): truthy → ONLY soft-deleted workflows;
   * absent/falsy → the active list (default). Serialized as `trashed=1` (omit-or-1),
   * mirroring the forms list's trashed flag. Composes with `search`/`status`.
   */
  trashed?: boolean;
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
  /**
   * The condition TREE (`WireConditionGroup`, B3). OMITTED entirely when the tree is
   * empty (emit-or-omit). The flat `WorkflowCondition[]` remains accepted by the
   * backend for compatibility, but the editor always emits the tree.
   */
  conditions?: WireConditionGroup | WorkflowCondition[];
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
  /**
   * Detail only, SCHEDULE runs only — the workflow's full v2 schedule descriptor
   * (incl. `tz`), upgraded on the way out. Lets the run drawer name a schedule run's
   * matched occurrence semantically (`describeOccurrence`) without a workflow loaded.
   */
  schedule_descriptor?: WorkflowScheduleConfig | null;
  /**
   * Detail only, form_submitted runs only — the trigger form RESOLVED LIVE
   * server-side (name + description + icon come from the current form, NOT the
   * payload snapshot). Carried IN the run-show response so the drawer renders a lean
   * form item with NO extra fetch. ABSENT when the trigger form was deleted/foreign —
   * then fall back to the name-only `trigger_payload.form`.
   */
  form?: { id: string; name: string; description: string | null; icon: string | null } | null;
  /** Detail only — the ordered per-step audit timeline. */
  steps?: WorkflowRunStep[];
  /**
   * GLOBAL feed only (`whenLoaded('workflow')`) — the parent workflow's identity so
   * the cross-workflow runs list can render a workflow column + resolve the run's
   * nested detail route. ABSENT on the per-workflow feed and on the run SHOW body.
   */
  workflow?: WorkflowRunWorkflow | null;
}

/**
 * The compact workflow identity attached to a GLOBAL runs-feed row
 * (`whenLoaded('workflow')`). Mirrors the backend's index-only nested object.
 */
export interface WorkflowRunWorkflow {
  id: string;
  name: string;
  icon: string | null;
  status: WorkflowStatus;
  trigger_type: WorkflowTriggerType;
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

/**
 * The runs list-screen filter state. Mirrors the `/runs` query params (all optional):
 * `state[]` / `origin[]` / `trigger_type[]` / `workflow_id[]` are REPEATED array params
 * (a single legacy scalar is tolerated on restore for each); `workflow_id[]` is honored
 * ONLY by the global feed (backend accepts the array + tolerates a legacy single scalar);
 * `date_from` / `date_to` (ISO `yyyy-mm-dd`) + `date_preset`
 * (today|this_week|last_week|this_month) share the DateRangeFilter. Both feeds
 * (per-workflow `/workflows/{id}/runs` + global `/workflows/runs`) accept the same set.
 */
export interface WorkflowRunFilters {
  state?: string[];
  origin?: string[];
  trigger_type?: string[];
  workflow_id?: string[];
  date_from?: string;
  date_to?: string;
  date_preset?: string;
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
