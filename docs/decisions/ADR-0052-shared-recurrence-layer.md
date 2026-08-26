# ADR-0052 — Shared recurrence layer: extraction, the validation split, and the day-projection anchor

**Date:** 2026-08-25
**Status:** Accepted
**Module:** `App\Support\Recurrence` (`ScheduleEngine`, `ScheduleCompiler`, `CompiledSchedule`,
`LegacyScheduleUpgrader`, `RecurrenceDescriptorValidator`, `RecurrenceViolation`,
`Enums\{ScheduleTimeMode,ScheduleDayMode,ScheduleMonthMode,ScheduleDaySpecial,ScheduleLimits,
RecurrenceViolationCode}`), plus its one consumer today, `App\Modules\Workflows`
(`Services\WorkflowScheduleService` — now a facade keeping only the three members that touch a
`Workflow` model: `arm()`/`isArmable()`/`claimDue()` — and `Services\WorkflowScheduleRulesValidator`
— the per-key Laravel rules plus the English-sentence renderer)
**Relates to:** ADR-0009 §3 / ADR-0010 / ADR-0012 (the family→v2 compositional-descriptor design this
ADR relocates unchanged — the grammar did not change, only where it lives and how validating it is
split), ADR-0051 D1 (the module-naming ban this extraction exists to satisfy), D4 (the calendar-event
execution fence this shared layer becomes the fifth door of), D5 (the recurrence-deferral decision this
ADR flags, below), `docs/backend/calendar-api.md` / `docs/backend/workflows-api.md` (zero wire-contract
bytes changed; stale class pointers in the latter fixed by this pass)

---

## Context

R3's next chapter — recurring calendar events, explicitly deferred by ADR-0051 D5 — needs the same
cadence arithmetic Workflows already built for its `schedule` trigger. That arithmetic lived entirely
inside `App\Modules\Workflows`, and `CalendarModuleBoundaryTest` forbids the Calendar module from
naming any sibling module, Workflows included (ADR-0051 D1). Before a single recurring-event line can
be written, three separable questions needed answers:

1. Where does the engine itself live, if neither module may own it and neither may name the other?
2. When a second module can finally accept a recurrence descriptor, how does VALIDATING one split
   between a layer both modules can share and a layer only one of them owns?
3. The engine only understands INSTANTS (a schedule fires at a wall-clock moment); an all-day
   recurring subject — the shape a calendar event will need — has no fire time at all. How does the
   same engine answer a question about DAYS without inventing a second notion of cadence?

This ADR records those three answers. It ships in two pieces: the extraction itself (Decision 1)
landed as commit `3fae1e6` — a byte-for-byte move, gated by a golden-matrix parity proof. The
validation split (Decision 2) and the day-projection anchor (Decision 3) landed as a follow-up batch
on the same branch (`module/calendar`), reviewed but **not yet committed** at the time of writing.
**No recurring-event feature exists yet.** This ADR is the foundation the next chapter builds on, not
that chapter itself — see "Consequences" for what is, and is not, unblocked.

> **Update, later the same day.** The recurring-event chapter this paragraph said did not exist yet
> has since landed on this same branch, as its own B4 (the write surface —
> `recurrence`/`recurrence_until`, scope-based `PUT`/`DELETE`) and B5 (the read-side projection —
> every occurrence a series places inside the window, not only its anchor). Both build directly on
> Decisions 1–3 below: `App\Modules\Calendar\Services\CalendarRecurrenceService` is a thin wrapper
> over `ScheduleEngine`, and `App\Modules\Calendar\Sources\EventCalendarSource` is what reads it onto
> the grid. Read-side contract in `docs/backend/calendar-api.md`; see ADR-0051 D11 for the one
> projection-semantics decision B5 added on top of this layer — an event series projects its own
> past, unlike the future-only schedule source this ADR's Decision 3 shares a day-anchor with.

---

## Decisions

### Decision 1 — A shared layer below both modules, not a second engine and not an inverted dependency

Three shapes were on the table for "Calendar needs cadence arithmetic it may not name":

