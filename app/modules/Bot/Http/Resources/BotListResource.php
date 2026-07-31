<?php

namespace App\Modules\Bot\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BotListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'description' => $this->description,
            'icon' => $this->icon,

            // The text module is always present (persona is mandatory).
            'has_text_module' => true,
            'task_execution_enabled' => (bool) ($this->task_execution['enabled'] ?? false),

            // Visual module at a glance: is it ON, and does the bot have an APPROVED likeness?
            // Read straight off the column (hot path — the list never hydrates the full identity).
            'visual_enabled' => $this->visualEnabled(),
            'visual_has_image' => is_array($this->visual) && filled($this->visual['canonical_file_id'] ?? null),

            // Hot path: ownerUserId() reads creator_type/creator_id without loading the User
            // (the list query does not eager-load creator). A run/bot creator yields null ->
            // is_owner=false, matching isOwnedBy without the per-row N+1.
            'is_owner' => $request->user() !== null && $this->ownerUserId() === $request->user()->id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
