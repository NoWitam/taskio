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
> **Schedule rebuild (B1–B5, this revision).** The schedule vocabulary grew from 12 to **16
> families** (`every_n_months`, `nth_weekday_of_month`, `last_weekday_of_month`,
> `last_working_day_of_month` added), `weekly` moved from a single `weekday` scalar to a
> `weekdays` list (legacy scalar tolerated on READ only), and the schedule block gained two
> optional keys — `times[]` (multiple fire times) and `exclusions` (a skip filter) — plus a new
> live preview endpoint (`POST /workflows/meta/schedule-preview`). See ADR-0010 for the design
> decisions. Some narrative below that used to describe an infeasible request (e.g. "last Friday
> of the month", "every weekday") is now EXPRESSIBLE — read the Schedule section for the current
> family table, not just the AI-assist examples.

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
| `schedule`           | A cadence fires (16 families — see the Schedule section). Never event-dispatched. |

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
| `steps.*.key`                    | yes                      | string, max 100, **distinct across the whole array** (used for `{{steps.<key>.*}}`) |
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
| 422  | `steps.*.key`                        | Duplicate key across the step list.                          |
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

---

### GET /api/workflows/{workflow}/runs

Read-only monitoring: a workflow's runs, cursor-paginated (15/page), newest first
(`created_at DESC`, `id DESC` tiebreak — UUIDv7 ids are monotonic, so this keeps pagination
stable across a boundary where several runs share a timestamp). Authorization: `view` on the
parent workflow (any workspace member — runs are workspace-visible read-only monitoring).

**Query**

| Param    | Required | Notes                                                                 |
|----------|----------|--------------------------------------------------------------------------|
| `state`  | no       | one of `WorkflowRunState`. An unrecognised value is **silently ignored** (no filter, no error) — mirrors the Bot inbox `state` filter. |
| `origin` | no       | one of `WorkflowRunOrigin`. Same silent-ignore behavior.               |
| `cursor` | no       | cursor from `meta.next_cursor`.                                        |

**Response** `200 OK` — list shape carries `steps_count` (via `withCount`) but **excludes**
`trigger_payload` and `steps` (potentially large; detail-only):

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

**Errors**: `403` not a member, `404` unknown run, or a run belonging to a different workflow.

---

### GET /api/workflows/meta/schedule-families

Discovery endpoint for the schedule builder: the 16-family vocabulary plus each family's
per-param descriptors, so the FE renders correct controls and the AI-assist proposes a valid
`params` object. Static path, declared before the `{workflow}` resource so it never binds as an
id. Any authenticated member may read it (no policy object — it exposes no tenant data; labels
are NOT included, the FE supplies its own i18n — mirrors the Bot tool-registry discovery
endpoint). The endpoint (`WorkflowScheduleFamilyCatalog::all()`) is a thin `array_map` over
`WorkflowScheduleFamily::cases()` — it has no family list of its own, so it can never fall behind
the enum; the response below reflects the CURRENT 16-family output verbatim.

**Response** `200 OK`

```json
{
  "data": [
    { "family": "every_n_minutes", "params": [ { "name": "n", "type": "int", "required": true, "min": 1, "max": 59 } ] },
    { "family": "hourly", "params": [] },
    { "family": "hourly_at", "params": [ { "name": "minute", "type": "int", "required": true, "min": 0, "max": 59 } ] },
    { "family": "every_n_hours", "params": [
      { "name": "n", "type": "int", "required": true, "min": 2, "max": 12 },
      { "name": "minute", "type": "int", "required": false, "min": 0, "max": 59 }
    ] },
    { "family": "daily", "params": [ { "name": "time", "type": "time", "required": true } ] },
    { "family": "twice_daily", "params": [
      { "name": "first_hour", "type": "int", "required": true, "min": 0, "max": 23, "lt": "second_hour" },
      { "name": "second_hour", "type": "int", "required": true, "min": 0, "max": 23 },
      { "name": "minute", "type": "int", "required": false, "min": 0, "max": 59 }
    ] },
    { "family": "weekly", "params": [
      { "name": "weekdays", "type": "weekday_list", "required": true, "min": 0, "max": 6 },
      { "name": "time", "type": "time", "required": true }
    ] },
    { "family": "monthly", "params": [
      { "name": "day", "type": "int", "required": true, "min": 1, "max": 31 },
      { "name": "time", "type": "time", "required": true }
    ] },
    { "family": "twice_monthly", "params": [
      { "name": "first_day", "type": "int", "required": true, "min": 1, "max": 31, "lt": "second_day" },
      { "name": "second_day", "type": "int", "required": true, "min": 1, "max": 31 },
      { "name": "time", "type": "time", "required": true }
    ] },
    { "family": "last_day_of_month", "params": [ { "name": "time", "type": "time", "required": true } ] },
    { "family": "quarterly", "params": [
      { "name": "day", "type": "int", "required": true, "min": 1, "max": 31 },
      { "name": "time", "type": "time", "required": true }
    ] },
    { "family": "yearly", "params": [
      { "name": "month", "type": "int", "required": true, "min": 1, "max": 12 },
      { "name": "day", "type": "int", "required": true, "min": 1, "max": 31 },
      { "name": "time", "type": "time", "required": true }
    ] },
    { "family": "every_n_months", "params": [
      { "name": "n", "type": "int", "required": true, "min": 2, "max": 6 },
      { "name": "day", "type": "int", "required": true, "min": 1, "max": 31 },
      { "name": "time", "type": "time", "required": true }
    ] },
    { "family": "nth_weekday_of_month", "params": [
      { "name": "ordinal", "type": "int", "required": true, "min": 1, "max": 5 },
      { "name": "weekday", "type": "weekday", "required": true, "min": 0, "max": 6 },
      { "name": "time", "type": "time", "required": true }
    ] },
    { "family": "last_weekday_of_month", "params": [
      { "name": "weekday", "type": "weekday", "required": true, "min": 0, "max": 6 },
      { "name": "time", "type": "time", "required": true }
    ] },
    { "family": "last_working_day_of_month", "params": [
      { "name": "time", "type": "time", "required": true }
    ] }
  ]
}
```

`lt` names ANOTHER param of the same family this one must be strictly LESS THAN (the
`twice_daily` / `twice_monthly` ordering invariants) — see the Schedule section for the full
family table with next-fire semantics. `weekly`'s param is `weekdays` (type `weekday_list`, a
non-empty array of distinct weekday ints), not shown as a scalar `weekday` above — the `weekly`
entry in this endpoint's actual JSON now reads `{ "name": "weekdays", "type": "weekday_list",
"required": true, "min": 0, "max": 6 }` (the `min`/`max` bound each ARRAY ELEMENT, 0..6 — not the
list length). See the Schedule section for the full weekly write shape and the legacy scalar
read-tolerance note.

---

### POST /api/workflows/meta/schedule-preview

LIVE SCHEDULE PREVIEW: projects the next N fire instants of a proposed (not-yet-saved) cadence, so
the FE schedule builder can show a running "next runs" list as the user edits. Static path,
declared before the `{workflow}` resource. Any authenticated member may call it — it exposes no
tenant data, only a projection over the public cadence vocabulary. Backed by
`WorkflowSchedulePreviewController` + `WorkflowScheduleService::nextOccurrences()` (a PURE
function — no tenancy, no model writes).

**Body**

```json
{ "schedule": { "family": "weekly", "params": { "weekdays": [1, 3], "time": "09:00" }, "tz": "Europe/Warsaw" }, "count": 6 }
```

| Field       | Required | Constraints                                                          |
|--------------|-----------|--------------------------------------------------------------------------|
| `schedule`     | **yes**    | the same `{ family, params, tz?, times?, exclusions? }` block the write path accepts, validated by the SAME `WorkflowScheduleRulesValidator` — with ONE difference (below). |
| `count`          | no          | integer 1–12, default 6 — how many upcoming occurrences to project.       |

**The ONE validation difference from the write path**: `SchedulePreviewRequest` calls
`WorkflowScheduleRulesValidator::secondPass(..., checkEmpty: false)` — the empty-schedule guard
(the check that rejects a cadence whose `exclusions` rule out every occurrence) is OFF. Every
STRUCTURAL rule (unknown family, out-of-bounds params, malformed `times`/`exclusions`, the `lt`
ordering invariants) still returns a 422 exactly as it would on save. An over-constrained but
otherwise well-formed schedule (e.g. `weekly` on Monday whose `exclusions.weekdays` also excludes
Monday) is NOT a 422 here — it comes back as DATA (`empty: true`), so the FE can render a
pre-save warning instead of surfacing a confusing validation error for a block that is otherwise
well-formed.

**Response** `200 OK`

```json
{
  "occurrences": ["2026-07-13T07:00:00.000000Z", "2026-07-15T07:00:00.000000Z", "2026-07-20T07:00:00.000000Z"],
  "count": 6,
  "empty": false,
  "approximate": false
}
```

| Field           | Meaning                                                                                    |
|-------------------|-----------------------------------------------------------------------------------------------|
| `occurrences`       | ISO-8601 UTC instants, STRICTLY ascending, computed from `now()` — at most `count`, may be FEWER (or `[]`) when the cadence has no reachable occurrence within `WorkflowScheduleService`'s horizon (1000 iterations / 10 years). Same string format `WorkflowResource` uses for `next_due_at` (`toISOString()`), so the FE parses one shape everywhere. |
| `count`               | echoes the resolved (defaulted/clamped) count that was requested.                             |
| `empty`                 | `true` when `occurrences` is `[]` — an over-constrained `exclusions` set (or, in principle, any cadence the compiler cannot resolve). This is DATA, never a 422, on this endpoint. |
| `approximate`             | `true` ONLY for `every_n_minutes`. Its phase is set by the workflow's ARM instant (activation), not the calendar — a preview computed from "now" is indicative of the cadence, not the exact grid the live workflow will fire on once activated. Every wall-clock cron / `last_working_day_of_month` family is calendar-anchored, so its preview is EXACT. Decided on the COMPILED kind (`CompiledSchedule::isInterval()`), never on the family string. |

**This is the ONLY place occurrence dates are computed for the FE.** The frontend never
locally re-implements cron/interval math to render a preview or a "next run" hint — every preview
surface (the builder's live preview, the AI-assist's alternative preview) calls this endpoint.

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

### POST /api/workflows/schedule-assist

AI SCHEDULE ASSIST: turns a natural-language schedule description into the structured
`trigger_config.schedule` config the human builder uses — or an honest report of what cannot be
expressed, with an optional approximating alternative. Authorization: any authenticated
workspace member (workspace membership already enforced upstream by `ResolveWorkspace`; the
endpoint exposes no tenant data).

**This revision's vocabulary is wider.** The agent's prompt (`ScheduleAssistAgent`) is generated
from `WorkflowScheduleFamily::paramDescriptors()` at runtime, so it automatically picked up the 4
new families plus the `times`/`exclusions` extensions — no prompt hand-editing was needed. Two
requests that were previously reported `feasible:false` are now genuinely feasible:
- **"the last Friday of every month at 9am"** → `last_weekday_of_month` (`{weekday: 5, time:
  "09:00"}`) — previously there was no weekday-of-month family at all.
- **"daily except weekends, at 9am"** → `daily` with `exclusions.weekdays: [0, 6]` — previously
  there was no exclusion mechanism.

**Still genuinely infeasible** (see `ScheduleAssistAgent::semanticCaveats()` for the exact,
current list the model is instructed to report honestly): no every-N-days family (only every-N-
MINUTES/HOURS/MONTHS grids exist, no every-N-days), and no continuous time-WINDOW cadence (only
discrete fire times — `times[]` covers "at 8 and at 17", not "sometime between 9 and 17").

**Body**

```json
{ "prompt": "every weekday morning at 9", "tz": "Europe/Warsaw" }
```

| Field    | Required | Constraints                                    |
|-----------|-----------|-------------------------------------------------|
| `prompt`   | yes        | string, max 500 — UNTRUSTED free text, treated purely as data by the agent. |
| `tz`         | no          | nullable, a valid IANA timezone string.         |

**Response** `200 OK`

```json
{
  "data": {
    "feasible": true,
    "config": { "family": "weekly", "params": { "weekdays": [1], "time": "09:00" }, "tz": "Europe/Warsaw" },
    "unsupported": [],
    "alternative": null,
    "explanation": "Ustawiłem harmonogram na każdy poniedziałek o 9:00 (Europe/Warsaw)."
  }
}
```

A feasible example using a family this revision ADDED (previously would have needed the
`alternative` channel):

```json
{
  "data": {
    "feasible": true,
    "config": { "family": "last_weekday_of_month", "params": { "weekday": 5, "time": "09:00" }, "tz": "Europe/Warsaw" },
    "unsupported": [],
    "alternative": null,
    "explanation": "Ustawiłem harmonogram na ostatni piątek każdego miesiąca o 9:00 (Europe/Warsaw)."
  }
}
```

An honest infeasible example (the model correctly reports no every-weekday-only-Mon-Fri family
exists — `times`/`exclusions` widened the vocabulary, but there is still no continuous Mon-Fri-
only cadence family):

```json
{
  "data": {
    "feasible": false,
    "config": null,
    "unsupported": ["brak rodziny \"co dzień roboczy\" (pon-pt) — dostępne są tylko cykle dzienne/tygodniowe/miesięczne"],
    "alternative": { "config": { "family": "daily", "params": { "time": "09:00" }, "tz": "Europe/Warsaw", "exclusions": { "weekdays": [0, 6] } }, "note": "To uruchomi harmonogram codziennie z pominięciem sobót i niedziel — nie jest to identyczne z \"dniem roboczym\" (nie uwzględnia świąt), ale w praktyce odpowiada dniom pon-pt." },
    "explanation": "Nie ma rodziny harmonogramu ograniczonej wyłącznie do dni roboczych (święta nie są uwzględniane w żadnej rodzinie). Zaproponowałem alternatywę: codziennie o 9:00, z pominięciem weekendów."
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
   reaching the API response.
3. A `config` claimed `feasible:true` is RE-VALIDATED against the exact same rules the write path
   uses (`WorkflowScheduleRulesValidator`) AND run through the real compiler
   (`WorkflowScheduleCompiler::compile()`) as a final sanity gate. Either failing downgrades the
   response to `feasible:false`, `config:null`, with the validation failure appended to
   `unsupported`.
4. An `alternative.config` that fails the same gate is dropped (`alternative:null`) rather than
   surfaced broken.
5. A surviving config with no `tz` inherits the caller's `tz` hint (an explicit `tz` from the
   model wins).

This means: **a config the endpoint returns as `feasible:true` is GUARANTEED to be a valid,
compilable schedule** — the backend re-derives that guarantee itself, it does not take the
model's word for it.

**The residual honesty limit (accepted, not a bug).** The re-validation gate proves a returned
config is STRUCTURALLY valid and COMPILABLE — it cannot prove the config SEMANTICALLY matches
what the user asked for. If the model mis-reads "every weekday" as `daily` and wrongly marks it
`feasible:true`, the backend has no way to detect that the resulting (valid, compilable) `daily`
schedule is not what was actually requested — a structurally-valid-but-wrong config can still
reach the caller. Mitigations: the agent's instructions explicitly enumerate the closed family
vocabulary and forbid inventing families/approximating silently into `config` (an
approximation must go through `alternative` + an honest `note`, never straight into `feasible:
true`/`config`); the frontend always surfaces `explanation` so the user can sanity-check the
result before saving; nothing about this endpoint is fully closed-loop-verifiable server-side.

**Stateless, not run-cap-counted.** A schedule-assist call creates nothing (no task, no run) — it
is metered by its OWN per-user throttle (`assist_rate_per_minute`), never against
`config('workflows.max_runs_per_month')` / `max_runs_hard_cap` (those meter workflow RUNS).

---

## Capability flags

`WorkflowResource` (detail) exposes the same server-authoritative capability-flag convention as
Bot/Approvals — the frontend must never invent authorization, only read these:

| Flag                | Source                                                    |
|-----------------------|----------------------------------------------------------------|
| `is_owner`             | `creator_id === auth user id`.                              |
| `can_be_edited`        | `WorkflowPolicy::update` (creator-only).                    |
| `can_be_deleted`       | `WorkflowPolicy::delete` (creator-only).                    |
| `can_change_status`    | `WorkflowPolicy::changeStatus` (creator-only).               |
| `can_run`               | `WorkflowPolicy::run` (any workspace member).                |

`WorkflowListResource` carries only `is_owner` (lean list shape) plus `step_count` (derived:
`count(steps ?? [])`) and `next_due_at`.

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
{ "schedule": { "family": "weekly", "params": { "weekdays": [1, 3, 5], "time": "09:00" }, "tz": "Europe/Warsaw" } }
```

| Key                 | Required                        | Notes                                              |
|-----------------------|------------------------------------|-----------------------------------------------------|
| `schedule`             | **yes**                             | object, `{ family, params, tz?, times?, exclusions? }`. |
| `schedule.family`      | **yes**                             | one of the 16 `WorkflowScheduleFamily` values.       |
| `schedule.params`      | per-family                          | see the family table in the Schedule section; validated by `WorkflowScheduleRulesValidator`, derived from `WorkflowScheduleFamily::paramDescriptors()` (the SAME source `/meta/schedule-families` exposes). |
| `schedule.tz`           | no                                  | IANA timezone string; default `config('app.timezone')` (UTC). |
| `schedule.times`         | no                                  | array, 1–6 DISTINCT `'HH:mm'` strings — an alternate way to say "fire at each of these times", replacing `params.time`. Only for families whose descriptors carry a `time` param (`supportsTimes()`); mutually exclusive with `params.time`. See the Schedule section. |
| `schedule.exclusions`      | no                                  | object, `{ months?: int[1-12] max 11, weekdays?: int[0-6] max 6, dates?: 'Y-m-d'[] max 50 }` — a post-filter that drops any occurrence matching. See the Schedule section. |

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
served by `GET /forms/{form}/workflow-catalog` and consumed by both step config surfaces.

### Two serializations, resolved by `WorkflowVariableResolver`

**1. TEXT / markdown fields** (e.g. `create_task.title`, `create_form_report.name`,
`.guidelines`) carry the next editor's **variable directive** — a markdown directive of the
exact byte shape the editor's `encodeVariableDirective` produces:

```
@[variable]("{\"v\":1,\"data\":{\"id\":\"trigger.fields.status\",\"name\":\"Status\",\"type\":\"text\",\"locked\":false}}")
```

The directive is **IDENTITY-ONLY**: `data.id` (the full path) is the ONLY key the resolver
reads. **The directive carries NO extra type field beyond the editor's own primitive
(`data.type` is the editor's rendering primitive, text/number/boolean — NOT the workflow type).**
A variable's REAL workflow type (`text|number|boolean|date|enum|multi`) is recovered from the
CATALOG by `path`, never trusted from the directive payload. `pipeline` content inside the
directive (if any) is ignored — MVP references are identity-only.

**2. NON-TEXT (structured) fields** (`create_task.priority`, `.deadline`;
`create_form_report.submissions_from`, `.submissions_to`) carry the **`{kind}` union**:

```json
{ "kind": "literal", "value": "high" }
{ "kind": "variable", "ref": { "source": "trigger", "path": "trigger.fields.priority", "type": "enum" } }
```

`resolveValueOrVariable()` handles this: a `literal` resolves (and type-coerces) its `value`
directly; a `variable` looks up `ref.path` in the run context then coerces the result to the
field's EXPECTED type (the step declares what type it needs — e.g. `priority` coerces to
`WorkflowVariableType::ENUM`, `deadline` to `DATE`). A bare scalar in a structured slot (no
`kind` wrapper) is tolerantly treated as a literal.

### Coercion table (`WorkflowVariableResolver::coerce`)

| Expected type | Coercion                                                       |
|-----------------|-------------------------------------------------------------------|
| `date`            | Carbon-parsed to an ISO-8601 string; unparseable/empty → `null`.   |
| `number`            | numeric value + 0 (int or float); non-numeric → `null`.             |
| `boolean`             | `filter_var(..., FILTER_VALIDATE_BOOLEAN)`.                          |
| `multi`                 | array passthrough; a scalar is wrapped in a 1-element array.        |
| `enum` / `text`           | scalar cast to string; a non-scalar (array) → `null`.                |

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

---

## Steps

Steps run **in the order they are stored**, each acting only through an existing domain service
(`TaskService`, `FormReportService`) — never a raw model write that bypasses business logic. A
step's output is merged into `context.steps.<key>` so LATER steps can reference it via
`{{steps.<key>.*}}` / the directive/union serializations above.

**First failure stops the run.** Steps commit independently (no all-or-nothing transaction
around the whole run): a workflow whose first step created a task and whose second step failed
leaves the task in place, and the run timeline shows exactly where it stopped.

### `create_task`

Creates a task through `TaskService::create()` (so label-attach and bot-dispatch side effects
fire as they would for a user-created task). Starts in `TO_DO`. Reaches ENTITY-FORM PARITY with
the create-task form (attachments excluded):

| Config field             | Required | Type            | Failure mode                                                             |
|----------------------------|----------|-------------------|--------------------------------------------------------------------------|
| `title`                      | **yes**  | resolved string     | **HARD** — blank after resolution fails the WHOLE step (`RuntimeException`; run stops here). |
| `description`                 | no       | resolved string       | n/a — absent/blank → `null`.                                             |
| `priority`                     | no       | `{kind}` union, ENUM   | **SOFT** — unresolved/unknown → defaults to `medium`.                    |
| `deadline`                       | no       | `{kind}` union, DATE     | **SOFT** — unresolved/unparseable/blank → `null`.                        |
| `labels`                          | no       | literal array of uuids     | **SOFT** — a foreign/unknown label id is silently ignored by `TaskService`'s attach (never throws); a stray "ghost" label id from a deleted label is simply dropped. |
| `assignee_type` / `assignee_id`     | no, both-or-neither | literal `'user'\|'bot'` + uuid | a partial pair (only one present) is treated as no assignee.        |
| `form_id`                              | no       | literal uuid                | optional, no existence guard beyond the write-time `ScopedExists` check. |
| `approval_pipeline_id`                   | no       | literal uuid              | optional, same as above.                                                 |

**Output**: `{ task_id, title }`.

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
| `name`                       | **yes**  | resolved string                  | **HARD** — blank after resolution fails the step.                    |
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

Creator attribution: `FormReport` uses `HasCreator`, which stamps `auth()->id()` on save. An
EVENT run fires inside the triggering user's authenticated request and a MANUAL run inside the
acting user's — so the report's creator is that user in both cases. A SCHEDULE run has no
authenticated user, so `creator_id` is `null` (existing `HasCreator` semantic, unchanged here).

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
  "trigger_config": { "schedule": { "family": "daily", "params": { "time": "09:00" } } },
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
`HasCreator`'s saving hook stamps `auth()->id()` on every save whenever it is unset, so an
EVENT run fired inside an authenticated HTTP request ends up carrying that user as
`creator_id` even though nobody "manually" ran it. A schedule sweep run started outside a
request (real cron) records `creator_id = null`. Only a MANUAL run explicitly, deliberately
carries the acting user's id. **Read `origin`, never infer engine-vs-manual from `creator_id`.**

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
`workflows:run-scheduled` console sweep. The 5.1 re-scope replaced the 4 hand-coded Etap-5
presets (`every_n_minutes`/`hourly`/`daily`/`weekly`) with 12 descriptor-driven families compiled
to a real cron expression (via `dragonmantank/cron-expression`) or a bespoke interval — see
ADR-0009 §3. **This revision (B1–B5) grew the vocabulary to 16 families**, changed `weekly` from
a single weekday to a list, and added the optional `times[]` / `exclusions` schedule-block
extensions plus the `POST /workflows/meta/schedule-preview` endpoint — see ADR-0010 for the
design decisions.

### Families (`WorkflowScheduleFamily`) — param descriptors + next-fire semantics

| Family                | Params                                                        | Compiles to                        | Next-fire semantics |
|-------------------------|--------------------------------------------------------------------|---------------------------------------|-----------------------|
| `every_n_minutes`         | `n` (1–59, required)                                                 | **bespoke interval** (not cron)        | `$from + n minutes` — phased on the ARM instant, no wall-clock alignment (armed at 10:02 with n=15 fires 10:17, 10:32, …, never snapping to :00/:15/:30/:45). |
| `hourly`                    | — (none)                                                              | `0 * * * *`                              | Next top-of-hour STRICTLY after `$from` (exactly-on-the-hour rolls to the FOLLOWING hour). |
| `hourly_at`                   | `minute` (0–59, required)                                              | `M * * * *`                                | Minute `M` of every hour, strictly after `$from`.                       |
| `every_n_hours`                 | `n` (2–12, required), `minute` (0–59, optional)                          | `M */N * * *`                                | **HOUR-OF-DAY MODULO N**, not a rolling interval — for `n=5` fires at 00,05,10,15,20 then RESETS at midnight (the gap across midnight is 4h, not 5h). |
| `daily`                            | `time` (`HH:mm`, required)                                                 | `M H * * *`                                    | Today at `time` if still future, else tomorrow.                          |
| `twice_daily`                        | `first_hour` (0–23, lt `second_hour`), `second_hour` (0–23), `minute` (0–59, optional) | `M h1,h2 * * *`                                  | Two explicit daily fires; `first_hour < second_hour` enforced. Does NOT accept `times[]` (no `time` param).           |
| `weekly`                               | `weekdays` (`weekday_list`, non-empty, required), `time` (required)                                 | `M H * * d1,d2,…`                                        | Next occurrence of ANY listed weekday at `time`, strictly after `$from`; same-day-but-passed rolls to next matching weekday. `0=Sunday`. See the "weekly: list, not scalar" note below. |
| `monthly`                                 | `day` (1–31, required), `time` (required)                                      | `M H D * *`                                          | Day `D` of the month at `time`. **Day-31 skip**: a day that does not exist in a shorter month (e.g. 31 in February) is SKIPPED, not clamped. |
| `twice_monthly`                             | `first_day` (1–31, lt `second_day`), `second_day` (1–31), `time` (required)      | `M H d1,d2 * *`                                        | Two explicit monthly fires; `first_day < second_day` enforced; same day-31-skip caveat per day. |
| `last_day_of_month`                           | `time` (required)                                                                 | `M H L * *` (`L` = dragonmantank's "last calendar day") | GUARANTEED month-end fire — use this instead of `monthly` day 31 when a reliable month-end trigger matters. |
| `quarterly`                                      | `day` (1–31, required), `time` (required)                                          | `M H D 1,4,7,10 *`                                       | Day `D` of Jan/Apr/Jul/Oct. Same day-skip caveat.                        |
| `yearly`                                            | `month` (1–12, required), `day` (1–31, required), `time` (required)                  | `M H D Mon *`                                              | Day `D` of month `Mon`. **`month=2, day=29` fires ONLY in leap years** (~once every 4 years) — pinned by a unit test. |
| `every_n_months`                                        | `n` (2–6, required), `day` (1–31, required), `time` (required)                        | `M H D m1,m2,… *`                                            | Day `D` of a **JANUARY-ANCHORED** month grid (`1, 1+n, 1+2n, … ≤ 12`), MODULO the year — mirrors `every_n_hours`' hour-of-day-modulo-N doctrine. `n=5` gives Jan(1)/Jun(6)/Nov(11), then resets to Jan — the gap across the year boundary (Nov→Jan, 2 months) can be SHORTER than `n`. Same day-skip caveat as `monthly`. |
| `nth_weekday_of_month`                                    | `ordinal` (1–5, required), `weekday` (0–6, required), `time` (required)                 | `M H * * W#O` (dragonmantank `#` token)                        | The `O`-th occurrence of weekday `W` in the month at `time` (e.g. ordinal=1 = "first Monday"). **`ordinal=5` SKIPS** any month that has only four occurrences of that weekday — same skip doctrine as day-31, not clamped to the fourth. |
| `last_weekday_of_month`                                     | `weekday` (0–6, required), `time` (required)                                             | `M H * * WL` (dragonmantank `L` suffix on the dow field)          | The LAST occurrence of weekday `W` in the month at `time` (e.g. "last Friday") — a guaranteed fire every month, unlike `nth_weekday_of_month` ordinal=5. |
| `last_working_day_of_month`                                   | `time` (required)                                                                           | **BESPOKE** (not cron — see note below)                            | The last Mon–Fri of the month at `time`. **Does NOT account for public holidays** — a month whose last weekday is a holiday still fires that day. |

**`weekday` convention: `0 = Sunday` … `6 = Saturday`** (matches `Carbon::dayOfWeek` /
`Carbon::SUNDAY` AND cron's day-of-week field, which also treats 0 as Sunday) — unchanged and
used consistently by both the scalar `weekday` type and the `weekday_list` type.

**`weekly`: a list, not a scalar (read-tolerant).** The write path now stores `params.weekdays` —
a non-empty array of DISTINCT weekday ints (the `weekday_list` descriptor type) — compiling to a
cron day-of-week field with every listed day (`M H * * d1,d2,…`, sorted+deduped). A **legacy
record** written before this change may still carry a scalar `params.weekday`; the compiler
(`WorkflowScheduleCompiler::weekdayList()`) reads it as a one-element list so old rows keep
compiling unchanged — this is READ-ONLY tolerance. **New writes MUST use `weekdays`**; a `weekday`
scalar submitted on write is rejected as a foreign param (422) because it is not in `weekly`'s
current descriptor set (see `WorkflowScheduleFamily::paramDescriptors()`).

**Why `last_working_day_of_month` is bespoke, not cron.** `dragonmantank/cron-expression` v3.6.0's
`LW` token does NOT mean "last working day of the month" — it parses the `L` in `LW` as day `0`,
which normalizes to the PREVIOUS month and returns wrong/garbage dates (verified against the
installed version; see `CompiledSchedule`'s docblock for the exact failure mode). "Last working
day" also cannot be expressed as any single standard cron expression (it is the LATEST of {last
Mon, …, last Fri}, not a fixed day-of-month or day-of-week rule). `WorkflowScheduleService`
therefore computes it directly: start at the month's last calendar day and step backward over
Saturday/Sunday (`lastWorkingDayOfMonth()`), resolved as a local wall-clock time in the schedule's
tz then converted to UTC — the same wall-clock-then-convert pattern every other family follows.
See ADR-0010 for the full LW-defect record (this decision supersedes nothing — it is new).

### Multiple fire times (`schedule.times`)

Any family whose descriptors carry a `time` param (`WorkflowScheduleFamily::supportsTimes()` —
every family above except the 5 interval/no-time families: `every_n_minutes`, `hourly`,
`hourly_at`, `every_n_hours`, `twice_daily`) may carry `schedule.times` INSTEAD of `params.time`:
a list of 1–6 DISTINCT `'HH:mm'` strings meaning "fire at EACH of these times" (e.g. a `daily`
schedule with `times: ["08:00", "17:00"]` fires twice a day). The two are MUTUALLY EXCLUSIVE — a
422 if both are present. `WorkflowScheduleCompiler` expands `times` into ONE cron expression per
time (sharing every other date field) — a `CompiledSchedule` with a `cron` kind is always a LIST
of expressions now, one element for a plain `params.time` schedule, N elements for a
`times[]` schedule. `WorkflowScheduleService` takes the EARLIEST strictly-after candidate across
the whole list — the union of the per-time occurrences. `last_working_day_of_month` supports
`times` the same way (a list of HH:mm last-working-day candidates, earliest wins).

```json
{ "family": "daily", "params": {}, "times": ["08:00", "17:00"], "tz": "Europe/Warsaw" }
```

### Exclusions (`schedule.exclusions`)

An optional post-filter, `{ months?, weekdays?, dates? }`, evaluated in the SCHEDULE's own
timezone AFTER a candidate fire time is computed — never inside the cron grammar itself:

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
{ "family": "daily", "params": { "time": "09:00" }, "exclusions": { "weekdays": [0, 6] } }
```

The filter is a LOOP inside `WorkflowScheduleService::nextDueAt()`: compute the next union
candidate, and if it is excluded, advance the cursor past it and recompute — for EVERY compiled
kind, including the bespoke `every_n_minutes` interval ("every 15 minutes except weekends" really
does skip the whole weekend, not just the boundary instant). Two HARD LIMITS bound the loop so an
unreachable schedule can never hang: at most **1000 iterations**, and a **10-year horizon** from
the search's start instant — exceeding either returns `null` (treated as "no occurrence").

**The empty-schedule guard.** After every structural rule passes, `WorkflowScheduleRulesValidator`
computes the schedule's actual first occurrence (reusing `WorkflowScheduleService::nextOccurrences`)
and rejects the write with a 422 on `trigger_config.schedule.exclusions` if the cadence has NO
reachable occurrence (e.g. `weekly` on Monday whose `exclusions.weekdays` also excludes Monday) —
an unfireable schedule must never persist. **This guard is OPTIONAL**, controlled by the
validator's `checkEmpty` parameter: the write path (`StoreWorkflowRequest`/`UpdateWorkflowRequest`)
and the AI-assist re-validation keep it ON; the live `schedule-preview` endpoint turns it OFF, so
an over-constrained draft comes back as `{ empty: true }` DATA for a pre-save warning instead of a
422 while the user is still mid-edit. Every STRUCTURAL check (bad family, out-of-bounds params,
malformed `times`/`exclusions` shape) still runs and still 422s on preview.

### Timezone

`schedule.tz` defaults to `config('app.timezone')` (UTC). Every wall-clock family (everything
except `every_n_minutes`) is resolved **IN** the schedule's own timezone by the cron library (or,
for `last_working_day_of_month`, by the bespoke calculation), then converted to UTC for storage —
so a "09:00 Europe/Warsaw" daily schedule fires at the correct UTC instant year-round (07:00 UTC
in winter CET, 06:00 UTC in summer CEST). `exclusions` are evaluated against the candidate
RE-EXPRESSED in this same tz (an exclusion is a wall-clock-day concept, like the rest of the
cadence).

Every computed fire time is **strictly after** the `$from` instant it was computed from
(never equal), and always stored/returned in UTC.

### DST behavior (spring-forward AND fall-back, both pinned by unit tests)

**Spring-forward gap**: during a spring-forward gap the requested local wall-clock time does not
exist (e.g. 02:30 on the changeover night, Europe/Warsaw 2026-03-29 02:00→03:00). The cron
library shifts the non-existent local time FORWARD past the gap (e.g. that day's fire resolves to
03:30 local instead of crashing or looping) — one skewed fire is the accepted trade-off for a
rare boundary; the following day returns to the configured time.

**Fall-back overlap (new in this revision's test coverage)**: during a fall-back the local
wall-clock hour repeats (Europe/Warsaw 2026-10-25 03:00→02:00, so local 02:00–03:00 happens
TWICE that night). A `daily` schedule at `02:30` therefore fires **TWICE** that calendar night, at
two DISTINCT UTC instants: `00:30 UTC` (02:30 CEST, +02:00 — the first pass, before the clock
rolls back) then `01:30 UTC` (02:30 CET, +01:00 — the second pass, after). The strictly-after
invariant is what makes this safe: because the two local 02:30s are different UTC instants,
advancing the cursor from the first to the second is genuine forward progress — the SAME UTC
moment is never fired twice, and the following day returns to a single 02:30 (01:30 UTC). This is
the cron library's observed resolution and is **PINNED as accepted behavior**, not something the
module works around — see
`tests/Unit/Workflows/WorkflowScheduleServiceTest.php::test_daily_across_fall_back_dst_fires_both_local_0230_instances`.

### Live preview — `POST /workflows/meta/schedule-preview`

See the endpoint section above for the full request/response contract. In short: it is the ONLY
place occurrence dates are computed for the frontend (the FE never re-implements cron/interval
math locally), it validates with the empty-guard OFF (`empty: true` instead of a 422), and it
flags `approximate: true` only for `every_n_minutes` (an activation-phased interval, not a
calendar-anchored cadence).

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
- **`last_working_day_of_month` and every other family ignore public holidays.** A computed "last
  working day" (or any other family's fire date) that lands on a public holiday still fires
  normally — there is no holiday-calendar concept anywhere in the schedule vocabulary. See
  Planned/deferred below.
- **DST fall-back double-fire is accepted, not a bug.** A wall-clock schedule whose time falls in
  a fall-back-overlap hour (e.g. `daily 02:30` in Europe/Warsaw on the October changeover) fires
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
- **`creator_id` is non-null on event runs fired inside an authenticated request** even though
  the run is engine-authored, not user-initiated — this is expected `HasCreator` behavior, not
  a bug. `origin` is the authoritative signal for how a run began; never infer engine-vs-manual
  from `creator_id` (see the Origin section above).
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

---

## Related files

- `app/modules/Workflows/` — module root
- `app/modules/Workflows/Models/Workflow.php`, `WorkflowRun.php`, `WorkflowRunStep.php`
- `app/modules/Workflows/Services/WorkflowService.php` — definition CRUD
- `app/modules/Workflows/Services/WorkflowDispatchService.php` — the event/manual dispatch seam
- `app/modules/Workflows/Services/WorkflowManualRunService.php` — manual-run target resolution + 422s
- `app/modules/Workflows/Services/WorkflowRunManager.php` — claim / release / reaper / cap counters
- `app/modules/Workflows/Services/WorkflowRunContext.php` — in-process current-run holder (loop-depth seam)
- `app/modules/Workflows/Services/WorkflowStepRunner.php` — executes a claimed run's steps
- `app/modules/Workflows/Services/WorkflowStepFactory.php` — step-type → implementation
- `app/modules/Workflows/Services/WorkflowConditionEvaluator.php` — typed condition matrix
- `app/modules/Workflows/Services/WorkflowTriggerPayloadFactory.php` — the whitelisted `{{trigger.*}}` payload builder
- `app/modules/Workflows/Services/WorkflowVariableResolver.php` — the directive + `{kind}` union resolver (replaces the Etap-5 flat `ReferenceResolver`)
- `app/modules/Workflows/Services/WorkflowVariableCatalogService.php` — the typed variable/condition catalog
- `app/modules/Workflows/Services/WorkflowScheduleService.php` — cadence math + CAS claim + `nextOccurrences()` (the preview seam) + the `times`/`exclusions` post-filter loop
- `app/modules/Workflows/Services/WorkflowScheduleCompiler.php` — family → cron-list/interval/last-working-day compiler
- `app/modules/Workflows/Services/CompiledSchedule.php` — the compiled cadence value object (`interval` / `cron` list / `last_working_day` list kinds)
- `app/modules/Workflows/Services/WorkflowScheduleRulesValidator.php` — the ONE schedule-block rule set (write path + AI re-validation + preview, `checkEmpty` toggle)
- `app/modules/Workflows/Services/WorkflowScheduleFamilyCatalog.php` — the `/meta/schedule-families` discovery source (16 families)
- `app/modules/Workflows/Services/WorkflowScheduleAssistService.php` — AI assist orchestration + re-validation gate
- `app/modules/Workflows/Agents/ScheduleAssistAgent.php` — the tool-less natural-language agent
- `app/modules/Workflows/Http/Requests/SchedulePreviewRequest.php`, `Http/Controllers/WorkflowSchedulePreviewController.php` — the live schedule-preview endpoint
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
- `tests/Feature/WorkflowScheduleSweepTest.php`
- `tests/Feature/WorkflowScheduleAssistTest.php`
- `tests/Feature/WorkflowScheduleFamilyMetaTest.php`
- `tests/Feature/WorkflowSchedulePreviewTest.php` — the preview endpoint (empty/approximate semantics, checkEmpty-off behavior)
- `tests/Feature/WorkflowStepsTest.php`
- `tests/Feature/WorkflowVariableCatalogTest.php`
- `tests/Unit/Workflows/WorkflowConditionEvaluatorTest.php`
- `tests/Unit/Workflows/WorkflowScheduleServiceTest.php` — includes the DST spring-forward AND fall-back pins, `times`/`exclusions` cases, `last_working_day_of_month`
- `tests/Unit/Workflows/WorkflowScheduleCompilerTest.php`
- `tests/Unit/Workflows/WorkflowVariableResolverTest.php`
- `resources/js/next/pages/workflows/__tests__/WorkflowEditorDrawer.spec.ts` — pins the exact create-payload wires reproduced above
- `docs/decisions/ADR-0010-workflows-schedule-rebuild.md` — this revision's schedule design decisions (16 families, times, exclusions, preview, LW-defect)
- `docs/decisions/ADR-0009-workflows-rescope-typed-variables.md` — the 5.1 re-scope decisions
- `docs/decisions/ADR-0008-workflows-module-design.md` — run-engine decisions that still hold (superseded sections marked)
- `docs/next/workflows-uxui-spec.md` — the frontend UX/UI specification

## Planned / deferred (not implemented)

- **Wait-for-approval resume**: `WorkflowRunState::WAITING` is declared but never produced by
  the MVP engine. There is no longer a `start_approval` step in the 5.1 step set at all, so this
  is even further from being built than at Etap-5 time. See ADR-0008 #11 (superseded context;
  the mechanism note still holds).
- **Manual run cancellation**: `WorkflowRunState::CANCELLED` is declared but no cancel action
  exists yet.
- **Bot-authored submission tracking**: `source` cannot express "a bot filled this form in" —
  see the Accepted residual risks section. Needs a new column, not just morph-derived logic.
- **Operations pipeline for the typed variable system**: the current resolver supports identity
  lookup + type coercion only — no computed operations (string concatenation, date formatting,
  arithmetic) on a resolved variable. Deliberately deferred — see ADR-0009 §2.
- **Per-tenant error isolation in the sweep commands**: see the accepted-risk note above.
- **Public holiday awareness**: `last_working_day_of_month` (and every other family) has NO
  concept of a public holiday calendar — a computed "last working day" or any other fire date
  that lands on a holiday still fires normally. Would need a holiday-calendar data source (and
  almost certainly a per-workspace/per-locale one), a real scope increase, not a tweak.
- **An every-N-days family and continuous time-window cadences**: the vocabulary has no "every N
  days" family (only N-minute/N-hour/N-month grids) and no continuous "between HH:mm and HH:mm"
  window — only discrete `times[]` fire points. Both are named explicitly in the AI-assist's
  honest-unsupported list (`ScheduleAssistAgent::semanticCaveats()`) rather than silently
  approximated.
