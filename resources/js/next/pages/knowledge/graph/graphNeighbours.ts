// graphNeighbours — the layout, read as a LIST.
//
// This is the other half of D9: the canvas is an illustration, and the list built here is the
// surface that carries the same content in a form a screen reader and a keyboard can walk. Both
// read from the SAME layout, so they cannot disagree — a node that is drawn is a row that exists,
// with the same relation, the same score and the same status.
//
// Pure and Vue-free, so the grouping/ordering rules can be pinned by unit tests instead of by
// poking at rendered DOM.
import type { KnowledgeEntryStatus, KnowledgeEntryType } from '../types';
import {
  GRAPH_EDGE_KINDS,
  type GraphEdgeKind,
  type GraphEdgePlacement,
  type GraphLayout,
} from './knowledgeGraphLayout';

/** The bucket a row belongs to: its relation to the focus, or `entry` when there is no focus. */
export type GraphNeighbourGroup = GraphEdgeKind | 'entry';

/** Group order for the list: the same order the legend uses, with the no-focus bucket first. */
export const GRAPH_NEIGHBOUR_GROUPS: GraphNeighbourGroup[] = ['entry', ...GRAPH_EDGE_KINDS];

export interface GraphNeighbour {
  /**
   * Identity of THIS ROW — unique within the result, which `id` no longer is.
   *
   * Since a neighbour can occupy several relation rows, the placement id stopped being a usable
   * `:key` and a usable final tie-break. This is the key; `id` stays what it always was, the handle
   * the canvas and the list correlate on (hover, focus, selection), and several rows pointing at
   * one placement is exactly the intent.
   */
  rowId: string;
  /** Placement id — the handle the canvas and the list share. */
  id: string;
  /** The entry behind the row; null for a ghost, which has no entry yet. */
  entryId: string | null;
  slug: string;
  title: string;
  group: GraphNeighbourGroup;
  score: number | null;
  /** The EDGE id, when this row stands for one relation — what a dismissal acts on. */
  linkId: string | null;
  /**
   * Whether that relation may be dismissed — the SERVER's `can_be_dismissed`, carried straight
   * through. Never re-derived from the kind: a new derived edge kind then needs no change here.
   */
  canDismiss: boolean;
  status: KnowledgeEntryStatus | null;
  isStale: boolean;
  isGhost: boolean;
  degree: number;
  distance: number | null;
  /** Ghosts only: how many entries link to the missing slug. */
  ghostCount: number;
  /** The neighbour's own kind, for the text badge. Never drawn on the canvas — see G9. */
  entryType: KnowledgeEntryType | null;

  // --- relation rows only; null everywhere else -------------------------------------------
  /** The relation this row stands for — what "end" and "delete" act on. Null on every soft row. */
  relationId: string | null;
  /**
   * The verb, and both its readings. These exist so the LIST can say what the edge label says:
   * the canvas is `aria-hidden`, and the relation layer is the only one whose edge carries meaning
   * rather than just a kind, so without the verb here that meaning would be sighted-only content.
   */
  relationType: string | null;
  label: string | null;
  inverseLabel: string | null;
  symmetric: boolean;
  /**
   * Which way to read the verb from the FOCUS entry's point of view. Looking at Acme, the row
   * about Anna reads "has member", not "is a member of" with the arrow turned around.
   */
  direction: 'forward' | 'inverse';
  validFrom: string | null;
  validTo: string | null;
  historical: boolean;
}

/** Mirrors the layout's KIND_RANK, plus the no-focus bucket last. */
const GROUP_RANK: Record<GraphNeighbourGroup, number> = {
  relation: 0,
  wikilink: 1,
  manual: 2,
  mention: 3,
  similarity: 4,
  ghost: 5,
  entry: 6,
};

/**
 * The rows for a focus node — or, with no focus, every node that is drawn.
 *
 * ONE row per neighbour for the four SOFT layers, never one per edge: two entries can be joined by
 * both a wikilink and a machine proposal, and listing them twice would make the neighbourhood look
 * bigger than it is. The relation shown is the strongest one (a written link outranks a guessed
 * one), which is also the relation the canvas draws on top.
 *
 * ------------------------------------------------------------------------------------------------
 * THE ONE EXCEPTION: TYPED RELATIONS ARE NEVER COLLAPSED.
 *
 * "Similar AND mentioned" is still ONE neighbourhood — the collapse is right there, because those
 * four layers each carry a single meaning and the second adds nothing a reader needs. A typed
 * relation carries one of FIFTEEN meanings, and the same pair can legitimately hold several: Anna
 * `works_on` Orion and Anna `created` Orion are two different facts. Collapsing them would show one
 * and destroy the other with no trace that anything was hidden — the reader would not know to look.
 *
 * So relations are keyed per EDGE and soft layers per NEIGHBOUR. A neighbour with three relations
 * yields three rows in the Relations group, and still at most one row in each soft group.
 *
 * This exception is load-bearing and easy to erase by accident: the natural "simplify" refactor is
 * to put everything back through `betterRelation`, which would look like tidying and would silently
 * lose data. It is pinned by a test for that reason.
 */
