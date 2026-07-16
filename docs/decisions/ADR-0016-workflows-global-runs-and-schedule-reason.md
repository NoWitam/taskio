# ADR-0016 — Global runs feed, `schedule_descriptor` exposure, and FE-owned schedule "reason"

**Date:** 2026-07-15 (created)
**Status:** Accepted (implemented)
**Module:** `App\Modules\Workflows` (backend), `resources/js/next/pages/workflows/` +
`resources/js/next/pages/forms/` (frontend)

---

## Context

The Runs monitoring surface (ADR-0008's run engine, exposed as `GET /workflows/{workflow}/runs` +
`GET /workflows/{workflow}/runs/{run}`) only ever answered "what happened inside THIS workflow?".
Three gaps surfaced once the module had real usage:

1. **No cross-workflow view.** A user auditing "what ran today across the whole workspace" had to
   open every workflow one at a time — there was no single feed.
2. **A schedule run's "why did this fire?" was illegible.** The detail drawer showed the raw
   `trigger_payload` (`{ scheduled_at: "2026-07-09T08:00:00Z" }`) — a timestamp, not a sentence a
   human recognizes ("the first Thursday of the month at 14:00"). The schedule descriptor needed
   to compute that sentence lives on the parent `Workflow`, not the `WorkflowRun`.
3. **The run-now `form_submitted` target was a raw id `TextInput`.** A user had to already know a
   `FormSubmission` uuid (from an API client or a browser deep-link) to test-run a workflow — the
   honest MVP gap flagged in the original UX spec (§8, "no submission picker exists").

This ADR also folds in the `SubmissionPreviewDrawer` "diff" mode question raised alongside the
global feed: the run detail drawer needed to show whether the submission a run captured has since
changed, which meant fetching a second, LIVE copy of the submission and comparing it against the
run's own frozen snapshot (`trigger_payload.fields`).

---

## Decisions

### 1. One shared `IndexWorkflowRunsRequest` + `WorkflowRunService`, not a parallel global stack

**Decision:** `GET /workflows/runs` (global) and `GET /workflows/{workflow}/runs` (per-workflow)
are two thin controller actions (`WorkflowRunController::global()` / `::index()`) over the SAME
FormRequest and the SAME service's `filteredRunsQuery()` builder. The global action adds exactly
two things the per-workflow action does not: an optional `workflow_id` scope and `->with('workflow')`
eager-loading (so `WorkflowRunResource` can emit the `workflow` block). Authorization branches
inside the ONE FormRequest (`view` on a bound `{workflow}` vs. `viewAny` on `Workflow` when none is
bound) rather than living in two separate classes.

**Alternatives rejected:**
- **A dedicated `GlobalWorkflowRunController` + `IndexGlobalWorkflowRunsRequest`.** Rejected — the
  filter surface (state/origin/trigger_type/date range) is byte-for-byte identical; a second class
  pair would drift the moment one filter changes and the other is forgotten (the module has already
  paid for this kind of drift once, with the pre-v2 schedule family list). One request + one
  service query builder is the same "single source of validation truth" pattern the schedule
  contract already uses (`WorkflowScheduleRulesValidator` shared by write/assist/preview).
- **A single endpoint with an optional `{workflow}` route parameter** (`GET /workflows/{workflow?}/runs`).
  Rejected — Laravel route-model binding does not cleanly support an optional bound model, and the
  static `workflows/runs` segment already has to be declared before the `workflows/{workflow}`
  apiResource for routing-order reasons; collapsing the two into one optional-param route would
  fight the framework for a cosmetic win.

### 2. Filters are now arrays, with a legacy-scalar shim — not a breaking rename

**Decision:** `state`, `origin`, and `trigger_type` become `state[]` / `origin[]` / `trigger_type[]`
(multi-select, matching the FE's `Select multiple` + the DateRangeFilter convention every other
next list already uses). `IndexWorkflowRunsRequest::prepareForValidation()` coerces a bare scalar
(`?state=completed`) into a one-element array BEFORE validation, so an old single-value deep-link
still works unchanged. Enum membership itself is still NOT validated (the original index's
"unrecognised value is silently ignored" behavior, preserved and now documented as a deliberate
"tolerant filter" rather than an oversight) — `WorkflowRunService::enumValues()` drops any member
`tryFrom()` cannot resolve.

**Alternatives rejected:** requiring the array shape strictly (422 on a legacy scalar) — rejected,
it would break any bookmarked/shared single-value run-filter link with no user-visible benefit.

### 3. `schedule_descriptor` rides on the RUN's SHOW response, gated on the run being a schedule run

**Decision:** `WorkflowRunResource` adds `schedule_descriptor` — the parent workflow's
`trigger_config.schedule` block, upgraded to v2 via the existing `LegacyScheduleUpgrader` — gated on
`relationLoaded('steps') && isScheduleRun()` (i.e. only on the detail/SHOW shape, and only for a
`schedule`-origin run). `WorkflowRunService::showRun()` was extended to eager-load `workflow`
alongside `steps` specifically so this works from the GLOBAL feed's detail drawer too, where the
caller has no workflow already loaded client-side (unlike the per-workflow Runs section, which
already has the workflow in its own store).

