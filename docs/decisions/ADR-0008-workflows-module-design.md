# ADR-0008 — Workflows module design decisions

**Date:** 2026-07-07 (created)
**Status:** Accepted (see supersession note below)
**Module:** Workflows (`app/modules/Workflows/`), Tasks, Forms, Approvals, Bot

> **Partially superseded by ADR-0009 (2026-07-09, the Etap 5.1 re-scope).** This record
> describes the Etap-5 MVP as originally built: 5 trigger types, 4 step types, and a flat
> untyped condition model. The 5.1 re-scope shrank the trigger/step surface to 2+2 and
> replaced conditions with a typed system — see **ADR-0009** for the re-scope decisions.
> Decisions **#7, #9, #13, #14, #15** below are marked `[SUPERSEDED — see ADR-0009]` inline
> and kept as history, not rewritten. **Decisions #1–#6, #8, #10–#12 are UNCHANGED and remain
> authoritative** — the run-engine machinery (claim/release/reaper, loop depth, cost caps, the
> resolver's whitelist property, manual-run-as-origin) did not change in 5.1.

---

## Context

The Workflows module (Etap 5) lets a user automate a process: "when X happens (and matches Y),
run these steps" — creating tasks, assigning bots, attaching forms, starting approvals — either
in reaction to a domain event, on a schedule, or on demand. It was implemented across a
sequence of reviewed batches (definitions CRUD → run engine → event dispatch → schedule sweep →
run monitoring → frontend). This record documents the non-trivial design choices made along the
way and the reasoning behind each, mirroring the format of ADR-0007 (Bot module).

---

## Decisions

### 1. Own lightweight engine — Option A over extending Approvals or a package

**Decision:** Workflows is a NEW module with its own definition model (`Workflow`), its own
run/execution model (`WorkflowRun`, `WorkflowRunStep`), and its own small step-runner engine
(`WorkflowStepRunner`, `WorkflowStepFactory`, four `WorkflowStep` implementations). It does not
extend the Approvals module's stage/process machinery, and no third-party workflow-engine
package was introduced.

**Alternatives rejected:**
- **Option B — extend Approvals.** Approvals already has an ordered multi-stage process with a
  state machine (`ApprovalProcess` / `ApprovalStage`). Reusing it was considered, but Approvals'
  domain is specifically "a sequence of approve/reject decisions on ONE entity" — it has no
  concept of a trigger, no concept of heterogeneous step TYPES (create a task vs. attach a form
  vs. start an approval), and conflating "a workflow step" with "an approval stage" would have
  forced Approvals to grow trigger/targeting/condition concepts it does not otherwise need,
  degrading its own clarity for a marginal reuse gain.
- **Option C — a third-party workflow/BPMN engine package.** Rejected per the project's
  "do not introduce packages without justification" rule: the MVP surface (4 step types, linear
  execution, no branching/parallelism/human-in-the-loop-wait) does not need general BPMN
  machinery, and a package would import a much larger conceptual and dependency surface than
  the problem currently requires.

**Rationale:** A workflow definition (trigger + conditions + ordered steps) and a workflow run
(one execution + accumulated context + step audit trail) are genuinely a new domain concept —
distinct from "a task", "an approval process", or "a bot run" — so a dedicated module with its
own persistence and its own minimal engine is the smallest coherent abstraction. Steps act
ONLY through existing domain services (`TaskService`, `ApprovalService`) rather than duplicating
their logic, so the engine adds orchestration, not a parallel task/approval implementation.

---

### 2. Observers + service seams, NO event bus

**Decision:** Domain triggers (task created, task status changed, form submitted, approval
finished) are wired via direct calls from existing Eloquent **observers**
(`TaskObserver`, `FormSubmissionObserver`) and one **service method**
(`ApprovalService::dispatchWorkflowTrigger()`), each calling
`WorkflowDispatchService::dispatch()` directly (deferred to `DB::afterCommit`). No Laravel event
(`Event::dispatch()` / listeners) was introduced.

**Rationale:** The Taskio codebase has **zero** Laravel domain events at the time of this
build — `TaskObserver` already calls services directly for cross-cutting concerns (e.g.
auto-approving a form submission when a task reaches `DONE`), and `ApprovalService` already
calls back into `Task` model hooks (`onApprovalCompleted` / `onApprovalRejected`) the same way.
Introducing an event bus for Workflows alone would create a second, inconsistent mechanism for
"a thing happened, notify interested code" alongside the observer/service-seam pattern already
used everywhere else — exactly the "avoid parallel implementations" rule this project holds to.
Matching the existing pattern keeps the change small and consistent, at the cost of each
producer needing an explicit one-line call into `WorkflowDispatchService` rather than a
generic "subscribe to anything" mechanism. If Taskio ever adopts a real event bus project-wide,
migrating these four call sites is a small, mechanical follow-up — not a reason to build a
bespoke bus for one module now.

**Consequence:** Every new domain trigger type requires a code change at its producing
observer/service (not just a config-level trigger registration) — an accepted, explicit
coupling that keeps "what can cause a workflow to fire" auditable by reading the producers.

---

### 3. Run state lives on `workflow_runs`, not on `tasks`

**Decision:** A workflow run's lifecycle (`pending → running → completed|failed`) is tracked
entirely on the `WorkflowRun` row itself — its own `state`, `started_at`, `finished_at`, `error`
columns. Nothing is written onto the `tasks` table to reflect "a workflow touched this task"
(contrast with the Bot module, which DOES add `bot_run_state` / `bot_runs_used` columns
directly to `tasks`, because a bot run's identity IS a task's assignment lifecycle).

**Rationale:** A workflow run's relationship to a task is much looser than a bot run's: a single
run may create MULTIPLE tasks (via multiple `create_task` steps), touch tasks it did not create
(via `assign_bot`/`attach_form`/`start_approval` referencing an existing `task_id`), or touch NO
task at all (e.g. future step types). There is no single "the task this run belongs to" the way
a bot run belongs to exactly one task. Modeling run state as its own first-class row — with
steps as child rows — is the natural fit, and keeps `tasks` free of a column that would only be
meaningful for SOME rows some of the time.

---

### 4. Flat whitelisted `{{...}}` resolver — hard boundary vs. an expression language

**Decision:** `ReferenceResolver` supports exactly two readable roots (`trigger`, `steps`),
flat dotted-path lookups via `Arr::get`, no filters/arithmetic/method calls/conditionals. A
token whose root is anything else (`{{env.*}}`, `{{config.*}}`, `{{now}}`, …) is **not
recognised as a reference at all** and passes through completely literal.

**Alternatives rejected:**
- **A real templating/expression engine** (Blade-like, or a small custom expression parser
  supporting e.g. `{{trigger.task.priority == 'high' ? 'x' : 'y'}}`). Rejected: step `config` is
  user-authored data stored in a JSON column and resolved server-side against live application
  context — an expression language there is a code-injection-adjacent surface (even a "safe"
  custom parser needs to be proven safe against denial-of-service via pathological expressions,
  unbounded recursion, etc.), for a feature the MVP does not need. The four step types take
  simple scalar config (a title, a task id, a form id) — there is no use case yet for computed
  expressions.
- **A free-form dotted path into the ENTIRE application container/config** (e.g. resolving
  `{{config.services.stripe.key}}`). Rejected outright — this would leak secrets/config into a
  user-editable JSON column's resolved output. The whitelist of exactly `trigger` and `steps` is
  the mechanism that makes this structurally impossible, not a convention to remember.

**Rationale:** "Nothing to evaluate" is the actual security property here, not merely a
minimalism choice: because resolution is a plain lookup with no method dispatch, no arithmetic,
and no fallthrough to arbitrary object properties, there is no code path by which a
crafted `trigger_config`/`steps.*.config` value could execute anything beyond a dictionary
lookup. If a genuine need for computed values arises later (e.g. string concatenation
helpers, date formatting), it should be added as explicit, individually-reviewed whitelisted
FUNCTIONS on top of this resolver — not by generalizing it into an expression language.

---

### 5. Loop protection = origin-tagging + depth, plus workspace-wide cost caps

**Decision:** Two independent mechanisms bound runaway re-triggering:
1. `WorkflowRunContext` (an in-process singleton) tags a NEW run as a child of the run currently
   executing on this worker (if any), incrementing `depth`; `config('workflows.max_depth')`
   refuses a child run past that depth.
2. `config('workflows.max_runs_per_month')` (per-workflow soft cap) and
   `max_runs_hard_cap` (workspace-wide hard cap, **including manual runs**) bound total run
   volume regardless of chain shape.

**Alternatives rejected:** A pure depth-only guard was considered insufficient on its own — two
DIFFERENT workflows could form a longer mutual cycle (A creates a task matching B's trigger, B's
step creates a task matching A's trigger again) that depth alone would still eventually stop
(each hop increments depth), but only after `max_depth` hops of real work; the cost cap is the
backstop that bounds total SPEND regardless of the exact chain topology, independent of whether
depth-tracking correctly attributes every hop.

**Rationale:** Depth-tracking answers "how deep is THIS chain" (structural loop protection);
the cost caps answer "how much has this workspace spent this month" (economic loop protection,
same doctrine as the Bot module's per-task run cap in `config/ai.php`). Together they cover both
a fast/tight infinite loop (caught by depth in a handful of hops) and a slow/wide fan-out that
never technically loops but still runs unboundedly (caught by the monthly caps).

---

### 6. Status is `active | inactive` only, mutated through a dedicated PATCH endpoint

**Decision:** `WorkflowStatus` has exactly two values. `status` is never accepted in the
create/update request body (the FormRequest does not even validate it as a field on those
endpoints); the ONLY way to change it is `PATCH /workflows/{workflow}/status`, authorized
separately (`WorkflowPolicy::changeStatus`).

**Rationale:** A workflow's trigger having "gone live" is a meaningfully different, higher-
stakes action than editing its steps or conditions — activating a workflow means its trigger
can now fire in production, possibly against real user data, possibly with cost implications.
Splitting it into its own endpoint (mirroring the Bot module's `task_execution.enabled` /ish
posture and Approvals' pipeline activation pattern) makes "flip this live" an explicit,
auditable, separately-authorizable action rather than a side effect of an otherwise-routine
edit. A workflow is ALWAYS created inactive, so "build it, test it manually, then activate it"
is the only path — there is no way to accidentally ship a live trigger on first save.

---

### 7. Schedule = structured presets, NOT raw cron strings — [SUPERSEDED by ADR-0009 §3]

> **Status: superseded by the Etap 5.1 re-scope.** This decision describes the Etap-5
> 4-preset mechanism (hand-coded Carbon arithmetic per preset). It was replaced by a
> 12-family, descriptor-driven schedule compiled to real cron expressions (still NOT raw
> user-supplied cron — that conclusion survives). See ADR-0009 §3 for the current mechanism.
> Kept here as history.

**Original decision:** `trigger_config.schedule` is a structured object (`{ preset, n?, time?, weekday?,
tz? }`) validated against exactly four presets (`every_n_minutes`, `hourly`, `daily`, `weekly`).
Raw cron expression strings are not accepted.

**Rationale:** Cron syntax is a well-known footgun for end users (ambiguous field order,
easy-to-typo wildcards, no client-side validation short of re-implementing a cron parser in the
frontend) and is himself a string-parsing surface that would need its own hardening. A small,
closed set of presets can be FULLY validated server-side with ordinary Laravel validation rules
(`required_if`, `date_format:H:i`, `min:0|max:6`), rendered as ordinary form controls in the
frontend (a Select + a time picker, not a cron-syntax text box), and is provably safe to
evaluate (`WorkflowScheduleService` is pure Carbon arithmetic, no parser). The presets cover the
overwhelming majority of real automation cadences (every N minutes, hourly, daily at a time,
weekly on a day+time).

**Consequence — PLANNED, not built:** a `custom_cron` preset (accepting a validated cron
expression via a mature cron-parsing library, for the minority of cases the four presets don't
cover) is a plausible ADDITIVE future preset — it would slot into the existing
`WorkflowSchedulePreset` enum and `WorkflowScheduleService::nextDueAt()` match arm without
disturbing the four existing presets. Not scheduled, not started.

---

### 8. Manual run = a run ORIGIN, not a trigger type

**Decision:** "Manually run this workflow" is not a `WorkflowTriggerType` value — every
workflow, regardless of its trigger type, can be run manually via
`POST /workflows/{workflow}/run`. What varies per trigger type is only the TARGET the caller
must supply (`target_id` resolving to a Task, a FormSubmission, or nothing for `schedule`).
Manual runs are distinguished from real triggers by `WorkflowRun.origin = 'manual'`, and
**work on an INACTIVE workflow** (a workflow is created inactive by design — decision #6 — so
manual run is also the only way to test a workflow's steps before flipping it live).

**Alternatives rejected:** A hypothetical `WorkflowTriggerType::MANUAL` was considered and
rejected — it would have meant a workflow's trigger type could itself BE "manual", which
conflates "how was this workflow's real automation supposed to fire" (its actual
`trigger_type`) with "did a human just click a button" (which can happen for ANY workflow,
regardless of its configured trigger). Making manual an origin rather than a type keeps a
workflow's `trigger_type` meaningful as "what this automation is FOR" while still allowing
every workflow to be test-run or ad-hoc-run on demand.

**Rationale:** This also directly enables test-before-activate (decision #6): because manual
run is orthogonal to `active`/`inactive` status, a newly-built INACTIVE workflow can be run
against a real target immediately, using the exact same payload-building code
(`WorkflowTriggerPayloadFactory`) a live trigger would use, proving the step chain works before
the user commits to activating it.

---

### 9. Targeting lives inside `trigger_config` JSON, applied BEFORE conditions — [SUPERSEDED by ADR-0009 §1, §5]

> **Status: partially superseded by the Etap 5.1 re-scope.** The label/status/pipeline
> targeting this decision describes belonged to the 3 REMOVED trigger types
> (`task_created`, `task_status_changed`, `approval_finished`) and no longer exists.
> `form_submitted`'s surviving targeting (`form_id`/`source`/`anonymous`) is documented
> fresh in `docs/backend/workflows-api.md` and ADR-0009 §5. The general PRINCIPLE (targeting
> is trigger-type-specific and evaluated before the general conditions gate) still holds for
> the one trigger type that survives. Kept here as history for the removed types.

**Original decision:** Trigger-type-specific "which entities does this workflow care about" filtering
(`target.label_ids`, `target.form_ids`, `target.pipeline_ids`, plus `to_status`/`outcome`) is
stored as part of `trigger_config` (validated per-type by `StoreWorkflowRequest`) and evaluated
by a SEPARATE service (`WorkflowTargetingEvaluator`) BEFORE the general-purpose `conditions`
gate (`WorkflowConditionEvaluator`) runs.

**Alternatives rejected:** Folding targeting into `conditions` (e.g. expressing "only tasks
with this label" as a `conditions` clause like `{field: "task.label_ids", operator: "contains",
value: "..."}`) was considered and rejected as the PRIMARY mechanism, though the condition
system CAN technically express similar checks. Targeting needed type-specific semantics
`conditions` cannot express generically — e.g. `task_status_changed`'s REQUIRED `to_status`
(the trigger literally doesn't match without it, which is a strict per-type contract enforced
by the FormRequest, not an optional user-added gate) and the `label_operator: OR/AND`
combinator (a targeting-specific concept, not a general condition operator).

**Rationale:** Splitting the two makes each simpler: `trigger_config.target` is
STRUCTURED and per-trigger-type (each type declares exactly its allowed target shape, enforced
by the cross-type-rejection validator), rendered in the frontend as typed pickers (label/form/
pipeline selects) rather than a generic condition-builder row. `conditions` stays a small,
uniform, trigger-type-agnostic mechanism (`field`/`operator`/`value` over whatever the payload
happens to expose) for anything targeting doesn't cover (e.g. gating on `task.priority`).
Evaluating targeting FIRST is also a performance ordering: targeting is a cheap in-memory check
against fields already present in the payload snapshot, so it is the natural first filter before
spending any more evaluation effort.

---

### 10. Hard cap is WORKSPACE-WIDE, including manual runs

**Decision:** `config('workflows.max_runs_hard_cap')` counts EVERY run across EVERY workflow in
the workspace this calendar month — event-triggered, schedule-triggered, AND manual. A manual
run at the hard cap is refused with a 422, exactly like an event trigger silently skipping.

**Rationale:** The hard cap exists specifically to bound worst-case spend regardless of HOW runs
were started. If manual runs were exempt, a user (or a compromised/careless script hitting the
manual-run endpoint in a loop) could bypass the workspace's cost ceiling entirely by always
running workflows "manually" instead of letting them fire from events — defeating the cap's
entire purpose. Counting manual runs toward the SAME ceiling as automated ones is the only
version of "hard cap" that actually caps anything.

---

### 11. Fire-and-forget `start_approval` — waiting state designed-in but unused

**Decision:** The `start_approval` step calls `ApprovalService::startProcess()` and returns
immediately with `{process_id, run_id}` — it does NOT wait for the approval's decision. The run
completes (assuming it was the last step) while the started approval process is still `pending`.
`WorkflowRunState::WAITING` exists in the enum and is documented as anticipating a future
"suspend this run until an external event resolves it" mechanism, but the MVP engine never
produces it.

**Rationale:** Building "wait for an async decision, then resume" properly requires a resumption
mechanism analogous to the Bot module's `ask_and_wait` → resume-on-human-comment pattern (a
persisted "what am I waiting for" pointer, plus a hook on the approval-decided event to find and
resume the matching waiting run) — meaningfully more machinery than the rest of the MVP step
model. Declaring the `WAITING` state now (rather than adding it later, which would mean a schema
migration to introduce a state value that consumers — the frontend badge map, `isTerminal()` —
must already know how to handle) keeps the run-state vocabulary and the frontend's exhaustive
state→badge map stable ACROSS this future addition, at the cost of one currently-dead enum case.
This mirrors the Bot module's `WorkflowRunOrigin`-style "declare now, produce later" pattern.

**Consequence — PLANNED, not built:** wait-for-approval resume (suspending a run at
`start_approval` until the started process concludes, then resuming remaining steps with the
outcome in context) is explicitly deferred. `start_approval` remains a fire-and-forget kickoff
step for the foreseeable MVP lifetime.

---

### 12. `Workflow.trigger_type` enum-cast, `WorkflowRun.trigger_type` plain string (deliberate asymmetry)

**Decision:** `Workflow` casts `trigger_type` to the `WorkflowTriggerType` enum (Eloquent
`$casts`). `WorkflowRun` stores `trigger_type` as a **plain string** column with no cast.
`WorkflowRunManager::start()` explicitly unwraps the definition's enum with `->value` when
writing the run row (`'trigger_type' => $workflow->trigger_type->value`).

**Rationale:** `Workflow.trigger_type` is part of a live DEFINITION that the application reads
and branches on constantly (validation, targeting, the dispatch pipeline's type-match query) —
an enum cast gives compile-time-checked `match` exhaustiveness and prevents an invalid string
ever being read back as a "valid" trigger type. `WorkflowRun.trigger_type` is a denormalized
COPY, written once at run-creation time and read only for display/filtering — treating it as a
plain string avoids a second enum-cast/parse round-trip for a value that is never branched on
after creation, and keeps the run row cheap to construct from `$workflow->trigger_type->value`
without needing to re-wrap it. This is a narrow, deliberate asymmetry, not an oversight — a
future refactor should NOT "fix" this by casting `WorkflowRun.trigger_type` without first
checking whether anything relies on it being a plain string (e.g. raw SQL filtering).

---

### 13. Manual-run 422s keyed distinctly (`approval_process` vs `target_id`) so the FE maps by key — [SUPERSEDED by ADR-0009 §1]

> **Status: superseded by the Etap 5.1 re-scope.** The `approval_process` key described here
> belonged to the `approval_finished` trigger, which was REMOVED. The manual-run 422 bag is
> now exactly `target_id` + `workflow` — see `docs/backend/workflows-api.md`. The KEY-not-
> message mapping CONVENTION this decision established still holds. Kept here as history.

**Original decision:** `WorkflowManualRunService` raises THREE distinctly-keyed `ValidationException`s
for a manual run: `target_id` (the target itself doesn't resolve), `approval_process` (the
target resolves fine, but has no concluded approval process to report an outcome for — only
relevant for `approval_finished` workflows), and `workflow` (the run-budget cap is reached).

**Rationale:** `target_id` and `approval_process` are semantically different failures that a
naive implementation could easily collapse into one `target_id` error ("the approval process
doesn't exist" could be phrased as "target invalid"). Keeping them distinct lets the frontend
distinguish "you picked the wrong task" (fix: pick a different target) from "this task exists
but hasn't been through approval yet" (fix: nothing to pick differently — wait, or pick a
DIFFERENT task that has). The Polish error MESSAGES are UI copy and may change; the KEYS are the
actual contract. **Established convention (reused from Approvals/Bot precedent): the frontend
maps a 422 response by error-bag KEY, never by parsing message prose.**

---

### 14. Frontend: new `workflow` icon glyph; no visual canvas builder (ordered step list, Approvals precedent) — [PARTIALLY SUPERSEDED — see ADR-0009 §8]

> **Status: the icon and canvas-vs-list decisions below are UNCHANGED and still authoritative.**
> Only the STEP ROSTER available in that list changed (2 step types in 5.1, not 4) — see
> ADR-0009 §1. The reasoning below (why a new icon, why a linear list not a canvas) applies
> verbatim to the current, smaller step set.

**Decision:** The Workflows module's identity icon (nav, PageHeader, aside header, empty state,
entity-card fallback) is a **NEW** icon registered in the `next` icon registry: `workflow` (the
Lucide "two connected blocks" glyph). The step editor is an **ordered ▲▼ list** (add / reorder /
remove, one step at a time) — the same interaction pattern as the Approvals pipeline stage
builder (`PipelineBuilderDrawer.vue`) — not a visual node-and-edge canvas.

**Why a new icon, not reuse:** the original design intent was to reuse `git-branch` (branching
paths reads naturally as "workflow"), but `git-branch` was discovered already in use as the
Approvals module's TOP-LEVEL nav icon (`AppLayout` → `/approvals`). Two adjacent top-level nav
items sharing an icon defeats the icon's entire purpose (fast visual module identification), so
a distinct glyph was required. `git-branch` remains available and IS still used as a SECTION
glyph within Workflows (e.g. a "Conditions" panel, where "branching logic" is an apt local
metaphor and there is no top-level-nav collision).

**Alternatives rejected — visual canvas builder.** A node-and-edge visual builder (drag boxes,
draw connections) is the more "obviously workflow-like" UI for this domain and was considered.
Rejected for the MVP: the current step model is strictly LINEAR (one ordered list, no
branching, no parallel paths, no conditional routing between steps — `conditions` gate the
WHOLE workflow, not individual steps) so a graph-editing canvas would visually promise
capabilities (branching, merging, parallel steps) the engine does not have, and would be a
substantially larger frontend investment (a canvas library, custom node/edge rendering,
layout/connection-drawing logic) for a linear list the Approvals precedent already solves well
with ▲▼ reordering.

**Consequence — PLANNED, not built:** if the step model ever grows real branching/parallelism,
a visual canvas becomes the RIGHT tool at that point (linear ▲▼ lists cannot represent branches
well) — this decision applies to the MVP's linear step model specifically, not as a permanent
rejection of canvas UIs.

---

### 15. MVP UI compromises: id-`TextInput` fallbacks, append-only reference insertion — [SUPERSEDED by ADR-0009 §2, §8]

> **Status: superseded by the Etap 5.1 re-scope.** The flat reference-chip-append mechanism
> this decision describes was replaced entirely by the typed directive/union variable system
> (ADR-0009 §2). The `task_id`-as-raw-`TextInput` gap is largely MOOT: `assign_bot` /
> `attach_form` / `start_approval`'s standalone `task_id` fields no longer exist (folded into
> `create_task`, which has no need to reference an already-created task's id). Kept here as
> history.

**Original decision:** Two known frontend shortcuts, taken deliberately rather than blocking the batch on
building supporting infrastructure that does not exist yet:

- **`task_id` step-config fields render as a plain, mono-font `TextInput`** (the user
  pastes/types a raw UUID) rather than a proper entity picker, because a reusable `TaskSelect`
  (search-as-you-type task picker) does not exist ANYWHERE in the `next` frontend yet — building
  one is a real component-extraction effort (search debounce, async options, workspace scoping)
  that belongs to its own change, not a drive-by inside this batch. By contrast, `bot_id` /
  `form_id` / `pipeline_id` step-config fields use the REAL `BotSelect` / `FormSelect` /
  `PipelineSelect` picker components (they already existed from Bot/Forms/Approvals editors) —
  the gap is specifically task identifiers used as step config values (`assign_bot`'s `task_id`,
  `attach_form`'s `task_id`, `start_approval`'s `task_id`), plus the manual-run target picker for
  `FormSubmission`-targeted (`form_submitted`) workflows, which has the same gap for the same
  reason (no submission picker exists either).
- **Reference-token insertion (`{{trigger.task.id}}` etc.) is APPEND-ONLY** — clicking a
  reference chip in the popover appends the token to the end of the current field's value,
  rather than inserting it at the cursor position, because the plain `TextInput` primitive used
  for these fields does not expose/track cursor selection state (it is not a rich-text/code
  editor). A proper mid-string insertion would require either extending `TextInput` with
  selection tracking (a primitive-level change affecting every consumer) or swapping these
  specific fields for a heavier editor — both out of scope for wiring up the reference catalog
  itself.

**Rationale:** Both are explicit, reviewed trade-offs that keep the MVP UI FUNCTIONAL (a user
can absolutely build and run a working workflow with copy-pasted ids and append-inserted
references) without inventing new shared primitives speculatively. Per the project's "extract
only when a third consumer appears" precedent (ADR-0006 §2, reaffirmed in ADR-0007 #14 for the
Bot Knowledge module): a `TaskSelect` extraction is warranted once a second or third concrete
consumer needs it, not preemptively for one field in one drawer.

**Consequence — PLANNED, not built:** `TaskSelect` (and a form-submission equivalent) extraction,
and cursor-aware reference insertion, are both explicitly deferred UI polish items, not
silently-accepted permanent gaps.

---

## Related files

- `docs/decisions/ADR-0009-workflows-rescope-typed-variables.md` — the Etap 5.1 re-scope that supersedes decisions #7, #9, #13, #14, #15 above
- `app/modules/Workflows/` — module root
- `app/modules/Workflows/Models/Workflow.php`, `WorkflowRun.php`, `WorkflowRunStep.php`
- `app/modules/Workflows/Services/WorkflowDispatchService.php` — decisions #2, #5, #9, #10
- `app/modules/Workflows/Services/WorkflowManualRunService.php` — decisions #8, #13
- `app/modules/Workflows/Services/WorkflowRunManager.php` — decisions #3, #12
- `app/modules/Workflows/Services/WorkflowRunContext.php` — decision #5
- `app/modules/Workflows/Services/ReferenceResolver.php` — decision #4
- `app/modules/Workflows/Services/WorkflowScheduleService.php` — decision #7
- `app/modules/Workflows/Steps/StartApprovalStep.php` — decision #11
- `app/modules/Workflows/Enums/WorkflowRunState.php` — decisions #3, #11
- `app/modules/Workflows/Enums/WorkflowStatus.php`, `Http/Requests/ChangeWorkflowStatusRequest.php` — decision #6
- `app/modules/Workflows/Http/Requests/StoreWorkflowRequest.php` — decision #9 (cross-type rejection)
- `app/modules/Tasks/Observers/TaskObserver.php`, `app/modules/Forms/Observers/FormSubmissionObserver.php`, `app/modules/Approvals/Services/ApprovalService.php` — decision #2 (seam hook points)
- `config/workflows.php` — decisions #5, #10
- `resources/js/next/ui/primitives/icons.ts` — decision #14 (`workflow` glyph)
- `resources/js/next/pages/workflows/WorkflowStepListEditor.vue` — decision #14 (ordered list, not canvas)
- `resources/js/next/pages/workflows/WorkflowStepCard.vue` — decision #15 (`task_id` TextInput fallback vs. real `BotSelect`/`FormSelect`/`PipelineSelect`, append-only reference insertion)
- `resources/js/next/pages/workflows/TargetPickerModal.vue` — decision #15 (manual-run target picker, same task/submission-picker gap)
- `resources/js/next/pages/workflows/WorkflowTriggerFields.vue`, `WorkflowConditionsEditor.vue` — targeting/condition editors
- `resources/js/next/pages/workflows/workflowReferences.ts` — reference catalog consumed by decision #15's insertion UX
- `docs/backend/workflows-api.md` — full backend API reference
- `docs/next/workflows-uxui-spec.md` — the UX/UI specification these frontend decisions were drawn from