- **A second engine, built inside Calendar.** Rejected. The product already intends to draw a
  workflow schedule's projected occurrences and a recurring event's future dates **on the same grid,
  side by side** (`WorkflowScheduleCalendarSource` and a future event source, per ADR-0051 D1/D6). Two
  independent implementations of "every other Tuesday" is not a style problem — it is two
  *definitions* of one cadence with no guarantee they agree, drifting apart from the day the second
  one is written. This is the exact failure ADR-0051 D5 already refused for a hypothetical
  calendar-native `repeat` column ("a second recurrence engine ... with its own DST story"), now
  arriving through a different door.
- **Dependency inversion: Calendar defines a contract, Workflows implements it.** Rejected. This
  saves the *letter* of the module boundary (Calendar still names no concrete Workflows class) but not
  its *purpose*. To validate a descriptor against that contract, Calendar would still need to know the
  descriptor's vocabulary — which mode owns which keys, how a window pairs, what `special` means —
  which is the grammar itself. A contract that requires knowing the grammar to use correctly is a
  second implementation of the grammar wearing an interface.
- **Extraction to a shared layer neither module owns.** Accepted.

**Home: `App\Support\Recurrence`, not a new module.** The engine has no model, no migration, no
route, no provider — nothing a module's scaffold exists for. This mirrors an existing pattern rather
than inventing one: `App\Support\Meter\MeterActorResolver` and `App\Support\Ai\FencedBlock` are both
shared-but-not-a-module layers built for the identical reason (two modules that may not name each
other need to share one implementation), and `FencedBlock`'s own docblock states the argument in the
words `MeterActorResolver`'s used first — "Placement mirrors `MeterActorResolver`: `App\Support`, not
a module" — because the two modules on either side of *that* fence (Knowledge, Generator) may not name
each other either. `ScheduleEngine` is the third instance of the same argument, for Workflows and
Calendar.

**The cut: whether a method accepts or touches a model.** Six pure arithmetic methods moved down
verbatim into `ScheduleEngine` — `nextDueAt`, `nextOccurrences`, `occurrencesFrom`,
`previousOrAtOccurrence`, `isApproximate`, `timezone` — none of them touch a `Workflow`. Three stayed
in `WorkflowScheduleService`, because they are the only ones that do: `arm()` (writes `next_due_at`
onto a `Workflow`), `isArmable()` (reads `trigger_type`/`status` off one), and `claimDue()` (the
compare-and-swap `UPDATE ... WHERE next_due_at = ?` race guard). That is the entire cutting rule — no
method was moved for any other reason. `WorkflowScheduleService` is now a thin facade over
`ScheduleEngine` with its public signature **unchanged**, so every existing caller (the write path,
the AI-assist re-validation, the sweep command) needed no edit. It no longer holds the compiler or the
legacy upgrader in its own fields, either — it hands both straight to the `ScheduleEngine` it
constructs, so an instance divergence between the facade and the engine is unrepresentable in the
type system, not merely avoided by convention. The scheduler's higher-level semantics — when the sweep
runs, how a due slot is claimed and skipped once a workflow is over its run budget — live in
`RunScheduledWorkflowsCommand`, which sat outside this cut on either side of it and needed no change.

**Guarded with zero exceptions.** `RecurrenceLayerBoundaryTest` enforces two properties, each checked
from more than one angle so a rename or an import alias cannot defeat it:

