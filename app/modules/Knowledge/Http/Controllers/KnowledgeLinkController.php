<?php

namespace App\Modules\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Knowledge\Http\Requests\DismissKnowledgeLinkRequest;
use App\Modules\Knowledge\Http\Resources\KnowledgeLinkResource;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Services\KnowledgeLinkService;

/**
 * The human verdict on a MACHINE-PROPOSED edge.
 *
 * A dismissal is a stamp, not a deletion, and that is the whole design. The similarity linker rebuilds
 * an entry's edges on every re-index; if "no, these are not related" were expressed by deleting the
 * row, the next edit of any paragraph would propose it again — and a suggestion a user has to reject
 * repeatedly is worse than one that was never made. Because it is a stamp it is also REVERSIBLE, hence
 * the `DELETE` on the same path: the undo of a dismissal is un-dismissing, not re-deriving.
 *
 * Both verbs return the edge, so a client can update the row it is looking at rather than refetching
 * a graph to learn the outcome of a one-click action.
 */
class KnowledgeLinkController extends Controller
{
    public function __construct(
        private KnowledgeLinkService $service,
    ) {}

    /**
     * Reject a suggestion. The edge stays, marked, and no re-index will re-propose it.
     *
     * The `KnowledgeLink` parameter is what makes the route MODEL-BOUND: without it the request would
     * only ever see a raw id string, the policy check in the FormRequest would fail closed, and a
     * foreign id would 403 instead of 404ing at bind (which is the app-wide tenancy posture).
     */
    public function dismiss(DismissKnowledgeLinkRequest $request, KnowledgeLink $link): KnowledgeLinkResource
    {
        return KnowledgeLinkResource::make($this->service->dismiss($link));
    }

    /**
     * Undo the rejection. The edge becomes live again immediately with the score and evidence it
     * already carried — it is NOT re-derived, because re-deriving would need a re-index and would make
     * an undo silently unavailable until one happened.
     */
    public function restore(DismissKnowledgeLinkRequest $request, KnowledgeLink $link): KnowledgeLinkResource
    {
        return KnowledgeLinkResource::make($this->service->undismiss($link));
    }
}
