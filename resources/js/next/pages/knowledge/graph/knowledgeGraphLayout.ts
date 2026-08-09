// knowledgeGraphLayout — WHERE every dot and every line goes.
//
// A pure, DETERMINISTIC function over the graph payload: no `Math.random`, no `Date`, no physics
// simulation, no DOM. Determinism is a UX requirement, not a preference — the same base must look
// the same after a refresh, or the user loses the mental map that is the only reason to draw a
// graph at all. A force layout re-shuffles on every mount; this one cannot.
//
// It is also STABLE against input order: everything that decides a position is sorted here, with a
// total order (the final tie-break is always the slug, which is unique within a base). The server's
// `nodes[]` order is NOT trusted — the overview's hub ranking comes out of a `group by` with no
// `order by`, so two identical requests may return the same set in a different sequence.
//
// ─────────────────────────────────────────────────────────────────────────────
// THE TWO PICTURES
//
// EGO (`data.center` is set) — the centre at the origin, its direct neighbours on an inner ring
// ORDERED BY WHAT THE RELATION IS (wikilinks first, then manual, then similarity by descending
// score, then the missing entries), and second-hop neighbours on an outer arc anchored under the
// neighbour they hang off. The ordering is the point: a reader scanning clockwise from the top
// walks from "things this entry says" to "things a machine thinks are related".
//
// OVERVIEW (no centre) — concentric rings by degree (hubs innermost), then two BARYCENTRIC passes
// that rotate each ring so a node sits near its neighbours while keeping the ring evenly spaced.
// Even spacing beats exact barycentres: overlapping dots are unreadable, a slightly-off angle is
// not.
//
// ─────────────────────────────────────────────────────────────────────────────
// WHAT IS DELIBERATELY NOT USED
//
// `updatedAt` — the UX spec's overview sort was `degree ⇣ → updatedAt ⇣ → id`, but a graph node
// carries NO timestamp on the wire (KnowledgeGraphResource is minimal by design). Recency already
// decides WHICH isolated entries the server puts in the response; it cannot also decide where they
// land, so the tie-break is the slug. Inventing an `updated_at` field would be a lie the renderer
// tells about a contract it cannot see.
//
// `node.degree` — the server counts edges in the whole RESPONSE. The canvas may be showing fewer
// (the edge-kind chips filter client-side, and the client cap can drop nodes), so the radius is
// driven by the degree that is actually DRAWN. A dot labelled with a degree it does not visibly
// have is a dot the reader stops believing.
import type {
  KnowledgeEntryStatus,
  KnowledgeGraphData,
  KnowledgeGraphEdge,
  KnowledgeGraphNode,
  KnowledgeLinkEvidence,
  KnowledgeLinkSource,
  KnowledgeEntryType,
  KnowledgeRelationState,
} from '../types';
import { KNOWLEDGE_GRAPH_MAX_NODES } from '../types';

/** How a line came to be drawn. `ghost` is not a server source — it is a link with no target. */
export type GraphEdgeKind = KnowledgeLinkSource | 'ghost' | 'relation';

/**
 * Every kind, in the order the legend and the neighbour list group them.
 *
 * `relation` comes FIRST because it is the only layer a human authored the meaning of. The other
 * four are the machine's reading of text, rebuilt on every save; a relation is a statement somebody
 * approved. The strongest semantics reads first.
 */
export const GRAPH_EDGE_KINDS: GraphEdgeKind[] = [
  'relation',
  'wikilink',
  'similarity',
  'mention',
  'manual',
  'ghost',
];

/**
 * The kinds a LEGEND explains — every kind the canvas can draw, minus `manual`.
 *
 * Nothing writes that source. The enum member stays (the mention scanner still reads it, and a type
 * is not a UI), but a legend row for a pattern that can never appear is a promise the base cannot
 * keep: the reader looks for a solid squared line and concludes they are missing something.
 *
 * `ghost` stays even though it is no longer a top-level FILTER — it is still a distinct pattern on
 * the canvas, and explaining the patterns is what a legend is for.
 */
export const LEGEND_EDGE_KINDS: GraphEdgeKind[] = GRAPH_EDGE_KINDS.filter(
  (kind) => kind !== 'manual',
);

export interface GraphLayoutOptions {
  /** Virtual canvas. The SVG scales it through `viewBox`, so these are world units, not pixels. */
  width?: number;
  height?: number;
  /** Breathing room between the outermost ring and the canvas edge. */
  margin?: number;
  /** Client-side node cap. Never looser than the server's own (`knowledge.graph.max_nodes`). */
  cap?: number;
  /** The edge kinds to draw. A kind that is off removes its LINES, never its nodes. */
  kinds?: GraphEdgeKind[];
}

/** One placed node — a real entry or a ghost (a slug that was linked to but never written). */
export interface GraphNodePlacement {
  /** Placement id: the entry id, or `ghost:<slug>` for a ghost. Unique within a layout. */
  id: string;
  kind: 'entry' | 'ghost';
  /** The entry this dot stands for; null for a ghost, which has no entry yet. */
  entryId: string | null;
  slug: string;
  title: string;
  status: KnowledgeEntryStatus | null;
  isStale: boolean;
  isCenter: boolean;
  /** Edges DRAWN at this node in this layout (see the header note on `node.degree`). */
  degree: number;
  /** Hops from the centre; null in the overview, which has none. */
  distance: number | null;
  ring: number;
  /** Radians. Kept so the host can place labels without re-deriving trigonometry. */
  angle: number;
  x: number;
  y: number;
  r: number;
  /** Ghosts only: how many entries link to this missing slug. */
  ghostCount: number;
  /**
   * What KIND of thing this node is, or null where nobody has said — which is MOST nodes.
   *
   * Carried, never DRAWN. Seven categories cannot be told apart in a 12px glyph inside a circle of
   * radius 6-18, and shape is already spoken for by ghost / draft / amended. Encoding it would be
   * decoration that lies about being readable. It is here so the neighbour list and the side panel
   * can show it as TEXT, which is the only honest way to render seven categories.
   */
  entryType: KnowledgeEntryType | null;
}

