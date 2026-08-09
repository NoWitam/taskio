<?php

namespace App\Modules\Knowledge\Console;

use App\Modules\Knowledge\Enums\KnowledgeIndexStatus;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stale-claim reaper (scheduled) for knowledge INDEXING, mirroring the bot / workflow / generation
 * reapers and existing for the same reason: a worker killed by SIGKILL or the OOM killer never reaches
 * the job's failed() hook, so its `indexing` claim is never released by anything.
 *
 * That matters more here than it looks. The sweep deliberately SKIPS entries in `indexing` — it has no
 * way to distinguish a live run from a dead one — so an abandoned claim does not merely look untidy:
 * it permanently removes that entry from the catch-up path, and the entry stays unretrievable with no
 * error anywhere. Releasing it back to `pending` after `knowledge.index.stale_after` is what closes
 * that hole.
 *
 * Back to `pending`, not `failed`: nothing is known to be wrong with the entry, and a re-run costs
 * almost nothing thanks to differential indexing — any chunk the dead run had already embedded is
 * reused by digest, so the retry pays only for what was actually left undone.
 *
 * Entries live in the shared database AND in each own-database workspace, so this runs once on the
 * default connection (WorkspaceScope self-disables with no active workspace → every shared row) and
 * once per own-DB tenant, with the same per-tenant isolation as the other reapers.
 */
class ReapStaleKnowledgeIndexCommand extends Command
{
    protected $signature = 'knowledge:reap-stale-index';

    protected $description = 'Release knowledge entries stranded in `indexing` by a dead worker, across the shared DB and every own-database workspace.';

    public function handle(TenantContext $context, TenantManager $tenants): int
    {
        $released = 0;

        // Shared-mode workspaces all live in the default connection: one unscoped pass covers them.
        $context->clear();
        $tenants->forget();
        $released += $this->release();

        $ownWorkspaces = Workspace::query()
            ->where('db_mode', WorkspaceDbMode::Own)
            ->where('status', WorkspaceStatus::Ready)
            ->get();

        foreach ($ownWorkspaces as $workspace) {
            // One broken tenant must not stop the sweep for every workspace after it.
            try {
                $context->set($workspace);
                $tenants->configure($workspace);
                $released += $this->release();
            } catch (Throwable $e) {
                // Class + SQLSTATE, never the message: a QueryException carries its bindings — here,
                // knowledge content — inside its message text.
                Log::error('Knowledge index reaper failed for workspace; continuing with remaining workspaces.', [
                    'workspace_id' => $workspace->id,
                    'exception' => $e::class,
                    'code' => $e->getCode(),
                ]);
            }
        }

        $context->clear();
        $tenants->forget();

        $this->info("Released {$released} stale knowledge indexing claim(s).");

        return self::SUCCESS;
    }

    /**
     * Release claims older than the threshold on the ACTIVE connection.
     *
     * `index_started_at` is the claim clock — never `updated_at`, which is the entry's user-visible
     * "last edited" and would make a long-untouched entry look instantly stale while reprieving one
     * that was edited a moment ago. A null clock on an `indexing` row can only come from a state this
     * module no longer writes, so it is treated as expired rather than left to sit forever.
     */
    private function release(): int
    {
        $threshold = now()->subSeconds(max(1, (int) config('knowledge.index.stale_after')));

        return KnowledgeEntry::query()
            ->where('index_status', KnowledgeIndexStatus::INDEXING->value)
            ->where(fn ($query) => $query
                ->whereNull('index_started_at')
                ->orWhere('index_started_at', '<', $threshold))
            ->update([
                'index_status' => KnowledgeIndexStatus::PENDING->value,
                'index_started_at' => null,
                // Hold `updated_at` where it is. The builder would otherwise stamp it fresh, and a
                // reaper pass would show up in the UI as though someone had edited every entry it
                // touched — the same reason the indexer never lets it move either.
                'updated_at' => DB::raw('updated_at'),
            ]);
    }
}
