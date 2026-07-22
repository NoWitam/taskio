<?php

namespace App\Modules\Workflows\Http\Resources;

use App\Http\Resources\CreatorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workflow GLOBAL: its identity, its `globals.<key>` reference path, the authoritative type
 * `descriptor`, and the stored literal `value`, plus the creator + capability flags (mirrors the
 * Workflow/Bot convention). The descriptor is the authoritative type the global-manager UI reads;
 * `reference` is the exact dotted path (`globals.<key>`) an editor uses to reference the global.
 */
class WorkflowGlobalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'key' => $this->key,
            'reference' => 'globals.' . $this->key,

            // The authoritative type descriptor + the stored literal.
            'descriptor' => $this->descriptor,
            'value' => $this->value,

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            // Capability flags (mirrors the Workflow/Bot convention).
            'is_owner' => $this->isOwnedBy($request->user()),
            'can_be_edited' => $request->user()?->can('update', $this->resource) ?? false,
            'can_be_deleted' => $request->user()?->can('delete', $this->resource) ?? false,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
