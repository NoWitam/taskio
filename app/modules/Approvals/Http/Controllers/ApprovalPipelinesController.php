<?php

namespace App\Modules\Approvals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Approvals\DTOs\ApprovalPipelineDTO;
use App\Modules\Approvals\Http\Requests\StoreApprovalPipelineRequest;
use App\Modules\Approvals\Http\Resources\ApprovalPipelineListResource;
use App\Modules\Approvals\Http\Resources\ApprovalPipelineResource;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Services\ApprovalPipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApprovalPipelinesController extends Controller
{
    public function __construct(
        private ApprovalPipelineService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $paginator = $this->service->index($request);

        return ApprovalPipelineListResource::collection($paginator);
    }

    public function show(ApprovalPipeline $pipeline): ApprovalPipelineResource
    {
        return ApprovalPipelineResource::make(
            $pipeline->loadMissing(['stages.approver', 'creator'])
        );
    }

    public function store(StoreApprovalPipelineRequest $request): ApprovalPipelineResource
    {
        return ApprovalPipelineResource::make(
            $this->service->create(
                ApprovalPipelineDTO::fromRequest($request)
            )
        );
    }

    public function update(StoreApprovalPipelineRequest $request, ApprovalPipeline $pipeline): ApprovalPipelineResource
    {
        return ApprovalPipelineResource::make(
            $this->service->update(
                $pipeline,
                ApprovalPipelineDTO::fromRequest($request)
            )
        );
    }

    public function destroy(ApprovalPipeline $pipeline): JsonResponse
    {
        $this->authorize('delete', $pipeline);

        $this->service->delete($pipeline);

        return response()->json([
            'message' => 'Pipeline deleted successfully',
        ]);
    }
}
