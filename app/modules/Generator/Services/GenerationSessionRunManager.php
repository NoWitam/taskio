<?php

namespace App\Modules\Generator\Services;

use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Events\GenerationSessionUpdated;
use App\Modules\Generator\Exceptions\GenerationBudgetExceeded;
use App\Modules\Generator\Jobs\RenderStoryboardFrameJob;
use App\Modules\Generator\Jobs\RunGenerationSessionJob;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Support\StoryboardFrame;
use App\Modules\Variables\Services\AiUsageService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the ASYNC lifecycle of a generation run — the state machine over
 * {@see GenerationSessionStatus}. Mirrors the Disk/Workflow run pattern (atomic CLAIM → dispatch a job
 * that re-establishes tenancy → render → terminal), NOT a new engine. R2 sub-stage 2d parameterizes the
 * SAME machinery with a {@see GenerationRunMode} so the per-part refine loop reuses the claim/job/tenancy/
 * meter/idempotency path (no fork):
 *
 *   claimAndDispatch()  a guarded UPDATE claims the session (draft|ready|failed → generating) and, only
 *                       when THIS request won the claim, queues {@see RunGenerationSessionJob} with SCALARS
 *                       (id + workspace id + mode/partKey/instruction, never the model). An already-
 *                       `generating` session is not claimed → the caller returns 409 — the same 409 gates a
 *                       concurrent whole-run AND a part op (one op at a time per session).
 *   run()               the worker path: reload a FRESH session and run it ONLY while it is still
 *                       `generating` (this request won the claim); a gone, terminal, or unclaimed
 *                       draft/ready row is an idempotent no-op — a stale/stray delivery must never
 *                       resurrect/double-run it. Dispatches by mode: full → the whole-session executor;
 *                       regenerate/refine → the {@see GenerationSessionRefiner} part op. Then `ready`.
 *   fail()              terminal-safe whole-run failure (the job's failed() hook) — mark `failed` unless
 *                       already terminal.
 *
 * A run no longer necessarily ENDS with the session job. When the rendered results announce storyboard
 * FRAMES ({@see StoryboardFrame} — one queue job per shot image, because N slow provider calls cannot fit the
 * job's inviolate 300s window), run() persists the results, leaves the session `generating`, and queues those
 * jobs; the LAST frame to settle flips the status and calls {@see announceSettled}. The FE contract is
 * unchanged and is precisely why the status is held open: `generating` until exactly ONE terminal broadcast.
 * A session with no storyboard announces nothing, queues nothing, and settles inside run() as it always did.
 *
 * The STALE reaper (a run stuck in `generating` past a timeout → failed) lives in the separate
 * {@see GenerationSessionLifecycleService} + the scheduled `generator:reap-sessions` command (R2 sub-stage 2d),
 * NOT in this manager.
 */
class GenerationSessionRunManager
{
    public function __construct(
        private GenerationSessionExecutor $executor,
        private GenerationSessionRefiner $refiner,
        private TenantContext $tenant,
        private GeneratedImageStore $images,
        private AiUsageService $usage,
    ) {}

    /**
     * Atomically CLAIM a session for a run and queue it. The claim is a guarded UPDATE: only a session that
     * is NOT already `generating` transitions to `generating`, and the affected-row count tells us whether
     * THIS request won the claim — so a concurrent double-run is a no-op, not a double (billed) run, for
     * BOTH a whole-session generate and a part op. A whole-session run (`mode:full`) clears the prior
     * `results` + `history` AND the prior run's produced-image blobs (so a part that flips ok→failed on
     * re-run can't serve/save a stale image, and every part restarts at version 1). A part op
     * (regenerate/refine) claims the WHOLE session but must NOT clear results/history/blobs — that would
     * destroy the other parts' versions and the refine history — so it only flips the status. EVERY claim
     * (full OR part op) clears the transient `last_op_status`/`last_op_error` signal, so a stale prior-op
     * outcome can never leak onto a fresh run. Returns true when claimed + dispatched, false when the session
     * was already `generating` (the caller answers 409).
     *
     * CREATIVE DIRECTION follows the same split: a FULL run nulls it (the recipe/slots may have changed, so
     * the run derives a fresh frame), while a part op PRESERVES it — that is precisely what keeps a refine
     * coherent with the rest of the piece, and it is why a refine costs ZERO derivation calls.
     *
     * $connection is an OPTIONAL, defaulted queue connection for the dispatch (R2 sub-stage 5 automation
     * seam). Omitted (every interactive caller) it resolves to `config('queue.default')` — exactly the
     * connection an unqualified dispatch would have picked, so the interactive path is unchanged. A
     * NON-interactive caller that runs inside a scope which has REBOUND the default connection (e.g. an
     * engine forcing `sync` around its own work) passes the real connection EXPLICITLY, so a session run is
     * still queued ASYNC instead of executing inline. Pinning a connection on the JOB class instead would
     * change the interactive chat path too, which is why it is a per-dispatch argument.
     */
    public function claimAndDispatch(
        GenerationSession $session,
        GenerationRunMode $mode = GenerationRunMode::Full,
        ?string $partKey = null,
        ?string $instruction = null,
        ?string $connection = null,
    ): bool {
        // GATE-BEFORE-SPEND (R2 sub-stage 4): the SINGLE choke point every run entry point routes through
        // (whole generate / per-part regenerate / per-part refine / delegate auto-run all call this), so the
        // budget refusal lives here ONCE — before the claim/dispatch — never duplicated across four
        // controllers. An already-over-cap workspace is refused UP FRONT (a 429), so its run is never claimed
        // or partially billed; a run that CROSSES the cap mid-flight is still handled fail-soft by the executor.
        $this->assertWithinBudget();

        $isFull = $mode === GenerationRunMode::Full;

        // IMAGE BUDGET RESET (the distributed-frames stage): the per-run `ai_generate`/`ai_edit` ceilings are
        // now charged against PERSISTED counters, because a storyboard run spans the session job plus one job
        // per frame and no in-process counter can bound that. Zeroing them here — on EVERY claim, full run and
        // part op alike — keeps the documented semantics exactly as they were: a budget per RUN, where a
        // regenerate/refine is its own run and gets its own fresh budget. The claim is already the single
        // choke point every entry point routes through, so this is the one place that can define "a run".
        $ledgerReset = ['ai_generate_calls' => 0, 'ai_edit_calls' => 0];

        $claimed = GenerationSession::query()
            ->whereKey($session->getKey())
            ->whereIn('status', [
                GenerationSessionStatus::Draft->value,
                GenerationSessionStatus::Ready->value,
                GenerationSessionStatus::Failed->value,
            ])
            ->update($isFull
                ? ['status' => GenerationSessionStatus::Generating->value, 'results' => null, 'history' => null, 'creative_direction' => null, 'last_op_status' => null, 'last_op_error' => null] + $ledgerReset
                : ['status' => GenerationSessionStatus::Generating->value, 'last_op_status' => null, 'last_op_error' => null] + $ledgerReset);

        if ($claimed === 0) {
            return false;
        }

        // Only a WHOLE-session run wipes the produced-image prefix; a part op keeps every part's versions
        // (and the refine history's blobs) intact. Only the request that WON the claim reaches here, so
        // clearing is race-free, in the request's (correct) tenant context matching the store's path.
        if ($isFull) {
            $this->images->clearSession($session->getKey());
        }

        $session->refresh();

        // Pass scalars, never the model: the worker reloads a FRESH row and the workspace id lets it
        // re-establish tenancy outside the request (see the job). The connection is the caller's explicit
        // one or the ambient default (the unqualified dispatch's own resolution).
        RunGenerationSessionJob::dispatch($session->getKey(), (string) $this->tenant->id(), $mode->value, $partKey, $instruction)
            ->onConnection($connection ?? config('queue.default'));

        return true;
    }

    /**
     * Refuse a run UP FRONT when the active workspace is ALREADY at/over its effective calendar-month AI $
     * cap. Reuses the SAME predicate as the usage summary's `blocked` flag ({@see AiUsageService::blocked})
     * so the server's refusal and the FE's budget banner AGREE. Byte-preserving when the cap is off
     * (`blocked()` is false → a NO-OP, the run proceeds exactly as before the gate). Throws the renderable
     * {@see GenerationBudgetExceeded} (HTTP 429 + `code:ai_budget_exceeded`) — a real HTTP response the FE
     * recognizes, NOT the mid-run fail-soft {@see \App\Modules\Variables\Exceptions\AiBudgetExceededException}.
     */
    private function assertWithinBudget(): void
    {
        if ($this->usage->blocked()) {
            throw new GenerationBudgetExceeded;
        }
    }

    /**
     * Run a claimed session through the appropriate unit of work. Idempotent AND claim-checked: it runs ONLY
     * a session that is actually `generating` (this request won the claim). A missing row, an already-
     * terminal row (a retry/duplicate landing after the run finished or after the reaper gave up), or an
     * unclaimed `draft`/`ready` row (a stray dispatch that never won the claim) are all clean no-ops — never
     * an unclaimed run. The mode selects the unit: `full` renders every part fresh; `regenerate`/`refine`
     * apply a single-part op (render + history push) via the refiner. On success the results (+ history for a
     * part op) are persisted and the session is marked `ready` — even when some parts individually `failed`
     * (fail-soft per part).
     */
    public function run(
        string $sessionId,
        GenerationRunMode $mode = GenerationRunMode::Full,
        ?string $partKey = null,
        ?string $instruction = null,
    ): void {
        $session = GenerationSession::find($sessionId);

        // Only a CLAIMED (generating) session runs. A gone row, a terminal row, or an unclaimed
        // draft/ready row are all clean no-ops — a stray/stale delivery must never run unclaimed.
        if ($session === null || $session->status !== GenerationSessionStatus::Generating) {
            Log::info('Generation session run skipped (gone or not generating)', [
                'session_id' => $sessionId,
                'mode' => $mode->value,
                'status' => $session?->status->value,
            ]);

            return;
        }

        $update = match ($mode) {
            GenerationRunMode::Full => ['results' => $this->executor->execute($session)],
            GenerationRunMode::Regenerate => $this->refiner->regenerate($session, (string) $partKey),
            GenerationRunMode::Refine => $this->refiner->refine($session, (string) $partKey, (string) $instruction),
        };

        // A3: lift the deferred history-cap blob GC out of the column update — a part op returns the dropped
        // blob refs; GC them only AFTER the new pointer commits (below). A full run never carries them.
        $gcBlobs = $update['gc_blobs'] ?? [];
        unset($update['gc_blobs']);

        // DISTRIBUTED FRAMES: a storyboard part ANNOUNCES its shots rather than rendering them (the images
        // are too slow to fit this job's inviolate 300s window), so finishing this job no longer means
        // finishing the RUN. When frames were announced the session deliberately stays `generating` — the FE
        // contract is one broadcast at settle, and settling now belongs to whichever frame job finishes last.
        $frames = StoryboardFrame::pendingIn(is_array($update['results'] ?? null) ? $update['results'] : []);

        // A1: a part op's $update also carries last_op_status/last_op_error, so the op OUTCOME lands in the SAME
        // write that flips `ready` (a full run leaves them null — already cleared at claim).
        $session->update($update + ['status' => $frames === [] ? GenerationSessionStatus::Ready : GenerationSessionStatus::Generating]);

        $this->refiner->gcDroppedBlobs($session, $gcBlobs);

        if ($frames !== []) {
            $this->dispatchFrames($session, $frames, $mode);

            return;
        }

        $this->announceSettled($session, $mode->value);
    }

    /**
     * Queue ONE {@see RenderStoryboardFrameJob} per announced frame, AFTER the results carrying those frames
     * are persisted — a frame job re-reads the row and must find its own `pending` entry there, so dispatching
     * earlier would race the write. Scalars only (id + workspace + the frame's canonical `storyboard.<i>` key
     * + its correlation token), never the model: the worker rebuilds everything under its own tenancy.
     *
     * The frame key is passed as the single dotted address rather than as a (part, index) pair, because that
     * dotted key is ALREADY the canonical way every layer addresses a shot (the executor, the refiner, the
     * image store); splitting it in the payload would create two values that could disagree.
     *
     * @param  array<int, array{part_key: string, token: string}>  $frames
     */
    private function dispatchFrames(GenerationSession $session, array $frames, GenerationRunMode $mode): void
    {
        $workspaceId = (string) $this->tenant->id();

        foreach ($frames as $frame) {
            RenderStoryboardFrameJob::dispatch($session->getKey(), $workspaceId, $frame['part_key'], $frame['token']);
        }

        // The FACT only (how many frames, for which run) — never a visual, prompt or shot text.
        Log::info('Generation session storyboard frames dispatched', [
            'session_id' => $session->getKey(),
            'mode' => $mode->value,
            'frames' => count($frames),
        ]);
    }

    /**
     * The run reached its TERMINAL state: log the non-secret outcome trail and push the single settle
     * broadcast the FE waits on. PUBLIC because the settling write is no longer always made by the session
     * job — when a run fanned out, the LAST frame job flips the status and then calls this, so the terminal
     * log + the one broadcast keep exactly one definition instead of being reimplemented per path.
     */
    public function announceSettled(GenerationSession $session, string $mode): void
    {
        // Outcome trail: the run is `ready`, but individual parts may have `failed` (fail-soft). Log the
        // per-part status map (keys + statuses only — never the produced content) so a run that looks
        // successful yet has a silently-failed part is diagnosable. See the executor for each part's cause.
        Log::info('Generation session run completed', [
            'session_id' => $session->getKey(),
            'mode' => $mode,
            'status' => $session->status->value,
            'part_status' => $this->partStatusSummary($session),
            'last_op_status' => $session->last_op_status,
        ]);

        $this->broadcastTerminal($session);
    }

    /**
     * PUSH the TERMINAL status to the workspace's private channel so the chat stops waiting (no polling) and
     * re-fetches the settled session. Guarded on an active workspace — a reaper sweep with cleared tenant
     * context (the shared-DB pass) skips the push, so those rare stale-reaped sessions rely on the chat's
     * safety timeout. Never carries the produced content (see {@see GenerationSessionUpdated}).
     */
    private function broadcastTerminal(GenerationSession $session): void
    {
        $workspaceId = $this->tenant->id();

        if ($workspaceId === null) {
            return;
        }

        broadcast(new GenerationSessionUpdated(
            (string) $workspaceId,
            $session->id,
            $session->status->value,
            $session->last_op_status,
        ));
    }

    /**
     * A NON-SECRET per-part status map (`{partKey: status}`) for the completion log — makes a `ready` run
     * whose parts individually `failed` visible, without ever logging the produced text/image/prompt.
     *
     * @return array<string, string>
     */
    private function partStatusSummary(GenerationSession $session): array
    {
        $results = is_array($session->results) ? $session->results : [];
        $summary = [];

        foreach ($results as $key => $result) {
            $summary[(string) $key] = is_array($result) && is_string($result['status'] ?? null)
                ? $result['status']
                : 'unknown';
        }

        return $summary;
    }

    /**
     * Mark a run failed (the job's failed() hook / the stale-session reaper). Terminal-safe — never
     * overwrites an already-finished session. The per-part reason (if any) lives in `results`; a whole-run
     * failure is signalled by the status alone (no secret is ever surfaced).
     *
     * IT ALSO CLOSES THE FRAMES THE RUN ABANDONS. A frame may only be claimed while its session is still
     * `generating` ({@see StoryboardFrameManager::claim}), so failing the run makes every `pending`/
     * `rendering` shot permanently unreachable — no worker will ever settle it, and the FE (which branches
     * per shot on `image_status`) would show them in flight for the lifetime of the row. They are settled
     * `failed` with the same lost-frame reason the reaper uses, in the SAME write as the status flip, under
     * the session row lock the frame writers serialize on — so a frame committing concurrently either lands
     * before this and is preserved, or blocks and finds the run already terminal.
     */
    public function fail(string $sessionId): void
    {
        $failed = DB::transaction(function () use ($sessionId): ?GenerationSession {
            $locked = GenerationSession::query()->whereKey($sessionId)->lockForUpdate()->first();

            if ($locked === null || $locked->status->isTerminal()) {
                return null;
            }

            $results = is_array($locked->results) ? $locked->results : [];
            $closed = StoryboardFrame::failOutstandingIn($results, __('generator.sessions.frame_lost'));

            // A run with no outstanding frame writes exactly the column it always did (the helper returns
            // the results UNCHANGED), so a text-only session's failure is byte-identical to before.
            $locked->update(['status' => GenerationSessionStatus::Failed]
                + ($closed === $results ? [] : ['results' => $closed]));

            return $locked;
        });

        if ($failed !== null) {
            $this->broadcastTerminal($failed);
        }
    }
}
