# ADR-0010 — Workflows schedule rebuild: 12→16 families, times/exclusions, live preview, bespoke last-working-day

**Date:** 2026-07-10 (created)
**Status:** Accepted
**Module:** Workflows (`app/modules/Workflows/`)

---

## Context

ADR-0009 §3 replaced the Etap-5 4-preset schedule with 12 descriptor-driven families compiled to
`dragonmantank/cron-expression`. In use, three gaps surfaced quickly: (1) the vocabulary still had
no "day-of-week within a month" concept (no "first Monday", no "last Friday"), so requests like
"the last working day of the month" or "every other Tuesday" had no honest family to map onto and
fell to the AI-assist's `alternative` channel every time; (2) a schedule could only fire ONCE a
day even though "twice a day" is common outside the one hard-coded `twice_daily` family, and there
was no way to skip a fixed set of dates/weekends/a month without picking a whole different family;
(3) the frontend had no way to show the user "here is what this cadence will actually do" before
saving — it either trusted its own (necessarily approximate, hand-rolled) description or asked the
user to save first and find out. This batch (B1–B5) closes all three gaps: 4 new families, two
optional schedule-block extensions (`times`, `exclusions`), and a new read-only preview endpoint.
It also fixes a load-bearing cron-library defect discovered while implementing the fourth new
family. This ADR documents those decisions and explicitly marks what it supersedes in ADR-0009 §3.

---

## Decisions

### 1. Closed descriptor vocabulary extended to 16 families, not opened to raw cron/RRULE

**Decision:** Four families were added to `WorkflowScheduleFamily`: `every_n_months` (a month-grid
cadence, e.g. quarterly-but-custom-N), `nth_weekday_of_month` (e.g. "first Monday", "third
Thursday"), `last_weekday_of_month` (e.g. "last Friday" — a GUARANTEED monthly fire, unlike
`nth_weekday_of_month` ordinal=5 which can skip a month), and `last_working_day_of_month` (the
last Mon–Fri of the month). Each ships a `paramDescriptors()` entry exactly like the original 12,
so the SAME three consumers (write validation, `/meta/schedule-families` discovery, the AI-assist
prompt) picked them up automatically with zero incremental wiring — this is the single-source
property ADR-0009 §3 established, now exercised a second time by a real extension.

