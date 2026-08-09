<?php

namespace App\Modules\Knowledge\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One edge of the knowledge graph, in either direction.
 *
 * `is_ghost` is stated explicitly rather than left for a client to infer from a null `to_entry_id`:
 * a ghost is a first-class thing a base wants to SHOW ("you referred to this, it does not exist
 * yet"), and making every consumer re-derive that from a null check is how it ends up rendered as an
 * error in one place and a broken link in another.
 *
 * `target` and `source_entry` are the two ends, each present only when the relation was eager-loaded
 * — the outgoing list loads the target, the backlink list loads the source. Both are the minimal
 * {id, title, slug} needed to render a link; loading the whole entry would drag 40k-character bodies
 * into a sidebar.
 */
class KnowledgeLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'knowledge_base_id' => $this->knowledge_base_id,
            'from_entry_id' => $this->from_entry_id,
            'to_entry_id' => $this->to_entry_id,
            'target_slug' => $this->target_slug,
            'source' => $this->source?->value,
            'is_ghost' => $this->isGhost(),

            // Machine-proposed edges only. `similarity` carries a score plus the two chunk ordinals;
            // `mention` carries no score (it is not a measurement) and its evidence is the
            // {char_start, char_length} of the first place the target's name appears. A wikilink and a
            // manual edge carry neither.
            'score' => $this->score,
            'evidence' => $this->evidence,
            'dismissed_at' => $this->dismissed_at?->toISOString(),

            // Whether "no, these are not related" is an available answer — true for the derived kinds,
            // false for the authored ones. A capability flag rather than a rule the client re-derives
            // from `source`, so a new derived kind does not need a matching change in every UI.
            'can_be_dismissed' => $this->source?->isDismissable() ?? false,

            'target' => $this->whenLoaded('toEntry', fn () => $this->toEntry === null ? null : [
                'id' => $this->toEntry->id,
                'title' => $this->toEntry->title,
                'slug' => $this->toEntry->slug,
            ]),

            'source_entry' => $this->whenLoaded('fromEntry', fn () => $this->fromEntry === null ? null : [
                'id' => $this->fromEntry->id,
                'title' => $this->fromEntry->title,
                'slug' => $this->fromEntry->slug,
            ]),

            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
