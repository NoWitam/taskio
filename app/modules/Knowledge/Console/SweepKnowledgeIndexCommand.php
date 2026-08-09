<?php

namespace App\Modules\Knowledge\Console;

use App\Modules\Knowledge\Enums\KnowledgeIndexStatus;
use App\Modules\Knowledge\Jobs\IndexKnowledgeEntryJob;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Catch-up sweep (scheduled): dispatch an indexing job for every entry whose stored vectors no longer
 * describe its text.
 *
 * The observer's dispatch is the FAST path, not the guarantee. Everything that can leave an entry
 * stale without a save happening lands here instead:
 *
 *   - a workspace that hit its AI budget (`pending_budget` / `partial`) and has since had it raised;
 *   - a provider outage that failed a batch (`failed`);
 *   - a CONFIG change — bumping `chunking.version` or swapping the embedding model moves every
 *     entry's `index_digest` at once, with no write anywhere, and this is what notices;
 *   - a queue that dropped a job, or a worker that was down when the entry was written.
 *
 * Entries in `indexing` are skipped: a live worker holds them, and the stale-index reaper is what
 * releases one whose worker died. Everything else is decided by `index_digest != indexed_digest`, the
 * single authority — never by the status column, which can go stale.
 *
 * TENANCY. Entries live in the shared database (shared-mode workspaces) AND in each own-database
 * workspace, so the sweep walks WORKSPACES rather than connections. That costs one scoped query per
 * workspace instead of one unscoped query for all shared ones, and buys the thing that matters: the
 * budget gate is per workspace, so it can only be evaluated with that workspace active. Mirrors the
 * per-tenant isolation of the other sweeps — one broken tenant is logged and skipped so the rest still
 * finish.
 *
 * BATCHED per workspace (`knowledge.index.sweep_batch`). A workspace that just imported a thousand
 * entries drains over several passes instead of filling the queue — and burning a month's budget — in
 * one minute.
 */
class SweepKnowledgeIndexCommand extends Command
{
    protected $signature = 'knowledge:sweep-index';

    protected $description = 'Dispatch indexing jobs for knowledge entries whose embeddings are out of date, across the shared DB and every own-database workspace.';

    public function handle(TenantContext $context, TenantManager $tenants, MeteredAiCall $meter): int
    {
        if (!config('knowledge.index.enabled')) {
            $this->info('Knowledge indexing is disabled (knowledge.index.enabled); nothing dispatched.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $skipped = 0;

        $workspaces = Workspace::query()
            // An own-database workspace only has a database to read once it is provisioned; a
            // shared-mode one is Ready from the moment it exists.
            ->where(fn ($query) => $query
                ->where('db_mode', WorkspaceDbMode::Shared)
                ->orWhere('status', WorkspaceStatus::Ready))
            ->get();

        foreach ($workspaces as $workspace) {
            try {
                $context->set($workspace);

                if ($workspace->db_mode === WorkspaceDbMode::Own) {
                    $tenants->configure($workspace);
                } else {
                    $tenants->forget();
                }

                try {
                    // Per-workspace GATE BEFORE DISPATCH: queuing jobs for a workspace that is over
                    // its cap only produces a queue full of refusals, each of which still costs a
                    // ledger read and a status write.
                    $meter->assertWithinBudget('ai_embedding');
                } catch (AiBudgetExceededException) {
                    $skipped++;

                    continue;
                }

                $dispatched += $this->sweep($workspace);
            } catch (Throwable $e) {
                // Class + SQLSTATE, never the message: a QueryException carries its bindings — here,
                // knowledge content — inside its message text.
                Log::error('Knowledge index sweep failed for workspace; continuing with remaining workspaces.', [
                    'workspace_id' => $workspace->id,
                    'exception' => $e::class,
                    'code' => $e->getCode(),
                ]);
            }
        }

        $context->clear();
        $tenants->forget();

        $this->info("Dispatched {$dispatched} knowledge indexing job(s); skipped {$skipped} over-budget workspace(s).");

        return self::SUCCESS;
    }

    /** Dispatch up to one batch of stale entries for the ACTIVE workspace. */
    private function sweep(Workspace $workspace): int
    {
        $entries = KnowledgeEntry::query()
            ->needsIndexing()
            // A live worker holds these; the reaper releases the ones whose worker died.
            ->where('index_status', '!=', KnowledgeIndexStatus::INDEXING->value)
            ->orderBy('updated_at')
            ->limit(max(1, (int) config('knowledge.index.sweep_batch')))
            ->pluck('id');

        foreach ($entries as $entryId) {
            IndexKnowledgeEntryJob::dispatch((string) $entryId, (string) $workspace->id);
        }

        return $entries->count();
    }
}
