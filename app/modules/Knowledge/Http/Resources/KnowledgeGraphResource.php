<?php

namespace App\Modules\Knowledge\Http\Resources;

use App\Modules\Knowledge\DTOs\KnowledgeGraph;
use App\Modules\Knowledge\DTOs\KnowledgeGraphEdge;
use App\Modules\Knowledge\DTOs\KnowledgeGraphNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The GRAPH response: four lists and a receipt.
 *
 * ```
 * nodes[]   { id, slug, title, entry_type, status, is_stale, degree, distance }
 * edges[]   { id, kind, from, to,
 *             source, score, evidence, dismissed, can_be_dismissed,      ← kind=link
 *             relation_type, label, inverse_label, symmetric,            ← kind=relation
 *             description, properties, valid_from, valid_to, state, origin }
 * ghosts[]  { target_slug, from_ids[], count }
 * truncated { hidden_nodes, hidden_edges }
 * center    "<uuid>" | null          ← a bare id, not an object
 * ```
 *
 * `edges[].kind` is the DISCRIMINATOR, and every field of the other kind is present-and-null rather
 * than absent. That costs a few bytes and buys a client that never has to test for key existence
 * before reading a value — the shape of a row does not change with its kind, only its contents do.
 *
 * A `link` is DERIVED (the machine's reading of the text, rebuilt on every save, dismissible because it
 * is a guess). A `relation` is ASSERTED (a typed statement a person approved, which no sweep may
 * remove). Drawing them identically would be the single most misleading thing this response could do,
 * which is why the discriminator is first-class rather than inferred from a null `source`.
 *
 * Nodes are DELIBERATELY minimal — no content, no excerpt, no metadata. A graph of sixty entries that
 * carried bodies would be a megabyte to draw sixty circles, and the moment a node is selected the
 * client has an entry id and the entry endpoint. What a node does carry is what changes how it is
 * DRAWN: `status` and `is_stale` are visual states, `degree` sizes it, `distance` rings it.
 *
 * `degree` counts edges present in THIS response, and `distance` is hops from the centre (0 for the
 * centre itself, null in the overview, which has none). Both describe the picture that was returned
 * rather than the base — a node labelled with a degree it does not visibly have is a node the reader
 * stops believing.
 *
 * Every `edges[].from` and `.to` is guaranteed to be an id present in `nodes[]`. The graph is
 * assembled so that this holds, so a renderer never has to decide what to do with a dangling end.
 *
 * `ghosts[]` are NOT edges: a ghost has no target to draw to. It is the base saying "four entries
 * reference `polityka-zwrotow` and it does not exist", aggregated by the slug that was meant, with the
 * ids that meant it — enough to draw the red-link affordance and to offer "create this entry".
 *
 * `edges[].dismissed` is always present and is false unless `?include_dismissed=1` was passed; without
 * that flag dismissed edges are not in the response at all. Two states rather than one so the flag can
 * drive an "undo" affordance instead of the client inferring rejection from absence.
 *
 * `truncated` is the receipt for the node cap: `hidden_nodes` is what an uncapped answer would have
 * added (in the overview, that means the rest of the base), `hidden_edges` is what the walk found but
 * could not draw because an endpoint was cut. Reported rather than hidden — a graph that quietly shows
 * two thirds of a neighbourhood is a wrong map; one that shows a third and says so is a partial one.
 */
class KnowledgeGraphResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var KnowledgeGraph $graph */
        $graph = $this->resource;

        return [
            'center' => $graph->centerId,

            'nodes' => array_map(
                static fn (KnowledgeGraphNode $node): array => [
                    'id' => $node->entry->id,
                    'slug' => $node->entry->slug,
                    'title' => $node->entry->title,
                    // WHAT KIND of thing the node is, or null where nobody has said. It changes how the
                    // node is DRAWN (a person and a place are not the same shape), which is the same
                    // test `status` and `is_stale` pass to be here at all.
                    'entry_type' => $node->entry->entry_type?->value,
                    'status' => $node->entry->status?->value,
                    'is_stale' => $node->entry->isStale(),
                    'degree' => $node->degree,
                    'distance' => $node->distance,
                ],
                $graph->nodes,
            ),

            'edges' => array_map(
                static fn (KnowledgeGraphEdge $edge): array => [
                    'id' => $edge->id,
                    // THE DISCRIMINATOR — `link` (derived) or `relation` (asserted).
                    'kind' => $edge->kind,
                    'from' => $edge->fromId,
                    'to' => $edge->toId,

                    // --- kind=link; null on a relation ------------------------------------------
                    'source' => $edge->link?->source?->value,
                    // Similarity edges only; a wikilink has no score and a manual one has no evidence.
                    'score' => $edge->link?->score,
                    'evidence' => $edge->link?->evidence,
                    'dismissed' => $edge->link !== null && $edge->link->dismissed_at !== null,
                    // Whether "no, these are not related" is an available answer. It was on
                    // KnowledgeLinkResource from the start but MISSING here, so a client rendering the
                    // graph had to re-derive the rule from `source` — and a helper that duplicates a
                    // server rule is a helper that goes stale the first time a new machine-derived kind
                    // is added. Read from the same enum predicate the dismiss endpoint refuses on.
                    //
                    // Always FALSE for a relation, and not by omission: a relation was asserted rather
                    // than proposed, so "these are not related" is not the available answer — ENDING it
                    // (it stopped being true) or RETRACTING it (it never was) are, and both are
                    // different claims with their own endpoint.
                    'can_be_dismissed' => $edge->link?->source?->isDismissable() ?? false,

                    // --- kind=relation; null on a link ------------------------------------------
                    'relation_type' => $edge->relation?->relation_type?->value,
                    // Both readings of the statement, so a panel anchored on either end words it
                    // correctly without re-implementing the grammar.
                    'label' => $edge->relation?->relation_type?->label(),
                    'inverse_label' => $edge->relation?->relation_type?->inverseLabel(),
                    // The stored direction of a symmetric verb is CANONICAL, not editorial — draw it
                    // undirected rather than picking an arrow out of the id ordering.
                    'symmetric' => $edge->relation?->isSymmetric() ?? false,
                    'description' => $edge->relation?->description,
                    'properties' => $edge->relation?->properties,
                    'valid_from' => $edge->relation?->valid_from?->toDateString(),
                    'valid_to' => $edge->relation?->valid_to?->toDateString(),
                    'state' => $edge->relation?->state?->value,
                    'origin' => $edge->relation?->origin?->value,
                ],
                $graph->edges,
            ),

            'ghosts' => $graph->ghosts,

            'truncated' => [
                'hidden_nodes' => $graph->hiddenNodes,
                'hidden_edges' => $graph->hiddenEdges,
            ],
        ];
    }
}
