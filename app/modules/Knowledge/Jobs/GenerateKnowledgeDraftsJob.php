<?php

namespace App\Modules\Knowledge\Jobs;

use App\Modules\Knowledge\Enums\KnowledgeDraftSessionStatus;
use App\Modules\Knowledge\Events\KnowledgeDraftSessionUpdated;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Services\KnowledgeDraftService;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ONE composition run for a drafting session, off the request.
 *
 * Async because a model writing several entries takes tens of seconds, and the alternative — holding
 * the HTTP request open — turns every composition into a timeout risk and makes the browser the thing
 * that decides whether the user's work survives. The session row is the state; the client polls it.
 *
 * SCALARS on the payload, never the model: a serialized session would carry the whole source text
 * through the queue and could resolve stale against a session the user has since abandoned.
 *
 * TENANCY is re-established explicitly from the workspace id, the posture every queued job in this
 * codebase takes — a security property, not a convenience. Without it a worker whose context leaked
 * from a previous job would compose against another workspace's base.
 *
 * `tries = 1`, and deliberately: the run's failure modes are a refusing provider, an unparseable reply
 * and an over-cap workspace. None improves on a retry, all three are recorded ON THE SESSION as a
 * status a human can act on, and retrying would re-spend money on the same refusal. Retrying is the
 * user's decision — and it is the same action as refining.
 */
class GenerateKnowledgeDraftsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Comfortably above the service's own provider timeout, so the job never dies mid-call. */
    public int $timeout = 300;

    public function __construct(
        public string $sessionId,
        public string $workspaceId,
    ) {}

    public function handle(KnowledgeDraftService $service): void
    {
        if (!config('knowledge.index.enabled')) {
            // The module's single AI kill switch, honoured as the first statement exactly as the
            // indexing job honours it — including for work already sitting in the queue. The session is
            // released rather than left claimed: an operator flipping a switch must not strand a user's
            // session in `generating` forever.
            $this->releaseSession();

            return;
        }

        $this->activateTenant();

        $service->generate($this->sessionId);
    }

    /**
     * An infrastructure fault (or a job timeout): record it on the session, so the client's poll sees a
     * terminal state instead of a claim nobody holds.
     */
    public function failed(Throwable $e): void
    {
        $this->activateTenant();

        // The exception CLASS only — a provider or query message can carry the prompt, and the prompt
        // is the user's material.
        Log::error('Knowledge drafting job FAILED', [
            'session_id' => $this->sessionId,
            'workspace_id' => $this->workspaceId,
            'exception' => $e::class,
            'code' => $e->getCode(),
        ]);

        $this->releaseSession(KnowledgeDraftService::FAILURE_PROVIDER);
    }

    /**
     * Put the session back into a state the user can act from — and TELL the browser.
     *
     * The push is not optional here. This is the module's second settle path (the service owns the
     * other, {@see KnowledgeDraftService::settle()}), reached when the kill switch was flipped after
     * the job was queued or when the job died on its own timeout. Both write a terminal status, and
     * writing one without the broadcast leaves the composer waiting on an event that will never come:
     * with a 300-second job timeout on top of the run, that is minutes of a spinner for a failure the
     * server already knows about. "A transition is never persisted without the push" has to hold on
     * every path or it is not an invariant, just a habit of one of them.
     */
    private function releaseSession(?string $reason = null): void
    {
        $this->activateTenant();

        $session = KnowledgeDraftSession::query()->find($this->sessionId);

        if ($session === null || $session->status !== KnowledgeDraftSessionStatus::GENERATING) {
            return; // already settled — a redelivery must not overwrite a real outcome
        }

        $session->forceFill([
            'status' => KnowledgeDraftSessionStatus::FAILED,
            'claimed_at' => null,
            'failure_reason' => $reason ?? 'disabled',
        ])->save();

        // Both scalars are already on the payload, so this needs no tenant lookup of its own.
        KnowledgeDraftSessionUpdated::dispatch(
            $this->workspaceId,
            $this->sessionId,
            KnowledgeDraftSessionStatus::FAILED->value,
        );
    }

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
