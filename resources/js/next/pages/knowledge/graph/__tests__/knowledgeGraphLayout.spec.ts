// knowledgeGraphLayout.spec — the layout is a CONTRACT, not a drawing.
//
// The single most important property is DETERMINISM: the same payload must produce byte-identical
// coordinates, in any input order, on any machine. Everything else the graph promises (a stable
// mental map, a deep link that looks the same after a refresh, an SVG snapshot that means
// something) is downstream of that. So the pinned snapshot here is DATA — ids, rings and rounded
// coordinates — never pixels or markup.
import { describe, it, expect } from 'vitest';
import {
  ghostId,
  layoutEgoGraph,
  layoutKnowledgeGraph,
  layoutOverviewGraph,
  type GraphEdgeKind,
} from '../knowledgeGraphLayout';
import type { KnowledgeGraphData, KnowledgeGraphEdge, KnowledgeGraphNode } from '../../types';

// --- Fixtures ---------------------------------------------------------------

function node(
  id: string,
  slug: string,
  extra: Partial<KnowledgeGraphNode> = {},
): KnowledgeGraphNode {
  return {
    id,
    slug,
    title: slug.replace(/-/g, ' '),
    status: 'approved',
    is_stale: false,
    degree: 0,
    distance: null,
    ...extra,
  };
}

function edge(
  id: string,
  from: string,
  to: string,
  source: KnowledgeGraphEdge['source'] = 'wikilink',
  score: number | null = null,
): KnowledgeGraphEdge {
  return { id, from, to, source, score, evidence: null, dismissed: false };
}

/** A small EGO payload: centre, three first-hop neighbours of three kinds, one second hop, one ghost. */
function egoPayload(): KnowledgeGraphData {
  return {
    center: 'c',
    nodes: [
      node('c', 'centrum', { distance: 0 }),
      node('w', 'wiki-neighbour', { distance: 1 }),
      node('m', 'manual-neighbour', { distance: 1 }),
      node('s1', 'sim-high', { distance: 1 }),
      node('s2', 'sim-low', { distance: 1, status: 'draft', is_stale: true }),
      node('d2', 'second-hop', { distance: 2 }),
    ],
    edges: [
      edge('e-wiki', 'c', 'w', 'wikilink'),
      edge('e-manual', 'c', 'm', 'manual'),
      edge('e-sim-high', 'c', 's1', 'similarity', 0.94),
      edge('e-sim-low', 's2', 'c', 'similarity', 0.88),
      edge('e-second', 'w', 'd2', 'wikilink'),
    ],
    ghosts: [{ target_slug: 'polityka-zwrotow', from_ids: ['c'], count: 4 }],
    truncated: { hidden_nodes: 0, hidden_edges: 0 },
  };
}

/** A small OVERVIEW payload: one hub, four spokes, two isolated entries, one ghost. */
function overviewPayload(): KnowledgeGraphData {
  return {
    center: null,
    nodes: [
      node('hub', 'hub'),
      node('a', 'alpha'),
      node('b', 'beta'),
      node('g', 'gamma'),
      node('d', 'delta'),
      node('i1', 'isolated-one'),
      node('i2', 'isolated-two'),
    ],
    edges: [
      edge('e1', 'hub', 'a'),
      edge('e2', 'hub', 'b'),
      edge('e3', 'hub', 'g'),
      edge('e4', 'hub', 'd'),
      edge('e5', 'a', 'b', 'similarity', 0.9),
    ],
    ghosts: [{ target_slug: 'brakujacy-wpis', from_ids: ['a'], count: 1 }],
    truncated: { hidden_nodes: 0, hidden_edges: 0 },
  };
}

/** Shuffle deterministically (reverse + interleave) — no RNG in a test that asserts determinism. */
function reshuffle<T>(items: T[]): T[] {
  const reversed = [...items].reverse();
  const out: T[] = [];
  for (let i = 0; i < reversed.length; i += 1) {
    if (i % 2 === 0) out.push(reversed[i]);
    else out.unshift(reversed[i]);
  }
  return out;
}

