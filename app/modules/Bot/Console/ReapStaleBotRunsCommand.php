<?php

namespace App\Modules\Bot\Console;

use App\Modules\Bot\Services\BotTaskRunManager;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stale-claim reaper (scheduled). Releases bot runs stranded in `running` — a worker
 * killed mid-run (SIGKILL/OOM) never fires the job's failed() hook, and the atomic claim
 * only matches idle/waiting, so nothing else can recover them.
 *
 * `tasks` lives in the shared database (shared-mode workspaces) AND in each own-database
 * workspace, so the sweep runs once on the default connection and once per own-DB tenant
 * — reusing the same tenancy primitives the queue boundary uses (TenantContext +
 * TenantManager connection).
 */
class ReapStaleBotRunsCommand extends Command
{
    protected $signature = 'bots:reap-stale-runs';

    protected $description = 'Release bot runs stuck in "running" past the timeout, across the shared DB and every own-database workspace.';

    public function handle(BotTaskRunManager $runManager, TenantContext $context, TenantManager $tenants): int
    {
        $total = 0;

        // Shared-mode workspaces all live in the default connection: one unscoped pass
        // covers every shared task at once.
        $context->clear();
        $tenants->forget();
        $total += $runManager->reapStaleRuns();

        // Each own-database workspace has its own tasks table: activate its context so the
        // tenant-aware models route to the dedicated connection, then reap there.
        $ownWorkspaces = Workspace::query()->where('db_mode', WorkspaceDbMode::Own)->get();

        foreach ($ownWorkspaces as $workspace) {
            // One broken tenant (unreachable DB, bad connection config) must not stop the
            // sweep for every workspace after it: log and continue.
            try {
                $context->set($workspace);
                $tenants->configure($workspace);
                $total += $runManager->reapStaleRuns();
            } catch (Throwable $e) {
                Log::error('Stale bot run reaper failed for workspace; continuing with remaining workspaces.', [
                    'workspace_id' => $workspace->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $context->clear();
        $tenants->forget();

        $this->info("Reaped {$total} stale bot run(s).");

        return self::SUCCESS;
    }
}
