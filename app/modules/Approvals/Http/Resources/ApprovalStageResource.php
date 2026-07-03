<?php

namespace App\Modules\Approvals\Http\Resources;

use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalStageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon?->value,
            'description' => $this->description,
            'approver_type' => $this->approver_type->value,
            // Back-compat: user-only approver (null for ai/bot stages).
            'approver' => UserResource::make($this->whenLoaded('approver')),
            // Polymorphic approver identity (User OR Bot, null for a generic AI stage).
            'approver_identity' => ApproverResource::present(
                $this->approver_type,
                $this->whenLoaded('approver'),
                $this->whenLoaded('approverBot'),
            ),
            'order' => $this->order,
        ];
    }
}