/** The comparable shape of a layout: what is placed, where. No markup, no pixels. */
function shapeOf(layout: ReturnType<typeof layoutEgoGraph>) {
  return {
    mode: layout.mode,
    centerId: layout.centerId,
    overflow: layout.overflow,
    fit: layout.fit,
    nodes: layout.nodes.map((n) => ({ id: n.id, ring: n.ring, x: n.x, y: n.y, r: n.r, degree: n.degree })),
    edges: layout.edges.map((e) => ({ id: e.id, kind: e.kind, x1: e.x1, y1: e.y1, x2: e.x2, y2: e.y2 })),
  };
}

// --- Determinism ------------------------------------------------------------

describe('knowledgeGraphLayout — determinism', () => {
  it('produces identical coordinates for the same ego payload, twice', () => {
    expect(shapeOf(layoutEgoGraph(egoPayload()))).toEqual(shapeOf(layoutEgoGraph(egoPayload())));
  });

  it('is stable against the order nodes, edges and ghosts arrive in', () => {
    const straight = egoPayload();
    const jumbled: KnowledgeGraphData = {
      ...straight,
      nodes: reshuffle(straight.nodes),
      edges: reshuffle(straight.edges),
      ghosts: reshuffle(straight.ghosts),
    };
    expect(shapeOf(layoutEgoGraph(jumbled))).toEqual(shapeOf(layoutEgoGraph(straight)));
  });

  it('is stable against input order in the overview too (the server does not promise one)', () => {
    const straight = overviewPayload();
    const jumbled: KnowledgeGraphData = {
      ...straight,
      nodes: reshuffle(straight.nodes),
      edges: reshuffle(straight.edges),
    };
    expect(shapeOf(layoutOverviewGraph(jumbled))).toEqual(shapeOf(layoutOverviewGraph(straight)));
  });

  it('pins the EGO layout to a data snapshot (coordinates, not pixels)', () => {
    expect(shapeOf(layoutEgoGraph(egoPayload()))).toMatchSnapshot();
  });

  it('pins the OVERVIEW layout to a data snapshot', () => {
    expect(shapeOf(layoutOverviewGraph(overviewPayload()))).toMatchSnapshot();
  });

  // ── NON-DEGENERACY ────────────────────────────────────────────────────────
  //
  // The bug this guards: a four-entry base rendered as a VERTICAL LINE. Rings of one and two
  // nodes, angles handed out sequentially from twelve o'clock, and a barycentric re-spread over a
  // full turn put a two-node ring at 0° and 180° — with the hub at the origin, three collinear
  // points. Tutte (1963): barycentric iteration without a fixed frame converges to a point or a
  // line. These assertions are the frame's receipt.

  /** Are all the placements on one straight line (within a hair's width)? */
  function collinear(points: Array<{ x: number; y: number }>): boolean {
    if (points.length < 3) return true;
    const [a, b] = [points[0], points[points.length - 1]];
    return points.every(
      (p) => Math.abs((b.x - a.x) * (p.y - a.y) - (b.y - a.y) * (p.x - a.x)) < 1e-6,
    );
  }

  describe('the owner’s four-node overview is a SHAPE, not an axis', () => {
    const fourNodes: KnowledgeGraphData = {
      center: null,
      nodes: [node('a', 'alpha'), node('b', 'beta'), node('c', 'gamma'), node('d', 'delta')],
      edges: [edge('e1', 'a', 'b'), edge('e2', 'a', 'c')],
      ghosts: [],
      truncated: { hidden_nodes: 0, hidden_edges: 0 },
    };

    it('does not put every node on one vertical or horizontal line', () => {
      const nodes = layoutOverviewGraph(fourNodes).nodes;

      expect(new Set(nodes.map((n) => n.x)).size).toBeGreaterThan(1);
      expect(new Set(nodes.map((n) => n.y)).size).toBeGreaterThan(1);
    });

    it('does not put every node on ANY straight line', () => {
      expect(collinear(layoutOverviewGraph(fourNodes).nodes)).toBe(false);
    });
  });

  it('never lands a TWO-node ring on a diameter (the collapse in miniature)', () => {
    // Two nodes opposite each other, plus a hub at the origin, IS the degenerate line. The ring's
    // minimum slot count is what makes that unreachable.
    const payload: KnowledgeGraphData = {
      center: null,
      nodes: [node('h', 'hub'), node('x', 'x-one'), node('y', 'y-two')],
      edges: [edge('e1', 'h', 'x'), edge('e2', 'h', 'y')],
      ghosts: [],
      truncated: { hidden_nodes: 0, hidden_edges: 0 },
    };

    const nodes = layoutOverviewGraph(payload).nodes;
    const ring = nodes.filter((n) => n.ring === 1);
    expect(ring).toHaveLength(2);

    // Their angular separation must not be a half turn.
    const delta = Math.abs(
      Math.atan2(ring[0].y, ring[0].x) - Math.atan2(ring[1].y, ring[1].x),
    );
    const separation = Math.min(delta, Math.PI * 2 - delta);
    expect(Math.abs(separation - Math.PI)).toBeGreaterThan(0.01);
    expect(collinear(nodes)).toBe(false);
  });

  it('keeps small EGO graphs off the axis too', () => {
    // One centre + two neighbours is the same trap on the other code path.
    const payload: KnowledgeGraphData = {
      center: 'c',
      nodes: [
        node('c', 'centrum', { distance: 0 }),
        node('n1', 'first', { distance: 1 }),
        node('n2', 'second', { distance: 1 }),
      ],
      edges: [edge('e1', 'c', 'n1'), edge('e2', 'c', 'n2')],
      ghosts: [],
      truncated: { hidden_nodes: 0, hidden_edges: 0 },
    };

    expect(collinear(layoutEgoGraph(payload).nodes)).toBe(false);
  });

  it('never emits NaN or Infinity, whatever the payload shape', () => {
    for (const layout of [
      layoutEgoGraph(egoPayload()),
      layoutOverviewGraph(overviewPayload()),
      layoutOverviewGraph({ center: null, nodes: [node('only', 'only')], edges: [], ghosts: [], truncated: { hidden_nodes: 0, hidden_edges: 0 } }),
    ]) {
      for (const n of layout.nodes) {
        for (const value of [n.x, n.y, n.r, n.angle]) expect(Number.isFinite(value)).toBe(true);
      }
      for (const e of layout.edges) {
        for (const value of [e.x1, e.y1, e.x2, e.y2, e.width]) expect(Number.isFinite(value)).toBe(true);
      }
      for (const value of Object.values(layout.fit)) expect(Number.isFinite(value)).toBe(true);
    }
  });
});

