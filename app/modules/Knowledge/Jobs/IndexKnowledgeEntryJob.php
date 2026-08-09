<?php

namespace App\Modules\Knowledge\Jobs;

use App\Modules\Knowledge\Services\KnowledgeIndexService;
use App\Modules\Knowledge\Services\KnowledgeMentionLinker;
use App\Modules\Knowledge\Services\KnowledgeSimilarityLinker;
use App\Modules\Workspaces\Enums\WorkspaceDbMode;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-index ONE knowledge entry off the request (B2a).
 *
 * Dispatched after the entry's write COMMITS — from the entry observer for an authored change, and
 * from `knowledge:sweep-index` for anything the observer's dispatch never reached (a queue outage, a
 * budget refusal, a chunker version bump). Saving an entry never waits on this and never fails because
 * of it: indexing is bookkeeping about the text, not part of storing it.
 *
 * KILL SWITCH FIRST. `knowledge.index.enabled` is read as the very first statement, before tenancy is
 * restored and before the service is touched, so flipping it off provably stops every provider call
 * this module can make — including the ones already sitting in the queue. An operator's one switch has
 * to mean it.
 *
 * TENANCY is re-established EXPLICITLY from the workspace id carried on the payload — the same posture
 * every queued job in this codebase takes, and deliberately NOT a reliance on QueueTenancy's ambient
 * re-application. It is a security property, not a convenience: without it a worker whose context
 * leaked from a previous job would read — and re-embed — another workspace's entry. failed()
 * re-establishes it too, since that hook can run after the context has already been popped.
 *
 * (The consumer modules that will read this index are deliberately unnamed here: Knowledge is a lower
 * shared layer and may not reference them even in a comment — see KnowledgeModuleBoundaryTest.)
 *
 * `tries = 1`, because none of this job's failure modes improve on a retry. A provider error, an
 * over-budget workspace and an oversized entry are all recorded ON THE ENTRY as distinct states
 * ({@see \App\Modules\Knowledge\Enums\KnowledgeIndexStatus}) and picked up by the sweep once the
 * underlying condition changes; retrying instead would re-attempt a spend that was just refused. Only
 * a genuine infrastructure fault throws, and that routes to failed().
 */
class IndexKnowledgeEntryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Must stay comfortably BELOW `knowledge.index.stale_after` (900s), or the reaper would release a
     * claim while its worker is still running and a second job could start on the same entry.
     */
    public int $timeout = 300;

    public function __construct(
        public string $entryId,
        public string $workspaceId,
    ) {}

    /**
     * One run at a time per entry. At-least-once delivery must never double-run a BILLED embedding
     * batch, and two concurrent runs would also race on the `(entry, ordinal)` unique index while
     * re-numbering chunks. A duplicate is released rather than run; the lock self-expires well past
     * the timeout so a killed worker cannot wedge the entry.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->entryId))->releaseAfter(30)->expireAfter(600)];
    }

    /**
     * Bring the entry's index up to date, and then the edges that index IMPLIES.
     *
     * The similarity pass runs here rather than inside the indexing service because it is a different
     * kind of work on the same trigger: the service owns the entry's vectors and its status lifecycle,
     * while the linker owns rows in the graph that the vectors merely justify. Keeping them separate
     * is what lets the linker be re-run, tested and reasoned about on its own — and what keeps a
     * failure to draw an edge from ever being able to invalidate an index that was paid for.
     *
     * It is GATED on the run having rebuilt something. A sweep over an already-current base returns
     * false for every entry and the linker is never invoked, which keeps the "a scheduled sweep over a
     * current base costs nothing" promise true of database work as well as of provider calls.
     *
     * The linker is a parameter with a default so the job stays callable with a single argument (the
     * container injects both in production and on the queue); it costs nothing and it is not part of
     * the payload, which a constructor dependency on a serialized job could not say.
     */
    public function handle(
        KnowledgeIndexService $service,
        ?KnowledgeSimilarityLinker $linker = null,
        ?KnowledgeMentionLinker $mentions = null,
    ): void {
        if (!config('knowledge.index.enabled')) {
            return; // the module's kill switch — no tenancy, no chunking, no provider call
        }

        $this->activateTenant();

        if ($service->index($this->entryId)) {
            ($linker ?? app(KnowledgeSimilarityLinker::class))->link($this->entryId);
            // The MENTION pass (B10) rides the same trigger and spends nothing: it reads words, not
            // vectors. Run after the similarity pass because it defers to authored edges, and running
            // both from one place keeps "the graph is rebuilt when the text is" a single rule.
            ($mentions ?? app(KnowledgeMentionLinker::class))->link($this->entryId);
        }
    }

    /**
     * An infrastructure fault (or an exhausted attempt): record it on the entry so the sweep can see a
     * terminal state instead of a claim nobody holds. The service guards the transition on `indexing`,
     * so a redelivery that never ran cannot overwrite a settled outcome.
     */
    public function failed(Throwable $e): void
    {
        $this->activateTenant();

        // The CLASS and the SQLSTATE, never the message: a QueryException interpolates its BINDINGS
        // into its message, and on this path the bindings are chunk CONTENT — so logging it would copy
        // the workspace's knowledge into the application log every time indexing failed.
        Log::error('Knowledge index job FAILED', [
            'entry_id' => $this->entryId,
            'workspace_id' => $this->workspaceId,
            'exception' => $e::class,
            'code' => $e->getCode(),
        ]);

        app(KnowledgeIndexService::class)->fail($this->entryId);
    }

    /**
     * Re-apply the dispatching workspace so the tenant-scoped entry resolves, and — for an
     * own-database workspace — so every query routes to its dedicated connection. A vanished workspace
     * leaves the context cleared and the downstream find() simply no-ops.
     */
    private function activateTenant(): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        app(TenantContext::class)->set($workspace);

        if ($workspace->db_mode === WorkspaceDbMode::Own) {
            app(TenantManager::class)->configure($workspace);
        }
    }
}
