# Backend API: Calendar module

Module: `app/modules/Calendar/`
Auth: every endpoint requires `auth:sanctum` **and** the `X-Workspace-Id` header
(`RequireWorkspace` — a 400 before anything is looked up). The occurrence READ endpoint has
no Policy of its own; it leans entirely on `ResolveWorkspace` having already proven
membership (a non-member gets 403 there). The event WRITE endpoints have a Policy
(`CalendarEventPolicy`).
Tenant scope: `CalendarEvent` uses `TenantAware` — a foreign event 404s at route-model
binding; every source queries through its own module's tenant scope.

Covers R3 B1–B6: the read path (a source registry, a merged/ordered occurrence list, an
honest truncation report), the workspace-timezone decision on both the read and write sides,
and the module's own write surface — calendar events, including the `create_event` workflow
step, **B4**'s scope-based write surface for a repeating event (`recurrence`,
`scope`/`occurrence_date` on `PUT`/`DELETE` — see "Recurrence on write" and "Scope" under
"Concepts" below, and the expanded `PUT`/`DELETE` sections under "Endpoints"), and **B5**'s
read-side projection: every occurrence a series places inside the window, not only its
anchor. **B6 is a documentation-only pass**: it writes down B4's write surface, which had
already shipped and was already covered by tests — nothing about the module's behaviour
changed to produce this pass, including the one pre-existing side effect it newly documents
that a previous documentation pass deliberately deferred rather than describe piecemeal
(re-stamping a series' timezone, "When a write re-stamps the clock" under "Concepts"
below — since narrowed to writes that actually move the anchor, see the note there).

A UX specification review of B5's own wire shape
([`docs/next/calendar-uxui-spec.md`](../next/calendar-uxui-spec.md) §24.15) surfaced three
gaps and this page was updated to match the additive fix for each: **L8** — an occurrence had
no way to say "I was computed from a repeating rule" other than a `cadence_label` that is
legitimately allowed to be `null` for a repeating subject, closed by two new always-present
keys, `recurring` and `occurrence_date` (see "Resource shapes" below); **L9** — the event
resource carried the machine-readable `recurrence` rule but no translated sentence for it
unless a reader had arrived by clicking a square, closed by `recurrence_label`; **L10** — a
yearly preset (a monthly rule confined to one month) rendered as a monthly sentence, true
word for word and misleading on the single most human preset this grammar serves — a
birthday, an anniversary — closed by a yearly form in `CalendarCadenceLabel`. None of the
three changed an existing key's meaning or removed one; every occurrence and every event
already on the wire keeps reading exactly as this page described before. Design records:
[`docs/decisions/ADR-0051-calendar-module-design.md`](../decisions/ADR-0051-calendar-module-design.md)
(the module boundary, the event definitional fence) and
[`docs/decisions/ADR-0052-shared-recurrence-layer.md`](../decisions/ADR-0052-shared-recurrence-layer.md)
(the shared cadence engine this projection and Workflows' `schedule` trigger both project
through).

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

### Recurrence on write — the narrow subset, and what is refused rather than silently dropped

`recurrence` is accepted on **both** `POST` and `PUT`
(`StoreCalendarEventRequest`/`UpdateCalendarEventRequest`) — absent or empty means the event
happens once, which is what every payload written before this rule existed says and keeps
meaning.

**What a caller may set** — the same narrow subset `CalendarRecurrence` accepts and nothing
wider:

| Key | Shape | Notes |
|-----|-------|-------|
| `recurrence.day.mode` | `every_day` \| `weekdays` \| `month_days` \| `special` | absent axis = every day |
| `recurrence.day.weekdays[]` | `int` 0–6, distinct, 1–7 entries | `mode=weekdays`; `0` = Sunday |
| `recurrence.day.days[]` | `int` 1–31, distinct, 1–31 entries | `mode=month_days` |
| `recurrence.day.special` | `last_day` \| `nth_weekday` \| `last_weekday` | `mode=special` |
| `recurrence.day.ordinal` | `int` 1–5 | `special=nth_weekday` |
| `recurrence.day.weekday` | `int` 0–6 | `special` ∈ `{nth_weekday, last_weekday}` |
| `recurrence.month.mode` | `every_month` \| `months` | absent axis = every month |
| `recurrence.month.months[]` | `int` 1–12, distinct, 1–12 entries | `mode=months` |
| `recurrence.exclusions.dates[]` | `Y-m-d`, distinct, **max 50** (`EXCLUSIONS_DATES_MAX`) | days the series skips |
| `recurrence.until` | `Y-m-d`, `>=` the anchor day | the series' end — **or** `count`, never both |
| `recurrence.count` | `int` 1–366 (`calendar.recurrence_count_max`) | resolved to a day **once, at write time** — see below |

**What is refused with a 422 — never accepted silently, and never dropped:**

