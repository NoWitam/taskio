<?php

namespace App\Modules\History\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'event_description' => $this->event->getDescription(),
            'description' => $this->description,
            'changes' => $this->getChanges(),
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'causer' => $this->causer ? [
                'id' => $this->causer->id,
                'name' => $this->causer->name,
                'email' => $this->causer->email,
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
