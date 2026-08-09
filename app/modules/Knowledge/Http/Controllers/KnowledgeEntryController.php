<?php

namespace App\Modules\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Knowledge\Http\Requests\RetryKnowledgeIndexRequest;
use App\Modules\Knowledge\Http\Resources\KnowledgeEntryListResource;
use App\Modules\Knowledge\Http\Resources\KnowledgeEntryResource;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Services\KnowledgeEntryService;
use App\Modules\Knowledge\Services\KnowledgeIndexService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * READING KNOWLEDGE ENTRIES — and, deliberately, nothing else.
 *
 * There is no create, no update, no delete, no restore, no purge and no reorder here. An entry is
 * written by the COMPOSER and reaches the base only when a person accepts the proposal that carries
 * it; a person's authority over an entry is to approve it, refuse it, or ask the composer again in
 * different words. {@see \App\Modules\Knowledge\Http\Controllers\KnowledgeDraftSessionController} is
 * where all of that lives.
 *
 * The SERVICE still has `create`, `update`, `delete` and `purge`, and they are called constantly — by
 * the composer when it writes a draft, by the applier when a proposal is accepted, by the erasure
 * command. Removing them would break the AI. What was removed is the HTTP surface that let a person
 * call them directly, and {@see \App\Modules\Knowledge\Policies\KnowledgeEntryPolicy} denies the
 * abilities as well, so the refusal does not depend on this file staying small.
 *
 * `retryIndex` is the one action here that changes anything, and it changes no text: it re-queues an
 * indexing run that failed. Without it a failed entry is silently unsearchable for ever.
 *
 * The list is served by a SEPARATE resource that carries an excerpt instead of the body — see
 * {@see KnowledgeEntryListResource} for why that is a payload requirement, not a preference.
 */
class KnowledgeEntryController extends Controller
{
    public function __construct(
        private KnowledgeEntryService $service,
        private KnowledgeIndexService $index,
    ) {}

    /**
     * Filters: `status[]`, `stale=1`, `search`, `trashed=1`. Cursor-paginated in the base's manual
     * order. `trashed=1` lists the base's deleted entries instead of its live ones — the entry-level
     * trash. READ-ONLY now: nothing restores an entry from it, because nothing trashes one by hand any
     * more. What is in there predates the withdrawal of hand-authorship, or arrived with a base that
     * was trashed and restored whole.
     */
    public function index(Request $request, KnowledgeBase $base): AnonymousResourceCollection
    {
        $this->authorize('view', $base);
        $this->authorize('viewAny', KnowledgeEntry::class);

        return KnowledgeEntryListResource::collection($this->service->index($base, $request));
    }

    public function show(KnowledgeEntry $entry): KnowledgeEntryResource
    {
        $this->authorize('view', $entry);

        return KnowledgeEntryResource::make(
            $entry->loadMissing(['creator', 'outgoingLinks.toEntry', 'incomingLinks.fromEntry'])
                ->loadIndexedChunks()
        );
    }

    /**
     * Re-queue indexing for an entry whose last run did not finish (`failed`, `pending_budget`,
     * `partial`). 422 for any other state — see {@see KnowledgeIndexService::retry()} for why those
     * three and not the rest. Returns the entry with its index state already moved to `pending`, so
     * the client can render the change without a refetch.
     */
    public function retryIndex(RetryKnowledgeIndexRequest $request, KnowledgeEntry $entry): KnowledgeEntryResource
    {
        $this->index->retry($entry, app(TenantContext::class)->id());

        return KnowledgeEntryResource::make(
            $entry->loadMissing(['creator', 'outgoingLinks.toEntry', 'incomingLinks.fromEntry'])
                ->loadIndexedChunks()
        );
    }
}
