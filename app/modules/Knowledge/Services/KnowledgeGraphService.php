<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\DTOs\KnowledgeGraph;
use App\Modules\Knowledge\DTOs\KnowledgeGraphEdge;
use App\Modules\Knowledge\DTOs\KnowledgeGraphNode;
use App\Modules\Knowledge\DTOs\KnowledgeGraphQuery;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Assembles the GRAPH view of a base: nodes, the edges between them, and the ghosts they point at.
 *
 * ------------------------------------------------------------------------------------------------
 * TWO MODES, ONE SHAPE
 *
 * EGO — a breadth-first walk out from one entry, to `depth` hops. The walk follows edges in the UNION
 * of both directions, and that is the whole reason the graph is worth drawing: a similarity edge is
 * materialised only on the side that was re-indexed most recently, so A→B may exist while B→A does
 * not. A walk that respected direction would show a reader a different neighbourhood depending on
 * which of two related entries they happened to open — the same relation, present or absent by
 * accident of indexing order. Unioning makes "related" symmetric at the point where a human looks at
 * it, without forcing the linker to write two rows for every pair.
 *
 * TWO KINDS OF LINE, ONE PICTURE. The graph draws derived LINKS (parsed prose, measured similarity)
 * and asserted RELATIONS (typed statements a person approved) in a single `edges` list discriminated
 * by `kind`. The walk follows BOTH, and that is not a detail: an entry connected to its neighbour only
 * by an approved relation would otherwise be missing from that neighbour's graph entirely — the most
 * reliable connection in the base would be the one least likely to be drawn.
 *
 * OVERVIEW — no centre: the base's most CONNECTED entries and the edges among them. Degree is the
 * right ranking here because the question is "what is this base shaped like", and the answer is its
 * hubs. When there are fewer connected entries than the cap allows, the remainder is filled with the
 * most recently updated entries as isolated nodes — a young base has no edges yet, and an empty graph
 * screen would say "you have nothing" when the truth is "you have not linked anything yet".
 *
 * ------------------------------------------------------------------------------------------------
 * THE CAP IS PART OF THE ANSWER
 *
 * `knowledge.graph.max_nodes` bounds every response. When it bites, the neighbours that SURVIVE are
 * the ones nearest the centre, then the ones the walk reached along the most edges, then the ones with
 * the strongest score — i.e. the ones a reader would have picked out anyway. What was dropped is
 * reported in `truncated` rather than silently omitted, because a graph that quietly shows two thirds
 * of a neighbourhood is worse than one that shows a third and says so: the first is a wrong map, the
 * second is a partial one.
 *
 * ------------------------------------------------------------------------------------------------
 * QUERY BUDGET
 *
 * Constant in the number of nodes and edges — never a query per node. Two lookups per BFS level (out
 * and in), then exactly one query each for the entries, the edges among them, and their ghosts. The
 * walk deliberately fetches only the id columns it needs to DISCOVER nodes; the edges that are
 * actually DRAWN come from the single closing query over the final node set, which is what guarantees
 * every edge has both of its endpoints in the response — and picks up the edges BETWEEN two
 * neighbours, which a walk outward from the centre never traverses.
 *
 * No column of the vector table is touched anywhere in this file. The graph is a read of materialised
 * rows; if drawing it needed an embedding it could not be the default view of anything.
 */
class KnowledgeGraphService
{
    /**
     * The ranking weight a typed relation carries where a link carries its cosine score.
     *
     * The maximum, deliberately: a relation is a statement a person approved, so when the node cap
     * forces a choice, an entry the base ASSERTS a relationship with should outrank one a vector merely
     * measured at 0.9. It is used for ordering only — relations are never filtered by `min_score`.
     */
    private const RELATION_SCORE = 1.0;

