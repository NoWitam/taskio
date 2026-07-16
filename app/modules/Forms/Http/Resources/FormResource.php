<?php

namespace App\Modules\Forms\Http\Resources;

use App\Http\Resources\CreatorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon?->value,
            'description' => $this->description,
            'content' => $this->content,
            'is_anonymous' => $this->is_anonymous,

            // Activation status
            'enabled_at' => $this->enabled_at?->toISOString(),
            'is_enabled' => $this->isEnabled(),

            // Index status
            'indexed_at' => $this->indexed_at?->toISOString(),
            'is_indexed' => $this->isIndexed(),
            'is_indexing' => $this->isIndexing(),

            // Versioning
            'content_version' => $this->content_version,
            'content_updated_at' => $this->content_updated_at?->toISOString(),

            // Centralized capabilities
            'can_be_edited' => $this->canBeEdited(),
            'can_be_filled' => $this->canBeFilled(),
            'can_be_enabled' => $this->canBeEnabled(),
            'can_be_disabled' => $this->canBeDisabled(),
            'can_be_indexed' => $this->canBeIndexed(),
            'can_be_unindexed' => $this->canBeUnindexed(),
            'can_restore_index' => $this->canRestoreIndex(),
            'has_index_backup' => $this->index_backup !== null,
            'is_draft' => $this->isDraft(),

            // Filter & reporting capabilities
            'available_filters' => $this->getAvailableFilters(),
            'reporting_mode' => $this->getReportingMode(),

            'creator' => CreatorResource::make($this->whenLoaded('creator')),
            'submissions_count' => $this->whenCounted('submissions'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
