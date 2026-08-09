<?php

namespace App\Modules\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Knowledge\DTOs\KnowledgeBaseDTO;
use App\Modules\Knowledge\Http\Requests\StoreKnowledgeBaseRequest;
use App\Modules\Knowledge\Http\Requests\UpdateKnowledgeBaseRequest;
use App\Modules\Knowledge\Http\Resources\KnowledgeBaseResource;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Services\KnowledgeBaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD for KNOWLEDGE BASES. Thin: each method converts request → DTO, calls the service, returns a
 * resource. Authorization lives in the FormRequests (mutations, via KnowledgeBasePolicy) and the
 * explicit authorize() calls (reads and the id-resolved lifecycle endpoints).
 */
class KnowledgeBaseController extends Controller
{
    public function __construct(
        private KnowledgeBaseService $service,
    ) {}

    /** `?trashed=1` lists the workspace's trashed bases instead of its live ones. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', KnowledgeBase::class);

        $bases = $this->service->index($request);

        // Two grouped queries for the whole page — the card aggregates, never an N+1. See
        // KnowledgeBaseResource for what a card does with them.
        $this->service->attachAggregates($bases->items());

        return KnowledgeBaseResource::collection($bases);
    }

    public function store(StoreKnowledgeBaseRequest $request): KnowledgeBaseResource
    {
        return $this->withAggregates(
            $this->service->create(KnowledgeBaseDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    public function show(KnowledgeBase $base): KnowledgeBaseResource
    {
        $this->authorize('view', $base);

        return $this->withAggregates($base->loadMissing('creator'));
    }

    public function update(UpdateKnowledgeBaseRequest $request, KnowledgeBase $base): KnowledgeBaseResource
    {
        return $this->withAggregates(
            $this->service->update($base, KnowledgeBaseDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    /** Trash the base AND everything live inside it, reversibly (see KnowledgeBaseService). */
    public function destroy(KnowledgeBase $base): JsonResponse
    {
        $this->authorize('delete', $base);

        $this->service->delete($base);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Restore a trashed base. Resolved with withTrashed() rather than route-model binding, which
     * would 404 a soft-deleted row (mirrors the Disk/Forms/Workflows restore endpoints). The
     * WorkspaceScope still applies, so a foreign id 404s here exactly as it does at bind.
     */
    public function restore(string $id): KnowledgeBaseResource
    {
        $base = KnowledgeBase::withTrashed()->findOrFail($id);

        $this->authorize('restore', $base);

        return $this->withAggregates($this->service->restore($base)->loadMissing('creator'));
    }

    /** Permanent: the base and every entry, revision, chunk and link beneath it. */
    public function forceDestroy(string $id): JsonResponse
    {
        $base = KnowledgeBase::withTrashed()->findOrFail($id);

        $this->authorize('forceDelete', $base);

        $this->service->purge($base);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Every single-base response goes through here, so the card contract
     * ({@see KnowledgeBaseResource}) is the same shape whether a base was listed, opened, created or
     * saved. A client that had to check whether `index_summary` came back is a client that will
     * eventually forget to.
     */
    private function withAggregates(KnowledgeBase $base): KnowledgeBaseResource
    {
        $this->service->attachAggregates([$base]);

        return KnowledgeBaseResource::make($base);
    }
}
