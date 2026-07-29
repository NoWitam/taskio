<?php

namespace App\Modules\Generator\Jobs;

use App\Modules\Generator\Enums\GenerationRunMode;
use App\Modules\Generator\Services\GenerationSessionRunManager;
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
 * One ASYNC generation-session run (R2 sub-stage 2b; parameterized by MODE in 2d). The session was CLAIMED
 * into `generating` at dispatch
 * ({@see \App\Modules\Generator\Services\GenerationSessionRunManager::claimAndDispatch}); this job renders it
 * off the request (an `@[ai-text]` / `ai_image_edit` provider call is slow) and records the outcome. The
 * {@see GenerationRunMode} + optional partKey/instruction select the unit of work — a WHOLE-session generate
 * (`full`, the 2b/2c behavior, unchanged) or a single-part refine-loop op (`regenerate`/`refine`, 2d) — so the
 * ONE async engine (claim / tenancy / idempotency / WithoutOverlapping / meter tag / failed()) backs both.
 * The `instruction` is user DATA carried on the scalar payload; it is NEVER logged.
 *
 * DETERMINISTIC failure model: the shared ai-text generator is FAIL-CLOSED (a provider/transport/over-cap
 * error resolves to '', never throws) and the executor is fail-soft PER PART, so a run only throws on an
 * infra fault (e.g. the DB). Such a fault will not clear within a retry window, so `tries = 1` — the throw
 * routes straight to failed(), which marks the session `failed`. The STALE reaper (a worker SIGKILL/OOM
 * that never reaches failed()) is 2d.
 *
 * Tenancy: mirrors {@see \App\Modules\Disk\Jobs\EditDiskImageJob} — QueueTenancy re-applies the
 * dispatching workspace on the worker, but this job ALSO re-establishes it explicitly from its own stored
 * workspace id (a security-critical, explicit property; and failed() can run after the listener already
 * restored the previous context). Broadcast/poll: 2b keeps it LEAN — no push; the FE polls
 * GET /generator/sessions/{id} until the status is terminal.
 */
class RunGenerationSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // The run may make up to config('generator.ai_text_max_calls_per_session') ai-text provider calls
    // (a single text part can carry several inline @[ai-text] blocks), each up to ai.text_timeout (60s),
    // PLUS exactly ONE creative-direction derivation bounded by the tighter ai.direction_timeout (30s).
    // The job's own SIGALRM must EXCEED that worst-case total, or a slow-but-alive run is killed and
    // (tries=1) spuriously failed — and no reaper recovers a stranded `generating` row until 2d.
    // INVARIANT (mirrors the Disk edit job): retry_after > $timeout > max_calls x ai.text_timeout +
    // ai.direction_timeout. With the default budget 4: 300s > 4 x 60 + 30 = 270s (30s headroom). This
    // timeout is deliberately UNCHANGED by the direction layer — the derivation was given its own
    // tighter per-call ceiling precisely so the 300s window (and the lock/reaper windows ordered on top
    // of it) did not have to move. The queue's retry_after is left at the app default (below $timeout);
    // WithoutOverlapping below makes a duplicate delivery safe regardless. Raise this AND
    // config('generator.ai_text_max_calls_per_session') together to grow the fan-out.
    public int $timeout = 300;

    public function __construct(
        public string $sessionId,
        public string $workspaceId,
        public string $mode = 'full',
        public ?string $partKey = null,
        public ?string $instruction = null,
    ) {}

    /**
     * One run at a time per session. At-least-once delivery (a visibility-timeout re-reservation) must
     * never double-run the BILLED ai-text, so a duplicate is released rather than run; the lock keys on
     * the session and self-expires well past the timeout so a killed worker never wedges it.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->sessionId))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(GenerationSessionRunManager $manager): void
    {
        $this->activateTenant();

        Log::info('Generation session run starting', [
            'session_id' => $this->sessionId,
            'workspace_id' => $this->workspaceId,
            'mode' => $this->mode,
            'part_key' => $this->partKey,
            'has_instruction' => $this->instruction !== null,
        ]);

        try {
            $manager->run(
                $this->sessionId,
                GenerationRunMode::tryFrom($this->mode) ?? GenerationRunMode::Full,
                $this->partKey,
                $this->instruction,
            );
        } catch (Throwable $e) {
            // A whole-run throw (an infra fault, NOT a fail-soft part) — log the real cause LOUD (error +
            // class + location + full trace via report()) so it is never silent, kept OUT of the row (which
            // surfaces no secret). The instruction/prompt/slot content is NEVER logged. Then rethrow so the
            // job routes to failed().
            Log::error('Generation session run threw', [
                'session_id' => $this->sessionId,
                'mode' => $this->mode,
                'part_key' => $this->partKey,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);
            report($e);

            throw $e;
        }
    }

    /**
     * A thrown run (or exhausted retries): mark the session failed. Restores tenancy first — failed() can
     * run after QueueTenancy has already popped this job's context.
     */
    public function failed(Throwable $e): void
    {
        $this->activateTenant();

        Log::error('Generation session run FAILED (marking session failed)', [
            'session_id' => $this->sessionId,
            'mode' => $this->mode,
            'part_key' => $this->partKey,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        app(GenerationSessionRunManager::class)->fail($this->sessionId);
    }

    /**
     * Re-apply the dispatching workspace so the tenant-scoped session resolves (and, for an own-database
     * workspace, routes to the right connection). Mirrors the Disk job's inline activation. A vanished
     * workspace leaves the context cleared and the downstream find() simply no-ops.
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