1. **The layer names no module.** `App\Modules` in any spelling, on any line, comments included, with
   **no exemption list of any kind**. The guard's own history is the argument for the zero: its first
   run found four foreign names already sitting in the engine's own docblock (the module facade that
   keeps the three impure methods, plus the sibling `App\Support` layers cited above as placement
   precedent). A narrow per-name carve-out was built to let those docblock mentions stand, then
   **deleted**, because a list of four teaches the next author that a fifth is a normal move — and the
   fifth will have an argument every bit as good as the first four did. A list of length zero cannot
   grow. The docblocks were reworded to name their collaborators in prose instead ("the module's
   schedule-service facade keeps the arming and the CAS claim") rather than spelling the namespace —
   the reader loses nothing, and the guard gains having nothing to forgive.
2. **The layer has no executive surface.** No persistence, no queue, no writes, no ambient tenant
   state (checked by a byte scan over a needle list — `Illuminate\Database`, `DB::`, `dispatch`,
   `Event::`, `Auth::`, and more — with backslash-run collapsing so an escaped-string bypass cannot
   hide a class lookup), and — checked by **reflection** over real method/property signatures, not by
   reading bytes — **no public method that accepts or returns a Model**, judged both negatively (is it,
   or does it extend, `Illuminate\Database\Eloquent\Model`?) and positively (is it one of the layer's
   own value types, `Carbon`, or a builtin — anything else, including a Request or a query builder, is
   refused even though it is not a Model either). This is explicitly **the fifth door** of the
   calendar-event execution fence (ADR-0051 D4): the Calendar module itself may not execute anything
   because a row exists, and a shared layer it calls that *could* execute would let a calendar row do
   exactly that without breaking a single existing rule — the fence would hold everywhere except the
   one seam built after it was written.

**Why the flat module-ban survives alongside a namespace ceiling that would also catch it.** The guard
adds a second, *positive* check — an explicit allowlist of every namespace the layer may name
(`App\Support\Recurrence\` itself, `Carbon\`, `Cron\`, and a short list of global date/exception
types) — because a denylist can only forbid what somebody already thought of, and this one has a real
hole: `App\Support\Pagination\StagedCursorPaginator`, a **sibling** of this layer one directory over,
imports Eloquent and the query builder wholesale, and matches not one entry in the executive-surface
needle list. "Lives in `App\Support`" is not a synonym for "is pure." Even with that ceiling in place,
the flat module-ban is **kept, not folded into it** — the ceiling is a list, and a list can be widened
by adding one line with a plausible reason; the ban is an edge that is not a list entry at all, so
widening the ceiling can never widen the ban. This was verified with a probe, not assumed: adding a
module namespace to the ceiling's allowlist leaves the module-ban test red.

**The gate: a golden matrix, independently verified against the pre-extraction engine.** 70
descriptors × 16 anchors = 1,120 cases, 12,420 concrete instants, computed from the code *before* the
move and committed as a fixture (`tests/Support/Schedule/schedule-golden-matrix.json`). It exists
because the pre-existing parity test only compared a cold engine instance against a warm one, both
*after* the refactor — which pins statelessness, not correctness; a refactor that shifted every
schedule by an hour would have sailed through it unchanged. The fixture closes that gap, and was
independently confirmed to reproduce the engine exactly as it stood at `e501cf2` (the last commit
before this extraction): the review exported that commit to an isolated tree, with the old engine
still inside the Workflows module, and ran the identical fixture against it — 19,396 green assertions.
Sensitivity was **measured** with mutations, not assumed: an hour-shift mutation was caught by
976/1120 cases; a last-working-day mutation by 109/1120; the weakest mutation tried — dropping the
timezone reconversion inside the exclusions filter — was caught by only 2/1120, a mutation that reads
like harmless reordering rather than a real defect. **The matrix has an expiry date.** It is scheduled
for deletion, along with the rest of `tests/Support/Schedule/`, now that this review is closed — and
the cross-commit verification against `e501cf2` exists **nowhere else**: once the fixture is gone, this
ADR is the only place that fact survives.

### Decision 2 — The validation split: codes down, prose and per-key rules stay

**This is the most important decision in this document. A second consumer must read this section
before writing its own recurrence validation.**

The grammar of *shape* — which mode owns which keys, what each mode requires, how a window pairs and
orders, which axis combinations contradict, whether the cadence can ever fire at all — moved to the
shared layer as `RecurrenceDescriptorValidator::violations()` / `::unreachable()`, answering
exclusively in `RecurrenceViolationCode` cases carried by a `RecurrenceViolation` value object
(`path`, `code`, `context`). **No prose, no framework dependency, no language.**
`WorkflowScheduleRulesValidator` (the module's own copy) became a **renderer** for that half:
`message()` is a `match` over every `RecurrenceViolationCode` case with **no default arm**, so a
grammar code added to the shared enum and left unrendered **throws** at the point of use instead of
reaching a user as an unexplained blank field next to a control that simply refuses to save. Asserted
exhaustive by `WorkflowScheduleRulesRendererTest::test_every_violation_code_renders_a_sentence`, which
iterates every `RecurrenceViolationCode::cases()` and demands a non-empty, terminated sentence back.

**The distinction that actually decided this was not "where does validation live" but "what is
validation made of."** One half of the original module validator was already framework-attached:
`baseRules()`'s `required`/`array`/`integer`/`min`/`max`/`date_format`/`distinct`/`Rule::enum()`
entries resolve through Laravel's own `lang/{pl,en}/validation.php` automatically. That file is not
the framework default in this repository — its own header records that `lang/pl/validation.php` was
written specifically because, before it existed, **every** Laravel validation failure across the whole
product fell back to a raw untranslated key (`"validation.required"`), since neither a Polish
translation nor an English fallback was configured. That half of the schedule validator was therefore
**already correctly localized**, for free, purely by using the framework's own rule vocabulary. The
*other* half — the mode/window/contradiction grammar — was hand-written PHP conditionals emitting
hardcoded English sentences with no lang-file hook of any kind, and *that* is the half that could
neither be shared as-is (sharing prose would force the shared layer to pick one language, wrong for an
app that is PL+EN switchable, and one vocabulary, wrong for every consumer after Workflows — an
automation's "fire time" is an event's "start") nor stay module-only once a second consumer arrived
(who would then have to re-derive the same conditionals to get its own 422s — the exact duplication
this whole extraction exists to prevent). So the question was never geography; it was composition.

**The declared, deliberate limit.** Per-key TYPES and RANGES (`weekday` is an int 0..6; `at` is
`HH:mm`; a list carries no duplicates) stay in the module, expressed in that module's own framework
rule vocabulary, because that is what produces the per-field error path a form control attaches to.
Moving them down would create a second live implementation of the same rule, with only one of the two
ever rendering on any given path. What keeps the *facts* themselves from drifting between the module's
Laravel rules and the shared layer's own assumptions is that every numeric bound either side reads
comes from the one constants class, `App\Support\Recurrence\Enums\ScheduleLimits` (moved down
alongside everything else in Decision 1) — the bound is stated once, even though the rule-vocabulary
spelling it is per module.

**What that promise does *not* cover — three shape facts, verified against the current
`RecurrenceDescriptorValidator` source, that a second consumer must independently supply:**

1. **The time axis is required.** `timeViolations()` returns `[]` immediately when `time` is absent
   or not an array, with the comment *"absence/shape of the axis itself is the consumer's
   required/array rule"* — the shared layer assumes a consumer already enforces this and says nothing
   if one does not.
2. **The timezone must be a valid IANA string.** Nothing in `RecurrenceDescriptorValidator` inspects
   `tz` at all; only the module's own `'tz' => ['nullable', 'timezone']` rule does.
3. **Exclusions may not rule out an entire dimension.** `exclusionViolations()` only rejects an
   unknown *key name* inside `exclusions` (`months`/`weekdays`/`dates` is the whole vocabulary it
   checks); the *count* ceilings that make a set structurally unable to name every value of its axis
   (`ScheduleLimits::EXCLUSIONS_MONTHS_MAX = 11`, one short of all twelve months;
   `EXCLUSIONS_WEEKDAYS_MAX = 6`, one short of all seven days) exist only as the module's own `'max'`
   array rules.

A consumer relying on the shared layer alone is not unsafe in the sense of accepting a broken
descriptor — `unreachable()` still runs a real projection, and its own `try`/`catch (Throwable)` folds
an absent time axis or a malformed timezone (both of which make `ScheduleCompiler`/`ScheduleEngine`
throw) into the same empty-occurrences verdict as a genuinely over-constrained exclusion set. **It is
fail-closed.** But every one of those three gaps surfaces as the *same* violation —
`RecurrenceViolationCode::NO_OCCURRENCE` reported on `exclusions`, "the schedule has no occurrences" —
regardless of whether the real defect was a missing `time` block, a garbage timezone string, or an
actually unfireable cadence. **A second consumer that skips re-deriving these three checks ships a
correct gate with a misleading error**, and that has to live here, not only in the class docblock,
because `RecurrenceDescriptorValidator`'s own "what a consumer must do" section documents the three
*steps* a consumer follows — it does not flag the facts those steps quietly assume are supplied
elsewhere.

### Decision 3 — Noon as the day-projection anchor, measured rather than assumed

`ScheduleEngine::occurrenceDaysBetween()`'s contract is **days in, days out**: no instant crosses the
boundary in either direction. An all-day recurring subject (the shape a future recurring calendar
event needs) has no fire time to speak of, but the engine underneath has nothing *but* fire times — a
cadence compiles to a cron grid, and a cron grid fires at an hour. So a day projection has to supply
one internally (`ScheduleEngine::DAY_ANCHOR`), compile the descriptor with its `time` axis replaced by
that anchor, and format the resulting instants back to `Y-m-d` in the descriptor's own timezone.

**The hour is measured, not assumed.** Over **every** IANA timezone identifier, for 2020–2035, local
midnight does not exist on **112 zone-days** (a spring-forward crossing 00:00 — Africa/Cairo,
Asia/Beirut, America/Santiago, America/Havana, Asia/Tehran all do this) and occurs **twice** on
**45** (a fall-back crossing 00:00 — America/Havana every November). Noon has **zero** of either, over
the identical window and the identical tzdata. The number is not hard-coded anywhere as a constant:
`RecurrenceDayProjectionTest::test_the_day_anchor_exists_exactly_once_on_every_day_of_every_timezone`
**recomputes** it from `timezone_identifiers_list()` / `DateTimeZone::getTransitions()` on every run,
so a future tzdata update that moves the anchor out from under this decision fails the test with the
offending zone named, rather than staying silently wrong.

**The honest scope limit, recorded rather than implied.** The zero/zero property is a statement about
the 2020–2035 window and about the future, not about all of history. `DAY_ANCHOR`'s own docblock
records the counter-examples that window deliberately excludes: Sudan (Africa/Khartoum, Africa/Juba)
moved its clocks **at 12:00** on 2000-01-15; Morocco/Ceuta did the same on 1967-06-03; Havana on
1925-07-19; several 1900-era Alaskan re-basings did too; and a handful of whole calendar days never
existed at all where a zone jumped the date line (Apia 2011-12-30, Kiritimati 1994-12-31, Kwajalein
1993-08-21) — across 1900–2020, 21 zone-days skip noon and 4 repeat it. None of this changes the
decision — a calendar only ever projects forward from today, and the count is zero for every zone
through 2050 — but it is written down so a future reader does not lean on "noon never breaks" for
arithmetic outside the window this was actually measured over.

**Deliberately not deduped.** `occurrenceDaysBetween()` never calls `array_unique` on its result.
Under the noon anchor, each calendar day can appear at most once by construction — that is the
practical meaning of "zero repeats." A defensive dedupe here would silently absorb the *one* available
signal that the anchor has stopped being safe (a tzdata change reintroducing a repeated local noon
somewhere), while the caller's own result cap would quietly pay for the duplicate instead of the
project ever finding out. The guard test is what is meant to hold this invariant; a dedupe call is not
a substitute for it.

---

## Alternatives considered

- **A second recurrence engine built inside the Calendar module.** Rejected — see Decision 1; the
  product draws a schedule's occurrences and an event's recurrences on one grid, so two independent
  cadence grammars is two competing definitions of "every other Tuesday," not a style choice.
- **Dependency inversion — Calendar defines a `RecurrenceSource`-shaped contract, Workflows
  implements it.** Rejected — see Decision 1; it preserves the letter of the module boundary while
  losing its purpose, since validating against the contract still requires knowing the grammar it
  encodes.
- **Sharing the shape grammar as translated prose from the shared layer.** Rejected — see Decision 2;
  it would force the pure layer to pick one language (wrong — the app is PL+EN switchable) and one
  vocabulary (wrong — the next consumer's screen talks about a different subject than an automation's
  "fire time").
- **Sharing the per-key type/range rules as well, not only the shape grammar.** Rejected — see
  Decision 2; it would produce a second live implementation of a rule Laravel's own vocabulary already
  states once (and already renders correctly in both configured locales), with only one of the two
  implementations ever active on a given validation path.
- **Leaving validation entirely in the Workflows module and letting a second consumer write its own
  from scratch.** Rejected — this is the second definition of "valid" the whole extraction exists to
  prevent; it is also the option ADR-0051 D5 already named as unacceptable for the engine itself.
- **Midnight as the day-projection anchor.** Rejected — see Decision 3; measured, not stylistic: 112
  skipped + 45 repeated zone-days in the 2020–2035 window, against zero and zero for noon over the
  identical scan.
- **Defensively deduplicating the day list `occurrenceDaysBetween()` returns.** Rejected — see
  Decision 3; it would hide the one observable signal that the noon-anchor assumption had stopped
  holding, in exchange for a property (`array_unique`) the anchor already guarantees when it is safe.

---

## Consequences

- **Calendar now depends on `App\Support\Recurrence`** — `CalendarModuleBoundaryTest`'s allowlist
  already grants the `App\Support\Recurrence\` namespace prefix (pinned by
  `test_the_allowlist_matcher_does_not_prefix_match_a_class_entry`), and as of the recurring-event
  chapter's own B4/B5, something finally calls it:
  `App\Modules\Calendar\Services\CalendarRecurrenceService` wraps `ScheduleEngine` for a series'
  day/instant projection, and `EventCalendarSource` is what reads that projection onto the grid.
  This ADR cleared the ground for a recurring-event feature; the feature itself is now built on top
  of it. See the (now-resolved) flagged note on ADR-0051 D5, and D11, for what shipped and the one
  projection-semantics decision — past-drawing — that belongs to the calendar series rather than to
  this shared layer.
- `docs/backend/workflows-api.md` and three earlier ADRs (0009, 0010, 0012) named the moved classes
  under their old `App\Modules\Workflows` locations — one of them, `WorkflowScheduleCompiler`, was
  **renamed** to `ScheduleCompiler` during the move, not merely relocated, so the old name no longer
  resolves at all. Pointers in `docs/backend/workflows-api.md` (body text and the source-code map) are
  fixed by this same pass; the three ADRs each got a short pointer to this document rather than a
  rewrite of their own historical text, which remains an accurate record of the descriptor-v2 design at
  the time it was written.
- The golden matrix (`tests/Support/Schedule/`) is scheduled for deletion now that this review is
  closed. The cross-commit parity proof against `e501cf2` it carried (Decision 1) will not exist
  anywhere else once it is gone — this ADR is where that fact is preserved.
- **ADR-0051 D5, flagged, now resolved.** D5 deferred recurrence and offered a stated workaround: "a
  workflow author who wants a recurring annotation already has the tool: a schedule-triggered
  workflow with a `create_event` step." That workaround **materialized rows** — it was the identical
  second-source-of-truth shape D2/D4 of the *same* ADR reject for every other calendar source (a task
  deadline, a workflow run, a workflow schedule), with the same failure mode named there restated in
  miniature: a disabled or deleted workflow leaves its already-created `calendar_events` rows behind,
  with nothing that sweeps them. This ADR removed the one reason D5's workaround looked acceptable
  (there was, until this extraction, no shared engine a real recurring-event feature could use
  instead) — and the recurring-event chapter that followed on the same branch used it directly:
  `calendar_events` now carries its own `recurrence` rule (B4), projected onto the grid by
  `CalendarRecurrenceService` (B5), in place of the `create_event` workaround. ADR-0051 D5 has been
  annotated in place to point here, and ADR-0051 D11 records the one projection-semantics decision —
  past-drawing — this shared layer made possible but did not itself decide.

---

## Addendum — the mirror in the UI (2026-08-26, commit `62a73e4`)

**Recorded here rather than under a new ADR number, deliberately.** The frontend event below is a
CONSEQUENCE of Decision 2 below, not an independent architectural choice: it is the same "one
grammar, several consumers" shape this ADR already decided for validation, arriving one layer up,
in the layer this ADR's own Decision 1 named as the reason a second engine would have been wrong
("two independent implementations of 'every other Tuesday' ... drifting apart from the day the
second one is written"). A new ADR number would dress a consequence up as a fresh decision; this
addendum keeps the reasoning where the reasoning already lives.

**What happened.** The Calendar event drawer's repeat control and the Workflows schedule trigger's
builder started as two unrelated frontend controls — a narrow, date-derived preset list
(`pages/calendar/recurrencePresets.ts`) on one side, a full three-axis editor
(`pages/workflows/*Schedule*.vue`) on the other — a split `docs/next/calendar-uxui-spec.md` §24.5
made DELIBERATELY, on the grounds that the shared editor would expose automation vocabulary
("every 5 minutes", "last working day", an AI assist) inside a "repeat this meeting" dialog. The
owner watched the shipped module and reversed that call: the two controls are now **the same
component**, `resources/js/next/ui/recurrence/RecurrenceAxisEditor.vue`, plus six files beside it
(the pure axis grammar, three per-axis panels, the option-card radiogroup, the shared "od–do"
window field). Full reasoning for the reversal, and what survived of the original concern, is
recorded where the reversal happened: `docs/next/calendar-uxui-spec.md` §24.5 (banner) and §24.17
poz. 10 — not repeated here.

**Why this is Decision 2's shape, not a new one.** Decision 2 above splits backend validation into
a shared, codes-only grammar (`RecurrenceDescriptorValidator`, no prose, no per-consumer
vocabulary) and a per-module rendering layer (`WorkflowScheduleRulesValidator`'s `message()`,
framework rules, a consumer's own field paths). The frontend split drawn on `62a73e4` is the
identical cut, one layer up:

| | Backend (Decision 2) | Frontend (this addendum) |
| --- | --- | --- |
| Shared, consumer-blind | `RecurrenceDescriptorValidator` — shape grammar as `RecurrenceViolationCode`, no language, no vocabulary | `ui/recurrence/recurrenceAxes.ts` — the axis TYPES, `ScheduleLimits`-mirrored numeric bounds, per-axis client validators, the `{slot}`-sentence splitter; Vue-free and i18n-free at its core |
| Per-consumer | Each module's own Laravel rules + rendered `message()` prose | Each page supplies its own `RecurrenceProfile` (`WORKFLOW_SCHEDULE_PROFILE` — the whole grammar; `CALENDAR_RECURRENCE_PROFILE` — day+month only, no time axis, no modulo cadences, no `last_working_day`) — read from the backend's own admitted subset (`CalendarRecurrence::dayModes()`/`daySpecials()`/`monthModes()`), not re-typed from memory |
| What a profile can never do | A module cannot validate a shape the shared grammar has no code for | A profile cannot render a card the shared component has no sub-mode for — the editor cannot compose a rule its own endpoint would 422 on |

The property Decision 1 protects — ONE definition of "every other Tuesday", never two — now holds
end to end: one engine (`App\Support\Recurrence`), one validation grammar over it (Decision 2), and
now one UI grammar over THAT (`recurrenceAxes.ts`), with every consumer on either side of the stack
supplying nothing but its own admitted subset.

**What did NOT move.** The Workflows-only surface — the upcoming-runs preview strip
(`WorkflowSchedulePreviewStrip.vue`) and the AI assist modal (`WorkflowScheduleAssistModal.vue`) —
stayed in `pages/workflows/`, unmoved, because both are wired to Workflows' own store and endpoints
(`schedulePreview`, `scheduleAssist`); a component with a store dependency and an endpoint call is
not a candidate for the shared layer by the same test Decision 1 applies to the backend ("no
executive surface"). `WorkflowScheduleBuilder.vue` (the host) and `RecurrenceField.vue` (the
Calendar host) also stayed page-local — each still owns what only its own module has an opinion
about: Workflows' preview/assist/exclusions-with-weekdays-and-months; Calendar's "does it repeat at
all" switch, its end-of-series control, and its anchor-invariant (§24.5.6 of the calendar spec).

**The boundary is enforced, not merely documented — the frontend analogue of this ADR's
`RecurrenceLayerBoundaryTest`.** `resources/js/next/__tests__/uiLayerImportBoundary.spec.ts` fails
the suite if any file under `ui/**` imports from `pages/**`, type-only imports included. There is
no frontend equivalent of the backend's module-naming ban (`App\Modules` in any spelling) — the two
pages here (`pages/calendar/`, `pages/workflows/`) have no rule against naming each other and never
needed one, since neither imports the other; the one enforced invariant is strictly directional:
the shared layer must never import UP into a page. Both pages import DOWN into
`ui/recurrence/`, which is the only direction the boundary test allows.
