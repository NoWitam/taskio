<?php

namespace App\Modules\Approvals\Http\Resources;

use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalPipelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon?->value,
            'description' => $this->description,
            'stages' => ApprovalStageResource::collection($this->whenLoaded('stages')),
            'creator' => UserResource::make($this->whenLoaded('creator')),
            'is_owner' => $this->creator_id === $request->user()?->id,
            'can_be_edited' => $this->canBeEdited(),
            'can_be_deleted' => $this->canBeDeleted(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
