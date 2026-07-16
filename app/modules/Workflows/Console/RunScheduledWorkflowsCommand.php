<?php

namespace App\Modules\Workflows\Console;

use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Services\WorkflowDispatchService;
use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workflows\Services\WorkflowScheduleService;
use App\Modules\Workflows\Services\WorkflowTriggerPayloadFactory;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Schedule forward-sweep (scheduled every minute). Fires `schedule`-type workflows whose
 * next_due_at has arrived. This is the ONLY path that starts a schedule run — the event
 * dispatcher hard-refuses SCHEDULE by design.
 *
 * `workflows` lives in the shared database (shared-mode workspaces) AND in each own-database
 * workspace, so the sweep runs once on the default connection and once per own-DB tenant,
 * reusing the same tenancy primitives the queue boundary uses (mirrors the reaper commands).
 *
 * Per candidate the sweep does a RACE-SAFE compare-and-swap claim (WorkflowScheduleService::
 * claimDue): it advances next_due_at + stamps last_scheduled_run_at in one conditional UPDATE
 * that matches only if next_due_at is still the value we read. Affected=1 means we won the
 * slot; 0 means a rival sweep already took it (skip). That CAS is the second guard beyond the
 * scheduler's withoutOverlapping, so a due workflow fires at most once even under overlap.
 *
 * SLOT-CONSUMED doctrine: the slot is advanced the MOMENT we win the CAS, BEFORE the cap
 * check. A workflow that is over its run budget therefore consumes and skips its due slot
 * (Log::info, no run) instead of backlog-firing every missed slot when the cap later lifts —
 * matching the event dispatcher, which also skips a capped trigger silently.
 */
class RunScheduledWorkflowsCommand extends Command
{
    protected $signature = 'workflows:run-scheduled';

    protected $description = 'Fire schedule-triggered workflows whose next_due_at has arrived, across the shared DB and every own-database workspace.';

    public function handle(
        WorkflowScheduleService $schedule,
        WorkflowDispatchService $dispatcher,
        WorkflowRunManager $runManager,
        WorkflowTriggerPayloadFactory $payloads,
        TenantContext $context,
        TenantManager $tenants,
    ): int {
        $counts = ['armed' => 0, 'fired' => 0, 'skipped_cap' => 0, 'lost_claim' => 0];

        // Shared-mode workspaces all live in the default connection: one unscoped pass covers
        // every shared schedule workflow at once.
        $context->clear();
        $tenants->forget();
        $this->sweep($schedule, $dispatcher, $runManager, $payloads, $counts);

        // Each own-database workspace has its own workflows table: activate its context so the
        // tenant-aware models route to the dedicated connection, then sweep there.
        $ownWorkspaces = Workspace::query()->where('db_mode', WorkspaceDbMode::Own)->get();

        foreach ($ownWorkspaces as $workspace) {
            // One broken tenant (unreachable DB, bad connection config) must not stop the
            // sweep for every workspace after it: log and continue.
            try {
                $context->set($workspace);
                $tenants->configure($workspace);
                $this->sweep($schedule, $dispatcher, $runManager, $payloads, $counts);
            } catch (Throwable $e) {
                Log::error('Scheduled sweep failed for workspace; continuing with remaining workspaces.', [
                    'workspace_id' => $workspace->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $context->clear();
        $tenants->forget();

        $this->info(sprintf(
            'Scheduled sweep: %d fired, %d armed, %d skipped (cap), %d lost claim.',
            $counts['fired'], $counts['armed'], $counts['skipped_cap'], $counts['lost_claim'],
        ));

        return self::SUCCESS;
    }

    /**
     * Sweep the CURRENTLY ACTIVE connection. Arms self-healing NULL-due workflows, then
     * claims + fires every due one. Counts accumulate across the shared + per-tenant passes.
     *
     * @param  array<string, int>  $counts
     */
    private function sweep(
        WorkflowScheduleService $schedule,
        WorkflowDispatchService $dispatcher,
        WorkflowRunManager $runManager,
        WorkflowTriggerPayloadFactory $payloads,
        array &$counts,
    ): void {
        $this->armOrphans($schedule, $counts);

        // Active schedule workflows already past due. next_due_at is stored UTC; now() is UTC.
        $due = Workflow::query()
            ->where('status', WorkflowStatus::ACTIVE->value)
            ->where('trigger_type', WorkflowTriggerType::SCHEDULE->value)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<=', now())
            ->orderBy('next_due_at')
            ->get();

        foreach ($due as $workflow) {
            // One corrupt workflow (e.g. an uncompilable stored schedule) must not block
            // every other due workflow on this connection: log and continue.
            try {
                $this->fireIfClaimed($workflow, $schedule, $dispatcher, $runManager, $payloads, $counts);
            } catch (Throwable $e) {
                Log::error('Scheduled sweep failed for workflow; continuing with remaining due workflows.', [
                    'workflow_id' => $workflow->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Self-healing: an ACTIVE schedule workflow with a NULL next_due_at (legacy/edge — e.g.
     * activated before this batch landed) is ARMED so a later pass can fire it. It does NOT
     * fire this pass — arming only sets the first due time.
     *
     * @param  array<string, int>  $counts
     */
    private function armOrphans(WorkflowScheduleService $schedule, array &$counts): void
    {
        $orphans = Workflow::query()
            ->where('status', WorkflowStatus::ACTIVE->value)
            ->where('trigger_type', WorkflowTriggerType::SCHEDULE->value)
            ->whereNull('next_due_at')
            ->get();

        foreach ($orphans as $workflow) {
            try {
                $schedule->arm($workflow);
                $workflow->save();
                $counts['armed']++;
            } catch (Throwable $e) {
                Log::error('Scheduled sweep failed to arm workflow; continuing with remaining workflows.', [
                    'workflow_id' => $workflow->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Claim one due workflow's slot (CAS) and, if won, enforce caps then start the run. A lost
     * claim (a rival sweep already advanced the slot) or a capped workflow is skipped — but a
     * capped skip still consumed its slot via the CAS, so it will not backlog-fire.
     *
     * @param  array<string, int>  $counts
     */
    private function fireIfClaimed(
        Workflow $workflow,
        WorkflowScheduleService $schedule,
        WorkflowDispatchService $dispatcher,
        WorkflowRunManager $runManager,
        WorkflowTriggerPayloadFactory $payloads,
        array &$counts,
    ): void {
        $dueAt = $workflow->next_due_at;

        if ($schedule->claimDue($workflow) === null) {
            // A concurrent sweep already advanced this slot — do nothing (fire once).
            $counts['lost_claim']++;

            return;
        }

        // Cap enforcement mirrors the event dispatcher: reached => skip SILENTLY. The slot is
        // already consumed (next_due_at advanced by the CAS above), so a capped workflow does
        // not backlog-fire when the cap later lifts.
        if ($dispatcher->capReached($workflow)) {
            Log::info('Scheduled workflow run skipped: run budget reached.', [
                'workflow_id' => $workflow->id,
            ]);
            $counts['skipped_cap']++;

            return;
        }

        // A schedule run is always top-level (depth 0, no origin) and engine-authored (no
        // creator). Its payload uses the SAME factory manual schedule runs use, so
        // {{trigger.scheduled_at}} has identical shape. Anchor scheduled_at to the slot fired.
        $runManager->start(
            $workflow,
            WorkflowRunOrigin::SCHEDULE,
            $payloads->fromSchedule($dueAt),
            depth: 0,
            originRunId: null,
            creatorId: null,
        );

        $counts['fired']++;
    }
}
