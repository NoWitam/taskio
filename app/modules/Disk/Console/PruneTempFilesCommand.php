<?php

namespace App\Modules\Disk\Console;

use App\Modules\Disk\Services\FileService;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prunes uploads that were never attached or placed.
 *
 * The two-step upload (POST /disk/temp, then reference the id when saving the parent) leaves a
 * row + blob behind whenever the second step never comes — a closed dropzone, an abandoned
 * form. Nothing collected those, so they accumulated forever.
 *
 * `files` lives in the shared database AND in each own-database workspace, so this runs once
 * on the default connection and once per own-DB tenant — mirroring the reaper commands,
 * including their per-tenant isolation: one broken tenant is logged and skipped, never fatal.
 */
class PruneTempFilesCommand extends Command
{
    protected $signature = 'disk:prune-temp-files';

    protected $description = 'Delete never-attached temp uploads (rows + blobs) older than the retention window, across the shared DB and every own-database workspace.';

    public function handle(FileService $files, TenantContext $context, TenantManager $tenants): int
    {
        $total = 0;

        // Shared-mode workspaces all live in the default connection: one unscoped pass covers
        // every shared temp upload at once.
        $context->clear();
        $tenants->forget();
        $total += $files->pruneTempFiles();

        $ownWorkspaces = Workspace::query()
            ->where('db_mode', WorkspaceDbMode::Own)
            ->where('status', WorkspaceStatus::Ready)
            ->get();

        foreach ($ownWorkspaces as $workspace) {
            try {
                $context->set($workspace);
                $tenants->configure($workspace);
                $total += $files->pruneTempFiles();
            } catch (Throwable $e) {
                Log::error('Temp-file prune failed for workspace; continuing with remaining workspaces.', [
                    'workspace_id' => $workspace->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $context->clear();
        $tenants->forget();

        $this->info("Pruned {$total} abandoned temp upload(s).");

        return self::SUCCESS;
    }
}