// --- The ego picture --------------------------------------------------------

describe('knowledgeGraphLayout — ego', () => {
  it('puts the centre at the origin', () => {
    const layout = layoutEgoGraph(egoPayload());
    const centre = layout.nodes.find((n) => n.id === 'c');
    expect(centre).toMatchObject({ x: 0, y: 0, ring: 0, isCenter: true });
  });

  it('orders the first ring wikilink → manual → similarity (by score) → ghost', () => {
    const layout = layoutEgoGraph(egoPayload());
    const ring1 = layout.nodes
      .filter((n) => n.ring === 1)
      .sort((a, b) => a.angle - b.angle)
      .map((n) => n.slug);

    expect(ring1).toEqual([
      'wiki-neighbour',
      'manual-neighbour',
      'sim-high', // 0.94 before 0.88
      'sim-low',
      'polityka-zwrotow', // the ghost of the centre closes the ring
    ]);
  });

  it('anchors a second-hop node in its parent’s sector, not on the parent', () => {
    const layout = layoutEgoGraph(egoPayload());
    const parent = layout.nodes.find((n) => n.slug === 'wiki-neighbour');
    const child = layout.nodes.find((n) => n.slug === 'second-hop');

    expect(child?.ring).toBe(2);
    // The only child of its parent → same direction, further out.
    expect(child?.angle).toBeCloseTo(parent?.angle ?? 0, 5);
    expect(Math.hypot(child?.x ?? 0, child?.y ?? 0)).toBeGreaterThan(
      Math.hypot(parent?.x ?? 0, parent?.y ?? 0),
    );
  });

  it('falls back to the overview when the centre is not among the nodes', () => {
    const orphaned: KnowledgeGraphData = { ...egoPayload(), center: 'not-in-this-response' };
    expect(layoutEgoGraph(orphaned).mode).toBe('overview');
  });

  it('dispatches on the payload: `center` set ⇒ ego, absent ⇒ overview', () => {
    expect(layoutKnowledgeGraph(egoPayload()).mode).toBe('ego');
    expect(layoutKnowledgeGraph(overviewPayload()).mode).toBe('overview');
  });
});