    public function build(KnowledgeBase $base, KnowledgeGraphQuery $query): KnowledgeGraph
    {
        // The CENTRE must be an entry of THIS base. Resolved through the base's own relation — a
        // foreign (or trashed) id is simply not found, which surfaces as a 404 — rather than by plain
        // model binding, which would happily centre the walk on an entry from somewhere else and
        // answer a question about a different base with a graph of one lonely node.
        if ($query->centerId !== null) {
            $base->entries()->whereKey($query->centerId)->firstOrFail();
        }

        [$candidates, $stats, $discoveredEdges, $hiddenBaseline] = $query->centerId === null
            ? $this->overviewCandidates($base, $query)
            : $this->egoCandidates($base, $query);

        if ($candidates === []) {
            return KnowledgeGraph::empty($query->centerId);
        }

        $cap = max(1, (int) config('knowledge.graph.max_nodes'));
        $kept = $this->truncate($candidates, $stats, $query->centerId, $cap);

        $entries = KnowledgeEntry::query()
            ->whereKey($kept)
            ->where('knowledge_base_id', $base->getKey())
            ->get()
            ->keyBy('id');

        // A link may still point at an entry that has since been trashed: a soft delete deliberately
        // leaves the edge alone so a restore brings the relation back whole. Those ids simply do not
        // come back from the query above, and dropping them here is what keeps the edge invariant
        // (both endpoints present) true.
        $ids = array_values(array_filter($kept, fn (string $id): bool => $entries->has($id)));

        $edges = $this->edgesAmong($base, $query, $ids);
        $ghosts = $this->ghosts($base, $query, $ids);

        return new KnowledgeGraph(
            nodes: $this->nodes($entries, $ids, $stats, $edges),
            edges: $edges->all(),
            ghosts: $ghosts,
            hiddenNodes: max(0, $hiddenBaseline - count($ids)),
            hiddenEdges: $this->hiddenEdges($discoveredEdges, $ids),
            centerId: $query->centerId,
        );
    }

    // ---- discovery ------------------------------------------------------------

    /**
     * Walk out from the centre. Returns the candidate ids, per-candidate ranking stats, the edges the
     * walk saw, and how many nodes an uncapped answer would have held.
     *
     * @return array{0: array<int, string>, 1: array<string, array{distance: int, links: int, score: float}>, 2: array<int, array{0: string, 1: string}>, 3: int}
     */
    private function egoCandidates(KnowledgeBase $base, KnowledgeGraphQuery $query): array
    {
        $center = (string) $query->centerId;

        $stats = [$center => ['distance' => 0, 'links' => 0, 'score' => 1.0]];
        $frontier = [$center];
        $discovered = [];

        for ($distance = 1; $distance <= $query->depth && $frontier !== []; $distance++) {
            $next = [];

            foreach (['from_entry_id' => 'to_entry_id', 'to_entry_id' => 'from_entry_id'] as $anchor => $far) {
                // BOTH TABLES are walked. A neighbour joined only by an approved relation is otherwise
                // invisible from this entry's graph, which would make the base's most reliable
                // connections the ones least likely to be drawn.
                $rows = $this->edges($base, $query)
                    ->select(['from_entry_id', 'to_entry_id', 'score'])
                    ->whereIn($anchor, $frontier)
                    ->whereNotNull('to_entry_id')
                    ->get()
                    ->concat($this->relationRows($base, $query, $anchor, $frontier));

                foreach ($rows as $row) {
                    $id = (string) $row->getAttribute($far);

                    if ($id === '' || $id === $center) {
                        continue;
                    }

                    $discovered[] = [(string) $row->from_entry_id, (string) $row->to_entry_id];

                    if (!isset($stats[$id])) {
                        $stats[$id] = ['distance' => $distance, 'links' => 0, 'score' => 0.0];
                        $next[] = $id;
                    }

                    $stats[$id]['links']++;
                    $stats[$id]['score'] = max($stats[$id]['score'], (float) $row->score);
                }
            }

            // Only the best of this level are expanded further. Without this bound the second level's
            // `whereIn` would carry every neighbour of a hub — a base with one heavily linked entry
            // would turn a bounded response into an unbounded query, and the extra nodes would be
            // discarded by the cap moments later anyway. Ranked by how many edges reached them, so
            // what IS expanded is the part of the neighbourhood a reader would have looked at.
            $frontier = $this->strongest($next, $stats, max(1, (int) config('knowledge.graph.max_nodes')));
        }

        return [array_keys($stats), $stats, $discovered, count($stats)];
    }

