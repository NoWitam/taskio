# ADR-0009 — Workflows 5.1 re-scope: typed variables, 2 triggers, 2 steps, AI schedule assist

**Date:** 2026-07-09 (created)
**Status:** Accepted
**Module:** Workflows (`app/modules/Workflows/`), Forms, Tasks

---

## Context

ADR-0008 documented the Etap-5 Workflows MVP: 5 trigger types (`task_created`,
`task_status_changed`, `form_submitted`, `approval_finished`, `schedule`), 4 step types
(`create_task`, `assign_bot`, `attach_form`, `start_approval`), and a flat untyped condition
model (`{field, operator, value}` with 4 operators applicable to any trigger). After that build
shipped, the USER RE-SCOPED the module (Etap 5.1, batches B1–B7): the trigger/step surface was
DELIBERATELY SHRUNK and made TYPED, a real 12-family schedule cron compiler replaced the 4
hand-coded presets, and an AI natural-language schedule assistant was added. This record
documents the 5.1 decisions and their reasoning, mirroring the format of ADR-0007 (Bot module)
and ADR-0008 (Workflows Etap-5). **ADR-0008 remains the reference for the run-engine machinery
that did NOT change** (claim/release/reaper, loop depth, cost caps, the `{{...}}` resolver's
whitelist property, manual-run-as-origin) — this ADR records only what 5.1 changed, and marks
ADR-0008's superseded sections.

---

## Decisions

### 1. 5→2 triggers, 4→2 steps: user re-scope, form/pipeline/assignee folded INTO `create_task`

**Decision:** `WorkflowTriggerType` shrank to `form_submitted` and `schedule` only —
`task_created`, `task_status_changed`, and `approval_finished` were REMOVED, along with their
targeting UI (`LabelSelect`, status pickers, `PipelineSelect`, outcome selects) and their
observer/service dispatch hooks (`TaskObserver`'s two hooks,
`ApprovalService::dispatchWorkflowTrigger()`). `WorkflowStepType` shrank to `create_task` and
`create_form_report` — `assign_bot`, `attach_form`, and `start_approval` were REMOVED as
standalone steps; the capabilities they provided (assigning a bot, attaching a form, attaching
an approval pipeline) were folded IN as first-class fields on `create_task`
(`assignee_type`/`assignee_id`, `form_id`, `approval_pipeline_id`).

**Alternatives rejected:** Keeping all 5 triggers/4 steps and layering the new typed-variable
system on top was considered and rejected. The re-scope was a deliberate, explicit USER decision
(not discovered through implementation friction) — the wider Etap-5 surface was judged to have
grown ahead of real usage, and a smaller, typed, well-understood surface was preferred over a
wide, partially-typed one. Task-created / task-status-changed / approval-finished triggers had
no typed-field system to type (their payloads are entity snapshots, not form answer maps), so
extending the typed-condition work to them would have meant either leaving them condition-less
or inventing a second, parallel typed system for entity fields — both rejected as scope
expansion the re-scope was explicitly trying to avoid.

