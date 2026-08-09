<?php

namespace App\Modules\Knowledge\Http\Resources;

use App\Http\Resources\CreatorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A KNOWLEDGE ENTRY as it appears in a LIST — everything the full resource carries EXCEPT the body,
 * which is replaced by a short excerpt.
 *
 * A separate resource (mirroring the Bot module's list/detail split) rather than a flag on the full
 * one, because the difference is a real payload hazard, not a preference: an entry may hold 40 000
 * characters, so a 25-row page of full entries is a megabyte of text nothing on screen displays. The
 * excerpt is deliberately taken from the raw content — the entries in a knowledge base are prose,
 * and the first couple of lines are what a human uses to recognise one.
 */
class KnowledgeEntryListResource extends JsonResource
{
    /** How much of the body the list carries. Enough to recognise an entry, far from enough to read it. */
    private const EXCERPT_CHARS = 200;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'knowledge_base_id' => $this->knowledge_base_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt(),
            'metadata' => $this->metadata ?? [],
            // Cheap (a jsonb column already on the row, capped at 10 short strings), so the list
            // carries them too — a chip row on a card needs no second request.
            'aliases' => $this->aliases ?? [],
            // Same reason as the aliases above: a column already on the row, and a list that draws a
            // type badge should not have to fetch each entry to do it.
            'entry_type' => $this->entry_type?->value,

            'status' => $this->status?->value,
            'stale_at' => $this->stale_at?->toISOString(),
            'is_stale' => $this->isStale(),
            'position' => $this->position,

            'current_revision_id' => $this->current_revision_id,

            // Same `index` shape as the detail resource — a list row and a detail view must not
            // describe indexing differently. `indexed_chunks_count` rides one correlated sub-select
            // for the whole page (KnowledgeEntry::scopeWithIndexedChunks), so it costs no N+1; it is
            // null when the list was built without that scope or the connection cannot count it.
            'index' => [
                'status' => $this->index_status?->value,
                'chunks_count' => $this->chunks_count,
                'indexed_chunks_count' => $this->indexedChunksCount(),
                'needs_indexing' => $this->needsIndexing(),
                'can_retry' => $this->index_status?->isRetryable() ?? false,
            ],

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            // No `can_be_*` here either — see KnowledgeEntryResource. Nobody edits, trashes or purges
            // an entry, so the flags said the same thing to everybody on every row of every page.
            'is_owner' => $this->isOwnedBy($request->user()),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }

    private function excerpt(): string
    {
        $content = trim((string) $this->content);

        if ($content === '') {
            return '';
        }

        // Collapse whitespace so a heading-heavy entry does not excerpt to a column of blank lines.
        $flat = trim((string) preg_replace('/\s+/u', ' ', $content));

        return mb_strlen($flat) <= self::EXCERPT_CHARS
            ? $flat
            : mb_substr($flat, 0, self::EXCERPT_CHARS) . '…';
    }
}
