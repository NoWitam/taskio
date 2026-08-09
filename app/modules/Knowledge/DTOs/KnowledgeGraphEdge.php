<?php

namespace App\Modules\Knowledge\DTOs;

use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Models\KnowledgeRelation;

/**
 * ONE LINE ON THE CANVAS, whichever of the two tables it came from.
 *
 * The graph now draws two genuinely different things and must not pretend they are one:
 *
 *   a LINK      is DERIVED — parsed from prose, or measured between two vectors. Nobody asserted it;
 *               it is the machine's reading of what is written, rebuilt on every save, and dismissible
 *               precisely because it is a guess.
 *   a RELATION  is ASSERTED — a typed statement a person approved. It carries a verb, a validity
 *               period and a lifecycle, and no sweep may remove it.
 *
 * `kind` is the DISCRIMINATOR the client renders from, and it is on the wire rather than inferred
 * because every alternative is worse: inferring from a null `source` makes a renderer depend on a
 * field's absence, and two separate arrays would force every consumer (layout, hit-testing, the
 * legend) to merge them again at the point of drawing.
 *
 * `fromId`/`toId` are lifted out of both models so the traversal, the degree count and the "both
 * endpoints are present" invariant can be written ONCE instead of branching on the type at every step.
 */
final class KnowledgeGraphEdge
{
    public const KIND_LINK = 'link';

    public const KIND_RELATION = 'relation';

    private function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $fromId,
        public readonly string $toId,
        public readonly ?KnowledgeLink $link,
        public readonly ?KnowledgeRelation $relation,
    ) {}

    public static function fromLink(KnowledgeLink $link): self
    {
        return new self(
            kind: self::KIND_LINK,
            id: (string) $link->getKey(),
            fromId: (string) $link->from_entry_id,
            toId: (string) $link->to_entry_id,
            link: $link,
            relation: null,
        );
    }

    public static function fromRelation(KnowledgeRelation $relation): self
    {
        return new self(
            kind: self::KIND_RELATION,
            id: (string) $relation->getKey(),
            fromId: (string) $relation->from_entry_id,
            toId: (string) $relation->to_entry_id,
            link: null,
            relation: $relation,
        );
    }
}