**Alternatives rejected:**
- **Accept raw cron expressions, or adopt an RRULE (iCalendar recurrence) grammar** — rejected
  again, for the same reason ADR-0008 #7 and ADR-0009 §3 already rejected it: both are a
  significant footgun surface for a non-technical user (ambiguous field order, silent typos,
  RRULE's much larger BYSETPOS/BYDAY/COUNT/UNTIL cross-product) with no natural client-side
  validation, and every new REAL user need this batch identified ("first Monday", "last working
  day", "several times a day", "skip weekends") maps cleanly onto a NEW closed descriptor or a
  bounded extension — there was no case that actually NEEDED open-ended recurrence syntax to
  express. Opening the grammar to solve four concrete needs would have imported unbounded
  complexity to solve bounded problems.
- **A single generic "day selector" family parameterized by a rule type** (ordinal-or-last,
  weekday-or-workday) instead of three separate families — considered, and rejected as premature
  generalization: `nth_weekday_of_month`, `last_weekday_of_month`, and `last_working_day_of_month`
  have genuinely different cron-token strategies underneath (`#`, `L` suffix, and a bespoke
  calculation respectively — see decision #4), and collapsing them into one family with a
  discriminator param would hide that difference from the descriptor consumers (the FE would need
  its own conditional rendering keyed on the discriminator anyway, so nothing is actually saved by
  merging the outer family id).

**Rationale:** The whole point of ADR-0009 §3's descriptor-driven design was that growing the
vocabulary is SUPPOSED to be cheap and safe — this batch is the proof. Each new family is a
`paramDescriptors()` entry plus a compiler `match` arm; nothing else in the write path, the
discovery endpoint, or the AI-assist prompt needed to change to accommodate it.

**Consequence:** `docs/backend/workflows-api.md` documents all 16 families with next-fire
semantics; `GET /workflows/meta/schedule-families` now returns 16 entries — this is a WIDENING,
additive change to that endpoint's response (no existing entry's shape changed except `weekly`,
covered in decision #2).

---

### 2. `weekly` becomes a list (`weekdays`), scalar `weekday` tolerated on READ only

**Decision:** `weekly`'s descriptor changed from a single required `weekday` (type `weekday`) to a
single required `weekdays` (a NEW descriptor type, `weekday_list` — a non-empty array of distinct
weekday ints, each still `0..6`). `WorkflowScheduleCompiler::weekdayList()` reads a legacy scalar
`params.weekday` as a one-element list when `weekdays` is absent, so PRE-EXISTING rows compile
unchanged with no migration/backfill. A NEW write MUST use `weekdays` — a `weekday` key submitted
on write fails `WorkflowScheduleRulesValidator`'s foreign-param check (422) because it is no
longer in `weekly`'s current descriptor set.

**Alternatives rejected:**
- **A one-time data migration rewriting every stored `weekly` schedule's `weekday` to
  `weekdays: [weekday]`** — rejected as unnecessary risk for a cosmetic-only gain: the compiler's
  read-tolerance achieves IDENTICAL runtime behavior for existing rows without touching data, and
  the project's safety rules require explicit approval before migrations that modify data: a
  code-level compatibility shim is strictly safer than a data rewrite for a change that has no
  behavioral difference for the old shape.
- **Keep `weekday` scalar AND add a separate `weekdays` field on the family, with the union of
  both honored** — rejected: this doubles the ways to express the same intent going forward
  (should the FE ever write `weekday` again?) where the read-tolerance approach cleanly retires
  the scalar for all NEW authorship while still honoring it for old rows — one write shape, one
  legacy read shape, not two live write shapes forever.

**Rationale:** "Several days a week in one schedule" (e.g. "every Monday, Wednesday, Friday") was
a real, common request the single-weekday `weekly` family could not express at all — it would
have needed three separate `weekly` workflow definitions instead of one, which is both a worse
authoring experience and a worse monitoring experience (three separate run histories instead of
one). Bot-module precedent for read-tolerant legacy-shape compatibility already exists in this
codebase; mirroring it here (rather than forcing a migration) is the smallest safe change.

**Consequence:** `docs/backend/workflows-api.md`'s `weekly` row and the `/meta/schedule-families`
worked example were rewritten to the `weekdays` shape; the read-tolerance note is called out
explicitly so a future contributor does not "fix" the compiler into rejecting the legacy scalar.

---

### 3. `times[]` as a list of cron expressions, `exclusions` as a post-filter — not new cron grammar

**Decision:** Two schedule-block extensions, both fully optional and additive:

- **`times`**: 1–6 distinct `'HH:mm'` strings, allowed only on wall-clock families (those with a
  `time` descriptor) and mutually exclusive with the scalar `params.time`. `CompiledSchedule`'s
  `cron` kind changed shape from "one expression" to "a non-empty LIST of expressions, one per
  fire time, sharing every other cron field" (`WorkflowScheduleCompiler::expandTimes()`); a plain
  single-time schedule is simply a one-element list, so nothing about the common case changed
  shape-wise. `WorkflowScheduleService` takes the earliest strictly-after candidate across the
  whole list.
- **`exclusions`**: `{ months?, weekdays?, dates? }`, evaluated as a POST-FILTER in
  `WorkflowScheduleService::nextDueAt()` — compute the next union candidate, drop it and recompute
  if excluded, bounded by a hard 1000-iteration / 10-year-horizon loop. It never touches the cron
  grammar at all.

**Alternatives rejected:**
- **Express exclusions AS cron** (e.g. a negative day-of-week clause, or a second "blackout" cron
  expression ANDed against the first) — rejected: standard cron has no native "NOT" or set-
  difference operator, and dragonmantank's dialect is no exception; faking it would mean either
  a second compiled expression per exclusion type (multiplying the already-list-shaped `cron` kind
  further and complicating "earliest wins" into "earliest-that-doesn't-also-match-the-blackout-
  expression") or bespoke Carbon-level filtering — which is exactly what was built, just described
  honestly as a post-filter instead of disguised as more cron. A post-filter is simpler to test,
  reason about, and explain in docs than a two-expression AND-NOT cron composition would have
  been.
- **Unbounded exclusion lists** — rejected: `months` capped at 11 entries and `weekdays` at 6
  (each list can never exclude EVERY value of that single dimension by construction), `dates`
  capped at 50. These caps exist so a single clause can never trivially make a schedule
  unfireable; a genuinely unfireable COMBINATION (e.g. a `weekly`-Monday schedule that also
  excludes Monday) is still possible and is caught by decision #5's empty-schedule guard, not by
  the per-list caps.
- **A "skip window" (date range) exclusion instead of/alongside `dates`** — considered and
  deferred: no concrete request for it existed in this batch's scope; `dates` (explicit days,
  up to 50) covers the observed "skip these specific holidays" need without inventing a range
  grammar prematurely.

**Rationale:** Both extensions are strictly ADDITIVE to the existing `{family, params, tz}` shape
— an old stored schedule with neither key behaves byte-for-byte as before (`hourMinuteList()`
falls back to `params.time` when `times` is absent; `normalizeExclusions()` treats a missing block
as all-empty, a no-op filter). Keeping `times`/`exclusions` OUTSIDE the per-family `params` object
(rather than, say, adding a `times` param to every wall-clock family's own descriptor list) means
they are validated and documented ONCE at the schedule-block level instead of duplicated across
11 family descriptor entries.

**Consequence:** `docs/backend/workflows-api.md`'s Schedule section documents both extensions with
their wire shapes and the loop's hard limits; `WorkflowScheduleFamily::supportsTimes()` is the one
place "which families may carry `times`" is decided, consumed by both the validator and (via the
prompt vocabulary) the AI-assist.

---

### 4. `last_working_day_of_month` is bespoke — dragonmantank's `LW` token is broken

**Decision:** `last_working_day_of_month` is computed directly in `WorkflowScheduleService`
(`lastWorkingDayOfMonth()`: start at the month's last calendar day, step backward over
Saturday/Sunday) rather than compiled to a cron expression. `CompiledSchedule` grew a THIRD kind,
`last_working_day`, alongside `interval` and `cron`, carrying one-or-more `{hour, minute}` pairs
(supporting `times[]` the same way the cron kind does).

**Alternatives rejected:**
- **Use dragonmantank's `LW` token** (the documented cron extension for "last weekday of the
  month") — rejected because it is DEFECTIVE in the installed version (v3.6.0): the token parser
  reads the `L` inside `LW` as day `0`, which normalizes to the PREVIOUS month and returns
  wrong/garbage dates. This was verified directly against the installed library while implementing
  this family (not assumed from documentation) — using it would have shipped a family whose
  computed `next_due_at` is simply incorrect.
- **Upgrade or patch the cron library** — rejected as disproportionate: `dragonmantank/cron-
  expression` is a transitive Laravel scheduler dependency already relied on for 11 other
  families' correct behavior (including the `L`/`#` tokens used correctly elsewhere); patching or
  forking it over one broken token, or chasing an upstream fix/release, is a much larger and
  riskier change than a ~15-line bespoke Carbon calculation for the one family that needs it.
- **Express "last working day" as an approximation using `last_day_of_month` with a note** —
  rejected: "last working day of the month" is a common, well-understood business concept (payroll
  runs, month-end reporting) that deserves to be modeled exactly, not approximated to "last
  calendar day" (which is wrong roughly 2 days out of 7).

**Rationale:** "Last working day of the month" is also not expressible as any SINGLE standard cron
expression even setting the `LW` defect aside — it is the LATEST of {last Monday, …, last Friday},
which is a max-of-candidates computation, not a single field-matching rule. A bespoke calculation
was therefore the correct shape for this family regardless of the library defect; the defect is
the reason `LW` could not be used as a shortcut, not the sole reason for going bespoke.

**Consequence:** `CompiledSchedule::isLastWorkingDay()` is a new branch `WorkflowScheduleService`
must handle everywhere `isInterval()`/cron are already branched on (`nextCandidate()`,
`isApproximate()` correctly returns `false` for it — it IS calendar-anchored, unlike the interval
kind). Public holidays are explicitly NOT accounted for (documented as a known gap, not silently
wrong) — see `docs/backend/workflows-api.md`'s Planned/deferred section.

---

### 5. `every_n_months` is January-anchored (modulo-year), matching the `every_n_hours` doctrine

**Decision:** `every_n_months`'s month list is `1, 1+n, 1+2n, … ≤ 12` — always anchored to
January and reset every year, NOT a rolling "N months from whenever this fired last" interval. For
`n=5` this gives months {1, 6, 11} (Jan/Jun/Nov), so the gap from November back to January is 2
months, shorter than `n`.

**Alternatives rejected:**
- **A rolling N-month interval from the arm/last-fire instant** (mirroring `every_n_minutes`'s own
  "from + N minutes" behavior) — rejected for CONSISTENCY, not because it is wrong in isolation:
  `every_n_hours` already established the precedent that an "every N `<unit>`" family compiling to
  a cron step field (`*/N`) is HOUR-OF-DAY-modulo-N, resetting at midnight, not a rolling interval
  — because that is what cron's `*/N` actually means and Laravel's own `everyFiveHours()` already
  behaves this way. Making `every_n_months` a true rolling interval while `every_n_hours` stays
  modulo-N would mean the two "every_n_*" families in this same vocabulary behave by two different
  underlying doctrines despite near-identical names and param shapes — a much worse trap for a
  user (or the AI-assist) reasoning by analogy between them than the modulo-year boundary
  shortening one gap per year.

**Rationale:** Consistency of doctrine across same-shaped families is worth more here than
avoiding the one shortened boundary gap — a user who understands "every_n_hours resets at
midnight" can correctly predict "every_n_months resets in January" without re-reading the docs,
whereas two silently-different interpretations of "every N" within the same 16-family vocabulary
would be a genuine footgun.

**Consequence:** documented explicitly in `docs/backend/workflows-api.md`'s family table and
pinned by a unit test asserting the Nov→Jan gap for `n=5`; the AI-assist's semantic-caveats prompt
section states this doctrine so the model does not describe `every_n_months` as a rolling
interval when explaining a proposed config to the user.

---

### 6. Live preview endpoint — the FE never computes occurrence dates itself

**Decision:** `POST /workflows/meta/schedule-preview` (`WorkflowSchedulePreviewController` +
`WorkflowScheduleService::nextOccurrences()`) is a new, stateless, read-only endpoint that projects
the next N (1–12, default 6) fire instants of a schedule block the caller has NOT yet saved. It is
the ONLY place occurrence dates are computed for the frontend — the builder's live "next runs"
list and the AI-assist's alternative-preview both call it; neither re-implements cron/interval math
client-side.

**Alternatives rejected:**
- **Compute the preview client-side** (port the cron/interval/exclusion logic to TypeScript) —
  rejected outright: this would be a SECOND implementation of the exact cadence semantics
  (`every_n_hours` modulo-N, `every_n_months` January-anchoring, the DST fall-back double-fire,
  the `LW`-defect workaround, the exclusion loop's hard limits) that could silently drift from the
  backend's — the single most important property of the whole descriptor-driven design (ADR-0009
  §3) is that there is exactly ONE cadence grammar; a client-side reimplementation would break that
  property for the one place users actually LOOK at what a schedule will do.
- **Reuse the empty-schedule guard's 422 as the "how does my draft look" signal, without a
  separate preview endpoint** — rejected: a 422 is the wrong shape for a live, debounced,
  mid-typing UI signal (the FE would have to distinguish "structurally invalid, still typing" from
  "an over-constrained-but-otherwise-valid draft" by parsing error message content); a dedicated
  response with `occurrences`/`empty`/`approximate` fields is more explicit and does not force the
  FE to treat an expected, common mid-edit state (an over-constrained exclusion set) as an error.

**Rationale:** `WorkflowScheduleRulesValidator::secondPass()` gained a `checkEmpty` parameter
specifically so the SAME rule set could serve two different response postures from one place: the
write/assist paths reject an unfireable schedule outright (422), while the preview path reports it
as data (`empty: true`) — this is the "one source of truth, two consumption modes" pattern applied
to validation the same way `paramDescriptors()` already applies it to the family vocabulary.
`approximate: true` is decided on the COMPILED kind (`CompiledSchedule::isInterval()`), never the
family string, so it can never miss a future interval-shaped family.

**Consequence:** `docs/backend/workflows-api.md` documents the endpoint's full contract; the
frontend's `WorkflowScheduleBuilder.vue` and `WorkflowScheduleAssist.vue` both call
`store.schedulePreview()` — see decision #7 for the UI layer built on top of it.

---

### 7. Frontend: simple/advanced progressive-disclosure modes as a curated layer OVER the descriptors, not a parallel model

> **Superseded by ADR-0012.** The family-based descriptor this section's two-mode frontend was
> built over was itself retired by ADR-0012 (a compositional `{ time, day?, month? }` axis model
> replaces the family enumeration). The simple/advanced split described below no longer exists —
> the rebuilt frontend is a single three-tab builder (Czas / Dzień / Miesiąc) over the three axes,
> and the AI assist moved from an inline panel to a reviewed modal (never auto-applying). The
> rationale below is kept for historical record; it is no longer the current design.

**Decision:** `WorkflowScheduleBuilder.vue` was rebuilt with a SIMPLE mode (five curated intents —
Minutes/Hours/Daily/Weekly/Monthly — each mapping onto one or two of the 16 families with a
reduced control set) and an ADVANCED mode (four sections — Repeat/Days & dates/Times/Exclusions —
rendering every descriptor for the selected family, plus the `times`/`exclusions` editors). Both
modes write the SAME `ScheduleDraft` shape and both are validated identically; simple mode is
simply a curated SUBSET of what advanced mode can express, never a separate data model. A live
preview section (natural-language sentence via a deterministic `describeSchedule()` helper + the
next-occurrences list from the preview endpoint, debounced 400ms) is always visible in both modes.

**Alternatives rejected:**
- **A single flat 16-item family Select with no tiering at all** — rejected as a regression from
  ADR-0009 §8's already-established beginner-first mandate: 16 is a worse scan than the 12 that
  mandate was already written against.
- **Simple mode as a genuinely separate, simplified schema** (e.g. its own small set of preset
  configs, converted to the full schema only on save) — rejected: this would reintroduce exactly
  the kind of "two parallel representations that can drift" problem the descriptor-driven design
  exists to avoid. Simple mode instead directly manipulates the same `family`/`params`/`times`
  fields the advanced mode does — `isSimpleRepresentable()` decides whether the CURRENT draft can
  be shown in simple mode's reduced controls, and a draft that cannot (e.g. one using `exclusions`,
  or a family simple mode has no intent for) forces advanced mode rather than lying about what is
  configured.

**Rationale:** Two real usage patterns exist — a beginner setting up "run this every day at 9" and
a power user who wants "the 15th and last day of the month, except August, at 8 and 17" — and
serving both from ONE data model with a curated front layer (rather than two data models, or one
maximalist UI) keeps the FE's validation, the preview integration, and the AI-assist's "apply to
builder" path uniform: `configToDraft`/`draftToConfig` are the only two conversion functions in
either direction, used regardless of which mode is currently displayed.

**Consequence:** `docs/next/workflows-uxui-spec.md` §4.5 was rewritten to the as-built two-mode
structure (superseding its REVISION 2 tier-based single-mode description); the AI-assist's
"alternative" response state (§4.5.5(b)) was extended to show a PREVIEW of the alternative
(sentence + note + next 4 runs) before the user applies it, using the same preview endpoint — the
user should never have to apply-then-inspect to find out what an AI-suggested alternative
actually does.

---

## What this ADR supersedes in ADR-0009 §3

| ADR-0009 §3 statement | Superseded by | What changed |
|---|---|---|
| "12 descriptor-driven families" | This ADR §1 | 4 families added — 16 total. The single-source `paramDescriptors()` design that made this cheap is UNCHANGED and is exactly what made the extension low-risk. |
| `weekly`'s descriptor implied a single `weekday` scalar (via the original 12-family table) | This ADR §2 | `weekly` now takes `weekdays` (a list); the old scalar is read-tolerated, not write-accepted. |
| The schedule block shape `{ family, params, tz? }` | This ADR §3 | Grew two optional keys: `times`, `exclusions`. Fully additive — an old block with neither key is unaffected. |
| "Eleven of the twelve compile to a REAL cron expression … only `every_n_minutes` stays a bespoke interval" | This ADR §4 | `last_working_day_of_month` is a SECOND bespoke (non-cron) family, added for a genuine library-defect reason, not a design preference. `CompiledSchedule` now has three kinds, not two. |
| (not addressed in ADR-0009 §3) | This ADR §6 | A new read-only preview endpoint did not exist before this batch. |

Everything else in ADR-0009 §3 (the single-source `paramDescriptors()` design, the closed-
vocabulary-over-raw-cron rejection, `every_n_hours`' hour-of-day-modulo-N doctrine, the day-31 skip
behavior) is UNCHANGED and remains authoritative.

---

## Related files

- `app/modules/Workflows/Enums/WorkflowScheduleFamily.php` — decision #1, #2 (16 families, `weekday_list` type, `supportsTimes()`)
- `app/modules/Workflows/Services/WorkflowScheduleCompiler.php` — decisions #1, #2, #3, #4, #5 (the family→cadence grammar, `expandTimes()`, `weekdayList()`, `compileLastWorkingDayOfMonth()`, `compileEveryNMonths()`)
- `app/modules/Workflows/Services/CompiledSchedule.php` — decisions #3, #4 (the `cron` list shape, the new `last_working_day` kind)
- `app/modules/Workflows/Services/WorkflowScheduleService.php` — decisions #3, #4, #6 (the `times`/`exclusions` post-filter loop, `nextLastWorkingDay()`, `nextOccurrences()`/`isApproximate()`)
- `app/modules/Workflows/Services/WorkflowScheduleRulesValidator.php` — decisions #2, #3 (the `weekday_list`/`times`/`exclusions` rules, the `checkEmpty` toggle backing decision #6)
- `app/modules/Workflows/Services/WorkflowScheduleFamilyCatalog.php` — decision #1 (the 16-family discovery source)
- `app/modules/Workflows/Http/Requests/SchedulePreviewRequest.php`, `Http/Controllers/WorkflowSchedulePreviewController.php` — decision #6
- `app/modules/Workflows/Agents/ScheduleAssistAgent.php` — decisions #1, #3, #5 (the prompt's vocabulary + semantic caveats, generated from the same descriptors)
- `app/modules/Workflows/routes/api.php` — the `workflows/meta/schedule-preview` route
- `resources/js/next/pages/workflows/WorkflowScheduleBuilder.vue`, `WorkflowScheduleAssist.vue` — decision #7
- `resources/js/next/pages/workflows/workflowSchedule.ts` — decision #7 (`describeSchedule`, `configToDraft`/`draftToConfig`, `isSimpleRepresentable`)
- `resources/js/next/app/stores/workflows.ts` — `schedulePreview()` store method (decision #6)
- `tests/Unit/Workflows/WorkflowScheduleServiceTest.php` — DST spring-forward AND fall-back pins, `times`/`exclusions`, `last_working_day_of_month`
- `tests/Unit/Workflows/WorkflowScheduleCompilerTest.php`
- `tests/Feature/WorkflowSchedulePreviewTest.php`
- `tests/Feature/WorkflowScheduleFamilyMetaTest.php`
- `tests/Feature/WorkflowScheduleAssistTest.php`
- `tests/Feature/WorkflowScheduleSweepTest.php`
- `docs/backend/workflows-api.md` — the updated Schedule section (16 families, `times`/`exclusions`, preview endpoint)
- `docs/next/workflows-uxui-spec.md` — §4.5, rewritten to the as-built two-mode schedule builder
- `docs/decisions/ADR-0009-workflows-rescope-typed-variables.md` — §3, partially superseded (see table above)
- `docs/decisions/ADR-0008-workflows-module-design.md` — #7, already superseded by ADR-0009 §3; unaffected further by this ADR
