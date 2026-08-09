<?php

namespace App\Modules\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Knowledge\DTOs\KnowledgeSearchResults;
use App\Modules\Knowledge\Http\Requests\KnowledgeSearchRequest;
use App\Modules\Knowledge\Http\Resources\KnowledgeSearchResultResource;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Services\KnowledgeSearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * HYBRID SEARCH over knowledge entries — keyword and meaning, fused.
 *
 * Two entry points for one service. `/knowledge/search` spans every base in the workspace and each
 * result names the base it came from; `/knowledge/bases/{base}/search` is the same search with the
 * base as its scope. The scoped route exists rather than a `?base=` filter because the base is part of
 * the RESOURCE PATH everywhere else in this module, and because the scoped one can be authorized
 * against that base — a filter parameter cannot be, so it would have to be re-checked as data.
 *
 * NOT PAGINATED, deliberately. A relevance ranking has no stable cursor: the order is recomputed from
 * a fresh query embedding every time, so "page 2" is not a continuation of a page 1 that still exists.
 * The response carries `meta.limit` and `meta.has_more` so a client can say "showing the best 25" —
 * and a caller who wants the whole base wants the ENTRY LIST endpoint, which is cursor-paginated and
 * stably ordered.
 */
class KnowledgeSearchController extends Controller
{
    public function __construct(
        private KnowledgeSearchService $service,
    ) {}

    /** Every base in the active workspace. Each result carries its `base`. */
    public function workspace(KnowledgeSearchRequest $request): AnonymousResourceCollection
    {
        return $this->respond($this->service->search(
            null,
            $request->term(),
            $request->statuses(),
            $request->limit(),
        ));
    }

    /** One base. */
    public function base(KnowledgeSearchRequest $request, KnowledgeBase $base): AnonymousResourceCollection
    {
        return $this->respond($this->service->search(
            $base,
            $request->term(),
            $request->statuses(),
            $request->limit(),
        ));
    }

    /**
     * The metadata is the part worth defending: a search whose vector leg was skipped still returns
     * 200 with keyword results, and says so. Turning an exhausted AI budget into an error would make
     * an ordinary, expected cost event look like an outage — and would hide the results the workspace
     * can still have for free.
     */
    private function respond(KnowledgeSearchResults $results): AnonymousResourceCollection
    {
        return KnowledgeSearchResultResource::collection($results->hits)->additional([
            'meta' => [
                'query' => $results->query,
                'count' => count($results->hits),
                'limit' => $results->limit,
                'has_more' => $results->hasMore,
                'vector_search_skipped' => $results->vectorSkipped,
                // budget | disabled | unsupported | error — null when both legs ran.
                'vector_search_reason' => $results->vectorReason,
            ],
        ]);
    }
}
