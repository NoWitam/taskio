<?php

namespace App\Modules\Disk\Console;

use App\Modules\Disk\Services\DraftService;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reaper (scheduled) for per-user Disk file-edit drafts. Prunes drafts past their retention window
 * (24h by default) — row AND storage directory — so an abandoned autosave (a tab closed mid-edit)
 * never lingers with its multi-MB base blobs.
 *
 * `disk_file_drafts` lives in the shared database AND in each own-database workspace, so this runs
 * once on the default connection and once per own-DB tenant — mirroring the AI-edit / temp-file
 * reapers, including their per-tenant isolation: one broken tenant is logged and skipped.
 */
class ReapStaleDraftsCommand extends Command
{
    protected $signature = 'disk:reap-stale-drafts';

    protected $description = 'Prune per-user Disk file-edit drafts past their retention window, across the shared DB and every own-database workspace.';

    public function handle(DraftService $service, TenantContext $context, TenantManager $tenants): int
    {
        $pruned = 0;

        // Shared-mode workspaces all live in the default connection: one unscoped pass covers them.
        $context->clear();
        $tenants->forget();
        $pruned += $service->reapStale();

        $ownWorkspaces = Workspace::query()
            ->where('db_mode', WorkspaceDbMode::Own)
            ->where('status', WorkspaceStatus::Ready)
            ->get();

        foreach ($ownWorkspaces as $workspace) {
            // One broken tenant (unreachable DB, bad connection config) must not stop the sweep for
            // every workspace after it: log and continue.
            try {
                $context->set($workspace);
                $tenants->configure($workspace);
                $pruned += $service->reapStale();
            } catch (Throwable $e) {
                Log::error('Stale draft reaper failed for workspace; continuing with remaining workspaces.', [
                    'workspace_id' => $workspace->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $context->clear();
        $tenants->forget();

        $this->info("Pruned {$pruned} stale draft(s).");

        return self::SUCCESS;
    }
}
