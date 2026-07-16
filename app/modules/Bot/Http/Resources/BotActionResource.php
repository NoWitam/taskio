<?php

namespace App\Modules\Bot\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Bot\Models\BotAction
 */
class BotActionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bot_id' => $this->bot_id,
            'task_id' => $this->task_id,
            'type' => $this->type->value,
            'payload' => $this->payload,
            'status' => $this->status,
            'error' => $this->error,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