- **`recurrence.time`** — the series' one wall-clock hour is the event's own `starts_at` hour
  (or the shared engine's day anchor, for an all-day series). A caller sending its own could
  send one that disagrees with the anchor, so the key is `prohibited` and the hour is
  server-authored.
- **`recurrence.tz`** — this module has no `tz` parameter anywhere ("Whose midnight" above),
  and a series is no exception: the zone is stamped server-side, from the workspace's own
  timezone, when the series is laid out — see "When a write re-stamps the clock" immediately
  below for exactly which writes count as laying it out.
- **`recurrence.exclusions.months` / `recurrence.exclusions.weekdays`** — a month or weekday
  exclusion is already the complementary set on the day/month axis; accepting the key would
  let a rule exclude a whole dimension it never named in the first place.
- **Any key inside `recurrence` or `recurrence.exclusions` outside the table above** —
  reported on `recurrence.<key>`, naming the field. This is only possible because the stored
  descriptor is **rebuilt from named keys, never filtered from the input**: before this check
  existed, `{"freq": "WEEKLY", "interval": 2}` was accepted and silently stored as "every
  day, forever."

Refused rather than dropped throughout, for the same reason `color` is (see "The `event`
source's colour is a constant" above): a caller sending one of these believes it is setting
something, and dropping it quietly would leave that caller correct-looking and wrong.

**The anchor must satisfy the rule — this is what makes "start" mean "first occurrence."** The
event's own `start_date`/`starts_at` is required to be the rule's **first occurrence**,
checked by one engine call (`CalendarRecurrenceService::anchorIsFirstOccurrence()`). A
Monday-anchored event carrying a "fires on Tuesdays" rule is a 422 **on the start field**
(`anchor_not_an_occurrence`), never a series whose own first square lands a week after the row
says it starts.

**A series must fall on at least one day, which the anchor check does not prove on its own.**
The anchor check proves the bare cadence fires at the anchor; a series is that cadence *minus*
its exclusions, bounded by `until` — and can still have nowhere left to land. A
weekly-Mondays rule anchored on the 7th, excluding the 7th, ending on the 10th passes the
anchor check and draws nothing. Caught separately (`series_has_no_occurrences`, reported on
`recurrence.until` or `recurrence.exclusions.dates` — whichever the caller can actually
relax); without it the event would save with a `201` and simply never appear on the grid.

**"Repeat N times" is not what comes back as "N times."** `recurrence.count` is walked to a
real calendar day **once, at write time**, and only the resulting `recurrence.until` is ever
persisted — the row has no `count` column, and `CalendarEventResource` never returns one. A
client that wants to keep showing "10 times" in its own UI has to keep that number on its own
side; the server does not remember it. The same fact has a second consequence, worth stating
before a client builds a counter around it: **deleting one occurrence afterwards does not
extend the series to make up for it.** Removing an occurrence is an exclusion (see "Scope"
below), not a decrement — a series resolved from "10 times" that has since had two occurrences
deleted still ends on the day the original ten resolved to, and now draws eight squares before
that day, not ten.

**`count` counts SQUARES, not firings, and daylight saving is where that distinction is paid
for.** The walk is collapsed by the same rule the grid is: a series' hour that falls inside the
hour a zone *repeats* fires twice on a fall-back day and still draws **one** square, and a
series' hour that a zone *skips* on a spring-forward day draws **none**. So `count=5` resolves
to a `recurrence.until` on which the grid draws exactly five squares in every timezone —
including one whose fold is half an hour (`Australia/Lord_Howe`) or lands at midnight
(`America/Havana`). A client that predicts the end date itself by adding N cadence steps to the
start will disagree with the server on those two days a year; read `recurrence.until` off the
response rather than computing it.

A timed series also has to start on a whole minute (`starts_at`, `whole_minute`) — the grid it
projects onto is minute-granular, so a `starts_at` carrying seconds could never be one of its
own occurrences. Unreachable from a `TimePicker`-shaped client; listed here for completeness,
not because a UI needs to guard against it.

### When a write re-stamps the clock — only when it moves the anchor

**A series' `tz` is stamped when the series is *laid out*, and a write lays it out only when it
MOVES the anchor. A write that leaves the anchor where it is keeps the clock the series
already carries — including a write that changes nothing about the rule at all.**

Which anchor a write is judged against depends on what the write is:

| The write | The anchor it is judged against | Stamps |
|-----------|--------------------------------|--------|
| `POST` | — (nothing exists yet) | the workspace's **current** timezone, always |
| `PUT`, `scope=series`, on a row that already repeats | the row's own `starts_at` (timed) / `start_date` (all-day) | the row's **existing** `tz` when the payload names the same one; the workspace's current timezone when it does not |
| `PUT`, `scope=series`, on a row that does **not** repeat yet | — (a rule is being added, so the series is new) | the workspace's **current** timezone |
| `PUT`, `scope=following` | the occurrence named by `occurrence_date` | the old series' **existing** `tz` when the payload starts exactly there; the workspace's current timezone when it starts elsewhere |
| `PUT`, `scope=occurrence` | — (`recurrence` is `prohibited`; the detached row never repeats) | nothing |
| the *old* half of any split, and `DELETE` at any scope | — | nothing (`applySeries()` only patches `exclusions`/`until` onto the existing descriptor) |

A payload that **flips `all_day`** has moved the anchor by definition and always re-stamps. A
timed anchor is compared as an **instant**; an all-day anchor as a zone-free **day** — the two
are not interchangeable, and comparing an all-day series on instants would mix two clocks.

**Why not simply re-stamp on every write.** It was the behaviour that shipped, and it made a
series **uneditable** after a workspace changed its timezone. The stamped zone rules how a
series is *read*; the workspace's current zone ruled how it was *re-validated on save*, and
nothing reconciled the two. A series anchored early on a Monday is anchored on a **Sunday**
six hours west — so a `PUT` that changed only `title`, echoing `recurrence` back verbatim
exactly as the resource emits it, came back **422 on `starts_at`**, about a field the user had
not touched. Both ways out lost data (moving the start erases the series' past; choosing
another preset rewrites the cadence), and the client could not rescue it either — it stops
recognising its own rule and sends it back unchanged.

**Why re-stamp at all, then.** A **single** timed event stores an absolute UTC instant, so
changing the workspace's timezone already moves that event's displayed wall-clock hour without
anyone touching the row. A series whose author moves its start is being written again, and
stamping the current zone there gives it the same behaviour rather than freezing it forever on
a zone the workspace abandoned.

**What a caller can now rely on:**

- **A `PUT` that does not move the start is byte-stable in the rule.** `tz` and `time.at` come
  back exactly as they went in, so echoing `recurrence` verbatim — the documented round trip
  (see "`recurrence` ROUND-TRIPS" under "Resource shapes" above) — is always accepted, on any
  workspace timezone. `recurrence_timezone` in the response is unchanged.
- **A `PUT` that moves the start re-derives both.** `time.at` becomes *the new anchor instant
  expressed in the workspace's current timezone* (`$anchor->setTimezone($tz)->format('H:i')`),
  `tz` becomes that timezone, and `recurrence_timezone` in the response changes to match.
  Every occurrence other than the anchor day's therefore moves to the **new** zone's
  wall-clock hour — which is what "I am re-laying this series out" means.
- **An all-day series** follows the same rule on its zone-free day. Its projection is day-shaped
  and never converts an instant (see "The DAYS an ALL-DAY series falls on" above), so which days
  it falls on does not move either way — but its `recurrence_timezone` no longer changes behind
  a caller's back on an unrelated edit, which it silently did before.

**Why this matters for `occurrence_date`, specifically.** A client derives the day it sends
back as `occurrence_date` for a *timed* series by re-expressing `starts_at` in
`recurrence_timezone` (see "A series occurrence" above). That zone is now stable across every
edit that does not move the start, so an `occurrence_date` computed from a `GET` stays valid
across an ordinary save. It is **not** stable across a write that moves the start, and it is
not stable across somebody else changing the workspace's timezone and then moving the start —
so the rule stands: re-read `recurrence_timezone` from the response of the write that just
happened rather than holding one across an edit. A stale one is refused (`not_an_occurrence`)
rather than silently landing on the wrong day.

### Scope — how much of a series a `PUT`/`DELETE` touches

Every write against an **existing** event names a `scope` (`CalendarEventScope`) — absent on
`PUT`/`DELETE` means `series`, and that is not a convenience default, it is the compatibility
guarantee: a request naming no scope behaves **byte-for-byte** as it did before series
existed. `scope`/`occurrence_date` are `prohibited` on `POST` (422 `scope_on_create`) — there
is nothing to narrow when the row does not exist yet.

| `scope` | `PUT` does | `DELETE` does | The past |
|---------|-----------|---------------|----------|
| `series` (default) | rewrites the **whole row**, recurrence included | soft-deletes the row | rewritten/removed with it |
| `occurrence` | adds the day to `recurrence.exclusions.dates` **and** creates a **new, non-repeating** event from the payload — a **detach**, not an override | adds the day to `recurrence.exclusions.dates`; the row stays | untouched |
| `following` | closes the old series the **day before** `occurrence_date` and creates a **new** event from the payload, starting there | closes the old series the day before `occurrence_date` | preserved |

**`occurrence_date`** (`Y-m-d`) is required exactly when `scope ≠ series`, forbidden when it
is `series` (422 `occurrence_date_without_scope` / `occurrence_date_required`), and is
reckoned on the series' own **stamped** clock — see "Occurrence identity" and "A series
occurrence" above, and "When a write re-stamps the clock" above for the writes that can move
that clock. It must name a real, unexcluded day inside the series' own bounds,
or the write is refused (`not_an_occurrence`).

**`occurrence` carries no `recurrence` of its own.** One occurrence of a series is not itself
a series; a `PUT` at `scope=occurrence` that also sends a `recurrence` block is a 422
(`occurrence_has_no_rule`) rather than a nested series nobody asked for. Excluding a day has a
cap too: once a series has 50 excluded dates (`EXCLUSIONS_DATES_MAX`), a further
`scope=occurrence` write against it is refused (`exclusions_full`) — the message names the
actual remedy, splitting the series with `scope=following`, because a series with fifty holes
in it is functionally two series.

**When a `following` split would leave nothing behind** — the common case is
`occurrence_date` naming the series' own first occurrence — it **collapses to `series`**:
`PUT` rewrites the row in place, `DELETE` removes it whole, rather than closing the old series
to an empty, unreachable span. The server decides this with a **projection over the outgoing
date range** (`splitLeavesSomethingBehind()`), never by comparing `occurrence_date` against
the anchor day — a series whose earlier occurrences have all since been individually deleted
still has an anchor before the split and would otherwise be closed to a span containing
nothing. A client cannot reproduce this decision from the fields it already holds and must
not try to predict it.

**The response body under `scope=occurrence`, and usually under `scope=following`, is a
different resource than the one named in the URL — and `PUT`'s status code says so, not just
the body.** `200` means the id in the URL is the id that was rewritten (`series`, and a
`following` split that collapsed above); `201` means a **new** row was created (a detached
occurrence, or the continuation half of a genuine split) — `$written->wasRecentlyCreated`,
set explicitly rather than left to Laravel's own inference, because it is a **contract** here,
not an implementation detail: a client can tell, from the status code alone and without
parsing the body, that the id it is holding has stopped being the one to edit next. `DELETE`
never creates a new row under any scope — every scope acts on the row named in the URL itself
(soft-deleting it under `series` or a collapsed `following`; adding a day to its own
exclusions under `occurrence`; truncating its own `recurrence_until` under an ordinary
`following`) — so `DELETE`'s `204` carries no such distinction to make, and needs none.

`DELETE` reads `scope`/`occurrence_date` through `input()`, so a caller may send them as query
parameters or as a request body — not every HTTP client sends a body on a `DELETE`, and
refusing the query string would make the operation unreachable from some of them.

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

**A recurring `event` files all three, and each has its own exact reading for a series** (not
borrowed automation vocabulary applied loosely — `EventCalendarSource` earns every one of them):

- **`item_densified`** — one series contributed more occurrences to the window than its own
  per-item budget allows. What ships for it is a **sample**, and every occurrence of that series
  — not only the ones past the cap — carries `dense: true`, so a client can tell a sampled square
  from a complete one without comparing it to anything else on the grid.
- **`items_dropped`** — a whole series is missing from the **entire** window, for either of two
  reasons that both land on this same entry: the item budget (`max_source_items`) was already
  spent before the source reached that series, or the response-wide occurrence ceiling was
  already full when the source's per-item loop got to it. Either way there was no budget left to
  draw even one square of it, so there is nothing to flag `dense` and nothing to sample — the
  series is absent, not thinned.
- **`window_trimmed` with a `null` count** — the response ceiling bit **inside** one series
  rather than between two of them: part of that series shipped (flagged `dense: true` along with
  the rest of the item, per `item_densified`), and the rest did not. The count is deliberately
  left unknown rather than computed, because the query service's own trim of the *merged* set can
  count exactly what *it* cut — and merging that exact figure with this source's unknown one is
  what stops a precise-but-partial number from standing in for the whole loss (see "Unknown
  poisons a sum" below).

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

A source can fail to answer in four structurally different ways, and only one of them is worth
retrying — so `meta.unavailable_sources` is a list of **`{ source, reason }`**, never bare ids
(`CalendarUnavailableReason`):

| `reason`          | What happened | Worth retrying? |
|--------------------|----------------|------------------|
| `failed`             | The source was asked and **threw** something that might not happen again — a statement timeout, a dropped connection, a deadlock, a module having a bad minute. Anything thrown that does not identify itself as structural lands here. | **Yes** — the only one that is. |
| `broken`                    | The source was asked and threw a database error naming a **schema rather than a moment**: SQLSTATE class `42` — undefined table (`42P01`), undefined column, invalid statement, insufficient privilege. **A migration that was never run is the ordinary cause.** Classified off `errorInfo`, never off the message (which is driver-specific and translated by the server's locale). | No — the next identical query meets the identical schema. Someone has to migrate or deploy. |
| `not_constructed`       | The source could not be brought up at all: its factory threw, or it was registered under an id it does not itself claim (a registration bug — `sources[]` naming an id the server does not know is a separate case, refused as a 422, and never reaches this report). | No — configuration or a broken boot, identical on the next request. |
| `malformed`                 | The source answered with something that is **not** a calendar answer — a well-typed result full of the wrong things — caught at the registry boundary before it could take the whole merge down with a 500. | No — a defect in that source's code; retrying reproduces it exactly. |

```json
"unavailable_sources": [
  { "source": "workflow_schedule", "reason": "failed" }
]
```

**The reason exists to be branched on, not merely displayed.** A client that offers "retry" for
all four, or for none, is wrong in three cases out of four; a bare list of ids could never tell
them apart. **An unrecognised, future `reason` is treated as not retryable** — "we do not
know whether this can recover" is not grounds to promise that it can — but the source is still
named, never dropped for being unrecognised. `unavailable_sources` is always present, even when
empty; its absence must never be read as "nothing is wrong."

`broken` was split out of `failed` after a live screen described a table that had never been
migrated as "usually temporary — try again", under a button that could not conjure a table. The
split needed no client change precisely because of the unrecognised-reason rule above: `broken`
arrives as a code the frontend does not know, and the frontend's floor for an unknown code is
"name the source, offer nothing" — which is the correct handling for this one.

### Occurrence identity

Every occurrence's `id` is deterministic and stable across a refresh — `task:{uuid}`,
`workflow_schedule:{workflow uuid}:{iso instant}`, `event:{uuid}` for a one-off event and
`event:{uuid}:{Y-m-d}` for one occurrence of a recurring one (see "A series occurrence" below
for the full shape) — because a **computed** occurrence (a projected schedule fire, or a
projected day of a series) has no database row to key on, and the grid re-fetches on every
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
  "recurring": false,
  "occurrence_date": null,
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
  "recurring": true,
  "occurrence_date": null,
  "subject": { "type": "workflow", "id": "9ab2..." }
}
```

`badge` is already translated prose from the owning source, not a code — the same "no
frontend change per source" property the label carries. `subject` is a morph alias + id for
deep-linking; it is **not** dereferenced or expanded, so a client follows it to the owning
module's own route.

`cadence_label` is **optional, translated prose**, and the key is always present but `null` is a
common, ordinary value — an occurrence with nothing to say about its own repetition. What it says
when it *is* populated is a decision each **source** makes about its own subject; there is no one
global rule for when the field is filled, only a shared contract: it is prose translated
server-side, exactly like a source's `label` and an occurrence's `badge`, so a client renders a
"this repeats" marker for a source it has never heard of.

- **`workflow_schedule`** says the **interval** of its two sub-daily time-modes — "Every 5 min",
  "Every 2 h, 09:00–17:00" — and is `null` for every other schedule shape, `at` (a list of fixed
  wall-clock times) included: an honest sentence for `at` would also have to name the day/month
  axes ("daily at 09:00" is a lie for a schedule whose day axis is `month_days: [1]`), which is a
  full schedule-to-prose engine, not a density marker. Computed once per workflow — not per
  occurrence — by `App\Modules\Workflows\Support\ScheduleCadenceLabel`.
- **`event`** says the **day/month cadence** of a series — "Weekly on Mon", "Monthly on the third
  Tue, in January, July" — for **every** occurrence of a recurring event, densified or not, never
  only the sampled ones. It is populated exclusively from the series' `at`-mode time descriptor,
  because a calendar series has exactly one wall-clock hour by construction (see
  `CalendarRecurrence`): there is no sub-daily interval to name, only a day/month rule to put into
  words. Computed once per series — not per occurrence — by
  `App\Modules\Calendar\Support\CalendarCadenceLabel`.

The two readings differ because the two subjects differ, not because one source tried harder:
`workflow_schedule` only has something to say about the *few* sub-daily modes dense enough to need
sampling, and says nothing about the rest; the Calendar's whole recurrence subset has exactly one
hour, so there is never anything sub-daily to name and the sentence is always about the day/month
rule instead.

**For `event` specifically, a non-null `cadence_label` is *sufficient* evidence that a square is
one of many, drawn on this particular day — but it is not *necessary*, and that gap is exactly
why `recurring`/`occurrence_date` exist below rather than being left to inference.**
`EventCalendarSource` populates the same field every repeating subject can populate — no boolean
of its own — so a non-null `cadence_label` on an `event` occurrence does prove it is part of a
series (`dense: true` alone cannot: it only ever means "there is more of this than you see," and
says nothing about the occurrences that are *not* densified, which is the common case, not the
exception — the Calendar's subset has no sub-daily cadence, so even the busiest series the API
accepts fits inside a maximal window without ever being sampled). What a non-null `cadence_label`
cannot do is stand in for the negative: a `null` value does not mean "this does not repeat," only
"this source had nothing to say about its cadence" — and `workflow_schedule`'s own fixed-times
(`at`-mode) projections are exactly that case (see the bullet above: an honest sentence for `at`
would need the day/month axes too, which `ScheduleCadenceLabel` declines to build). A client
branching on the prose alone would call every one of those squares a one-off, silently and
without a way to notice.

A UX specification review named this precisely
([`docs/next/calendar-uxui-spec.md`](../next/calendar-uxui-spec.md) §24.15, **L8**), and it is now
closed by two additive, always-present keys rather than by strengthening the inference — no source
that leaves them at their defaults had to change:

- **`recurring` (bool)** — whether this square was **computed from a repeating rule** rather than
  read from a row of its own: `true` for every `event` series occurrence and for every
  `workflow_schedule` projection, `false` for a task deadline, a workflow run, and a one-off event.
  Stated **outright**, not left to be recovered from `cadence_label`, precisely because that
  inference is sufficient-but-not-necessary: a repeating subject is *allowed* to have no sentence
  (a fixed-times schedule has none), so a client that kept branching on the prose would be right
  about most series by accident and silently wrong about the rest — and would go on being wrong for
  any future source shaped the same way. `recurring` is a fact about what the square *is*, never a
  permission; what a caller may *do* to one occurrence is still answered through `occurrence_date`
  below and the write-side policy, not through this flag.
- **`occurrence_date` (`string|null`, `'Y-m-d'`)** — **which** occurrence of its series this is, as
  the plain day the subject's own write surface addresses it by. Filled by the `event` source
  only, reckoned on the **series' own stamped clock** (the `tz` written into `recurrence` at save
  time) — **never** the active workspace's current timezone, the same rule the occurrence `id`
  already follows (see "A series occurrence" below). `null` for a one-off event *and* for every
  `workflow_schedule` projection, and both are deliberate: nothing addresses one firing of a
  schedule — there is no write path that names a single fire — so publishing a day there would be
  an identifier that points at nothing. For an `event` series it is the exact string a client sends
  back as `occurrence_date` on the scoped `PUT`/`DELETE`, and it is available the instant a square
  is clicked, before the event has been fetched and without parsing `id` — which is precisely the
  thing `subject.id` already exists to make unnecessary, and `occurrence_date` now does for the day
  the way `subject.id` does for the row.

**Both keys are always present, on every occurrence from every source.** Absent-and-null must
never be two cases a client handles differently — the same shape-stability rule `cadence_label`
and the all-day discriminator already follow on this page. A source with no notion of either
simply leaves them at their defaults (`recurring: false`, `occurrence_date: null`); it does not
gain a decision to make.

**The combination a client actually branches on is `editable && recurring`, not either flag
alone.** `editable` is `true` only for the `event` source (see "The Calendar knows nobody"
above) — it gates whether a write path exists at all. `recurring` says whether *this particular
square* belongs to a series rather than being a one-off. **An occurrence that is both editable
and recurring always carries a non-null `occurrence_date`** — that is the one case the key exists
to serve, and it is exactly the moment a client needs to open the scope dialog
(`series`/`occurrence`/`following`, "Scope" below) rather than a plain single-event edit. An
editable, non-recurring occurrence (a one-off event) has nothing to scope and carries
`occurrence_date: null`; a recurring, non-editable occurrence (`workflow_schedule`) has no write
path at all, `editable: false`, and also carries `occurrence_date: null` — the same `null` for
two unrelated reasons, on two shapes a client must not confuse.

### The cadence sentence's yearly form — a monthly rule confined to one month reads as yearly

A **monthly** rule whose month axis names exactly **one** month renders as a **yearly** sentence —
`"Every year on August 25"` / `"Co roku, 25 sierpnia"` — instead of `"Monthly on day 25, in
August"` / `"Co miesiąc, dnia 25 (sierpień)"`. Both sentences are true word for word; the reason to
prefer the second is that the first reads as "again next month" on the single most human preset
this whole grammar exists to serve — a birthday, a wedding anniversary. This was flagged by the
same UX review as **L10**: someone picks the "every year" preset, and the grid tells them it
repeats in four weeks.

Only the **monthly** family collapses this way. `"Daily, in August"` and `"Weekly on Mon, in
August"` keep their own cadence word, because that word is still true *inside* the month it names
— rewriting either as yearly would trade a misleading sentence for a false one. A rule confined to
**two or more** months also stays monthly-with-a-suffix (`"Monthly on day 25, in July, August"`);
the collapse only fires on exactly one month.

`CalendarCadenceLabel` tries this branch **first**, before its ordinary day/month rendering, and
only for the day-axis shapes it would otherwise mislead about: named month-days
(`"Every year on :month :days"`) and the two month-anchored `special` shapes that carry their own
month-suffixed forms (`"Every year on the last day of :month"`, `"Every year on the :ordinal
:weekday of :month"`, `"Every year on :weekday of :month"`). An every-day or weekday-list day axis
returns null from this branch — nothing about them misleads — and falls through to the ordinary
monthly/weekly/daily rendering unchanged.

**A note for maintainers/translators, and why there are now two month catalogues.** Rendering a
month standalone ("August", for the `in_months` suffix list) and rendering it *inside a date*
("August 25") are different grammatical forms in an inflecting language — Polish among them: a
date's month is in the genitive, "25 **sierpnia**", never "25 sierpień". `lang/{en,pl}/calendar.php`
therefore carries **two** month catalogues under `cadence`: `months` (the standalone name, used by
`in_months`) and `months_in_date` (the form a month takes inside a date, used by every `yearly_*`
key and by the month-anchored specials). The two read identically in English and diverge in
Polish — the same reason `last_weekdays` is already written out per-language rather than composed
from a name plus an adjective (see the translators' note in the lang file itself).

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
  "recurring": false,
  "occurrence_date": null,
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

### A series occurrence — same shape, a stable per-day id

Every square a recurring event draws is built through the identical `::allDay()`/`::timed()`
constructors as a one-off event — a client that already understands "an event occurrence"
understands a series occurrence too, with no new vocabulary to learn. Four things distinguish
one, and each answers a question a *repeating* row raises that a one-off row cannot:

- **`id` is `event:{uuid}:{Y-m-d}`** — one segment longer than a one-off event's `event:{uuid}`.
  The day is reckoned on the **series' own stamped clock**, the `tz` written into `recurrence` at
  save time — **never** the active workspace's current timezone — so an occurrence's id cannot
  change out from under a client because a workspace changed its clock between two reads. It is
  also the **same key the write surface names an occurrence by** (`occurrence_date` on the
  scope-based `PUT`/`DELETE`), so the read and write halves of the module identify one occurrence
  identically. That same day is now also published directly, as the occurrence's own
  `occurrence_date` (see "`recurring`"/"`occurrence_date`" above) — a client no longer has to take
  `id` apart to recover it, which is precisely the thing this page has always told a reader not to
  do.
- **`subject.id` is still the ROW's id**, exactly as for a one-off event, so a client opens the
  event drawer for a series square the same way it opens one for a single event — without parsing
  anything out of the occurrence id first.
- **The shape follows the series, never the window.** An all-day series yields all-day
  occurrences (`start_date`, zone-free, never converted — see "The all-day / instant contract"
  above); a timed series yields instants whose duration is the anchor's own `ends_at − starts_at`,
  applied as a **fixed interval** to every occurrence — an hour-long standup stays exactly an hour
  long across a daylight-saving transition, rather than becoming a 55- or 65-minute meeting
  depending on which week it falls in.
- **`editable` is answered once per row, through the policy, and applies to every occurrence of
  that row.** There is no per-occurrence write path of its own — editing or removing a single
  square is a scoped operation against the *row*, keyed by its `occurrence_date` — so
  `editable: true` on one square of a series means every square of that series is open to the
  same caller.

```json
{
  "id": "event:9f2b...:2026-09-14",
  "source": "event",
  "editable": true,
  "all_day": false,
  "start_date": null,
  "starts_at": "2026-09-14T14:00:00.000000Z",
  "ends_at": "2026-09-14T15:00:00.000000Z",
  "title": "Sprint review",
  "color": "primary",
  "badge": null,
  "dense": false,
  "cadence_label": "Weekly on Mon",
  "recurring": true,
  "occurrence_date": "2026-09-14",
  "subject": { "type": "calendar_event", "id": "9f2b..." }
}
```

`dense: true` on an event occurrence means exactly what it means for any other source's item —
**the series was sampled**, either by its own per-item budget or by the response ceiling landing
inside it (see "The truncation report" above). And unlike a one-off event, a series occurrence
always carries a non-null `cadence_label`, `recurring: true`, and (uniquely among today's sources)
a non-null `occurrence_date` — see "`recurring`"/"`occurrence_date`" above for what each of the
three says and why `cadence_label` alone was not enough to say all of it.

### A series draws its past too — deliberately unlike `workflow_schedule`

`workflow_schedule` projects only **forward**, from `max(now, window start)` (ADR-0051 D6). A
recurring event's own source does the opposite: `GET /api/calendar/occurrences` for a window
entirely in the past still draws every occurrence a series had inside it, all the way back to the
series' own anchor.

This is not an inconsistency waiting to be reconciled — the two subjects answer different
questions. A **computed schedule occurrence in the past is a claim about execution**: the
automation may since have been deactivated, edited, or exceeded its run budget with the due slot
consumed and nothing actually run, so projecting it backwards would assert something that may be
false on any workspace old enough to matter. A **calendar event never causes anything to execute**
— by the module's own definitional fence (ADR-0051 D4), nothing reads `calendar_events` to decide
whether to run, so there is no execution history for a projection to disagree with. A weekly
meeting recorded last spring happened exactly as many times as its rule says it did; refusing to
draw those squares would not be caution, it would delete data the row already, unambiguously,
contains. See ADR-0051 D11 for the fuller argument, including why this is one source answering
both directions rather than a past/future split into two sources the way the schedule/run pair is.

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
  "recurrence": null,
  "recurrence_timezone": null,
  "recurrence_label": null,
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

`recurrence`/`recurrence_timezone`/`recurrence_label` are `null` together for an event that
happens once, as above, and populated together for one that repeats — for example, the
"every year on August 25" anniversary from "The cadence sentence's yearly form" above:

```json
{
  "id": "a1c9...",
  "title": "Wedding anniversary",
  "description": null,
  "all_day": true,
  "start_date": "2026-08-25",
  "starts_at": null,
  "ends_at": null,
  "recurrence": {
    "day": { "mode": "month_days", "days": [25] },
    "month": { "mode": "months", "months": [8] },
    "exclusions": null,
    "until": null
  },
  "recurrence_timezone": "Europe/Warsaw",
  "recurrence_label": "Every year on August 25",
  "subject": null,
  "creator": { "type": "user", "id": "...", "name": "..." },
  "is_owner": true,
  "can_be_edited": true,
  "can_be_deleted": true,
  "created_at": "2026-08-08T10:00:00.000000Z",
  "updated_at": "2026-08-08T10:00:00.000000Z"
}
```

**`recurrence_label` is the same translated sentence every square of this series carries as
`cadence_label` on the grid, published on the event itself because the grid is not always
there.** A reader who arrived by clicking a square already has the sentence on the occurrence;
a reader who arrived by deep link, or is looking at a series with no occurrence inside the
window currently on screen (a rule that last fired months ago, opened from a search result),
has not — and this key is what lets the editor still say "every year on August 25" without the
frontend composing that sentence itself, which is exactly the vocabulary this whole module
keeps on the server (see the UX gap this closed, **L9**, in the header above). Read-only and
derived, like `recurrence_timezone` beside it: a caller sends `recurrence`, never a sentence
about it, and this key is deliberately **not** inside the `recurrence` block, which round-trips
verbatim to the write endpoint — growing it with a key the endpoint does not accept would make
a client's own read-edit-write loop fail. `null` covers two cases a client treats identically:
the event does not repeat, and its rule is one `CalendarCadenceLabel` cannot render a sentence
for.

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
| `starts_at`                   | required iff `all_day=false`, **forbidden** otherwise | any parseable instant, normalized to UTC. **A value with no zone is read in the workspace's timezone; an explicit offset always wins** — see "Whose midnight — the write side" above. |
| `ends_at`                        | no, only valid when `all_day=false`  | must be `>= starts_at`; the same zone rule as `starts_at`. |
| `subject_type` / `subject_id`          | no, **both-or-neither**                    | an optional pointer stored **verbatim**, never validated for existence and never dereferenced (see "Concepts" in the event model's own docblock) — a stale pointer is an inert dangling deep-link, not a broken calendar. |
| `recurrence`                                  | no                                          | absent/empty = the event happens once. Narrow, partly server-authored subset — see "Recurrence on write" under "Concepts" above for the full grammar, every refused key, and the anchor/end-of-series rules. |
| `scope` / `occurrence_date`                       | **forbidden**                          | `prohibited` on create — there is no existing row to scope a write against yet. Sending either is a 422 (`scope_on_create`); see "Scope" above. |

Both halves of the discriminator are enforced: an all-day payload carrying `starts_at`, or a
timed payload carrying `start_date`, is a 422 naming the offending field — an
**over-complete** payload is refused rather than silently narrowed, because a caller that
believes it stored a time on an event the grid renders as a day has no way to discover the
disagreement otherwise.

**A recurring create — the same endpoint, one more block:**

```
POST /api/calendar/events
```

```json
{
  "title": "Sprint review",
  "all_day": false,
  "starts_at": "2026-08-10T14:00:00+02:00",
  "ends_at": "2026-08-10T15:00:00+02:00",
  "recurrence": {
    "day": { "mode": "weekdays", "weekdays": [1] },
    "until": "2026-12-31"
  }
}
```

`recurrence.time`/`recurrence.tz` are never sent — the hour comes from `starts_at`, the zone
from the workspace, both stamped server-side (see "Recurrence on write" and "When a write
re-stamps the clock" above). `2026-08-10` is a Monday, so it satisfies its own rule; a
Tuesday here with `weekdays: [1]` (Monday) would be refused on `starts_at`
(`anchor_not_an_occurrence`), never silently accepted with the series starting a week later.

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
the table (`calendar.validation.*` message keys), plus every recurrence rule in "Recurrence on
write" above when `recurrence` is present (`anchor_not_an_occurrence`, `whole_minute`,
`series_has_no_occurrences`, the per-key grammar codes, `time_not_accepted` /
`timezone_not_accepted` for the two server-authored keys, `field_not_allowed` for any key
outside the accepted subset) and `scope_on_create` for a stray `scope`/`occurrence_date`.

### `GET /api/calendar/events/{event}` — show

`CalendarEventResource`. A foreign-workspace `{event}` 404s at route binding. `view`
requires only an authenticated user (workspace membership is the real gate, proven upstream).

### `PUT /api/calendar/events/{event}` — update

**Same full rule set as create** — this is a whole-event write, not a patch. The all-day
discriminator decides which columns carry the event's place in time, so a partial update that
changed only `all_day` (or only `starts_at` on an all-day event) would have to guess at the
other side; sending the whole event means the caller has already answered that. **This
includes `recurrence`: an update that omits it removes the recurrence**, exactly as omitting
`description` clears the description. A client editing a series must send the rule back;
`CalendarEventResource` already emits it in the exact shape this endpoint accepts (see
"`recurrence` ROUND-TRIPS" under "Resource shapes" above), so echoing it back is the whole of
the obligation — but that echo has to be **complete**: dropping only `exclusions.dates` while
keeping the rest resurrects every occurrence somebody had deleted one at a time.

| Field | Required | Notes |
|-------|----------|-------|
| every `POST` field | same rules | see "`POST /api/calendar/events`" above — `recurrence` included, and now genuinely optional in the sense that omitting it removes an existing rule |
| `scope` | no | `series` (default) \| `occurrence` \| `following` — full contract in "Scope" above |
| `occurrence_date` | required iff `scope ≠ series`, forbidden iff `scope = series` | `Y-m-d`, on the series' own **stamped** clock (`recurrence_timezone`, not `meta.timezone`) |

A plain `PUT` naming no `scope` — the entire pre-existing contract — rewrites the whole row in
place and always returns `200`. The two scoped shapes:

```
PUT /api/calendar/events/{event}          — scope=occurrence, detach one Monday
```

```json
{
  "title": "Sprint review — offsite this week",
  "all_day": false,
  "starts_at": "2026-09-14T15:00:00+02:00",
  "ends_at": "2026-09-14T17:00:00+02:00",
  "scope": "occurrence",
  "occurrence_date": "2026-09-14"
}
```

```json
// 201 Created — a NEW event, not the one named in the URL
{
  "id": "b7e1...",
  "title": "Sprint review — offsite this week",
  "all_day": false,
  "starts_at": "2026-09-14T13:00:00.000000Z",
  "ends_at": "2026-09-14T15:00:00.000000Z",
  "recurrence": null,
  "recurrence_timezone": null,
  "recurrence_label": null
}
```

```
PUT /api/calendar/events/{event}          — scope=following, split into a new cadence
```

```json
{
  "title": "Sprint review",
  "all_day": false,
  "starts_at": "2026-09-21T14:00:00+02:00",
  "ends_at": "2026-09-21T15:00:00+02:00",
  "recurrence": { "day": { "mode": "weekdays", "weekdays": [1] } },
  "scope": "following",
  "occurrence_date": "2026-09-21"
}
```

Returns `201` with the **new** continuation event — unless `2026-09-21` was the series' own
first occurrence, in which case the split collapses to `series` and this returns `200` with
the same row named in the URL (see "Scope" above for how the server decides this, and why a
client cannot predict it from the fields it already holds).

**Denials**: 403 unless the caller is the event's creator **or** the active workspace's
owner (`CalendarEventPolicy::update` — the same widened rule `KnowledgeBasePolicy` uses for a
shared, non-personal asset: an event left by someone who has since left the workspace must
still be correctable, or a shared calendar stops being trusted). A workflow-run-created event
has no human owner, so this is also what keeps it editable at all. 422 — every rule `POST`
reports, plus every rule in "Recurrence on write" and "Scope" above:
`occurrence_date_required` / `occurrence_date_without_scope`, `not_an_occurrence`,
`occurrence_has_no_rule` (a `recurrence` block sent alongside `scope=occurrence`),
`exclusions_full`, `event_does_not_repeat` (a scope other than `series` against a row with no
rule at all), and `split_starts_before_the_split` — a `following` payload whose own new start
lands before `occurrence_date`, which would leave the new series overlapping days the closed
half still covers.

### `DELETE /api/calendar/events/{event}` — soft delete, or a scoped removal

`204`, no body, same authorization as update. `scope`/`occurrence_date` follow the identical
contract "Scope" above describes, read through `input()` — a caller may send them as query
parameters or in a request body, since not every HTTP client sends a body on a `DELETE`:

| `scope` | Effect |
|---------|--------|
| `series` (default) | soft-deletes the row — reversible (`SoftDeletes`), but **there is no restore endpoint yet**. Byte-for-byte the pre-existing contract; see below. |
| `occurrence` | adds `occurrence_date` to the row's own `recurrence.exclusions.dates`. The row is **modified, not deleted**, even though the verb is `DELETE`. |
| `following` | closes the series the day before `occurrence_date` — or, when nothing would be left behind, soft-deletes the whole row, the same collapse `PUT` makes. |

```
DELETE /api/calendar/events/{event}?scope=occurrence&occurrence_date=2026-09-14
```

`204` either way. What survives is the rest of the series, re-read from the grid — there is
no response body to describe it, and `DELETE` never creates a new row under any scope, so
there is no status-code distinction to make the way `PUT` has to make one.

The trash exists so a mis-click on a shared calendar is not permanent, but a full trash UI is
a screen nobody has designed, and an endpoint with no screen would be a contract kept for
free. *Planned, not implemented.*

**A known, accepted edge**: excluding every remaining occurrence of a series one at a time
(`scope=occurrence`, repeated) leaves a row that draws nothing. It is not detected or
refused — nothing executes because a calendar row exists (the module's own definitional
fence), so an empty series costs a row and no behaviour, and detecting it would mean
projecting the whole remaining span on every single-occurrence delete to catch a user who
should have deleted the series instead.

**Denials**: 403 unless creator or workspace owner. 422 — the identical `scope`/
`occurrence_date` rules "Scope" above describes for `PUT`, including `exclusions_full` when a
further `scope=occurrence` delete would push the series past 50 skipped dates.

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
- `app/modules/Calendar/Services/CalendarRecurrenceService.php` — everything the Calendar knows
  about repeating: stamping a rule at save time (B4), and B5's own `occurrenceDaysIn()` /
  `occurrenceInstantsIn()` — the day/instant projections `EventCalendarSource` reads a series
  through, bounded by the window and the series' own anchor/end rather than by a count. A thin
  layer over the shared `App\Support\Recurrence\ScheduleEngine` (ADR-0052), not a second engine.
- `app/modules/Calendar/Support/CalendarCadenceLabel.php` — the `cadence_label`/`recurrence_label`
  prose for an **event** series: a day/month renderer ("Weekly on Mon", "Monthly on the third
  Tue"), not shared with and not reused from `ScheduleCadenceLabel` below — see "`cadence_label` is
  optional, translated prose" above for why the two address different questions and cannot be
  one class. Includes the **yearly branch** ("The cadence sentence's yearly form" above): a
  monthly rule confined to one month renders as yearly, tried before the ordinary day/month path.
- `app/modules/Calendar/DTOs/{CalendarWindow,CalendarOccurrence,CalendarResult,
  CalendarSourceResult,CalendarTruncation,CalendarBadge,CalendarEventDTO,CalendarRecurrence}.php`
  — `CalendarOccurrence`'s constructor carries `$recurring`/`$occurrenceDate` (default `false`/
  `null` on both `allDay()` and `timed()`), documented on the wire as `recurring`/
  `occurrence_date` above
- `app/modules/Calendar/Enums/{CalendarColor,CalendarTruncationKind,CalendarUnavailableReason}.php`
- `app/modules/Calendar/Sources/EventCalendarSource.php` — the Calendar's own subject: one
  occurrence per one-off event, plus (B5) one occurrence per day/instant a recurring event's
  series falls on inside the window, through the identical public contract either way.
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
- `app/modules/Workflows/Support/ScheduleCadenceLabel.php` — the `cadence_label` prose for a
  **schedule**: the interval of its two sub-daily modes, never the day/month axis (contrast
  `CalendarCadenceLabel` above — the two renderers answer different questions for different
  readers, deliberately not one shared class).
- `app/modules/Workflows/Steps/CreateEventStep.php` — the `create_event` step (contract
  documented in `docs/backend/workflows-api.md`)
- `config/calendar.php` — every budget, with its own arithmetic justification
- `lang/{en,pl}/calendar.php` — includes the `cadence` block B5 added: every sentence
  `CalendarCadenceLabel` can render, in both configured locales — including the later
  `yearly_*` keys and the `months_in_date` catalogue (the genitive month form Polish dates
  need, distinct from the standalone `months` list; see the lang file's own translators' note)
- `tests/Feature/CalendarModuleBoundaryTest.php` — the pinned boundary + the "add a source"
  recipe exercised as a test
- `tests/Feature/CalendarEventFenceTest.php` — the D4 "nothing executes" fence, executed
  (four independent doors, structural + behavioural)
- `tests/Feature/CalendarOccurrencesTest.php` — the whole read path
- `tests/Feature/CalendarEventTest.php` — the write surface
- `tests/Feature/CalendarEventWorkflowStepTest.php` — the `create_event` step
- `tests/Feature/CalendarSeriesProjectionTest.php` — B5's own suite: every occurrence a series
  places inside the window, the past-drawing decision, the two ways a series can be cut and the
  truncation entries each one files, the fixed-at-four query count, and the one-off compatibility
  guarantee
- `docs/decisions/ADR-0051-calendar-module-design.md` — the design record (rejected
  materialization, the timezone decision, the event definitional fence and its automated
  guard, the measured memoization that was removed, and — D11 — why a series projects its own
  past instead of splitting into a past/future pair the way the schedule/run sources do)
- `docs/decisions/ADR-0052-shared-recurrence-layer.md` — the shared `App\Support\Recurrence`
  engine `CalendarRecurrenceService` projects a series through, and the day-projection anchor
  (noon, not midnight) B5's all-day half relies on
- [`docs/next/calendar-uxui-spec.md`](../next/calendar-uxui-spec.md) §24 — the UX
  specification review that found the three read-side gaps (`L8`/`L9`/`L10`, §24.15) B5's
  additive pass closed, and that separately catalogued (§24.2–§24.4) the full write surface
  this page's B6 pass documents: `recurrence`'s accepted subset and every refused key, the
  anchor/end-of-series rules, the three `scope` values and what each returns, and every 422
  path — see "Recurrence on write" and "Scope" under "Concepts" above, and the `POST`/
  `PUT`/`DELETE` sections under "Endpoints." The gap this review flagged — this page
  describing its own write surface as undocumented — is closed by B6; nothing about the write
  surface's actual behaviour changed to close it.
