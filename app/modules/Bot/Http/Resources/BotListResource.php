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

            'is_owner' => $this->creator_id === $request->user()?->id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
