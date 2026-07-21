# Backend API: Workflows module

Module: `app/modules/Workflows/`
Auth: all endpoints require `auth:sanctum` + `X-Workspace-Id` header (resolved by `ResolveWorkspace` middleware).
Tenant scope: `TenantAware` trait — all queries are automatically scoped to the active workspace.

> **Etap 5.1 re-scope.** This document describes the CURRENT contract after the 5.1 re-scope
> (B1–B7, all complete and reviewed): **2 trigger types** (`form_submitted`, `schedule`), **2
> step types** (`create_task`, `create_form_report`), a **typed condition/variable system**, and
> AI schedule-assist. It supersedes the Etap-5 contract (5 triggers: `task_created`,
> `task_status_changed`, `form_submitted`, `approval_finished`, `schedule`; 4 steps:
> `create_task`, `assign_bot`, `attach_form`, `start_approval`; flat untyped conditions) —
> see ADR-0009 for the re-scope rationale and ADR-0008 for the run-engine decisions that still
> hold. This document describes IMPLEMENTED behavior only; anything not yet built is marked
> **PLANNED**.
>
> **Runtime operations, if-blocks, and AI text in step fields (SB1/SB2, this revision) —
> ADDITIVE, not breaking.** A step's text fields (`create_task.description`,
> `create_form_report.guidelines`) and value-or-variable fields
> (`create_task.priority`/`.deadline`, `create_form_report.submissions_from`/`.submissions_to`)
> now execute variable-operation PIPELINES, conditional `if-block`s, and `@[ai-text]`
> AI-generated text at RUN time — see "Runtime operations, if-blocks, and AI text" below. This
> REVERSES ADR-0009 §2's "an operations pipeline is explicitly deferred" — see
> **ADR-0013-workflows-step-operations-conditionals-ai-text.md** for the reasoning. Nothing about
> the wire shape existing consumers relied on changed (an old workflow with identity-only
> directives / pipeline-less value-or-variable fields keeps behaving exactly as before); every new
> capability is opt-in content authored through the frontend editor.
>
> **Choice-coercion for value pipelines (this revision) — the operation catalog grows 66→68;
> tightens (not breaks) the value-or-variable WRITE validator for a "choice" field.** Two new
> operations, `enum_to_choice` and `match_to_choice`, let a value-or-variable pipeline map an
> arbitrary source variable into a DESTINATION field's own fixed option set — e.g. mapping a
> form's free-text `category` answer onto `create_task.priority`'s
> `urgent|high|medium|low`. `WorkflowOperation::producesChoice()` marks these two ops as CHOICE
> terminals; a pipeline targeting a choice field (currently only `priority`) must now be
> non-empty and END in one — a bare identity ref or a pipeline ending in a non-choice op (e.g.
> `enum_to_text`) is **REJECTED** at write time (previously accepted under the "enum or text"
> terminal rule). This is a validator-only tightening: a workflow SAVED before this change keeps
> running unchanged (the runtime coercion path was never affected), but re-saving a step whose
> `priority` pipeline does not end in a choice op now 422s. See
> **ADR-0014-workflows-choice-coercion.md** for the full design record and
> "Runtime operations, if-blocks, and AI text" → "Choice fields" below for the wire contract.
>
> **Schedule model v2 (this revision) — BREAKING, supersedes the 12/16-family model.** The
> family-based vocabulary (`WorkflowScheduleFamily`, previously 16 members) described in earlier
> revisions of this document is RETIRED. `trigger_config.schedule` is now a COMPOSITIONAL
> descriptor of three independent axes — `{ time, day?, month?, exclusions?, tz? }` — combined
> with AND, instead of one of 16 named presets. This is a BREAKING wire change: the write path and
> the schedule-preview endpoint no longer accept `{ family, params }`; every response
> (`WorkflowResource`, the assist envelope) always returns the v2 shape; and
> `GET /workflows/meta/schedule-families` (the family-discovery endpoint) is REMOVED — there is no
> vocabulary left to discover, the frontend owns the axis/mode list and mirrors the numeric bounds
> from `ScheduleLimits` directly. A schedule **stored** before this change keeps firing and keeps
> rendering unchanged — `LegacyScheduleUpgrader` transparently upgrades a legacy `{ family, params
> }` block to v2 at every read/compile boundary, so no data migration was run. See ADR-0012 for
> the design decisions (ADR-0012 supersedes ADR-0010 §7's frontend two-mode decision) and the
> Schedule section below for the full v2 contract — the family table that used to live there is
> gone.
>
> **Polymorphic `creator` (this revision) — CORRECTS previously-documented behavior, not a wire
> change.** `WorkflowRun`'s creator, and every record a step creates (`create_task`,
> `create_form_report`), now attribute correctly and are never left null — see the "Creator
> attribution" note in the `create_form_report` section, the corrected "Origin" note under
> Trigger dispatch pipeline, and the Capability flags table below. Full cross-module reference:
> `docs/backend/creator-attribution.md` and **ADR-0015-polymorphic-creator-ownership.md**.
>
> **Runs monitoring — a GLOBAL cross-workflow feed + a schedule run's `schedule_descriptor` (this
> revision) — ADDITIVE, no breaking change.** A new `GET /workflows/runs` endpoint lists every run
> in the active workspace regardless of which workflow started it, sharing its filter contract
> (`state[]`/`origin[]`/`trigger_type[]`, `date_from`/`date_to`/`date_preset`) with the existing
> `GET /workflows/{workflow}/runs` via one `IndexWorkflowRunsRequest` — plus a global-only
> `workflow_id` scope. Every list filter is now an ARRAY (a legacy single-scalar deep-link such as
> `?state=completed` is still tolerated, coerced to a one-element array) and an unrecognised enum
> member is silently dropped rather than 422'd (unchanged from the original runs index, just
> formalized here). `WorkflowRunResource` additionally carries a `workflow` block on INDEX rows
> (global feed only, `whenLoaded`) and a `schedule_descriptor` on SHOW for a `schedule`-origin run
> (the parent workflow's v2 schedule block, upgraded through `LegacyScheduleUpgrader`) — see the
> Runs endpoints below and **ADR-0016-workflows-global-runs-and-schedule-reason.md** for the design
> record. The human-readable "why did this fire" sentence itself is computed on the FRONTEND
> (`describeOccurrence` in `resources/js/next/pages/workflows/workflowSchedule.ts`), the same
> FE-owns-the-grammar split ADR-0012 established for the schedule sentence — the backend exposes
> data, never prose.
>
> **Retry a FAILED run (this revision) — a new mutating endpoint, ADDITIVE, no breaking change.**
> `POST /workflows/{workflow}/runs/{run}/retry` re-executes a terminal `failed` run. The engine has
> **no mid-run resume** (`WorkflowStepRunner` always executes from the first step), so a "retry"
> STARTS A NEW run for the same workflow, reusing `{run}`'s stored `trigger_payload` (the
> `form_submitted` form/submission snapshot, or the schedule's `scheduled_at`) — the original failed
> row is never mutated. Authorization mirrors run-now exactly (`WorkflowPolicy::run`, any workspace
> member); the new run is `manual`-origin, attributed to the acting user, `pending`, depth 0,
> `origin_run_id` null. **Returns 201 Created**, not the 202 run-now returns — see the endpoint
> section below for the full contrast. A non-`failed` run 422s under the `run` key; the same
> run-budget cap run-now enforces 422s under `workflow`.
>
> **Form-independent variable catalog + a `types` catalog key (this revision, Phase 0 of the
> variable-typesystem rework) — ADDITIVE, no breaking change.** `WorkflowVariableCatalogService`
> is now composed from sources via `forContext(?WorkflowTriggerType, ?Form)` — `forForm()` is a
> thin `FORM_SUBMITTED` specialization of it — so a new `GET /workflows/catalog` endpoint serves a
> real catalog for a form-less workflow (e.g. a `schedule` trigger) instead of the frontend
> maintaining a static mirror of the trigger-system variables / step-output templates. Both catalog
> responses also gained a `types` key — `[{ id, primitive, operators }]`, one entry per
> `WorkflowVariableType` — describing the full type vocabulary label-lessly (the FE localizes),
> the same pattern `operations`/`ai_personas` already use. See
> **ADR-0021-workflows-variable-catalog-composable-roots.md** for the "adding a root is a 3-point
> change" recipe this groundwork sets up for the rest of the rework (global variables, a loop item,
> a template slot, campaign inputs), and "GET /api/workflows/catalog" / the `types` key below for
> the wire contract.
>
> **Structured `descriptor`, a `TIME` type, per-reference `default`s, and 5 append-only ops (this
> revision, Phase 1 of the variable-typesystem rework) — ADDITIVE, no breaking change.** Every
> catalog variable now ALSO carries a structured `descriptor` (`{ base, nullable, array, options?
> }`) alongside the unchanged flat `type` — enum/multi options carry real `{key,label}` pairs (the
> label lives in the form element's config, not the JSON schema) instead of a bare value list. A
> new `WorkflowVariableType::TIME` case joins the vocabulary (the form builder's TIME element,
> previously silently folded into `text`) — it is descriptor-only THIS phase: the flat wire `type`
> still degrades it to `text` and it carries no condition operators (`operatorCases()` is `[]`), a
> deliberate loud tripwire rather than a silent `UnhandledMatchError` once real TIME semantics
> land. Both wire serializations of a reference (the markdown directive's `data.default`, the
> `{kind:'variable'}` union's `default`) gained an OPTIONAL literal `default`, substituted for a
> null/`''` lookup BEFORE the pipeline runs, through the SAME NUL-mask injection-guard path a
> resolved value already uses. The operation catalog grows **72 → 77**, append-only: `coalesce`,
> `is_present`, `is_null`, `assert_present` (the type-agnostic presence family — the ONE opt-in
> HARD failure in the pipeline engine), and `date_format` (a safe-token date renderer, never a raw
> PHP format string). See **ADR-0022-workflows-variable-typesystem-phase1.md** for the full design
> record and "Structured `descriptor`", "Per-reference defaults", and "Presence, null-handling,
> and date-format ops" below for the wire contracts.

---

## Concepts

A **Workflow** is a stored automation DEFINITION: `trigger → conditions → an ordered list of
steps`. It does nothing by itself — a **WorkflowRun** is one EXECUTION of that definition,
created when the trigger fires (or a user runs it manually) and driven through its steps by a
queued job. Each step's outcome is recorded as a **WorkflowRunStep** audit row.

```
Workflow (definition)
  └─ trigger_type + trigger_config   (what starts it: form_submitted | schedule)
  └─ conditions[]                    (form_submitted only: typed {field,field_type,operator,value}, AND-combined)
  └─ steps[]                         (ordered actions: create_task, create_form_report)

WorkflowRun (one execution)
  └─ state machine: pending → running → completed | failed   (waiting, cancelled reserved, unused)
  └─ origin: event | schedule | manual
  └─ context: { trigger: {...}, steps: { <key>: {...output} } }
  └─ WorkflowRunStep[]  (one audit row per executed step, in order)
```

A workflow is always created **INACTIVE** (`status` is never accepted on create/update — the
body field is ignored/rejected; the ONLY way to flip it is `PATCH /workflows/{id}/status`). An
inactive workflow's trigger never fires from a real domain event, but it CAN still be run
manually (test-before-activate — see the manual-run endpoint below).

### WorkflowStatus

| Value      | Meaning                                                        |
|------------|-----------------------------------------------------------------|
| `active`   | The trigger may fire (event/schedule). Manual run also works.  |
| `inactive` | The trigger never fires from a real event. Manual run still works (test-run). |

### WorkflowTriggerType

| Value             | Fires when…                                                                 |
|--------------------|-------------------------------------------------------------------------------|
| `form_submitted`    | A form submission transitions into its **approved** state (Taskio has no separate "submit" event — a submission counts as submitted once approved). |
| `schedule`           | A cadence fires (a v2 compositional `{ time, day?, month? }` descriptor — see the Schedule section). Never event-dispatched. |

**The other three Etap-5 trigger types (`task_created`, `task_status_changed`,
`approval_finished`) were REMOVED in the 5.1 re-scope** — see ADR-0009 §1. Any workflow
definition or code path referencing them is stale.

### WorkflowRunState

```
pending ──claim──▶ running ──release(completed|failed)──▶ terminal
```

| Value       | Meaning                                                                 |
|-------------|--------------------------------------------------------------------------|
| `pending`   | Run row created; job not yet claimed it.                                |
| `running`   | Claimed; steps executing.                                                |
| `waiting`   | **Reserved, not produced by the MVP engine.** Anticipates a future step that suspends a run to await an external event. See ADR-0008 #11. |
| `completed` | Every step succeeded.                                                    |
| `failed`    | A step failed (or the job/worker failed) — the run stopped at that step. |
| `cancelled` | **Reserved, not produced by the MVP engine.** Anticipates a future manual-cancel action. |

`WorkflowRunState::isTerminal()` is `true` for `completed`, `failed`, `cancelled`.

### WorkflowRunStepStatus

`pending` (row created before execution) → `succeeded` (with an `output` payload) or `failed`
(with an `error`). The runner always writes the row already resolved (`succeeded`/`failed`) —
`pending` exists in the vocabulary but is not observed on a finished step in practice.

---

## Endpoints

### GET /api/workflows

List workflow definitions in the workspace. Cursor-paginated, 8 per page, newest first.

**Query**

| Param    | Required | Notes                                              |
|----------|----------|------------------------------------------------------|
| `search` | no       | case-insensitive match on `name` / `description`   |
| `status` | no       | `active \| inactive` — exact match, omitted if blank |
| `cursor` | no       | cursor from `meta.next_cursor` for the next page   |

**Response** `200 OK`

```json
{ "data": [ WorkflowListResource ], "meta": { "next_cursor": "string | null" } }
```

---

### POST /api/workflows

Create a workflow definition. Always created **inactive** — a `status` field in the body is
silently accepted by the FormRequest (not validated) but never persisted; the DTO does not
carry `status` at all, and `WorkflowService::create()` hardcodes `WorkflowStatus::INACTIVE`.

**Base fields (every trigger type)**

| Field                     | Required             | Constraints                                                        |
|----------------------------|-----------------------|------------------------------------------------------------------|
| `name`                      | yes                    | string, max 255                                                    |
| `description`               | no                      | string, max 2500                                                   |
| `icon`                       | no                      | string, max 100                                                    |
| `trigger_type`               | yes                     | `form_submitted` \| `schedule`                                    |
| `trigger_config`             | per-type                | see the per-trigger sections below                                |
| `conditions`                  | no                       | array, max 50; **only valid for `form_submitted`**; see the Conditions section |
| `conditions.*.field`          | required-if-present      | string, max 255, MUST start with `fields.` (e.g. `fields.abc123`) |
| `conditions.*.field_type`     | required-if-present      | one of `WorkflowVariableType` (`text\|number\|boolean\|date\|enum\|multi`) |
| `conditions.*.operator`       | required-if-present      | one of `WorkflowConditionOperator`, MUST belong to `field_type`'s allow-list |
| `conditions.*.value`          | required-if-present (unless value-less) | shape depends on the operator — see the Conditions section |
| `steps`                        | yes                      | array, min 1, max 50                                               |
| `steps.*.type`                  | yes                      | `create_task` \| `create_form_report`                             |
| `steps.*.key`                    | yes                      | string, max 100, **distinct across the whole array**, `[A-Za-z0-9_]+` only (letters/digits/underscore — see below) — used for `{{steps.<key>.*}}` |
| `steps.*.config`                 | no                       | object; shape depends on `steps.*.type` (see the Steps section)   |

`status` is NOT in this table — it is not an accepted create field. `PUT` (update) uses the
identical rule set (it subclasses `StoreWorkflowRequest`); only the authorization target
differs (an existing workflow, creator-only).

**Cross-type / cross-step rejection**: any `trigger_config.<key>` outside the submitted
`trigger_type`'s allow-list, or any `steps.*.config.<key>` outside the submitted step's own
allow-list, is rejected as a 422 (`withValidator()` walks the payload and reports the shallowest
foreign segment — e.g. a `schedule.*` block on a `form_submitted` trigger fails on
`trigger_config.schedule`, and a `guidelines` field on a `create_task` step fails on
`steps.<i>.config.guidelines`).

**Errors**

| Code | Field                              | Meaning                                                    |
|------|--------------------------------------|--------------------------------------------------------------|
| 422  | `name`                               | Required, max 255.                                          |
| 422  | `trigger_type`                       | Required; must be `form_submitted` or `schedule`.            |
| 422  | `steps`                              | Missing / empty / more than 50.                              |
| 422  | `steps.*.key`                        | Duplicate key across the step list, OR a character outside `[A-Za-z0-9_]` (reviewer fix — a key is substituted into `steps.<key>.<output>` dotted paths and read via `Arr::get`, so a dot/space would silently break every reference to the step; the frontend mirrors this with a `sanitizeStepKey` guard that strips invalid characters as they are typed). |
| 422  | `steps.*.type`                       | Unknown step type.                                           |
| 422  | `steps.<i>.config.<key>`             | A key not allowed for that step's own type.                  |
| 422  | `trigger_config.<key>`               | A key that does not belong to this trigger type's config (cross-type rejection). |
| 422  | `conditions`                         | Present on a non-`form_submitted` trigger.                    |
| 422  | `trigger_config.form_id`             | Conditions present but no form selected (conditions REQUIRE a form). |
| 422  | (per-trigger / per-step keys — see below) | Missing/invalid field for the submitted type.            |
| 403  | —                                     | User not authenticated or not a workspace member.            |

**Response** `200 OK` — `WorkflowResource` with `creator` loaded.

---

### GET /api/workflows/{id}

Fetch one workflow definition (soft-deleted workflows are NOT found; restore first).

**Response** `200 OK` — `WorkflowResource` with `creator` loaded.

**Errors**: `403` not a workspace member, `404` not found.

---

### PUT /api/workflows/{id}

Update a workflow. Same validation rules as `POST`. Authorization: creator only
(`WorkflowPolicy::update`). Never touches `status` — the status endpoint owns that transition.
If the workflow is an ACTIVE schedule workflow, changing its `trigger_config.schedule` cadence
**re-arms** `next_due_at` from the new cadence (see the Schedule section).

**Response** `200 OK` — `WorkflowResource`. **Errors**: `403` not creator, `404` not found,
`422` validation (same table as POST).

---

### DELETE /api/workflows/{id}

Soft-delete a workflow. Authorization: creator only.

**Response** `200 OK` — `{ "message": "Workflow deleted successfully" }`.
**Errors**: `403` not creator, `404` not found.

---

### POST /api/workflows/{id}/restore

Restore a soft-deleted workflow. `{id}` resolves through `Workflow::withTrashed()`.
Authorization: creator only (`WorkflowPolicy::restore`).

**Response** `200 OK` — `WorkflowResource`. **Errors**: `403` not creator, `404` not found
(including trashed).

---

### PATCH /api/workflows/{workflow}/status

Toggle `active | inactive`. **The only path that mutates status.** Authorization: creator only
(`WorkflowPolicy::changeStatus`).

**Body**

```json
{ "status": "active" }
```

**Effect on scheduling:** activating an ACTIVE schedule-triggered workflow **arms**
`next_due_at` from its cadence; deactivating **nulls** `next_due_at` so the schedule sweep's
`WHERE` clause never sees it. A `form_submitted` workflow is unaffected either way.

**Response** `200 OK` — `WorkflowResource`. **Errors**: `403` not creator, `404` not found,
`422` unknown status value.

---

### POST /api/workflows/{workflow}/run

Manually run a workflow **on demand** — works on ANY workflow, **including an INACTIVE one**
(test-before-activate). Authorization: any workspace member (`WorkflowPolicy::run` mirrors
`view` — running is not creator-gated). Returns **202 Accepted**: the run executes asynchronously
under a real queue, or in-process immediately under `QUEUE_CONNECTION=sync`.

**Body**

```json
{ "target_id": "b1b2c3d4-..." }
```

Target requirement depends on the workflow's `trigger_type`:

| Trigger type       | `target_id` resolves to…                                        | Required? |
|----------------------|----------------------------------------------------------------------|-----------|
| `form_submitted`      | a `FormSubmission` (tenant-scoped)                                   | yes       |
| `schedule`              | none — payload is the minimal `{ scheduled_at }` a real sweep would build | no (omit `target_id`) |

**Response** `202 Accepted` — `WorkflowRunResource` (the stable core shape: id, state, origin,
trigger_type, timing, error — no `steps`/`trigger_payload`, since neither is loaded for this
shape).

```json
{
  "data": {
    "id": "c1c2c3c4-...",
    "state": "completed",
    "state_label": "Zakończony",
    "state_tone": "success",
    "origin": "manual",
    "trigger_type": "form_submitted",
    "depth": 0,
    "origin_run_id": null,
    "error": null,
    "started_at": "2026-07-09T10:00:00.000000Z",
    "finished_at": "2026-07-09T10:00:01.000000Z",
    "created_at": "2026-07-09T10:00:00.000000Z",
    "duration_seconds": 1
  }
}
```

**Manual runs COUNT toward the run-budget caps and are REFUSED (422), not silently skipped**,
at the ceiling — the opposite of an event trigger, which skips silently. See Cost limits below.

**422 error-key contract — EXACTLY two keys** (the frontend maps these by **key**, never by
message; the Polish prose may change). The 5.1 re-scope DROPPED the `approval_process` key that
existed under the Etap-5 `approval_finished` trigger — that trigger type no longer exists, so
there is nothing left to key a "no concluded process" error under:

| Key          | When                                                                  |
|---------------|--------------------------------------------------------------------------|
| `target_id`   | Missing (for `form_submitted`, which needs one) or well-formed-but-unresolvable (not found in this tenant). |
| `workflow`     | The run-budget cap (per-workflow monthly or workspace-wide hard cap) is already reached. |

```json
// target_id unresolvable
{ "message": "...", "errors": { "target_id": ["Wskazane wysłanie formularza nie istnieje w tej przestrzeni roboczej."] } }

// cap reached
{ "message": "...", "errors": { "workflow": ["Ten workflow osiągnął limit uruchomień na ten miesiąc. Uruchomienie zostało zablokowane."] } }
```

A manual run's payload is built by the **same** `WorkflowTriggerPayloadFactory` a real event
uses, so `{{trigger.*}}` / directive references resolve identically regardless of how the run
started — a test-run proves what a real trigger would do.

**vs. retry** (`POST /workflows/{workflow}/runs/{run}/retry`, below): run-now builds a FRESH
payload from a caller-supplied `target_id` and returns **202 Accepted**; retry reuses an existing
FAILED run's already-stored `trigger_payload` (no `target_id`, no body at all) and returns **201
Created**. Both share the same authorization and the same run-budget cap.

---

### GET /api/workflows/{workflow}/runs

Read-only monitoring: a workflow's runs, cursor-paginated (15/page), newest first
(`created_at DESC`, `id DESC` tiebreak — UUIDv7 ids are monotonic, so this keeps pagination
stable across a boundary where several runs share a timestamp). Authorization: `view` on the
parent workflow (any workspace member — runs are workspace-visible read-only monitoring).
Validation + filtering are shared with the global feed below via ONE `IndexWorkflowRunsRequest` +
`WorkflowRunService` — see that endpoint for the full filter reference; this endpoint additionally
hard-scopes to `{workflow}` (a `workflow_id` query param, if sent, is simply ignored here — the
URL already pins the workflow).

**Query** (all optional, all AND-combined)

| Param             | Notes                                                                 |
|-------------------|------------------------------------------------------------------------|
| `state[]`         | subset of `WorkflowRunState`. Also accepts a single legacy scalar (`?state=completed`), coerced to a one-element array. An unrecognised member is **silently dropped** (no filter, no error) — mirrors the Bot inbox `state` filter. |
| `origin[]`        | subset of `WorkflowRunOrigin`. Same array/legacy-scalar/tolerant-drop rules as `state[]`. |
| `trigger_type[]`  | subset of `WorkflowTriggerType`. Same array/legacy-scalar/tolerant-drop rules. |
| `date_from` / `date_to` | a date range on `created_at`, via the shared `scopeFilterByDate` every next list screen already emits. |
| `date_preset`     | `today \| this_week \| last_week \| this_month` — a shortcut inherited from the same scope. |
| `cursor`          | cursor from `meta.next_cursor`.                                        |

**Response** `200 OK` — list shape carries `steps_count` (via `withCount`) but **excludes**
`trigger_payload` and `steps` (potentially large; detail-only). The per-workflow index does NOT
eager-load the `workflow` relation (the caller already has the workflow context from the URL), so
rows here carry no `workflow` block — that is a global-feed-only addition, see below:

```json
{
  "data": [
    {
      "id": "c1c2c3c4-...",
      "state": "completed",
      "state_label": "Zakończony",
      "state_tone": "success",
      "origin": "event",
      "trigger_type": "form_submitted",
      "depth": 0,
      "origin_run_id": null,
      "error": null,
      "started_at": "2026-07-09T10:00:00.000000Z",
      "finished_at": "2026-07-09T10:00:01.000000Z",
      "created_at": "2026-07-09T10:00:00.000000Z",
      "duration_seconds": 1,
      "steps_count": 2
    }
  ],
  "meta": { "next_cursor": "string | null" }
}
```

**Errors**: `403` not a member, `404` unknown workflow.

---

### GET /api/workflows/runs

The GLOBAL runs feed: every run in the active workspace, regardless of which workflow started it
— cursor-paginated (15/page), newest first, same ordering/tiebreak as the per-workflow index.
Declared BEFORE the `workflows/{workflow}` apiResource route so the static `runs` segment always
wins the match. Authorization: `viewAny` on `Workflow` (`$user !== null` — any authenticated
workspace member; cross-workspace isolation is enforced upstream by `ResolveWorkspace` +
`WorkspaceScope`, not by this policy check). Backed by `WorkflowRunController::global()` →
`WorkflowRunService::runsGlobal()`.

**Query** — the same `state[]` / `origin[]` / `trigger_type[]` / `date_from` / `date_to` /
`date_preset` / `cursor` params as the per-workflow index above, **plus**:

| Param         | Notes                                                                     |
|---------------|----------------------------------------------------------------------------|
| `workflow_id` | nullable uuid. Honored **only** by this global feed — scopes the feed to one workflow (an alternative entry point to the per-workflow index, e.g. from a saved view). Not validated against tenant scope explicitly; a foreign-workspace id simply matches nothing (`WorkspaceScope` already isolates the base query). |

**Response** `200 OK` — the SAME row shape as the per-workflow index, with one addition: each row
carries a `workflow` block (`id`, `name`, `icon`, `status`, `trigger_type`) — the parent workflow
is eager-loaded (`with('workflow')`) specifically for this feed, since the caller has no other way
to know which workflow a row belongs to:

```json
{
  "data": [
    {
      "id": "c1c2c3c4-...",
      "state": "completed",
      "state_label": "Zakończony",
      "state_tone": "success",
      "origin": "event",
      "trigger_type": "form_submitted",
      "depth": 0,
      "origin_run_id": null,
      "error": null,
      "started_at": "2026-07-09T10:00:00.000000Z",
      "finished_at": "2026-07-09T10:00:01.000000Z",
      "created_at": "2026-07-09T10:00:00.000000Z",
      "duration_seconds": 1,
      "steps_count": 2,
      "workflow": {
        "id": "b1b2c3d4-...",
        "name": "Escalate urgent tickets",
        "icon": "workflow",
        "status": "active",
        "trigger_type": "form_submitted"
      }
    }
  ],
  "meta": { "next_cursor": "string | null" }
}
```

**Errors**: `403` not an authenticated workspace member.

---

### GET /api/workflows/{workflow}/runs/{run}

One run with its full detail: `trigger_payload` and the ordered `steps` timeline (eager-loaded,
ordered by `position`). **Nested-ownership guard**: `{run}` is bound independently of
`{workflow}` (both resolve through `WorkspaceScope`), so the controller explicitly checks
`$run->workflow_id === $workflow->id` and throws a 404 if a run from a DIFFERENT workflow (but
the same workspace) is requested under this workflow's URL — otherwise it would leak through.

**Response** `200 OK`

```json
{
  "data": {
    "id": "c1c2c3c4-...",
    "state": "failed",
    "state_label": "Błąd",
    "state_tone": "danger",
    "origin": "event",
    "trigger_type": "form_submitted",
    "depth": 0,
    "origin_run_id": null,
    "error": "create_form_report step could not find form `...` in this workspace.",
    "started_at": "2026-07-09T10:00:00.000000Z",
    "finished_at": "2026-07-09T10:00:01.000000Z",
    "created_at": "2026-07-09T10:00:00.000000Z",
    "duration_seconds": 1,
    "trigger_payload": { "form": { "id": "...", "name": "...", "is_anonymous": false }, "submission": { "id": "..." }, "source": "manual", "task": null, "submitted_at": "2026-07-09T09:59:00.000000Z", "fields": { "status": "urgent" } },
    "steps": [
      {
        "id": "d1d2d3d4-...",
        "position": 0,
        "type": "create_task",
        "key": "make_task",
        "status": "succeeded",
        "status_label": "Zakończony",
        "status_tone": "success",
        "payload": { "task_id": "...", "title": "..." },
        "error": null,
        "created_at": "2026-07-09T10:00:00.500000Z"
      },
      {
        "id": "e1e2e3e4-...",
        "position": 1,
        "type": "create_form_report",
        "key": "make_report",
        "status": "failed",
        "status_label": "Błąd",
        "status_tone": "danger",
        "payload": null,
        "error": "create_form_report step could not find form `...` in this workspace.",
        "created_at": "2026-07-09T10:00:01.000000Z"
      }
    ]
  }
}
```

**`schedule_descriptor` — SCHEDULE runs only.** When the run's `trigger_type` is `schedule`, the
response additionally carries `schedule_descriptor`: the parent workflow's `trigger_config.schedule`
block, upgraded to the v2 shape (`{ time, day?, month?, exclusions?, tz? }`, same shim
`WorkflowResource` uses — see the Schedule section) via `LegacyScheduleUpgrader`. Absent
(`null`/omitted key) for a `form_submitted` run, or for a schedule run whose workflow carries no
schedule block. This is DATA only — the backend does not compute a "why did this run fire" sentence;
the frontend derives that itself (`describeOccurrence`) from `schedule_descriptor` plus the run's own
`trigger_payload.scheduled_at`:

```json
{
  "data": {
    "id": "f1f2f3f4-...",
    "state": "completed",
    "state_tone": "success",
    "origin": "schedule",
    "trigger_type": "schedule",
    "trigger_payload": { "scheduled_at": "2026-07-09T08:00:00.000000Z" },
    "schedule_descriptor": {
      "time": { "mode": "at", "at": ["08:00"] },
      "day": { "mode": "weekdays", "weekdays": [1, 3, 5] },
      "tz": "Europe/Warsaw"
    }
  }
}
```

**Errors**: `403` not a member, `404` unknown run, or a run belonging to a different workflow.

---

### POST /api/workflows/{workflow}/runs/{run}/retry

Retry a **FAILED** run: starts a **NEW** run for `{workflow}`, reusing `{run}`'s stored
`trigger_payload`. The engine has **no mid-run resume** — `WorkflowStepRunner` always executes
from the first step — so this is not a resume, it is a fresh run over the same trigger context
(the `form_submitted` form/submission snapshot, or the schedule's `scheduled_at`). The original
failed run is never mutated — it stays `failed` and a second retry of it is still allowed. Route
name `workflows.runs.retry`. Authorization: same as run-now, any workspace member
(`WorkflowPolicy::run` — not creator-gated).

**Nested-ownership + cross-tenant guards** (both a 404, same as `GET .../runs/{run}` above, and
enforced in `RetryWorkflowRunRequest::authorize()` before the terminal-state check ever runs):
`{run}` must belong to `{workflow}` (a run of a DIFFERENT workflow in the same workspace 404s),
and `{run}` must belong to the ACTIVE workspace (route-model binding runs BEFORE
`ResolveWorkspace`, so the request re-checks the run through the now-active `WorkspaceScope` — a
cross-workspace run 404s rather than leaking a 403 that would confirm it exists elsewhere).

**Body**: none (empty `POST`).

**Response** `201 Created` — `WorkflowRunResource`, the SAME stable-core shape run-now returns (no
`steps`/`trigger_payload` — neither is loaded for this shape), but describing the **NEW** run, not
the retried one:

```json
{
  "data": {
    "id": "g1g2g3g4-...",
    "state": "pending",
    "state_label": "Oczekuje",
    "state_tone": "neutral",
    "origin": "manual",
    "trigger_type": "form_submitted",
    "depth": 0,
    "origin_run_id": null,
    "error": null,
    "started_at": null,
    "finished_at": null,
    "created_at": "2026-07-15T10:05:00.000000Z",
    "duration_seconds": null
  }
}
```

The new run is always `origin: "manual"` and `creator` = the acting user (`creator_type: "user"`)
regardless of what started the original failed run (`event`/`schedule`/`manual`) — a retry is a
user-initiated action, like run-now. `depth` is always `0` and `origin_run_id` is always `null`:
retrying does not extend the original run's re-trigger chain, it starts a new top-level one.

**Errors**

| Code | Key         | When                                                                                          |
|------|-------------|------------------------------------------------------------------------------------------------|
| 401  | —           | Guest (unauthenticated).                                                                       |
| 403  | —           | Authenticated but not a member of the workflow's workspace.                                     |
| 404  | —           | `{run}` missing, belongs to a DIFFERENT workflow than `{workflow}` (foreign-workflow), or belongs to a DIFFERENT workspace (cross-tenant) — all three collapse to "does not exist under this parent", same as `GET .../runs/{run}`. |
| 422  | `run`       | `{run}` is not in the terminal `failed` state — `pending`/`running`/`waiting`/`completed`/`cancelled` are all rejected (a completed run is not "with an error"). |
| 422  | `workflow`  | The run-budget cap is already reached — the SAME per-workflow monthly soft cap + workspace-wide hard cap `POST .../run` enforces. |

```json
// not a failed run
{ "message": "...", "errors": { "run": ["Ponowić można tylko uruchomienie zakończone błędem."] } }

// cap reached
{ "message": "...", "errors": { "workflow": ["Ten workflow osiągnął limit uruchomień na ten miesiąc. Uruchomienie zostało zablokowane."] } }
```

**vs. `POST /workflows/{workflow}/run` (run-now, above)** — same authorization and the same
run-budget cap, but retry returns **201 Created** (a fresh run over the FAILED run's already-stored
`trigger_payload`, no request body) where run-now returns **202 Accepted** (a fresh run over a
caller-supplied `target_id`).

---

### ~~GET /api/workflows/meta/schedule-families~~ — REMOVED (v2)

The REV3 family-discovery endpoint (the 16-family vocabulary + per-family param descriptors) no
longer exists. The v2 compositional descriptor has no closed family list to discover — the
frontend owns the axis/mode/label inventory itself and mirrors every numeric bound from
`App\Modules\Workflows\Enums\ScheduleLimits` as a TypeScript constant
(`SCHEDULE_LIMITS` in `resources/js/next/pages/workflows/workflowSchedule.ts`), so there is nothing
left for a runtime discovery call to serve. A request to this path now 404s (unmatched route).

---

### POST /api/workflows/meta/schedule-preview

LIVE SCHEDULE PREVIEW: projects the next N fire instants of a proposed (not-yet-saved) v2 cadence,
so the FE schedule builder can show a running "next runs" list as the user edits, and can centre
that list on an arbitrary anchor instant ("jump to date"). Static path, declared before the
`{workflow}` resource. Any authenticated member may call it — it exposes no tenant data, only a
projection over the public cadence grammar. Backed by `WorkflowSchedulePreviewController` +
`WorkflowScheduleService::nextOccurrences()` / `occurrencesFrom()` (PURE functions — no tenancy, no
model writes). Throttled `60,1` (per user) on top of the FE's own debounce, since the projection
loop is CPU-bound.

**Body**

```json
{ "schedule": { "time": { "mode": "at", "at": ["08:00"] }, "day": { "mode": "weekdays", "weekdays": [1, 3] }, "tz": "Europe/Warsaw" }, "count": 6 }
```

| Field       | Required | Constraints                                                          |
|--------------|-----------|--------------------------------------------------------------------------|
| `schedule`     | **yes**    | the same v2 `{ time, day?, month?, exclusions?, tz? }` block the write path accepts, validated by the SAME `WorkflowScheduleRulesValidator` — with ONE difference (below). A legacy `{ family, params }` block is upgraded through the read-shim first (`LegacyScheduleUpgrader`), so a not-yet-migrated draft still previews. |
| `count`          | no          | integer 1–12, default 6 — how many occurrences to project.       |
| `anchor`           | no          | an ISO-8601 datetime string to centre the projection on (see below). Omitted ⇒ project forward from `now()`. |

**The ONE validation difference from the write path**: `SchedulePreviewRequest` calls
`WorkflowScheduleRulesValidator::secondPass(..., checkEmpty: false)` — the empty-schedule guard
(the check that rejects a cadence with no reachable occurrence) is OFF. Every STRUCTURAL rule
(unknown mode, out-of-bounds field, malformed window/exclusions, a field foreign to the chosen
mode) still returns a 422 exactly as it would on save. An over-constrained but otherwise
well-formed schedule (e.g. `day.weekdays:[1]` whose `exclusions.weekdays` also excludes Monday) is
NOT a 422 here — it comes back as DATA (`empty: true`), so the FE can render a pre-save warning
instead of surfacing a confusing validation error for a block that is otherwise well-formed.

**`anchor` — centres the projection instead of starting from `now()`.** An ISO-8601 datetime.
WITHOUT a UTC offset it is read as a WALL-CLOCK time in the schedule's own `tz`; WITH an offset it
is an absolute instant (the offset wins). When present, `occurrences[0]` is the occurrence AT OR
BEFORE the anchor (prev-or-at) when one exists within the horizon, followed by the ascending
occurrences strictly after it — so a caller can render a "previous" tile distinct from the
forward list. **Paging is a plain re-call**: to fetch the next page, call again with `anchor` set
to the last occurrence already shown; there is no separate cursor field. Omitting `anchor` behaves
exactly like the pre-anchor contract (`nextOccurrences()` from `now()`).

**Response** `200 OK` — FLAT, no `data` wrapper:

```json
{
  "occurrences": ["2026-07-13T06:00:00.000000Z", "2026-07-15T06:00:00.000000Z", "2026-07-20T06:00:00.000000Z"],
  "count": 6,
  "empty": false,
  "approximate": false
}
```

| Field           | Meaning                                                                                    |
|-------------------|-----------------------------------------------------------------------------------------------|
| `occurrences`       | ISO-8601 UTC instants, ASCENDING — at most `count`, may be FEWER (or `[]`) when the cadence has no reachable occurrence within `WorkflowScheduleService`'s horizon (1000 iterations / 10 years). Without `anchor` every entry is strictly after `now()`; WITH `anchor`, `occurrences[0]` may equal the anchor's prev-or-at occurrence exactly (the rest are strictly ascending after it). Same string format `WorkflowResource` uses for `next_due_at` (`toISOString()`), so the FE parses one shape everywhere. |
| `count`               | echoes the resolved (defaulted/clamped) count that was requested.                             |
| `empty`                 | `true` when `occurrences` is `[]` — an over-constrained `exclusions` set (or, in principle, any cadence the compiler cannot resolve). This is DATA, never a 422, on this endpoint. |
| `approximate`             | **ALWAYS `false` in v2.** Every v2 cadence is a wall-clock-anchored grid (there is no phase-from-activation interval left — see `CompiledSchedule`'s docblock), so a projection is always exact. The field is KEPT (not dropped) purely for response-shape stability; do not read it as a live signal. |

**This is the ONLY place occurrence dates are computed for the FE.** The frontend never
locally re-implements cron math to render a preview or a "next run" hint — every preview
surface (the builder's live preview strip, the AI-assist modal's proposal preview) calls this
endpoint.

---

### GET /api/forms/{form}/workflow-catalog

The TYPED variable catalog for a `form_submitted` workflow built on `{form}`: the reference-able
variables (trigger system vars + per-form field vars + step outputs) and the condition field
descriptors. This is the contract the workflow editor and AI-assist consume. Authorization:
`FormPolicy::view` (the endpoint lives in the Workflows module — it owns the "variable catalog"
concept — but binds a `Form` and defers to the Forms module's own view policy, mirroring how the
dispatch service already reads `Form`).

**Response** `200 OK`

```json
{
  "data": {
    "variables": [
      { "source": "trigger", "path": "trigger.submission.id", "name": "Submission ID", "type": "text" },
      { "source": "trigger", "path": "trigger.form.id", "name": "Form ID", "type": "text" },
      { "source": "trigger", "path": "trigger.form.name", "name": "Form name", "type": "text" },
      { "source": "trigger", "path": "trigger.source", "name": "Source", "type": "enum", "enumOptions": ["manual", "task"] },
      { "source": "trigger", "path": "trigger.submitted_at", "name": "Submitted at", "type": "date" },
      { "source": "trigger", "path": "trigger.task.id", "name": "Task ID", "type": "text", "nullable": true },
      { "source": "trigger", "path": "trigger.fields.status", "name": "Status", "type": "enum", "enumOptions": ["open", "urgent"] },
      { "source": "steps", "path": "steps.create_task.task_id", "name": "Utwórz zadanie · task_id", "type": "text" },
      { "source": "steps", "path": "steps.create_task.title", "name": "Utwórz zadanie · title", "type": "text" },
      { "source": "steps", "path": "steps.create_form_report.report_id", "name": "Utwórz raport formularza · report_id", "type": "text" },
      { "source": "steps", "path": "steps.create_form_report.report_name", "name": "Utwórz raport formularza · report_name", "type": "text" }
    ],
    "fields": [
      { "path": "fields.status", "field_id": "status", "label": "Status", "type": "enum", "operators": ["is", "is_not", "in"], "enumOptions": ["open", "urgent"] }
    ]
  }
}
```

A `variable` is `{ source, path, name, type, enumOptions?, nullable? }` — `source` is
`'trigger' | 'steps'`, `path` is the FULL dotted path a reference resolves against (identical in
both serializations — the editor directive and the structured union), `type` is a
`WorkflowVariableType`. A `field` descriptor (the CONDITION builder's subset) is `{ path,
field_id, label, type, enumOptions?, operators }` — `operators` is exactly the type's allowed
operator set (`WorkflowVariableType::operatorCases()`), so the FE can never offer an operator the
backend would reject.

**Repeaters are EXCLUDED from the catalog** — honest, not an oversight: a repeater's answers are
an array-of-objects (JSONB) that `fields.<id>` cannot resolve to a single comparable
scalar/flat-set, so emitting a variable for one would be a dead path (a reference that always
resolves to something the condition evaluator or a step config could never meaningfully use).
See ADR-0009 §7.

---

### GET /api/workflows/catalog

The FORM-INDEPENDENT variable catalog — the same catalog contract as
`GET /api/forms/{form}/workflow-catalog` above, but assembled from sources
(`WorkflowVariableCatalogService::forContext()`) so a workflow with **no form at all** — a
`schedule` trigger, or a `form_submitted` workflow before a form is chosen — still gets a real
catalog instead of the frontend maintaining a static mirror of the trigger-system variables and
step-output templates. A static `workflows/catalog` path, declared BEFORE the `{workflow}`
apiResource routes so it never binds as an id. Backed by
`WorkflowVariableCatalogController::index()` → `IndexWorkflowCatalogRequest` →
`WorkflowVariableCatalogService::forContext()`.

**Query**

| Param          | Notes                                                                                        |
|----------------|-------------------------------------------------------------------------------------------------|
| `trigger_type` | **required WITHOUT `form_id`** (`required_without:form_id`) — one of `WorkflowTriggerType::ids()` (`form_submitted`, `schedule`). Ignored when `form_id` is present (a form implies `form_submitted`, matching `forForm()`). |
| `form_id`      | optional uuid. When present, layers that form's field variables + condition field descriptors onto the structural catalog, exactly like the form-bound route.                                       |

**Two authorization paths** (`IndexWorkflowCatalogRequest::authorize()`):

- **No `form_id`** — `WorkflowPolicy::viewAny` (`$user !== null`, any authenticated user). The
  response carries **structural metadata only** — trigger-system vars for `trigger_type`, the
  `steps.<TYPE>.*` output templates, `operations`, `ai_personas`, `types` — **no tenant rows and no
  per-form field variables** — so authentication alone is enough to gate it; a workspace header is
  NOT required to call it (unlike the blanket "all endpoints require ... X-Workspace-Id" note at
  the top of this document), though membership is still enforced upstream by `ResolveWorkspace`
  whenever one is sent.
- **With `form_id`** — the form is resolved under `WorkspaceScope` (`Form::find`) and gated by
  `FormPolicy::view`, exactly like the `{form}` route-model binding above. A foreign-workspace or
  nonexistent `form_id` therefore **404s** (mirrors the binding failure), not 403.

**Response** `200 OK` — the identical envelope/shape `GET /api/forms/{form}/workflow-catalog`
returns (`{ data: { variables, fields, operations, ai_personas, types } }`; see the `types` key
below); a form-less call simply returns `fields: []` and no `trigger.fields.*` variables. Example
(`?trigger_type=schedule`, no form):

```json
{
  "data": {
    "variables": [
      { "source": "trigger", "path": "trigger.scheduled_at", "name": "Scheduled at", "type": "date" },
      { "source": "steps", "path": "steps.create_task.task_id", "name": "Utwórz zadanie · task_id", "type": "text" }
    ],
    "fields": [],
    "operations": [...],
    "ai_personas": [{ "id": "neutral" }, { "id": "friendly" }, { "id": "formal" }, { "id": "concise" }],
    "types": [...]
  }
}
```

**Errors**: `401` unauthenticated. `422` under `trigger_type` when both `trigger_type` and
`form_id` are absent, or `trigger_type` names an id outside `WorkflowTriggerType::ids()`. `404`
when `form_id` is a well-formed uuid that does not resolve in the active workspace (foreign or
missing).

This is why the endpoint exists: before Phase 0 of the variable-typesystem rework, a schedule
workflow's editor had no server catalog to call at all and carried a hand-maintained mirror of the
trigger-system variables / step-output templates instead — see
**ADR-0021-workflows-variable-catalog-composable-roots.md**.

---

### POST /api/workflows/schedule-assist

AI SCHEDULE ASSIST: turns a natural-language schedule description into the structured v2
`trigger_config.schedule` config (`{ time, day?, month?, exclusions?, tz? }`) the human builder
uses — or an honest report of what cannot be expressed, with an optional approximating
alternative. Authorization: any authenticated workspace member (workspace membership already
enforced upstream by `ResolveWorkspace`; the endpoint exposes no tenant data).

**The prompt's vocabulary is assembled programmatically from the v2 enums** — `ScheduleAssistAgent`
builds its instructions from `ScheduleTimeMode`, `ScheduleDayMode`, `ScheduleMonthMode`,
`ScheduleDaySpecial` and `ScheduleLimits` at construction time (one line per mode, one bound per
constant), so the model is never told about a mode or a bound the validator/compiler do not
actually enforce — the prompt cannot drift from the code. A model that still answers in the
PRE-v2 `{ family, params }` shape is not rejected either: the re-validation gate below runs every
proposed config through `LegacyScheduleUpgrader` first, so an old-format proposal is judged by the
same v2 rules and, if valid, returned **normalized to v2** — both `config` and `alternative.config`
are rewritten through the upgrader before the envelope is returned, so the FE always receives v2.

**Genuinely feasible today** (a non-exhaustive sample — see `ScheduleAssistAgent::examplesSection()`
for the full worked list the model is shown): multiple daily fire times ("o 8 i 17"), a set of
weekdays in one schedule ("w poniedziałki i środy"), the Nth/last weekday of the month ("ostatni
piątek miesiąca"), the last working day of the month, an every-N-days/hours/minutes grid with an
optional time-of-day window, and an `exclusions` skip filter ("oprócz weekendów", "oprócz sierpnia").

**Still genuinely infeasible** (see `ScheduleAssistAgent::semanticCaveats()` for the exact, current
list the model is instructed to report honestly): a rolling interval the wall-clock grids cannot
express (e.g. "dokładnie co 90 minut", "co 2,5 godziny" — the grids are modulo-N, not phased from
an arbitrary start), "every N weeks" (no such axis), one-off single dates, and sub-minute cadences.

**Body**

```json
{ "prompt": "codziennie o 8 i 17 oprócz weekendów", "tz": "Europe/Warsaw" }
```

| Field    | Required | Constraints                                    |
|-----------|-----------|-------------------------------------------------|
| `prompt`   | yes        | string, max 500 — UNTRUSTED free text, treated purely as data by the agent. |
| `tz`         | no          | nullable, a valid IANA timezone string.         |

**Response** `200 OK` — a feasible multi-time-plus-exclusions example (pinned by
`WorkflowScheduleAssistTest::test_feasible_multi_time_config_with_exclusions_passes_the_gate`):

```json
{
  "data": {
    "feasible": true,
    "config": { "time": { "mode": "at", "at": ["08:00", "17:00"] }, "exclusions": { "weekdays": [0, 6] }, "tz": "Europe/Warsaw" },
    "unsupported": [],
    "alternative": null,
    "explanation": "Codziennie o 8:00 i 17:00, z pominięciem weekendów."
  }
}
```

A feasible example using the `day.special` axis (the last weekday of the month — previously only
reachable through the `alternative` channel in the pre-v2 vocabulary):

```json
{
  "data": {
    "feasible": true,
    "config": { "time": { "mode": "at", "at": ["09:00"] }, "day": { "mode": "special", "special": "last_weekday", "weekday": 5 }, "tz": "Europe/Warsaw" },
    "unsupported": [],
    "alternative": null,
    "explanation": "Ustawiłem harmonogram na ostatni piątek każdego miesiąca o 9:00 (Europe/Warsaw)."
  }
}
```

An honest infeasible example WITH a valid alternative (pinned by
`test_infeasible_with_valid_alternative_is_passed_through` — "co 2 tygodnie", every-other-week, has
no axis; the model honestly falls back to a plain weekly schedule):

```json
{
  "data": {
    "feasible": false,
    "config": null,
    "unsupported": ["co 2 tygodnie — brak osi „co N tygodni”"],
    "alternative": { "config": { "time": { "mode": "at", "at": ["08:00"] }, "day": { "mode": "weekdays", "weekdays": [1] } }, "note": "Zaproponowano co tydzień w poniedziałek zamiast co 2 tygodnie." },
    "explanation": "Nie można ustawić co 2 tygodnie; proponuję co tydzień w poniedziałek."
  }
}
```

**429** — `WORKFLOWS_ASSIST_RATE_PER_MINUTE` (default 5) exceeded for this user, keyed
`workflow-schedule-assist:{userId}`. **This throttle needs a PERSISTENT cache store** — the
default `database` cache driver is fine in production; under the `array` driver the counter
resets every process (fine for tests, wrong for a real multi-request server). See Ops notes.

**The re-validation guarantee.** The model's `feasible`/`config` self-report is NEVER trusted
directly:

1. The agent runs tool-less, emitting a strict JSON string (`ScheduleAssistAgent`) — parsed
   defensively; malformed/empty output collapses to a safe `feasible:false` envelope, never a
   500.
2. Every response key is WHITELISTED — unknown keys the model invented are dropped before
   reaching the API response. The whitelist forwards BOTH the v2 axis keys (`time`, `day`,
   `month`, `exclusions`) AND the legacy bridge keys (`family`, `params`, `times`) verbatim, so a
   still-legacy-speaking proposal reaches the gate unmodified rather than being stripped.
3. A `config` claimed `feasible:true` is upgraded through `LegacyScheduleUpgrader` (a no-op on an
   already-v2 block) and RE-VALIDATED against the exact same rules the write path uses
   (`WorkflowScheduleRulesValidator`) AND run through the real compiler
   (`WorkflowScheduleCompiler::compile()`) as a final sanity gate. Either failing downgrades the
   response to `feasible:false`, `config:null`, with the validation failure appended to
   `unsupported`.
4. An `alternative.config` that fails the same gate is dropped (`alternative:null`) rather than
   surfaced broken — its failure reason is NOT appended to `unsupported` (only a main-config
   downgrade does that; a dropped alternative is simply removed, pinned by
   `test_dropped_alternative_does_not_leak_validator_jargon`).
5. A surviving config with no `tz` inherits the caller's `tz` hint (an explicit `tz` from the
   model wins).

This means: **a config the endpoint returns as `feasible:true` is GUARANTEED to be a valid,
compilable schedule** — the backend re-derives that guarantee itself, it does not take the
model's word for it.

**The residual honesty limit (accepted, not a bug).** The re-validation gate proves a returned
config is STRUCTURALLY valid and COMPILABLE — it cannot prove the config SEMANTICALLY matches
what the user asked for. If the model mis-reads "every weekday" as a plain daily `time.at` and
wrongly marks it `feasible:true`, the backend has no way to detect that the resulting (valid,
compilable) config is not what was actually requested — a structurally-valid-but-wrong config can
still reach the caller. Mitigations: the agent's instructions explicitly enumerate the closed
axis/mode vocabulary and forbid inventing a mode/field or approximating silently into `config`
(an approximation must go through `alternative` + an honest `note`, never straight into
`feasible: true`/`config`); the frontend always surfaces `explanation` so the user can
sanity-check the result before saving; nothing about this endpoint is fully
closed-loop-verifiable server-side.

**Stateless, not run-cap-counted.** A schedule-assist call creates nothing (no task, no run) — it
is metered by its OWN per-user throttle (`assist_rate_per_minute`), never against
`config('workflows.max_runs_per_month')` / `max_runs_hard_cap` (those meter workflow RUNS).

---

## Capability flags

`WorkflowResource` (detail) exposes the same server-authoritative capability-flag convention as
Bot/Approvals — the frontend must never invent authorization, only read these:

| Flag                | Source                                                    |
|-----------------------|----------------------------------------------------------------|
| `is_owner`             | `isOwnedBy(auth user)` — TRUE only for a HUMAN creator match; a run/bot-created (system) workflow is `false` even for the workspace owner. See `docs/backend/creator-attribution.md`. |
| `can_be_edited`        | `WorkflowPolicy::update` — the creator, OR (system workflow only) the workspace owner fallback. |
| `can_be_deleted`       | `WorkflowPolicy::delete` — same fallback rule as `can_be_edited`.                    |
| `can_change_status`    | `WorkflowPolicy::changeStatus` — same fallback rule as `can_be_edited`.               |
| `can_run`               | `WorkflowPolicy::run` (any workspace member).                |

**A `Workflow` is created only through the authenticated `POST /workflows` surface** (no engine
step creates a `Workflow`), so its creator is always human in practice today — the fallback row
above documents the general Policy behavior, not an observed divergence for this resource.

`WorkflowResource` also carries `creator` — the polymorphic discriminated union (`user | workflow_run
| bot | null`), eager-loaded on every detail response (`GET/POST/PUT/{id}/restore`). See
`docs/backend/creator-attribution.md` for the full shape and worked examples;
`resources/js/next/ui/patterns/creator.ts` / `CreatorBadge.vue` render it on the frontend.

`WorkflowListResource` carries only `is_owner` (lean list shape, via the hot-path `ownerUserId()` —
no `creator` eager-load, no `User` load) plus `step_count` (derived: `count(steps ?? [])`) and
`next_due_at`.

---

## Trigger configs — per type

### `form_submitted`

```json
{ "form_id": "b1b2c3d4-...", "source": { "in": ["manual", "task"] }, "anonymous": false }
```

| Key                | Required | Notes                                                                |
|----------------------|----------|--------------------------------------------------------------------------|
| `form_id`             | no       | nullable, tenant-scoped `Form` uuid (`ScopedExists(Form::class)`). `null` = any form. **REQUIRED when `conditions` is present** (conditions read one form's typed answer map — there is nothing to type-check against without one). |
| `source`               | no       | nullable object, `{ in: string[] }`.                                     |
| `source.in`            | no       | nullable array, each element `'manual' \| 'task'` (`Rule::in`). Absent/empty = any source. |
| `anonymous`             | no       | nullable boolean. `null` = either; `true`/`false` matches the form's `is_anonymous` flag exactly. |

**Matching (`WorkflowDispatchService::matchesFormSubmitted`)** — every present clause is
AND-combined, evaluated against the whitelisted trigger payload (no extra queries — `form.id`,
`source`, and `form.is_anonymous` are already in the snapshot):

- `form_id` absent/null → matches every form; present → must equal the payload's `form.id`.
- `source.in` absent/empty → matches every source; present → the payload's `source` must be a
  member.
- `anonymous` absent/null → matches either; present → must equal the payload's `form.is_anonymous`.

**Source vocabulary — `manual | task`, derived from the submission's polymorphic `submittable`
morph.** A `task`-attached submission (filled out as part of a task's attached form) normalizes
to `'task'`; everything else (a submission filled directly against the form, with no task) is
`'manual'`. **A bot-authored submission is NOT distinguishable from a human one in this
vocabulary today** — both normalize to whichever of `manual`/`task` their submittable happens to
be; there is no `source` value that means "a bot filled this in". See ADR-0009 §5 (deferred:
would need a new column to track authorship, not just the submittable morph).

**`anonymous` is a DERIVED tri-state**, not a stored trigger property: it reads
`form.is_anonymous` off the trigger payload at match time. The config's `anonymous` value is
itself a plain nullable boolean (not a tri-state enum) — `null` genuinely means "don't filter on
this", which IS the third state.

### `schedule`

```json
{ "schedule": { "time": { "mode": "at", "at": ["09:00"] }, "day": { "mode": "weekdays", "weekdays": [1, 3, 5] }, "tz": "Europe/Warsaw" } }
```

`schedule` is the v2 COMPOSITIONAL descriptor — three independent axes combined with AND, not one
of a closed set of named presets. See the Schedule section below for the complete axis/mode
reference; this table is the top-level shape only.

| Key                 | Required                        | Notes                                              |
|-----------------------|------------------------------------|-----------------------------------------------------|
| `schedule`             | **yes**                             | object, `{ time, day?, month?, exclusions?, tz? }`. |
| `schedule.time`      | **yes**                             | the WHEN-in-the-day axis; one of `at` / `every_minutes` / `every_hours`. The only required axis. |
| `schedule.day`      | no (default `every_day`)                          | the WHICH-day axis; one of `every_day` / `every_n_days` / `weekdays` / `month_days` / `special`. |
| `schedule.month`           | no (default `every_month`)                                  | the WHICH-month axis; one of `every_month` / `every_n_months` / `months`. |
| `schedule.exclusions`         | no                                  | object, `{ months?: int[1-12] max 11, weekdays?: int[0-6] max 6, dates?: 'Y-m-d'[] max 50 }` — a post-filter that drops any occurrence matching. See the Schedule section. |
| `schedule.tz`      | no                                  | IANA timezone string; default `config('app.timezone')` (UTC). |

Every field is validated by the ONE shared `WorkflowScheduleRulesValidator` (write path, AI-assist
re-validation, and the preview endpoint all delegate to it), so the accepted shape can never drift
from what `WorkflowScheduleCompiler` understands.

`schedule` workflows are **never event-dispatched** — `WorkflowDispatchService::dispatch()`
hard-refuses `WorkflowTriggerType::SCHEDULE` at the top. The ONLY path that starts a schedule run
is the `workflows:run-scheduled` sweep (or a manual run).

---

## Conditions — the typed system

Conditions are an OPTIONAL, flat list of `{ field, field_type, operator, value }` clauses,
**AND-combined** — every clause must pass for the workflow to run. An empty/absent list always
passes (an ungated workflow). **Conditions are ONLY valid for `form_submitted`** (a schedule run
has no field source in the MVP; any condition on a schedule trigger is a 422) and **REQUIRE
`trigger_config.form_id`** to be set (field conditions read one form's typed answer map — there
is no form to type-check against without one). Applied AFTER trigger matching, over the SAME
whitelisted `fields` map `{{trigger.fields.*}}` exposes.

`field` MUST be a `fields.<id>` path. The write path deliberately does NOT load the form schema
to verify `<id>` exists (that would couple write-validation to form content and race with form
edits) — a stale/wrong id is handled honestly at EVALUATION time by the missing-path semantics
below.

### The operator × type matrix (`WorkflowVariableType::operatorCases()`)

| `field_type` | Allowed operators                          | Payload comparison                                          |
|---------------|-----------------------------------------------|------------------------------------------------------------------|
| `text`         | `equals`, `not_equals`, `contains`             | string-normalized equality / negation; substring for `contains`. |
| `number`        | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`            | numeric comparison; a non-numeric side always fails.             |
| `date`           | `before`, `after`, `on`, `between`               | Carbon instant/day compare; `between` takes a `[from, to]` pair. |
| `enum`            | `is`, `is_not`, `in`                              | string equality / negation / array membership.                   |
| `multi`            | `includes`, `excludes`                             | array membership over the payload's array value.                 |
| `boolean`           | `is_true`, `is_false`                               | value-LESS — truthiness of the payload value.                    |

An operator submitted for the WRONG `field_type` is a 422 (`conditions.<i>.operator`): *"The
`in` operator is not valid for a `date` field."*

### Value shape per operator

| Operator(s)                                       | Value shape                                                          |
|-------------------------------------------------------|--------------------------------------------------------------------------|
| `is_true`, `is_false`                                   | **value-less** — the `value` key is absent/ignored.                     |
| `between`                                                | a 2-element array `[from, to]` of parseable date strings.                |
| `in`                                                       | a non-empty array of strings.                                             |
| `before` / `after` / `on`                                    | a single parseable date string.                                          |
| `eq` / `neq` / `gt` / `gte` / `lt` / `lte`                     | a single numeric value.                                                  |
| everything else (`equals`/`not_equals`/`contains`/`is`/`is_not`/`includes`/`excludes`) | a single non-null, non-empty scalar (or an array for `includes`/`excludes`, matched against the payload's array). |

### Missing-path semantics (verified in `WorkflowConditionEvaluator`)

When `field`'s dotted path is ABSENT from the payload (`Arr::get` misses), the clause **fails for
every operator EXCEPT the operators that assert an ABSENCE** — `not_equals`, `neq`, `is_not`,
`excludes` — which **PASS** (`WorkflowConditionOperator::passesOnMissingPath()`):

```
"fields.status equals urgent"       on a payload with no fields.status  → FAILS
"fields.status not_equals urgent"   on a payload with no fields.status  → PASSES
"fields.tags includes urgent"       on a payload with no fields.tags   → FAILS
"fields.tags excludes urgent"       on a payload with no fields.tags   → PASSES
```

Rationale: *"status is_not done" should hold when the payload carries no status at all (there is
nothing that equals done), whereas "status is done" cannot hold without a status.* This keeps
every negative operator a true logical negation over present values while giving the intuitive
answer when the field is absent.

An **unknown type/operator combination**, or an operator not in the type's own allow-list
(should never occur post-validation — the FormRequest enum-checks and cross-checks both), fails
**closed** (the clause never passes), so a corrupted definition can never silently open the gate.

### Example

```json
"trigger_config": { "form_id": "b1b2c3d4-...", "source": null, "anonymous": null },
"conditions": [
  { "field": "fields.priority", "field_type": "enum", "operator": "is", "value": "urgent" },
  { "field": "fields.due_date", "field_type": "date", "operator": "before", "value": "2026-08-01" }
]
```

---

## The typed variable system

A **variable identity** is always the triple `{ source: trigger|steps, path, type }` — `path` is
the FULL dotted path a reference resolves against (e.g. `trigger.fields.status`,
`steps.create_task.task_id`), identical across both serializations below. This ONE identity is
served by `GET /forms/{form}/workflow-catalog` (or, for a form-less workflow,
`GET /workflows/catalog` — see above) and consumed by both step config surfaces.

### Two serializations, resolved by `WorkflowVariableResolver`

**1. TEXT / markdown fields** (e.g. `create_task.title`, `create_form_report.name`,
`.guidelines`) carry the next editor's **variable directive** — a markdown directive of the
exact byte shape the editor's `encodeVariableDirective` produces:

```
@[variable]("{\"v\":1,\"data\":{\"id\":\"trigger.fields.status\",\"name\":\"Status\",\"type\":\"text\",\"locked\":false}}")
```

The directive's identity lookup (`data.id`) is always honored. **SB1 adds RUNTIME pipeline
execution**: when `data.pipeline` is a non-empty list of `{operationId|op, args}` steps (the next
editor's pipeline-editor format), `WorkflowVariableResolver` runs it through the shared
`WorkflowOperationExecutor` and STRINGIFIES the typed result into the surrounding text; an EMPTY
(or absent) pipeline keeps the original identity-only behavior unchanged, WITH one reviewer fix:
a STANDALONE identity chip that IS the whole field (no pipeline) now always STRINGIFIES its
looked-up value before it reaches the field — a bare non-text variable (a multi-select, a number,
a boolean) resolving into e.g. `create_task.title` becomes its text representation, never the raw
array/scalar that would otherwise reach the title's non-empty-string check and hard-fail the run
with a misleading "requires a title". **The directive still
carries NO extra type field beyond the editor's own primitive** (`data.type` is the editor's
rendering primitive, text/number/boolean — NOT the workflow type, and NOT trusted as the
pipeline's base type either). The pipeline's REAL base type is recovered, in order: (1) the run's
TYPE MAP by path (`WorkflowVariableCatalogService::runtimeTypeMap()`), (2) failing that, the
pipeline's first operation's declared input type (the editor authored the pipeline against the
real type), (3) failing that, `text`. See "Runtime operations, if-blocks, and AI text" below for
the full pipeline/if-block/ai-text contract, including the fail-closed doctrine.

```
@[variable]("{\"v\":1,\"data\":{\"id\":\"trigger.fields.due_date\",\"name\":\"Due date\",\"type\":\"text\",\"locked\":false,\"pipeline\":[{\"operationId\":\"date_add_days\",\"stepId\":\"s1\",\"args\":{\"value\":3},\"outputType\":\"date\"}]}}")
```

resolves to the field's `due_date` value plus 3 days, stringified `Y-m-d` (a `date`-typed
terminal renders `Y-m-d`; every other terminal type renders through the same stringify rules the
identity embed already used — numbers naturally, booleans as `true`/`false`).

**2. NON-TEXT (structured) fields** (`create_task.priority`, `.deadline`;
`create_form_report.submissions_from`, `.submissions_to`) carry the **`{kind}` union** — SB1 adds
an OPTIONAL `pipeline` to the `variable` arm:

```json
{ "kind": "literal", "value": "high" }
{ "kind": "variable", "ref": { "source": "trigger", "path": "trigger.fields.priority", "type": "enum" } }
{ "kind": "variable", "ref": { "source": "trigger", "path": "trigger.fields.category", "type": "enum" }, "pipeline": [{ "op": "enum_to_choice", "args": { "mapping": { "blog": "high", "news": "low" } } }] }
{ "kind": "variable", "ref": { "source": "trigger", "path": "trigger.fields.headline", "type": "text" }, "pipeline": [{ "op": "match_to_choice", "args": { "rules": [{ "when": "BREAKING", "then": "urgent" }], "fallback": "medium" } }] }
```

`resolveValueOrVariable()` handles this: a `literal` resolves (and type-coerces) its `value`
directly; a `variable` WITHOUT a `pipeline` (or an empty one) looks up `ref.path` in the run
context then coerces the result to the field's EXPECTED type, unchanged from before SB1 (e.g.
`priority` coerces to `WorkflowVariableType::ENUM`, `deadline` to `DATE`). A `variable` WITH a
non-empty `pipeline` instead runs it through `WorkflowOperationExecutor` **from `ref.type`**
(never the field's expected type — the pipeline's own declared base) and coerces the TYPED RESULT
to the field's expected type. A pipeline FAILURE coerces to `null` — the exact same soft default
an unresolved ref already produced, so a bad pipeline degrades exactly like a bad reference (the
field's own soft doctrine still applies: `priority` defaults to `medium`, `deadline` to `null`). A
bare scalar in a structured slot (no `kind` wrapper) is tolerantly treated as a literal, unchanged.
**Unlike the markdown directive's pipeline, THIS pipeline IS write-validated** — see "Write-time
validation" below, and "Choice fields" further down for `priority`'s stricter, CHOICE-only
terminal contract (`enum_to_choice` / `match_to_choice` — the runtime coercion above is unchanged,
only the write validator is stricter).

### Coercion table (`WorkflowVariableResolver::coerce`)

| Expected type | Coercion                                                       |
|-----------------|-------------------------------------------------------------------|
| `date`            | Carbon-parsed to an ISO-8601 string; unparseable/empty → `null`.   |
| `number`            | numeric value + 0 (int or float); non-numeric → `null`.             |
| `boolean`             | `filter_var(..., FILTER_VALIDATE_BOOLEAN)`.                          |
| `multi`                 | array passthrough; a scalar is wrapped in a 1-element array.        |
| `enum` / `text`           | scalar cast to string; a non-scalar (array) → `null`.                |

---

### The resolver's whitelist (exfiltration-safe)

Exactly two readable context roots: `trigger` and `steps`. A path rooted anywhere else (e.g.
`env.*`, `config.*`, `__proto__.*`) is **NOT recognised as a reference at all** — a standalone
occurrence resolves to `null`/stays literal, an embedded occurrence stays completely literal in
the surrounding text. There is no expression language (no filters, arithmetic, method calls, or
conditionals) — a plain whitelisted `Arr::get` lookup has no code path through which a crafted
`trigger_config`/`steps.*.config` value could execute anything beyond a dictionary read.

### Transitional flat `{{...}}` tokens

`{{trigger.task.id}}`-style flat tokens (both standalone — resolving to the raw typed value —
and embedded — stringified into surrounding text) are **still resolved**, alongside the
directive/union serializations above. This keeps any already-authored step config or e2e path
working during the 5.1 migration; it is NOT a second expression language, just the same
whitelisted `Arr::get` lookup under different bracket syntax. New editor-authored content uses
the directive/union shapes; the flat grammar is not the primary authoring path going forward.

**Reviewer fix — an embedded directive's resolved value is MASKED before the flat pass runs**, so
it can never be re-scanned as a second flat token. Without this, a form value that itself
happens to contain literal `{{...}}` bytes (typed by an end user, not authored by the workflow)
would be substituted in by the directive pass and then ACCIDENTALLY matched again by the flat-token
pass right after — a second-order "injection" purely by coincidence of content. Each resolved
embedded directive is stashed behind a NUL-delimited placeholder (form values can never contain a
NUL byte — Postgres rejects it — so a value can never forge one) and restored only AFTER the flat
pass has already run. This mirrors the same masking `@[ai-text]`'s generated output already uses
(see "Runtime operations, if-blocks, and AI text" → §c above) — applied here to every directive's
looked-up value, not just AI-generated text.

### Structured `descriptor` (phase-1a, additive)

Every `variable` entry in BOTH catalog responses (`GET /forms/{form}/workflow-catalog`,
`GET /workflows/catalog`) now ALSO carries `descriptor: { base, nullable, array, options? }`
(`WorkflowVariableCatalogService::variable()`, built by `WorkflowVariableType::descriptor()`) —
alongside the UNCHANGED flat `type`/`enumOptions?`/`nullable?` keys. Nothing about the flat shape
changed; `descriptor` is a second, richer view of the same variable:

| Key | Meaning |
|---|---|
| `base` | The type's own scalar base — EXCEPT `multi`, whose base is `enum` (a multi is "an array of enum"); every other type (`time` included) is its own base. |
| `array` | `true` only for a `multi` variable. |
| `nullable` | Mirrors the variable's own `nullable` flag. |
| `options` | Present ONLY when `base === 'enum'` (an `enum` or `multi` variable): a list of `{ key, label }`. `key` is the SAME string the flat `enumOptions` already carries (the wire value stored/matched at runtime — unchanged); `label` is the human-readable option label. |

For a FORM field (a `select`/`checklist` element), `label` is read from the element's
`config.options` — the only place it survives, since `FormElementType::toJsonSchema` emits option
VALUES only into the JSON schema `enum`. When the element config carries no label for an option,
the label falls back to the option's own value. A system/step enum variable (e.g.
`trigger.source`) has no element config to read, so every option's label equals its key.

```json
{ "source": "trigger", "path": "trigger.fields.category", "name": "Category", "type": "enum",
  "enumOptions": ["blog", "news"],
  "descriptor": { "base": "enum", "nullable": false, "array": false,
    "options": [{ "key": "blog", "label": "Blog" }, { "key": "news", "label": "News" }] } }

{ "source": "trigger", "path": "trigger.fields.channels", "name": "Channels", "type": "multi",
  "enumOptions": ["fb", "ig"],
  "descriptor": { "base": "enum", "nullable": false, "array": true,
    "options": [{ "key": "fb", "label": "Facebook" }, { "key": "ig", "label": "Instagram" }] } }
```

A `time` field (the form builder's TIME element, `format:'time'`) keeps its flat `type` degraded
to `text` (unchanged runtime/FE behavior — see "Runtime operations" below), but its
`descriptor.base` is the real `time`:

```json
{ "source": "trigger", "path": "trigger.fields.start_time", "name": "Start time", "type": "text",
  "descriptor": { "base": "time", "nullable": false, "array": false } }
```

**Frontend consumption**: `CatalogVariable.descriptor` is OPTIONAL on the TypeScript side (a
label-less/older fixture without it still parses). `variableOptionList()`
(`resources/js/next/pages/workflows/workflowVariables.ts`) prefers `descriptor.options`
(rendering the human `label`, emitting the `key` as the stored/compared value) and falls back to
the flat `enumOptions` (label = value) only when no descriptor is present — the single place the
editor turns a variable's choices into human labels, so the variable picker, pipeline
`sourceOption`/`sourceMap` args, and the choice-mapping UI (ADR-0014) all agree.

### Per-reference `default`s (phase-1b, additive)

Both wire serializations of a variable reference gained an OPTIONAL literal `default`:

- The markdown directive: a `data.default` scalar, alongside `data.id`/`data.pipeline`/etc.
- The `{kind:'variable'}` union: a sibling `default` key next to `ref`/`pipeline`.

`WorkflowVariableResolver::applyDefault()` substitutes it when the looked-up value is `null` or
`''` (empty string) — for an identity-only reference exactly as for a piped one. The default is
substituted BEFORE any pipeline runs, so it can itself be transformed/formatted like a real value
(e.g. a missing date reference can default to an ISO string a downstream `date_format` op then
renders). When the reference already resolves to a real value, the default is never consulted.

```
@[variable]("{\"v\":1,\"data\":{\"id\":\"trigger.fields.due_date\",\"name\":\"Due date\",\"type\":\"text\",\"locked\":false,\"pipeline\":[],\"resultType\":\"text\",\"default\":\"2026-01-09\"}}")
```

```json
{ "kind": "variable", "ref": { "source": "trigger", "path": "trigger.fields.due_date", "type": "date" }, "default": "2026-01-09" }
```

**Injection-guard invariant (stated explicitly — a security property, not an implementation
detail).** A substituted default enters the resolved-value stream at EXACTLY the point a real
context value would, so it flows through the SAME NUL-delimited placeholder mask an embedded
directive's resolved value already uses before the transitional flat `{{...}}` pass runs (see the
"Reviewer fix" masking note above). A default literal that happens to contain `{{...}}` or
`@[...]` bytes is therefore NEVER re-interpreted as a second-order reference — the exact same
guarantee untrusted user-typed form content already had. A standalone directive or a
structured-slot default is never re-scanned at all (there is no second pass over that shape).

**Wire economy**: the frontend only serializes `default` when it is non-empty
(`encodeVariableDirective`; `ValueOrVariableField.vue`'s `saveModal()`), so a reference with no
default stays byte-identical to a pre-Phase-1 payload. The "Default when empty" affordance appears
in both places a reference is edited once one is picked: `ValueOrVariableField.vue` (the
structured value-or-variable field, e.g. `create_task.deadline`) and the markdown editor's
`VariablePanel.vue` (the `@[variable]` chip's edit modal) — one low-emphasis text input each,
empty ⇒ omitted from the wire.

See **ADR-0022-workflows-variable-typesystem-phase1.md** for the full design record (why a second
additive `descriptor` field instead of reshaping the flat one, the TIME loud-tripwire trade-off,
and why the default is a plain literal rather than a nested reference).

---

## Runtime operations, if-blocks, and AI text (SB1 / SB2)

Before this revision both serializations above only ever did identity lookup + coercion. SB1
extends the runtime resolver (`WorkflowVariableResolver`) with three additional capabilities, and
SB2 adds a fourth (AI text generation). All four share ONE property: **they never throw** — every
failure mode collapses to a fail-closed default (`''` for a text-field directive/if-block/ai-text
result, the field's own soft coercion for a value-or-variable pipeline — see the coercion table
above), so a bad configuration degrades a field's CONTENT, never the whole run (except where a
field's own hard-fail doctrine already applied, e.g. a blank `create_task.title` — see the Steps
section's hard/soft table, unchanged).

**Where each is enabled** (a frontend/editor decision, not a backend restriction — the resolver
itself would execute any of these in any text field): all four text fields —
`create_task.title`/`.description` and `create_form_report.name`/`.guidelines` — offer directive
pipelines, if-blocks, AND `@[ai-text]`. This is a FE-only change (SF3.6 in
`docs/next/workflows-uxui-spec.md` §4.6.3): `title`/`name` originally rendered as toolbar-less,
single-line editors offering pipelines only, but now render as ordinary multi-line editors with
the full toolbar, carrying the same capability `description`/`guidelines` always had. See the
Steps section for the field-by-field config table.

### a. Directive pipelines (`@[variable]` in text fields)

Covered above under "Two serializations" — a non-empty `data.pipeline` on the markdown directive
transforms the resolved value through `WorkflowOperationExecutor` and stringifies the typed
result into the surrounding text.

### b. Conditional `if-block`s in text fields

A markdown field may contain a FENCED `if-block` container — the exact byte format the next
editor's markdown serializer produces (see `resources/js/next/ui/editor/README.md` for the
authoring/encoding side, which this runtime mirrors byte-for-byte):

```text
```if-block {"id":"if_1","v":1}
[[IF {"id":"b1","condition":{"variableId":"trigger.fields.priority","pipeline":[{"op":"enum_is","args":{"value":"urgent"}}]}}]]
This is urgent — @[variable]("...")!
[[ELSE_IF {"id":"b2","condition":{"variableId":"trigger.fields.priority","pipeline":[{"op":"enum_is","args":{"value":"high"}}]}}]]
High priority.
[[ELSE {"id":"b3"}]]
Standard priority.
```
```

`WorkflowVariableResolver::resolveString` scans for `` ```if-block `` fences and, for each one,
evaluates every branch's `condition.variableId` + `condition.pipeline` IN ORDER — `IF`, then each
`ELSE_IF`, falling to `ELSE`, or to `''` when nothing matches — and replaces the WHOLE fence with
the winning branch's body, resolved RECURSIVELY (a branch body may itself carry nested
directives, nested if-blocks, or an `@[ai-text]`). A condition reads `variableId` off the run
context, recovers its REAL base type the same way a directive pipeline does (type map by path,
else the pipeline's first-op input type, else falling to `boolean`), runs it through
`WorkflowOperationExecutor`, and requires a `true` **boolean** terminal. Nesting is capped at
depth **6** (`IF_BLOCK_MAX_DEPTH` — a margin over the editor's own default `maxDepth: 3`) — beyond
the cap a block resolves to `''`. A missing/non-reference `variableId`, an unparseable condition,
or ANY executor failure is fail-closed to `false` (never surfaced as an error) — a misconfigured
condition simply falls through to `ELSE` (or to `''` with no `ELSE`).

### c. `@[ai-text]` — AI-generated text (SB2)

A markdown field may contain an `@[ai-text]("<json>")` directive — `<json>` (after `\"`→`"`
un-escaping) is `{"v":1,"data":{id, personaId, prompt, labels}}`; `labels` is accepted on the wire
but NOT consumed by the runtime (an editor-only concern). At RUN time:

1. `data.prompt` (markdown that may itself contain a nested `@[variable]`, if-block, or another
   `@[ai-text]` directive) is resolved through the SAME resolver / run context / type map FIRST —
   so form values, step outputs, and conditional branches land in the prompt BEFORE it reaches the
   model. Nested `@[ai-text]` is depth-capped at **3** (`AI_TEXT_MAX_DEPTH`) — beyond the cap
   resolves to `''` with NO further AI call spent.
2. The resolved prompt + `WorkflowAiPersona::fromNullable(personaId)` (defaults to `neutral` for
   a null/unknown id) are handed to `WorkflowAiTextService::generate()`, which runs the tool-less
   `WorkflowAiTextAgent` (provider/model from `config('ai')`; NO tools, NO structured-output
   schema — plain text only, read from `$response->text`) and trims + length-caps the result.
3. The generated string REPLACES the directive span verbatim — it is never re-interpreted as a
   reference/directive itself (every `@[ai-text]` span is masked to an inert placeholder before
   the variable/flat-token passes run, then restored with the generated text afterward).

**Fail-closed, budgeted, length-capped — never an exception:**

| Failure mode | Result |
|---|---|
| Blank prompt after resolution | `''` — no AI call spent. |
| Per-run call budget exhausted (`config('workflows.ai_text_max_calls_per_run')`, default 10) | `''` — logged, no call. |
| Provider/transport failure (missing key, timeout, any exception) | `''` — logged, never thrown. |
| Generated text longer than `config('workflows.ai_text_max_chars')` (default 2000) | truncated (multibyte-safe) to the cap. |

The call BUDGET is scoped to ONE run — `WorkflowAiTextService` is resolved fresh alongside the
resolver for each `WorkflowRunJob` (never bound as a container singleton), so its call counter
naturally resets every run; it bounds how much a SINGLE run can fan out into AI spend,
independent of the run-budget cost caps below (`max_runs_per_month` etc.), which meter the NUMBER
of runs, not AI calls within one.

**Personas — a closed set of TONES, not the bot system.** `WorkflowAiPersona`: `neutral`
(default) | `friendly` | `formal` | `concise` — each folds a short English style instruction into
the agent's system prompt (steering TONE only; the agent is always told to write in the language
of the resolved prompt, so the English tone line never forces English output). This is
DELIBERATELY NOT the Bot/Character system — see ADR-0013 for the alternatives considered and why
a bot-as-persona idea was left for a possible future, not built now.

**Prompt-injection posture (accepted, bounded risk).** The resolved prompt embeds values taken
from user-submitted forms (untrusted input). `WorkflowAiTextAgent`'s instructions frame
EVERYTHING in the prompt as DATA to write about, never as commands, and explicitly tell the model
to ignore any embedded command/role-play/rule-change attempt. The blast radius stays narrow even
if that framing is defeated: the agent has NO tools, its output lands only in a task/report text
field inside the SAME workspace the run belongs to, it is length-capped, and it can reference only
the whitelisted `trigger`/`steps` context (the same exfiltration-safe whitelist every other
directive already relies on — see below). This is a documented, ACCEPTED risk, not eliminated.

Both catalog endpoints — `GET /forms/{form}/workflow-catalog` and, since Phase 0 of the
variable-typesystem rework, the form-independent `GET /workflows/catalog` (see above) — also
return `ai_personas` (the label-less persona catalog the ai-text editor's persona picker consumes;
the FE localizes via `workflows.aiPersona.<id>`) and `types` (see below):

```json
{
  "data": {
    "variables": [...],
    "fields": [...],
    "operations": [...],
    "ai_personas": [{ "id": "neutral" }, { "id": "friendly" }, { "id": "formal" }, { "id": "concise" }],
    "types": [
      { "id": "text", "primitive": "text", "operators": ["equals", "not_equals", "contains"] },
      { "id": "number", "primitive": "number", "operators": ["eq", "neq", "gt", "gte", "lt", "lte"] },
      { "id": "boolean", "primitive": "boolean", "operators": ["is_true", "is_false"] },
      { "id": "date", "primitive": "text", "operators": ["before", "after", "on", "between"] },
      { "id": "enum", "primitive": "text", "operators": ["is", "is_not", "in"] },
      { "id": "multi", "primitive": "text", "operators": ["includes", "excludes"] },
      { "id": "file", "primitive": "text", "operators": ["filled", "empty"] }
    ]
  }
}
```

(`operations` — the 68-op catalog `WorkflowOperation::catalog()` the directive/value pipelines
above run on (66 at the time SB1/SB2 shipped `ai_personas` as its sibling key; now 68 after the
`enum_to_choice`/`match_to_choice` addition — see "Choice fields" below).)

**`types`** (`WorkflowVariableCatalogService::variableTypes()`) — one entry per
`WorkflowVariableType` case, `{ id, primitive, operators }`, in enum declaration order (text,
number, boolean, date, enum, multi, file):

- `id` — the `WorkflowVariableType` value.
- `primitive` — the EDITOR primitive (`WorkflowVariableType::editorPrimitive()`) this type
  degrades to inside a markdown directive's `data.type`. Only `number` and `boolean` keep their
  own primitive; `date`/`enum`/`multi`/`file` all degrade to `text` (see "Two serializations"
  above) — the directive carries no other type hint, so this is how the FE knows which types
  round-trip losslessly through a directive and which don't.
- `operators` — exactly `WorkflowVariableType::operators()` for that type, the SAME set already
  shown per-field in `fields[].operators` and enforced by the condition write-validator (see "The
  operator × type matrix" below) — one wire source for the type vocabulary instead of a
  hand-maintained frontend mirror of it.

Label-less like `operations`/`ai_personas` (the FE localizes each type's display name); it exists
so a form-less catalog can still describe the full type system without a static frontend mirror —
the same motivation `GET /workflows/catalog` itself was built for.

**Phase 1 additions (append-only, no breaking change to either list).** `types` gains an 8th
entry, `{ id: "time", primitive: "text", operators: [] }` — `WorkflowVariableType::TIME` is
descriptor-only this phase (see "Structured `descriptor`" above), so it carries no operators yet.
`operations` grows from 68 to **77** (72 immediately before this phase, +5 append-only —
`coalesce`/`is_present`/`is_null`/`assert_present`/`date_format`, see "Presence, null-handling,
and date-format ops" below).

### d. Write-time validation — runtime-only vs. validated

There is NO PHP markdown parser in this codebase, so a text field's directive pipeline / if-block
/ ai-text content is **NOT validated at write time** — `StoreWorkflowRequest` only checks that
`title`/`name` (etc.) are non-empty strings, never parses their markdown content. Every failure
mode described above is therefore a RUNTIME concern only, always fail-closed — an author can save
a step whose description contains a malformed if-block or a pipeline that will fail at every run;
the workflow saves, and the field simply resolves emptier than intended at run time.

**The value-or-variable pipeline is the one exception.** Because it lives in a structured
(non-markdown) field, `StoreWorkflowRequest` (via
`WorkflowConditionTreeValidator::validateValuePipeline()`) DOES type-flow-validate it on save —
walking the pipeline from the ref's declared type through each op to a required TERMINAL type per
field:

| Field | Allowed pipeline terminal(s) |
|---|---|
| `create_task.priority` | **CHOICE** — must END in a choice-producing op (`enum_to_choice` / `match_to_choice`); see "Choice fields" below. |
| `create_task.deadline` | `date` |
| `create_form_report.submissions_from` / `.submissions_to` | `date` |

A wrong terminal, an unknown op, a type mismatch mid-pipeline, or a bad arg (an out-of-catalog
`sourceOption`/`sourceMap` key, a non-Y-m-d literal date, a foreign arg key, …) is a `422` under
`steps.<i>.config.<field>.pipeline.<m>.op` / `.args.<key>` / `.args.<key>.<index>` — the SAME
indexed-key convention a condition pipeline already uses (`WorkflowConditionTreeValidator` is the
ONE place both pipelines' arg/type rules live), so the frontend maps every message to the
offending pipeline step. The reference catalog this validates against (`ref.type` +
`sourceOption`/`sourceMap` option membership) is `WorkflowVariableCatalogService::referenceIndex()`
— built ONLY when some step actually carries a value-or-variable pipeline, so the common
(pipeline-less) create/update path pays no extra cost.

### e. Choice fields — `targetOptions` + the `producesChoice()` terminal rule

A **choice field** is a value-or-variable field whose destination is not just a workflow TYPE but
one of a small, FIXED set of option VALUES — today, exactly `create_task.priority`
(`TaskPriority::ids()` = `urgent|high|medium|low`). Two operations exist to map an arbitrary
source value into such a set:

| Op | Input → output | Args | Runtime semantics |
|---|---|---|---|
| `enum_to_choice` | `enum` → `enum` (choice) | `mapping` — a `sourceMap` arg (`mapType: enum`): each SOURCE option value → one DESTINATION option value. | Looks the source option up in `mapping`; an UNMAPPED source option fails closed (same as every other `enum_to_*` op). |
| `match_to_choice` | `text` → `enum` (choice) | `rules` — a `choiceRules` list of `{when, then}` (first match wins); `fallback` — a REQUIRED `choiceFallback` (used when no rule matches, keeping the op total). | Compares the text value against each rule's `when` in order; the first equal match's `then` wins, else `fallback`. A missing/blank `fallback` fails closed. |

`WorkflowOperation::producesChoice()` is `true` for exactly these two ops (and only these two) —
callers check this method at the TERMINAL-op position rather than hardcoding op ids, so a future
choice-producing op is picked up automatically. Any NON-text source (number/boolean/date/multi)
reaches `match_to_choice` the same way it reaches any other text-only op: through the existing
`*_to_text` op first (e.g. `date_to_text` → `match_to_choice`).

**The destination option set is injected PER-FIELD, not part of the static op descriptor.**
`enum_to_choice`'s `mapping` arg and `match_to_choice`'s `rules`/`fallback` args are declared with
NO fixed option list (`WorkflowOperationArgType::CHOICE_RULES` / `CHOICE_FALLBACK`, and a
`sourceMap` arg whose `mapType` is `enum`) — the actual allowed VALUES
(`$targetOptions`, e.g. `TaskPriority::ids()`) are threaded into
`WorkflowConditionTreeValidator::validateValuePipeline()` by the caller
(`StoreWorkflowRequest::validateCreateTaskConfig()`) for the ONE field that needs them today. This
keeps the 68-op catalog itself generic (an op descriptor never hardcodes "priority") while still
letting the write validator enforce that every mapped/ruled value is a real option of the
DESTINATION field.

**Write contract for a choice field.** A value-or-variable field whose destination is a choice
field:

- a **literal** must still be a plain member of the field's own enum (e.g. a literal `priority`
  must be a valid `TaskPriority`) — unchanged from before this batch;
- a **variable** MUST carry a NON-EMPTY pipeline that ENDS in a `producesChoice()` op, and every
  mapped/ruled target value in that pipeline must be `⊆` the field's option set. A bare/identity
  variable ref (no pipeline) or a pipeline whose terminal is NOT a choice op (e.g. `enum_to_text`,
  which used to be accepted under the old "enum or text" terminal rule) is now **REJECTED** —
  `422` under `steps.<i>.config.priority.pipeline` (no pipeline) or
  `.pipeline.<m>.op` (wrong terminal) or `.pipeline.<m>.args.mapping.<key>` /
  `.args.rules.<i>.then` / `.args.fallback` (an out-of-set target value).

```json
{ "op": "enum_to_choice", "args": { "mapping": { "blog": "high", "news": "low" } } }
{ "op": "match_to_choice", "args": { "rules": [{ "when": "BREAKING", "then": "urgent" }], "fallback": "medium" } }
```

`deadline` / `submissions_from` / `submissions_to` are plain `date` fields — they never carry
`targetOptions` (the date terminal is not a choice), so their existing "enum or text vs. date"
distinction is untouched by this section.

**This is a validator-only tightening, not a runtime behavior change.** `WorkflowVariableResolver`
never calls `producesChoice()` — at RUN time a `priority` pipeline still just executes and coerces
its result to `WorkflowVariableType::ENUM` exactly as before (a result outside `TaskPriority`
soft-defaults to `medium`, same as an unresolved/unknown value always has). A workflow SAVED
before this change keeps firing and keeps producing the same task priority it always did; only
attempting to RE-SAVE a step whose `priority` pipeline does not end in a choice op now fails with
a `422` where it previously passed. See `docs/decisions/ADR-0014-workflows-choice-coercion.md` for
the full rationale (why a generic `enum` type + injected `targetOptions` + a `producesChoice()`
terminal rule, instead of a new branded "choice" type in the closed
`WorkflowVariableType`/`WorkflowOperationArgType` sets).

### f. Presence, null-handling, and date-format ops (phase-1b, append-only)

5 operations are appended to `WorkflowOperation` — ids are only ever appended, never reordered or
removed (pinned by `WorkflowConditionEngineTest::test_operation_ids_are_the_pinned_wire_contract`),
growing the catalog **72 → 77**:

| Op | Input → output (nominal) | Args | Runtime semantics |
|---|---|---|---|
| `coalesce` | `text` → `text` | `fallback` (literal) | The running value when present, else `fallback` normalized to the running type. |
| `is_present` | `text` → `boolean` | — | `true` when the running value is non-empty. |
| `is_null` | `text` → `boolean` | — | The negation of `is_present`. |
| `assert_present` | `text` → `text` | — | The running value when present; over an EMPTY value, the ONE opt-in HARD failure (see below). |
| `date_format` | `date` → `text` | `pattern` (literal, safe-token) | Renders the date via the safe-token pattern below. NOT a presence op. |

**The first four are the PRESENCE family** (`WorkflowOperation::isPresenceOp()`). Their declared
`input`/`output` above are the NOMINAL shape the catalog and the write-validator advertise; at
RUN time `WorkflowOperationExecutor::execute()` dispatches them BEFORE the normal per-step
`inputType() !== currentType` gate, so — unlike every other op — they accept the running value AS
IS regardless of its declared type, including a base value that failed normalization outright (a
genuinely absent/unrepresentable value a normal op would already have failed closed on).
"Empty" for this family (`isEmptyValue()`) is `null`, `''`, `[]`, or an unnormalizable base —
mirroring the existing FILLED/EMPTY condition semantics.

**`date_format`'s safe-token whitelist** (`WorkflowOperationExecutor::DATE_FORMAT_TOKENS`,
matched longest-first so `MMMM` never loses to `MM`) — a raw PHP `date()` format string is NEVER
honored; any byte outside this table or the literal separators ` - / : . ,` fails the WHOLE
pattern CLOSED (soft failure — `''`/coerced null downstream — never a throw):

| Token | Renders |
|---|---|
| `YYYY` | 4-digit year |
| `MMMM` | full month name |
| `MMM` | short month name |
| `MM` | 2-digit month |
| `DD` | 2-digit day |
| `D` | unpadded day |
| `HH` | 2-digit hour |
| `mm` | 2-digit minute |

Example: pattern `DD/MM/YYYY` over `2026-01-09` renders `09/01/2026`; `D MMMM YYYY` renders
`9 January 2026` (`WorkflowOperationExecutorTest`).

**`assert_present` is the ONE opt-in HARD failure in the pipeline engine.** `OperationResult`
gained a `bool $hard` flag + a `hardFailure()` factory. Over an empty value, `assert_present`
returns `OperationResult::hardFailure()` — still a `failed` result, so a CONDITION caller (which
only ever reads `$result->failed`) is unaffected and stays fail-closed to `false` exactly as
before. A VALUE-producing caller (`WorkflowVariableResolver::applyDirectivePipeline()` /
`resolveVariableUnion()`) additionally checks `$result->hard` and RE-RAISES it as a
`RuntimeException`, which the run records as that step's failure — the run stops there, joining
the field's existing hard-fail doctrine (e.g. a blank `create_task.title`). The executor itself
still NEVER throws — the hard signal is a return value read by exactly one call site.

**Frontend mirror**: `standardOperationsCatalog()` declares the same 5 ids with the same nominal
input/output the backend catalog advertises (labels only — the FE does not special-case presence
semantics, it renders the op like any other); `date_format`'s `pattern` arg carries a persistent
`hint` (`VariablePipelineEditor.vue`) showing the safe-token legend under the field.

**A known asymmetry, inert this phase (Phase 2 item)**: `WorkflowConditionTreeValidator::walkPipeline`
— the write-time type-flow gate for a value-or-variable pipeline (`priority`/`deadline`/
`submissions_from`/`submissions_to`) — was not changed this phase and still requires an EXACT
`op->inputType() === currentType` match at every step, including for the 4 presence ops (nominally
`text`), where the runtime executor above already bypasses that exact check. See "Accepted
residual risks" below and ADR-0022 for the full reasoning.

---

## Steps

Steps run **in the order they are stored**, each acting only through an existing domain service
(`TaskService`, `FormReportService`) — never a raw model write that bypasses business logic. A
step's output is merged into `context.steps.<key>` so LATER steps can reference it via
`{{steps.<key>.*}}` / the directive/union serializations above.

**First failure stops the run.** Steps commit independently (no all-or-nothing transaction
around the whole run): a workflow whose first step created a task and whose second step failed
leaves the task in place, and the run timeline shows exactly where it stopped.

**Runtime capability per field (SB1/SB2 — see "Runtime operations, if-blocks, and AI text"
above).** All four text fields — `title` / `name` / `description` / `guidelines` — resolve
DIRECTIVE PIPELINES, `if-block`s, AND `@[ai-text]` identically (the resolver treats every text
field the same; which of these an author can actually insert is purely a frontend editor
decision — see the "Where each is enabled" note above and `docs/next/workflows-uxui-spec.md`
§4.6.3 SF3.6, which now offers the full toolbar on `title`/`name` too). `priority` /
`deadline` / `submissions_from` / `submissions_to` (the `{kind}` union fields) resolve an
OPTIONAL, WRITE-VALIDATED pipeline on their `variable` arm (type-flowed to the field's own
accepted terminal — see the Write-time validation subsection above).

### `create_task`

Creates a task through `TaskService::create()` (so label-attach and bot-dispatch side effects
fire as they would for a user-created task). Starts in `TO_DO`. Reaches ENTITY-FORM PARITY with
the create-task form (attachments excluded):

| Config field             | Required | Type            | Failure mode                                                             |
|----------------------------|----------|-------------------|--------------------------------------------------------------------------|
| `title`                      | **yes**  | resolved string     | **HARD** — blank after resolution fails the WHOLE step (`RuntimeException`; run stops here). Clamped to 255 chars (the `tasks.title` column width) AFTER resolution — see the note below. |
| `description`                 | no       | resolved string       | n/a — absent/blank → `null`.                                             |
| `priority`                     | no       | `{kind}` union, ENUM   | **SOFT** — unresolved/unknown → defaults to `medium`.                    |
| `deadline`                       | no       | `{kind}` union, DATE     | **SOFT** — unresolved/unparseable/blank → `null`.                        |
| `labels`                          | no       | literal array of uuids     | **SOFT** — a foreign/unknown label id is silently ignored by `TaskService`'s attach (never throws); a stray "ghost" label id from a deleted label is simply dropped. |
| `assignee_type` / `assignee_id`     | no, both-or-neither | literal `'user'\|'bot'` + uuid | a partial pair (only one present) is treated as no assignee.        |
| `form_id`                              | no       | literal uuid                | optional, no existence guard beyond the write-time `ScopedExists` check. |
| `approval_pipeline_id`                   | no       | literal uuid              | optional, same as above.                                                 |

**Output**: `{ task_id, title }`.

**Reviewer fix — the resolved title is clamped to the column width, not just checked non-empty.**
`title` is unbounded at write time (the raw config may contain a directive/pipeline/`@[ai-text]`
whose RESOLVED length is unknowable before it actually runs), so `CreateTaskStep` clamps the
resolved string to 255 chars (`mb_substr`, multibyte-safe) AFTER resolution, right before the
non-empty check — without this, a long composed value (a verbose AI-generated sentence, a wide
pipeline concatenation) could overflow the `tasks.title` column and fail the step with a raw SQL
error instead of a clean, expected outcome.

**Hard vs. soft failure table** (the exact behavior a stale/bad reference produces):

| Field         | Bad resolution → | Behavior      |
|----------------|--------------------|-----------------|
| `title`          | blank string          | **HARD FAIL** — whole step fails, run stops. |
| `priority`         | unresolved/unknown        | soft-default `medium`.                    |
| `deadline`           | unresolved/unparseable/blank | soft-default `null`.                   |
| `labels` (ghost ids)   | id no longer exists        | silently ignored/dropped, no error.       |

A bot assignee goes through `TaskService`'s normal `maybeDispatch()` — only an execution-capable
bot on a `TO_DO` task starts a bot run.

### `create_form_report`

Creates a Form REPORT through `FormReportService::create()` — the SAME path
`StoreFormReportRequest`'s controller uses. Creating the report fires its `created` model event,
which dispatches `CreateFormReportJob` (`implements ShouldQueue`). The step is
**FIRE-AND-FORGET**: it returns as soon as the row is created and NEVER waits for the AI
analysis/report completion (matching the interactive manual create-report behavior).

| Config field           | Required | Type                      | Notes                                                                 |
|--------------------------|----------|------------------------------|----------------------------------------------------------------------|
| `form_id`                  | **yes**  | literal uuid                   | tenant-scoped (`Form::find`, `WorkspaceScope` applies). A missing/foreign/disabled form **HARD-FAILS** the step. |
| `name`                       | **yes**  | resolved string                  | **HARD** — blank after resolution fails the step. Clamped to 255 chars (the `form_reports.name` column width) after resolution, same reviewer fix as `create_task.title` above. |
| `guidelines`                   | no       | resolved string                    | absent/blank → `null`.                                                 |
| `sources`                        | no       | literal subset of `['task','form']`  | absent/empty → `[]` (no source filter — every source analysed).       |
| `submissions_from`                 | no       | `{kind}` union, DATE                   | **defaults from the STEP, not a request** — the form's `enabled_at` date, or today if `enabled_at` is null. |
| `submissions_to`                     | no       | `{kind}` union, DATE                     | defaults to today.                                                    |

**Output**: `{ report_id, report_name }`.

**Window default semantics — this lives IN the step, mirroring the request's own defaults but
computed independently at run time** (not copied from the trigger payload): `submissions_from`
defaults to `form.enabled_at` (as `Y-m-d`) when the config key is absent, or `now()` if
`enabled_at` is null; `submissions_to` defaults to `now()` (as `Y-m-d`) when absent. An
explicitly-supplied-but-unresolvable/blank value ALSO falls back to the same default (never
leaves the DTO's non-nullable window field empty).

`sources` here is `['task','form']` — the ANALYSIS-SOURCE vocabulary (which submissions count
toward the report), **NOT** the same vocabulary as the trigger's `source.in` (`['manual','task']`,
the SUBMITTABLE-morph vocabulary) — the two `task`/`form`-ish enums look similar but answer
different questions; do not conflate them.

**Creator attribution (corrected — see ADR-0015).** `FormReport` uses the polymorphic `HasCreator`.
Because this step runs INSIDE a live `WorkflowRunContext` (`WorkflowStepRunner` publishes the
executing run around the whole step loop), the report is attributed to the **run itself** —
`creator_type='workflow_run', creator_id=run->id` — for EVERY origin (event, schedule, AND manual),
whether or not an HTTP user happens to be authenticated in that process. `creator_id` is never
`null` for a step-created report. The task created by `CreateTaskStep` is attributed the same way.
This SUPERSEDES the previous text on this page, which described `FormReport` inheriting
`auth()->id()` (null for a schedule run) — that was the actual crash `HasCreator`'s polymorphic
stamping was built to fix (a `NOT NULL creator_id` column, `auth()->id()` always null inside a
queued job). See `docs/backend/creator-attribution.md` for the full stamping-precedence contract.

---

## Worked create-payload examples (verified wire shapes)

These payloads are the EXACT wire the frontend's `WorkflowEditorDrawer` POSTs, pinned by
`resources/js/next/pages/workflows/__tests__/WorkflowEditorDrawer.spec.ts` (`SAVE payload —
form_submitted wire` / `SAVE payload — schedule wire`).

### `form_submitted`

```json
{
  "name": "My workflow",
  "description": null,
  "icon": null,
  "trigger_type": "form_submitted",
  "trigger_config": { "form_id": "form-a", "source": { "in": ["manual"] }, "anonymous": true },
  "conditions": [
    { "field": "fields.status", "field_type": "enum", "operator": "is", "value": "a" }
  ],
  "steps": [
    { "type": "create_task", "key": "task", "config": { "title": "Do it" } }
  ]
}
```

### `schedule`

```json
{
  "name": "My workflow",
  "description": null,
  "icon": null,
  "trigger_type": "schedule",
  "trigger_config": { "schedule": { "time": { "mode": "at", "at": ["09:00"] } } },
  "steps": [
    { "type": "create_task", "key": "task", "config": { "title": "Do it" } }
  ]
}
```

Note the **absence of the `conditions` key entirely** on a schedule payload (not an empty array
— the key is omitted) — conditions are structurally only meaningful for `form_submitted`.

---

## Manual runs — target contract summary

| Trigger type       | `target_id` resolves to…  | Required? | 422 bag on failure                    |
|-----------------------|------------------------------|-----------|------------------------------------------|
| `form_submitted`        | a `FormSubmission`             | yes         | `target_id` (unresolvable) or `workflow` (cap) |
| `schedule`                 | none (omit `target_id`)          | no           | `workflow` (cap) only                      |

The 422 error bag is **EXACTLY** `target_id` + `workflow` — no third key. (Etap-5's
`approval_process` key does not exist in the 5.1 contract; the `approval_finished` trigger it
belonged to was removed.)

---

## Trigger dispatch pipeline

Every real domain trigger and the manual-run endpoint funnel through **one** seam,
`WorkflowDispatchService`. The pipeline runs cheapest gates first:

```
1. TRIGGER-TYPE MATCH   Workflow::query()->where(status=active, trigger_type=$type)
                         (tenant scope automatic; schedule workflows structurally excluded —
                          dispatch() hard-refuses WorkflowTriggerType::SCHEDULE up front)
2. TARGETING            the inline form_submitted match (form_id / source / anonymous) — cheap
                         in-memory check on the payload snapshot, no extra queries
3. CONDITIONS           WorkflowConditionEvaluator — the typed {field, field_type, operator,
                         value} AND-gate
4. LOOP / COST GATES     depth (re-trigger chain) → per-workflow monthly cap → workspace hard cap
                         An event that trips ANY of these is skipped SILENTLY (Log::info only).
5. WorkflowRunManager::start()   creates the `pending` run row and defers the job dispatch
```

The manual-run endpoint (`WorkflowManualRunService` + `dispatchManual()`) BYPASSES steps 1–3
(it targets ONE explicit workflow, resolved id, no targeting/conditions gate — a manual run is
a deliberate, explicit execution) but still enforces the cap gate (step 4), as a **422** instead
of a silent skip.

### Observer / service seam hook points

Each domain write defers its trigger dispatch to `DB::afterCommit()` — ONE `afterCommit` layer
per authoring write, so the dispatch only runs after the write that produced the payload has
actually committed:

| Domain event                          | Hook                                                       |
|------------------------------------------|----------------------------------------------------------------|
| Form submission approved                  | `App\Modules\Forms\Observers\FormSubmissionObserver::saved()` (fires on `wasChanged('approved_at')` OR a freshly-created row with `approved_at` already set) |

The Etap-5 task-created / task-status-changed / approval-concluded hooks (`TaskObserver`,
`ApprovalService::dispatchWorkflowTrigger()`) were REMOVED along with their trigger types. Any
reference to them is stale.

### `DB::afterCommit` layering (single layer, not double-deferred)

The hook above wraps its `dispatch()` call in `DB::afterCommit`, so `dispatch()` runs only after
the authoring transaction commits. `WorkflowRunManager::start()` ALSO wraps its job dispatch in
`DB::afterCommit` — but by the time `dispatch()` executes (already post-commit) there is no open
transaction, so that inner `afterCommit` fires **immediately** (Laravel runs a callback
synchronously when no transaction is active). The run ROW is created synchronously inside
`dispatch()`; only the JOB dispatch is (harmlessly) re-wrapped. There is no double deferral and
no risk of the job running before the run row exists.

### Origin (`WorkflowRunOrigin`)

| Value       | Set by…                                                              |
|---------------|----------------------------------------------------------------------|
| `event`        | `WorkflowDispatchService::dispatch()` (a real domain event matched). |
| `schedule`      | `RunScheduledWorkflowsCommand` (the sweep — see below).             |
| `manual`         | `WorkflowManualRunService` / `dispatchManual()`.                    |

`origin` is the **authoritative** signal for how a run began — **not** `creator_id`.
`WorkflowRunManager::start()` stamps the run row's OWN `creator_id`/`creator_type` EXPLICITLY
(bypassing `HasCreator`'s generic `saving`-hook inference, so a child re-trigger run is never
accidentally stamped onto its parent's `WorkflowRunContext`): the acting user for a MANUAL run,
or — for an engine-started run (event/schedule) — the WORKFLOW'S OWN AUTHOR
(`creator_id => $creatorId ?? $workflow->creator_id`, `creator_type => 'user'` always). **`creator_id`
is therefore never `null` on ANY run**, including a schedule-sweep run started outside a request
(real cron) — this corrects the previously-documented behavior on this page (the old `HasCreator`
fallback to `auth()->id()` did leave a schedule run's `creator_id` null; that gap is closed — see
ADR-0015 §5). Regardless: **read `origin`, never infer engine-vs-manual from `creator_id`** — an
EVENT run fired inside an authenticated HTTP request still carries the workflow's author (which
may or may not be the same person triggering the event) as `creator_id`, not "whoever caused the
event."

---

## Loop protection

A workflow step can author a change (create a task) that itself matches ANOTHER workflow's
trigger — or, since `create_form_report` creates a `FormReport` (not a `FormSubmission`), a
step's `create_task` output could in principle feed a downstream `form_submitted` workflow only
if that task's form is later itself submitted (not automatic). Two independent mechanisms bound
runaway re-triggering regardless of the exact chain shape:

### Origin-tagging + depth (`WorkflowRunContext`)

`WorkflowRunContext` is an in-process singleton holder for the run **currently executing on
this worker**. `WorkflowStepRunner::run()` sets it for the duration of a run and clears it in a
`finally` block. When `WorkflowDispatchService::maybeStart()` computes a new run's depth/origin:

- If a run IS currently executing on this worker (a step authored the re-triggering change),
  the new run is that run's **child**: `depth = parent.depth + 1`, `origin_run_id = parent.id`.
- Otherwise it is top-level: `depth = 0`, `origin_run_id = null`.

`config('workflows.max_depth')` (default 3, env `WORKFLOWS_MAX_DEPTH`) bounds the chain: a
would-be child run whose `depth` would exceed this is refused (`Log::info`, no run created) —
so a workflow that keeps re-triggering itself (or another workflow) cannot loop unbounded.

A manual run and a schedule-sweep run are ALWAYS top-level (`depth = 0`, no `origin_run_id`) —
loop protection only concerns chains authored BY a running workflow's own steps.

**The loop-surface note (now dormant, kept for the mechanism's sake):** with the 5.1 step set
(`create_task`, `create_form_report`), a step no longer directly authors an approval conclusion
or a task-status transition the way the Etap-5 `assign_bot`/`start_approval` steps could — so
the most direct re-trigger loops from Etap-5 are structurally gone. The depth/origin machinery is
kept exactly as-is (not simplified away) because it remains the correct general safety net for
ANY future step type that authors a change able to feed back into a trigger, and because a
`create_task` step's created task can still itself be submitted through a form later, indirectly
reaching a `form_submitted` trigger.

### Cost limits (`config/workflows.php`)

A workflow **run** is the unit of cost (the same doctrine as `config/ai.php`'s per-task bot-run
cap): rather than metering tasks created / reports created separately, the NUMBER OF RUNS stands
in as the cost proxy.

| Config key             | Env var                        | Default | Meaning                                                                |
|---------------------------|-------------------------------------|---------|--------------------------------------------------------------------------|
| `max_runs_per_month`       | `WORKFLOWS_MAX_RUNS_PER_MONTH`       | 100     | SOFT per-workflow monthly budget. `WorkflowRunManager::runsThisMonth($workflow)`. |
| `max_runs_hard_cap`         | `WORKFLOWS_MAX_RUNS_HARD_CAP`         | 500     | ABSOLUTE **workspace-wide** monthly ceiling across ALL workflows, **including manual runs**. `runsThisMonthAcrossWorkspace()`. |
| `run_timeout`               | `WORKFLOWS_RUN_TIMEOUT`               | 900 (s) | Stale-claim reaper threshold — see Run lifecycle below.                 |
| `max_depth`                  | `WORKFLOWS_MAX_DEPTH`                 | 3       | Re-trigger depth guard — see above.                                     |
| `assist_rate_per_minute`       | `WORKFLOWS_ASSIST_RATE_PER_MINUTE`      | 5       | AI schedule-assist per-user throttle — a SEPARATE meter, not counted against the run budget. |
| `ai_text_max_calls_per_run`      | `WORKFLOWS_AI_TEXT_MAX_CALLS_PER_RUN`     | 10      | `@[ai-text]` calls allowed within ONE run (SB2) — a PER-RUN budget, not per-workflow/month. Occurrences beyond the cap resolve to `''`, no call spent. |
| `ai_text_max_chars`               | `WORKFLOWS_AI_TEXT_MAX_CHARS`               | 2000    | Length cap applied to each generated `@[ai-text]` string (SB2), multibyte-safe truncation. The target field's own DB limit still applies on top. |

**Either run cap being reached refuses a new run** (`WorkflowDispatchService::capReached()`,
shared by the event pipeline, the schedule sweep, and the manual endpoint's 422 check — one
method, so all three paths enforce the identical ceiling). Behavior differs by how the run was
ABOUT to start:

| Path              | At-cap behavior                                                                  |
|---------------------|---------------------------------------------------------------------------------------|
| Event trigger        | Skipped **silently** (`Log::info`), never surfaced to the user who triggered the underlying domain action (e.g. approving a submission never fails because a workflow it might have triggered is over budget). |
| Schedule sweep         | Skipped, but the due SLOT IS STILL CONSUMED (see the Schedule section's "slot-consumed" doctrine) — a capped workflow does not backlog-fire every missed slot once the cap later lifts. |
| Manual run               | Refused with a **422** on the `workflow` key (see the manual-run endpoint above) — a manual run is a deliberate action, so silent skipping would be confusing; the caller needs to know it did not happen. |

---

## Schedule

`schedule`-triggered workflows are driven entirely by `WorkflowScheduleService` (the single
source of truth for "what is this workflow's next fire time") plus the
`workflows:run-scheduled` console sweep. **This revision replaces the closed 12/16-family
vocabulary (ADR-0009 §3, ADR-0010) with a COMPOSITIONAL descriptor v2** — see ADR-0012 for the
full design record. Instead of picking one of N named presets, a schedule is now THREE
INDEPENDENT axes combined with AND — `time` (WHEN in the day, required), `day` (WHICH day,
optional) and `month` (WHICH month, optional) — plus an optional `exclusions` skip filter and an
optional `tz`. A fire happens only when time AND day AND month all match, minus any exclusion:

```
{ "time": { "mode": "…", … }, "day"?: { "mode": "…", … }, "month"?: { "mode": "…", … },
  "exclusions"?: { "months"?: [...], "weekdays"?: [...], "dates"?: [...] }, "tz"?: "…" }
```

Every field is validated in ONE place, `WorkflowScheduleRulesValidator` (shared verbatim by the
write path, the AI-assist re-validation, and the preview endpoint), and compiled in ONE place,
`WorkflowScheduleCompiler`. All numeric bounds live in `App\Modules\Workflows\Enums\ScheduleLimits`
— the single contract the frontend mirrors as a TypeScript constant. Validation errors are keyed
`trigger_config.schedule.{time,day,month,exclusions,tz}.*` (write path) or `schedule.*` (the
AI-assist's standalone re-check).

### TIME axis (`schedule.time`) — the only required axis

| `time.mode` | Fields | Limits | Semantics |
|---|---|---|---|
| `at` | `at`: string[] of `'HH:mm'` | 1–6 distinct entries | Fires at EACH listed wall-clock time (the union across the list) — e.g. `at:["08:00","17:00"]` fires twice a day. |
| `every_minutes` | `minutes`: int; optional `from`/`to`: `'HH:mm'` | `minutes` 1–59 | A WALL-CLOCK minute grid (`:00,:15,:30,:45` for `minutes:15`) — NOT an activation-phased interval. The optional window bounds it to part of the day (both-or-neither, `from` strictly before `to`). |
| `every_hours` | `hours`: int; `minute`: int (optional, default 0); optional `from`/`to`: int hour | `hours` 1–23, `minute` 0–59, window bounds 0–23 | An every-N-hours grid at `:minute` past the hour, MODULO N within the day (resets at midnight — the gap across midnight can be shorter than `hours`). The optional window bounds it to a whole-hour range. |

### DAY axis (`schedule.day`) — optional, defaults to `every_day`

| `day.mode` | Fields | Limits | Semantics |
|---|---|---|---|
| `every_day` | — | — | No day restriction (the default). |
| `every_n_days` | `n`: int; optional `from`/`to`: int day-of-month | `n` 1–31, window bounds 1–31 | A day-of-month step. The optional window bounds it to a day-of-month range. |
| `weekdays` | `weekdays`: int[] | 1–7 distinct, each 0–6 (`0`=Sunday) | A SET of weekdays — several days per week in ONE schedule (e.g. Mon+Wed+Fri → `[1,3,5]`). |
| `month_days` | `days`: int[] | 1–31 distinct, each 1–31 | A SET of calendar days. A day a given month lacks (31 in February) SKIPS that month's fire — never clamped. Use `special:last_day` for a guaranteed month-end fire. |
| `special` | `special`: one of the rules below, + that rule's own params | — | A month-anchored day a plain set cannot express. |

**`day.special` rules** (`ScheduleDaySpecial`):

| `special` | Extra params | Semantics |
|---|---|---|
| `last_day` | — | The last calendar day of the month. |
| `nth_weekday` | `ordinal` (1–5), `weekday` (0–6) | The ordinal-th occurrence of a weekday in the month (e.g. ordinal=1, weekday=1 → "first Monday"). `ordinal=5` SKIPS any month with only four occurrences of that weekday — not clamped to the fourth. |
| `last_weekday` | `weekday` (0–6) | The LAST occurrence of a weekday in the month (e.g. weekday=5 → "last Friday") — a GUARANTEED monthly fire, unlike `nth_weekday` ordinal=5. |
| `last_working_day` | — | The last Mon–Fri of the month. **BESPOKE** — see the note below — and therefore **REQUIRES `time.mode = at`** (a dedicated 422 on `time.mode` otherwise); it fires at explicit `HH:mm` times, never on a minute/hour grid. Does **NOT** account for public holidays — a month whose last weekday is a holiday still fires that day. |

**Why `last_working_day` is bespoke, not compiled to a cron token.** The
`dragonmantank/cron-expression` v3.6.0 `LW` token does NOT mean "last working day of the month" —
it parses the `L` in `LW` as day `0`, which normalizes to the PREVIOUS month and returns
wrong/garbage dates (verified directly against the installed version, not assumed from
documentation). "Last working day" also cannot be expressed as any single standard cron
expression (it is the LATEST of {last Mon, …, last Fri}, not a fixed day-of-month/day-of-week
rule). `WorkflowScheduleService` therefore computes it directly: start at the month's last
calendar day and step backward over Saturday/Sunday, resolved as a local wall-clock time in the
schedule's tz then converted to UTC — the same wall-clock-then-convert pattern every other axis
follows. It carries the `time.at` list (one or more `HH:mm` fire times) and the set of months the
`month` axis allows, so it composes with a month restriction (e.g. "last working day of every
quarter-end month"). See ADR-0012 for the full record.

### MONTH axis (`schedule.month`) — optional, defaults to `every_month`

| `month.mode` | Fields | Limits | Semantics |
|---|---|---|---|
| `every_month` | — | — | No month restriction (the default). |
| `every_n_months` | `n`: int; optional `from`/`to`: int month | `n` 1–12, window bounds 1–12 | A **JANUARY-ANCHORED** month step (`1, 1+n, 1+2n, … ≤ 12`), MODULO the year — mirrors `every_hours`' hour-of-day-modulo-N doctrine. `n=5` gives {1,6,11} (Jan/Jun/Nov), then resets in January — the gap across the year boundary (Nov→Jan, 2 months) can be SHORTER than `n`. The optional window narrows the grid to a month range (e.g. `n:3, from:3, to:11` → March/June/September). |
| `months` | `months`: int[] | 1–12 distinct, each 1–12 | A SET of months (1=January). |

### Windows (`from`/`to`) — the shared bounded-range pattern

Four axis fields carry an OPTIONAL window: `time.every_minutes` (`'HH:mm'` pair), `time.every_hours`
(int hour 0–23 pair), `day.every_n_days` (int day-of-month 1–31 pair), and `month.every_n_months`
(int month 1–12 pair). Every window is **BOTH-OR-NEITHER** (supplying only `from` or only `to` is
a 422) and **`from` strictly less than `to`** — a window never wraps midnight (time) or the year
end (month). Omitting the window entirely means "unbounded" (the full grid, all day / all hours /
all months).

### Composition semantics

The three axes are INDEPENDENT and AND-combined: `day` fills EITHER the compiled day-of-month OR
day-of-week field (never both at once, so the classic cron dom/dow OR-trap cannot arise), and
`month` fills the month field — every `time` fire moment carries the SAME day/month restriction.
`exclusions` and `tz` never reach the compiled cadence grammar: exclusions are applied as a
POST-FILTER (below) and `tz` only resolves wall-clock fields before converting to UTC.

**Compiled form (implementation detail).** `WorkflowScheduleCompiler` turns a validated descriptor
into either a NON-EMPTY LIST of `dragonmantank/cron-expression` strings (one per `time.at` entry,
or up to 3 for a minute window split across an hour boundary — `WorkflowScheduleService` takes the
earliest strictly-after candidate across the whole list) or, for `day.special:last_working_day`,
the bespoke non-cron form described above. This is an implementation detail the FE never needs —
the wire contract is always the `{ time, day, month }` descriptor, never a cron string.

### Exclusions (`schedule.exclusions`)

An optional post-filter, `{ months?, weekdays?, dates? }`, evaluated in the SCHEDULE's own
timezone AFTER a candidate fire time is computed — never inside the compiled cadence itself:

| Key         | Shape                                          | Effect                                                      |
|---------------|--------------------------------------------------|------------------------------------------------------------------|
| `months`        | int array, values 1–12, max 11 entries, distinct   | drop a candidate whose LOCAL month is in this list.               |
| `weekdays`         | int array, values 0–6 (0=Sunday), max 6 entries, distinct | drop a candidate whose LOCAL day-of-week is in this list.  |
| `dates`               | `'Y-m-d'` string array, max 50 entries, distinct        | drop a candidate whose LOCAL date exactly matches an entry.  |

A candidate is dropped when its month **OR** its weekday **OR** its date matches (any one clause
is enough — the clauses are OR-combined against the SAME candidate, not independently applied
cadences). `months` is capped at 11 and `weekdays` at 6 so an exclusion list can never rule out
EVERY possible value of that dimension by itself — but a combination (or `dates`) can still leave
a schedule with no reachable occurrence at all; see "the empty-schedule guard" below.

```json
{ "time": { "mode": "at", "at": ["09:00"] }, "exclusions": { "weekdays": [0, 6] } }
```

The filter is a LOOP inside `WorkflowScheduleService::nextDueAt()`: compute the next union
candidate, and if it is excluded, advance the cursor past it and recompute — for EVERY compiled
form, including the bespoke `last_working_day` cadence ("every day except weekends" really does
skip the whole weekend, not just the boundary instant). Two HARD LIMITS bound the loop so an
unreachable schedule can never hang: at most **1000 iterations**, and a **10-year horizon** from
the search's start instant — exceeding either returns `null` (treated as "no occurrence").

**The empty-schedule guard.** After every structural rule passes, `WorkflowScheduleRulesValidator`
computes the schedule's actual first occurrence (reusing `WorkflowScheduleService::nextOccurrences`)
and rejects the write with a 422 on `trigger_config.schedule.exclusions` if the cadence has NO
reachable occurrence (e.g. `day.weekdays:[1]` whose `exclusions.weekdays` also excludes Monday) —
an unfireable schedule must never persist. **This guard is OPTIONAL**, controlled by the
validator's `checkEmpty` parameter: the write path (`StoreWorkflowRequest`/`UpdateWorkflowRequest`)
and the AI-assist re-validation keep it ON; the live `schedule-preview` endpoint turns it OFF, so
an over-constrained draft comes back as `{ empty: true }` DATA for a pre-save warning instead of a
422 while the user is still mid-edit. Every STRUCTURAL check (unknown mode, out-of-bounds field,
malformed window/exclusions) still runs and still 422s on preview.

### Timezone

`schedule.tz` defaults to `config('app.timezone')` (UTC). Every axis is now a WALL-CLOCK cadence
(there is no phase-from-activation interval left in v2), so every axis resolves **IN** the
schedule's own timezone — via the compiled cron form for a normal descriptor, or the bespoke
calculation for `last_working_day` — then converts to UTC for storage; a "09:00 Europe/Warsaw"
schedule fires at the correct UTC instant year-round (07:00 UTC in winter CET, 06:00 UTC in summer
CEST). `exclusions` are evaluated against the candidate RE-EXPRESSED in this same tz (an exclusion
is a wall-clock-day concept, like the rest of the cadence).

Every computed fire time is **strictly after** the `$from` instant it was computed from
(never equal), and always stored/returned in UTC.

### DST behavior (spring-forward AND fall-back, both pinned by unit tests)

**Spring-forward gap**: during a spring-forward gap the requested local wall-clock time does not
exist (e.g. 02:30 on the changeover night, Europe/Warsaw 2026-03-29 02:00→03:00). The cron
library shifts the non-existent local time FORWARD past the gap (e.g. that day's fire resolves to
03:30 local instead of crashing or looping) — one skewed fire is the accepted trade-off for a
rare boundary; the following day returns to the configured time.

**Fall-back overlap**: during a fall-back the local wall-clock hour repeats (Europe/Warsaw
2026-10-25 03:00→02:00, so local 02:00–03:00 happens TWICE that night). A wall-clock schedule at
`02:30` therefore fires **TWICE** that calendar night, at two DISTINCT UTC instants: `00:30 UTC`
(02:30 CEST, +02:00 — the first pass, before the clock rolls back) then `01:30 UTC` (02:30 CET,
+01:00 — the second pass, after). The strictly-after invariant is what makes this safe: because
the two local 02:30s are different UTC instants, advancing the cursor from the first to the
second is genuine forward progress — the SAME UTC moment is never fired twice, and the following
day returns to a single 02:30 (01:30 UTC). This is the cron library's observed resolution and is
**PINNED as accepted behavior**, not something the module works around — see
`tests/Unit/Workflows/WorkflowScheduleServiceTest.php::test_fall_back_fires_both_local_0230_instances`.

### Legacy read-shim (`LegacyScheduleUpgrader`) — no data migration was run

A schedule stored BEFORE this revision in the pre-v2 `{ family, params }` shape (or the earlier
Etap-5 preset shape) is upgraded to v2 TRANSPARENTLY at every read/compile boundary —
`WorkflowResource` (so the FE editor always seeds from the v2 shape), `WorkflowScheduleService` /
`WorkflowScheduleCompiler` (so an old row keeps firing), and the AI-assist re-validation (so a
model that still answers in the old vocabulary is judged fairly). Detection is by the presence of
a `family` key (v2 blocks never carry one); a v2 block is returned VERBATIM (the shim is
idempotent), and an unknown/garbage family is returned unchanged too — it then fails v2 validation
honestly on its missing `time`, exactly like any malformed block. `exclusions`/`tz` pass through
unchanged in both shapes. **No data migration or backfill was run** — every previously stored
schedule keeps working through this shim; only a NEW write must use the v2 shape (the write path
does not accept `{ family, params }` at all — see the BREAKING note at the top of this document).
See `LegacyScheduleUpgrader`'s docblock for the complete family→axis mapping table.

### Live preview — `POST /workflows/meta/schedule-preview`

See the endpoint section above for the full request/response contract. In short: it is the ONLY
place occurrence dates are computed for the frontend (the FE never re-implements cadence math
locally); it validates with the empty-guard OFF (`empty: true` instead of a 422); it accepts an
optional `anchor` to centre the projection (the prev-or-at occurrence first, then ascending) so a
"jump to date" / paging UI needs no separate cursor field; and `approximate` is now ALWAYS
`false` (every v2 cadence is a wall-clock grid — there is no activation-phased interval left to
be indicative about).

### `workflows:run-scheduled` sweep

Scheduled `everyMinute()` + `withoutOverlapping()` in `routes/console.php`. This is the **ONLY**
path that starts a schedule run (the event dispatcher hard-refuses `SCHEDULE` by design).
Requires the app's `schedule:run` entry to actually be on cron/supervisor — like any Laravel
scheduled command, nothing fires without that. See Ops notes.

**Per-candidate race-safe compare-and-swap (`WorkflowScheduleService::claimDue`)**:

```sql
UPDATE workflows
SET next_due_at = <recomputed next fire time>, last_scheduled_run_at = now()
WHERE id = ? AND next_due_at = <the value the sweep just read> AND status = 'active'
```

Postgres row-locks the UPDATE, so exactly one concurrent sweep wins (`affected = 1`) and any
rival that already advanced the slot loses (`affected = 0` → skip). This is the **second**
concurrency guard beyond `withoutOverlapping` — even two overlapping sweep processes fire a due
workflow at most once. The WHERE compares the RAW stored `next_due_at` (`getRawOriginal`), so
it is a byte-for-byte match against what the DB holds, with no cast/precision mismatch.

**SLOT-CONSUMED doctrine**: the slot advances the MOMENT the CAS is won — **before** the cap
check. A workflow over its run budget therefore consumes and silently skips its due slot rather
than backlog-firing every missed slot once the cap later lifts (matches the event dispatcher's
silent-skip behavior for the same reason: caps are a spend ceiling, not a queue to drain later).

### Arming lifecycle (`WorkflowScheduleService::arm`)

`next_due_at` is armed (given a concrete future value) or nulled (cleared) at every write that
could affect it — never computed lazily elsewhere:

| Trigger                                     | Effect                                                                    |
|------------------------------------------------|--------------------------------------------------------------------------------|
| Create                                          | Always inactive → `next_due_at` stays `null` (never armed on create).         |
| `PATCH .../status` → `active` on a schedule workflow | Arms `next_due_at` from the cadence.                                     |
| `PATCH .../status` → `inactive`                    | Nulls `next_due_at`.                                                      |
| `PUT` (update) — cadence changed on an ACTIVE schedule workflow | Re-arms `next_due_at` from the NEW cadence.                       |
| `PUT` (update) — any other workflow                  | `arm()` no-ops to `null` (not armable: not schedule, or not active).      |

**Self-healing**: the sweep's `armOrphans()` step ARMS (does not fire) any ACTIVE schedule
workflow found with a `NULL next_due_at` (a legacy/edge state — e.g. one activated before this
mechanism existed). It only sets the first due time; it does not fire on that same pass.

---

## Run lifecycle

### Atomic claim (`WorkflowRunManager::claim`)

```sql
UPDATE workflow_runs
SET state = 'running', started_at = now()
WHERE id = ? AND state = 'pending'
```

A single conditional UPDATE, row-locked by Postgres, so exactly one concurrent worker can flip
`pending → running` (`affected = 1` = won the claim). A LOST claim (already running or
terminal) is a silent no-op in `WorkflowRunJob::handle()` — race-safe even under a non-sync
queue. `started_at` is stamped in the SAME statement, so the reaper can distinguish a
genuinely-stuck run from a slow-but-alive one.

### `$tries = 1` + `failed()` release, guarded by `isTerminal()`

`WorkflowRunJob::tries = 1` — no automatic Laravel retry. Two independent release paths exist:

1. `WorkflowStepRunner::run()` catches every step `Throwable` itself and releases the run to
   `failed` with the step's error message — this covers essentially all real failures.
2. `WorkflowRunJob::failed(Throwable $e)` is the LAST-RESORT release: fires on a job-level
   failure (e.g. a timeout, or an exception escaping around `handle()` rather than inside a
   step). It re-checks `$run->state->isTerminal()` first and no-ops if the run is already
   terminal (so it can never "double-release" a run the step runner already finished).

Neither path covers a hard process kill (SIGKILL, OOM, `queue:restart` mid-job) — the job
simply stops with no chance to run `failed()`, stranding the run in `running` forever (`claim()`
only matches `pending`, so nothing else can recover it). That is what the reaper is for.

### `workflows:reap-stale-runs`

Scheduled `everyFiveMinutes()` + `withoutOverlapping()`. Releases runs stuck in `running` past
`config('workflows.run_timeout')` (default 900s) to `failed` with a timeout error. Also reaps
**NULL-started orphans** (rows claimed before the `started_at` column/mechanism existed — a
defensive catch-all, not expected in steady state post-deploy). Must exceed the longest
plausible real run duration, or a slow-but-alive run would be reaped prematurely.

**Per-tenant sweep**: `workflow_runs` lives in the shared database (shared-mode workspaces) AND
in each own-database workspace, so both this command and `workflows:run-scheduled` run once
unscoped on the default connection, then once per own-DB workspace (activating `TenantContext`
+ `TenantManager` for each) — mirrors `ReapStaleBotRunsCommand`.

---

## Tenancy

`Workflow`, `WorkflowRun`, `WorkflowRunStep` all use the `TenantAware` trait — every query is
automatically scoped to the active workspace via `WorkspaceScope`.

| Table                | Central (shared-db) migration                                  | Tenant (own-db) mirror                                          |
|-------------------------|----------------------------------------------------------------------|-----------------------------------------------------------------------|
| `workflows`               | `database/migrations/2026_07_07_000200_create_workflows_table.php` | `database/migrations/tenant/0001_01_01_000025_create_workflows_table.php` |
| `workflow_runs`             | `database/migrations/2026_07_07_000201_create_workflow_runs_table.php` | `database/migrations/tenant/0001_01_01_000026_create_workflow_runs_table.php` |
| `workflow_run_steps`         | `database/migrations/2026_07_07_000202_create_workflow_run_steps_table.php` | `database/migrations/tenant/0001_01_01_000027_create_workflow_run_steps_table.php` |

The tenant (own-db) mirrors omit `workspace_id` (one tenant database = one workspace) and carry
no cross-database foreign keys (`workflow_id`, `creator_id`, `workflow_run_id` are plain UUID
columns, matching the project-wide no-cross-DB-FK convention used by `bot_actions`). Both the
schedule sweep and the stale-run reaper explicitly iterate every own-database workspace in
addition to the shared connection (see above) — a workflow living only in one tenant's own
database would otherwise never be swept.

---

## Ops notes

- **`QUEUE_CONNECTION=sync` runs the report job inline on the sweep/request worker.**
  `create_form_report` fires `CreateFormReportJob` (`ShouldQueue`) fire-and-forget. Under a real
  queue this is genuinely async — the workflow step returns immediately, the AI analysis runs
  later on a queue worker. **Under `QUEUE_CONNECTION=sync` (unsupported in production — see the
  general Taskio ops guidance) the job runs INLINE**, meaning a schedule-sweep or event-dispatch
  worker processing a `create_form_report` step would synchronously perform the full AI report
  analysis before the sweep/dispatch call returns — a real queue is required for this step type
  to behave as documented (fire-and-forget) in production.
- **The schedule-assist throttle (`assist_rate_per_minute`) needs a PERSISTENT cache store.**
  `RateLimiter` reads/writes the DEFAULT cache store. The production default (`database`) is
  fine; the `array` driver resets every process — correct for tests exercising the throttle
  in-process, WRONG for a real multi-worker/multi-request deployment (each process would get its
  own counter, defeating the throttle).
- **`schedule:run` must be on cron/supervisor.** Both `workflows:run-scheduled`
  (`everyMinute()`) and `workflows:reap-stale-runs` (`everyFiveMinutes()`) are registered via
  Laravel's scheduler in `routes/console.php` — like any Laravel scheduled command, NOTHING
  fires unless the environment actually invokes `schedule:run` on a real interval (cron entry or
  a supervisor process). This is unconditional infrastructure, not optional.

---

## Accepted residual risks / known notes

These are documented, reviewed trade-offs — not a TODO list.

- **Bot-authored form submissions are not distinguishable in `source`.** See the `form_submitted`
  trigger-config section and ADR-0009 §5 — `source` is derived purely from the submittable
  morph (`manual`/`task`), which cannot express "who/what filled this in". DEFERRED: would need
  a new column tracking submission authorship.
- **The residual schedule-assist honesty limit.** Re-validation guarantees STRUCTURAL
  validity/compilability of an AI-proposed schedule, never SEMANTIC correctness against the
  user's actual intent — see the `/workflows/schedule-assist` endpoint section above.
- **`day.special:last_working_day` and every other axis/mode ignore public holidays.** A computed
  "last working day" (or any other fire date) that lands on a public holiday still fires
  normally — there is no holiday-calendar concept anywhere in the schedule vocabulary. See
  Planned/deferred below.
- **DST fall-back double-fire is accepted, not a bug.** A wall-clock schedule whose time falls in
  a fall-back-overlap hour (e.g. a daily `02:30` in Europe/Warsaw on the October changeover) fires
  TWICE that calendar night at two distinct UTC instants — see the DST section above. This is the
  cron library's observed resolution, pinned by a unit test, and treated as correct: the
  wall-clock time genuinely occurred twice that night.
- **Stale targeting ids are a safe no-op.** If a workflow's `trigger_config.form_id` references
  a form that later gets deleted, the targeting match simply never matches it again — no error,
  no orphan-cleanup needed. The definition just becomes effectively untargeted until edited.
- **Thundering-herd at popular schedule times** (e.g. many workspaces choosing `09:00 daily`)
  is bounded by the run-budget caps, not eliminated by scheduling jitter. The sweep runs every
  minute and processes all due workflows found in the read, so a large clustered due-set is
  handled in one pass rather than smeared — acceptable at current scale.
- **Sweep commands (`workflows:run-scheduled`, `workflows:reap-stale-runs`) have NO per-tenant
  `try/catch`.** A failure sweeping one own-database workspace's connection currently aborts the
  rest of that command's run for every subsequent workspace in the loop. This is a consistent,
  accepted pattern (matches the existing Bot reaper commands) — hardening (isolating per-tenant
  failures so one bad connection doesn't starve the others) is queued as future work, not done
  in this batch.
- **`creator_id` is non-null on EVERY run, including engine-authored (event/schedule) ones** —
  `WorkflowRunManager::start()` explicitly inherits the workflow's own author for an engine run,
  never leaving it null. This is expected, intentional behavior (see ADR-0015 §5 and the Origin
  section above), not a bug: `origin` remains the authoritative signal for how a run began; never
  infer engine-vs-manual from `creator_id`.
- **The pre-existing `ResolveWorkspace`-after-`SubstituteBindings` middleware ordering** means
  route-model-binding for `{workflow}` / `{run}` resolves BEFORE the tenant/workspace context is
  set (`bootstrap/app.php` appends `ResolveWorkspace` to the `api` group, which already runs
  `SubstituteBindings` earlier in Laravel's default ordering). In practice this is caught by
  `WorkspaceScope` at QUERY time — every list/read in this module is independently
  workspace-scoped, so cross-workspace ROWS never leak into a response
  (`tests/Feature/WorkflowRunReadTest.php::test_run_index_returns_only_active_workspace_runs`
  documents exactly this reliance). This is an **app-wide, pre-existing condition, NOT
  introduced by the Workflows module** — a fix (reordering the middleware, or moving workspace
  resolution earlier) is queued separately as a cross-cutting concern, not scoped to this batch.
- **A `TIME` variable is descriptor-only and not yet conditionable — a deliberate loud tripwire,
  not an oversight (Phase 1a).** `WorkflowVariableType::TIME` has NO condition operators
  (`operatorCases()` = `[]`) and its flat wire `type` still degrades to `text`
  (`WorkflowVariableCatalogService::flatType()`) — the resolver, condition evaluator, and
  operation executor all still dispatch on the ORIGINAL 7-case exhaustive `match` (no `default`
  arm), and the FE mirrors a closed 7-member type union. Letting `time` flow onto the flat wire
  today would trip an `UnhandledMatchError` at runtime or break the FE union; keeping it
  descriptor-only makes the gap loud instead of a landmine for whoever wires up real TIME
  semantics next. See ADR-0022.
- **`WorkflowConditionTreeValidator::walkPipeline`'s type gate does not yet know about the
  presence-op family (Phase 1b).** The write validator for a value-or-variable pipeline
  (`priority`/`deadline`/`submissions_from`/`submissions_to`) still requires an EXACT
  `op->inputType() === currentType` match at every step, including `coalesce`/`is_present`/
  `is_null`/`assert_present` (nominally `text`); the RUNTIME executor already special-cases these
  4 to accept any type (`isPresenceOp()`). Today this is INERT — no shipped field pipeline opens
  with a presence op over a non-text ref, and a markdown directive's pipeline has no write
  validation at all (there is no PHP markdown parser in this codebase), so it is unaffected — but
  it means a presence op is only writable at the START of a value-or-variable pipeline when the
  reference is itself `text`-typed. Relaxing `walkPipeline` to mirror the executor's bypass is
  deferred to Phase 2. See ADR-0022.

---

## Related files

- `app/modules/Workflows/` — module root
- `app/modules/Workflows/Models/Workflow.php`, `WorkflowRun.php`, `WorkflowRunStep.php`
- `app/modules/Workflows/Services/WorkflowService.php` — definition CRUD
- `app/modules/Workflows/Services/WorkflowDispatchService.php` — the event/manual dispatch seam
- `app/modules/Workflows/Services/WorkflowManualRunService.php` — manual-run target resolution + 422s; also owns `retry()` (terminal-FAILED guard + the shared run-budget cap)
- `app/modules/Workflows/Http/Requests/RetryWorkflowRunRequest.php` — retry's authorization + nested-ownership/cross-tenant 404 guards
- `app/modules/Workflows/Http/Controllers/WorkflowRunController.php` — `index`/`global`/`show`/`retry` (run monitoring + retry)
- `app/modules/Workflows/Services/WorkflowRunManager.php` — claim / release / reaper / cap counters
- `app/modules/Workflows/Services/WorkflowRunContext.php` — in-process current-run holder (loop-depth seam)
- `app/modules/Workflows/Services/WorkflowStepRunner.php` — executes a claimed run's steps
- `app/modules/Workflows/Services/WorkflowStepFactory.php` — step-type → implementation
- `app/modules/Workflows/Services/WorkflowConditionEvaluator.php` — typed condition matrix
- `app/modules/Workflows/Services/WorkflowTriggerPayloadFactory.php` — the whitelisted `{{trigger.*}}` payload builder
- `app/modules/Workflows/Services/WorkflowVariableResolver.php` — the directive + `{kind}` union resolver (replaces the Etap-5 flat `ReferenceResolver`)
- `app/modules/Workflows/Services/WorkflowVariableCatalogService.php` — the typed variable/condition catalog
- `app/modules/Workflows/Enums/ScheduleTimeMode.php`, `ScheduleDayMode.php`, `ScheduleMonthMode.php`, `ScheduleDaySpecial.php` — the v2 axis/mode enums
- `app/modules/Workflows/Enums/ScheduleLimits.php` — the ONE place every numeric bound lives (the FE mirrors it verbatim)
- `app/modules/Workflows/Services/WorkflowScheduleService.php` — cadence math + CAS claim + `nextOccurrences()`/`occurrencesFrom()` (the preview + anchor seam) + the `exclusions` post-filter loop
- `app/modules/Workflows/Services/WorkflowScheduleCompiler.php` — the v2 descriptor → cron-list/last-working-day compiler (the one cadence grammar)
- `app/modules/Workflows/Services/CompiledSchedule.php` — the compiled cadence value object (`cron` list / `last_working_day` kinds — no interval kind in v2)
- `app/modules/Workflows/Services/WorkflowScheduleRulesValidator.php` — the ONE schedule-block rule set (write path + AI re-validation + preview, `checkEmpty` toggle)
- `app/modules/Workflows/Services/LegacyScheduleUpgrader.php` — the read-shim upgrading a stored/proposed legacy `{ family, params }` block to v2 (no data migration was run)
- `app/modules/Workflows/Services/WorkflowScheduleAssistService.php` — AI assist orchestration + re-validation gate
- `app/modules/Workflows/Agents/ScheduleAssistAgent.php` — the tool-less natural-language agent, prompt built from the v2 enums/limits
- `app/modules/Workflows/Http/Requests/SchedulePreviewRequest.php`, `Http/Controllers/WorkflowSchedulePreviewController.php` — the live schedule-preview endpoint (incl. the `anchor` param)
- `app/modules/Workflows/Steps/` — the 2 step implementations (`CreateTaskStep`, `CreateFormReportStep`)
- `app/modules/Workflows/Jobs/WorkflowRunJob.php`
- `app/modules/Workflows/Console/RunScheduledWorkflowsCommand.php`
- `app/modules/Workflows/Console/ReapStaleWorkflowRunsCommand.php`
- `app/modules/Workflows/Policies/WorkflowPolicy.php`
- `app/modules/Workflows/routes/api.php`
- `app/modules/Forms/Observers/FormSubmissionObserver.php` — form_submitted hook
- `app/modules/Forms/Services/FormReportService.php`, `Jobs/CreateFormReport.php` — the `create_form_report` step's sink
- `config/workflows.php` — caps, run timeout, max depth, assist throttle
- `routes/console.php` — schedule registration for both console commands
- `tests/Feature/WorkflowCrudTest.php`
- `tests/Feature/WorkflowDispatchTest.php`
- `tests/Feature/WorkflowReferenceContractTest.php` — pins the `{{trigger.*}}` payload paths for `form_submitted`/`schedule` (updated for the 5.1 trigger set)
- `tests/Feature/WorkflowRunEngineTest.php`
- `tests/Feature/WorkflowRunReadTest.php`
- `tests/Feature/WorkflowRunRetryTest.php` — the retry endpoint (new-run wiring, terminal-state 422, budget-cap 422, foreign-workflow/missing/cross-tenant 404s, guest 401, non-member 403)
- `tests/Feature/WorkflowScheduleSweepTest.php`
- `tests/Feature/WorkflowScheduleAssistTest.php` — v2 examples incl. the legacy-proposal read-shim case
- `tests/Feature/WorkflowSchedulePreviewTest.php` — the preview endpoint (empty/`anchor`/prev-or-at semantics, `approximate` always false, checkEmpty-off behavior)
- `tests/Feature/WorkflowStepsTest.php`
- `tests/Feature/WorkflowVariableCatalogTest.php`
- `tests/Unit/Workflows/WorkflowConditionEvaluatorTest.php`
- `tests/Unit/Workflows/WorkflowScheduleServiceTest.php` — includes the DST spring-forward AND fall-back pins, the `exclusions` post-filter loop, `last_working_day`
- `tests/Unit/Workflows/WorkflowScheduleCompilerTest.php` — per-axis compiled-cron assertions, the `last_working_day` month-filter cases
- `tests/Unit/Workflows/WorkflowScheduleLegacyUpgraderTest.php` — the full legacy-family → v2 mapping table
- `tests/Unit/Workflows/WorkflowVariableResolverTest.php`
- `resources/js/next/pages/workflows/__tests__/WorkflowEditorDrawer.spec.ts` — pins the exact create-payload wires reproduced above (incl. the v2 `schedule` wire)
- `docs/decisions/ADR-0012-workflows-schedule-descriptor-v2.md` — this revision's schedule design decisions (compositional descriptor, read-shim, flat anchored preview, AI-modal-with-approval)
- `docs/decisions/ADR-0010-workflows-schedule-rebuild.md` — the 12→16-family batch; §7 (frontend two-mode) is SUPERSEDED by ADR-0012, the rest stands as history
- `docs/decisions/ADR-0009-workflows-rescope-typed-variables.md` — the 5.1 re-scope decisions
- `docs/decisions/ADR-0008-workflows-module-design.md` — run-engine decisions that still hold (superseded sections marked)
- `docs/next/workflows-uxui-spec.md` — the frontend UX/UI specification (REVISION 4 — the v2 compositional builder)
- `app/modules/Workflows/Services/WorkflowOperationExecutor.php` — the shared pipeline engine (72→77 ops; phase-1b's presence family + `date_format`)
- `app/modules/Workflows/DTOs/OperationResult.php` — pipeline outcome, incl. the `hard` flag (phase-1b)
- `app/modules/Workflows/Enums/WorkflowOperation.php` — the 77-op enum (`inputType`/`outputType`/`argDescriptors`/`producesChoice`/`isPresenceOp`)
- `app/modules/Workflows/Enums/WorkflowVariableType.php` — the 8-case type enum incl. `TIME` and `descriptor()` (phase-1a)
- `app/modules/Workflows/Services/WorkflowConditionTreeValidator.php` — the value-pipeline write validator (the `walkPipeline` presence-op asymmetry noted above)
- `tests/Unit/Workflows/WorkflowOperationExecutorTest.php`, `tests/Unit/Workflows/WorkflowVariableResolverTest.php` — phase-1b presence/date-format/default coverage
- `tests/Feature/WorkflowVariableCatalogTest.php` — phase-1a `descriptor` coverage
- `docs/decisions/ADR-0022-workflows-variable-typesystem-phase1.md` — this phase's design record

## Planned / deferred (not implemented)

- **Wait-for-approval resume**: `WorkflowRunState::WAITING` is declared but never produced by
  the MVP engine. There is no longer a `start_approval` step in the 5.1 step set at all, so this
  is even further from being built than at Etap-5 time. See ADR-0008 #11 (superseded context;
  the mechanism note still holds).
- **Manual run cancellation**: `WorkflowRunState::CANCELLED` is declared but no cancel action
  exists yet.
- **Bot-authored submission tracking**: `source` cannot express "a bot filled this form in" —
  see the Accepted residual risks section. Needs a new column, not just morph-derived logic.
- ~~Operations pipeline for the typed variable system~~ — **DONE (SB1/SB2, ADR-0013), no longer
  deferred.** A directive and a value-or-variable reference now both transform their resolved
  value through the shared 68-op `WorkflowOperationExecutor` at run time (string ops, date
  arithmetic, arithmetic, per-option mapping, and — since ADR-0014 — mapping into a destination
  field's fixed choice set); ADR-0009 §2's "deferred" consequence is explicitly reversed. Kept
  struck through so a reader of an older snapshot of this doc understands the change.
- **Per-tenant error isolation in the sweep commands**: see the accepted-risk note above.
- **Public holiday awareness**: `day.special:last_working_day` (and every other axis/mode) has NO
  concept of a public holiday calendar — a computed "last working day" or any other fire date
  that lands on a holiday still fires normally. Would need a holiday-calendar data source (and
  almost certainly a per-workspace/per-locale one), a real scope increase, not a tweak.
- **Rolling intervals, every-N-weeks, one-off dates, and sub-minute cadences**: the v2 vocabulary
  has no rolling interval axis (a cadence phased from an arbitrary start rather than a wall-clock
  grid — e.g. "dokładnie co 90 minut", "co 2,5 godziny"), no "every N weeks" axis, no single
  one-off-date cadence, and no sub-minute grid. All four are named explicitly in the AI-assist's
  honest-unsupported list (`ScheduleAssistAgent::semanticCaveats()`) rather than silently
  approximated. `every_n_days`/`every_n_hours`/`every_n_minutes` WITH an optional time-of-day
  window ARE supported (see the TIME/DAY axis tables) — do not confuse these with the unsupported
  rolling-interval case.
- **`TIME` real runtime semantics** (Phase 2 of the variable-typesystem rework): condition
  operators, resolver/evaluator/executor support, and a flat wire representation beyond the
  current `text` degrade. `WorkflowVariableType::TIME` (phase-1a) is catalog/descriptor-only
  today — see the Accepted residual risks note above and ADR-0022.
- **Presence-op write validation on a non-text reference** (Phase 2): `WorkflowConditionTreeValidator::walkPipeline`'s
  exact-type gate does not yet special-case `coalesce`/`is_present`/`is_null`/`assert_present` the
  way the runtime executor already does — see the Accepted residual risks note above and
  ADR-0022.
- **Defensive `normalizeInput` default arm + write-time `date_format` pattern validation** (Phase
  2 hardening, neither reachable today): `WorkflowOperationExecutor::normalizeInput()`'s `match`
  is still exhaustive over the original 7 `WorkflowVariableType` cases (no `default` arm), so a
  hypothetical future call with `baseType: TIME` would throw an `UnhandledMatchError` instead of
  failing closed (no such call exists today). `date_format`'s `pattern` arg is validated at write
  time only as a generic string, not against the safe-token whitelist — a malformed pattern is
  only caught at RUN time (fails soft), never a `422`. See ADR-0022.
