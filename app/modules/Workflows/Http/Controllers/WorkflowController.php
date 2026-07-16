<?php

namespace App\Modules\Workflows\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workflows\DTOs\WorkflowDTO;
use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Http\Requests\ChangeWorkflowStatusRequest;
use App\Modules\Workflows\Http\Requests\RunWorkflowRequest;
use App\Modules\Workflows\Http\Requests\StoreWorkflowRequest;
use App\Modules\Workflows\Http\Requests\UpdateWorkflowRequest;
use App\Modules\Workflows\Http\Resources\WorkflowListResource;
use App\Modules\Workflows\Http\Resources\WorkflowResource;
use App\Modules\Workflows\Http\Resources\WorkflowRunResource;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowManualRunService;
use App\Modules\Workflows\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class WorkflowController extends Controller
{
    public function __construct(
        private WorkflowService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Workflow::class);

        return WorkflowListResource::collection(
            $this->service->index($request)
        );
    }

    public function store(StoreWorkflowRequest $request): WorkflowResource
    {
        return WorkflowResource::make(
            $this->service->create(WorkflowDTO::fromRequest($request))
                ->loadMissing(['creator' => fn ($creator) => $creator->morphWith([WorkflowRun::class => ['workflow']])])
        );
    }

    public function show(Workflow $workflow): WorkflowResource
    {
        $this->authorize('view', $workflow);

        return WorkflowResource::make($workflow->loadMissing(['creator' => fn ($creator) => $creator->morphWith([WorkflowRun::class => ['workflow']])]));
    }

    public function update(UpdateWorkflowRequest $request, Workflow $workflow): WorkflowResource
    {
        return WorkflowResource::make(
            $this->service->update($workflow, WorkflowDTO::fromRequest($request))
                ->loadMissing(['creator' => fn ($creator) => $creator->morphWith([WorkflowRun::class => ['workflow']])])
        );
    }

    public function changeStatus(ChangeWorkflowStatusRequest $request, Workflow $workflow): WorkflowResource
    {
        return WorkflowResource::make(
            $this->service->changeStatus($workflow, $request->enum('status', WorkflowStatus::class))
                ->loadMissing(['creator' => fn ($creator) => $creator->morphWith([WorkflowRun::class => ['workflow']])])
        );
    }

    /**
     * Manually run a workflow (works on ANY workflow, including INACTIVE — test-before-activate).
     * Authorization + target_id validation live in RunWorkflowRequest/WorkflowPolicy::run; the
     * service resolves the tenant-scoped target, builds the trigger payload, enforces the cap
     * (422), and starts a manual run whose job is deferred+dispatched by the run manager.
     * Returns 202 Accepted — the run executes asynchronously (or in-process under sync).
     */
    public function run(RunWorkflowRequest $request, Workflow $workflow, WorkflowManualRunService $manualRuns): JsonResponse
    {
        $run = $manualRuns->run(
            $workflow,
            $request->input('target_id'),
            $request->user()->id,
        );

        return WorkflowRunResource::make($run)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function destroy(Workflow $workflow): JsonResponse
    {
        $this->authorize('delete', $workflow);

        $this->service->delete($workflow);

        return response()->json(['message' => 'Workflow deleted successfully']);
    }

    public function restore(string $id): WorkflowResource
    {
        $workflow = Workflow::withTrashed()->findOrFail($id);

        $this->authorize('restore', $workflow);

        return WorkflowResource::make(
            $this->service->restore($workflow)->loadMissing(['creator' => fn ($creator) => $creator->morphWith([WorkflowRun::class => ['workflow']])])
        );
    }
}
