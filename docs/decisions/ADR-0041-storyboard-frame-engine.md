# ADR-0041 — the distributed storyboard frame engine

**Date:** 2026-07-30 (created)
**Status:** Accepted
**Module:** `App\Modules\Generator` (`Support\StoryboardFrame`, `Jobs\RenderStoryboardFrameJob`,
`Services\StoryboardFrameManager`, `Services\SessionImageBudget`, `Services\GenerationSessionRunManager`,
`Services\GenerationSessionLifecycleService`, `Console\ReapGenerationSessionsCommand`,
`Jobs\RunGenerationSessionJob` — hardened, not rewritten)
**Relates to:** ADR-0034 (the session engine's claim/job/reaper machinery this stage extends, not forks —
same guarded-UPDATE claim idiom, same terminal-broadcast contract), ADR-0035 (the `storyboard` part this
stage changes the RENDERING of, never its authored shape or its wire result kind), ADR-0038 (the per-run
image-budget ceilings and the `RunGenerationSessionJob` 300s timeout invariant this stage had to preserve
while still fitting a full storyboard inside it), ADR-0042 (the character visual-identity phase — the
concrete cost increase, measured in the same spike as this ADR, that made a full 8-shot storyboard
infeasible inside one job and is this stage's direct trigger)

---

## Context

A `storyboard` part (ADR-0035 Phase B) renders one AI image per shot of its sibling `shot_list`, up to
`generator.storyboard_max_shots` (platform ceiling 8). Until this stage every one of those images was
produced INLINE, inside `GenerationSessionExecutor::executeStoryboardPart()`'s own loop, as part of the
single `RunGenerationSessionJob` that also rendered the run's text parts and derived its creative direction
(ADR-0038). That job's timeout is 300 seconds and is **inviolate** — the queue's `WithoutOverlapping` lock
(600s expiry), the whole-session stale-recovery reaper (1800s), and the config-documented timeout-invariant
math for the text and image budgets are all ordered strictly on top of that one number. Raising it would move
every window above it too.

A pre-implementation spike measured the ACTUAL provider latency this run pays: an `ai_generate` base at
~33s, an `ai_edit` (a reference-anchored edit — see ADR-0042) at a **median 57.8s**. Eight shots of plain
generates alone (`8 x 33s = 264s`) already consumed nearly the whole 300s window before a single text part or
the one creative-direction derivation call was paid for; eight shots that include even one authored `ai_edit`
filter, or a character reference edit, made the inline loop mathematically impossible to keep inside the
window without either shrinking the shot ceiling or raising the timeout (and everything ordered above it).
The owner's decision, given that measurement, was explicit: **split rendering into one job per frame,
running in parallel**, rather than either of those.

## Decisions

**D1 — one queue job per FRAME, not a bigger job or a longer timeout.** `RenderStoryboardFrameJob` renders
exactly one shot's image; `RunGenerationSessionJob` still renders every text part, still derives the
creative direction, but for a storyboard now only **announces** each shot as a `pending` frame and returns.
This keeps the 300s window — and every reaper/lock window ordered on top of it — completely untouched, gives
every frame a WHOLE job's own budget (240s: one `ai_generate` plus one `ai_edit` at their full per-call
hung-provider ceilings), confines a failing/slow shot to its own job instead of the run's remaining headroom,
and lets a fleet of workers render frames of the SAME run **in parallel** — the actual point of the split, not
merely a side effect of it.

**D2 — the frame state machine lives INSIDE the shot entry in `results`, not a side table.**
`Support\StoryboardFrame` defines `pending → rendering → ok|failed` as fields on the existing shot object
(`image_status`, plus two transient bookkeeping keys — see D3). `results` is already the row of record every
other layer reads and writes under `lockForUpdate()`; a separate frames table would have needed its own
concurrency story to stay consistent with it. On settle the two transient keys are stripped, so a finished
run's `results` are **byte-identical** to one rendered inline — the FE contract this stage had to leave alone.

**D3 — the claim is CORRELATED by a per-delivery TOKEN, not by shot index alone.** Queues are
at-least-once, so the same frame job can be redelivered, and a run that was reaped/re-claimed leaves
stragglers from a superseded attempt still technically in flight. `StoryboardFrameManager::claim()` is a
guarded, atomic `pending → rendering` transition under `lockForUpdate()` that only succeeds when the shot
still carries the EXACT `frame_token` the job was dispatched with. A duplicate delivery finds the shot already
`rendering` (or terminal) and stops; a straggler from a dead run finds a different token (a full re-claim wipes
`results`) and stops. Neither case ever bills a second image — the same discipline the workflow resume claim's
`waiting_key` already established (ADR-0039).

