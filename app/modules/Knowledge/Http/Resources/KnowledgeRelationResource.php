<?php

namespace App\Modules\Knowledge\Http\Resources;

use App\Http\Resources\CreatorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One TYPED RELATION — an approved statement about two entries.
 *
 * `label` and `inverse_label` are both sent, and neither is decoration. A relation panel is read from
 * ONE entry's point of view, so the same row has to be worded two ways ("is a member of Acme" on
 * Anna's page, "has member Anna" on Acme's), and a client that derived the second from the first would
 * be re-implementing grammar the server already knows. `symmetric` says the direction carries no
 * meaning at all, so the UI draws it undirected instead of picking an arbitrary arrow.
 *
 * `state` is the whole lifecycle: `active` is a claim about now, `ended` is a claim about the past
 * (with `valid_to` saying when it stopped), `retracted` is "this was never true". Those are three
 * different sentences and a UI that renders them identically is lying about two of them.
 *
 * `origin` is provenance, and it answers the question a reviewer actually asks: is this here because a
 * person said so, or because a model did and somebody clicked accept?
 *
 * The two endpoints are the minimal {id, title, slug, entry_type} — enough to render and route.
 * Loading whole entries would drag 40k-character bodies into a sidebar of edges.
 */
class KnowledgeRelationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'knowledge_base_id' => $this->knowledge_base_id,

            'from_entry_id' => $this->from_entry_id,
            'to_entry_id' => $this->to_entry_id,

            'relation_type' => $this->relation_type?->value,
            // Both readings of the same statement — see the class docblock.
            'label' => $this->relation_type?->label(),
            'inverse_label' => $this->relation_type?->inverseLabel(),
            'symmetric' => $this->isSymmetric(),

            'description' => $this->description,
            'properties' => $this->properties ?? [],
            // Dates, not timestamps: these facts are known to the day at best.
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),

            'state' => $this->state?->value,
            'is_active' => $this->isActive(),
            'superseded_by_id' => $this->superseded_by_id,

            // human | composer | promoted. `composer` means a model proposed it and a person approved
            // it, which is a different kind of trust from a person having written it.
            'origin' => $this->origin?->value,

            'from_entry' => $this->whenLoaded('fromEntry', fn () => $this->fromEntry === null ? null : [
                'id' => $this->fromEntry->id,
                'title' => $this->fromEntry->title,
                'slug' => $this->fromEntry->slug,
                'entry_type' => $this->fromEntry->entry_type?->value,
            ]),

            'to_entry' => $this->whenLoaded('toEntry', fn () => $this->toEntry === null ? null : [
                'id' => $this->toEntry->id,
                'title' => $this->toEntry->title,
                'slug' => $this->toEntry->slug,
                'entry_type' => $this->toEntry->entry_type?->value,
            ]),

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            // NO CAPABILITY FLAGS. `can_be_edited`, `can_be_ended` and `can_be_deleted` are gone
            // because none of those things is possible for anyone: a relation is written by an accepted
            // proposal and by nothing else. Sending three permanently-false booleans on every edge of
            // every graph would be a field the client must read to learn nothing.
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