    /**
     * The `$limit` best-connected of `$ids`, or all of them when they already fit.
     *
     * @param  array<int, string>  $ids
     * @param  array<string, array{distance: int|null, links: int, score: float}>  $stats
     * @return array<int, string>
     */
    private function strongest(array $ids, array $stats, int $limit): array
    {
        if (count($ids) <= $limit) {
            return $ids;
        }

        usort($ids, static fn (string $a, string $b): int => [-$stats[$a]['links'], -$stats[$a]['score'], $a]
            <=> [-$stats[$b]['links'], -$stats[$b]['score'], $b]);

        return array_slice($ids, 0, $limit);
    }

    /**
     * The base's hubs, then its most recent entries to fill the frame.
     *
     * @return array{0: array<int, string>, 1: array<string, array{distance: int|null, links: int, score: float}>, 2: array<int, array{0: string, 1: string}>, 3: int}
     */
    private function overviewCandidates(KnowledgeBase $base, KnowledgeGraphQuery $query): array
    {
        $stats = [];

        foreach (['from_entry_id', 'to_entry_id'] as $column) {
            $rows = $this->edges($base, $query)
                ->select($column)
                ->selectRaw('count(*) as edge_count')
                ->selectRaw('max(score) as best_score')
                ->whereNotNull('to_entry_id')
                ->groupBy($column)
                ->get();

            // Relations count towards a base's HUBS too — an entry everything is related to is exactly
            // what "what is this base shaped like" is asking about.
            $relations = $this->relations($base, $query)
                ?->select($column)
                ->selectRaw('count(*) as edge_count')
                ->groupBy($column)
                ->get();

            foreach ($rows as $row) {
                $id = (string) $row->getAttribute($column);
                $stats[$id] ??= ['distance' => null, 'links' => 0, 'score' => 0.0];
                $stats[$id]['links'] += (int) $row->getAttribute('edge_count');
                $stats[$id]['score'] = max($stats[$id]['score'], (float) $row->getAttribute('best_score'));
            }

            foreach ($relations ?? [] as $row) {
                $id = (string) $row->getAttribute($column);
                $stats[$id] ??= ['distance' => null, 'links' => 0, 'score' => 0.0];
                $stats[$id]['links'] += (int) $row->getAttribute('edge_count');
                $stats[$id]['score'] = max($stats[$id]['score'], self::RELATION_SCORE);
            }
        }

        $cap = max(1, (int) config('knowledge.graph.max_nodes'));

        // The uncapped answer for an overview is THE WHOLE BASE — that is what the mode claims to
        // show — so the honest baseline for `truncated` is the entry count, not the number of hubs.
        $total = KnowledgeEntry::query()->where('knowledge_base_id', $base->getKey())->count();

        if (count($stats) < $cap) {
            $filler = KnowledgeEntry::query()
                ->where('knowledge_base_id', $base->getKey())
                ->when($stats !== [], fn ($builder) => $builder->whereKeyNot(array_keys($stats)))
                ->orderByDesc('updated_at')
                ->orderBy('id')
                ->limit($cap - count($stats))
                ->pluck('id');

            foreach ($filler as $id) {
                $stats[(string) $id] = ['distance' => null, 'links' => 0, 'score' => 0.0];
            }
        }

        return [array_keys($stats), $stats, [], $total];
    }

    // ---- shaping --------------------------------------------------------------

