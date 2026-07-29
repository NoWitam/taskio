<?php

namespace App\Modules\Workflows\Jobs;

use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workflows\Services\WorkflowStepRunner;
use App\Modules\Workflows\Support\RealQueueConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * One execution of a workflow run. Tenancy is carried across the queue boundary by
 * QueueTenancy (mirrors BotTaskExecutionJob / ProcessAiApprovalJob), so the run's steps
 * create tenant-aware rows on the correct connection.
 *
 * The run is created `pending` by WorkflowRunManager::start(); this job CLAIMS it (atomic,
 * so a lost claim — already running or terminal — is a silent no-op) and hands off to the
 * step runner, which releases the run to its terminal state — OR parks it `waiting` if a step
 * suspends to await external work, in which case a later WorkflowRunResumeJob finishes the run.
 * $tries = 1: a failure fires failed() as the last-resort release.
 *
 * Only the run ID is serialized (not the model) so the worker re-reads it under the correct
 * tenant connection.
 */
class WorkflowRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * The job's OWN SIGALRM window. Until now the job declared none and silently inherited the
     * worker's `--timeout` (60s by default) — far below what a real run may need, since ONE run may
     * make up to config('workflows.ai_text_max_calls_per_run') (10) `@[ai-text]` provider calls, each
     * bounded by config('ai.text_timeout') (60s): a 600s worst case.
     *
     * INVARIANT: workflows.run_timeout (900s, the stale-RUNNING reaper) > this timeout (720s) >=
     * worst-case run (600s). The reaper must fire LAST, or it would fail a run that the worker is
     * still legitimately executing; and the job must be killed before it, so failed() gets the chance
     * to release the run honestly. The worker's `--timeout` must be >= this value for it to apply
     * (Laravel uses the job's timeout when set, so raising it here is what actually relaxes the
     * 60s default). The queue's retry_after (90s) is BELOW this on purpose only because $tries = 1
     * plus the atomic claim makes a duplicate delivery a no-op rather than a double run.
     * Raise this together with workflows.ai_text_max_calls_per_run if that fan-out ever grows.
     */
    public int $timeout = 720;

    public function __construct(
        public string $runId,
    ) {}

    public function handle(WorkflowRunManager $runManager, WorkflowStepRunner $runner): void
    {
        $run = WorkflowRun::find($this->runId);

        if ($run === null) {
            return;
        }

        // Atomic claim: if another worker already took this run (or it is terminal), this
        // call loses and we silently no-op. Race-safe under a non-sync queue.
        if (!$runManager->claim($run)) {
            return;
        }

        $previousConnection = Queue::getDefaultDriver();
        $realQueue = app(RealQueueConnection::class);
        // SAVE/RESTORE, not set/clear: a run re-triggered from inside a step executes IN-PROCESS
        // under the sync override, so this job may be nested inside another run job. Clearing
        // unconditionally would wipe the PARENT's published connection, and the parent's suspending
        // step would then dispatch its external work onto the forced `sync` driver — running it
        // inline and defeating suspension entirely.
        $publishedConnection = $realQueue->current();

        try {
            // The sync override keeps WorkflowRunContext alive across anything a step dispatches, so
            // HasCreator stamps step-authored rows with the RUN (ADR-0015). Consequence worth naming:
            // work a step "fires and forgets" (e.g. the form-report analysis job) actually executes
            // IN-PROCESS before that step returns — it is not asynchronous during a workflow run.
            // A step that genuinely needs the REAL queue (one that suspends the run to await external
            // work) reads the pre-override connection from RealQueueConnection and dispatches onto it.
            // A nested job publishes NOTHING: its own "previous" connection is the parent's forced
            // `sync`, which is exactly the value a suspending step must never see.
            if ($publishedConnection === null) {
                $realQueue->set($previousConnection);
            }

            Queue::setDefaultDriver('sync');

            $runner->run($run->fresh());
        } finally {
            Queue::setDefaultDriver($previousConnection);
            // Restored in the SAME finally as the driver: this is a container singleton that
            // survives across jobs in a long-running worker, so a leak would mis-route a later,
            // unrelated dispatch. The OUTERMOST job restores null, i.e. the old clear().
            $realQueue->set($publishedConnection);
        }
    }

    /**
     * Last-resort release. handle()'s runner covers step throwables, but a job timeout or
     * an exception escaping around handle() would otherwise strand the run in `running`
     * forever (claim() only matches `pending`, so nothing could recover it). $tries = 1, so
     * this fires on first failure. A hard SIGKILL/OOM still bypasses this — the scheduled
     * reaper is the backstop for that.
     *
     * A run parked `waiting` is EXCLUDED as well as a terminal one: `waiting` is NOT terminal, so an
     * exception escaping around handle() AFTER a successful park would otherwise fail a perfectly
     * healthy waiting run — and orphan the external work its step had just started. The wait sweep
     * owns a parked run from that point on.
     */
    public function failed(Throwable $e): void
    {
        $run = WorkflowRun::find($this->runId);

        if ($run === null || $run->state->isTerminal() || $run->state === WorkflowRunState::WAITING) {
            return;
        }

        Log::error("Workflow run {$this->runId} failed: {$e->getMessage()}");

        app(WorkflowRunManager::class)->release($run, WorkflowRunState::FAILED, $e->getMessage());
    }
}
