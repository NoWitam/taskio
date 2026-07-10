<?php

namespace App\Modules\Workflows\Http\Resources;

use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full workflow DEFINITION shape (detail / after-write). Exposes the complete config plus
 * capability flags. Scheduling fields (next_due_at, last_scheduled_run_at) are surfaced
 * now but only become meaningful once the scheduler ships.
 */
class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'description' => $this->description,
            'icon' => $this->icon,

            // Trigger definition.
            'trigger_type' => $this->trigger_type,
            'trigger_config' => $this->trigger_config ?? [],

            // Optional gate conditions and the ordered step list.
            'conditions' => $this->conditions ?? [],
            'steps' => $this->steps ?? [],

            // Scheduling (populated by the scheduler in a later batch).
            'last_scheduled_run_at' => $this->last_scheduled_run_at?->toISOString(),
            'next_due_at' => $this->next_due_at?->toISOString(),

            'creator' => UserResource::make($this->whenLoaded('creator')),

            // Capability flags (mirrors the Bot/Approvals convention).
            'is_owner' => $this->creator_id === $request->user()?->id,
            'can_be_edited' => $request->user()?->can('update', $this->resource) ?? false,
            'can_be_deleted' => $request->user()?->can('delete', $this->resource) ?? false,
            'can_change_status' => $request->user()?->can('changeStatus', $this->resource) ?? false,
            'can_run' => $request->user()?->can('run', $this->resource) ?? false,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
