<?php

namespace App\Modules\Knowledge\Observers;

use App\Modules\Knowledge\Jobs\IndexKnowledgeEntryJob;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\Scopes\WithoutDraftsScope;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Queues a re-index whenever an entry's authored text moves.
 *
 * WHY AN OBSERVER rather than a call in the entry service: the service writes an entry from four
 * places (create, update, restore-from-revision, and the trash restore), each inside its own
 * transaction, and every one of them must trigger indexing. Hanging the trigger off the MODEL means a
 * fifth path — a console fix-up, a future import — cannot forget it. The condition is read off the
 * digests, which the service already maintains as the single definition of "the text changed", so
 * there is no second notion of staleness that could drift from the first.
 *
 * AFTER COMMIT, always. The worker reads the entry from the database, so dispatching inside the
 * writing transaction is a race it loses more often the faster the queue is: under a sync queue it
 * loses it every time, and under Redis it loses it exactly on the machine that is fastest. DB::afterCommit
 * is the codebase's idiom for this (see WorkflowRunManager, FormSubmissionObserver) and fires
 * immediately when no transaction is open.
 *
 * The WORKSPACE is captured HERE, from the ambient tenant context, and travelled on the payload. It
 * cannot be read off the model: in own-database mode an entry has no workspace_id column at all. This
 * is the same reason the job re-establishes tenancy explicitly rather than trusting the worker's
 * ambient state.
 */
class KnowledgeEntryObserver
{
    /**
     * `created` / `updated` rather than `saved`, and that distinction is load-bearing: ONE logical
     * write hits `saved` more than once, because the entry service appends a revision and then saves
     * the entry again to point `current_revision_id` at it. Keyed off `saved` this would dispatch
     * twice for every create and every edit — two queue jobs, two locks, and a duplicated
     * WithoutOverlapping release cycle for work only one of them can do. `created` fires exactly once
     * per insert, and `updated` is filtered to the save that actually moved the digest.
     */
    public function created(KnowledgeEntry $entry): void
    {
        $this->queueIndexing($entry);
    }

    public function updated(KnowledgeEntry $entry): void
    {
        // An ACCEPTED draft is new to the world even though its text did not move: it was never
        // indexed while it was a draft (deliberately — see queueIndexing), so the digest it carries
        // has never been acted on. Keying only off `index_digest` would leave every accepted entry
        // permanently unsearchable, which is the one thing acceptance is supposed to change.
        $published = $entry->wasChanged(WithoutDraftsScope::COLUMN) && !$entry->isDraft();

        if (!$published && !$entry->wasChanged('index_digest')) {
            return; // a status flip, a reorder, a revision pointer — the text did not move
        }

        $this->queueIndexing($entry);
    }

    /** A restore brings an entry back into retrieval; its chunks may be stale or purged. */
    public function restored(KnowledgeEntry $entry): void
    {
        $this->queueIndexing($entry);
    }

    private function queueIndexing(KnowledgeEntry $entry): void
    {
        if ($entry->isDraft()) {
            // An unaccepted AI draft is not knowledge yet. Indexing it would embed text nobody has
            // approved — real money spent on something the user may reject in ten seconds — and worse,
            // its chunks would sit in the vector table where retrieval reaches them, so a bot could
            // quote a machine-written draft to a customer as fact. Accepting the entry clears
            // `draft_session_id`, which fires `updated` and queues it exactly like any other save.
            return;
        }

        if (!config('knowledge.index.enabled')) {
            return; // the kill switch, honoured before anything is queued (and again in the job)
        }

        if (!$entry->needsIndexing()) {
            return; // the stored vectors already describe this text
        }

        $workspaceId = app(TenantContext::class)->id();

        if ($workspaceId === null) {
            return; // no tenant to attribute the work to; the sweep will catch the entry
        }

        $entryId = (string) $entry->getKey();

        DB::afterCommit(fn () => IndexKnowledgeEntryJob::dispatch($entryId, $workspaceId));
    }
}
