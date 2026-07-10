<?php

namespace App\Modules\Workflows\Console;

use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Stale-claim reaper (scheduled). Releases workflow runs stranded in `running` — a worker
 * killed mid-run (SIGKILL/OOM) never fires the job's failed() hook, and the atomic claim
 * only matches `pending`, so nothing else can recover them.
 *
 * `workflow_runs` lives in the shared database (shared-mode workspaces) AND in each
 * own-database workspace, so the sweep runs once on the default connection and once per
 * own-DB tenant — reusing the same tenancy primitives the queue boundary uses (mirrors
 * ReapStaleBotRunsCommand).
 */
class ReapStaleWorkflowRunsCommand extends Command
{
    protected $signature = 'workflows:reap-stale-runs';

    protected $description = 'Release workflow runs stuck in "running" past the timeout, across the shared DB and every own-database workspace.';

    public function handle(WorkflowRunManager $runManager, TenantContext $context, TenantManager $tenants): int
    {
        $total = 0;

        // Shared-mode workspaces all live in the default connection: one unscoped pass
        // covers every shared run at once.
        $context->clear();
        $tenants->forget();
        $total += $runManager->reapStaleRuns();

        // Each own-database workspace has its own workflow_runs table: activate its context
        // so the tenant-aware models route to the dedicated connection, then reap there.
        $ownWorkspaces = Workspace::query()->where('db_mode', WorkspaceDbMode::Own)->get();

        foreach ($ownWorkspaces as $workspace) {
            $context->set($workspace);
            $tenants->configure($workspace);
            $total += $runManager->reapStaleRuns();
        }

        $context->clear();
        $tenants->forget();

        $this->info("Reaped {$total} stale workflow run(s).");

        return self::SUCCESS;
    }
}