    /**
     * Keep the centre, then the best `cap - 1` candidates: nearest first, then the ones the walk
     * reached along the most edges, then the strongest score, then the id so the result is total.
     *
     * @param  array<int, string>  $candidates
     * @param  array<string, array{distance: int|null, links: int, score: float}>  $stats
     * @return array<int, string>
     */
    private function truncate(array $candidates, array $stats, ?string $centerId, int $cap): array
    {
        if (count($candidates) <= $cap) {
            return $candidates;
        }

        $rest = array_values(array_filter($candidates, static fn (string $id): bool => $id !== $centerId));

        usort($rest, static function (string $a, string $b) use ($stats): int {
            return [$stats[$a]['distance'] ?? PHP_INT_MAX, -$stats[$a]['links'], -$stats[$a]['score'], $a]
                <=> [$stats[$b]['distance'] ?? PHP_INT_MAX, -$stats[$b]['links'], -$stats[$b]['score'], $b];
        });

        $kept = $centerId === null ? [] : [$centerId];

        return array_merge($kept, array_slice($rest, 0, $cap - count($kept)));
    }

    /**
     * Every qualifying edge — link OR relation — whose BOTH endpoints are in the response.
     *
     * Both are fetched with `whereIn`/`whereIn` over the FINAL node set, which is the mechanism that
     * makes the "no dangling end" invariant hold. It is stated once here for both kinds rather than
     * being re-derived per table, so a future third kind of line cannot quietly get it wrong.
     *
     * @param  array<int, string>  $ids
     * @return Collection<int, KnowledgeGraphEdge>
     */
    private function edgesAmong(KnowledgeBase $base, KnowledgeGraphQuery $query, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $links = $this->edges($base, $query)
            ->whereIn('from_entry_id', $ids)
            ->whereIn('to_entry_id', $ids)
            ->orderBy('id')
            ->get()
            ->map(static fn (KnowledgeLink $link): KnowledgeGraphEdge => KnowledgeGraphEdge::fromLink($link));

        $relations = $this->relations($base, $query)
            ?->whereIn('from_entry_id', $ids)
            ->whereIn('to_entry_id', $ids)
            ->orderBy('id')
            ->get()
            ->map(static fn (KnowledgeRelation $relation): KnowledgeGraphEdge => KnowledgeGraphEdge::fromRelation($relation));

        return $links->concat($relations ?? []);
    }

    /**
     * Unresolved wikilinks drawn BY the nodes on screen, aggregated by the slug that was meant.
     *
     * Grouped in PHP over one query rather than in SQL: the input is bounded by the node cap, and a
     * `group by` with an aggregate over the source ids would be a driver-specific expression in
     * exchange for nothing measurable.
     *
     * @param  array<int, string>  $ids
     * @return array<int, array{target_slug: string, from_ids: array<int, string>, count: int}>
     */
    private function ghosts(KnowledgeBase $base, KnowledgeGraphQuery $query, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->edges($base, $query)
            ->select(['from_entry_id', 'target_slug'])
            ->whereNull('to_entry_id')
            ->whereIn('from_entry_id', $ids)
            ->orderBy('target_slug')
            ->get();

        $ghosts = [];

        foreach ($rows as $row) {
            $slug = (string) $row->target_slug;
            $ghosts[$slug]['target_slug'] = $slug;
            $ghosts[$slug]['from_ids'][] = (string) $row->from_entry_id;
        }

        return array_values(array_map(
            static fn (array $ghost): array => [
                'target_slug' => $ghost['target_slug'],
                'from_ids' => array_values(array_unique($ghost['from_ids'])),
                'count' => count(array_unique($ghost['from_ids'])),
            ],
            $ghosts,
        ));
    }

    /**
     * @param  Collection<string, KnowledgeEntry>  $entries
     * @param  array<int, string>  $ids
     * @param  array<string, array{distance: int|null, links: int, score: float}>  $stats
     * @param  Collection<int, KnowledgeGraphEdge>  $edges
     * @return array<int, KnowledgeGraphNode>
     */
    private function nodes(Collection $entries, array $ids, array $stats, Collection $edges): array
    {
        $degree = [];

        // Counted off the unified edge, so a node's degree matches the lines actually drawn around it
        // whichever table each came from.
        foreach ($edges as $edge) {
            $degree[$edge->fromId] = ($degree[$edge->fromId] ?? 0) + 1;
            $degree[$edge->toId] = ($degree[$edge->toId] ?? 0) + 1;
        }

        return array_map(
            fn (string $id): KnowledgeGraphNode => new KnowledgeGraphNode(
                entry: $entries->get($id),
                distance: $stats[$id]['distance'] ?? null,
                degree: $degree[$id] ?? 0,
            ),
            $ids,
        );
    }