// --- The overview picture ---------------------------------------------------

describe('knowledgeGraphLayout — overview', () => {
  it('puts the best-connected entries on the innermost ring', () => {
    const layout = layoutOverviewGraph(overviewPayload());
    const hub = layout.nodes.find((n) => n.id === 'hub');
    expect(hub?.ring).toBe(0);
    // Rings really are concentric: nothing on ring 0 is further out than anything on ring 2.
    const radius = (id: string): number => {
      const n = layout.nodes.find((x) => x.id === id);
      return Math.hypot(n?.x ?? 0, n?.y ?? 0);
    };
    expect(radius('hub')).toBeLessThan(radius('i1')); // `i1` = the isolated entry, on the outer ring
  });

  it('puts a SOLE innermost node at the origin (a ring of one is not a ring)', () => {
    // Five nodes ⇒ ceil(5 × 0.15) = 1 on the inner ring.
    const layout = layoutOverviewGraph({
      center: null,
      nodes: [node('hub', 'hub'), node('a', 'alpha'), node('b', 'beta'), node('g', 'gamma'), node('d', 'delta')],
      edges: [edge('e1', 'hub', 'a'), edge('e2', 'hub', 'b'), edge('e3', 'hub', 'g')],
      ghosts: [],
      truncated: { hidden_nodes: 0, hidden_edges: 0 },
    });
    expect(layout.nodes.find((n) => n.id === 'hub')).toMatchObject({ ring: 0, x: 0, y: 0 });
  });

  it('sizes a node by the degree it actually SHOWS, not by the server’s count', () => {
    // The payload claims 99 on the wire; only two edges are drawn at `a`.
    const payload = overviewPayload();
    payload.nodes = payload.nodes.map((n) => (n.id === 'a' ? { ...n, degree: 99 } : n));
    const layout = layoutOverviewGraph(payload);

    const a = layout.nodes.find((n) => n.id === 'a');
    expect(a?.degree).toBe(3); // hub↔a, a↔b, and the ghost line leaving a
    expect(a?.r).toBeLessThan(18);
  });

  it('keeps isolated entries on the canvas — a young base is not an empty one', () => {
    const layout = layoutOverviewGraph(overviewPayload());
    const isolated = layout.nodes.filter((n) => n.slug.startsWith('isolated'));
    expect(isolated).toHaveLength(2);
    expect(isolated.every((n) => n.degree === 0)).toBe(true);
  });

  it('reports its own overflow when the client cap bites, and keeps the cap’s survivors', () => {
    const payload = overviewPayload();
    const layout = layoutOverviewGraph(payload, { cap: 3 });

    expect(layout.nodes.filter((n) => n.kind === 'entry')).toHaveLength(3);
    expect(layout.overflow).toBe(payload.nodes.length - 3);
    // The best-connected node always survives.
    expect(layout.nodes.some((n) => n.id === 'hub')).toBe(true);
  });

  it('never draws an edge with an end that was capped away', () => {
    const layout = layoutOverviewGraph(overviewPayload(), { cap: 2 });
    const ids = new Set(layout.nodes.map((n) => n.id));
    for (const e of layout.edges) {
      expect(ids.has(e.from)).toBe(true);
      expect(ids.has(e.to)).toBe(true);
    }
  });
});

