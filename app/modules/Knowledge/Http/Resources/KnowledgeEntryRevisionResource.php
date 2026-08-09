<?php

namespace App\Modules\Knowledge\Http\Resources;

use App\Http\Resources\CreatorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One append-only snapshot of an entry's authored state.
 *
 * The actor is exposed as `author` even though the underlying relation is the shared `creator`
 * morphTo (the columns are author_id/author_type): on a revision the actor is the author of THAT
 * version, and calling it a "creator" in the API would suggest it created the entry.
 *
 * There is no `updated_at` because there is no such column — a revision is written once. That is the
 * property that makes the history trustworthy, so the absence is contract, not omission.
 */
class KnowledgeEntryRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'knowledge_entry_id' => $this->knowledge_entry_id,
            'title' => $this->title,
            'content' => $this->content,
            'metadata' => $this->metadata ?? [],
            'change_note' => $this->change_note,

            'author' => CreatorResource::make($this->whenLoaded('creator')),

            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