**Alternatives rejected:**
- **Compute the "reason" sentence server-side and return it as a string field.** Rejected — the
  schedule descriptor's human-readable grammar (day names, ordinals, locative month forms, PL
  plural rules) already lives entirely on the frontend (`describeSchedule` / `describeOccurrence` in
  `workflowSchedule.ts`, established by ADR-0012 for the schedule BUILDER's own summary sentence).
  Duplicating that grammar in PHP would mean maintaining two translation/pluralization engines for
  the same sentence family, one of which (Laravel's) has no natural home for the PL-specific rules
  the FE already solved. The backend's job is to prove the descriptor is real, valid v2 data (which
  it already does at read time via the same upgrader every other schedule-reading surface uses);
  turning it into a sentence is presentation, and this module already draws that line at the FE.
- **Always include `schedule_descriptor`, even for non-schedule runs (`null` otherwise).** Rejected
  in favor of a `when()`-gated omission — an explicit `null` on every `form_submitted` row would be
  a wasted key on the hot path (the SHOW response already carries the full step timeline + trigger
  payload); omission communicates "not applicable" as clearly as a typed `null` here.

### 4. The run detail's form-submission "diff" reuses `SubmissionPreviewDrawer` via a new `mode` prop, not a second component

**Decision:** `SubmissionPreviewDrawer` (previously Forms-only, view/edit) gains a `mode: 'view' |
'diff'` prop. `diff` mode is snapshot-vs-current: it renders `trigger_payload.fields` (the run's
frozen snapshot) as the base and fetches the CURRENT submission (`GET
/api/form-submissions/{id}`) to compute a field-by-field diff, using the project-wide `modified`
semantic (Decision 6) to highlight anything that changed. `view` mode is untouched — same behavior
FormSubmissionsView always had.

**Alternatives rejected:** a standalone `SubmissionDiffDrawer` component. Rejected — the two modes
share the entire shell (drawer chrome, footer, field-list rendering, date/source formatting); a
second component would fork all of that for one behavioral difference (fetch-and-compare vs.
render-and-edit).

### 5. Run-now target acquisition becomes Pick / Create, but the wire contract (`{target_id}`) is unchanged

**Decision:** `TargetPickerModal`'s `form_submitted` case replaces the raw-uuid `TextInput` with a
read-only "selected submission" summary plus two actions: **Pick** (a new `SubmissionPickerDrawer`,
a lean list over the existing `submissionsFor`/`fetchSubmissions` forms store, scoped to the
trigger's bound form) and **Create** (`FormFillView` mounted in a drawer, producing a REAL
`FormSubmission` via the existing forms-fill flow). Both resolve to a submission id that becomes
`target_id` — `POST /workflows/{id}/run { target_id }` is byte-for-byte unchanged; only how the id
is OBTAINED changed. When the trigger accepts any form (`trigger_config.form_id === null`), both
Pick and Create show a `FormSelect` step first.

**Alternatives rejected:** a bespoke submission-search endpoint dedicated to the run-now flow.
Rejected — the Forms module's existing submissions list endpoint + store already supports
search/source/date filtering and cursor pagination; a dedicated endpoint would duplicate a query
that already exists for FormSubmissionsView, for no behavioral gain.

### 6. `next-modified` is a PROJECT-WIDE semantic token, not a Workflows-scoped color

**Decision:** the "value has drifted from a captured snapshot" signal (`--color-next-modified` +
`-foreground`/`-subtle`/`-subtle-foreground`, `Badge variant="modified"`) is registered in
`resources/css/next.css` and `Badge.vue` alongside the four existing status families
(success/warning/danger/info), not as a Workflows-local class. Its first consumer is
`SubmissionPreviewDrawer`'s diff mode, but the token is intentionally NOT named
`workflows-diff` or similar — any future "changed since X" surface (e.g. a task edit history, a
form-schema version diff) can reuse it without a new token.

**Alternatives rejected:** reusing `warning` (amber) for "changed". Rejected — amber already means
"needs attention / caution" everywhere else in the design system (draft states, at-risk badges); a
diff highlight is neutral information, not a warning, and overloading the token would make a real
warning less legible next to a routine diff marker.

---

## Consequences

- **Additive, no breaking change.** Every new field (`workflow`, `schedule_descriptor`) is
  `when()`/`whenLoaded()`-gated and absent where it does not apply; every new filter param is
  optional; the legacy scalar shim keeps old single-value deep-links working; `target_id` on the
  run-now submit is unchanged.
- **One filter contract to maintain.** A future filter (e.g. a `created_by` scope) is added once,
  in `IndexWorkflowRunsRequest` + `WorkflowRunService::filteredRunsQuery()`, and both endpoints gain
  it simultaneously — there is no second copy to forget.
- **The schedule "reason" grammar stays a frontend maintenance concern.** A new schedule day/month
  mode added to the compositional descriptor (ADR-0012) needs a matching `describeOccurrence` case
  on the frontend, same as it already needs a matching `describeSchedule` case for the builder's own
  summary sentence — one place, not two.

## References

- `docs/backend/workflows-api.md` — the Runs endpoints section (wire contract).
- `docs/next/workflows-uxui-spec.md` §5/§6 — the Runs view + run-now UX spec, updated to as-built.
- `docs/decisions/ADR-0012-workflows-schedule-descriptor-v2.md` — the schedule v2 model and the
  FE-owns-the-grammar precedent this ADR extends to the run "reason" sentence.
- `docs/decisions/ADR-0008-workflows-module-design.md` — the original run engine / monitoring design.
