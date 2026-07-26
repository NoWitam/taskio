<?php

namespace App\Modules\Variables\Http\Resources;

use App\Http\Resources\CreatorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A CUSTOM FUNCTION: its identity + signature (input_type / typed args / return_type), the saved body
 * pipeline, and the creator + capability flags (mirrors the Constant/Workflow/Bot convention). Identity
 * is the uuid — the wire op id is `fn:<uuid>` — and `name` is a user-facing label only.
 */
class CustomFunctionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,

            // The signature + the saved body (the authoritative definition the function-manager UI reads).
            'input_type' => $this->input_type,
            'args' => $this->args,
            'return_type' => $this->return_type,
            'body' => $this->body,

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            // Capability flags (mirrors the Constant/Workflow/Bot convention).
            'is_owner' => $this->isOwnedBy($request->user()),
            'can_be_edited' => $request->user()?->can('update', $this->resource) ?? false,
            'can_be_deleted' => $request->user()?->can('delete', $this->resource) ?? false,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
