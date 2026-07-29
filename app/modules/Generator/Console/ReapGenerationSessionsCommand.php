<?php

namespace App\Modules\Generator\Console;

use App\Modules\Generator\Services\GenerationSessionLifecycleService;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lifecycle reaper (scheduled) for generation SESSIONS (R2 sub-stage 2d). One pass applies three windows
 * ({@see GenerationSessionLifecycleService}): recover sessions stranded in `generating` by a dead worker
 * (SIGKILL/OOM bypasses the job's failed() hook), then TRASH non-archived sessions idle past the trash
 * window, then PURGE trashed non-archived sessions past the purge window (force-delete + produced-image
 * blob GC). Archived sessions are exempt from trash + purge.
 *
 * `generation_sessions` lives in the shared database AND in each own-database workspace, so this runs once
 * on the default connection (WorkspaceScope self-disables with no active workspace → every shared row) and
 * once per own-DB tenant — mirroring the bot / AI-edit / draft reapers, including their per-tenant
 * isolation: one broken tenant is logged and skipped so the sweep still finishes for the rest.
 */
class ReapGenerationSessionsCommand extends Command
{
    protected $signature = 'generator:reap-sessions';

    protected $description = 'Fail stale generating sessions, trash idle ones, and purge old trashed ones (with blob GC), across the shared DB and every own-database workspace.';

    public function handle(GenerationSessionLifecycleService $lifecycle, TenantContext $context, TenantManager $tenants): int
    {
        $reaped = 0;
        $trashed = 0;
        $purged = 0;

        // Shared-mode workspaces all live in the default connection: one unscoped pass covers them.
        $context->clear();
        $tenants->forget();
        $reaped += $lifecycle->reapStale();
        $trashed += $lifecycle->trashStale();
        $purged += $lifecycle->purgeTrashed();

        // Each own-database workspace has its own generation_sessions table: activate its context so the
        // tenant-aware model routes to the dedicated connection, then reap there. Only READY tenants have a
        // provisioned database to reap.
        $ownWorkspaces = Workspace::query()
            ->where('db_mode', WorkspaceDbMode::Own)
            ->where('status', WorkspaceStatus::Ready)
            ->get();

        foreach ($ownWorkspaces as $workspace) {
            // One broken tenant (unreachable DB, bad connection config) must not stop the sweep for every
            // workspace after it: log and continue.
            try {
                $context->set($workspace);
                $tenants->configure($workspace);
                $reaped += $lifecycle->reapStale();
                $trashed += $lifecycle->trashStale();
                $purged += $lifecycle->purgeTrashed();
            } catch (Throwable $e) {
                Log::error('Generation session reaper failed for workspace; continuing with remaining workspaces.', [
                    'workspace_id' => $workspace->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $context->clear();
        $tenants->forget();

        $this->info("Reaped {$reaped} stale session(s); trashed {$trashed}; purged {$purged}.");

        return self::SUCCESS;
    }
}
