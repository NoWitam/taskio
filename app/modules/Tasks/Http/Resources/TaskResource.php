<?php

namespace App\Modules\Tasks\Http\Resources;

use App\Modules\Approvals\Http\Resources\ApprovalPipelineResource;
use App\Modules\Approvals\Http\Resources\ApprovalProcessResource;
use App\Modules\Disk\Http\Resources\FileResource;
use App\Modules\Forms\Http\Resources\FormResource;
use App\Modules\Forms\Http\Resources\FormSubmissionResource;
use App\Modules\Labels\Http\Resources\LabelResource;
use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'deadline' => $this->deadline?->format('Y-m-d'),
            'deadline_overdue' => $this->deadline?->diffInDays(now(), absolute: false),
            'is_overdue' => $this->isDeadlineOverdue(),
            'is_at_risk' => $this->isDeadlineAtRisk(),
            'attachments' => FileResource::collection($this->files),
            'creator' => UserResource::make($this->creator),
            'assigned' => UserResource::make($this->assigned),
            'labels' => LabelResource::collection($this->labels),
            'form_id' => $this->form_id,
            'form' => FormResource::make($this->whenLoaded('form')),
            'form_submission' => FormSubmissionResource::make($this->whenLoaded('formSubmission')),
            'approval_pipeline_id' => $this->approval_pipeline_id,
            'approval_pipeline' => $this->whenLoaded('approvalPipeline', fn () => ApprovalPipelineResource::make($this->approvalPipeline)),
            'is_in_approval' => $this->isInApproval(),
            'pending_approval_process' => $this->whenLoaded('pendingApprovalProcess', fn () => ApprovalProcessResource::make($this->pendingApprovalProcess)),
        ];
    }
}
