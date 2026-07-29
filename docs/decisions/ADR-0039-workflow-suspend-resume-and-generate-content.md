# ADR-0039 — R2 sub-stage 5: the workflow suspend/resume engine + the `generate_content` step

**Date:** 2026-07-29 (created)
**Status:** Accepted
**Module:** `App\Modules\Workflows` (`Exceptions\StepSuspended`, `Steps\SuspendableWorkflowStep`,
`Steps\GenerateContentStep`, `Services\WorkflowRunManager`, `Services\WorkflowStepRunner`,
`Jobs\WorkflowRunResumeJob`, `Contracts\WaitResolver`, `Services\WaitResolverRegistry`,
`Services\GenerationSessionWaitResolver`, `Listeners\ResumeWaitingRunOnSessionTerminal`,
`Support\RealQueueConnection`, `Enums\WorkflowRunState`, `Enums\WaitStatus`), `App\Modules\Generator`
(`Services\SessionAutomationService`, `Services\SessionContentProjector`, `Services\GeneratedImageExporter`,
`Enums\SlotScopePolicy`)
**Relates to:** ADR-0034 (the generation session engine this step drives — snapshot-authoritative creation,
the atomic claim, the terminal status machine, the broadcast-on-settle push this ADR's fast path listens to),
ADR-0036 (the bot delegation slot-fill scope this ADR's `SlotScopePolicy::Automation` sits beside — same
enum, same shared validation authority, a DIFFERENT scope for a DIFFERENT trust boundary — see D14), ADR-0037
(the $ cost meter and the pre-run 429 gate this step's claim goes through unchanged), ADR-0015 (the
polymorphic creator/attribution precedence `HasCreator` already established — the export-in-resume decision,
D7, exists to keep this run inside that precedence), ADR-0008 §11 / the Etap-5 design record that first
anticipated a suspending step and left `WorkflowRunState::WAITING` reserved for it

---

## Context

R2 sub-stage 1 (Templatki, ADR-0032) and sub-stage 2 (Sessions, ADR-0034) built a Generator that a HUMAN
drives from the chat: fill slots, hit Generate, wait for the websocket push. The roadmap's R2 sub-stage 5
closes the loop the whole R2 chapter was building toward — a **workflow** step that runs a template and
publishes the result, so a schedule or a form submission can produce real content without a human opening the
Generator at all (`docs/product/plan-dzialania.md`).

The shape problem was not "how do we call the Generator" — sub-stage 3 (ADR-0036) had already proven the
one-way `Bot → Generator` edge pattern, and PR-1a (ADR-0030) had already made `Generator`/`Workflows` sibling
modules over a shared `Variables` layer specifically so a step like this could exist without a dependency
cycle (`docs/decisions/ADR-0031-generator-module-templates.md`'s forward-looking note, now cashed in). The
real problem was **time**: a generation is genuinely asynchronous, multi-second-to-multi-minute AI work, and
`WorkflowStepRunner`'s entire execution model — one job, one pass, steps run strictly in order, the run
released `completed`/`failed` when the loop ends — had no notion of a step that starts something and comes
back LATER for the answer. `WorkflowRunState::WAITING` had sat reserved in the enum since Etap-5 (ADR-0008
§11) for exactly this eventuality, unproduced.

Four questions had to be answered together:

1. **Does the step run the generation synchronously (inline, inside the workflow job) or does it genuinely
   suspend the run and let a real queue worker do the generation?**
2. **How does a step signal "I am not done, come back to me" without corrupting the run's published output
   contract** (`context.steps.<key>` is merged verbatim from whatever a step returns)?
3. **How does a LATER pass — a fresh job, days after the original one may have been recycled — pick up
   exactly where the run left off, safely, exactly once, even under redelivery and even if the SAME step
   suspends again on a second leg?**
4. **What does a workflow author's `slots` mapping tell the Generator, and where does the produced content —
   text AND images — actually end up, attributed to the run and not to nobody?**

## Decisions

**D1 — Option C: genuine suspend/resume over the real queue, not an inline generation and not a
sentinel-polling step.** Three shapes were on the table (see "Alternatives" below); the one built is: the
step starts the generation, the run PARKS in a new `waiting` state, and a queue worker resumes the SAME run
position once the generation settles. This was chosen over synchronous-inline generation (recommended
elsewhere in this codebase's docs as the simpler default) because:

- **Correct queue semantics.** `WorkflowRunJob` forces `Queue::setDefaultDriver('sync')` around the whole
  step loop — load-bearing, not incidental (see D8) — so an unqualified `RunGenerationSessionJob::dispatch()`
  from inside a step would NOT go to a real worker; it would execute the entire generation inline, inside the
  workflow job's own process, under the workflow job's own timeout. A generation can legitimately take
  minutes (a `video_script` storyboard: one structured shot-list call plus one AI image per shot); running
  that inline would mean sizing the workflow job's timeout to the WORST CASE of the Generator's own worst
  case, coupling two modules' operational budgets that should stay independent.
- **The Generator keeps its own job budget.** `RunGenerationSessionJob` already has its own `tries=1`,
  `timeout=300`, `WithoutOverlapping` lock, and its own stale-session reaper (`generator.session_stale_after`,
  ADR-0034). Suspending and letting THAT machinery run the generation means the workflow engine inherits none
  of the Generator's complexity — it only needs to know when the wait is over.
- **Unblocks R4/R5.** The roadmap's Publishing Hub and Campaigns chapters (`docs/product/plan-dzialania.md`)
  assume a workflow can wait on genuinely long-running, externally-triggered work (a platform publish job, an
  approval). Building the suspend/resume ENGINE now, generically, means `generate_content` is the first
  consumer, not a one-off special case that would need re-deriving later.
- **A hybrid ("suspend for a video_script, run post inline") was rejected as dishonest.** `outputDescriptors()`
  — the mechanism the whole variable catalog depends on to know a step's output shape — is a STATIC method,
  called before any config is resolved and before any session exists. A step type cannot conditionally BE
  suspending or non-suspending depending on the chosen template's content type; the catalog, the FE step
  editor, and `WorkflowStepRunner`'s dispatch (`instanceof SuspendableWorkflowStep`) all need one fixed answer
  per step TYPE. Every `generate_content` step suspends, even a same-second `post` generation — the cost is
  one extra queue round trip on the fast path (see D6), which is cheap next to the alternative of two step
  behaviors under one type name.

**D2 — suspension is signaled by a THROW, never a sentinel return value.**
`WorkflowStepRunner`'s existing step contract is "a step that cannot proceed MUST throw" (a failure); a
suspending step is the symmetric NON-TERMINAL sibling of that same signal —
`throw new StepSuspended($kind, $correlationKey, $payload)` — rather than returning a magic marker array. A
sentinel return was rejected outright: a step's return value is merged VERBATIM into `context.steps.<key>`,
so a magic key would (a) leak into the user-visible variable catalog the moment a later step's picker looked
at `steps.<key>.*`, and (b) be able to collide with a real step output name. The throw also makes the
sequential loop trivially correct — `catch (StepSuspended)` unwinds and returns immediately, with no code
path on which a suspended step's (non-existent) output could be merged into `context`. The ordering matters
mechanically: the runner's `catch (StepSuspended)` MUST be written before its `catch (Throwable)`, or PHP's
first-match semantics would record every suspension as an ordinary step failure.

**D3 — the config a suspending step suspended with is REPLAYED verbatim on resume, never re-resolved.**
`WorkflowRunManager::suspend()` persists the step's own `$config` — already resolved against the run's
context at the moment it suspended — into `waiting_on.config`, and `WorkflowStepRunner::execute()` reads it
back on the matching resume pass instead of calling `$this->resolver->resolve()` again. Two reasons, one
mechanical and one economic:

- **Spend-incurring directives pay once.** A `generate_content` step's config may itself carry a resolved
  `@[ai-text]` or a pipeline over prior step output; re-resolving on resume — potentially minutes or hours
  later, against a context that may have moved on — would either double-spend the run's `ai_text_max_calls_per_run`
  budget or resolve against stale/changed upstream state.
- **The outcome is collected under the very config the external work was started with.** `GenerateContentStep::resume()`
  reads `config['folder_id']` to decide where to export images — that must be the SAME folder id `run()`
  resolved, not whatever a since-edited step config would now resolve to. Everything AFTER the suspended step
  is still re-read live on resume (the fresh-job idiom, D5) — only the ONE position that suspended is frozen.
- A wait parked BEFORE this column existed (pre-migration) has no persisted config and falls back to
  re-resolution — the old, paid-twice behavior, accepted for the narrow legacy-row case rather than failing
  every in-flight wait at deploy time.

**D4 — three additive, nullable columns; a workflow without a suspending step is byte-identical to before.**
`workflow_runs` gained `waiting_on` (json), `waiting_key` (string, indexed), `waiting_since` (timestamp) —
central and tenant mirrors, additive migrations, no touch to the existing `create_workflow_runs_table`
migration. `waiting_on` carries `{kind, step_key, step_type, position, payload, config, definition_hash,
ai_text_calls}`:

- `kind`/`step_key`/`step_type`/`position`/`payload` are the step's own descriptors (see D2's `StepSuspended`
  constructor) plus the run-loop's own bookkeeping of WHERE it suspended.
- `config` is D3's replayed value.
- `definition_hash` is D6's fingerprint.
- `ai_text_calls` is the run's `@[ai-text]` spend so far, re-seeded on resume so a fresh job's fresh
  `WorkflowAiTextService` instance does not silently reset the run's per-run budget.

**PRIVACY. `waiting_on` now carries the RESOLVED config**, which may hold form-submitted personal data (a
`{{trigger.fields.*}}` reference resolves to whatever the submitter typed) and AI-generated content. It MUST
NEVER be serialized into a run-detail API Resource, logged, or exposed to the frontend —
`WorkflowRunResource` deliberately omits it, matching the posture the run's `context` column already has for
anything sensitive.

**D5 — a resume is a FRESH scalar-payload job, never a serialized continuation** — the same idiom
`BotTaskRunManager`/`BotTaskExecutionJob` already established for the Bot module's own claim/resume cycle.
`WorkflowRunResumeJob(runId, workspaceId, waitingKey)` carries three primitives; `handle()` re-reads the
`WorkflowRun` row, re-establishes tenancy explicitly (`activateTenant()` — belt-and-braces alongside
`QueueTenancy`, needed because the waiting-run SWEEP dispatches this job from a pass whose ambient tenant
context may already be cleared or pointed at a different tenant by the time the job actually runs), and calls
`WorkflowStepRunner::resume($run)`, which rebuilds `context` from the DATABASE — `steps` from the persisted
`context.steps` the suspension wrote, `globals`/custom functions/the type map RE-READ LIVE. A constant edited
while the run waited therefore affects only the steps that run AFTER the resume, exactly as it would for any
run started after the edit — the deliberate semantic of "fresh job, not a continuation": nothing about the
suspended process is kept alive across the wait.

**D6 — the resume CLAIM is atomic AND correlated on the observed `waiting_key`, not merely on
`state='waiting'`.** `WorkflowRunManager::claimResume($run, $waitingKey)` is the exact mirror of `claim()` —
`UPDATE workflow_runs SET state='running', started_at=now() WHERE id=? AND state='waiting' [AND
waiting_key=?]` — but the extra predicate matters specifically because `SuspendableWorkflowStep::resume()` may
throw `StepSuspended` AGAIN (a multi-leg wait — the wait resolver reports `generating`/`draft` as still
pending, D10). Two dispatch sites can independently learn that a wait settled — the fast-path LISTENER
(D10) and the sweep (D10) — and either can be redelivered at-least-once by the queue. Without the key, a
stale/duplicate job for LEG A could win the claim on LEG B (the step having since re-suspended onto a NEW
correlation key) and resume the wrong external work. `WORKFLOWS_WAIT_TIMEOUT` and the definition fingerprint
(D9) are the ONLY things allowed to fail a run out from under a re-suspension; a doubled resume must always be
a clean, silent no-op instead.

**D7 — image export happens in the RESUME phase, inside the run, so `HasCreator` stamps the run as the
uploader — never inside the generation worker.** `GenerateContentStep::resume()` calls
`GeneratedImageExporter::saveToDiskIfPresent()` for every produced image AFTER the session settles `ready`,
not from anywhere inside `RunGenerationSessionJob`/`GenerationSessionExecutor`. `WorkflowRunJob`/
`WorkflowRunResumeJob` both force the `sync` queue driver around the step loop specifically so
`WorkflowRunContext` stays published for the whole pass (ADR-0015) — exporting from inside the generation
worker instead would run with NO run context (the generation worker is a different job, on the real queue,
outside that sync-forced scope) and, usually, no authenticated user, leaving a NULL `uploader_type` on a
real, user-visible Disk file. `GeneratedImageExporter` itself performs **NO authorization at all** — this is
safe here ONLY because the run exports a session it created moments earlier, from a template the workflow
author was already authorized to use; the exporter must never be reachable with a session id supplied from
outside this one call site.

**D8 — the sync-driver override is DOCUMENTED here as the load-bearing mechanism it always was, because this
feature is the first place its absence would silently break suspension.** `WorkflowRunJob`/
`WorkflowRunResumeJob` both call `Queue::setDefaultDriver('sync')` around the step loop so that anything a
step "fires and forgets" (the pre-existing `create_form_report`'s `CreateFormReportJob`) actually runs
IN-PROCESS, keeping `WorkflowRunContext` alive so `HasCreator` stamps step-authored rows with the run. This
was already true before this feature; it is now load-bearing in a NEW way — `generate_content` MUST escape
that override to reach the real queue, or its generation would run inline and defeat suspension entirely (see
D1). `App\Modules\Workflows\Support\RealQueueConnection` is the escape hatch: both run jobs publish the
PRE-OVERRIDE connection into it (save/restore, not set/clear, because a re-triggered child run executes
IN-PROCESS nested inside a step, and an unconditional clear would wipe the OUTER run's published connection),
and `GenerateContentStep::run()` reads it explicitly when dispatching the generation claim. A defensive assert
(`assertGenerationCanLeaveThisProcess()`) refuses the step outright, before any session is created, if that
published value is ever absent WHILE the ambient default driver is still `sync` — the exact combination
that would mean the generation runs inline — this can only happen if a future change breaks the
publish/restore discipline, and the step must fail loudly rather than silently run a generation inline. (A
genuinely `sync`-configured deployment is unaffected: there the published value published by the run jobs
IS `sync`, and running in-process is that deployment's correct behavior — see `GenerateContentStep`'s own
docblock.)

**D9 — a DEFINITION FINGERPRINT gates every resume, on top of the pre-existing per-position checks.**
`WorkflowStepRunner::definitionHash()` SHA1-hashes the whole ordered step definition at both suspend and
resume time; `hasDefinitionDrift()` compares it FIRST, before re-checking the one position the wait targets.
The per-position checks alone (same key, same type, still an instance of `SuspendableWorkflowStep`) only see
ONE position — an edit ANYWHERE else in the definition (a step appended after the suspended one, a LATER
step's config rewritten, or even the suspended step's own config edited without changing its key/type) used
to pass silently and alter a run already in flight. Either check failing releases the run `failed` with a
clear "definition changed" error rather than silently skipping or corrupting work. A wait persisted before
the fingerprint column existed carries no hash and falls back to the old per-position-only guard, mirroring
D3's config-replay legacy fallback.

**D10 — the settle LISTENER is a latency optimization; the waiting-run SWEEP is the correctness guarantee,
and the no-broadcast reaper case is the evidence for why the sweep must stay authoritative.**
`ResumeWaitingRunOnSessionTerminal` listens to the Generator's own `GenerationSessionUpdated` event (already
broadcast on the private per-workspace channel for the chat's own settle push, ADR-0034 D10) and dispatches a
correlated `WorkflowRunResumeJob` the moment a session it is watching goes `ready`/`failed` — Workflows reacts
to a Generator-owned signal, so the Generator needs zero knowledge that Workflows exists. But
`GenerationSessionRunManager::broadcastTerminal()` SKIPS the broadcast when no workspace is active
(`$this->tenant->id() === null`) — precisely the case when the Generator's OWN lifecycle reaper
(`generator:reap-sessions`, sweeping the shared connection with cleared tenant context) settles a stranded
session to `failed`. An event-only design would therefore strand EXACTLY the runs that most need recovering —
a session abandoned because its worker died is settled by the reaper, with no listener ever firing. Workflows'
own `WorkflowRunManager::reapWaitingRuns()` (`workflows:reap-stale-runs`, already scheduled `everyFiveMinutes()`)
is the backstop that MUST be able to observe settlement independent of any event: it asks the kind-keyed
`WaitResolverRegistry` → `GenerationSessionWaitResolver` → `SessionAutomationService::terminalStatusFor()`
directly, a plain DB read, so it needs no broadcast to have fired at all. `WORKFLOWS_WAIT_TIMEOUT` is the
final backstop for the case where even that resolver never reports SETTLED (the session vanished, or the
resolver itself throws — `WaitResolverRegistry` downgrades an unregistered kind or a throwing resolver to
PENDING rather than GONE, so a transient fault can never wrongly time out a healthy wait).

**D11 — the timeout ordering invariant chains FIVE windows, each strictly wider than the one it backstops.**
Read bottom-up: the most informative recovery must always get the first chance to fire, because it turns an
abandoned piece of work into a SETTLED (not merely timed-out) outcome the run can resume on and record
honestly.

```
RunGenerationSessionJob timeout      300s   (the generation's own SIGALRM window)
  < WithoutOverlapping lock expiry   600s   (releaseAfter 30s / expireAfter 600s)
  < workflows.run_timeout            900s   (stale-RUNNING reaper — NEVER matches `waiting`)
  < generator.session_stale_after   1800s   (the Generator's own stale-session reaper — settles the
                                             session to `failed`, which the wait resolver then reports
                                             SETTLED)
  < workflows.wait_timeout          2700s   (stale-WAITING reaper — the LAST resort; fires only if
                                             everything above somehow never settled the session)
```

`waiting_since` is re-stamped on EVERY park, including a re-park of the SAME step onto the SAME leg — so
`wait_timeout` bounds time since the LAST park, not the run's total wait time. This cannot loop: a resume is
only ever dispatched on a SETTLED or GONE observation, and a step that re-suspends does so because its work
is still genuinely pending — no two machines can ping-pong the clock. The one case that legitimately extends a
wait indefinitely is a HUMAN repeatedly acting on the external work (refining a generation before it settles,
in a hypothetical future consumer) — precisely the case the timeout should not cut short.

**`WorkflowRunJob::$timeout` is now DECLARED (720s) rather than inherited.** Before this feature the job
silently inherited the worker's `--timeout` (Laravel's 60s default) — far below what even a single-step run
already needed once `@[ai-text]` existed (up to 10 calls × 60s each = 600s worst case, SB2). This gap
predates suspend/resume but is fixed alongside it because `WorkflowRunResumeJob` needs the identical budget
(`720s`, matched deliberately) for its own SIGALRM window, and the two jobs are now conceptually one pass
split across a park — they must share the same worst-case budget assumption.

**D12 — `Workflows → Generator` stays one-way; the wait RESOLVER lives in Workflows, not Generator, even
though it is arguably "about" a Generator concept.** `WaitResolver`/`WaitResolverRegistry` are Workflows
contracts (mirroring the inverted-dependency trick `Variables\Contracts\AiTextGenerator` already used for
`WorkflowAiTextService`); a feature module is meant to REGISTER its own concrete resolver from its own
provider. But the "feature" here is the STEP (`GenerateContentStep`), and the step is a Workflows class — the
Generator must never name `Workflows` (its boundary test forbids it, unchanged since ADR-0031/ADR-0032/
ADR-0034/ADR-0036). So `GenerationSessionWaitResolver` lives in `App\Modules\Workflows\Services`, on the side
that is ALLOWED to know both, and still only reaches the Generator through the narrow automation seam
(`SessionAutomationService::terminalStatusFor()`, a plain status string) — never a Generator model, never a
query against a Generator table.

**D13 — `SessionAutomationService` is the Generator's ONE automation seam: HTTP-free, primitives-only, and
it re-wraps as little as possible.** It owns exactly the two operations that had NO prior callable form for a
non-interactive caller: CREATE from a live template (`createFromTemplate` — delegates to the existing
`GenerationSessionService::create()`, snapshot-authoritative per ADR-0034 D1) and the WAIT status
(`terminalStatusFor`). Every OTHER step of an automated run — FILL, RUN, COLLECT — reuses the module's
EXISTING interactive seams directly and unwrapped: `SessionDelegationService::applySlotValues()` (under the
new `SlotScopePolicy::Automation`, D14 of ADR-0036's sibling decision below), `GenerationSessionRunManager::
claimAndDispatch()` (the SAME budget-gated, atomically-claimed choke point a manual "Generuj" click and a bot
`auto_generate` both go through — ADR-0037's pre-run 429 gate applies unchanged), `SessionContentProjector`
(new, but a server-side sibling of the FE's existing `FinalPostBody.vue` composition, not a competing render
path), and `GeneratedImageExporter` (extracted VERBATIM from the existing `saveToDisk` controller action, no
behavior change, now with a non-aborting entry point for a queued caller with no HTTP response to 404 into).
There is exactly one implementation of each step; the automated path can never drift from the interactive
one because it does not have its own copy to drift.

**D14 — `SlotScopePolicy::Automation` allows a SCALAR `file` slot; `SlotScopePolicy::Bot` (ADR-0036) still
refuses every file slot. Same enum, same shared validator, two different trust boundaries — a deliberate,
narrow divergence, not a reversal of ADR-0036.** `SlotScopePolicy` decides OFFERABILITY only — every accepted
value is still re-validated against its descriptor by the shared `ConstantTypeValidator` regardless of
policy. A BOT's proposed values come from a MODEL, which could fabricate a plausible-looking Disk reference
it was never actually given — ADR-0036 D-E's refusal is about a model being untrustworthy with references it
did not receive from a human, and that reasoning is unchanged and still absolute for `SlotScopePolicy::Bot`.
An AUTOMATION mapping is different in kind: it is authored by a TRUSTED workspace human who wrote the
`generate_content` step's `slots.<name>` config, and the file id it carries is resolved through the
TENANT-SCOPED `File` model before anything is persisted — a foreign-workspace, deleted, or made-up id simply
does not resolve and the value is dropped like any other invalid one, exactly the posture every other
automation-mapped value already has. The authorization boundary is therefore the tenant scope plus the human
author of the step, not the mere absence of the capability. Deep composites — an `array<object>`, an object
nesting another object/file, and an `array<file>` — remain out of scope for BOTH policies AT THIS LAYER
(`SlotScopePolicy::accepts()`): `TemplateVariableCatalog`/the resolver do not offer per-element composite
access yet (the "R2 loop" deferral), so no automatic filler may write one either way. A PLAIN (non-array)
`object` slot whose fields are all scalar IS offerable at this layer for BOTH `Bot` and `Automation` —
`SlotScopePolicy::accepts()` returns `true` for it; the blanket refusal of every `object`-base slot, any
shape, is a separate, STRICTER rule that exists only at the workflow-authoring layer (D15), for a different
reason.

**D15 — a composite slot the step cannot supply is refused at AUTHORING time, not discovered as a run-time
surprise.** `StoreWorkflowRequest::isUnsuppliableSlot()` rejects a mapped `object`-base slot (any shape,
including one whose fields are all scalar and would otherwise be `SlotScopePolicy`-offerable per D14 — the
resolver has no object coercion, so the value the author wrote could never reach the session) and a
list-of-object/list-of-file slot (a deferred composite `SlotScopePolicy::accepts()` refuses outright) with a
granular `422` on `steps.<i>.config.slots.<name>` — REQUIRED unconditionally (the recipe cannot be driven by a
workflow at all), NULLABLE only when the author actually mapped it (leaving it unmapped is a legitimate
"generate with this slot empty"). Before this rule existed the two failure modes were both silent-until-run:
a REQUIRED composite slot saved cleanly and then hard-failed EVERY run (leaving an orphan draft session each
time — the generator's own idle-trash reaper is the only thing that ever cleaned it up), and a NULLABLE one
silently generated with an empty slot, quietly discarding the author's mapping. A SCALAR `file` slot is
deliberately NOT refused here (D14). This rule applies only to a `generate_content` step's `slots` mapping —
it has no bearing on a bot's direct delegation fill (ADR-0036), which never goes through
`StoreWorkflowRequest`.

**Arrayed-slot pipeline terminals (implementation detail tied to D15).**
`StoreWorkflowRequest::slotPipelineTerminals()` lets a mapped `{kind:'variable'}` pipeline targeting an
ARRAYED slot (`{base:<scalar|enum>, array:true}`, which maps to `VariableType::MULTI`) end in EITHER its own
`MULTI` type OR its plain ELEMENT type. Demanding `MULTI` alone was an unreachable dead end — no pipeline
operation produces a `multi` from a scalar source, so every non-identity pipeline over a scalar variable
mapped onto an arrayed slot was an unavoidable `422` with no way to satisfy it. The runtime already tolerates
this: `VariableResolver::coerce()`'s `MULTI => is_array($value) ? array_values($value) : [$value]` wraps a
resolved scalar into a one-element list, so admitting the element terminal at write time only accepts what
the resolver could already deliver at run time. `object`/`array<file>` descriptors never reach this check —
D15's refusal rejects them first.

**D16 — a hard cap of 2 `generate_content` steps per workflow.** Each one is a whole, separately-budgeted AI
generation (potentially several `ai_text`/`ai_image_generate`/`ai_image_edit` calls under its own session
budget); `StoreWorkflowRequest::validateGenerateContentBudget()` rejects a 3rd on `steps.<i>.type`. This bounds
a single run's worst-case AI fan-out and total wait time (two suspensions in sequence, each up to
`workflows.wait_timeout`) without touching the existing `workflows.max_runs_per_month`/`max_runs_hard_cap`
run-count budgets, which count RUNS, not steps within one.

## Alternatives considered

- **(A) Synchronous-inline generation** — the recommended default posture for a workflow step elsewhere in
  this codebase's guidance ("prefer the smallest safe change"). Rejected: would require either sizing
  `WorkflowRunJob`'s own timeout to the Generator's worst case (coupling two independently-evolving modules'
  operational budgets) or accepting that a slow `video_script` storyboard silently blocks the whole run
  process for minutes, defeating the point of a queued architecture. See D1.
- **(B) Sentinel-return "still working" marker instead of a thrown exception.** Rejected (D2): the return
  value's ONLY existing contract is "merge verbatim into `context.steps.<key>`" — a marker would leak into
  the catalog and could collide with a real output name. A throw reuses a control-flow shape the runner
  already has for failure, symmetrically, with zero new merge-path special-casing.
- **(C) [Chosen] Genuine suspend/resume: `StepSuspended` parks the run in `waiting`; a fresh job resumes it
  later.** See D1–D11.
- **(D) Client-side polling from the FE instead of an engine-level wait state.** Rejected: would mean the run
  is `completed` (or stuck `running`) from the backend's own point of view while content is still being
  produced — dishonest to every OTHER consumer of run state (the Runs list, a future automated consumer of
  `steps.<key>.status`), not just the one browser tab watching it.
- **A hybrid — suspend only for a multi-part/video content type, run a simple `post` inline.** Rejected (D1):
  `outputDescriptors()` is a static, config-independent method; the catalog and the FE step editor need one
  fixed answer per step TYPE, not a runtime-conditional one. The uniform cost (one extra queue round trip even
  for a same-second generation) was judged strictly cheaper than two behaviors sharing one type name.

## Consequences

- **Positive.** The suspend/resume engine is GENERIC and Workflows-owned — `StepSuspended`/
  `SuspendableWorkflowStep`/the `waiting_on`/`waiting_key`/`waiting_since` columns/`WaitResolverRegistry` name
  no Generator class anywhere. `generate_content` is the first consumer; a future step that waits on an
  approval, a platform publish callback, or any other externally-triggered settlement (R4/R5) reuses this
  machinery by registering one `WaitResolver` and implementing `resume()` — no new engine work.
- **Positive.** A workflow WITHOUT a suspending step is byte-identical to before this feature — pinned by
  `tests/Feature/WorkflowSuspendResumeTest.php`'s regression coverage and the additive-only migration/column
  discipline (D4).
- **Positive.** The attribution invariant (D7) means every automated generation's session, spend event, and
  exported Disk file is attributable to the RUN that produced it — no orphaned/null-uploader content, no
  spend nobody can trace to a workflow.
- **Trade-off (accepted).** Every `generate_content` step costs at minimum one extra queue round trip (the
  suspend + the resume dispatch) versus a hypothetical inline call, even for the fastest possible generation
  — the uniform-type-contract reasoning in D1's rejected hybrid.
- **Trade-off (accepted).** The 2-step cap (D16) means a workflow author who wants three independent
  generations in one run must split it into two workflows (or chain via a re-trigger) — a deliberate ceiling
  on worst-case AI fan-out per run, not a technical limit of the engine itself.
- **Deferred scope** (see `docs/backend/workflows-api.md` and `docs/backend/generator-sessions-api.md` →
  "Planned / deferred" for the authoritative list): manual cancellation of a `waiting` run (`WorkflowRunState::
  CANCELLED` is still declared, still unproduced); live push on the run detail page (the waiting panel is an
  honest snapshot-as-of-last-read plus an explicit Refresh, not a websocket subscription — unlike the
  Generator chat's own `useSessionSettle`); per-part granular `generate_content` outputs (today the step
  publishes one assembled `content` string and one `image_file_ids` list, not a per-part breakdown a later
  step could address individually); a bot delegating a workflow-driven generation (ADR-0036's delegation
  overlay and this ADR's automation seam are SIBLING trust boundaries today, not composed).

## Amendment to ADR-0036

ADR-0036's "Alternatives considered" describes bot-filling a FILE slot as deferred because "a bot forging a
Disk file reference is a security concern this slice does not need to solve." Read in isolation that could be
mistaken for a blanket statement that NO automated caller may ever supply a file slot. It is not: it was
always scoped to the BOT trust boundary specifically (a model proposing a value it was never given). This ADR's
D14 makes that scoping explicit by introducing a SECOND policy (`SlotScopePolicy::Automation`) on the SAME
enum, for a DIFFERENT caller (a trusted human's own step config, resolved through the tenant-scoped `File`
model) — `SlotScopePolicy::Bot`'s refusal is unchanged and still absolute for a bot. ADR-0036's own "Deferred
scope" bullet listing "the `generate_content` workflow step (R2 sub-stage 5 … the next sub-stage in the same
roadmap chapter)" is superseded by this ADR — that step is now built.

---

See `docs/backend/workflows-api.md` for the full `generate_content` config/output/error contract and the
suspend/resume engine's columns, `waiting_on` shape, and reaper mechanics; `docs/backend/
generator-sessions-api.md` for the automation seam (`SessionAutomationService`, `SessionContentProjector`,
`GeneratedImageExporter`, `SlotScopePolicy`) and the automation-session lifecycle; `docs/decisions/
ADR-0034-generation-sessions.md` for the session engine this step drives unchanged; `docs/decisions/
ADR-0036-bot-delegation-generation-sessions.md` for the sibling trust boundary `SlotScopePolicy::Bot` occupies;
`docs/decisions/ADR-0037-ai-cost-limits.md` for the $ gate this step's claim goes through unchanged.
