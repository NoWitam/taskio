<?php

namespace App\Modules\Variables\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Variables\DTOs\CustomFunctionDTO;
use App\Modules\Variables\Http\Requests\StoreCustomFunctionRequest;
use App\Modules\Variables\Http\Requests\UpdateCustomFunctionRequest;
use App\Modules\Variables\Http\Resources\CustomFunctionResource;
use App\Modules\Variables\Models\CustomFunction;
use App\Modules\Variables\Services\CustomFunctionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRUD for CUSTOM FUNCTIONS — user-created, workspace-scoped variable transforms (input + typed args →
 * return, over a saved body pipeline). Thin: each method converts request → DTO, calls the service, and
 * returns a resource. Authorization lives in the FormRequests (mutations, via CustomFunctionPolicy) and
 * the explicit authorize() calls (reads); the delete-while-referenced guard lives in the service.
 */
class CustomFunctionController extends Controller
{
    public function __construct(
        private CustomFunctionService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomFunction::class);

        return CustomFunctionResource::collection(
            $this->service->index($request)
        );
    }

    public function store(StoreCustomFunctionRequest $request): CustomFunctionResource
    {
        return CustomFunctionResource::make(
            $this->service->create(CustomFunctionDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    public function show(CustomFunction $function): CustomFunctionResource
    {
        $this->authorize('view', $function);

        return CustomFunctionResource::make($function->loadMissing('creator'));
    }

    public function update(UpdateCustomFunctionRequest $request, CustomFunction $function): CustomFunctionResource
    {
        return CustomFunctionResource::make(
            $this->service->update($function, CustomFunctionDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    public function destroy(CustomFunction $function): JsonResponse
    {
        $this->authorize('delete', $function);

        $this->service->delete($function);

        return response()->json(['message' => 'Function deleted successfully']);
    }
}
