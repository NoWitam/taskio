<?php

namespace App\Modules\Knowledge\Http\Resources;

use App\Http\Resources\CreatorResource;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Support\RelationVocabulary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A KNOWLEDGE BASE: its identity, its charter, the metadata schema its entries are validated against,
 * and the capability flags the UI hides actions behind (mirrors the Workflow/Bot/Constant convention).
 *
 * `can_be_managed` is the one worth reading twice: it is a DIFFERENT ability from `can_be_edited`,
 * because editing a name and rewriting the schema every entry is validated against are different
 * kinds of act. A UI that collapses them into one "edit" button will produce 403s it cannot explain.
 *
 * ------------------------------------------------------------------------------------------------
 * THE CARD CONTRACT (B2b). Three fields exist so a base card can be rendered from the LIST response
 * alone, with no follow-up request per base:
 *
 *   entries_count      live entries in the base.
 *   ghost_links_count  unresolved `[[wikilinks]]` — the red-link chip. Dismissed ones and ones drawn
 *                      by a trashed entry are excluded, because the chip is a call to action and
 *                      neither of those is actionable. Render it ONLY when it is greater than zero:
 *                      a permanent "0 red links" is noise, a number that appears is a signal.
 *   index_summary      `{total, indexed, pending, indexing, partial, pending_budget, failed}` — the
 *                      index badge. The unit at BASE level is ENTRIES (at entry level, `index` counts
 *                      chunks); `total` equals `entries_count`, so a badge can be expressed as a
 *                      fraction without a second number. `pending_budget` is deliberately its own
 *                      bucket rather than folded into `failed`: nothing is broken and no retry helps
 *                      — raising the workspace's AI cap does — and a UI that says "failed" sends
 *                      somebody hunting a bug that does not exist.
 *
 * All three are ALWAYS present. They are filled by
 * {@see \App\Modules\Knowledge\Services\KnowledgeBaseService::attachAggregates()}, which every
 * controller path calls — including store and update, so a freshly created base carries the same
 * shape as a listed one and a client never has two cases to handle.
 */
class KnowledgeBaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'charter' => $this->charter,
            'language' => $this->language,

            // The ordered {key, label, descriptor} list — the authority an entry's metadata form is
            // rendered from and validated against.
            'metadata_schema' => $this->metadata_schema ?? [],

            // The relation verbs this base allows, RESOLVED — null (never configured) is answered as
            // the whole vocabulary rather than echoed back, so a client renders one picker from one
            // field instead of re-implementing "null means everything" itself.
            'relation_types' => RelationVocabulary::idsFor($this->resource),
            // The vocabulary those ids index into: labels, both readings, symmetry, allowed property
            // keys, and the entry-type matrix. Sent WITH the base so a relation editor needs no second
            // request — and so a client can never disagree with the server about what a verb accepts.
            'relation_vocabulary' => KnowledgeRelationType::catalog(),

            'entries_count' => $this->whenCounted('entries'),

            // The base card's two aggregates — see the class docblock for what a UI does with them.
            'ghost_links_count' => (int) ($this->ghost_links_count ?? 0),
            'index_summary' => $this->index_summary ?? [],

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            'is_owner' => $this->isOwnedBy($request->user()),
            'can_be_edited' => $request->user()?->can('update', $this->resource) ?? false,
            // Governance: the charter + the metadata schema (base creator or workspace owner).
            'can_be_managed' => $request->user()?->can('manage', $this->resource) ?? false,
            'can_be_deleted' => $request->user()?->can('delete', $this->resource) ?? false,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
