<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * The assembled GRAPH: the nodes a response draws, the edges between exactly those nodes, the ghost
 * links they point at, and an honest account of what the cap left out.
 *
 * `$edges` holds BOTH kinds of line — derived links and asserted relations — as
 * {@see KnowledgeGraphEdge}, each carrying its own `kind`. They are one list rather than two because
 * the renderer draws them on one canvas: two arrays would only push the merge into every consumer that
 * lays out, hit-tests or legends the picture.
 *
 * `$edges` is guaranteed to reference only ids present in `$nodes`. That invariant is why the edges
 * are collected AFTER the node set is final rather than during the walk: a renderer handed an edge to
 * a node it was never given has no good option — it either drops it silently (and the picture lies) or
 * invents a placeholder (and the picture lies differently). It holds for relations for exactly the
 * same reason and by exactly the same mechanism.
 *
 * GHOSTS are not edges and are deliberately a third list. A ghost has no target node to draw to; it is
 * a statement about something MISSING, aggregated by the slug that was meant so the base can say
 * "four entries reference `polityka-zwrotow`, and it does not exist" in one row instead of four
 * dangling half-edges.
 */
final class KnowledgeGraph
{
    /**
     * @param  array<int, KnowledgeGraphNode>  $nodes
     * @param  array<int, KnowledgeGraphEdge>  $edges
     * @param  array<int, array{target_slug: string, from_ids: array<int, string>, count: int}>  $ghosts
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $edges,
        public readonly array $ghosts,
        /** Nodes this response would have drawn without the cap. */
        public readonly int $hiddenNodes,
        /** Edges the walk found but could not draw, because an endpoint was cut. */
        public readonly int $hiddenEdges,
        public readonly ?string $centerId,
    ) {}

    public static function empty(?string $centerId = null): self
    {
        return new self([], [], [], 0, 0, $centerId);
    }
}