/** One placed edge, with endpoints already trimmed to the circles they connect. */
export interface GraphEdgePlacement {
  /** The link id, or `ghost:<fromId>:<slug>` for a ghost line (which has no single row). */
  id: string;
  /** Placement ids, NOT entry ids — a ghost end has no entry id. */
  from: string;
  to: string;
  kind: GraphEdgeKind;
  score: number | null;
  evidence: KnowledgeLinkEvidence | null;
  /** A wikilink points somewhere; a similarity is mutual and gets no arrowhead. */
  directed: boolean;
  /**
   * RELATION ONLY, null on every other kind — the verb, and the two ways it reads.
   *
   * These travel through the layout for one reason: the neighbour list is the ACCESSIBLE twin of a
   * canvas that is `aria-hidden`, and the relation layer is the only one whose edge carries meaning
   * rather than just a kind. Without the verb reaching the list, the edge label would be content
   * available to sighted users alone.
   */
  relationType: string | null;
  label: string | null;
  inverseLabel: string | null;
  symmetric: boolean;
  validFrom: string | null;
  validTo: string | null;
  state: KnowledgeRelationState | null;
  /** `state !== 'active'` — a claim about the past, drawn muted and labelled with its end year. */
  historical: boolean;
  /**
   * Whether this relation may be dismissed — the SERVER's `can_be_dismissed`, carried through
   * untouched. A preview edge (the composer) is always false: nothing is materialised, so nothing
   * can be refused.
   */
  canDismiss: boolean;
  x1: number;
  y1: number;
  x2: number;
  y2: number;
  /** Midpoint — where the `manual` badge sits and where a title/tooltip is anchored. */
  mx: number;
  my: number;
  /** Stroke width. Similarity scales with score, so a strong claim reads as a stronger line. */
  width: number;
}

export interface GraphLayout {
  mode: 'ego' | 'overview';
  centerId: string | null;
  nodes: GraphNodePlacement[];
  edges: GraphEdgePlacement[];
  /** Nodes the CLIENT cap dropped. The server's own truncation is reported separately. */
  overflow: number;
  world: { width: number; height: number };
  /** Tight bounding box + 8 % margin — exactly what "fit to screen" sets the viewBox to. */
  fit: { x: number; y: number; width: number; height: number };
}

const TAU = Math.PI * 2;
/** The first slot of every ring sits at the top, which is where a reader starts scanning. */
const START_ANGLE = -Math.PI / 2;

/**
 * The GOLDEN ANGLE (~137.508°) — the phyllotaxis increment, and the reason a small overview is a
 * shape rather than a line.
 *
 * Handing out angles sequentially (`i · 2π/n`) puts a two-node ring at 0° and 180°: with the hub at
 * the origin that is three collinear points, i.e. the vertical stripe this layout used to draw for
 * a four-entry base. The golden angle is INCOMMENSURABLE with a full turn, so no number of steps
 * ever lands two nodes opposite each other — it cannot construct the degenerate case. It is the
 * same trick d3-force uses to seed positions deterministically, and it costs one constant.
 */
const GOLDEN_ANGLE = Math.PI * (3 - Math.sqrt(5));

/**
 * The fewest angular slots a ring is divided into, however few nodes sit on it.
 *
 * Cytoscape's circle layout derives a MINIMUM RADIUS from the desired arc gap; our radii are fixed
 * per ring, so the same idea is applied to the other side of the equation — a minimum number of
 * SLOTS, with the nodes taking the first few. Three is deliberate rather than the five that was
 * suggested: three is the smallest count that makes an antipodal pair impossible (2 nodes land
 * 120° apart), while five would squeeze a three-node ring into 144° of arc and make a triangle
 * look like a fan. For n ≥ 3 this changes nothing at all.
 *
 * A four-node cross (n = 4) still contains collinear TRIPLES through the centre. That is fine and
 * not what this guards: the failure is the whole picture collapsing onto one axis, not the
 * existence of a diameter.
 */
const MIN_RING_SLOTS = 3;

/**
 * DECISION — no physics, and why.
 *
 * The research offered two remedies for the collapse: port a miniature d3-force (link + charge +
 * collide, fixed iteration count) or fix the barycentric pass. This module takes the second: the
 * radius is FROZEN (a node never leaves its ring) and the barycentric passes optimise the ANGLE
 * only, which is the textbook cure for Tutte's collapse — barycentric iteration without a fixed
 * frame converges to a point or a line, and a pinned ring IS that frame.
 *
 * A force port is the recorded plan B if the result is still ugly at some future size. It is not
 * needed today: with the radius pinned and the angles seeded by the golden angle, the degenerate
 * configuration is unreachable by construction rather than damped by repulsion — and this keeps
 * the layout a pure function, which is what the snapshot tests are built on.
 */

const DEFAULTS: Required<GraphLayoutOptions> = {
  width: 1200,
  height: 900,
  margin: 56,
  cap: KNOWLEDGE_GRAPH_MAX_NODES,
  kinds: GRAPH_EDGE_KINDS,
};

/** Ring radii as a share of R. Overview: hubs inside; ego: centre, neighbours, second hop, ghosts. */
const OVERVIEW_RINGS = [0.16, 0.46, 0.84];
const EGO_RINGS = [0, 0.45, 0.85, 0.97];

const NODE_MIN_R = 6;
const NODE_MAX_R = 18;
const GHOST_R = 7;

/**
 * The relation ORDER, and it is the ego graph's whole editorial claim, from the most deliberate
 * relation to the least:
 *
 *   wikilink    the entry's own text points there.
 *   manual      a human drew it on purpose.
 *   mention     the target's NAME appears in the text — evidence from the writing, but not a
 *               decision to link; it outranks similarity because it is textual rather than
 *               statistical, and a reader can verify it by reading.
 *   similarity  a machine thinks the meanings overlap.
 *   ghost       the entry points at something that does not exist.
 *
 * The last rank is the defensive "no direct edge" bucket — unreachable through the documented
 * contract, since every neighbour was discovered along an edge that the closing query returns.
 */
const KIND_RANK: Record<GraphEdgeKind, number> = {
  // An ASSERTED statement outranks every derived reading of the text, including a wikilink: the
  // wikilink is what someone wrote, the relation is what someone MEANT and approved.
  relation: 0,
  wikilink: 1,
  manual: 2,
  mention: 3,
  similarity: 4,
  ghost: 5,
};
const NO_EDGE_RANK = 6;

