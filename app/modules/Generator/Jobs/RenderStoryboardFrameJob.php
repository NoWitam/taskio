<?php

namespace App\Modules\Generator\Jobs;

use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Services\GenerationSessionRunManager;
use App\Modules\Generator\Services\StoryboardFrameManager;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ONE storyboard FRAME's image (the distributed-frames stage).
 *
 * WHY A JOB PER FRAME. A storyboard fans a slow provider call out per shot, and those calls were measured at
 * ~33s to generate and a median ~58s to edit against a reference. {@see RunGenerationSessionJob} has a fixed
 * 300s SIGALRM window that is INVIOLATE — the WithoutOverlapping expiry, the stale-frame window and the
 * whole-session reaper are all ordered on top of it — so eight frames could never fit inside it, and even the
 * generate-only case was already at the edge before the text parts and the direction derivation were paid
 * for. Splitting the work gives every frame a whole job's budget, lets a fleet of workers render frames in
 * PARALLEL, and confines a failure to its own frame: a failing provider call burns ~57s of ITS job instead of
 * the run's remaining headroom.
 *
 * SCALARS ONLY on the payload (session id + workspace id + the frame's canonical `storyboard.<i>` key + the
 * correlation token this delivery is FOR) — the model is deliberately not serialized, so the worker re-reads
 * the row under the right tenant connection and can never act on a stale copy. This is the same correlated
 * claim-and-run job shape the app's other suspend/resume engines use.
 *
 * THE CLAIM IS CORRELATED, and that is what makes at-least-once delivery safe here: the frame is claimed by
 * TOKEN ({@see StoryboardFrameManager::claim}), so a duplicate delivery and a straggler from a previous,
 * already-reaped attempt both lose the claim and return silently instead of billing a second image.
 *
 * `tries = 1`, like every other job in this engine: the render is fail-SOFT by construction (a provider,
 * budget or domain failure comes back as a `failed` frame, not a throw), so a throw here is an infra fault
 * that a retry would not clear. It is settled as a failed frame — never left outstanding, or the run could
 * not finish — and then rethrown so it lands in failed_jobs for diagnosis.
 *
 * ...WHICH IS ALSO WHY failed() IS DELIVERY-GUARDED. `tries = 1` means a redelivery (the render outlives the
 * queue's retry_after — see the timeout note) is failed by the worker in
 * markJobAsFailedIfAlreadyExceedsMaxAttempts, which runs BEFORE fire() and therefore before the
 * WithoutOverlapping middleware below could release it. So failed() fires for a delivery that never entered
 * handle(), on a FRESH command instance unserialized from the payload — and the correlation token cannot
 * tell it apart from the rightful owner, because the token identifies the FRAME, not the DELIVERY. Left
 * unguarded, that duplicate settles a frame the ORIGINAL is at that moment paying for: the run flips
 * terminal, the FE is told it is done, and the original's own write-back is then rejected and its blob
 * orphaned. {@see mayReleaseFrame} is the discriminator.
 */
class RenderStoryboardFrameJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Whether THIS object won the frame's claim inside handle(). Not serialized state — it is set on the
     * live instance only, and read by failed() when the framework happens to call it on that same object
     * (an in-process dispatch). The production path is the attempt check in {@see mayReleaseFrame}.
     */
    private bool $claimWon = false;

    /**
     * One frame is ONE `ai_generate` base plus however many `ai_edit` filters the storyboard's authored chain
     * applies to every shot. Each of those is bounded by `ai.image_timeout` (120s), so 240s covers the common
     * base-plus-one-filter frame at its full HUNG-PROVIDER ceiling, against a measured ~91s for a healthy
     * one. It stays BELOW RunGenerationSessionJob's 300s, so the existing 300 < 600 < 900 < 1800 ordering is
     * untouched and this simply slots in beneath it.
     *
     * Note the honest caveat this engine already lives with: the queue's retry_after (90s app default) is
     * lower than any of these timeouts, so a long frame CAN be re-reserved and redelivered. That is precisely
     * why the WithoutOverlapping lock below and the token claim exist — a redelivery is released or lost, and
     * either way it never renders a second billed image.
     */
    public int $timeout = 240;

    public function __construct(
        public string $sessionId,
        public string $workspaceId,
        public string $partKey,
        public string $frameToken,
    ) {}

    /**
     * One in-flight job per FRAME, not per session: the lock keys on the frame's own address so frames of the
     * same run still render CONCURRENTLY (the entire point of the fan-out) while a duplicate delivery of the
     * SAME frame is released. Deliberately a different key space from the session run job's lock, which keys
     * on the bare session id — sharing it would serialize the whole storyboard back into one queue slot.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->lockKey()))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(StoryboardFrameManager $frames, GenerationSessionRunManager $runs): void
    {
        $this->activateTenant();

        $session = $frames->claim($this->sessionId, $this->partKey, $this->frameToken);

        // Lost claim: a duplicate delivery, a straggler from a superseded run, or a session that is no longer
        // generating. A clean, silent no-op — never a second billed image. DEBUG, not info: with a retry_after
        // below the render's real duration a redelivered frame is ROUTINE, and an info line per duplicate
        // buries the events that actually mean something.
        if ($session === null) {
            Log::debug('Storyboard frame render skipped (claim lost)', [
                'session_id' => $this->sessionId,
                'part_key' => $this->partKey,
            ]);

            return;
        }

        $this->claimWon = true;

        try {
            if ($frames->render($session, $this->partKey, $this->frameToken)) {
                $this->announceSettled($runs);
            }
        } catch (Throwable $e) {
            // An INFRA fault (the render itself is fail-soft). Log the real cause LOUD but keep it out of the
            // row, settle this frame `failed` so the run is not held open by a frame that will never return,
            // then rethrow for failed_jobs. Never logs a prompt, visual or produced content.
            Log::error('Storyboard frame render threw', [
                'session_id' => $this->sessionId,
                'part_key' => $this->partKey,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);
            report($e);

            $this->releaseFrame($frames, $runs);

            throw $e;
        }
    }

    /**
     * A thrown / timed-out frame: make sure it is not left outstanding — but ONLY for the delivery that
     * actually held the claim ({@see mayReleaseFrame}). Idempotent for that delivery: if handle() already
     * settled the frame in its own catch, the token claim rejects this one and nothing happens (a settled
     * frame is no longer `rendering`). Restores tenancy first, since failed() can run after the queue
     * listener popped this job's context.
     */
    public function failed(Throwable $e): void
    {
        $this->activateTenant();

        $owns = $this->mayReleaseFrame();

        Log::error('Storyboard frame job FAILED', [
            'session_id' => $this->sessionId,
            'part_key' => $this->partKey,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            // The fact that decides whether this hook touches the row at all — invaluable when a frame
            // "disappeared" and the question is which delivery did it.
            'failing_the_frame' => $owns,
            'attempt' => $this->job?->attempts(),
        ]);

        if (!$owns) {
            return;
        }

        $this->releaseFrame(app(StoryboardFrameManager::class), app(GenerationSessionRunManager::class));
    }

    /**
     * Whether the delivery being failed is the one that CLAIMED the frame — the guard that makes this hook
     * safe under at-least-once delivery (see the class note).
     *
     * Two ways to know, because Laravel calls failed() on a FRESH command unserialized from the payload
     * (Job::failed → CallQueuedHandler::failed → getCommand), so the instance that ran handle() is normally
     * not the instance that is asked:
     *
     *   1. this very object won the claim — true only for an in-process failure of the running instance;
     *   2. the queue Job the framework injects reports its ATTEMPT. Under `tries = 1` only attempt 1 can
     *      ever reach handle() (a later attempt is failed before fire()), so `attempts() > 1` is PROOF that
     *      this delivery never claimed anything, while attempt 1 is the SIGALRM/kill case that genuinely
     *      owns the frame and must release it — otherwise the run hangs until the (much slower) stale-frame
     *      reaper.
     *
     * FAILS CLOSED with no Job attached (a hand-built instance, a caller outside the worker): unable to
     * prove ownership, it does not touch a frame that may be live. The reaper remains the backstop for the
     * frame it then leaves `rendering`, which is exactly what that window exists for.
     */
    private function mayReleaseFrame(): bool
    {
        return $this->claimWon || ($this->job !== null && $this->job->attempts() <= 1);
    }

    /**
     * Settle this frame as FAILED (fail-soft per frame — every other frame keeps its image and the run still
     * ends `ready`) and, when that was the last outstanding frame, push the run's single terminal
     * notification. A frame that is already terminal is not owned any more, so this is a no-op.
     */
    private function releaseFrame(StoryboardFrameManager $frames, GenerationSessionRunManager $runs): void
    {
        if ($frames->settle($this->sessionId, $this->partKey, $this->frameToken, null)) {
            $this->announceSettled($runs);
        }
    }

    /** Push the ONE terminal broadcast + outcome log for the run this frame just completed. */
    private function announceSettled(GenerationSessionRunManager $runs): void
    {
        $session = GenerationSession::find($this->sessionId);

        if ($session !== null) {
            $runs->announceSettled($session, 'frame');
        }
    }

    /** The per-frame overlap lock key (session + frame address), namespaced away from the run job's key. */
    private function lockKey(): string
    {
        return 'generator-frame:' . $this->sessionId . ':' . $this->partKey;
    }

    /**
     * Re-apply the dispatching workspace so the tenant-scoped session resolves (and, for an own-database
     * workspace, routes to the right connection). Mirrors {@see RunGenerationSessionJob::activateTenant} — a
     * vanished workspace leaves the context cleared and the downstream claim simply finds nothing.
     */
    private function activateTenant(): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        app(TenantContext::class)->set($workspace);

        if ($workspace->db_mode === WorkspaceDbMode::Own) {
            app(TenantManager::class)->configure($workspace);
        }
    }
}
