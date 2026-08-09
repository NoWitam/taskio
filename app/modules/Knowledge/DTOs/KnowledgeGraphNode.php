<?php

namespace App\Modules\Knowledge\DTOs;

use App\Modules\Knowledge\Models\KnowledgeEntry;

/**
 * One node of a graph response, carrying the two facts that are true OF THIS RESPONSE rather than of
 * the entry itself.
 *
 * `$distance` is hops from the centre — 0 for the centre, null in the overview, which has none.
 *
 * `$degree` counts the edges present in THIS response, not the entry's degree in the base. The
 * distinction is deliberate: a node drawn with three edges but labelled "degree 19" is a node the
 * reader cannot trust, and the label's job is to explain the picture in front of them. What the cap
 * removed is reported once, for the whole graph, where it can be stated as a number instead of
 * implied by an inconsistency.
 */
final class KnowledgeGraphNode
{
    public function __construct(
        public readonly KnowledgeEntry $entry,
        public readonly ?int $distance,
        public readonly int $degree,
    ) {}
}
