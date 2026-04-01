<?php

namespace App\Modules\Forms\Http\Resources;

use App\Modules\Users\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon?->value,
            'description' => $this->description,
            'is_anonymous' => $this->is_anonymous,
            'creator' => UserResource::make($this->whenLoaded('creator')),
            'submissions_count' => $this->whenCounted('submissions'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
