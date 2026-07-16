<?php

namespace App\Modules\Approvals\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalPipelineListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon?->value,
            'description' => $this->description,
            'stages_count' => $this->whenCounted('stages', $this->stages->count()),
            'stages' => $this->stages->sortBy('order')->values()->map(fn ($stage) => [
                'name' => $stage->name,
                'icon' => $stage->icon?->value,
                'order' => $stage->order,
            ]),
            // Hot path: ownerUserId() reads creator_type/creator_id without loading the User
            // (the list query does not eager-load creator). A run/bot creator yields null ->
            // is_owner=false, matching isOwnedBy without the per-row N+1.
            'is_owner' => $request->user() !== null && $this->ownerUserId() === $request->user()->id,
            'can_be_edited' => $this->canBeEdited(),
            'can_be_deleted' => $this->canBeDeleted(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