**Rationale:** `create_task` absorbing assignment/form/pipeline as fields (rather than three
separate downstream steps referencing the created task's id) removes an entire class of
`{{steps.<key>.task_id}}` wiring the user previously had to do by hand for the extremely common
"create a task AND assign/attach things to it" pattern — the common case becomes ONE step
instead of up to four chained ones. The two SURVIVING triggers are exactly the two that have a
natural TYPED field source: `form_submitted` (a form's own answer schema) and `schedule` (no
fields at all, so nothing to type). This is what makes the typed variable/condition system
(decision #2) coherent — it was not retrofitted onto triggers that cannot support it.

**Consequence:** Any workflow definition or code path referencing the removed trigger/step
types is STALE. `docs/backend/workflows-api.md` was rewritten (not appended-to) to the 5.1
contract; ADR-0008's sections describing the removed types are marked SUPERSEDED below.

---

### 2. Typed variable system — ONE identity, TWO serializations, identity-only directive (the wfType fiction correction)

**Decision:** A workflow variable reference is canonically `{ source: trigger|steps, path, type
}` — ONE identity, served by `GET /forms/{form}/workflow-catalog`. It is serialized TWO ways
depending on the field surface:

1. **Text/markdown fields** (`create_task.title`/`.description`,
   `create_form_report.name`/`.guidelines`) carry the next editor's markdown **variable
   directive** — `@[variable]("{...}")`. The directive is **IDENTITY-ONLY**: it carries `data.id`
   (the path) and the editor's own rendering primitive, but **NO extra workflow-type field**. The
   REAL workflow type (`text|number|boolean|date|enum|multi`) is recovered from the **catalog by
   path** at resolve time (`WorkflowVariableResolver` never trusts a type embedded in the
   directive — there isn't one to trust).
2. **Non-text (structured) fields** (`create_task.priority`/`.deadline`,
   `create_form_report.submissions_from`/`.submissions_to`) carry the **`{kind:
   literal|variable}` union** — a `variable` kind's `ref` DOES carry an explicit `type`, because
   these fields have no markdown editor to defer type-recovery to; the ref is the only place the
   type can live.

Operations on a resolved variable (string concatenation, date arithmetic, computed
transformations) are **deliberately NOT built** — the resolver performs identity lookup +
one-shot type coercion only (see the coercion table in `docs/backend/workflows-api.md`).

**The wfType fiction incident (the correction that shaped this decision):** during the 5.1
design, an early iteration of the editor directive was drafted to carry an EXTRA type field
(internally called `wfType` during design) alongside the directive's identity, on the assumption
that carrying the type inline would let the resolver skip a catalog lookup. This was CORRECTED
before implementation: a type field embedded in user-editable markdown content is untrustworthy
input (the directive lives inside a text field a user can, in principle, hand-edit or a client
bug could desync from the catalog) and, more fundamentally, REDUNDANT — the catalog is already
the single source of truth for a path's type (`WorkflowVariableCatalogService` derives it from
the form schema), so a second copy of that type sitting in the directive can only ever drift
from the catalog, never legitimately disagree with it. The directive was corrected to be
IDENTITY-ONLY (`data.id` + the editor's own rendering primitive) before this shipped — `wfType`
never reached the implemented contract. This ADR records the incident specifically so a future
contributor who finds a stray `wfType`-shaped idea in a draft/branch understands it was
considered and rejected, not merely forgotten.

**Alternatives rejected:**
- **A single unified serialization** (e.g. always the `{kind}` union, even inside markdown text)
  was rejected — text fields need to interleave literal prose and variable references inline
  (`"Follow up on {variable chip}"`), which the union shape cannot express (it is an
  all-or-nothing field value, not an inline token inside a string). The directive format is the
  markdown editor's own established mechanism for inline non-text content; reusing it here (with
  the resolver reading `data.id` from the encoded payload) is smaller than inventing a rival
  inline-token syntax.
- **A type field embedded in the directive** ("wfType") — rejected per the incident above.
- **Building an operations pipeline now** (format-date, concatenate, etc.) — rejected as scope
  the re-scope was explicitly trying to shed. The MVP need is "reference a value, optionally
  literal-or-variable" — no user-facing requirement for computed transformations existed at
  design time.

**Rationale:** ONE canonical identity means the catalog, the resolver, and both serializations
can never independently drift on what a given `path` means or what type it carries — there is
exactly one place (`WorkflowVariableCatalogService`) that decides a path's type, and both
consumers (directive-by-path lookup, union-ref explicit type) either defer to it or state a type
that must match it. The resolver's WHITELIST property (only `trigger`/`steps` roots are
readable — see ADR-0008 #4, unchanged) is preserved identically across both serializations,
because both ultimately resolve through the same `Arr::get`-against-context mechanism.

**Consequence — PLANNED, not built:** an operations pipeline (computed values: string
concatenation, date formatting, conditional value selection) is explicitly deferred. If a real
need arises, it should be added as explicit, individually-reviewed whitelisted FUNCTIONS on top
of the existing resolver (per ADR-0008 #4's same guidance), not by generalizing either
serialization into an expression language.

---

### 3. Schedule families → a real cron compiler (dragonmantank), `every_n_minutes` bespoke

**Decision:** The 4 hand-coded Etap-5 presets (`every_n_minutes`, `hourly`, `daily`, `weekly`)
were replaced by **12 descriptor-driven families** (`WorkflowScheduleFamily`): `every_n_minutes`,
`hourly`, `hourly_at`, `every_n_hours`, `daily`, `twice_daily`, `weekly`, `monthly`,
`twice_monthly`, `last_day_of_month`, `quarterly`, `yearly`. Eleven of the twelve compile to a
REAL cron expression evaluated by `dragonmantank/cron-expression` (already a transitive
Laravel dependency — no new package introduced); only `every_n_minutes` stays a bespoke
interval (`$from + N minutes`, phased on the arm instant, deliberately NOT cron `*/N` which
would snap to wall-clock boundaries — preserving the historical Etap-5 behavior for that one
family). Every family's parameter shape is declared ONCE, as a `paramDescriptors()` method on
the `WorkflowScheduleFamily` enum — this single source drives THREE consumers: the write-path
validation (`WorkflowScheduleRulesValidator`), the discovery endpoint
(`GET /workflows/meta/schedule-families`, via `WorkflowScheduleFamilyCatalog`), and the AI
assist's prompt vocabulary (`ScheduleAssistAgent::vocabularySection()`).

**Alternatives rejected:**
- **Keep the 4 presets, add more presets ad hoc** — rejected: each additional cadence
  (twice-daily, month-end, quarterly, yearly…) would have meant another hand-coded
  `WorkflowScheduleService::nextDueAt()` match arm reimplementing cron-like logic in bespoke
  Carbon arithmetic, with no single source for the AI/discovery endpoint to read validated
  parameter shapes from. This does not scale past a handful of cadences.
- **Accept raw cron strings from the user** — rejected for the SAME reason ADR-0008 #7 rejected
  it originally: cron syntax is a well-known footgun (ambiguous field order, silent typos, no
  natural client-side validation) and the descriptor-driven approach gets the coverage benefit
  of real cron semantics WITHOUT exposing cron syntax to the user — the user picks a family +
  fills typed params (an int, a time, a weekday), and the compiler produces the cron string
  internally.
- **A different cron library** — `dragonmantank/cron-expression` was chosen because it was
  already reachable as a Laravel scheduler dependency and directly supports the `L` (last
  calendar day) token `last_day_of_month` needs, avoiding a bespoke month-end calculation.

**Rationale:** The descriptor-single-source design is the load-bearing property: because
`paramDescriptors()` is the ONE place a family's parameter shape (name, type, required, bounds,
`lt` ordering relations) is declared, the write-path validator, the FE-facing discovery
endpoint, and the AI-assist prompt CANNOT independently drift on what counts as valid — a
family's bounds can only be changed in one place, and that change automatically propagates to
validation, discovery, and the AI vocabulary simultaneously. `every_n_hours` compiling to
`M */N * * *` deliberately reproduces Laravel's `everyNHours()` HOUR-OF-DAY-MODULO-N semantics
(not a rolling interval) — an accepted quirk inherited from cron's own field semantics, not a
bug, and documented explicitly (both in the compiler's docblock and the API doc) so it is never
"fixed" into a rolling interval without realizing every_n_minutes already covers that use case.

**Consequence:** `WorkflowScheduleCompiler` is now the ONLY place the family→cron/interval
grammar lives (mirrors ADR-0008 #7's "one source" principle, just realized against real cron
instead of bespoke Carbon branches). Day-31/day-30/day-29 in a shorter month SKIPS that month's
fire rather than clamping (cron's native behavior, matches Laravel's `monthlyOn(31)`) —
`last_day_of_month` exists specifically as the guaranteed-month-end escape hatch for callers who
need that instead.

---

### 4. AI schedule-assist honesty contract — untrusted model, dual-gate re-validation, feasible-downgrade, alternative channel

**Decision:** `POST /workflows/schedule-assist` (`WorkflowScheduleAssistService` +
`ScheduleAssistAgent`) converts a natural-language schedule description into a structured
config, or an honest report of infeasibility. The model's self-reported `feasible`/`config` is
**NEVER trusted directly** — every response goes through:

1. Defensive JSON parsing (malformed/empty → a safe `feasible:false` envelope, never a 500).
2. Response-key WHITELISTING (unknown keys the model invented are dropped before they reach the
   API caller).
3. RE-VALIDATION of any `config` claimed feasible against the EXACT SAME rules the human write
   path uses (`WorkflowScheduleRulesValidator`) PLUS a real compile attempt
   (`WorkflowScheduleCompiler::compile()`) — either failing downgrades the response to
   `feasible:false`, `config:null`, with the validation failure appended to `unsupported`.
4. The SAME gate applied to `alternative.config`; a failing alternative is dropped
   (`alternative:null`) rather than surfaced broken.
5. A per-user rate limit (`assist_rate_per_minute`, default 5/min) — a SEPARATE meter from the
   workflow run-budget caps, because a planning call creates nothing.

**Alternatives rejected:**
- **Trust the model's `feasible:true`/`config` at face value** — rejected outright: an LLM can
  hallucinate a family name, invent a parameter, or misjudge bounds; surfacing an
  unvalidated/uncompilable config to the write path would either 422 confusingly on save (the
  user did nothing wrong; the AI did) or, worse, silently produce a config that "validates" by
  accident but does not mean what the user asked. Re-validation against the WRITE PATH's own
  rules closes this gap completely for STRUCTURAL/compile validity.
- **Structured-output / schema-constrained generation instead of free-text JSON** — considered,
  but the agent intentionally emits a raw JSON STRING (no tool calls, no structured-output
  schema) so the malformed-JSON defensive path stays REAL and testable, and so the model can
  return the WHOLE envelope (`unsupported`/`alternative`/`explanation`) uniformly in one shot
  without fighting a rigid schema around optional/nullable fields.
- **Silently approximate on the model's behalf** — rejected: if the model's best understanding
  doesn't match the vocabulary exactly, the contract requires it to say so
  (`unsupported: [...]`) and offer an approximation ONLY through the separate `alternative`
  channel with an honest `note` — never mixed into the trusted `config` as if it were exact.

**Rationale:** "Re-validate, don't trust" is the same posture the codebase already takes toward
any untrusted input (the write-path FormRequest validates regardless of what a client claims);
applying it to AI output specifically closes the gap an LLM's inherent unreliability opens. The
`unsupported`/`alternative` split gives the UI (and the user) an honest, actionable signal
instead of a binary success/failure — "here's exactly what I couldn't express, and here's the
closest thing I CAN express, with the difference stated" is strictly more useful than a bare
422-equivalent failure.

**The residual honesty limit (accepted, not solved).** Re-validation proves a returned config is
STRUCTURALLY valid and COMPILABLE — it CANNOT prove the config is SEMANTICALLY what the user
asked for. If the model mis-reads "every weekday" as `daily` and wrongly self-reports
`feasible:true`, the backend has no way to detect that the resulting `daily` schedule (which IS
a valid, compilable config) does not actually mean "weekdays only" — there is no oracle server-
side for "does this config match this English sentence's intent." Mitigations, not solutions:
(a) the agent's instructions enumerate the CLOSED family vocabulary explicitly and forbid
silently approximating into `config` (an approximation must go through `alternative` + a stated
`note`); (b) the frontend always surfaces `explanation` so a human reviews the AI's own summary
before saving; (c) nothing about this endpoint is closed-loop-verifiable purely server-side —
this is accepted as inherent to natural-language-to-structured-config translation, not specific
to this implementation.

**Consequence:** the throttle (`assist_rate_per_minute`) requires the DEFAULT cache store to be
PERSISTENT (`database` in production; `array` resets per-process, fine only for tests) — see the
Ops notes in `docs/backend/workflows-api.md`.

---

### 5. Source vocabulary `manual | task` (bot NOT distinguishable — deferred); `anonymous` as derived tri-state

**Decision:** `form_submitted`'s `trigger_config.source.in` accepts a subset of
`['manual', 'task']`, DERIVED purely from the submission's polymorphic `submittable` morph at
payload-build time (`WorkflowTriggerPayloadFactory::normalizeSource()`): a task-attached
submission normalizes to `'task'`, everything else to `'manual'`. `trigger_config.anonymous` is
a plain NULLABLE BOOLEAN (not a 3-value enum) — `null` genuinely means "match either"; the
tri-state is the type's own null/true/false, not a separate vocabulary value. Matching reads
`form.is_anonymous` straight off the trigger payload snapshot (already whitelisted in), so no
extra query is needed at dispatch time.

**Alternatives rejected:** A THIRD source value for "bot-authored" was considered and rejected
for THIS batch — the submittable morph (`Task` vs. none) answers "was this submission attached
to a task," which is orthogonal to "who/what actually filled it in" (a human working a task's
form vs. a bot executing a task's form-fill tool both produce a `task`-sourced submission today
— see `App\Modules\Bot\Tools\FillFormTool` filling a task-attached form exactly the same way a
human would through `TaskService`). Distinguishing bot-authored submissions would require a NEW
column tracking submission authorship (who/what actually submitted, not just what it's attached
to) — a real schema change, not a vocabulary tweak, and out of scope for this re-scope batch.

**Rationale:** `manual`/`task` answers a question the system can already answer for free (it's
inherent to the submittable morph, already loaded for other reasons); a bot-authorship signal
would need new data collection the module does not currently have anywhere. Shipping the
achievable two-value vocabulary now, and DOCUMENTING the gap explicitly (rather than pretending
`source` distinguishes bot activity, which it does not), is the honest MVP scope. Making
`anonymous` a bare nullable boolean rather than an enum keeps the FIELD's own type expressive
enough for the tri-state without inventing a parallel `'any'|'true'|'false'` string vocabulary —
Laravel's nullable-boolean validation (`nullable`, `boolean`) already expresses exactly this.

**Consequence — PLANNED, not built:** a `source` value (or a separate field) distinguishing
bot-authored submissions is explicitly DEFERRED, tracked as a real schema-change follow-up, not
silently accepted as permanent.

---

### 6. Conditions require exactly ONE form — the typed catalog needs a schema to type against

**Decision:** `conditions` are valid ONLY on `form_submitted` workflows (a 422 on any condition
present for a `schedule` trigger), and REQUIRE `trigger_config.form_id` to be set whenever any
condition is present (a 422 on `trigger_config.form_id` otherwise) — even though `form_id`
itself is otherwise OPTIONAL on `form_submitted` (a `null` form_id means "match any form" for
TARGETING purposes).

**Alternatives rejected:** Allowing conditions against an "any form" (`form_id: null`)
`form_submitted` workflow, resolving field types dynamically per-submission at RUN time, was
considered and rejected. The whole point of the 5.1 TYPED condition system (decision below —
`field_type` + the operator-per-type matrix) is that `field_type` is declared once, AT WRITE
TIME, against a KNOWN schema — this is what lets the write-path validator reject an
operator/type mismatch immediately (a 422, not a silent runtime no-op) and lets the FE condition
builder render the RIGHT input control (a date picker for a date field, a multi-select for a
multi field) without querying every possible form's schema live. Without a single pinned form,
there is no schema to declare `field_type` against, and the entire typed-condition mechanism
degrades back to the Etap-5 flat, untyped model this re-scope specifically replaced.

**Rationale:** This is a direct, necessary consequence of typing conditions at all — "conditions
need ONE form" is not an arbitrary restriction, it is the structural requirement typed
conditions impose. A workflow that genuinely wants to react to "any form" simply cannot also
have typed field conditions (it can still target broadly via `form_id: null` and skip
conditions entirely, or narrow to one form to gain conditions) — this is an honest trade-off
inherent to the design, not a bug to work around.

---

### 7. Repeaters excluded from the variable catalog — dead-path honesty

**Decision:** `WorkflowVariableCatalogService::formFieldVariables()` explicitly SKIPS any form
field whose schema type is `repeater` — no `trigger.fields.<path>` variable is emitted for one,
and consequently no condition field descriptor exists for one either.

**Alternatives rejected:** Emitting a variable/condition anyway (typed as `multi` or `text`, the
closest existing types) was considered and rejected. A repeater's answer is an ARRAY OF OBJECTS
(structured JSONB — each row potentially multiple sub-fields), which `fields.<id>` — a single
dotted lookup — cannot resolve down to a single comparable scalar or flat set the way `multi`
(a flat array of values) or any other `WorkflowVariableType` can represent. Emitting a variable
for a path that can never meaningfully resolve to something the condition evaluator or a step
config's `{kind:variable}` union could use would be a REFERENCE THAT LOOKS VALID IN THE UI BUT
IS ALWAYS A DEAD PATH AT RUN TIME — worse than simply not offering it, because it would fail
silently/confusingly rather than never being offered.

**Rationale:** This is the "honest catalog" principle applied narrowly: every variable the
catalog emits corresponds to a real, resolvable `fields.<path>` key in the whitelisted
submission answer map (`WorkflowTriggerPayloadFactory::whitelistFields()`). A repeater
CATEGORICALLY cannot satisfy that property with the current single-scalar-path variable model,
so excluding it is more honest than offering a reference that would never actually work.

**Consequence — PLANNED, not built:** if repeater-aware conditions/variables become a real
need (e.g. "any repeater row where X"), it requires a genuinely different reference shape (an
aggregate/exists-over-rows operator, not a flat path) — a new mechanism, not an extension of the
current one. Not scoped, not started.

---

### 8. Frontend: quick-pick chips beginner-first, title = constrained `MarkdownEditor`, two add-ons not three, `VariableChip` echoes rather than reuses

**Decision:** Several frontend structural choices for the B7 editor, made to keep the typed
variable system approachable without over-building:

- **Schedule builder is quick-pick-chips-first**: the MOST COMMON cadences (e.g. "daily",
  "weekly") are exposed as one-click chips ahead of the full 12-family/descriptor-driven form,
  with the AI assist as a THIRD path (natural language) alongside chips and the full builder —
  a beginner-first progressive-disclosure layout rather than presenting all 12 families flatly.
- **`create_task.title` uses the existing, constrained `MarkdownEditor`** (with its variable
  extension) rather than a plain `TextInput` — this is what makes the directive serialization
  (decision #2) possible for a field that must mix literal text and inline variable references;
  the editor is CONSTRAINED (a reduced toolbar appropriate for a task title) rather than the
  full rich-text surface used elsewhere.
- **Exactly TWO structured-field add-ons**, not three: `ValueOrVariableField` (generic literal-or-
  variable, used for `priority` and similarly typed scalar fields) and `DateOrVariableField`
  (date-specific — needed because a date literal wants a date-picker control the generic add-on
  should not have to special-case). A third, more general add-on was considered and rejected as
  premature abstraction — two concrete, purpose-built add-ons for the two structured-field
  SHAPES that actually exist (generic scalar, date) is simpler than one over-parameterized
  component trying to cover both.
- **`VariableChip` is ECHOED, not reused**, between the markdown editor's own chip rendering and
  the structured add-ons' variable-selected display: the add-ons render their OWN small
  chip-styled affordance that visually matches the editor's `VariableChip` component rather than
  importing/wrapping it directly, because the add-ons operate on the `{kind:variable}` union
  data shape while the editor's chip renders from directive/ProseMirror node state — forcing a
  shared component would mean the chip component itself needing to understand two different
  underlying data models, which is more coupling than duplicating a small presentational chip.

**Rationale:** Each of these is a "smallest coherent thing for what exists today" call,
consistent with the project's stated preference (see `docs/decisions/ADR-0008-workflows-module-
design.md` #14–#15 for the same posture applied to the Etap-5 UI) — visually matching but not
structurally sharing the `VariableChip` look avoids a premature shared-component abstraction
across two genuinely different data shapes; two add-ons instead of a general one avoids
speculative generality for a scalar-vs-date distinction that is unlikely to need a third
variant soon.

**Consequence — PLANNED, not built:** if a third structured-field shape emerges (e.g. an
enum-specific add-on with an inline option picker), evaluate at that point whether the two
existing add-ons should be refactored toward a shared base — not before a third concrete
consumer exists (mirrors the project's "extract on third consumer" precedent, ADR-0006 §2 /
ADR-0007 #14).

---

## ADR-0008 sections superseded by this ADR

The following ADR-0008 decisions describe the Etap-5 (5-trigger, 4-step, flat-condition)
contract and are **SUPERSEDED** by the decisions above. ADR-0008 itself has been annotated with a
short banner pointing here; its historical reasoning is left in place (not deleted) because it
remains the correct record of what was decided AT THE TIME and why, and the run-engine mechanics
it documents (claim/release/reaper, loop depth, cost caps, the resolver's whitelist property,
manual-run-as-origin) are UNCHANGED and still authoritative:

| ADR-0008 § | Superseded by | What changed |
|---|---|---|
| #7 (Schedule = structured presets, not raw cron) | This ADR §3 | The 4 presets became 12 descriptor-driven families compiled to real cron (still not raw user-supplied cron — that part of #7's conclusion still holds). |
| #9 (Targeting lives inside `trigger_config`, evaluated before conditions) | This ADR §1, §5 | Targeting for the 3 removed trigger types (label/status/pipeline targeting) no longer exists; `form_submitted`'s remaining targeting (`form_id`/`source`/`anonymous`) is documented fresh in `docs/backend/workflows-api.md`. |
| #13 (Manual-run 422s keyed `target_id`/`approval_process`/`workflow`) | `docs/backend/workflows-api.md` (manual-run section) | `approval_process` no longer exists — the `approval_finished` trigger it belonged to was removed. The 422 bag is now exactly `target_id` + `workflow`. |
| #14 (Frontend: ordered step list, no canvas; new `workflow` icon) | Unchanged in spirit; step ROSTER changed | The linear-list-not-canvas and icon decisions still hold verbatim — only the step TYPES available in that list changed (2, not 4). |
| #15 (MVP UI compromises: `task_id` TextInput fallback, append-only reference insertion) | This ADR §2, §8 | The typed variable/directive system replaced the flat reference-chip-append mechanism entirely; the `task_id`-as-raw-TextInput gap is largely MOOT because `assign_bot`/`attach_form`/`start_approval`'s standalone `task_id` fields no longer exist (folded into `create_task`, which doesn't need to reference an already-created task's id). |

Sections #1–#6, #8, #10–#12 of ADR-0008 (own-engine-vs-package, observer/service-seam dispatch,
run-state-on-`workflow_runs`, the resolver's whitelist HARD BOUNDARY property, loop protection
mechanics, manual-run-as-origin, hard-cap-workspace-wide, the `trigger_type` enum-cast asymmetry)
are **UNCHANGED** and remain authoritative as written.

---

## Related files

- `app/modules/Workflows/Enums/WorkflowTriggerType.php`, `WorkflowStepType.php` — decision #1
- `app/modules/Workflows/Enums/WorkflowVariableType.php`, `WorkflowConditionOperator.php` — decisions #2, #6
- `app/modules/Workflows/Services/WorkflowVariableResolver.php` — decision #2 (replaces the Etap-5 `ReferenceResolver`)
- `app/modules/Workflows/Services/WorkflowVariableCatalogService.php` — decisions #2, #6, #7
- `app/modules/Workflows/Http/Controllers/WorkflowVariableCatalogController.php` — decision #2, #6
- `app/modules/Workflows/Enums/WorkflowScheduleFamily.php` — decision #3
- `app/modules/Workflows/Services/WorkflowScheduleCompiler.php`, `WorkflowScheduleRulesValidator.php`, `WorkflowScheduleFamilyCatalog.php` — decision #3
- `app/modules/Workflows/Services/WorkflowScheduleAssistService.php`, `Agents/ScheduleAssistAgent.php` — decision #4
- `app/modules/Workflows/Services/WorkflowDispatchService.php` — decision #5 (`matchesFormSubmitted`)
- `app/modules/Workflows/Services/WorkflowTriggerPayloadFactory.php` — decision #5 (`normalizeSource`)
- `app/modules/Workflows/Http/Requests/StoreWorkflowRequest.php` — decision #6 (form-required-for-conditions check)
- `app/modules/Workflows/Steps/CreateTaskStep.php`, `CreateFormReportStep.php` — decision #1
- `resources/js/next/pages/workflows/WorkflowScheduleBuilder.vue`, `WorkflowScheduleAssist.vue` — decision #8
- `resources/js/next/pages/workflows/ValueOrVariableField.vue`, `DateOrVariableField.vue` — decision #8
- `resources/js/next/ui/editor/` (`extensions/VariableChip.vue`, `VariablePanel.vue`) — decision #2, #8
- `docs/backend/workflows-api.md` — the rewritten 5.1 API reference
- `docs/decisions/ADR-0008-workflows-module-design.md` — Etap-5 decisions; superseded sections listed above
- `docs/next/workflows-uxui-spec.md` — REVISION 2, the B7 frontend spec these decisions were drawn from
