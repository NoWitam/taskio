// @vitest-environment happy-dom
// KnowledgeGraphCanvas.spec — the LABEL BUDGET.
//
// The rule this replaces was "degree ≥ 3 gets a label", which has no equivalent in any tool that
// draws graphs and answered the wrong question: whether a name fits is about SPACE, not about how
// well connected its entry is. On a four-entry base it hid half the labels at the exact moment
// there was room for all of them.
//
// What is pinned here is the property the occupancy grid buys with no special case in the code:
//   • a small graph gets EVERY label, because the cells are simply empty;
//   • a crowded graph gets a subset, and the same subset every time (forced → degree → slug);
//   • the centre and whatever is hovered are labelled regardless of collisions.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';
import KnowledgeGraphCanvas from '../KnowledgeGraphCanvas.vue';
import { layoutKnowledgeGraph } from '../knowledgeGraphLayout';
import type { KnowledgeGraphData, KnowledgeGraphNode } from '../../types';

function node(id: string, slug: string, over: Partial<KnowledgeGraphNode> = {}): KnowledgeGraphNode {
  return {
    id,
    slug,
    title: slug,
    status: 'approved',
    is_stale: false,
    degree: 0,
    distance: null,
    ...over,
  };
}

function payload(nodes: KnowledgeGraphNode[], edges: KnowledgeGraphData['edges'] = []): KnowledgeGraphData {
  return { center: null, nodes, edges, ghosts: [], truncated: { hidden_nodes: 0, hidden_edges: 0 } };
}

function mountCanvas(data: KnowledgeGraphData) {
  return mount(KnowledgeGraphCanvas, {
    attachTo: document.body,
    props: { layout: layoutKnowledgeGraph(data) },
  });
}

/** The slugs that actually got a `<text>` label drawn. */
function labelled(wrapper: ReturnType<typeof mountCanvas>): string[] {
  return wrapper.findAll('text.next-kg-label').map((t) => t.text()).sort();
}

