# ADR-0051 — Calendar module design: a registry the Calendar owns, sources everyone else owns

**Date:** 2026-08-09
**Status:** Accepted
**Module:** `App\Modules\Calendar` (`Contracts\CalendarSource`, `Services\CalendarSourceRegistry`,
`Services\CalendarQueryService`, `Services\CalendarTimezoneResolver`, `Services\CalendarInstantResolver`,
`Services\CalendarEventService`,
`Models\CalendarEvent`, `Sources\EventCalendarSource`, `DTOs\{CalendarWindow,CalendarOccurrence,
CalendarResult,CalendarSourceResult,CalendarTruncation}`), plus the two source modules that feed it
(`App\Modules\Tasks\Calendar\TaskDeadlineCalendarSource`,
`App\Modules\Workflows\Calendar\{WorkflowRunCalendarSource,WorkflowScheduleCalendarSource}`) and the
one workflow step that writes into it (`App\Modules\Workflows\Steps\CreateEventStep`)
**Relates to:** ADR-0009/ADR-0012 (the schedule compiler this module projects but never names),
ADR-0015 (polymorphic creator — how a run-created event is attributed), ADR-0043 (the Knowledge
module — the sibling "low module with a hard boundary" this one follows the same shape as),
`docs/backend/calendar-api.md` (the API contract this ADR explains the reasoning behind)

---

## Context

R3's job was a shared timeline for the whole platform, fed from day one by two existing modules
(Tasks' deadlines, Workflows' schedules and run history) and explicitly required to accept a third
— R4 Publishing's publication queue — **without editing the Calendar module to do it**. The plan's
own original wording for the backend ("model wydarzenia … powiązanie polimorficzne ze źródłem") and
its own risk note ("pilnować, by kalendarz był projekcją, a nie drugim źródłem prawdy") were, on
inspection, in tension with each other: the first phrase — a polymorphic tie *from* an event *to* a
source row — reads naturally as "materialize what a task/schedule looks like on a given day into a
`calendar_events`-shaped row," which is exactly the second source of truth the risk note warns
against. Chasing that phrase literally would have produced a cron job writing occurrence rows to a
table, and every failure mode that shape implies: a task's deadline moves and the materialized row
does not follow it; a workflow is disabled and its materialized future occurrences become zombies
nothing ever sweeps.

## Decisions

**D1 — the Calendar is a LOW module with a registry, not a module that names its sources.** It owns
one contract (`CalendarSource`) and one registry (`CalendarSourceRegistry`); every module with
something to put on a grid implements the contract in its **own** namespace and registers itself
from its **own** provider's `boot()`. `app/modules/Calendar` therefore imports nothing from Tasks,
Workflows, Bot, Forms, Approvals, Disk, Knowledge, Publishing, or Workspaces (the last carve-out is
narrow: it reads the active workspace's timezone through `App\Tenancy\TenantContext`, shared
infrastructure every module already stands on, never through the Workspaces module itself). The
registry is bound as a singleton in `register()`; sources register themselves in `boot()`; Laravel
runs every `register()` before any `boot()`, so provider load order can never race a source against
a registry that does not exist yet. Pinned literally — over file bytes, including comments — by
`CalendarModuleBoundaryTest::test_calendar_module_names_no_source_module`, and the acceptance bar
itself (a fourth source joining with zero Calendar edits) is exercised end to end by
`test_a_new_source_can_join_without_touching_the_calendar_module`, using a throwaway `'publication'`
source that stands in for R4.

**D2 — the plan's "polymorphic tie to the source" wording is REJECTED; `subject_type`/`subject_id`
survives only as a soft, undereferenced pointer.** `CalendarEvent::$subject_type`/`$subject_id` is
stored verbatim and **never** wired to a `morphTo()` relation — the Calendar cannot resolve it to a
class without importing the module that owns whatever it points at, which is precisely the
dependency D1 forbids. A pointer at a deleted or foreign row is inert: nothing ever follows it, so a
bad one costs a dangling deep-link on the client, never a broken read. This is the model's own
doctrine, borrowed deliberately from the Knowledge module's binding table (ADR-0043 D1) rather than
reinvented. What the events grid actually shows for Tasks/Workflows is **not** a materialized row at
all — it is a live projection, computed at read time by each owning module's own `CalendarSource`
implementation, straight from `tasks.deadline` / `workflow_runs` / `workflows.trigger_config`. There
is no writer that turns a task or a schedule into a `calendar_events` row, and there never should be.

