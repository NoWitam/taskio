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
            'approver' => UserResource::make($this->whenLoaded('approver')),
            'order' => $this->order,
        ];
    }
}
