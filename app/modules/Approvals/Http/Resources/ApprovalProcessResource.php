<?php

namespace App\Modules\Approvals\Http\Resources;

use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalProcessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_id' => $this->run_id,
            'status' => $this->status->value,
            'note' => $this->note,
            'approver_type' => $this->approver_type->value,
            'approver' => UserResource::make($this->whenLoaded('approver')),
            'pipeline' => ApprovalPipelineResource::make($this->whenLoaded('pipeline')),
            'stage' => ApprovalStageResource::make($this->whenLoaded('stage')),
            'decided_at' => $this->decided_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
