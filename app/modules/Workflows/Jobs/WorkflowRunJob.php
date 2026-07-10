<?php

namespace App\Modules\Workflows\Jobs;

use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workflows\Services\WorkflowStepRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One execution of a workflow run. Tenancy is carried across the queue boundary by
 * QueueTenancy (mirrors BotTaskExecutionJob / ProcessAiApprovalJob), so the run's steps
 * create tenant-aware rows on the correct connection.
 *
 * The run is created `pending` by WorkflowRunManager::start(); this job CLAIMS it (atomic,
 * so a lost claim — already running or terminal — is a silent no-op) and hands off to the
 * step runner, which releases the run to its terminal state. $tries = 1: a failure fires
 * failed() as the last-resort release.
 *
 * Only the run ID is serialized (not the model) so the worker re-reads it under the correct
 * tenant connection.
 */
class WorkflowRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

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

        $runner->run($run->fresh());
    }

    /**
     * Last-resort release. handle()'s runner covers step throwables, but a job timeout or
     * an exception escaping around handle() would otherwise strand the run in `running`
     * forever (claim() only matches `pending`, so nothing could recover it). $tries = 1, so
     * this fires on first failure. A hard SIGKILL/OOM still bypasses this — the scheduled
     * reaper is the backstop for that.
     */
    public function failed(Throwable $e): void
    {
        $run = WorkflowRun::find($this->runId);

        if ($run === null || $run->state->isTerminal()) {
            return;
        }

        Log::error("Workflow run {$this->runId} failed: {$e->getMessage()}");

        app(WorkflowRunManager::class)->release($run, WorkflowRunState::FAILED, $e->getMessage());
    }
}
