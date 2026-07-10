<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Jobs\WorkflowRunJob;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use Illuminate\Support\Facades\DB;

/**
 * The run-state machine for workflow runs. Follows the SHAPE of BotTaskRunManager (per the
 * approved Etap 5 plan) rather than the formal Manager layer: a lifecycle owner built
 * around an ATOMIC conditional-UPDATE claim.
 *
 *   pending ──claim──▶ running ──release(completed|failed)──▶ terminal
 *
 * A run is CLAIMED with a single conditional UPDATE:
 *
 *   UPDATE workflow_runs
 *   SET state='running', started_at=now()
 *   WHERE id=? AND state='pending'
 *
 * Postgres locks the row for the UPDATE, so exactly one concurrent worker can flip
 * pending -> running and see affected=1. That is the concurrency guard: a run executes at
 * most once even under a non-sync queue (no check-then-act race), and started_at is stamped
 * in the SAME statement so the reaper can tell a genuinely stuck run from a slow one.
 */
class WorkflowRunManager
{
    /**
     * Create a `pending` run and dispatch its job after the surrounding transaction
     * commits (so a sync worker sees the committed row, and an async worker never races a
     * not-yet-committed insert). Returns the created run.
     *
     * $depth / $originRunId thread the re-trigger chain; $creatorId is null for
     * an engine-started run (event/schedule) and a uuid for a manual one.
     *
     * @param  array<string, mixed>  $triggerPayload
     */
    public function start(
        Workflow $workflow,
        WorkflowRunOrigin $origin,
        array $triggerPayload,
        int $depth = 0,
        ?string $originRunId = null,
        ?string $creatorId = null,
    ): WorkflowRun {
        $run = WorkflowRun::create([
            'workflow_id' => $workflow->id,
            'state' => WorkflowRunState::PENDING,
            'origin' => $origin,
            // WorkflowRun.trigger_type stays a plain string column — unwrap the model's enum cast.
            'trigger_type' => $workflow->trigger_type->value,
            'trigger_payload' => $triggerPayload,
            'context' => null,
            'depth' => $depth,
            'origin_run_id' => $originRunId,
            'creator_id' => $creatorId,
        ]);

        DB::afterCommit(fn () => WorkflowRunJob::dispatch($run->id));

        return $run;
    }

    /**
     * Atomic claim: flip a `pending` run to `running` and stamp started_at in the same
     * statement. Returns true iff THIS call won the claim (a lost claim is a no-op — the
     * run is already running or terminal).
     */
    public function claim(WorkflowRun $run): bool
    {
        $affected = WorkflowRun::withoutGlobalScopes()
            ->whereKey($run->id)
            ->where('state', WorkflowRunState::PENDING->value)
            ->update([
                'state' => WorkflowRunState::RUNNING->value,
                'started_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * Release a claimed run to a terminal state, stamping finished_at (and an error on
     * failure). Only the terminal state + finish metadata change — the accumulated context
     * and step rows are left intact for the timeline.
     */
    public function release(WorkflowRun $run, WorkflowRunState $terminal, ?string $error = null): void
    {
        WorkflowRun::withoutGlobalScopes()
            ->whereKey($run->id)
            ->update([
                'state' => $terminal->value,
                'finished_at' => now(),
                'error' => $error,
            ]);
    }

    /**
     * Stale-claim reaper: release runs stuck in `running` past config('workflows.run_timeout')
     * to `failed` with a timeout error. A worker killed mid-run (SIGKILL/OOM) never fires the
     * job's failed() hook, and claim() only matches `pending`, so such a run would strand
     * forever. Also reaps NULL-started orphans (rows claimed before this column existed).
     *
     * Runs on the CURRENTLY ACTIVE connection; the `workflows:reap-stale-runs` command
     * sweeps the shared DB and every own-database tenant. Returns the number reaped.
     */
    public function reapStaleRuns(): int
    {
        $timeout = max(60, (int) config('workflows.run_timeout', 900));
        $cutoff = now()->subSeconds($timeout);

        $stale = WorkflowRun::withoutGlobalScopes()
            ->where('state', WorkflowRunState::RUNNING->value)
            ->where(function ($query) use ($cutoff) {
                // NULL only for rows claimed before started_at existed (pre-deploy
                // orphans): post-deploy every claim stamps started_at atomically.
                $query->whereNull('started_at')
                    ->orWhere('started_at', '<', $cutoff);
            })
            ->get();

        foreach ($stale as $run) {
            $this->release($run, WorkflowRunState::FAILED, 'Run reaped: stuck in running past the timeout.');
        }

        return $stale->count();
    }

    /**
     * How many runs this workflow has started since the start of the current month. The
     * per-workflow monthly cost proxy the dispatcher checks before starting a run
     * (see config/workflows.php).
     */
    public function runsThisMonth(Workflow $workflow): int
    {
        return WorkflowRun::query()
            ->where('workflow_id', $workflow->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * How many runs have started across the WHOLE active workspace since the start of the
     * month — the workspace-wide hard-cap proxy the dispatcher checks last. The query
     * is workspace-scoped automatically (WorkflowRun is TenantAware), so in shared-db mode it
     * counts only this workspace's rows and in own-db mode it runs on the tenant connection.
     * One cheap COUNT with a covering (workspace_id, created_at) filter — cheaper than summing
     * per workflow, and correct as a runaway-spend ceiling.
     */
    public function runsThisMonthAcrossWorkspace(): int
    {
        return WorkflowRun::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }
}
