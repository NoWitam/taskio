# ADR-0012 — Workflows schedule descriptor v2: compositional axes replace the family model

**Date:** 2026-07-13 (created)
**Status:** Accepted
**Module:** Workflows (`app/modules/Workflows/`)

> **Class location note (ADR-0052, 2026-08-25).** `WorkflowScheduleCompiler`, `ScheduleLimits` and
> the other `Schedule*` enums this ADR describes under `app/modules/Workflows/` were later extracted
> to the shared `App\Support\Recurrence` layer (`WorkflowScheduleCompiler` was also **renamed** to
> `ScheduleCompiler` in the move — the old class no longer exists), and the SHAPE half of validation
> moved with it as `RecurrenceDescriptorValidator`. The compositional-descriptor design recorded below
> is unchanged; only its file location and how a second module shares its validation changed. See
> ADR-0052.

---

## Context

ADR-0009 §3 replaced the Etap-5 4-preset schedule with 12 descriptor-driven families compiled to
`dragonmantank/cron-expression`; ADR-0010 grew that to 16 families and added `times[]` /
`exclusions` / a live preview endpoint. In practice the family model kept hitting the same wall:
every new real-world request ("every 15 minutes between 9 and 5", "the 1st and the 15th, except
August", "last working day of every quarter-end month") either needed a brand-new named family or
an ad-hoc combination of `times`/`exclusions` bolted onto an existing one. Growing the vocabulary
was cheap (ADR-0010 proved that), but the vocabulary itself was the wrong shape: a user's mental
model of a schedule is not "pick one of N presets" — it is "when, on which days, in which months",
three largely independent questions. This batch (phases 1-4b) replaces the closed family
enumeration with a COMPOSITIONAL descriptor of three axes, rebuilds the compiler and validator
around it, replaces the two-mode (simple/advanced) frontend with a three-tab builder over the same
three axes, and rebuilds the AI schedule-assist around explicit user approval instead of
auto-apply. This ADR documents those decisions and explicitly marks what it supersedes in ADR-0010
§7.

---

## Decisions

### 1. A compositional `{ time, day?, month? }` descriptor replaces the closed family enumeration

**Decision:** `trigger_config.schedule` is now `{ time, day?, month?, exclusions?, tz? }` — three
INDEPENDENT axes combined with AND (a fire happens only when time AND day AND month all match,
minus any exclusion), rather than one of a closed set of named presets. `time` is the only
required axis (`ScheduleTimeMode`: `at` / `every_minutes` / `every_hours`); `day`
(`ScheduleDayMode`: `every_day` / `every_n_days` / `weekdays` / `month_days` / `special`) and
`month` (`ScheduleMonthMode`: `every_month` / `every_n_months` / `months`) are optional and default
to no restriction. Every axis is validated by ONE shared `WorkflowScheduleRulesValidator` (write
path, AI-assist re-validation, and preview all delegate to it) and compiled by ONE
`WorkflowScheduleCompiler`. All numeric bounds live in one place, `ScheduleLimits`, mirrored
verbatim by the frontend as a TypeScript constant (`SCHEDULE_LIMITS`) — there is no discovery
endpoint to keep the two ends in sync, because the bound now lives in exactly one artifact per
side, not a runtime-served catalog.

**Alternatives rejected:**
- **Keep growing the family enumeration** (a 17th, 18th, … family per new request) — rejected:
  the family model's core weakness was never the COUNT of families, it was that a family bundled
  "which axes are restricted" and "how each is restricted" into one opaque name. Every genuinely
  new request this batch was scoped against (a bounded minute/hour grid, several fire times a day,
  a day-of-month set combined with a month set) was actually a NEW COMBINATION of axes that
  already existed separately in the family table — proof that the axes, not the presets built from
  them, were the right unit of abstraction all along.
- **Open the grammar to raw cron or RRULE** — rejected again, for the same reasons ADR-0008 #7,
  ADR-0009 §3 and ADR-0010 §1 already rejected it: no natural client-side validation, a large
  footgun surface for a non-technical user, and every concrete need this batch identified maps
  cleanly onto the bounded axis model without needing open-ended recurrence syntax.
- **A single flat descriptor with every field always present** (no per-axis `mode` discriminator,
  every possible field just optional) — rejected: without an explicit `mode` per axis, an
  ambiguous or contradictory combination of fields (e.g. both `weekdays` and `days` set) would need
  its own tie-breaking rule instead of being structurally impossible; the mode-owns-its-fields
  discipline (a field foreign to the chosen mode is a 422) is what makes the descriptor
  self-describing and keeps the "foreign parameter" guard from ADR-0009 §3 intact.

**Rationale:** A compositional model scales by ADDING independence, not by enumerating the
cross-product of every combination up front. Three axes with 3-5 modes each already express
everything the 16-family model could, plus every combination the family model would have needed a
17th+ preset for (e.g. "day 15 of every third month" — previously impossible, now
`day.month_days:[15]` × `month.every_n_months:{n:3}`), without a single new top-level concept.

**Consequence:** `docs/backend/workflows-api.md`'s Schedule section is rewritten around the three
axis tables (replacing the family table entirely); `GET /workflows/meta/schedule-families` is
REMOVED (there is no vocabulary left to discover — see decision #6 for why no migration was
needed for existing rows).

---

### 2. Every minute/hour cadence is a wall-clock grid — the `interval` kind and `approximate` are retired

**Decision:** `time.every_minutes` and `time.every_hours` are now WALL-CLOCK grids (`:00,:15,:30,
:45` for `minutes:15`; `00,05,10,…` mod 24 for `hours:5`), identical in spirit to how
`every_n_hours` already behaved under the family model. There is no more activation-phased
interval kind at all — `CompiledSchedule` has exactly two kinds now (`cron` list and
`last_working_day`), down from the family model's `interval` / `cron` / `last_working_day` three.
Consequently `WorkflowScheduleService::isApproximate()` is now HARD-CODED `false` — every v2
cadence is calendar-anchored, so a preview is always exact. The method (and the response field) is
KEPT, not deleted, purely so the preview response shape stays stable; it must never be read as a
live signal again.

**Alternatives rejected:**
- **Keep `every_n_minutes` as an activation-phased interval, matching its Etap-5/ADR-0009
  behavior** — rejected: this was consistently the single most confusing part of the family model
  in practice — an "every 15 minutes" schedule armed at 10:02 fired at 10:17, 10:32, … rather than
  the wall-clock grid every user actually expected (:00/:15/:30/:45), and it was the ONLY reason
  `approximate`/`isInterval()` existed anywhere in the codebase. Retiring it removes an entire
  compiled kind, an entire response field's live meaning, and a whole class of "why did my preview
  not match what actually fired" support question.
- **Offer BOTH a wall-clock grid mode and an activation-phased interval mode on the same axis** —
  rejected as unnecessary complexity: no concrete use case in this batch's scope needed the old
  phased behavior once the wall-clock grid existed, and offering both would have reintroduced
  `approximate` as a real, load-bearing per-config flag instead of a retired one.

**Rationale:** A schedule a user can preview accurately is more valuable than a schedule that is
"exactly N minutes apart from whenever it happened to start" — the wall-clock grid is also simply
what most users expect from "every 15 minutes" in the first place (it is how cron's `*/15` has
always behaved, and how Laravel's own `everyFifteenMinutes()` behaves).

**Consequence:** the live preview (`POST /workflows/meta/schedule-preview`) is unconditionally
exact; `docs/backend/workflows-api.md` states `approximate` is always `false` in v2; the frontend
no longer has any UI copy conditioned on an "indicative, not exact" preview state.

---

### 3. `last_working_day` stays bespoke, and now composes with the month axis

**Decision:** `day.special:last_working_day` remains a bespoke (non-cron) calculation in
`WorkflowScheduleService`, carried over from ADR-0010 #4 — the `dragonmantank/cron-expression`
`LW` token is still defective in the installed version (verified again against the current
codebase, not just historically). What changes in v2: it now RESTRICTS to the months the `month`
axis allows (`WorkflowScheduleCompiler` expands the month axis into a concrete integer set the
bespoke calculation iterates, skipping disallowed months), so "last working day of every
quarter-end month" is expressible as one schedule instead of needing four separate ones. It still
REQUIRES `time.mode = at` (enforced by `WorkflowScheduleRulesValidator`, a dedicated 422 on
`time.mode`) because it fires at explicit `HH:mm` times, never on a minute/hour grid.

**Alternatives rejected:**
- **Re-attempt the `LW` cron token now that the compiler is rewritten anyway** — rejected: the
  defect is in the third-party library, not in anything this rebuild touches; re-verifying and
  re-rejecting it would just re-confirm ADR-0010 #4's finding for no benefit.
- **Drop the month-restriction composition and keep `last_working_day` as an all-months-only
  rule** — rejected: this was a genuine, named gap in the family model (there was no way to say
  "last working day, but only in March/June/September/December" without four schedules), and the
  compositional model makes closing it nearly free (the bespoke calculation already needed to know
  which months to consider; it previously just always considered all twelve).

**Rationale:** the compositional model should not regress a capability the family model, however
awkwardly, could still express (four separate `last_working_day_of_month` schedules with different
`month` filters was never possible in the family model either, so this is a net new capability, not
a preserved one) — but since the bespoke calculation and the month axis both already exist, this
is the smallest safe way to let them compose.

**Consequence:** `CompiledSchedule::lastWorkingDay()` carries a `months` array (from the month
axis, defaulting to all twelve when unrestricted); pinned by
`WorkflowScheduleCompilerTest::test_last_working_day_carries_every_time_and_the_allowed_month_filter`
and `test_last_working_day_month_filter_expands_an_every_n_months_grid`.

---

### 4. Live preview stays flat with an `anchor`, not `previous`/`cursor` — rejected for contract simplicity

**Decision:** `POST /workflows/meta/schedule-preview` keeps the SAME flat response shape as before
(`{ occurrences, count, empty, approximate }`) and adds exactly ONE new request field, `anchor`
(an optional ISO-8601 datetime). When present, `occurrences[0]` is the occurrence AT-OR-BEFORE the
anchor (when one exists within the horizon), followed by the ascending occurrences after it.
PAGING is simply a re-call with `anchor` set to the last occurrence already shown — there is no
separate `previous` field and no separate `cursor` field. An earlier frontend-side sketch of this
contract (written before the backend phase) proposed a richer shape —
`{ occurrences, previous?, empty, cursor? }` — which this decision explicitly REJECTS in favor of
the flatter one actually implemented.

**Alternatives rejected:**
- **A dedicated `previous` field carrying the prev-or-at occurrence separately from `occurrences`**
  — rejected: it does not change what information the client has (the prev-or-at occurrence is
  already `occurrences[0]` when an anchor is supplied), it just adds a second field the client
  would need to special-case, and it does not simplify the "is this tile the previous one"
  question — the client still needs to compare an occurrence against the anchor either way
  (`isPreviousOccurrence()` on the frontend does exactly this comparison regardless of which shape
  the field arrived in).
- **A `cursor` field distinct from re-sending `anchor`** — rejected: a cursor would be a SECOND way
  to express "continue from here" alongside `anchor`, when `anchor` already does that job — paging
  forward is simply "preview again, anchored on the last thing I already have." Introducing a
  distinct cursor concept would mean the client and the server both need to agree on TWO paging
  primitives instead of one, for no behavioral gain.

**Rationale:** the whole point of a "single source of truth for occurrence math" (ADR-0010 #6) is
undermined if the WIRE CONTRACT itself grows extra fields the client has to reconcile with data it
can already derive from the plain list — a flatter contract is strictly easier for the frontend to
consume correctly, and easier for this document to describe unambiguously.

**Consequence:** `resources/js/next/pages/workflows/workflowSchedule.ts::isPreviousOccurrence()`
compares a returned occurrence against the anchor client-side (a simple `<=` on parsed instants);
`WorkflowSchedulePreviewStrip.vue` pages by re-calling `store.schedulePreview(config, { anchor:
lastShown })`, never a `cursor` param. `docs/next/workflows-uxui-spec.md` §4.5.4's `[backend-dep]`
note (written against the rejected `previous`/`cursor` shape) is corrected to the shape above.

---

### 5. The AI schedule-assist is a reviewed proposal via a modal — auto-apply is retired

**Decision:** `WorkflowScheduleAssistModal.vue` (replacing the inline `WorkflowScheduleAssist.vue`)
is a focus-trapped `Modal` opened from a "Zaplanuj z AI" affordance on the schedule summary. It
NEVER auto-applies a result — even a `feasible:true` response is rendered as a *proposal* (the
deterministic `describeSchedule` sentence + a compact preview of the next occurrences, fetched
through the SAME preview endpoint) that the user must explicitly commit via a sticky-footer
"Zastosuj" button. Cancelling or dismissing the modal discards the proposal; the builder's draft is
untouched until the user approves.

**Alternatives rejected:**
- **Auto-apply a `feasible:true` result directly into the builder's draft** — rejected: the
  backend's own re-validation guarantee (ADR documented in `docs/backend/workflows-api.md`'s
  schedule-assist section) proves a returned config is STRUCTURALLY valid and compilable, but it
  explicitly cannot prove it SEMANTICALLY matches what the user meant. Auto-applying would silently
  overwrite a user's in-progress draft with a config that might be confidently wrong (e.g. the
  model mis-reading "every weekday" as a plain daily schedule) with no review step before the
  mistake is baked into the builder state.
- **An inline, always-visible assist panel** (the REV3 shape) — rejected in favor of a modal:
  reviewing a natural-language proposal (a sentence, a preview, an alternative note when
  infeasible) is a distinct, focused task from editing the three-axis builder concurrently visible
  behind it; a modal makes the review step unambiguous and its dismissal/commit boundary explicit,
  where an always-inline panel blurred "is this still just a suggestion or has it already changed
  my draft".

**Rationale:** the backend already treats the model's self-report as untrusted and re-validates it
server-side (ADR-0010's honesty-limit note, unchanged in this revision); the frontend's job is to
extend that same untrusting posture to the HUMAN in the loop — show what would happen, never do it
silently.

**Consequence:** `docs/next/workflows-uxui-spec.md`'s REV3 auto-apply description is superseded (it
already carries a "REV3 auto-apply is GONE" marker); the in-app docs page's schedule section
documents the modal, not the retired inline component.

---

### 6. A read-shim (`LegacyScheduleUpgrader`) replaces a data migration

**Decision:** Every schedule stored before this revision — in either the Etap-5 preset shape or
the ADR-0009/ADR-0010 `{ family, params, tz?, times?, exclusions? }` shape — is upgraded to v2
TRANSPARENTLY at every read/compile boundary (`WorkflowResource`, `WorkflowScheduleService`,
`WorkflowScheduleCompiler`, and the AI-assist re-validation gate) by `LegacyScheduleUpgrader`. NO
data migration or backfill was run. Detection is structural: a legacy block always carries a
`family` key, a v2 block never does; a v2 (or already-upgraded) block passes through unchanged
(idempotent), and an unrecognised family is passed through unchanged too, so it fails v2 validation
honestly on its missing `time` rather than silently coercing into something arbitrary.

**Alternatives rejected:**
- **A one-time migration rewriting every stored `trigger_config.schedule` to v2** — rejected for
  the same reason ADR-0010 §2 rejected migrating `weekly`'s scalar `weekday`: the project's safety
  rules require explicit approval before migrations that modify data, and a code-level
  compatibility shim achieves IDENTICAL runtime behavior for existing rows without touching data at
  all — strictly safer for a change with no behavioral difference for the old shape.
- **Support BOTH shapes forever on the write path too** (accept either `{ family, params }` or
  `{ time, day, month }` on `POST`/`PUT`) — rejected: this would mean every future consumer of the
  write contract (validation, the AI-assist prompt, the frontend builder) has to keep reasoning
  about two live write shapes indefinitely. Read-tolerance (old rows keep working) plus a
  write-time BREAKING change (new/edited schedules must use v2) is the smaller permanent surface —
  exactly the "one write shape, one legacy read shape, not two live write shapes forever" doctrine
  ADR-0010 §2 already established for the narrower `weekly` case, now applied to the whole
  descriptor.

**Rationale:** the read-shim gives every existing schedule workflow continuity (it keeps firing,
and its definition keeps rendering in the editor) without any risk to stored data, while still
letting the write contract be unambiguous and simple going forward.

**Consequence:** `docs/backend/workflows-api.md`'s top banner carries an explicit BREAKING notice;
the Schedule section's "Legacy read-shim" subsection documents the upgrade mapping;
`tests/Unit/Workflows/WorkflowScheduleLegacyUpgraderTest.php` pins the full family→axis mapping
table `LegacyScheduleUpgrader`'s docblock describes.

---

### 7. A freshly-created schedule defaults `tz` to the browser's timezone; an edited one keeps its stored value

**Decision:** When the schedule builder opens for a NEW workflow (no `trigger_config.schedule` yet
to seed from), the draft's `tz` defaults to `Intl.DateTimeFormat().resolvedOptions().timeZone` (the
browser's own IANA zone) rather than an empty string (server UTC). Opening the builder for an
EXISTING schedule always seeds from its stored `tz` unchanged — the browser-default behavior only
applies to a brand-new draft.

**Alternatives rejected:**
- **Always default to blank (server UTC)**, matching the field's own nullable semantics — rejected
  as a real-world footgun: a user in Warsaw creating a "daily at 09:00" schedule almost always
  means 09:00 THEIR time, not 09:00 UTC; defaulting blank silently produces a schedule that fires
  at the wrong local hour unless the user remembers to fill in `tz` explicitly every time.
- **Always default to the workspace's configured timezone** (if one exists) instead of the
  browser's — considered and deferred: Taskio has no single per-workspace timezone setting today:
  a browser-local default is the closest available proxy to "what the user actually means" without
  inventing a new workspace-level setting this batch was not scoped to add.

**Rationale:** defaulting a NEW schedule to the browser's timezone matches the overwhelming common
case (a user scheduling something for their own local time) while the read path for an EXISTING
schedule never overrides a value someone already deliberately set (e.g. a schedule intentionally
authored in a different region's timezone).

**Consequence:** `WorkflowScheduleBuilder.vue`'s draft-seeding path (via `emptyScheduleDraft()` plus
the host's own fresh-schedule branch) resolves the browser zone once at drawer-open time; an
edited schedule's `configToDraft()` path is unaffected and always reflects the stored `tz`.

---

## What this ADR supersedes

| Prior decision | Superseded by | What changed |
|---|---|---|
| ADR-0010 §7 — "Frontend: simple/advanced progressive-disclosure modes as a curated layer over the descriptors" | This ADR §1, §5 | The two-mode (simple/advanced) frontend is RETIRED. The builder is now a single three-tab surface (Czas / Dzień / Miesiąc) over the same three axes for every schedule, with no simple/advanced split; the AI assist moved from an inline panel to a reviewed modal (§5). |
| ADR-0009 §3 / ADR-0010 §1 — the closed 12/16-family vocabulary | This ADR §1 | The family enumeration is RETIRED in favor of the three-axis compositional descriptor. The single-source `paramDescriptors()`/enum-driven design philosophy those ADRs established is NOT rejected — it is exactly what this ADR's axis enums (`ScheduleTimeMode`/`ScheduleDayMode`/`ScheduleMonthMode`/`ScheduleDaySpecial`) and `ScheduleLimits` continue to apply, just to axes instead of whole presets. |
| ADR-0010 §5 — `every_n_months` is January-anchored, matching `every_n_hours`' doctrine | Unchanged, restated | Still true of `month.every_n_months` in v2; the modulo-year doctrine and its consistency rationale carry over unchanged. |
| ADR-0010 §4 — `last_working_day_of_month` is bespoke | This ADR §3 (extends, does not replace) | The bespoke calculation itself is UNCHANGED; it now additionally composes with the month axis. |
| ADR-0010 §6 — live preview is the only place occurrence dates are computed | This ADR §2, §4 (extends) | The single-preview-source principle is UNCHANGED; `approximate` is now always false (§2) and the endpoint gained `anchor`-based paging instead of the richer `previous`/`cursor` shape a pre-implementation sketch had proposed (§4). |

Everything else in ADR-0009 §3 and ADR-0010 (the tenancy model, the arming lifecycle, the
race-safe sweep CAS, the run-cap/cost doctrine, the DST spring-forward/fall-back handling) is
UNCHANGED and remains authoritative.

---

## Related files

- `app/modules/Workflows/Enums/ScheduleTimeMode.php`, `ScheduleDayMode.php`, `ScheduleMonthMode.php`, `ScheduleDaySpecial.php` — decision #1 (the axis/mode enums)
- `app/modules/Workflows/Enums/ScheduleLimits.php` — decision #1 (the single numeric-bound contract)
- `app/modules/Workflows/Services/WorkflowScheduleRulesValidator.php` — decision #1 (the one schedule-block rule set, per-axis mode-owns-its-fields discipline)
- `app/modules/Workflows/Services/WorkflowScheduleCompiler.php` — decisions #1, #2, #3 (the axis → cron-list/last-working-day compiler, the month-filter expansion)
- `app/modules/Workflows/Services/CompiledSchedule.php` — decision #2 (two kinds, not three — no more `interval`)
- `app/modules/Workflows/Services/WorkflowScheduleService.php` — decisions #2, #3, #4 (`isApproximate()` hard-false, `last_working_day` month filter, `occurrencesFrom()`/anchor)
- `app/modules/Workflows/Services/LegacyScheduleUpgrader.php` — decision #6 (the read-shim, no data migration)
- `app/modules/Workflows/Http/Requests/SchedulePreviewRequest.php`, `Http/Controllers/WorkflowSchedulePreviewController.php` — decision #4 (`anchor`)
- `app/modules/Workflows/Agents/ScheduleAssistAgent.php` — decision #1 (prompt vocabulary built from the axis enums), decision #5 (never auto-applies — enforced entirely on the frontend, the backend contract is unchanged)
- `app/modules/Workflows/Http/Requests/ScheduleAssistRequest.php` — docblock only, corrected to describe the current (non-family) flow
- `resources/js/next/pages/workflows/workflowSchedule.ts` — decisions #1, #4, #7 (`ScheduleDraft`, `SCHEDULE_LIMITS`, `describeSchedule`, `isPreviousOccurrence`, `configToDraft`/`draftToConfig`)
- `resources/js/next/pages/workflows/WorkflowScheduleBuilder.vue` — decisions #1, #7 (the three-tab host, browser-tz default for a fresh draft)
- `resources/js/next/pages/workflows/WorkflowScheduleTimePanel.vue`, `WorkflowScheduleDayPanel.vue`, `WorkflowScheduleMonthPanel.vue`, `WorkflowScheduleWindowField.vue` — decision #1 (per-axis tab panels, the shared window pattern)
- `resources/js/next/pages/workflows/WorkflowScheduleSummary.vue`, `WorkflowSchedulePreviewStrip.vue` — decisions #2, #4 (the sentence + the anchored preview strip)
- `resources/js/next/pages/workflows/WorkflowScheduleAssistModal.vue` (replaces `WorkflowScheduleAssist.vue`) — decision #5
- `tests/Unit/Workflows/WorkflowScheduleCompilerTest.php`, `WorkflowScheduleServiceTest.php`, `WorkflowScheduleLegacyUpgraderTest.php`
- `tests/Feature/WorkflowSchedulePreviewTest.php`, `WorkflowScheduleAssistTest.php`
- `docs/backend/workflows-api.md` — the rewritten Schedule section + endpoint contracts
- `docs/next/workflows-uxui-spec.md` — §4.5 (the REV4 three-tab builder), §4.5.4 (corrected anchor/paging contract)
- `docs/decisions/ADR-0010-workflows-schedule-rebuild.md` — §7 marked superseded by this ADR
- `docs/decisions/ADR-0009-workflows-rescope-typed-variables.md` — §3, the single-source descriptor design philosophy this ADR continues to apply
