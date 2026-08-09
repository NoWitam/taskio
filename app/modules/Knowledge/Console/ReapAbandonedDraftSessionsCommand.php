<?php

namespace App\Modules\Knowledge\Console;

use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Services\KnowledgeDraftSessionService;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Destroys drafting sessions nobody came back to.
 *
 * A SIBLING of `knowledge:reap-stale-index` rather than a branch inside it, and the distinction is
 * real: that one RELEASES a claim (a worker died; the entry is fine and wants re-indexing), this one
 * DELETES USER DATA (a session was abandoned; its drafts and its raw source text should stop existing).
 * Folding two operations with opposite blast radii into one command would make the safe one carry the
 * dangerous one's review burden forever.
 *
 * Why it has to exist at all: drafts are invisible by design, so an abandoned session is invisible
 * clutter — it never shows up in a list, never gets cleaned up by a human noticing it, and it holds
 * whatever material the user pasted. "Nobody will ever see this" is precisely the reason to have a
 * scheduled purge rather than to trust attrition.
 *
 * The purge goes through {@see KnowledgeDraftSessionService::abandon()}, the SAME path the user's own
 * "discard" button takes, so there is one definition of what abandoning means.
 */
class ReapAbandonedDraftSessionsCommand extends Command
{
    protected $signature = 'knowledge:reap-draft-sessions';

    protected $description = 'Purge AI drafting sessions (and their drafts) left untouched past knowledge.drafting.abandon_after_days';

    public function handle(TenantContext $context, TenantManager $tenants, KnowledgeDraftSessionService $service): int
    {
        $purged = $this->purge($service);

        // Own-database workspaces each keep their own tables, so the sweep visits them one at a time —
        // the posture every scheduled command in this module takes.
        $ownWorkspaces = Workspace::query()
            ->where('db_mode', WorkspaceDbMode::Own)
            ->where('status', WorkspaceStatus::Ready)
            ->get();

        foreach ($ownWorkspaces as $workspace) {
            try {
                $context->set($workspace);
                $tenants->configure($workspace);
                $purged += $this->purge($service);
            } catch (Throwable $e) {
                // One broken tenant must not stop the sweep. Class + code only: a query message carries
                // its bindings, and here those are the user's source material.
                Log::error('Knowledge draft-session reaper failed for workspace; continuing.', [
                    'workspace_id' => $workspace->id,
                    'exception' => $e::class,
                    'code' => $e->getCode(),
                ]);
            }
        }

        $context->clear();
        $tenants->forget();

        $this->info("Purged {$purged} abandoned knowledge drafting session(s).");

        return self::SUCCESS;
    }

    /**
     * Sessions untouched for longer than the window, on the ACTIVE connection.
     *
     * `updated_at` is the right clock: every action a user takes on a session (refining, accepting a
     * draft, and the run settling) writes the row, so it means "last activity" rather than "created".
     * A session someone is slowly working through is never reaped.
     */
    private function purge(KnowledgeDraftSessionService $service): int
    {
        $threshold = now()->subDays(max(1, (int) config('knowledge.drafting.abandon_after_days')));

        $sessions = KnowledgeDraftSession::query()
            ->where('updated_at', '<', $threshold)
            ->get();

        foreach ($sessions as $session) {
            $service->abandon($session);
        }

        return $sessions->count();
    }
}