export function neighboursOf(layout: GraphLayout, focusId: string | null): GraphNeighbour[] {
  const byId = new Map(layout.nodes.map((node) => [node.id, node]));
  const rows = new Map<string, GraphNeighbour>();

  const rowFor = (
    id: string,
    group: GraphNeighbourGroup,
    score: number | null,
    linkId: string | null,
    canDismiss = false,
    edge?: GraphEdgePlacement,
    direction: 'forward' | 'inverse' = 'forward',
  ) => {
    const node = byId.get(id);
    if (!node) return;
    const isRelation = group === 'relation';
    const rowId = isRelation ? `relation:${edge?.id ?? node.id}` : node.id;
    const candidate: GraphNeighbour = {
      rowId,
      id: node.id,
      entryId: node.entryId,
      slug: node.slug,
      title: node.title,
      group,
      score,
      linkId,
      canDismiss,
      status: node.status,
      isStale: node.isStale,
      isGhost: node.kind === 'ghost',
      degree: node.degree,
      distance: node.distance,
      ghostCount: node.ghostCount,
      entryType: node.entryType,
      relationId: isRelation ? (edge?.id ?? null) : null,
      relationType: isRelation ? (edge?.relationType ?? null) : null,
      label: isRelation ? (edge?.label ?? null) : null,
      inverseLabel: isRelation ? (edge?.inverseLabel ?? null) : null,
      symmetric: isRelation ? (edge?.symmetric ?? false) : false,
      direction,
      validFrom: isRelation ? (edge?.validFrom ?? null) : null,
      validTo: isRelation ? (edge?.validTo ?? null) : null,
      historical: isRelation ? (edge?.historical ?? false) : false,
    };

    // A relation gets its OWN row, keyed on the edge, so several between the same pair all survive.
    // Everything else collapses to one row per neighbour, keyed on the node.
    if (isRelation) {
      rows.set(rowId, candidate);

      return;
    }

    const seen = rows.get(id);
    if (!seen || betterRelation(candidate, seen)) rows.set(id, candidate);
  };

  if (focusId && byId.has(focusId)) {
    for (const edge of layout.edges) {
      // The verb is read from the FOCUS entry's side: standing on the target, the statement reads
      // backwards ("has member"), which is why the direction travels with the row.
      if (edge.from === focusId)
        rowFor(
          edge.to,
          edge.kind,
          edge.score,
          realLinkId(edge.kind, edge.id),
          edge.canDismiss,
          edge,
          'forward',
        );
      else if (edge.to === focusId)
        rowFor(
          edge.from,
          edge.kind,
          edge.score,
          realLinkId(edge.kind, edge.id),
          edge.canDismiss,
          edge,
          'inverse',
        );
    }
  } else {
    for (const node of layout.nodes) {
      rowFor(node.id, node.kind === 'ghost' ? 'ghost' : 'entry', null, null);
    }
  }

  return [...rows.values()].sort((a, b) => {
    const byGroup = GROUP_RANK[a.group] - GROUP_RANK[b.group];
    if (byGroup !== 0) return byGroup;

    // RELATIONS ONLY. Since a neighbour can now occupy several rows, its rows have to stay
    // TOGETHER — otherwise three facts about one person scatter through the group and read as
    // three unrelated neighbours. Within one neighbour: active before historical, then the most
    // recent first. The soft groups keep their original "strongest first" order untouched, which
    // is why this branch is scoped rather than folded into the shared comparator.
    if (a.group === 'relation') {
      return (
        a.title.localeCompare(b.title) ||
        Number(a.historical) - Number(b.historical) ||
        (b.validFrom ?? '').localeCompare(a.validFrom ?? '') ||
        (a.relationType ?? '').localeCompare(b.relationType ?? '') ||
        a.rowId.localeCompare(b.rowId)
      );
    }

    return (
      (b.score ?? 0) - (a.score ?? 0) ||
      b.degree - a.degree ||
      a.title.localeCompare(b.title) ||
      a.slug.localeCompare(b.slug)
    );
  });
}

/**
 * The id of a dismissible LINK row, or null.
 *
 * A ghost line is synthesised from an aggregate, so it has no row. A relation has a row, but it is
 * not a link and "these are not related" is not the available answer for it — ending it or deleting
 * it are, and those act through `relationId`. Returning its id here would offer a dismiss control
 * over a human assertion.
 */
function realLinkId(kind: GraphEdgeKind, id: string): string | null {
  return kind === 'ghost' || kind === 'relation' ? null : id;
}

function betterRelation(candidate: GraphNeighbour, seen: GraphNeighbour): boolean {
  const rank = GROUP_RANK[candidate.group] - GROUP_RANK[seen.group];
  if (rank !== 0) return rank < 0;
  return (candidate.score ?? 0) > (seen.score ?? 0);
}

/** The rows, split into the groups the list renders — empty groups dropped. */
export function groupNeighbours(
  rows: GraphNeighbour[],
): Array<{ group: GraphNeighbourGroup; rows: GraphNeighbour[] }> {
  return GRAPH_NEIGHBOUR_GROUPS.map((group) => ({
    group,
    rows: rows.filter((row) => row.group === group),
  })).filter((bucket) => bucket.rows.length > 0);
}
