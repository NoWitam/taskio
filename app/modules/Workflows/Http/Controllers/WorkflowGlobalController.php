<?php

namespace App\Modules\Workflows\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workflows\DTOs\WorkflowGlobalDTO;
use App\Modules\Workflows\Http\Requests\StoreWorkflowGlobalRequest;
use App\Modules\Workflows\Http\Requests\UpdateWorkflowGlobalRequest;
use App\Modules\Workflows\Http\Resources\WorkflowGlobalResource;
use App\Modules\Workflows\Models\WorkflowGlobal;
use App\Modules\Workflows\Services\WorkflowGlobalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRUD for workflow GLOBALS — user-created, workspace-scoped typed literal constants that become
 * `globals.<key>` references usable in every workflow. Thin: each method converts request → DTO,
 * calls the service, and returns a resource. Authorization lives in the FormRequests (mutations,
 * via WorkflowGlobalPolicy) and the explicit authorize() calls (reads).
 */
class WorkflowGlobalController extends Controller
{
    public function __construct(
        private WorkflowGlobalService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WorkflowGlobal::class);

        return WorkflowGlobalResource::collection(
            $this->service->index($request)
        );
    }

    public function store(StoreWorkflowGlobalRequest $request): WorkflowGlobalResource
    {
        return WorkflowGlobalResource::make(
            $this->service->create(WorkflowGlobalDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    public function show(WorkflowGlobal $workflowGlobal): WorkflowGlobalResource
    {
        $this->authorize('view', $workflowGlobal);

        return WorkflowGlobalResource::make($workflowGlobal->loadMissing('creator'));
    }

    public function update(UpdateWorkflowGlobalRequest $request, WorkflowGlobal $workflowGlobal): WorkflowGlobalResource
    {
        return WorkflowGlobalResource::make(
            $this->service->update($workflowGlobal, WorkflowGlobalDTO::fromRequest($request))->loadMissing('creator')
        );
    }

    public function destroy(WorkflowGlobal $workflowGlobal): JsonResponse
    {
        $this->authorize('delete', $workflowGlobal);

        $this->service->delete($workflowGlobal);

        return response()->json(['message' => 'Workflow global deleted successfully']);
    }
}
