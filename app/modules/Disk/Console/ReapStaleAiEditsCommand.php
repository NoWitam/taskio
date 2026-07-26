<?php

namespace App\Modules\Disk\Console;

use App\Modules\Disk\Services\ImageAiService;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reaper (scheduled) for async Disk AI image edits. Marks edits stranded in queued/processing past
 * the timeout as failed — a worker killed mid-run (SIGKILL/OOM) never fires the job's failed()
 * hook, and nothing else would recover them — and prunes terminal rows past the retention window
 * so the results table (multi-MB base64) does not grow without bound.
 *
 * `disk_ai_edits` lives in the shared database AND in each own-database workspace, so this runs
 * once on the default connection and once per own-DB tenant — mirroring the bot/temp-file
 * commands, including their per-tenant isolation: one broken tenant is logged and skipped.
 */
class ReapStaleAiEditsCommand extends Command
{
    protected $signature = 'disk:reap-stale-ai-edits';

    protected $description = 'Fail stuck async AI image edits and prune old terminal ones, across the shared DB and every own-database workspace.';

    public function handle(ImageAiService $service, TenantContext $context, TenantManager $tenants): int
    {
        $reaped = 0;
        $pruned = 0;

        // Shared-mode workspaces all live in the default connection: one unscoped pass covers them.
        $context->clear();
        $tenants->forget();
        $reaped += $service->reapStale();
        $pruned += $service->pruneTerminal();

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
                $reaped += $service->reapStale();
                $pruned += $service->pruneTerminal();
            } catch (Throwable $e) {
                Log::error('Stale AI-edit reaper failed for workspace; continuing with remaining workspaces.', [
                    'workspace_id' => $workspace->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $context->clear();
        $tenants->forget();

        $this->info("Reaped {$reaped} stale AI edit(s); pruned {$pruned} old edit(s).");

        return self::SUCCESS;
    }
}