describe('KnowledgeGraphCanvas — which nodes get a label', () => {
  beforeEach(() => {
    installBrowserMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  it('labels EVERY node of a small graph — the room is there, so the names are shown', () => {
    // The owner's case: four entries, two of them with degree 0. Under the old degree threshold
    // exactly one of these would have been named.
    const wrapper = mountCanvas(
      payload(
        [node('a', 'alpha'), node('b', 'beta'), node('c', 'gamma'), node('d', 'delta')],
        [
          { id: 'e1', from: 'a', to: 'b', source: 'wikilink', score: null, evidence: null, dismissed: false, can_be_dismissed: false },
        ],
      ),
    );

    expect(labelled(wrapper)).toEqual(['alpha', 'beta', 'delta', 'gamma']);
    wrapper.unmount();
  });

  it('labels every node of a ten-node graph (the ≤ ~10 property, with no special case)', () => {
    const nodes = Array.from({ length: 10 }, (_, i) => node(`n${i}`, `entry-${i}`));
    const wrapper = mountCanvas(payload(nodes));

    expect(labelled(wrapper)).toHaveLength(10);
    wrapper.unmount();
  });

  it('respects the budget on a crowded graph rather than drawing a wall of text', () => {
    // Sixty nodes in the same world box: some labels must lose, or the picture is unreadable.
    const nodes = Array.from({ length: 60 }, (_, i) =>
      node(`n${i}`, `a-fairly-long-entry-name-${i}`),
    );
    const wrapper = mountCanvas(payload(nodes));

    const drawn = labelled(wrapper);
    expect(drawn.length).toBeGreaterThan(0);
    expect(drawn.length).toBeLessThan(60);
    wrapper.unmount();
  });

  it('picks the SAME labels every time — the tie-break is the slug, not array order', () => {
    const nodes = Array.from({ length: 60 }, (_, i) => node(`n${i}`, `entry-name-${i}`));

    const first = mountCanvas(payload(nodes));
    const drawnFirst = labelled(first);
    first.unmount();

    // Same graph, nodes handed over in the opposite order.
    const second = mountCanvas(payload([...nodes].reverse()));
    const drawnSecond = labelled(second);
    second.unmount();

    expect(drawnSecond).toEqual(drawnFirst);
  });

  it('prefers the better-connected node when two labels compete for one cell', () => {
    // Two nodes, one with a real degree. Crowd the canvas so the budget actually bites.
    const nodes = [
      node('hub', 'hub-entry', { degree: 9 }),
      ...Array.from({ length: 40 }, (_, i) => node(`n${i}`, `a-long-lonely-entry-${i}`)),
    ];
    const edges = Array.from({ length: 9 }, (_, i) => ({
      id: `e${i}`,
      from: 'hub',
      to: `n${i}`,
      source: 'wikilink' as const,
      score: null,
      evidence: null,
      dismissed: false,
      can_be_dismissed: false,
    }));

    const wrapper = mountCanvas(payload(nodes, edges));
    expect(labelled(wrapper)).toContain('hub-entry');
    wrapper.unmount();
  });

  it('always labels the CENTRE, even where a label would collide', () => {
    const nodes = [
      node('c', 'centrum', { distance: 0 }),
      ...Array.from({ length: 30 }, (_, i) => node(`n${i}`, `neighbour-with-a-name-${i}`, { distance: 1 })),
    ];
    const edges = nodes.slice(1).map((n, i) => ({
      id: `e${i}`,
      from: 'c',
      to: n.id,
      source: 'wikilink' as const,
      score: null,
      evidence: null,
      dismissed: false,
      can_be_dismissed: false,
    }));

    const wrapper = mount(KnowledgeGraphCanvas, {
      attachTo: document.body,
      props: { layout: layoutKnowledgeGraph({ ...payload(nodes, edges), center: 'c' }) },
    });

    expect(labelled(wrapper)).toContain('centrum');
    wrapper.unmount();
  });

  it('truncates a long name instead of letting it run across the canvas', () => {
    const wrapper = mountCanvas(payload([node('a', 'a-really-very-long-entry-title-that-goes-on')]));

    const text = wrapper.find('text.next-kg-label').text();
    expect(text.length).toBeLessThanOrEqual(24);
    expect(text.endsWith('…')).toBe(true);
    wrapper.unmount();
  });

  it('keeps the backdrop behind every drawn label', () => {
    const wrapper = mountCanvas(payload([node('a', 'alpha'), node('b', 'beta')]));

    expect(wrapper.findAll('rect.next-kg-label-bg')).toHaveLength(
      wrapper.findAll('text.next-kg-label').length,
    );
    wrapper.unmount();
  });
});

// ------------------------------------------------------------------------------------------------
// RELATION EDGE LABELS — the fifth layer is the only one whose edge carries meaning.
//
// The four older kinds mean exactly one thing each, so the line pattern says all there is to say. A
// relation means one of fifteen things; an unlabelled one says only "there is some relation here".
// The cost is density: at the 60-node cap a busy base draws far more relations than fit as words,
// so past a budget only the hovered/selected edge is labelled — and the host says so in prose,
// because a canvas that quietly stops labelling is a canvas the reader stops trusting.

function relationEdge(id: string, from: string, to: string, over: Record<string, unknown> = {}) {
  return {
    id,
    kind: 'relation' as const,
    from,
    to,
    source: null,
    score: null,
    evidence: null,
    dismissed: false,
    can_be_dismissed: false,
    relation_type: 'member_of',
    label: 'is a member of',
    inverse_label: 'has member',
    symmetric: false,
    description: null,
    properties: null,
    valid_from: null,
    valid_to: null,
    state: 'active' as const,
    origin: 'human' as const,
    ...over,
  };
}

/** The text drawn ON edges, as opposed to the node labels. */
function edgeLabels(wrapper: ReturnType<typeof mountCanvas>): string[] {
  return wrapper.findAll('text.next-kg-edge-label').map((t) => t.text());
}

describe('relation edge labels', () => {
  beforeEach(() => {
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('labels a relation edge with the VERB, never the raw predicate id', () => {
    const wrapper = mountCanvas(
      payload([node('a', 'alpha'), node('b', 'beta')], [relationEdge('r1', 'a', 'b')]),
    );

    expect(edgeLabels(wrapper)).toHaveLength(1);
    expect(edgeLabels(wrapper)[0]).not.toContain('member_of');
    wrapper.unmount();
  });

  it('labels NOTHING on the four soft layers — their pattern already says everything', () => {
    const wrapper = mountCanvas(
      payload(
        [node('a', 'alpha'), node('b', 'beta')],
        [
          {
            id: 'l1',
            kind: 'link' as const,
            from: 'a',
            to: 'b',
            source: 'wikilink' as const,
            score: null,
            evidence: null,
            dismissed: false,
            can_be_dismissed: false,
          },
        ],
      ),
    );

    expect(edgeLabels(wrapper)).toHaveLength(0);
    wrapper.unmount();
  });

  it('puts the END YEAR in the label of a historical relation', () => {
    const wrapper = mountCanvas(
      payload(
        [node('a', 'alpha'), node('b', 'beta')],
        [relationEdge('r1', 'a', 'b', { state: 'ended', valid_to: '2026-03-01' })],
      ),
    );

    // The state has to survive being read by someone who cannot tell the muted stroke from the
    // ordinary one, so it is in the WORDS as well as in the colour.
    expect(edgeLabels(wrapper)[0]).toContain('2026');
    wrapper.unmount();
  });

  it('marks the historical edge in the DOM as well, not by colour alone', () => {
    const wrapper = mountCanvas(
      payload(
        [node('a', 'alpha'), node('b', 'beta')],
        [relationEdge('r1', 'a', 'b', { state: 'ended', valid_to: '2026-03-01' })],
      ),
    );

    expect(wrapper.find('.next-kg-edge.is-relation.is-historical').exists()).toBe(true);
    wrapper.unmount();
  });

  it('stops labelling past the budget, and reports that it has', () => {
    // 21 relation edges — one over the budget of 20.
    const nodes = Array.from({ length: 22 }, (_, i) => node(`n${i}`, `n${i}`));
    const edges = Array.from({ length: 21 }, (_, i) => relationEdge(`r${i}`, 'n0', `n${i + 1}`));
    const wrapper = mountCanvas(payload(nodes, edges));

    expect(edgeLabels(wrapper)).toHaveLength(0);
    // The host reads this to render the explanation — silence would be the real failure.
    expect((wrapper.vm as unknown as { edgeLabelsSuppressed: boolean }).edgeLabelsSuppressed).toBe(
      true,
    );
    wrapper.unmount();
  });

  it('labels every edge at exactly the budget — the limit is inclusive', () => {
    const nodes = Array.from({ length: 21 }, (_, i) => node(`n${i}`, `n${i}`));
    const edges = Array.from({ length: 20 }, (_, i) => relationEdge(`r${i}`, 'n0', `n${i + 1}`));
    const wrapper = mountCanvas(payload(nodes, edges));

    expect(edgeLabels(wrapper)).toHaveLength(20);
    expect((wrapper.vm as unknown as { edgeLabelsSuppressed: boolean }).edgeLabelsSuppressed).toBe(
      false,
    );
    wrapper.unmount();
  });

  it('still labels the SELECTED edge once the budget has been passed', () => {
    const nodes = Array.from({ length: 22 }, (_, i) => node(`n${i}`, `n${i}`));
    const edges = Array.from({ length: 21 }, (_, i) => relationEdge(`r${i}`, 'n0', `n${i + 1}`));
    const wrapper = mount(KnowledgeGraphCanvas, {
      attachTo: document.body,
      props: { layout: layoutKnowledgeGraph(payload(nodes, edges)), selectedId: 'n5' },
    });

    // Suppression is about the plate of overlapping words, not about the one edge being examined.
    expect(edgeLabels(wrapper).length).toBeGreaterThan(0);
    wrapper.unmount();
  });
});
