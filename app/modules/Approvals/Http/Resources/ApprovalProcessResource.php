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
            // Back-compat: user-only approver (null for ai/bot processes).
            'approver' => UserResource::make($this->whenLoaded('approver')),
            // Polymorphic approver identity (User OR Bot, null for a generic AI stage).
            'approver_identity' => ApproverResource::present(
                $this->approver_type,
                $this->whenLoaded('approver'),
                $this->whenLoaded('approverBot'),
            ),
            'pipeline' => ApprovalPipelineResource::make($this->whenLoaded('pipeline')),
            'stage' => ApprovalStageResource::make($this->whenLoaded('stage')),
            'decided_at' => $this->decided_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
