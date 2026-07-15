<?php

namespace App\Modules\Forms\Http\Resources;

use App\Http\Resources\CreatorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'form_id' => $this->form_id,
            'form' => FormListResource::make($this->whenLoaded('form')),
            'data' => $this->data,
            'source' => $this->submittable_type,
            'form_content_version_id' => $this->form_content_version_id,
            'indexed_at' => $this->indexed_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'is_approved' => $this->isApproved(),
            'can_be_edited' => $this->canBeEdited(),
            'creator' => CreatorResource::make($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
