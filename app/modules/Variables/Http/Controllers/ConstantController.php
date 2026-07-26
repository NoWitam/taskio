<?php

namespace App\Modules\Variables\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Variables\DTOs\ConstantDTO;
use App\Modules\Variables\Http\Requests\StoreConstantRequest;
use App\Modules\Variables\Http\Requests\UpdateConstantRequest;
use App\Modules\Variables\Http\Resources\ConstantResource;
use App\Modules\Variables\Models\Constant;
use App\Modules\Variables\Services\ConstantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRUD for CONSTANTS — user-created, workspace-scoped typed literal constants that become
 * `globals.<key>` references usable in every workflow. Thin: each method converts request → DTO,
 * calls the service, and returns a resource. Authorization lives in the FormRequests (mutations,
 * via ConstantPolicy) and the explicit authorize() calls (reads).
 */
class ConstantController extends Controller
{
    public function __construct(
        private ConstantService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Constant::class);

        return ConstantResource::collection(
            $this->service->index($request)
        );
    }

    public function store(StoreConstantRequest $request): ConstantResource
    {
        return ConstantResource::make(
            $this->service->create(ConstantDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    public function show(Constant $constant): ConstantResource
    {
        $this->authorize('view', $constant);

        return ConstantResource::make($constant->loadMissing('creator'));
    }

    public function update(UpdateConstantRequest $request, Constant $constant): ConstantResource
    {
        return ConstantResource::make(
            $this->service->update($constant, ConstantDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    public function destroy(Constant $constant): JsonResponse
    {
        $this->authorize('delete', $constant);

        $this->service->delete($constant);

        return response()->json(['message' => 'Constant deleted successfully']);
    }
}
