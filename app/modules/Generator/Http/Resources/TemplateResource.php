<?php

namespace App\Modules\Generator\Http\Resources;

use App\Http\Resources\CreatorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A TEMPLATE: its identity, the recipe `content_type`, the DECLARED typed `slots` ({name, description,
 * descriptor}), the per-part authored `content` map, and the creator + capability flags (mirrors the
 * Constant / CustomFunction / Workflow convention). The `descriptor` on each slot is the authoritative
 * type the template-editor UI reads, carried in the SAME `{base, nullable?, array?, options?, fields?}`
 * shape constants / functions use. The `content` map is emitted as authored (validated on write), keyed
 * by the content type's part keys — the FE reads each part by kind from the content-types catalog.
 *
 * @property \App\Modules\Generator\Models\Template $resource
 */
class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'content_type' => $this->content_type,

            // The authoritative definition the editor reads: the typed slots + the per-part content map.
            'slots' => $this->shapeSlots(),
            'content' => is_array($this->content) ? $this->content : [],

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            // Capability flags (server-authoritative — mirrors the Constant/CustomFunction convention).
            'is_owner' => $this->isOwnedBy($request->user()),
            'can_be_edited' => $request->user()?->can('update', $this->resource) ?? false,
            'can_be_deleted' => $request->user()?->can('delete', $this->resource) ?? false,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Each declared slot as exactly `{name, description, descriptor}` — the stable wire shape the FE
     * mirrors, so a stored slot never leaks an internal key. A malformed stored slot is skipped.
     *
     * @return array<int, array{name: string, description: string|null, descriptor: array<string, mixed>}>
     */
    private function shapeSlots(): array
    {
        $slots = is_array($this->slots) ? $this->slots : [];

        $shaped = [];

        foreach ($slots as $slot) {
            if (!is_array($slot) || !is_string($slot['name'] ?? null)) {
                continue;
            }

            $description = $slot['description'] ?? null;

            $shaped[] = [
                'name' => $slot['name'],
                'description' => is_string($description) ? $description : null,
                'descriptor' => is_array($slot['descriptor'] ?? null) ? $slot['descriptor'] : [],
            ];
        }

        return $shaped;
    }
}