// ---------------------------------------------------------------------------
// Small deterministic maths
// ---------------------------------------------------------------------------

/**
 * Two decimals. Not cosmetic: it keeps the pinned layout snapshot readable AND immune to the
 * last-bit float differences that make `atan2`/`sqrt` results differ between engines.
 */
function round2(value: number): number {
  const rounded = Math.round(value * 100) / 100;
  return rounded === 0 ? 0 : rounded; // normalise -0, which serialises differently
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

function normalizeAngle(angle: number): number {
  return ((angle % TAU) + TAU) % TAU;
}

/**
 * The circular mean of a set of angles — the "average direction", which a plain arithmetic mean
 * cannot give (350° and 10° average to 180°, i.e. the exact opposite of the right answer).
 *
 * A perfectly balanced set (two opposite angles) has NO mean direction; rather than return an
 * arbitrary one, the first angle wins, which keeps the function total and deterministic.
 */
function circularMean(angles: number[]): number {
  if (angles.length === 0) return START_ANGLE;
  let sin = 0;
  let cos = 0;
  for (const angle of angles) {
    sin += Math.sin(angle);
    cos += Math.cos(angle);
  }
  if (Math.abs(sin) < 1e-9 && Math.abs(cos) < 1e-9) return normalizeAngle(angles[0]);
  return normalizeAngle(Math.atan2(sin, cos));
}

/** Node radius by drawn degree — square-rooted so a hub is bigger without swallowing the canvas. */
function nodeRadius(degree: number): number {
  return clamp(6 + 3 * Math.sqrt(Math.max(0, degree)), NODE_MIN_R, NODE_MAX_R);
}

/** Stroke width per kind. Similarity is the only one that carries a number, so it is the only scaled one. */
function edgeWidth(kind: GraphEdgeKind, score: number | null): number {
  // The heaviest line in the picture, and the width lives HERE rather than in CSS because the
  // canvas and the legend keep separate stylesheets — a width set in one would not reach the other.
  if (kind === 'relation') return 2.5;
  if (kind === 'similarity') return round2(clamp(1 + (score ?? 0), 1, 2.5));
  if (kind === 'manual') return 2;
  if (kind === 'ghost') return 1.25;
  // `mention` shares the wikilink weight: the DASH PATTERN separates them, not the thickness.
  return 1.5;
}

// ---------------------------------------------------------------------------
// Preparation: what may be drawn at all
// ---------------------------------------------------------------------------

interface PreparedGhost {
  slug: string;
  count: number;
  /** Entry ids that link here, restricted to nodes present in the payload, slug-sorted. */
  sources: string[];
}

interface Prepared {
  nodes: KnowledgeGraphNode[];
  nodeById: Map<string, KnowledgeGraphNode>;
  edges: KnowledgeGraphEdge[];
  ghosts: PreparedGhost[];
  /** Drawn degree per entry id (real edges + ghost lines leaving it). */
  degree: Map<string, number>;
  /** entryId → neighbour entryId → the STRONGEST edge between the pair. */
  adjacency: Map<string, Map<string, KnowledgeGraphEdge>>;
}

/**
 * The identity of an edge. The row id when there is one; otherwise the endpoint pair plus the kind
 * — a preview edge (`id: null`) describes a relation that does not exist yet, so it has no row to
 * be named by, and a pair can carry at most one edge of each kind.
 */
function edgeKey(edge: KnowledgeGraphEdge): string {
  return edge.id ?? syntheticEdgeKey(edge);
}

/**
 * Identity for an edge with no row of its own — the composer's preview.
 *
 * The VERB is part of it, and that is not decoration: the same pair can carry two different
 * relations (Anna `works_on` Orion AND Anna `created` Orion), and keying preview edges on the pair
 * alone would silently drop the second as a duplicate of the first. For a link the verb is null
 * and the key degrades to what it always was.
 */
function syntheticEdgeKey(edge: KnowledgeGraphEdge): string {
  const kind = edgeKind(edge);

  return kind === 'relation'
    ? `${edge.from}:${edge.to}:${kind}:${edge.relation_type ?? ''}`
    : `${edge.from}:${edge.to}:${kind}`;
}

function edgeKind(edge: KnowledgeGraphEdge): GraphEdgeKind {
  // `kind` is the SERVER's discriminator between a derived link and an asserted relation. Read it
  // rather than inferring "relation" from a null `source`: on a link, `source` being null is a
  // different situation entirely (see below), and conflating the two would draw a manual edge as a
  // relation the moment the column ever went nullable.
  if (edge.kind === 'relation') return 'relation';

  // The column is not nullable; the resource types it optional. An edge with no stated source is
  // drawn as the neutral `manual` line rather than silently dropped.
  return edge.source ?? 'manual';
}

/** The edge a reader would call the relation between two nodes: strongest kind, then best score. */
function strongerEdge(a: KnowledgeGraphEdge, b: KnowledgeGraphEdge): KnowledgeGraphEdge {
  const rankA = KIND_RANK[edgeKind(a)];
  const rankB = KIND_RANK[edgeKind(b)];
  if (rankA !== rankB) return rankA < rankB ? a : b;
  const scoreA = a.score ?? 0;
  const scoreB = b.score ?? 0;
  if (scoreA !== scoreB) return scoreA > scoreB ? a : b;
  return a.id <= b.id ? a : b;
}

/**
 * Filter the payload down to what may be drawn: known nodes, edges of an ENABLED kind whose both
 * endpoints survived, and ghosts with at least one visible source.
 */
function prepare(
  data: KnowledgeGraphData,
  kinds: GraphEdgeKind[],
  allowed?: Set<string>,
): Prepared {
  const enabled = new Set(kinds);

  const nodeById = new Map<string, KnowledgeGraphNode>();
  for (const node of data.nodes ?? []) {
    if (!node?.id) continue;
    if (allowed && !allowed.has(node.id)) continue;
    if (!nodeById.has(node.id)) nodeById.set(node.id, node);
  }

  const seenEdges = new Set<string>();
  const edges: KnowledgeGraphEdge[] = [];
  for (const edge of data.edges ?? []) {
    if (!edge?.from || !edge?.to || edge.from === edge.to) continue;
    if (!nodeById.has(edge.from) || !nodeById.has(edge.to)) continue;
    if (!enabled.has(edgeKind(edge))) continue;
    // A PREVIEW edge carries `id: null` (it has no row), so identity is the endpoint pair plus the
    // kind — which is exactly what makes an edge unique there.
    const key = edgeKey(edge);
    if (seenEdges.has(key)) continue;
    seenEdges.add(key);
    edges.push(edge);
  }
  // Total order over the drawn edges, so the SVG paint order (and any snapshot) is stable.
  edges.sort((a, b) => edgeKey(a).localeCompare(edgeKey(b)));

  const ghosts: PreparedGhost[] = [];
  if (enabled.has('ghost')) {
    for (const ghost of data.ghosts ?? []) {
      if (!ghost?.target_slug) continue;
      const sources = [...new Set(ghost.from_ids ?? [])]
        .filter((id) => nodeById.has(id))
        .sort((a, b) => slugOf(nodeById, a).localeCompare(slugOf(nodeById, b)) || a.localeCompare(b));
      if (sources.length === 0) continue;
      ghosts.push({ slug: ghost.target_slug, count: ghost.count ?? sources.length, sources });
    }
    ghosts.sort((a, b) => a.slug.localeCompare(b.slug));
  }

  const degree = new Map<string, number>();
  const adjacency = new Map<string, Map<string, KnowledgeGraphEdge>>();
  const bump = (id: string): void => degree.set(id, (degree.get(id) ?? 0) + 1);

  for (const edge of edges) {
    bump(edge.from);
    bump(edge.to);
    for (const [a, b] of [
      [edge.from, edge.to],
      [edge.to, edge.from],
    ]) {
      const row = adjacency.get(a) ?? new Map<string, KnowledgeGraphEdge>();
      const seen = row.get(b);
      row.set(b, seen ? strongerEdge(seen, edge) : edge);
      adjacency.set(a, row);
    }
  }
  // A ghost line leaves its source, so it counts towards that entry's drawn degree.
  for (const ghost of ghosts) for (const source of ghost.sources) bump(source);

  return {
    nodes: [...nodeById.values()],
    nodeById,
    edges,
    ghosts,
    degree,
    adjacency,
  };
}

function slugOf(nodeById: Map<string, KnowledgeGraphNode>, id: string): string {
  return nodeById.get(id)?.slug ?? id;
}

// ---------------------------------------------------------------------------
// Placement plumbing
// ---------------------------------------------------------------------------

/** A node on its way to a position: everything decided except x/y. */
interface Seat {
  id: string;
  kind: 'entry' | 'ghost';
  entryId: string | null;
  slug: string;
  title: string;
  status: KnowledgeEntryStatus | null;
  isStale: boolean;
  isCenter: boolean;
  degree: number;
  distance: number | null;
  ghostCount: number;
  entryType: KnowledgeEntryType | null;
  ring: number;
  angle: number;
  radius: number;
}

function seatFromNode(
  node: KnowledgeGraphNode,
  prep: Prepared,
  centerId: string | null,
): Seat {
  return {
    id: node.id,
    kind: 'entry',
    entryId: node.id,
    slug: node.slug,
    title: node.title,
    status: node.status ?? null,
    isStale: !!node.is_stale,
    isCenter: node.id === centerId,
    degree: prep.degree.get(node.id) ?? 0,
    distance: node.distance ?? null,
    ghostCount: 0,
    entryType: node.entry_type ?? null,
    ring: 0,
    angle: START_ANGLE,
    radius: 0,
  };
}

function seatFromGhost(ghost: PreparedGhost): Seat {
  return {
    id: ghostId(ghost.slug),
    kind: 'ghost',
    entryId: null,
    slug: ghost.slug,
    // A ghost has no title — only the slug that was meant. The host renders it as-is rather than
    // prettifying it, because that string is what the user has to type to create the entry.
    title: ghost.slug,
    status: null,
    isStale: false,
    isCenter: false,
    degree: ghost.sources.length,
    distance: null,
    // A ghost is a slug nobody has written yet, so nothing is known about what kind of thing it is.
    ghostCount: ghost.count,
    entryType: null,
    ring: 0,
    angle: START_ANGLE,
    radius: 0,
  };
}

export function ghostId(slug: string): string {
  return `ghost:${slug}`;
}

function toPlacement(seat: Seat): GraphNodePlacement {
  const r = seat.kind === 'ghost' ? GHOST_R : nodeRadius(seat.degree);
  return {
    id: seat.id,
    kind: seat.kind,
    entryId: seat.entryId,
    slug: seat.slug,
    title: seat.title,
    status: seat.status,
    isStale: seat.isStale,
    isCenter: seat.isCenter,
    degree: seat.degree,
    distance: seat.distance,
    ghostCount: seat.ghostCount,
    entryType: seat.entryType,
    ring: seat.ring,
    angle: round2(seat.angle),
    x: round2(Math.cos(seat.angle) * seat.radius),
    y: round2(Math.sin(seat.angle) * seat.radius),
    r: round2(r),
  };
}

/**
 * How many angular slots a ring of `count` nodes is divided into.
 *
 * `max(1, …)` mirrors Cytoscape's `dTheta = sweep / max(1, n − 1)` guard: the divisor is never
 * zero, so a one-node ring is arithmetic rather than a special case.
 */
function ringSlots(count: number): number {
  return Math.max(1, count, MIN_RING_SLOTS);
}

/**
 * Spread `count` seats around a ring, first one at the top.
 *
 * Seats take the first `count` of `ringSlots(count)` positions, so a two-node ring opens at 120°
 * instead of collapsing onto a diameter. Order is preserved, which the ego graph depends on: its
 * ring reads clockwise from the top as wikilinks → manual → mention → similarity → ghost.
 */
function evenAngle(index: number, count: number): number {
  return START_ANGLE + (index * TAU) / ringSlots(count);
}

/**
 * Lay `children` out in an angular SECTOR under their parent.
 *
 * The sector is centred on the parent so a child visibly belongs to it, and its width is the
 * parent's share of all children on the ring — capped by the angular room between parents, since a
 * sector wider than the gap would put a child under the wrong parent.
 */
function placeSector(
  children: Seat[],
  parentAngle: number,
  totalChildren: number,
  parentCount: number,
  radius: number,
  ring: number,
): void {
  const count = children.length;
  if (count === 0) return;
  const desiredHalf = (Math.PI * count) / Math.max(1, totalChildren);
  const maxHalf = (0.9 * Math.PI) / Math.max(1, parentCount);
  const half = Math.min(desiredHalf, maxHalf);
  const step = count > 1 ? (2 * half) / (count - 1) : 0;

  children.forEach((seat, index) => {
    seat.ring = ring;
    seat.radius = radius;
    seat.angle = count === 1 ? parentAngle : parentAngle - half + index * step;
  });
}

// ---------------------------------------------------------------------------
// EGO
// ---------------------------------------------------------------------------

/** The relation between two entries, as a sortable pair. */
function relationRank(prep: Prepared, from: string, to: string): { rank: number; score: number } {
  const edge = prep.adjacency.get(from)?.get(to);
  if (!edge) return { rank: NO_EDGE_RANK, score: 0 };
  return { rank: KIND_RANK[edgeKind(edge)], score: edge.score ?? 0 };
}

/** The same rank for a SEAT, so a ghost child sorts as a ghost rather than as "no edge". */
function childRank(prep: Prepared, seat: Seat, parentId: string): { rank: number; score: number } {
  if (seat.kind === 'ghost') return { rank: KIND_RANK.ghost, score: 0 };
  return relationRank(prep, seat.entryId ?? seat.id, parentId);
}

/** wikilink → manual → similarity (by descending score) → ghost, then slug. A TOTAL order. */
function compareByRelation(
  a: { rank: number; score: number; slug: string },
  b: { rank: number; score: number; slug: string },
): number {
  return a.rank - b.rank || b.score - a.score || a.slug.localeCompare(b.slug);
}

/**
 * The EGO graph: one entry's neighbourhood, to `depth` hops.
 *
 * Called with a payload that has no usable `center` (a bare overview, or a centre missing from
 * `nodes[]`), it falls back to the overview rather than drawing a picture with a hole in it.
 */
export function layoutEgoGraph(
  data: KnowledgeGraphData,
  options: GraphLayoutOptions = {},
): GraphLayout {
  const opts = { ...DEFAULTS, ...options };
  const centerId = data.center ?? null;

  const probe = prepare(data, opts.kinds);
  if (!centerId || !probe.nodeById.has(centerId)) return layoutOverviewGraph(data, options);

  // The centre is never dropped by the cap: a neighbourhood without its subject is not a smaller
  // answer, it is a different one.
  const capped = capNodes(probe, opts.cap, centerId);
  const prep = capped.prep;
  const R = radiusOf(opts);

  const seats = new Map<string, Seat>();
  for (const node of prep.nodes) seats.set(node.id, seatFromNode(node, prep, centerId));

  const centre = seats.get(centerId) as Seat;
  centre.ring = 0;
  centre.radius = EGO_RINGS[0];
  centre.angle = START_ANGLE;

  /** Distance as the layout uses it: the server's, clamped into the rings this function draws. */
  const distanceOf = (id: string): number =>
    id === centerId ? 0 : clamp(prep.nodeById.get(id)?.distance ?? 1, 1, 2);

  // Every ghost hangs off ONE source — the nearest to the centre, slug-tied — so it is drawn as
  // the missing thing that that entry points at, not as a free-floating dot.
  const ghostSeats = new Map<string, Seat>();
  const ghostParent = new Map<string, string>();
  for (const ghost of prep.ghosts) {
    const parent = [...ghost.sources].sort(
      (a, b) => distanceOf(a) - distanceOf(b) || slugOf(prep.nodeById, a).localeCompare(slugOf(prep.nodeById, b)),
    )[0];
    const seat = seatFromGhost(ghost);
    ghostSeats.set(seat.id, seat);
    ghostParent.set(seat.id, parent);
  }

  // --- Ring 1: the direct neighbourhood, ordered by WHAT the relation is.
  const ring1: Seat[] = [
    ...prep.nodes
      .filter((node) => node.id !== centerId && distanceOf(node.id) === 1)
      .map((node) => ({
        seat: seats.get(node.id) as Seat,
        ...relationRank(prep, centerId, node.id),
        slug: node.slug,
      })),
    // A ghost of the CENTRE is a first-hop thing: the spec's ego ordering ends on exactly this.
    ...[...ghostSeats.values()]
      .filter((seat) => ghostParent.get(seat.id) === centerId)
      .map((seat) => ({ seat, rank: KIND_RANK.ghost, score: 0, slug: seat.slug })),
  ]
    .sort(compareByRelation)
    .map((row) => row.seat);

  ring1.forEach((seat, index) => {
    seat.ring = 1;
    seat.radius = EGO_RINGS[1] * R;
    seat.angle = evenAngle(index, ring1.length);
  });

  const ring1Ids = new Set(ring1.map((seat) => seat.id));

  // --- Ring 2: second-hop entries + ghosts of a first-hop entry, each under its parent.
  const childrenByParent = new Map<string, Seat[]>();
  const orphans: Seat[] = [];
  const attach = (parentId: string | null, seat: Seat): void => {
    if (parentId && ring1Ids.has(parentId)) {
      childrenByParent.set(parentId, [...(childrenByParent.get(parentId) ?? []), seat]);
    } else {
      orphans.push(seat);
    }
  };

  for (const node of prep.nodes) {
    if (node.id === centerId || distanceOf(node.id) !== 2) continue;
    // The parent is the first-hop neighbour with the strongest relation to this node, so a
    // second-hop entry hangs off the neighbour a reader would say it came from.
    const parent = [...ring1Ids]
      .filter((id) => prep.adjacency.get(node.id)?.has(id))
      .map((id) => ({ id, ...relationRank(prep, node.id, id), slug: slugOf(prep.nodeById, id) }))
      .sort(compareByRelation)[0];
    attach(parent?.id ?? null, seats.get(node.id) as Seat);
  }
  for (const seat of ghostSeats.values()) {
    const parent = ghostParent.get(seat.id);
    if (parent && ring1Ids.has(parent)) attach(parent, seat);
  }

  const ring2Total = [...childrenByParent.values()].reduce((sum, list) => sum + list.length, 0);
  for (const parentSeat of ring1) {
    const children = childrenByParent.get(parentSeat.id);
    if (!children) continue;
    children.sort((a, b) =>
      compareByRelation(
        { ...childRank(prep, a, parentSeat.id), slug: a.slug },
        { ...childRank(prep, b, parentSeat.id), slug: b.slug },
      ),
    );
    placeSector(children, parentSeat.angle, ring2Total, childrenByParent.size, EGO_RINGS[2] * R, 2);
  }

  // Defensive only: the contract guarantees every neighbour arrives with the edge that found it,
  // so a parentless second hop cannot happen. If it ever does it is spread, not dropped.
  orphans.sort((a, b) => a.slug.localeCompare(b.slug));
  orphans.forEach((seat, index) => {
    seat.ring = 2;
    seat.radius = EGO_RINGS[2] * R;
    seat.angle = evenAngle(index, orphans.length);
  });

  // --- Ring 3: ghosts of a second-hop entry — the outermost thing on the canvas.
  const ring2Ids = new Set(
    [...childrenByParent.values()].flat().concat(orphans).map((seat) => seat.id),
  );
  const ghostChildren = new Map<string, Seat[]>();
  for (const seat of ghostSeats.values()) {
    const parent = ghostParent.get(seat.id);
    if (!parent || !ring2Ids.has(parent)) continue;
    ghostChildren.set(parent, [...(ghostChildren.get(parent) ?? []), seat]);
  }
  const ring3Total = [...ghostChildren.values()].reduce((sum, list) => sum + list.length, 0);
  for (const [parentId, children] of [...ghostChildren.entries()].sort((a, b) => a[0].localeCompare(b[0]))) {
    // A ghost's parent is always an ENTRY (its `from_ids` are entry ids), never another ghost.
    const parentSeat = seats.get(parentId);
    if (!parentSeat) continue;
    children.sort((a, b) => a.slug.localeCompare(b.slug));
    placeSector(children, parentSeat.angle, ring3Total, ghostChildren.size, EGO_RINGS[3] * R, 3);
  }

  const placed = [...seats.values(), ...ghostSeats.values()];
  return assemble('ego', centerId, placed, prep, opts, capped.overflow);
}

// ---------------------------------------------------------------------------
// OVERVIEW
// ---------------------------------------------------------------------------

/**
 * The OVERVIEW graph: the base's shape. Hubs innermost, then two barycentric passes.
 */
export function layoutOverviewGraph(
  data: KnowledgeGraphData,
  options: GraphLayoutOptions = {},
): GraphLayout {
  const opts = { ...DEFAULTS, ...options };
  const probe = prepare(data, opts.kinds);
  const capped = capNodes(probe, opts.cap, null);
  const prep = capped.prep;
  const R = radiusOf(opts);

  const seats = new Map<string, Seat>();
  for (const node of prep.nodes) seats.set(node.id, seatFromNode(node, prep, null));

  // Rank by degree, tie-broken by slug then id — a TOTAL order over an input whose own order the
  // server does not promise (see the header).
  const ordered = [...seats.values()].sort(
    (a, b) => b.degree - a.degree || a.slug.localeCompare(b.slug) || a.id.localeCompare(b.id),
  );

  // Ring sizes are shares of what is SHOWN, not of the cap: with 12 nodes and a cap of 60, sizing
  // the inner ring off the cap would put nine of the twelve on top of each other.
  const total = ordered.length;
  const ring0Size = total === 0 ? 0 : Math.min(9, Math.max(1, Math.ceil(total * 0.15)));
  const ring1Size = Math.min(Math.ceil(total * 0.35), Math.max(0, total - ring0Size));
  const rings: Seat[][] = [
    ordered.slice(0, ring0Size),
    ordered.slice(ring0Size, ring0Size + ring1Size),
    ordered.slice(ring0Size + ring1Size),
  ];

  // GOLDEN-ANGLE SEEDING, indexed over the WHOLE ordering rather than per ring.
  //
  // Per-ring indices would restart at zero on every ring and hand the first node of each the same
  // angle — three rings, three nodes, one straight line out from the hub. A single running index
  // makes every node's seed angle distinct, and distinct by an irrational fraction of a turn, so
  // no pair is ever opposite. `ordered` is already a total order (degree, then slug, then id), so
  // this is as deterministic as the sequential version it replaces.
  let seed = 0;
  rings.forEach((ring, ringIndex) => {
    ring.forEach((seat) => {
      seat.ring = ringIndex;
      // A single innermost node is the hub of the picture; it belongs at the origin, not on a
      // one-node circle that reads as an accident.
      seat.radius = ringIndex === 0 && ring.length === 1 ? 0 : OVERVIEW_RINGS[ringIndex] * R;
      seat.angle = seat.radius === 0 ? START_ANGLE : START_ANGLE + seed * GOLDEN_ANGLE;
      seed += 1;
    });
  });

  // TWO barycentric passes, outermost→innermost then back. One pass moves a ring towards
  // neighbours that have not moved yet; the second lets those neighbours answer.
  for (const order of [[2, 1, 0], [0, 1, 2]]) {
    for (const ringIndex of order) rotateRingToBarycentres(rings[ringIndex], prep, seats);
  }

  // Ghosts hang just outside the source they were linked from, fanned out when a source has
  // several so two missing entries never land on the same dot.
  const ghostSeats: Seat[] = [];
  const byParent = new Map<string, PreparedGhost[]>();
  for (const ghost of prep.ghosts) {
    const parent = ghost.sources[0];
    byParent.set(parent, [...(byParent.get(parent) ?? []), ghost]);
  }
  let ghostIndex = 0;
  const ghostTotal = prep.ghosts.length;
  for (const [parentId, list] of [...byParent.entries()].sort((a, b) => a[0].localeCompare(b[0]))) {
    const parentSeat = seats.get(parentId);
    list.sort((a, b) => a.slug.localeCompare(b.slug));
    const spread = 0.14;
    list.forEach((ghost, index) => {
      const seat = seatFromGhost(ghost);
      seat.ring = 3;
      if (!parentSeat || parentSeat.radius === 0) {
        // The source sits at the origin (a single-hub overview): there is no direction to inherit,
        // so the ghosts take their own even share of the circle.
        seat.radius = OVERVIEW_RINGS[2] * R;
        seat.angle = evenAngle(ghostIndex, ghostTotal);
      } else {
        seat.radius = Math.min(parentSeat.radius + 0.1 * R, R * 1.02);
        seat.angle = parentSeat.angle + (index - (list.length - 1) / 2) * spread;
      }
      ghostIndex += 1;
      ghostSeats.push(seat);
    });
  }

  return assemble('overview', null, [...seats.values(), ...ghostSeats], prep, opts, capped.overflow);
}

/**
 * Rotate one ring so each node sits near its neighbours, WITHOUT breaking the even spacing.
 *
 * THE RADIUS IS NEVER TOUCHED HERE, and that is the fix for Tutte's collapse: barycentric
 * iteration over free vertices converges to a point or a line, so the ring itself is the fixed
 * frame and only the ANGLE is optimised. Every write below is to `seat.angle`.
 *
 * Sorting by barycentre and then re-spreading is the rest of the trick: pinning each node to its
 * exact barycentre clumps the ring, while sorting by it preserves the neighbour ORDER — which is
 * what makes edges stop crossing — and even spacing keeps the dots apart. The rotation offset is
 * the circular mean of each node's (barycentre − slot), so the re-spread ring lands as close to
 * the barycentres as an evenly spaced ring can.
 */
function rotateRingToBarycentres(ring: Seat[], prep: Prepared, seats: Map<string, Seat>): void {
  if (ring.length < 2) return;

  const keyed = ring.map((seat) => {
    const neighbours = [...(prep.adjacency.get(seat.id)?.keys() ?? [])]
      .map((id) => seats.get(id))
      .filter((other): other is Seat => !!other && other.id !== seat.id)
      .map((other) => other.angle);
    return { seat, key: neighbours.length ? circularMean(neighbours) : normalizeAngle(seat.angle) };
  });

  keyed.sort((a, b) => a.key - b.key || a.seat.slug.localeCompare(b.seat.slug));

  // The SLOT count, not the node count: re-spreading two nodes over a full turn would put them
  // back on a diameter and undo the seeding this pass runs after.
  const step = TAU / ringSlots(keyed.length);
  const offset = circularMean(keyed.map((row, index) => row.key - index * step));

  keyed.forEach((row, index) => {
    row.seat.angle = offset + index * step;
  });
}

// ---------------------------------------------------------------------------
// Shared tail
// ---------------------------------------------------------------------------

function radiusOf(opts: Required<GraphLayoutOptions>): number {
  return Math.max(1, Math.min(opts.width, opts.height) / 2 - opts.margin);
}

/**
 * Apply the CLIENT node cap and re-derive everything from the survivors.
 *
 * Re-preparing (rather than trimming) is what keeps the invariant the server also holds: every
 * drawn edge has both of its ends on the canvas. `keep` is never dropped — in the ego graph that
 * is the centre.
 */
function capNodes(prep: Prepared, cap: number, keep: string | null): { prep: Prepared; overflow: number } {
  if (prep.nodes.length <= cap) return { prep, overflow: 0 };

  // Nearest first, then best connected — the same priority the server's own cap applies, so the
  // client trimming a little further never contradicts what the server chose to send.
  const distanceKey = (node: KnowledgeGraphNode): number => node.distance ?? Number.MAX_SAFE_INTEGER;
  const ranked = [...prep.nodes].sort(
    (a, b) =>
      distanceKey(a) - distanceKey(b) ||
      (prep.degree.get(b.id) ?? 0) - (prep.degree.get(a.id) ?? 0) ||
      a.slug.localeCompare(b.slug) ||
      a.id.localeCompare(b.id),
  );

  const kept = new Set<string>(keep ? [keep] : []);
  for (const node of ranked) {
    if (kept.size >= cap) break;
    kept.add(node.id);
  }

  const overflow = prep.nodes.length - kept.size;
  return {
    prep: prepare(
      {
        center: null,
        nodes: prep.nodes,
        edges: prep.edges,
        ghosts: prep.ghosts.map((ghost) => ({
          target_slug: ghost.slug,
          from_ids: ghost.sources,
          count: ghost.count,
        })),
        truncated: { hidden_nodes: 0, hidden_edges: 0 },
      },
      // The kinds already did their filtering in the first pass; re-running with every kind here
      // would resurrect the lines the chips turned off.
      inferKinds(prep),
      kept,
    ),
    overflow,
  };
}

/** The kinds still present after the first filter — so a re-prepare cannot widen the picture. */
function inferKinds(prep: Prepared): GraphEdgeKind[] {
  const kinds = new Set<GraphEdgeKind>(prep.edges.map(edgeKind));
  if (prep.ghosts.length > 0) kinds.add('ghost');
  return [...kinds];
}

/** Turn seats + edges into the final, rounded, snapshot-stable layout. */
function assemble(
  mode: 'ego' | 'overview',
  centerId: string | null,
  seats: Seat[],
  prep: Prepared,
  opts: Required<GraphLayoutOptions>,
  overflow: number,
): GraphLayout {
  const nodes = seats
    .map(toPlacement)
    // Ring first, then the drawn degree, then the slug: a total order, and one that paints hubs
    // last so they sit on top of the lines that reach them.
    .sort((a, b) => a.ring - b.ring || b.degree - a.degree || a.slug.localeCompare(b.slug));

  const byId = new Map(nodes.map((node) => [node.id, node]));

  const edges: GraphEdgePlacement[] = [];

  for (const edge of prep.edges) {
    const from = byId.get(edge.from);
    const to = byId.get(edge.to);
    if (!from || !to) continue;
    const kind = edgeKind(edge);
    edges.push(
      placeEdge({
        // A PREVIEW edge (the composer) has no row and therefore no id — key on the endpoints, the
        // kind and (for a relation) the verb, which is what makes it unique.
        id: edge.id ?? syntheticEdgeKey(edge),
        kind,
        score: edge.score,
        evidence: edge.evidence,
        // A wikilink and a mention both POINT somewhere (A names B without B naming A, and both
        // directions can exist as two separate edges), so both get an arrowhead. A similarity is
        // mutual and a manual edge asserts no direction.
        //
        // A RELATION's direction depends on its VERB rather than on its layer: "is a member of"
        // points, "knows" does not. The symmetric flag is data from the server — a symmetric row is
        // stored with the smaller id first, which is canonical bookkeeping and not an editorial
        // claim about who comes first, so drawing an arrow off it would invent a direction.
        directed:
          kind === 'relation'
            ? edge.symmetric !== true
            : kind === 'wikilink' || kind === 'mention',
        // Straight from the wire — never re-derived from the kind (both graph endpoints send it).
        canDismiss: edge.can_be_dismissed === true,
        relation:
          kind === 'relation'
            ? {
                relationType: edge.relation_type ?? null,
                label: edge.label ?? null,
                inverseLabel: edge.inverse_label ?? null,
                symmetric: edge.symmetric === true,
                validFrom: edge.valid_from ?? null,
                validTo: edge.valid_to ?? null,
                state: edge.state ?? null,
              }
            : null,
        from,
        to,
      }),
    );
  }

  for (const ghost of prep.ghosts) {
    const target = byId.get(ghostId(ghost.slug));
    if (!target) continue;
    for (const sourceId of ghost.sources) {
      const from = byId.get(sourceId);
      if (!from) continue;
      edges.push(
        placeEdge({
          id: `ghost:${sourceId}:${ghost.slug}`,
          kind: 'ghost',
          score: null,
          evidence: null,
          directed: true,
          // A ghost line is synthesised from an aggregate: there is no row to dismiss.
          canDismiss: false,
          from,
          to: target,
        }),
      );
    }
  }

  edges.sort((a, b) => a.id.localeCompare(b.id));

  return {
    mode,
    centerId,
    nodes,
    edges,
    overflow,
    world: { width: opts.width, height: opts.height },
    fit: fitBox(nodes, opts),
  };
}

function placeEdge(input: {
  id: string;
  kind: GraphEdgeKind;
  score: number | null;
  evidence: KnowledgeLinkEvidence | null;
  directed: boolean;
  canDismiss: boolean;
  relation?: Pick<
    GraphEdgePlacement,
    'relationType' | 'label' | 'inverseLabel' | 'symmetric' | 'validFrom' | 'validTo' | 'state'
  > | null;
  from: GraphNodePlacement;
  to: GraphNodePlacement;
}): GraphEdgePlacement {
  const dx = input.to.x - input.from.x;
  const dy = input.to.y - input.from.y;
  const length = Math.sqrt(dx * dx + dy * dy) || 1;
  const ux = dx / length;
  const uy = dy / length;

  // Trimmed to the circles' rims (+2 at the head, so an arrow touches the node instead of
  // disappearing under it). Two nodes closer than their radii keep a hairline rather than
  // inverting the segment.
  const startGap = Math.min(input.from.r, length / 2 - 0.5);
  const endGap = Math.min(input.to.r + 2, length / 2 - 0.5);

  const x1 = input.from.x + ux * startGap;
  const y1 = input.from.y + uy * startGap;
  const x2 = input.to.x - ux * endGap;
  const y2 = input.to.y - uy * endGap;

  return {
    id: input.id,
    from: input.from.id,
    to: input.to.id,
    kind: input.kind,
    score: input.score,
    evidence: input.evidence,
    directed: input.directed,
    canDismiss: input.canDismiss,
    relationType: input.relation?.relationType ?? null,
    label: input.relation?.label ?? null,
    inverseLabel: input.relation?.inverseLabel ?? null,
    symmetric: input.relation?.symmetric ?? false,
    validFrom: input.relation?.validFrom ?? null,
    validTo: input.relation?.validTo ?? null,
    state: input.relation?.state ?? null,
    // A relation with no state at all is treated as CURRENT, not as historical: absence of a
    // lifecycle is how a preview edge arrives, and greying those out would say they had ended.
    historical: input.relation?.state != null && input.relation.state !== 'active',
    x1: round2(x1),
    y1: round2(y1),
    x2: round2(x2),
    y2: round2(y2),
    mx: round2((x1 + x2) / 2),
    my: round2((y1 + y2) / 2),
    width: edgeWidth(input.kind, input.score),
  };
}

/**
 * The "fit to screen" rectangle: the tight bounding box of every dot, plus 8 %, normalised to the
 * canvas aspect ratio so zooming does not distort, and floored so a one-node graph does not zoom
 * to a dot the size of the screen.
 */
function fitBox(
  nodes: GraphNodePlacement[],
  opts: Required<GraphLayoutOptions>,
): { x: number; y: number; width: number; height: number } {
  const aspect = opts.width / opts.height;
  const minWidth = opts.width * 0.35;

  if (nodes.length === 0) {
    return { x: round2(-opts.width / 2), y: round2(-opts.height / 2), width: opts.width, height: opts.height };
  }

  let minX = Infinity;
  let maxX = -Infinity;
  let minY = Infinity;
  let maxY = -Infinity;
  for (const node of nodes) {
    // The label sits under the dot, so the box reserves a little more room below than above.
    minX = Math.min(minX, node.x - node.r - 12);
    maxX = Math.max(maxX, node.x + node.r + 12);
    minY = Math.min(minY, node.y - node.r - 8);
    maxY = Math.max(maxY, node.y + node.r + 22);
  }

  const pad = 0.08 * Math.max(maxX - minX, maxY - minY);
  let width = Math.max(maxX - minX + 2 * pad, minWidth);
  let height = Math.max(maxY - minY + 2 * pad, minWidth / aspect);
  // Normalise to the canvas aspect so `preserveAspectRatio` has nothing to letterbox away.
  if (width / height > aspect) height = width / aspect;
  else width = height * aspect;

  const cx = (minX + maxX) / 2;
  const cy = (minY + maxY) / 2;

  return {
    x: round2(cx - width / 2),
    y: round2(cy - height / 2),
    width: round2(width),
    height: round2(height),
  };
}

/**
 * The one entry point a screen should call: the payload says which picture it is (`center` set or
 * not), so the caller never has to branch on a mode it also has to keep in sync with the request.
 */
export function layoutKnowledgeGraph(
  data: KnowledgeGraphData,
  options: GraphLayoutOptions = {},
): GraphLayout {
  return data.center ? layoutEgoGraph(data, options) : layoutOverviewGraph(data, options);
}