**D4 — the per-run image budget moved from instance counters to two PERSISTED, guarded-UPDATE columns.**
`ai_generate_calls`/`ai_edit_calls` (plain `unsignedInteger`, default 0, additive migrations central +
tenant) replace `ImageChainExecutor`'s own instance fields as the ceiling authority whenever a session is
bound (`SessionImageBudget`). Instance counters were correct exactly as long as one run meant one job — the
moment a storyboard run became N jobs, each frame job's own freshly-resolved `ImageChainExecutor` would have
started counting from zero, and `generator.image_generate_max_calls_per_session` would have bounded ONE frame
instead of the whole run. Every reservation is ONE conditional `UPDATE … SET x = x + 1 WHERE id = ? AND x <
max` — deliberately NOT a counter inside the `results`/`history` json: the guard and the increment must be a
single atomic statement under N frame jobs reserving concurrently, and a json read-modify-write loses
reservations under exactly that concurrency. Both counters are reset to 0 by **every** claim
(`GenerationSessionRunManager::claimAndDispatch()`, whole-run and part-op alike), so the documented semantics
— a budget per RUN — are unchanged; only the definition of "one run" now spans the session job plus its frame
jobs. `SessionImageBudget` falls back to `ImageChainExecutor`'s own instance counters when unbound (a
direct, session-less caller — its own unit tests), so that pre-existing behavior is untouched.

**D5 — the FE contract stays exactly one terminal broadcast per run.** A run with an announced storyboard
stays `generating` after the session job returns; the LAST frame to settle — the one that, under the row
lock, observes zero frames still `pending`/`rendering` — flips the status to `ready` and pushes the single
`GenerationSessionUpdated` broadcast (`GenerationSessionRunManager::announceSettled()`, now public so a frame
job, the reaper, and the session job all call the same method). A run with no storyboard settles inside the
session job exactly as it always did — the new machinery is additive, never on the hot path of a plain post.

**D6 — the delivery-vs-payload discriminator, in BOTH the new frame job and the pre-existing session job.**
This is the deepest finding of the adversarial review, and it changed the shape of the fix from what was
first proposed. The frame's correlation token (D3) identifies the FRAME, not the DELIVERY: under
`tries = 1`, a redelivered duplicate of a still-rendering frame's job is failed by Laravel's own
`markJobAsFailedIfAlreadyExceedsMaxAttempts` **before** `fire()` — so it never reaches `handle()`, never
takes the `WithoutOverlapping` lock, and yet still triggers `failed()`, on a FRESH command instance
unserialized straight from the queue payload. Left unguarded, that hook would happily call
`StoryboardFrameManager::settle()` with the SAME token the still-running original delivery is using, killing
a live frame the original is at that moment paying a provider for: the run would be told it is done while the
original's own write-back is then silently rejected and its produced image orphaned, and — because a frame
may only be claimed while its session is `generating` — every OTHER outstanding frame would be
stranded. `RenderStoryboardFrameJob::mayReleaseFrame()` (and the retrofitted twin,
`RunGenerationSessionJob::mayFailRun()`) resolve this with two facts, not one: the LIVE object's own "did I
actually enter the unit of work" flag (true only for an in-process failure of the delivery that ran), OR —
because `failed()` is normally called on a fresh instance — the queue `Job`'s own `attempts() <= 1` (under
`tries = 1`, only the FIRST attempt can ever have reached `handle()`; anything later provably did not, and is
therefore the redelivery, not the original). No attached `Job` at all (a hand-built instance, a caller outside
the worker) fails CLOSED — it does not touch a frame/run that may still be live, and leaves the respective
reaper as the backstop. **The session job got the identical hardening even though nothing about its OWN
timeout/lock/redelivery math changed** — the same 300s-window-vs-90s-`retry_after` gap that makes a frame
redelivery routine already existed there, and the exact failure mode (a duplicate `failed()` marking a still-
running session `failed`, freezing every frame it had fanned out) was reachable through it. Fixing only the
NEW job would have left the pre-existing one carrying the same latent bug the review had just found the
general shape of.

**D7 — the stale-FRAME reaper runs BEFORE the stale-SESSION reaper, in the same sweep pass.** A frame job
killed between its claim and its write-back (SIGKILL/OOM) never fires its own `failed()` hook, and unlike a
lost whole-run job it strands a run whose OTHER frames are already finished and PAID FOR. The whole-session
stale window (30 minutes) would eventually recover it too, but by discarding a run that might be 7/8 complete.
`GenerationSessionLifecycleService::reapStaleFrames()` — a frame `rendering` past
`generator.frame_stale_after` (default 900s) is failed individually, settling its run if it was the last one
outstanding — runs FIRST in `ReapGenerationSessionsCommand`'s pass specifically so a run it rescues never
reaches the coarser whole-session window in the same sweep. The ordering invariant this pins:
`frame job timeout (240s) < run job timeout (300s) < WithoutOverlapping expiry (600s) < frame_stale_after
(900s) < session_stale_after (1800s)` — each recovery window sits strictly above the widest live-job window below it,
so the fine sweep always gets first crack at a recoverable run before the coarse one discards it.