// --- Ghosts and edge kinds --------------------------------------------------

describe('knowledgeGraphLayout — ghosts and kinds', () => {
  it('places a ghost as its own node with a dashed line from every entry that meant it', () => {
    const payload = overviewPayload();
    payload.ghosts = [{ target_slug: 'brakujacy-wpis', from_ids: ['a', 'b'], count: 2 }];
    const layout = layoutOverviewGraph(payload);

    const ghost = layout.nodes.find((n) => n.id === ghostId('brakujacy-wpis'));
    expect(ghost).toMatchObject({ kind: 'ghost', entryId: null, ghostCount: 2, slug: 'brakujacy-wpis' });

    const lines = layout.edges.filter((e) => e.kind === 'ghost');
    expect(lines).toHaveLength(2);
    expect(new Set(lines.map((e) => e.from))).toEqual(new Set(['a', 'b']));
  });

  it('drops a ghost whose sources are all off-canvas', () => {
    const payload = overviewPayload();
    payload.ghosts = [{ target_slug: 'sierota', from_ids: ['not-here'], count: 1 }];
    expect(layoutOverviewGraph(payload).nodes.some((n) => n.kind === 'ghost')).toBe(false);
  });

  it('turning a kind off removes its LINES but never its nodes', () => {
    const kinds: GraphEdgeKind[] = ['wikilink', 'manual', 'ghost'];
    const full = layoutEgoGraph(egoPayload());
    const filtered = layoutEgoGraph(egoPayload(), { kinds });

    expect(full.edges.some((e) => e.kind === 'similarity')).toBe(true);
    expect(filtered.edges.some((e) => e.kind === 'similarity')).toBe(false);
    // `sim-high` is still a neighbour of the centre; it just has no line right now.
    expect(filtered.nodes.some((n) => n.slug === 'sim-high')).toBe(true);
  });

  it('turning ghosts off removes the ghost nodes as well — they exist only as links', () => {
    const layout = layoutEgoGraph(egoPayload(), { kinds: ['wikilink', 'similarity', 'manual'] });
    expect(layout.nodes.some((n) => n.kind === 'ghost')).toBe(false);
    expect(layout.edges.some((e) => e.kind === 'ghost')).toBe(false);
  });

  it('marks the POINTING kinds as directed, and scales similarity by score', () => {
    const payload = egoPayload();
    payload.nodes.push(node('mn', 'mention-target', { distance: 1 }));
    payload.edges.push(edge('e-mention', 'c', 'mn', 'mention'));

    const layout = layoutEgoGraph(payload);
    const byId = new Map(layout.edges.map((e) => [e.id, e]));

    // A wikilink and a mention both point (A naming B is not B naming A); a similarity is mutual
    // and a manual edge asserts no direction.
    expect(byId.get('e-wiki')?.directed).toBe(true);
    expect(byId.get('e-mention')?.directed).toBe(true);
    expect(byId.get('e-sim-high')?.directed).toBe(false);
    expect(byId.get('e-manual')?.directed).toBe(false);
    expect(byId.get('e-sim-high')?.width).toBeGreaterThan(byId.get('e-sim-low')?.width ?? 0);
  });

  it('ranks a MENTION above a similarity and below a wikilink in the first ring', () => {
    // The ego ordering is an editorial claim: what the text says, then what a human drew, then a
    // name found in the text, then a machine's guess.
    const payload: KnowledgeGraphData = {
      center: 'c',
      nodes: [
        node('c', 'centrum', { distance: 0 }),
        node('w', 'a-wiki', { distance: 1 }),
        node('m', 'b-manual', { distance: 1 }),
        node('n', 'c-mention', { distance: 1 }),
        node('s', 'd-similar', { distance: 1 }),
      ],
      edges: [
        edge('e1', 'c', 'w', 'wikilink'),
        edge('e2', 'c', 'm', 'manual'),
        edge('e3', 'c', 'n', 'mention'),
        edge('e4', 'c', 's', 'similarity', 0.99),
      ],
      ghosts: [],
      truncated: { hidden_nodes: 0, hidden_edges: 0 },
    };

    const ring1 = layoutEgoGraph(payload)
      .nodes.filter((n) => n.ring === 1)
      .sort((a, b) => a.angle - b.angle)
      .map((n) => n.slug);

    expect(ring1).toEqual(['a-wiki', 'b-manual', 'c-mention', 'd-similar']);
  });

  it('lets the mention chip be turned off like any other kind', () => {
    const payload = egoPayload();
    payload.nodes.push(node('mn', 'mention-target', { distance: 1 }));
    payload.edges.push(edge('e-mention', 'c', 'mn', 'mention'));

    const without = layoutEgoGraph(payload, { kinds: ['wikilink', 'similarity', 'manual', 'ghost'] });
    expect(without.edges.some((e) => e.kind === 'mention')).toBe(false);
    // The node it reached stays — a filter removes LINES, never entries.
    expect(without.nodes.some((n) => n.slug === 'mention-target')).toBe(true);
  });

  it('trims every line to the rims of the circles it joins', () => {
    const layout = layoutEgoGraph(egoPayload());
    const byId = new Map(layout.nodes.map((n) => [n.id, n]));

    for (const e of layout.edges) {
      const from = byId.get(e.from);
      const to = byId.get(e.to);
      if (!from || !to) continue;
      // The drawn segment starts outside the source circle and ends outside the target circle.
      expect(Math.hypot(e.x1 - from.x, e.y1 - from.y)).toBeGreaterThan(0);
      expect(Math.hypot(e.x2 - to.x, e.y2 - to.y)).toBeGreaterThan(0);
    }
  });

  it('drops a self-edge and an edge with an unknown end rather than drawing a stub', () => {
    const payload = overviewPayload();
    payload.edges = [...payload.edges, edge('self', 'hub', 'hub'), edge('dangling', 'hub', 'nope')];
    const layout = layoutOverviewGraph(payload);
    expect(layout.edges.some((e) => e.id === 'self' || e.id === 'dangling')).toBe(false);
  });
});

// --- Fit box ----------------------------------------------------------------

describe('knowledgeGraphLayout — fit', () => {
  it('encloses every node it placed', () => {
    const layout = layoutOverviewGraph(overviewPayload());
    const { x, y, width, height } = layout.fit;
    for (const n of layout.nodes) {
      expect(n.x).toBeGreaterThanOrEqual(x);
      expect(n.x).toBeLessThanOrEqual(x + width);
      expect(n.y).toBeGreaterThanOrEqual(y);
      expect(n.y).toBeLessThanOrEqual(y + height);
    }
  });

  it('keeps the canvas aspect ratio so zooming cannot distort', () => {
    const layout = layoutOverviewGraph(overviewPayload(), { width: 1200, height: 900 });
    expect(layout.fit.width / layout.fit.height).toBeCloseTo(1200 / 900, 2);
  });

  it('does not zoom a one-node graph into a wall of dot', () => {
    const layout = layoutOverviewGraph({
      center: null,
      nodes: [node('only', 'only')],
      edges: [],
      ghosts: [],
      truncated: { hidden_nodes: 0, hidden_edges: 0 },
    });
    expect(layout.fit.width).toBeGreaterThan(300);
  });
});