**D3 — a calendar grid is drawn in the WORKSPACE's timezone; there is no `tz` request parameter.**
The owner took this **against the planning agent's own recommendation** (the browser's timezone).
Both sides of the trade are real: a per-viewer timezone is the more locally correct answer for any
one person looking at their own list, but a calendar is specifically the screen where two or more
people have to agree on which day something is — "Thursday's post" has to mean the same Thursday for
the person scheduling it and the person publishing it, and a per-viewer zone makes that untrue in a
way neither of them can detect from the UI alone. A `tz` parameter would also have created a second
source of truth for "whose midnight is this" (the workspace's stored setting vs. whatever a client
happened to send), and the two only have to disagree once — in a saved view, a cached response, a
link pasted into chat — for the grid to become unexplainable. The cost is accepted, not hidden: a
traveling user sees the team's day, not their own, on every screen this module feeds. This decision
also settles a question R4 Publishing would otherwise have had to answer on its own arrival, since
"publish at 9:00" has exactly the same ambiguity as "the term is on Thursday" — R4 inherits the
workspace-timezone answer for free rather than re-litigating it.

**D4 — the definitional fence: a calendar event is an annotation on a timeline, and NOTHING ever
executes because one exists.** This is the single most important sentence in this ADR, stated
verbatim in `CalendarEvent`'s own docblock so it cannot drift: no trigger reads `calendar_events`,
no sweep scans it, no job wakes on it, and none ever will. The product already has a scheduler — the
Workflows module's `trigger_config`/cadence compiler, built across Etap 5.1/R2 — and the moment
anything executes off a calendar row, the Calendar becomes a second scheduler competing with it,
with its own half of the cadence vocabulary, its own timezone story, and its own reasons a thing did
not run. Without this fence, "event" is exactly the kind of word that becomes a drawer for anything
time-shaped within two more chapters — R4 Publishing, in particular, will be tempted to model a
scheduled publish as "an event that fires," and it must not: a scheduled publish is a trigger
(Workflows' domain), and a calendar event **about** that publish is, at most, an annotation a human
or a step chose to also write down.

**D5 — recurrence is out of scope, and not as an economy.** A repeating event needs a projection
engine, and this codebase has exactly one — the workflow schedule compiler, which lives in Workflows
and which the Calendar is structurally forbidden (D1) from naming. Adding a `repeat` column to
`calendar_events` would be the first line of a **second** recurrence engine, maintained beside the
first, with its own DST story and its own definition of "every other Tuesday." A workflow author who
wants a recurring annotation already has the tool: a schedule-triggered workflow with a
`create_event` step. A user who wants to see a schedule's future without triggering anything already
has it too: `workflow_schedule` is a first-class source. What is genuinely missing — a human clicking
"repeat" on a one-off event in the UI — is deferred, deliberately, rather than solved with a
second-rate copy of machinery that already exists one module over.

> **Flagged by ADR-0052 (2026-08-25).** The stated workaround above — "a schedule-triggered workflow
> with a `create_event` step" — **materializes rows**, which is exactly the second-source-of-truth
> shape D2/D4 above reject for every other calendar source, with the matching failure mode: a disabled
> or deleted workflow leaves its already-created `calendar_events` rows behind, and nothing sweeps
> them. ADR-0052 extracted the schedule engine into a shared `App\Support\Recurrence` layer precisely
> so the Calendar can eventually compute a recurring event's own projection instead of relying on this
> workaround — recurrence itself is still deferred, but the reason this workaround looked acceptable
> (no shared engine existed to use instead) no longer holds. See ADR-0052.

**D6 — past and future are two separate sources, never one schedule projected in both directions.**
`WorkflowScheduleCalendarSource` projects only forward from `max(now, window start)`; the past is
served entirely from real rows by `WorkflowRunCalendarSource`. Projecting a schedule backwards would
answer a question nobody asked — "what *would* have fired, if nothing had changed" — and on a
product where a workflow can be edited, deactivated, or can exceed its own run budget and have the
scheduler's slot-consumed doctrine skip it silently (`RunScheduledWorkflowsCommand`), "nothing had
changed" is false on any workspace old enough to matter. A month grid always contains past days, so a
single "schedule" source would misstate history on **every** screen it drew, not just an edge case.
Two ids, two filter chips, two independent caps — and a user who can tell "this happened" from "this
is planned" without being told, because they are visually and semantically distinct sources.

**D7 — `App\Support\Pagination\StagedCursorPaginator` (the reusable cursor paginator introduced in
R1, ADR-0017) was considered for the occurrences endpoint and rejected.** Its contract assumes a
query builder per stage that a cursor can seek against; a projected schedule occurrence has no
row and no builder — it is the output of a PHP loop through the cron engine. Its emission model is
also wrong for this shape: it walks stages **in order**, one exhausted before the next begins, while
an agenda needs occurrences from every source **interleaved by time**. And the endpoint itself is not
a stream to page through — it is a single, bounded-window query (≤ 62 days, ≤ `max_occurrences` rows)
that answers once and reports what it could not fit, which is a fundamentally different contract from
"give me the next page." (See `docs/backend/calendar-api.md` → "Deliberately not paginated" for the
read-side argument in full.)

**D8 — a memoization of schedule compilation was built, measured, and removed.** The obvious
optimization for `WorkflowScheduleCalendarSource` — cache `WorkflowScheduleCompiler::compile()` (and
`LegacyScheduleUpgrader::toV2()`) keyed on the descriptor, since a calendar month-view re-projects the
same schedules on every navigation — was implemented, checked for output parity against the
uncached path, and profiled by the implementing agent (the same profiling pass this ADR draws all of
D8's numbers from — the owner did not run this measurement either). **Per projection, on the dev
machine:** `compile()` cost 0.0053 ms and `toV2()` cost 0.0001 ms, against ~0.29 ms for the whole
projection — **compilation is under 2% of the cost**, and the cache's own `serialize()` key
computation ate a meaningful slice of even that 2%. **End to end, on the same benchmark, the memoized
path measured slower, not faster — roughly 968 ms without the cache versus roughly 1271 ms with it**,
and it was removed. The real cost is the date arithmetic underneath — the cron library's run-date
search and the Carbon timezone conversions around it — which happens per projection no matter what
sits above it, and caching the cheap 2% on top of the expensive 98% cannot move the total, only add
its own overhead on top. This is recorded because the conclusion is counter-intuitive enough that
someone will try it again: the fix belongs *under* the compiler, not around it, and needs its own
measurement plus `WorkflowScheduleSweepDeterminismTest` green before it is attempted
(`WorkflowScheduleService`'s own docblock carries the qualitative conclusion — memoization measured
slower and was removed — but not these exact figures, which live only here).

The optimization that **did** pay off attacks the actual cost instead: `WorkflowScheduleCalendarSource`
projects **fewer** schedules rather than projecting each one more cheaply — a `next_due_at` pre-filter
skips any workflow whose next armed fire is already past the window (proved never to hide a dense
cadence, since a minute-level schedule's `next_due_at` is always at most a minute away; pinned by
`test_a_dense_cadence_is_never_filtered_out_by_the_next_due_pre_filter`), plus the `max_source_items`
work cap that bounds how many workflows are even considered per read. **Measured, not reported**: the
implementing agent ran a disposable benchmarking harness against fixtures built for the purpose — 20
minute-cadence + 20 daily-cadence (dense) automations alongside a sparse batch scaled from 200 to 460,
projected over a 31-day window, median of multiple runs — before and after the pre-filter/item-cap
existed, then removed the probe once the numbers were in hand. Result: a workspace at roughly 500
scheduled automations, mostly sparse, now costs about what 40 did before the pre-filter existed, and a
month-to-month calendar navigation that had degraded to roughly 28 seconds dropped to roughly 800 ms
once the pre-filter and the item cap were in place. **The owner did not measure this** — the owner
decided, on the strength of this measurement, to accept the pre-filter/cap approach; the measurement
itself is the agent's. The harness was disposable and is not part of the committed test suite, so
there is no persisted load-benchmark for this path today (see "Consequences") — a future regression on
this exact path will not raise its own alarm.

**D9 — a response-ceiling cut landing INSIDE one item's series is reported as BOTH
`item_densified` AND an unknown-count `window_trimmed`, never as an exact count alone.**
`WorkflowScheduleCalendarSource` is the only source that *counts* occurrences rather than reading
bounded rows, so it is the only one able to stop mid-series rather than between items — and for a
while it did so silently: the occurrences already emitted kept `dense: false`, and the loss was
reported only if the outer loop happened to reach another workflow afterwards. A workspace with one
busy automation therefore came back with a short series that every signal in the payload called
complete — the query service's own trim of the *merged* set reports an exact count for what it
cut, and that exact, correct-looking number was the only figure a client had, silently standing in
for a loss the source had already taken before the merge ever saw it. The fix decides the split
*before* anything is emitted: the tail of that item's series is sliced off first, so every
occurrence that ships for it is flagged `dense: true` (rolling into `item_densified`), and the
source additionally files a `window_trimmed` with an **unknown** count for the same reason
`TaskDeadlineCalendarSource` does when its own query stops at its own bound — truncations merge per
`(source, kind)`, and merging an unknown count into the query service's exact one poisons the sum
back to unknown, which is the only honest total once part of the loss happened before the count was
ever taken. A precise-but-partial number is a smaller lie than none, and this contract does not
tell that one either.

**D10 — the event's `color` field was removed from the write surface, after shipping; a
manual colour choice is rejected as the shape for future grouping.** This chapter originally
let a human pick one of the six `CalendarColor` values when creating an event, the same
control every other source's colour comes from. The owner challenged that during the R3
commit review, correctly: colour on this grid is a **dictionary of meanings**, not a
palette — a task deadline colours by priority, a run by how it ended, a schedule projection
by being a projection, and each is a fact the source can state about its own subject. An
event has no such fact. A human-picked colour looked exactly like the other three while
meaning nothing, so the same red said "urgent," "failed," and nothing at all, in one grid.
`color` is now `['prohibited']` on `StoreCalendarEventRequest`/`UpdateCalendarEventRequest`
(a 422 naming the field, not a silent drop — a client still sending the old value has to find
out), gone from `CalendarEventDTO` and `CalendarEventResource` entirely (the key is absent,
never `null`), and gone from the `calendar_events` columns on both migrations (an edit to the
original migrations, not a follow-up dropping migration, since this had not shipped to a real
workspace yet). `EventCalendarSource` emits one constant, `CalendarColor::PRIMARY`, for every
event occurrence — deliberately neither `INFO` (belongs to the schedule projection's "this is
only a projection" reading; reusing it would conflate two different statements in one grid)
nor `NEUTRAL` (the *degradation* value `CalendarColor::fromTone()` falls back to for an
unrecognised tone; using it for events would make "this is an event" indistinguishable from
"something could not be interpreted"). The `create_event` workflow step's config lost the same
field for the same reason, refused by the step's own key allow-list like any other foreign
key. **If a future chapter wants events to be visually groupable, the honest shape is a
CATEGORY — a named thing whose colour is the category's own property, chosen once per
category rather than once per event — not a colour picker back on the event row.** Recorded
here so that gap is not read as "missing feature, add a colour field back": that is precisely
the shape this decision rejects. *Planned, not implemented* — no category concept exists yet.

## Alternatives considered

- **The Calendar names its source modules directly** (an `if`/`match` over Task/Workflow/… inside
  the Calendar itself). Rejected — every future chapter with something time-shaped would need to
  edit the Calendar to appear on it, the module would accumulate a dependency on each in turn, and
  the one property worth protecting (a module most other modules want a square on, reasoned about by
  reading only itself) would be gone. No boundary test could even express what "done" looks like for
  this shape, because there would be nothing to test *against*.
- **Materialize projected occurrences into rows via a cron sweep.** Rejected — this is the literal
  risk the plan's own R3 section warned against, restated in D2/D4 above: a moved deadline does not
  propagate to its materialized row, a disabled workflow leaves a zombie occurrence nobody sweeps,
  and the module quietly becomes a second source of truth for data it does not own.
- **Browser/client timezone via a `tz` parameter.** Rejected against the planning agent's own
  recommendation — see D3 for the full argument on both sides.
- **A genuine polymorphic `morphTo()` binding from an event to whatever it is about.** Rejected — see
  D2; it is the dependency D1 exists to forbid, dressed as a convenience.
- **In-module recurrence for calendar events.** Rejected — see D5; it would be a second recurrence
  engine beside the one Workflows already owns, which the Calendar cannot name and therefore cannot
  delegate to cleanly.
- **One `WorkflowScheduleCalendarSource` covering both past and future, projecting the cadence
  backwards for historical days.** Rejected — see D6; a projection cannot know what actually happened
  (edits, deactivation, exceeded run budgets, slot-consumed skips), so it would misstate history on
  every grid that included a past day, which is every grid.
- **`StagedCursorPaginator` for `GET /calendar/occurrences`.** Rejected — see D7; wrong emission
  model (staged, not interleaved) and no builder to seek against for a computed occurrence.
- **A manual per-event colour picker (the shape this chapter shipped with, then removed).**
  Rejected — see D10; it let a human choose from the same vocabulary the other three sources
  use to state a fact, on a subject with no fact to state, so the same colour meant three
  different things across one grid. A future colour-bearing CATEGORY is the accepted shape;
  a picker directly on the event is not.
- **Memoizing `WorkflowScheduleCompiler::compile()`/`LegacyScheduleUpgrader::toV2()`.** Built,
  measured, removed — see D8. The measured numbers are recorded specifically so this is not
  re-attempted without a fresh profile.

## Consequences

- R4 Publishing (or any future time-shaped module) adds itself as a fifth source by implementing
  `CalendarSource` in its own namespace and registering from its own provider — **zero lines change
  under `app/modules/Calendar`**, and `CalendarModuleBoundaryTest` fails the build if that stops
  being true.
- The "nothing executes because an event exists" fence (D4) **is enforced by an automated
  guard** — `tests/Feature/CalendarEventFenceTest.php`, which quotes this very paragraph (in an
  earlier revision, before the guard existed) in its own docblock as the reason it was written.
  Four independent doors are checked, because a trigger reading `calendar_events` has four ways to
  spell it: (1) STRUCTURAL — a literal byte scan (comments included) refuses anything outside
  `app/modules/Calendar` naming the table, the `CalendarEvent` model, or `EventCalendarSource`;
  (2) VOCABULARY — `WorkflowTriggerType` is asserted to have no calendar-shaped case, so the
  shortest path to "a workflow that runs when an event exists" is closed at the enum; (3)
  BEHAVIOURAL — the real schedule sweep runs with every SQL query captured, and none may touch
  `calendar_events`; (4) BEHAVIOURAL — writing an event through the API is asserted to dispatch
  nothing and start no run. The needle list under (1) started at **three** and grew a **fourth**:
  the MORPH ALIAS `calendar_event`. `Relation::getMorphedModel('calendar_event')` resolves the
  model class without the caller ever naming it, the table, or the source — the shortest of the
  four doors, and the one the original three left standing open; a reviewer's probe walked straight
  through it while the rest of the fence stayed green. It is the fourth entry in the guard's own
  `READ_NEEDLES` list, closing exactly the door the first three left open. Every scan carries an
  anti-vacuity assertion (a guard that scans nothing
  passes forever and is worse than none), and the byte-scan's allowlist is itself asserted to still
  exist and still contain the needle it was granted, so a deleted carve-out cannot leave an
  invisible hole for whatever file takes its path next.
- Recurrence (D5) and event restore (soft-deleted but with no restore endpoint — see
  `docs/backend/calendar-api.md`) are both explicitly **planned, not implemented**. Neither is
  blocked on anything architectural; both are UI-shaped gaps (a repeat control, a trash screen) that
  were not designed in this batch. Recurrence is no longer blocked on a shared engine either — ADR-0052
  extracted one into `App\Support\Recurrence`; see D5's flagged note above.
- Visual grouping of events by colour (D10) is likewise **planned, not implemented** — no
  CATEGORY concept exists on `calendar_events` or anywhere else in the module today. The gap
  is deliberate, not an oversight: do not close it by adding a `color` column or field back
  onto the event itself, which is the exact shape D10 rejected.
- The `next_due_at` pre-filter's correctness (D8) depends on an invariant maintained entirely on the
  write side — `WorkflowScheduleService::arm()`/`claimDue()` guaranteeing no occurrence exists
  strictly between the instant a schedule was last computed from and its stored `next_due_at`. The
  Calendar leans on that invariant without being able to verify it itself (it cannot name the
  Workflows module to assert against its internals); it is pinned where it is actually maintained,
  by `WorkflowScheduleSweepTest`'s arming tests.
- The 500-automations/28-second-to-800ms figures in D8 were measured by the implementing agent with a
  disposable benchmarking harness (fixtures, not production data; probe removed after the run) — the
  owner's role was deciding on the strength of that measurement, not taking it. Because the harness was
  never committed, there is no standing benchmark for this path: nothing will notice automatically if a
  future change regresses it. If the schedule-projection path is changed again, re-measure before
  trusting either figure to still hold — this ADR records the reasoning and the order of magnitude, not
  a regression gate.