**D8 — a whole-run failure closes every outstanding frame in the SAME write as the status flip.**
`GenerationSessionRunManager::fail()` — called by the session job's `failed()` hook, and by the stale-session
reaper — settles every still `pending`/`rendering` shot to `failed` (the reaper's own "lost frame" message)
inside the SAME locked transaction that marks the session `failed`. Without this, a run failed for an
unrelated reason (a DB error in a text part, say) would leave its already-announced frames permanently
unclaimable — a frame may only be claimed while its session is `generating` — and the FE, which branches per
shot on `image_status`, would show them spinning forever. A `results` value with no outstanding frame is
returned unchanged (`===`-identical), so a text-only session's failure path is byte-identical to before this
stage existed.

**D9 — a rejected write-back deletes its own orphaned image.** A frame may lose its claim WHILE its provider
call is still in flight (the reaper gave up on it moments before it returned; a re-claim superseded the whole
run) — by the time the write-back arrives, the bytes are already stored as a new version nothing will ever
reference again (not the shot, not the history stack, not the serve endpoint). `StoryboardFrameManager`
deletes exactly that version — provably the delivery's OWN allocation, since the image store hands out
strictly-increasing versions per part — logged as a warning (the fact only: session, part key, version —
never bytes, prompt, or shot text) rather than left for a general-purpose blob sweep to find weeks later.

## Alternatives considered

- **Raise `RunGenerationSessionJob`'s timeout instead of splitting the work.** Rejected: the 300s figure is
  not an isolated knob — the `WithoutOverlapping` lock expiry, the whole-session stale-reaper window, and the
  documented text/image budget timeout-invariant math are all ordered strictly above it, so raising it moves
  every window above it too. It also would not have actually solved the problem, only moved the ceiling: a
  long enough / character-heavy enough storyboard would still not fit inside SOME fixed number, and every shot
  would still share one job's fail-soft radius (a single hung provider call threatens the whole run's wall
  clock, not just its own beat).
- **A json-column ledger for the per-run image budget** (a counter nested in `results` or a new bookkeeping
  json column), instead of dedicated integer columns. Rejected: the guard-then-increment has to be ONE atomic
  statement under N frame jobs reserving concurrently; a json column forces a read-modify-write that loses
  reservations under exactly the concurrency this stage introduces — the identical reasoning that already
  ruled out a json shape for the workflow suspend/resume claim and the session claim itself.
- **A single job that renders all of a storyboard's frames sequentially, just moved to its own job class**
  (instead of one job per frame). Rejected: it would still be bounded by SOME fixed timeout (just a bigger,
  storyboard-specific one), would still serialize every shot behind the slowest one, and would forfeit the
  fan-out's actual payoff — a fleet of workers rendering the SAME run's frames in parallel.

## Consequences

- **Positive.** A full 8-shot `storyboard` — including one that draws a character via a reference edit on
  every flagged shot (ADR-0042) — now completes inside the platform's existing per-run ceilings without
  touching the session job's inviolate 300s window, and a single slow or failing shot no longer threatens the
  wall-clock budget of every other part in the run.
- **Positive (a bug fixed, not merely a bug avoided).** The delivery-vs-payload hardening (D6) closed a LATENT
  defect in the pre-existing, unrelated whole-session job — a redelivery under `tries = 1` could already have
  raced a live run's own completion and marked it `failed` out from under it. The frame engine's review is
  what surfaced the general shape of that bug; the fix landed in both places it applies, not only the new one.
- **Trade-off (accepted) — single-worker wall clock.** Frames of ONE run render in PARALLEL only across
  MULTIPLE workers; a single worker still dequeues and renders them one at a time, so an 8-shot storyboard
  with character reference edits (~58s median each) on one worker is several minutes, not seconds. The
  architecture's payoff is horizontal (add workers), not a faster single-worker path — this is an accepted,
  documented consequence, not an oversight.
- **Trade-off (accepted) — more moving parts.** A frame state machine, a second reaper window
  (`frame_stale_after`), and a second job class now exist where a plain loop inside one job used to be. This
  is the cost of the 300s window staying inviolate and of true per-frame parallelism; a smaller fix (e.g. a
  bigger timeout) would have been simpler but would not have scaled with the shot ceiling or the character
  phase's added per-shot cost.
- **Residual discipline required.** The five-window ordering invariant in D7 is load-bearing and is enforced
  only by documentation + a pinned default, not by a runtime assertion — a future change to any one window in
  isolation (raising the frame job's own timeout without raising `frame_stale_after`, say) could silently
  break the "the fine sweep always gets first crack" guarantee. Anyone touching one of the five numbers should
  re-read `config/generator.php`'s own invariant comment before changing it.

See `docs/backend/generator-sessions-api.md` → "Distributed storyboard frames" for the shipped wire/config
contract (the `pending`/`rendering` `image_status` values, `frame_stale_after`, the reaper table) and
`docs/decisions/ADR-0042-character-visual-identity.md` for the feature whose measured per-shot cost made this
stage necessary.
