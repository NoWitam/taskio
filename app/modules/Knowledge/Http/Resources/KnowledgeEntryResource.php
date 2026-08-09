<?php

namespace App\Modules\Knowledge\Http\Resources;

use App\Http\Resources\CreatorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A KNOWLEDGE ENTRY in full — the editor's payload.
 *
 * `current_revision_id` is not decoration: it is the OPTIMISTIC-LOCK token. A client reads it here
 * and sends it back as `expected_revision_id`, which is how a concurrent save is turned into a 409
 * the user can act on instead of a silent overwrite.
 *
 * The indexing fields are reported as a small `index` object rather than flattened, so a UI can show
 * "indexed / needs re-indexing" as one concept. `needs_indexing` is derived from the digest PAIR (not
 * from the status), which is what stops a worker that died holding `indexing` from making a stale
 * entry look current.
 *
 * `links` / `backlinks` appear only when eager-loaded (the single-entry view), never on a list.
 *
 * AND NEVER ON A DRAFT — the keys are ABSENT there, deliberately, because there is nothing to load. A
 * draft draws no edges at all: {@see \App\Modules\Knowledge\Services\KnowledgeLinkService} returns
 * early for one, since links out of an invisible node would be rows nobody can follow, and a draft
 * adopting a ghost would silently resolve another entry's red link to something the reader cannot
 * open. The link pass runs at ACCEPTANCE, when every endpoint is real.
 *
 * Eager-loading them onto the review cards would therefore buy a guaranteed-empty relation and — the
 * real cost — turn "not computed yet" into an authoritative empty list. A reviewer shown "no links"
 * would conclude the proposal connects to nothing, when the truth is that its connections are drawn
 * the moment they accept it. An absent key says that; `[]` would say the opposite.
 */
class KnowledgeEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'knowledge_base_id' => $this->knowledge_base_id,
            'title' => $this->title,
            // Stable handle: derived from the first title, never from later ones.
            'slug' => $this->slug,
            'content' => $this->content,
            'metadata' => $this->metadata ?? [],
            // Other surface forms this entry is called by. Feeds the MENTION layer only — a
            // `[[wikilink]]` still resolves by slug, so an alias is never an address.
            'aliases' => $this->aliases ?? [],
            // WHAT KIND of thing this entry is about, or null where nobody has said. Feeds the typed
            // relation matrix — and only that; nothing else in the module reads it.
            'entry_type' => $this->entry_type?->value,

            'status' => $this->status?->value,
            'stale_at' => $this->stale_at?->toISOString(),
            'is_stale' => $this->isStale(),
            'position' => $this->position,

            // The optimistic-lock token — echo it back as `expected_revision_id`.
            'current_revision_id' => $this->current_revision_id,

            // ON A DRAFT THIS WHOLE OBJECT IS EMPTY, and correctly so: a draft is never indexed — it is
            // a proposal, and embedding text nobody has accepted would be a spend on something that may
            // be thrown away, as well as a passage the search could return from an invisible entry.
            // Indexing is queued by the observer at ACCEPTANCE. A client must not read `status: null`
            // on a review card as "indexing failed"; it means "not applicable yet".
            'index' => [
                'status' => $this->index_status?->value,
                'chunks_count' => $this->chunks_count,
                // The NUMERATOR for `chunks_count`: passages carrying a vector from the CURRENT
                // embedding model, so a `partial` entry can be shown as "7 of 8" instead of as an
                // unquantified warning. Null where the connection has no vector support — which is
                // "cannot be counted here", not zero.
                'indexed_chunks_count' => $this->indexedChunksCount(),
                'needs_indexing' => $this->needsIndexing(),
                // Whether the retry endpoint would accept this entry right now (failed /
                // pending_budget / partial). A capability flag, mirroring the `can_be_*` convention:
                // the client hides the affordance, the FormRequest + service still enforce it. Read
                // from the SAME enum predicate the service refuses on, so the flag can never promise an
                // action that then 422s.
                'can_retry' => $this->index_status?->isRetryable() ?? false,
            ],

            // DRAFT-ONLY (B11a/B11b), null on every ordinary entry. `targets_entry` present means this
            // is a SHADOW: a proposed amendment to that entry rather than a new one, so the reviewer
            // reads it as a diff. `target_revision_stale` says the target moved since the composer read
            // it — surfaced BEFORE acceptance so the badge appears instead of a conflict after the
            // click.
            'draft_session_id' => $this->draft_session_id,
            'targets_entry' => $this->when($this->isShadow(), fn (): ?array => $this->targetsEntry === null ? null : [
                'id' => $this->targetsEntry->id,
                'title' => $this->targetsEntry->title,
                'slug' => $this->targetsEntry->slug,
                'current_revision_id' => $this->targetsEntry->current_revision_id,
            ]),
            'target_revision_id' => $this->when($this->isShadow(), fn () => $this->target_revision_id),
            'target_revision_stale' => $this->when($this->isShadow(), fn (): bool => $this->targetRevisionIsStale()),

            // HOW this proposal changes its target, and WHAT THE RESULT WOULD BE.
            //
            // `content` on an APPEND shadow is the ADDITION alone — that is what keeps the proposal
            // commutative with a concurrent edit (see the amend_mode migration). Rendering it as the
            // card's body tells a reviewer that the whole entry is about to be replaced by one
            // sentence, which is the opposite of what accepting it does. `amended_body` answers the
            // only question the card is really asking: what will this entry SAY afterwards?
            //
            // Composed from the target's LIVE text at read time, deliberately — the same rule the
            // publish path follows — so the card cannot go stale against somebody else's edit and then
            // disagree with what acceptance actually produces.
            'amend_mode' => $this->when($this->isShadow(), fn () => $this->amend_mode),
            'amend_section' => $this->when($this->isShadow(), fn () => $this->amend_section),
            'amended_body' => $this->when($this->isShadow(), fn (): string => $this->amendedBody()),

            'links' => KnowledgeLinkResource::collection($this->whenLoaded('outgoingLinks')),
            'backlinks' => KnowledgeLinkResource::collection($this->whenLoaded('incomingLinks')),

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            // `is_owner` STAYS — it says who this entry came from, which a reader wants to know and
            // which the creator column answers whether or not anybody may edit anything.
            //
            // `can_be_edited`, `can_be_deleted` and `can_be_purged` are GONE. An entry is written by
            // the composer and published by an acceptance; no person may edit, trash or purge one, so
            // those flags were false for everybody, always.
            'is_owner' => $this->isOwnedBy($request->user()),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
