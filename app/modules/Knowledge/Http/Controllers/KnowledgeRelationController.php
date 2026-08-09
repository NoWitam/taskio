<?php

namespace App\Modules\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Knowledge\Http\Requests\IndexKnowledgeRelationsRequest;
use App\Modules\Knowledge\Http\Resources\KnowledgeRelationResource;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Services\KnowledgeRelationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * READING TYPED RELATIONS — the approved statements a base records about pairs of its entries.
 *
 * NOBODY WRITES ONE BY HAND. `store`, `update`, `end` and `destroy` are gone, and with them the
 * promotion of a machine suggestion into a hard relation: a relation exists because the composer
 * proposed it and a person accepted the proposal, and by no other route. That is the product decision
 * — the graph is the AI's account of the material, and a person editing it directly would be quietly
 * co-authoring a record that presents itself as machine-derived.
 *
 * `end`, `supersede` and `retract` went with the rest. They are not "less destructive" in the way that
 * matters here: each is a person changing what the base asserts, which is authorship.
 *
 * {@see KnowledgeRelationService} keeps every one of those operations and they run on every accepted
 * proposal — including the `end` half of a supersede. Only the HTTP surface is gone, and
 * {@see \App\Modules\Knowledge\Policies\KnowledgeRelationPolicy} denies the abilities as well.
 *
 * Relations are workspace-scoped bindings, so a foreign id 404s at bind before any policy runs — the
 * app-wide tenancy posture (ResolveWorkspace → RequireWorkspace → SubstituteBindings).
 */
class KnowledgeRelationController extends Controller
{
    public function __construct(
        private KnowledgeRelationService $service,
    ) {}

    /** Every relation touching this entry, in either direction. Historical ones on request. */
    public function index(IndexKnowledgeRelationsRequest $request, KnowledgeEntry $entry): AnonymousResourceCollection
    {
        return KnowledgeRelationResource::collection(
            $this->service->forEntry($entry, $request->includeHistorical())
        );
    }
}
