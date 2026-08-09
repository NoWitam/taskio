<?php

namespace App\Modules\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Knowledge\Http\Requests\KnowledgeGraphRequest;
use App\Modules\Knowledge\Http\Resources\KnowledgeGraphResource;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Services\KnowledgeGraphService;

/**
 * The base's LINK GRAPH: an overview of the whole base, or one entry's neighbourhood.
 *
 * `?entry=<uuid>` selects the ego graph. A centre belonging to another base must 404 rather than
 * produce a graph of one lonely node that silently answers a question about a different base — that
 * check is a QUERY, so it lives in the service with every other one ({@see KnowledgeGraphService::build()})
 * rather than as a probe here. This controller converts request → query object and returns a resource.
 */
class KnowledgeGraphController extends Controller
{
    public function __construct(
        private KnowledgeGraphService $service,
    ) {}

    public function show(KnowledgeGraphRequest $request, KnowledgeBase $base): KnowledgeGraphResource
    {
        return KnowledgeGraphResource::make(
            $this->service->build($base, $request->graphQuery())
        );
    }
}
