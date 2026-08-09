<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * ONE typed relation of a resolved entity, as the frozen state carries it.
 *
 * `$handle` (`R<n>`) is unique across the WHOLE set, not per entity, so a later stage can name a single
 * edge unambiguously ("end R7") without also having to say which entity it belongs to. ONE ROW GETS ONE
 * HANDLE even when both of its ends are in the set — see {@see \App\Modules\Knowledge\Services\KnowledgeEntityResolutionService}.
 *
 * `$id` IS THE ROW THE HANDLE NAMES, and it is what makes "end R7" land on the right edge.
 *
 * It used to be absent, on the theory that keeping a database address out of a jsonb column the model
 * can see was worth re-deriving the row later. Re-derivation was the bug: the applier looked the handle
 * up by TYPE and took the lowest id, so a base with two active relations of one type ended the wrong
 * one — and `superseded_by_id` then bound the wrong history together. An identifier that has to be
 * guessed is not an identifier.
 *
 * The model never sees it: {@see \App\Modules\Knowledge\Services\KnowledgeDraftService::knownEntities()}
 * renders this set field by explicit field (handle, label, other title, dates, properties, description)
 * and nothing dumps the array wholesale. The set already carried `current_revision_id` on the same
 * principle. The id is re-CHECKED at apply time — still current, still this base — so a stale or
 * cross-base id resolves to nothing rather than to a write.
 *
 * `$direction` is the load-bearing field, and it is why this is not just the relation's own columns. A
 * relation is read from ONE end here, and "Anna is a member of Acme" and "Acme has member Anna" are the
 * same row seen from two sides. Storing the direction plus the already-translated `label` means the
 * consumer never has to work out which end it is standing on — a calculation that is easy to get
 * backwards and produces a graph that is confidently wrong rather than obviously broken.
 *
 * `$otherHandle` is the far end's handle WHEN that entity is also in this set, and null otherwise —
 * in which case `$otherTitle` is all there is. That asymmetry is deliberate: pulling every neighbour
 * into the set to give it a handle would expand a two-entity resolution into a subgraph, and the far
 * end of a relation is context, not a subject of this session.
 */
final readonly class ResolvedRelation
{
    public const DIRECTION_OUT = 'out';

    public const DIRECTION_IN = 'in';

    /** @param  array<string, mixed>  $properties */
    public function __construct(
        public string $handle,
        /** The relation row this handle names. Re-checked (current, same base) before any write. */
        public string $id,
        public string $type,
        /** The verb already read in this direction, translated. */
        public string $label,
        public string $direction,
        public ?string $otherHandle,
        public string $otherTitle,
        public ?string $description,
        public array $properties,
        public ?string $validFrom,
        public ?string $validTo,
        public string $state,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'handle' => $this->handle,
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label,
            'direction' => $this->direction,
            'other_handle' => $this->otherHandle,
            'other_title' => $this->otherTitle,
            'description' => $this->description,
            'properties' => $this->properties,
            'valid_from' => $this->validFrom,
            'valid_to' => $this->validTo,
            'state' => $this->state,
        ];
    }
}
