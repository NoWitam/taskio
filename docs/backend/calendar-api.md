# Backend API: Calendar module

Module: `app/modules/Calendar/`
Auth: every endpoint requires `auth:sanctum` **and** the `X-Workspace-Id` header
(`RequireWorkspace` — a 400 before anything is looked up). The occurrence READ endpoint has
no Policy of its own; it leans entirely on `ResolveWorkspace` having already proven
membership (a non-member gets 403 there). The event WRITE endpoints have a Policy
(`CalendarEventPolicy`).
Tenant scope: `CalendarEvent` uses `TenantAware` — a foreign event 404s at route-model
binding; every source queries through its own module's tenant scope.

Covers R3 B1–B4: the read path (a source registry, a merged/ordered occurrence list, an
honest truncation report), the workspace-timezone decision, and the module's own write
surface — calendar events, including the `create_event` workflow step. Design record:
[`docs/decisions/ADR-0051-calendar-module-design.md`](../decisions/ADR-0051-calendar-module-design.md).

---

## Concepts

### The Calendar knows nobody — how to add a new source

This is the one thing about this module a future author needs before touching anything
else. **The Calendar does not import Tasks, Workflows, or any module that has something to
put on a grid** — pinned literally, over the file bytes including comments, by
`CalendarModuleBoundaryTest`. Everything on the grid arrives through one contract,
implemented by the module that **owns** the subject, registered from **that module's own**
service provider. Adding R4 Publishing as a fifth source must not change one line under
`app/modules/Calendar` — that is the bar the boundary test enforces, and the recipe below is
how you clear it.

**1. Implement `App\Modules\Calendar\Contracts\CalendarSource` in your own module's
namespace** (mirror the shipped sources: `app/modules/Tasks/Calendar/TaskDeadlineCalendarSource.php`,
`app/modules/Workflows/Calendar/WorkflowRunCalendarSource.php`). Three methods:

- `id(): string` — a stable public id (`'task'`, `'workflow_schedule'`). It appears in
  request filters, in every occurrence id, and in saved views — **never rename it** once
  shipped.
- `label(): string` — translated (`__()`), **server-side**. The Calendar has no per-source
  vocabulary of its own; your source hands over prose the filter chip renders verbatim, which
  is what lets the frontend show a chip for a source it has never heard of.
