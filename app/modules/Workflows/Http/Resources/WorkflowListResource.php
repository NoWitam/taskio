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

            'is_owner' => $this->creator_id === $request->user()?->id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