    /**
     * How many edges the walk found but could not draw. Counted from what was DISCOVERED, so it says
     * "the cap cost you this much of what I was looking at" — a claim this response can actually
     * stand behind, unlike a total over the whole base that nothing here measured.
     *
     * @param  array<int, array{0: string, 1: string}>  $discovered
     * @param  array<int, string>  $ids
     */
    private function hiddenEdges(array $discovered, array $ids): int
    {
        if ($discovered === []) {
            return 0;
        }

        $kept = array_flip($ids);
        $hidden = [];

        foreach ($discovered as [$from, $to]) {
            if (!isset($kept[$from]) || !isset($kept[$to])) {
                $hidden[$from . '|' . $to] = true;
            }
        }

        return count($hidden);
    }

    // ---- the one filtered edge query everything reuses -------------------------

    /**
     * The base's edges, filtered exactly the way the caller asked — the single definition of "an edge
     * this response is allowed to see", so the walk, the closing query and the ghost lookup can never
     * disagree about the answer.
     *
     * `min_score` is applied ONLY to edges that carry one. A wikilink and a manual edge have no score
     * because the notion does not apply to them (one is what the text says, the other is what a human
     * drew), and letting a numeric floor delete them would mean a reader tightening the similarity
     * filter silently lost the links they wrote by hand.
     */
    private function edges(KnowledgeBase $base, KnowledgeGraphQuery $query): Builder
    {
        return KnowledgeLink::query()
            ->where('knowledge_base_id', $base->getKey())
            ->whereIn('source', $query->sources)
            ->when(!$query->includeDismissed, fn (Builder $builder) => $builder->whereNull('dismissed_at'))
            ->where(fn (Builder $builder) => $builder
                ->whereNull('score')
                ->orWhere('score', '>=', $query->minScore));
    }

    /**
     * The base's TYPED RELATIONS, filtered the way the caller asked — or NULL when they were switched
     * off, which is what lets every call site skip the query entirely rather than run one it will
     * discard.
     *
     * `min_score` is deliberately NOT applied. A relation has no score because the notion does not
     * apply to it: it is a statement somebody approved, not a measurement, and letting a numeric
     * similarity filter delete it would mean tightening the suggestion threshold silently erased the
     * base's facts. That is the same rule wikilinks and manual edges already live by.
     */
    private function relations(KnowledgeBase $base, KnowledgeGraphQuery $query): ?Builder
    {
        if (!$query->relations) {
            return null;
        }

        return KnowledgeRelation::query()
            ->where('knowledge_base_id', $base->getKey())
            ->when(!$query->includeHistorical, fn (Builder $builder) => $builder->current());
    }

    /**
     * Relation rows shaped like the link rows the walk consumes, so one loop handles both.
     *
     * `score` is synthesised as {@see RELATION_SCORE} purely for RANKING, never for filtering — see
     * {@see relations()}. It is the maximum because when the node cap bites, the neighbours worth
     * keeping are the ones the base actually asserts a relationship with, not the ones a vector
     * happened to score at 0.9.
     *
     * @param  array<int, string>  $frontier
     * @return Collection<int, KnowledgeRelation>
     */
    private function relationRows(KnowledgeBase $base, KnowledgeGraphQuery $query, string $anchor, array $frontier): Collection
    {
        $builder = $this->relations($base, $query);

        if ($builder === null) {
            return new Collection;
        }

        return $builder
            ->select(['from_entry_id', 'to_entry_id'])
            ->selectRaw('? as score', [self::RELATION_SCORE])
            ->whereIn($anchor, $frontier)
            ->get();
    }
}