- `occurrences(CalendarWindow $window): CalendarSourceResult` — everything your subject has
  inside the window, plus an honest account of what you left out (see "The truncation
  report" below). Use `CalendarSourceResult::complete($occurrences)` when nothing was cut.

**2. Query ascending, and bound your own query.** The query service sorts the merged set and
trims overflow from the **late** end, so order your own query the same way (`orderBy` your
time column, then `orderBy('id')` for a total order) and `->limit($window->maxOccurrences +
1)` — the `+1` is what lets you *detect* your own overflow rather than silently landing on
the cap and looking complete. A source ordering newest-first would hand the global trim
exactly the rows it discards first and contribute nothing under overflow. If your subject
can repeat per item (a schedule, anything cron-shaped), also respect
`$window->maxOccurrencesPerItem` and `$window->maxItems` — see
`WorkflowScheduleCalendarSource` for the full pattern (a work cap on top of the two answer
caps, because a thousand sparse items can cost real work while contributing almost no
occurrences).

**3. Build occurrences through `CalendarOccurrence::allDay()` or `::timed()`** — whichever
matches your subject's own place in time (see the discriminator warning below). Reuse your
model's own `tone()` enum (`TaskPriority::tone()`, `WorkflowRunState::tone()`, …) through
`CalendarColor::fromTone()` for `color`, so the calendar and your own list screen can never
drift apart. `subjectType` is a **morph alias**, never an FQCN — register one in your own
provider's `Relation::enforceMorphMap()` if the subject doesn't have one already.

**4. Register from your own provider's `boot()`**, lazily:

```php
// YourModuleServiceProvider::boot()
$this->app->make(CalendarSourceRegistry::class)->registerLazy(
    YourCalendarSource::ID,
    fn (): YourCalendarSource => $this->app->make(YourCalendarSource::class),
);
```

`registerLazy` never constructs your source until something actually asks for it — Calendar
boots on every request, so a source with real dependencies must not be built for a request
that never opens a calendar. The registry is bound as a singleton in
`CalendarModuleServiceProvider::register()`, and Laravel runs every `register()` before any
`boot()`, so provider load order can never make your `boot()` race the registry into
existence — `bootstrap/providers.php` lists Calendar first only for readability.

**That is the whole contract.** No allow-list, no config edit, nothing to touch under
`app/modules/Calendar`. `CalendarModuleBoundaryTest::test_a_new_source_can_join_without_touching_the_calendar_module`
is exactly this recipe exercised end to end, with a throwaway `'publication'` source standing
in for R4 — read it as a worked example.

**What you may return, and what you may not:**

- Return only real `CalendarOccurrence`/`CalendarTruncation` objects. The registry
  type-checks your **return value**, not just the call: a source that throws is caught, but a
  source that returns the wrong shape would otherwise throw later, mid-merge, outside every
  handler — and take down every *other* module's occurrences with it. A well-typed result
  full of the wrong things is logged and your source is reported `unavailable`; the rest of
  the grid still renders.
- Never mark `editable: true` unless you also expose a write path and actually gate it
  through a Policy — `EventCalendarSource` is the only source that does today (see
  `CalendarEventResource`'s treatment below), because the Calendar's write surface belongs to
  events. A source with no write path of its own should always answer `false`.
- Never reach back into the Calendar beyond `Contracts`/`DTOs`/`Enums` — those are the whole
  public surface. In particular, do not `morphTo()` or otherwise resolve another source's
  `subject_type`/`subject_id`; a click on an occurrence deep-links to **your own** module's
  route for the subject, built from the alias+id your own source already knows.

### The all-day / instant contract — read this before you render anything

Every occurrence and every event carries `all_day` as a **discriminator**, and it is not
decoration — treat it wrong and a client will draw a term on the wrong day for every viewer
whose clock sits on the other side of the workspace's zone from the author's.

- **`all_day = true`** → `start_date` is a plain calendar day, `'Y-m-d'`, and nothing else.
  It has **no hour and no zone**. **Never parse it into an instant, and never run it through a
  timezone conversion** — the value on the wire *is* the day, in every reader's calendar,
  everywhere. `starts_at`/`ends_at` are `null`.
- **`all_day = false`** → `starts_at` (and optionally `ends_at`) are ISO-8601 **UTC**
  instants. Render them by converting **into** `meta.timezone` (occurrences) or the
  workspace's current timezone (events) — never treat them as already-local.

All three keys (`all_day`, `start_date`, `starts_at`/`ends_at`) are always present in the
response — a shape-stable payload — but **exactly one shape is populated**. A client that
reads `starts_at` unconditionally, or that runs `start_date` through a `Date` constructor
"to be safe," will silently move a deadline by a day for roughly half the world. This is the
single most consequential contract on this page.

### Whose midnight — the workspace timezone, and why there is no `tz` parameter

Every day boundary in a response (`from`/`to`, `start_date` comparisons, and where a timed
occurrence's instant sorts) is reckoned in the **active workspace's** timezone
(`workspaces.timezone`, nullable — `null` inherits `config('app.timezone')`), never the
caller's browser. The response says which one it used, in `meta.timezone`, on every read.

**There is deliberately no `tz` request parameter, and an unknown query parameter is
silently ignored rather than honoured.** A shared calendar is a coordination surface — two
people discussing "Thursday's post" have to mean the same Thursday — and a per-viewer
timezone would make that untrue in a way neither of them could see: a saved view, a cached
response, or a link pasted into chat would carry a `tz` that was true only for whoever
generated it, and the grid would become unexplainable the first time two people disagreed
about it. Letting the client name the zone creates a **second source of truth** for "whose
midnight is this," and the two only have to disagree once — see ADR-0051 for the fuller
argument and the alternative the owner overruled the planner's recommendation to take.

The workspace's timezone is set through the existing workspace endpoint, not a
calendar-specific one: `PATCH /api/workspaces/{workspace}` with `timezone` (a `nullable`
IANA identifier, validated with Laravel's `timezone` rule; an explicit `null` clears it back
to inheriting the app default, an **absent** key leaves it untouched).

### Whose midnight — the write side

The read side above draws every day boundary in the workspace's timezone. The write side has to
agree with it, and the rule is stated in exactly **one** place so the two doors into
`calendar_events` cannot drift apart: `App\Modules\Calendar\Services\CalendarInstantResolver`.

- A `starts_at`/`ends_at` value **with no zone** (`"2026-08-09T14:30"`) is read on the
  **workspace's** clock — the same clock the grid renders in, so a caller's bare "14:30" means
  the same wall time the reader will see.
- A value **with an explicit zone** (`+02:00`, `Z`, a full IANA identifier) is taken **exactly
  as given**, workspace timezone ignored — a caller who already said what they meant is never
  second-guessed.

**Both writers of this table call it.** `StoreCalendarEventRequest`/`UpdateCalendarEventRequest`
(this endpoint) resolve through it, and so does the `create_event` workflow step (full contract
in [`docs/backend/workflows-api.md`](workflows-api.md)) — `Workflows → Calendar` is the one
sanctioned direction across the module boundary (`CalendarModuleBoundaryTest`), and this class is
what a third writer must resolve a zone-less instant through rather than parsing one
independently. Before `CalendarInstantResolver` existed the two doors disagreed: the HTTP
endpoint read a bare instant in the workspace's zone, the workflow step read the identical text
through a bare `Carbon::parse()` on `config('app.timezone')` (`'UTC'`) — so the same "14:30",
written through the two doors, landed two hours apart with nothing anywhere disagreeing out loud.

### The truncation report — three kinds, two counts, and when a count is unknown

A calendar response can be incomplete in three structurally different ways, and a single
`truncated: true` boolean cannot say which — so `meta.truncations` is a list of
`{ source, kind, omitted_occurrences, affected_items }`, one entry per `(source, kind)` pair
that actually lost something:

| `kind`            | What happened                                                                                      | Countable? |
|-------------------|------------------------------------------------------------------------------------------------------|------------|
| `window_trimmed`   | The response-wide occurrence ceiling cut the **late end** of the window. Everything shown is complete up to a point in time; past that point the grid is empty because it was cut, not because nothing is there. | Usually yes — see below. |
| `item_densified`   | **One item** repeats more often than its own per-item budget (a minute-cadence automation against a budget of 64). What is shown is a **sample** of that item's series, and the occurrences themselves also carry `dense: true` individually. | `affected_items` — how many items were sampled. |
| `items_dropped`    | **Whole items** are missing from the **entire** window — not the far end of it. The grid is a complete view of *some* things and no view at all of others. | Rarely — see below. |

**Two separate counts, on purpose: `omitted_occurrences` and `affected_items`.** They count
different **units** — occurrences for a window trim, items (automations, subjects) for a
dropped-item report — and a single `omitted` field would silently change what it meant
between kinds. A client must read the pair, keyed by `kind`, never assume the other is zero.

**Either count may be `null`, and `null` means "genuinely not known" — never zero.** A source
that already stopped loading at its own per-source bound can say "there is more" but not
*how much* more without a second, otherwise-pointless query; reporting a precise-looking
partial figure there would be a quieter lie than reporting none. When the response-wide
merge is what does the cutting (every source's own data reached it), the count **is** exact
— both cases are real and a client has to render both honestly:

```json
// exact — nothing was hidden before the merge
{ "source": "task", "kind": "window_trimmed", "omitted_occurrences": 2, "affected_items": null }

// unknown — the source itself had already stopped loading
{ "source": "task", "kind": "window_trimmed", "omitted_occurrences": null, "affected_items": null }

// items_dropped is usually unknown too — the source fetched one row past its item cap
// purely to detect an overflow, so it knows there ARE more but not how many
{ "source": "workflow_schedule", "kind": "items_dropped", "omitted_occurrences": null, "affected_items": null }
```

**Unknown poisons a sum.** When the same `(source, kind)` loses in more than one place in one
request (rare, but possible for `window_trimmed`), the counts are summed — except that adding
a known figure to an unknown one yields `null`, not the known figure. Reporting the known
half as though it were the whole would be a smaller lie than reporting nothing, and this
module does not tell that one either.

### The unavailable-source report — a reason, not just an id

A source can fail to answer in three structurally different ways, and only one of them is worth
retrying — so `meta.unavailable_sources` is a list of **`{ source, reason }`**, never bare ids
(`CalendarUnavailableReason`):

| `reason`          | What happened | Worth retrying? |
|--------------------|----------------|------------------|
| `failed`             | The source was asked and **threw** — a query failure, a timeout, a module having a bad minute. | **Yes** — the only one of the three. |
| `not_constructed`       | The source could not be brought up at all: its factory threw, or it was registered under an id it does not itself claim (a registration bug — `sources[]` naming an id the server does not know is a separate case, refused as a 422, and never reaches this report). | No — configuration or a broken boot, identical on the next request. |
| `malformed`                 | The source answered with something that is **not** a calendar answer — a well-typed result full of the wrong things — caught at the registry boundary before it could take the whole merge down with a 500. | No — a defect in that source's code; retrying reproduces it exactly. |

```json
"unavailable_sources": [
  { "source": "workflow_schedule", "reason": "failed" }
]
```

**The reason exists to be branched on, not merely displayed.** A client that offers "retry" for
all three, or for none, is wrong in two cases out of three; a bare list of ids could never tell
them apart. **An unrecognised, future fourth `reason` is treated as not retryable** — "we do not
know whether this can recover" is not grounds to promise that it can — but the source is still
named, never dropped for being unrecognised. `unavailable_sources` is always present, even when
empty; its absence must never be read as "nothing is wrong."

### Occurrence identity

Every occurrence's `id` is deterministic and stable across a refresh — `task:{uuid}`,
`workflow_schedule:{workflow uuid}:{iso instant}` — because a **computed** occurrence (a
projected schedule fire) has no database row to key on, and the grid re-fetches on every
navigation. A client keying a list on anything else will see selection/focus flicker for
every computed source.

### Budgets

Four numbers, all visible in the response rather than silently clamping (`config/calendar.php`):

| Config key                              | Env                                      | Default | What it bounds |
|------------------------------------------|-------------------------------------------|---------|----------------|
| `max_window_days`                          | `CALENDAR_MAX_WINDOW_DAYS`                   | 62      | Largest `from`–`to` span one read may ask for (inclusive days). A **refusal** (422 on `to`), never a silent clamp — a narrowed 90-day request would look like a complete 90-day answer. |
| `max_occurrences_per_source_item`             | `CALENDAR_MAX_OCCURRENCES_PER_SOURCE_ITEM`      | 64      | How many occurrences ONE item may contribute before it is flagged `dense`. |
| `max_occurrences`                                | `CALENDAR_MAX_OCCURRENCES`                        | 1000    | Ceiling on ONE response, across every source, applied after the merged set is sorted (so what survives is the **earliest** part of the window). |
| `max_source_items`                                  | `CALENDAR_MAX_SOURCE_ITEMS`                          | 200     | How many source ITEMS (automations, subjects) one source may examine per read — a work cap, not an answer cap: a thousand sparse automations that each contribute one occurrence never trip `max_occurrences`, yet each still costs a real query/projection. |

---

## Resource shapes

### CalendarOccurrenceResource — one item on the grid, from any source

```json
{
  "id": "task:0f1e...",
  "source": "task",
  "editable": false,
  "all_day": true,
  "start_date": "2026-08-09",
  "starts_at": null,
  "ends_at": null,
  "title": "Ship the thing",
  "color": "danger",
  "badge": { "label": "W trakcie", "color": "warning" },
  "dense": false,
  "cadence_label": null,
  "subject": { "type": "task", "id": "0f1e..." }
}
```

```json
{
  "id": "workflow_schedule:9ab2...:2026-08-16T09:05:00.000000Z",
  "source": "workflow_schedule",
  "editable": false,
  "all_day": false,
  "start_date": null,
  "starts_at": "2026-08-16T09:05:00.000000Z",
  "ends_at": null,
  "title": "Daily digest",
  "color": "info",
  "badge": { "label": "Zaplanowane", "color": "info" },
  "dense": true,
  "cadence_label": "Every 5 min",
  "subject": { "type": "workflow", "id": "9ab2..." }
}
```

`badge` is already translated prose from the owning source, not a code — the same "no
frontend change per source" property the label carries. `subject` is a morph alias + id for
deep-linking; it is **not** dereferenced or expanded, so a client follows it to the owning
module's own route.

`cadence_label` is **optional, translated prose** — "Every 5 min", "Every 2 h, 09:00–17:00" — the
key is always present but is `null` most of the time (an occurrence with nothing to say about
its own repetition, which is every occurrence outside a densified `workflow_schedule` item).
It exists to make `dense` sayable: `dense: true` alone can only mean "there is more of this than
you see," and a client with nothing else to render is left writing "Series — showing 64," which
tells a reader nothing they could not have counted. It is populated **only** by the two INTERVAL
schedule time-modes (`every_minutes`, `every_hours`) — never by `at` (a list of fixed wall-clock
times), because an honest sentence for `at` would also have to name the day/month axes ("daily at
09:00" is a lie for a schedule whose day axis is `month_days: [1]`), which is a full
schedule-to-prose engine, not a density marker. Computed once per workflow — not per occurrence —
by `App\Modules\Workflows\Support\ScheduleCadenceLabel`.

### The `event` source's colour is a constant, and which constant is a decision

Every other source colours an occurrence by a **fact** it knows about its subject: a task
deadline by priority, a run by how it ended, a schedule projection by being a projection. An
event states no such fact — it is an annotation, and whatever it might be *about* is already
its own square on the grid if it is on the grid at all. So `EventCalendarSource` emits the
**same** `CalendarColor` for every event occurrence it ever returns:

```json
{
  "id": "event:6c3a...",
  "source": "event",
  "editable": true,
  "all_day": false,
  "start_date": null,
  "starts_at": "2026-08-10T14:00:00.000000Z",
  "ends_at": "2026-08-10T15:00:00.000000Z",
  "title": "Sprint review",
  "color": "primary",
  "badge": null,
  "dense": false,
  "cadence_label": null,
  "subject": { "type": "calendar_event", "id": "6c3a..." }
}
```

The value is `primary`, and deliberately neither of the two obvious alternatives:

- **`info`** belongs to the schedule projection ("this has not happened yet") — reusing it for
  an event would say two different things in the same grid.
- **`neutral`** is the *degradation* value `CalendarColor::fromTone()` falls back to when a
  source hands over a tone nobody recognises. Colouring events with it would make "this is an
  event" indistinguishable from "something could not be interpreted."

`badge` is always `null` for this source too — an event carries no state to badge, and a chip
repeating the colour would be decoration standing where a fact goes on every other source.

**This is also why the write side (below) accepts no `color` at all.** A human picking from
the same six values a task/run/schedule use would be decorating with a vocabulary that states
nothing for an event — in one grid, red meant "urgent," "failed," and nothing at all. If a
future chapter needs events to be visually groupable, the honest shape is a **category** (a
named thing whose colour is the category's own property), not a colour back on the event row
— *planned, not implemented*; see ADR-0051 D10.

### CalendarEventResource — one event, in full (the editor's payload)

```json
{
  "id": "6c3a...",
  "title": "Sprint review",
  "description": null,
  "all_day": false,
  "start_date": null,
  "starts_at": "2026-08-10T14:00:00.000000Z",
  "ends_at": "2026-08-10T15:00:00.000000Z",
  "subject": null,
  "creator": { "type": "user", "id": "...", "name": "..." },
  "is_owner": true,
  "can_be_edited": true,
  "can_be_deleted": true,
  "created_at": "2026-08-08T10:00:00.000000Z",
  "updated_at": "2026-08-08T10:00:00.000000Z"
}
```

Note the asymmetry with the occurrence, and it is intentional: on the **grid**, an event's
`subject` is the event itself (what a click should open). Here, `subject` is what the event
**refers to** — an optional pointer, not dereferenced, `null` when the event stands alone.
`is_owner` is human authorship only (`false` for a run-created event, which is owned by
nobody per ADR-0015); gate UI actions on `can_be_edited`/`can_be_deleted`, which already fold
in the workspace-owner fallback.

---

## Endpoints

All under `/api/calendar`, behind `auth:sanctum` + `RequireWorkspace`.

### `GET /api/calendar/occurrences` — the one read endpoint

Everything on the grid, from every registered source, merged and ordered (by day, all-day
before timed, then by time). **Deliberately not paginated** — a window is bounded by its own
dates (≤ 62 days) and by `max_occurrences`; a cursor over a set merged from independent
sources has no stable seek key (computed occurrences have no row to page from). What does not
fit is *reported*, not paged.

| Query param  | Required | Notes |
|--------------|----------|-------|
| `from`         | yes      | `Y-m-d`, inclusive. An instant (`...T00:00:00Z`) is refused, not accepted-and-truncated. |
| `to`               | yes      | `Y-m-d`, inclusive, `>= from`; span ≤ `max_window_days`. |
| `sources[]`         | no       | Restrict to these source ids. **Absent means every source** — an empty filter is not an empty selection. An id the server does not know is a 422 (`sources.0`), never a silently-served subset. |
| `q`                     | no       | Free text; each source decides what "matching" means for its own subjects (a run/schedule matches its **workflow's** name, since neither has a name of its own). |

```
GET /api/calendar/occurrences?from=2026-08-01&to=2026-08-31&sources[]=task&sources[]=event
```

```json
{
  "data": [ /* CalendarOccurrenceResource[] */ ],
  "meta": {
    "timezone": "Europe/Warsaw",
    "truncated": false,
    "truncations": [],
    "sources": [
      { "id": "event", "label": "Wydarzenia" },
      { "id": "task", "label": "Zadania" },
      { "id": "workflow_run", "label": "Uruchomienia" },
      { "id": "workflow_schedule", "label": "Zaplanowane" }
    ],
    "unavailable_sources": []
  }
}
```

`meta.sources` is the **full catalogue** — every registered source, always, independent of
the `sources[]` filter — so a filter chip the user just switched off never disappears from
the UI with no way back on. `meta.unavailable_sources` names a source that was asked for and
could not answer, **with the reason** (see "The unavailable-source report" below); the rest of
the grid still rendered ("nothing scheduled" and "the schedule source is broken" are different
facts a user is entitled to tell apart).

**Denials**

| Code | When |
|------|------|
| 401  | No authenticated user. |
| 400  | No `X-Workspace-Id` header — refused before anything is looked up (the sources' own tenant scopes would otherwise go inert and aggregate across workspaces). |
| 403  | Authenticated, but not a member of the named workspace (`ResolveWorkspace`). |
| 422  | `to` — malformed date, `to < from`, or the span exceeds `max_window_days`. `from` — malformed/not a plain day. `sources.0` — an unknown source id. |

### `POST /api/calendar/events` — create

Any workspace member may create an event (`create` is unconditional in `CalendarEventPolicy`
— a shared calendar's write floor is membership).

| Field           | Required                      | Notes |
|------------------|--------------------------------|-------|
| `title`             | **yes**                          | string, max 255. |
| `description`         | no                                 | string, max 5000. |
| `all_day`               | **yes**                              | boolean — the discriminator, **never inferred** from which date fields are present. |
| `start_date`               | required iff `all_day=true`, **forbidden** otherwise | `Y-m-d`. |
| `starts_at`                   | required iff `all_day=false`, **forbidden** otherwise | any parseable instant, normalized to UTC. **A value with no zone is read in the workspace's timezone; an explicit offset always wins** — see "Whose midnight — the write side" below. |
| `ends_at`                        | no, only valid when `all_day=false`  | must be `>= starts_at`; the same zone rule as `starts_at`. |
| `subject_type` / `subject_id`          | no, **both-or-neither**                    | an optional pointer stored **verbatim**, never validated for existence and never dereferenced (see "Concepts" in the event model's own docblock) — a stale pointer is an inert dangling deep-link, not a broken calendar. |

Both halves of the discriminator are enforced: an all-day payload carrying `starts_at`, or a
timed payload carrying `start_date`, is a 422 naming the offending field — an
**over-complete** payload is refused rather than silently narrowed, because a caller that
believes it stored a time on an event the grid renders as a day has no way to discover the
disagreement otherwise.

**There is no `color` field, and sending one is a 422 — not a silent drop.** `color` is
`['prohibited']` on both `StoreCalendarEventRequest` and `UpdateCalendarEventRequest`: present
and non-empty fails validation naming the field (`calendar.validation.color_not_accepted`),
absent is fine. See "The `event` source's colour is a constant" above (under "Resource
shapes") for why an event has no colour of its own to accept in the first place. Refused
rather than quietly ignored, deliberately — a client still sending the old value believes it
is setting a colour, and dropping it silently would leave that client correct-looking and
wrong.

Returns `201` with a `CalendarEventResource`.

**Denials**: 401 / 400 as above (any member may create, so 403 does not occur here); 422 per
the table (`calendar.validation.*` message keys).

### `GET /api/calendar/events/{event}` — show

`CalendarEventResource`. A foreign-workspace `{event}` 404s at route binding. `view`
requires only an authenticated user (workspace membership is the real gate, proven upstream).

### `PUT /api/calendar/events/{event}` — update

**Same full rule set as create** — this is a whole-event write, not a patch. The all-day
discriminator decides which columns carry the event's place in time, so a partial update that
changed only `all_day` (or only `starts_at` on an all-day event) would have to guess at the
other side; sending the whole event means the caller has already answered that.

**Denials**: 403 unless the caller is the event's creator **or** the active workspace's
owner (`CalendarEventPolicy::update` — the same widened rule `KnowledgeBasePolicy` uses for a
shared, non-personal asset: an event left by someone who has since left the workspace must
still be correctable, or a shared calendar stops being trusted). A workflow-run-created event
has no human owner, so this is also what keeps it editable at all.

### `DELETE /api/calendar/events/{event}` — soft delete

`204`. Same authorization as update. Reversible (the row survives, scoped out by
`SoftDeletes`) but **there is no restore endpoint yet** — the trash exists so a mis-click on
a shared calendar is not permanent, but a full trash UI is a screen nobody has designed, and
an endpoint with no screen would be a contract kept for free. *Planned, not implemented.*

**No `GET /api/calendar/events` (list).** Events are read **on the grid**, through the
occurrences endpoint above, merged with every other source — a second list would be a second,
differently-filtered answer to "what is on the calendar."

### Workflow step `create_event`

Documented alongside the other step types in
[`docs/backend/workflows-api.md`](workflows-api.md) → "Steps" → `create_event` — see there
for the full config/output/error contract. In one line: it writes through this module's own
`CalendarEventService`, so a workflow-created event is indistinguishable in shape from a
hand-made one, and it is attributed to the executing run (ADR-0015), never to whoever
triggered it.

---

## Authorization

`CalendarEventPolicy` — workspace membership is enforced upstream (`ResolveWorkspace`), so
this gates only what it has to:

- `viewAny` / `view` — any authenticated user (reads are never creator-gated; a calendar that
  hid squares from a workspace's own members would not be a coordination surface).
- `create` — any workspace member.
- `update` / `delete` — the event's creator, **or** the active workspace's owner. Wider than
  the plain `HasCreator` ownership check on purpose: an event is a shared, non-personal
  artifact, and the workspace owner is the one person accountable for correcting one left by
  someone who has since moved on.

The occurrence READ endpoint has **no Policy at all** — deliberately; see
`CalendarWindowRequest`'s own docblock. There is no calendar model to authorize against, and
inventing one would add a second, weaker answer to a question `ResolveWorkspace` already
answered.

---

## Related files

- `app/modules/Calendar/` — module root (contract, registry, the module's own `event` source
  and write surface)
- `app/modules/Calendar/Contracts/CalendarSource.php` — the contract every source implements
- `app/modules/Calendar/Services/CalendarSourceRegistry.php` — registration, lazy resolution,
  fail-soft skip-and-report
- `app/modules/Calendar/Services/CalendarQueryService.php` — fan-out, merge, order, trim,
  truncation attribution
- `app/modules/Calendar/Services/CalendarTimezoneResolver.php` — the workspace-timezone
  decision (the read side)
- `app/modules/Calendar/Services/CalendarInstantResolver.php` — whose clock a zone-less instant
  is on when writing (the write side); the one rule both `StoreCalendarEventRequest` and
  `CreateEventStep` resolve through
- `app/modules/Calendar/Services/CalendarEventService.php` — the only writer of
  `calendar_events`
- `app/modules/Calendar/DTOs/{CalendarWindow,CalendarOccurrence,CalendarResult,
  CalendarSourceResult,CalendarTruncation,CalendarBadge,CalendarEventDTO}.php`
- `app/modules/Calendar/Enums/{CalendarColor,CalendarTruncationKind,CalendarUnavailableReason}.php`
- `app/modules/Calendar/Sources/EventCalendarSource.php` — the Calendar's own subject, mapped
  through the identical public contract
- `app/modules/Calendar/Models/CalendarEvent.php` — the all-day invariant and the "event is an
  annotation, nothing executes because it exists" fence, stated in full
- `app/modules/Calendar/Policies/CalendarEventPolicy.php`
- `app/modules/Calendar/Http/Controllers/{CalendarEventController,CalendarOccurrenceController}.php`
- `app/modules/Calendar/Http/Requests/{CalendarWindowRequest,StoreCalendarEventRequest,UpdateCalendarEventRequest}.php`
- `app/modules/Calendar/Http/Resources/{CalendarEventResource,CalendarOccurrenceResource}.php`
- `app/modules/Calendar/routes/api.php`
- `app/modules/Tasks/Calendar/TaskDeadlineCalendarSource.php` — an example source living in the
  module that owns the subject
- `app/modules/Workflows/Calendar/{WorkflowRunCalendarSource,WorkflowScheduleCalendarSource}.php`
  — the past/future split, the `next_due_at` pre-filter (see ADR-0051 for the measured
  cost argument), and the `item_densified`/unknown-`window_trimmed` double-report when the
  response ceiling cuts inside one item's series
- `app/modules/Workflows/Support/ScheduleCadenceLabel.php` — the `cadence_label` prose
- `app/modules/Workflows/Steps/CreateEventStep.php` — the `create_event` step (contract
  documented in `docs/backend/workflows-api.md`)
- `config/calendar.php` — every budget, with its own arithmetic justification
- `lang/{en,pl}/calendar.php`
- `tests/Feature/CalendarModuleBoundaryTest.php` — the pinned boundary + the "add a source"
  recipe exercised as a test
- `tests/Feature/CalendarEventFenceTest.php` — the D4 "nothing executes" fence, executed
  (four independent doors, structural + behavioural)
- `tests/Feature/CalendarOccurrencesTest.php` — the whole read path
- `tests/Feature/CalendarEventTest.php` — the write surface
- `tests/Feature/CalendarEventWorkflowStepTest.php` — the `create_event` step
- `docs/decisions/ADR-0051-calendar-module-design.md` — the design record (rejected
  materialization, the timezone decision, the event definitional fence and its automated
  guard, the measured memoization that was removed)
