<?php

namespace App\Modules\Approvals\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Http\Requests\MakeDecisionRequest;
use App\Modules\Approvals\Http\Resources\ApprovalProcessResource;
use App\Modules\Approvals\Http\Resources\ApprovalQueueItemResource;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ApprovalsController extends Controller
{
    public function __construct(
        private ApprovalService $service,
    ) {}

    public function queue(Request $request): AnonymousResourceCollection
    {
        $paginator = $this->service->getQueueForUser(auth()->id());

        return ApprovalQueueItemResource::collection($paginator)->additional(['meta' => [
            'total' => !$request->has('cursor')
                ? $this->service->getQueueCountForUser(auth()->id())
                : null,
        ]]);
    }

    public function queueCount(): JsonResponse
    {
        return response()->json([
            'count' => $this->service->getQueueCountForUser(auth()->id()),
        ]);
    }

    public function show(ApprovalProcess $process): ApprovalProcessResource
    {
        return ApprovalProcessResource::make(
            $process->loadMissing(['pipeline.stages.approver', 'stage', 'approver', 'approvable'])
        );
    }

    public function runHistory(string $runId): AnonymousResourceCollection
    {
        $processes = ApprovalProcess::query()
            ->with(['stage', 'approver'])
            ->where('run_id', $runId)
            ->orderBy('created_at')
            ->get();

        return ApprovalProcessResource::collection($processes);
    }

    public function decide(MakeDecisionRequest $request, ApprovalProcess $process): \Illuminate\Http\JsonResponse
    {
        $decision = ApprovalProcessStatus::from($request->string('decision'));

        $updatedProcess = $this->service->decide(
            $process,
            $decision,
            $request->string('note') ?: null,
        );

        return ApprovalProcessResource::make(
            $updatedProcess->loadMissing(['pipeline', 'stage', 'approver'])
        )->response()->setStatusCode(200);
    }
}
