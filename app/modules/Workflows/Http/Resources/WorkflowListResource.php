<?php

namespace App\Modules\Workflows\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean workflow shape for the list endpoint. Carries just enough to render a row: identity,
 * status, trigger type, a step count and the ownership flag.
 */
class WorkflowListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'description' => $this->description,
            'icon' => $this->icon,

            'trigger_type' => $this->trigger_type,
            'step_count' => count($this->steps ?? []),
            'next_due_at' => $this->next_due_at?->toISOString(),

            // Hot path: ownerUserId() reads creator_type/creator_id without loading the User
            // (the list query does not eager-load creator). A run/bot creator yields null ->
            // is_owner=false, matching isOwnedBy without the per-row N+1.
            'is_owner' => $request->user() !== null && $this->ownerUserId() === $request->user()->id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
