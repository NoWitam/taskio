<?php

namespace App\Modules\Workflows\Jobs;

use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workflows\Services\WorkflowStepRunner;
use App\Modules\Workflows\Support\RealQueueConnection;
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
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * The SECOND (or third, …) pass over a run that a step parked in `waiting`. A FRESH job, never a
 * serialized continuation: it rebuilds everything from the database, exactly the way this codebase's
 * other claim-and-run jobs (the bot run jobs) do.
 *
 * SCALARS ONLY on the payload (run id + workspace id + the correlation key this delivery is FOR) —
 * the model is deliberately NOT serialized, so the worker re-reads the row under the correct tenant
 * connection and can never act on a stale copy.
 *
 * The run is CLAIMED here, not at dispatch: `claimResume()` is an atomic `waiting → running` update
 * guarded on that correlation key, so a doubled resume (a settle listener racing the waiting-run
 * sweep, or an at-least-once redelivery) loses the claim and returns cleanly — the suspended step is
 * never resumed twice, and a stale job can never resume a DIFFERENT leg the step has since parked on.
 *
 * $tries = 1 like WorkflowRunJob: a thrown pass routes straight to failed(), which is the last-resort
 * release. A hard SIGKILL leaves the run `running` with a freshly stamped started_at, so the EXISTING
 * stale-running reaper recovers it — that is precisely why claimResume() re-stamps started_at.
 */
class WorkflowRunResumeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Same window as WorkflowRunJob (see its $timeout note): a resumed pass runs the SAME steps
     * under the SAME semantics, so it gets the same worst-case budget. Keep the two in lock-step.
     */
    public int $timeout = 720;

    /**
     * $waitingKey is the correlation key this delivery was dispatched FOR. The resume claim is
     * guarded on it, so a duplicate or stale job (two dispatch sites racing, an at-least-once
     * redelivery) cannot resume a DIFFERENT leg of a step that has since re-suspended. Null means
     * uncorrelated — the legacy shape, for a job enqueued before this parameter existed (an old
     * payload unserializes with this default); it falls back to the state-only claim.
     */
    public function __construct(
        public string $runId,
        public string $workspaceId,
        public ?string $waitingKey = null,
    ) {}

    public function handle(WorkflowRunManager $runManager, WorkflowStepRunner $runner): void
    {
        if (!$this->activateTenant()) {
            return;
        }

        $run = WorkflowRun::find($this->runId);

        if ($run === null) {
            return;
        }

        // Atomic, CORRELATED resume claim. A lost claim means the run is no longer waiting on THIS
        // key (already resumed, re-suspended onto another leg, reaped, or released) — a silent,
        // clean no-op.
        if (!$runManager->claimResume($run, $this->waitingKey)) {
            return;
        }

        $realConnection = Queue::getDefaultDriver();
        $realQueue = app(RealQueueConnection::class);
        // SAVE/RESTORE, not set/clear: a run re-triggered from inside a step executes IN-PROCESS
        // under the sync override, so this job may be nested inside another one. Clearing
        // unconditionally would wipe the OUTER run's published connection and its suspending step
        // would then dispatch its external work onto the forced `sync` driver, running it inline
        // and defeating suspension.
        $publishedConnection = $realQueue->current();

        try {
            // The remaining steps must run under the SAME semantics as the first pass: the sync
            // driver keeps WorkflowRunContext alive across anything a step dispatches, which is what
            // lets HasCreator stamp step-authored rows with the run (ADR-0015). A step that needs the
            // REAL queue (i.e. one that suspends again) reads it from RealQueueConnection.
            if ($publishedConnection === null) {
                $realQueue->set($realConnection);
            }

            Queue::setDefaultDriver('sync');

            $runner->resume($run->fresh());
        } finally {
            Queue::setDefaultDriver($realConnection);
            // Restored in the SAME finally as the driver: this is a container singleton that
            // survives across jobs in a long-running worker, so a leak would mis-route a later,
            // unrelated dispatch. The OUTERMOST job restores null, i.e. the old clear().
            $realQueue->set($publishedConnection);
        }
    }

    /**
     * Last-resort release, mirroring WorkflowRunJob::failed(). The run was claimed back into
     * `running` before the throw, so without this it would sit there until the stale-running reaper.
     *
     * A run parked `waiting` is EXCLUDED (as in WorkflowRunJob::failed()): the resumed pass may have
     * re-suspended the run onto a second leg and only then thrown, and failing that healthy parked
     * run would orphan the external work its step just started.
     */
    public function failed(Throwable $e): void
    {
        if (!$this->activateTenant()) {
            return;
        }

        $run = WorkflowRun::find($this->runId);

        if ($run === null || $run->state->isTerminal() || $run->state === WorkflowRunState::WAITING) {
            return;
        }

        Log::error("Workflow run {$this->runId} failed to resume: {$e->getMessage()}");

        app(WorkflowRunManager::class)->release($run, WorkflowRunState::FAILED, $e->getMessage());
    }

    /**
     * Re-apply the dispatching workspace explicitly (belt-and-braces alongside QueueTenancy, which
     * already stamps + re-applies it): the waiting-run sweep dispatches this job from a pass with a
     * CLEARED context (the shared-DB pass) or from another tenant's context, and failed() can run
     * after QueueTenancy has already popped this job's context.
     *
     * THIS LOOKUP *IS* THE TENANCY GUARANTEE for a swept resume — the sweep stamps nothing, so nothing
     * else re-establishes the tenant. A workspace id that is PRESENT but does not resolve is therefore
     * a HARD STOP (returns false, the caller returns early): continuing would run the run lookup, the
     * session lookup and the Disk write that creates real user-visible files under whatever context
     * happens to be ambient in the worker. The run stays parked and the stale-wait reaper owns it.
     *
     * A BLANK id is the legacy/uncorrelated shape and still means "no workspace to apply" — the
     * caller proceeds under the ambient context, exactly as before.
     *
     * @return bool whether the job may continue
     */
    private function activateTenant(): bool
    {
        if ($this->workspaceId === '') {
            return true;
        }

        $workspace = Workspace::find($this->workspaceId);

        if ($workspace === null) {
            Log::warning('Workflow run resume skipped: its workspace could not be resolved, so the run was left parked rather than resumed unscoped.', [
                'run_id' => $this->runId,
                'workspace_id' => $this->workspaceId,
            ]);

            return false;
        }

        app(TenantContext::class)->set($workspace);

        if ($workspace->db_mode === WorkspaceDbMode::Own) {
            app(TenantManager::class)->configure($workspace);
        }

        return true;
    }
}
