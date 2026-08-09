// @vitest-environment happy-dom
// KnowledgeGraphView.spec — the graph screen's acceptance criteria, as tests.
//
// Five of them are things a graph quietly gets wrong, and each has cost real products a rewrite:
//   1. edge TYPE readable without colour (pattern classes on the lines),
//   2. double-click / Enter RE-CENTRES and changes the URL (so a refresh redraws the same view),
//   3. ghosts are visible and offer "create this entry", prefilled from the slug,
//   4. the node cap is REPORTED ("+N more"), never silently applied,
//   5. the neighbour list is a real listbox with a virtual cursor — the canvas is aria-hidden, so
//      if this list is not operable the screen is not operable.
//
// The store, router and toast are mocked, so this is isolated from HTTP and from Pinia — the same
// shape as KnowledgeBasesView.spec.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale, translate } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { KnowledgeGraphData } from '../types';
/** Drive the one-of-N filter the way a user does: through the SegmentedControl's own model. */
async function pickGhosts(wrapper: ReturnType<typeof mountView>, value: string): Promise<void> {
  await wrapper.find('[data-ghost-filter]').findComponent({ name: 'Select' })
    .vm.$emit('update:modelValue', value);
  await nextTick();
}

async function pickLayer(wrapper: ReturnType<typeof mountView>, value: string): Promise<void> {
  await wrapper.find('[data-layer-filter]').findComponent({ name: 'SegmentedControl' })
    .vm.$emit('update:modelValue', value);
  await nextTick();
}

const h = vi.hoisted(() => {
  const graph: KnowledgeGraphData = {
    center: 'c',
    nodes: [
      { id: 'c', slug: 'centrum', title: 'Centrum', status: 'approved', is_stale: false, degree: 3, distance: 0 },
      { id: 'w', slug: 'wiki-target', title: 'Wiki target', status: 'approved', is_stale: false, degree: 1, distance: 1 },
      { id: 'm', slug: 'manual-target', title: 'Manual target', status: 'draft', is_stale: true, degree: 1, distance: 1 },
      { id: 's', slug: 'similar-target', title: 'Similar target', status: 'proposed', is_stale: false, degree: 1, distance: 1 },
      { id: 'n', slug: 'mention-target', title: 'Mention target', status: 'approved', is_stale: false, degree: 1, distance: 1 },
    ],
    edges: [
      { id: 'e-w', from: 'c', to: 'w', source: 'wikilink', score: null, evidence: null, dismissed: false, can_be_dismissed: false },
      { id: 'e-m', from: 'c', to: 'm', source: 'manual', score: null, evidence: null, dismissed: false, can_be_dismissed: false },
      { id: 'e-s', from: 'c', to: 's', source: 'similarity', score: 0.92, evidence: { chunks: [1, 4] }, dismissed: false, can_be_dismissed: true },
      // B10 — a MENTION: no score, and its evidence is where the name was found in the source.
      { id: 'e-n', from: 'c', to: 'n', source: 'mention', score: null, evidence: { char_start: 12, char_length: 7 }, dismissed: false, can_be_dismissed: true },
    ],
    ghosts: [{ target_slug: 'polityka-zwrotow', from_ids: ['c'], count: 4 }],
    truncated: { hidden_nodes: 7, hidden_edges: 2 },
  };

  return {
    graph,
    router: { push: vi.fn(), replace: vi.fn() },
    route: { name: 'next.knowledge.base.graph', params: { baseId: 'b1', slug: 'centrum' }, query: {} },
    toast: { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() },
    store: {
      graph: graph as KnowledgeGraphData | null,
      graphLoading: false,
      graphRefreshing: false,
      graphErrored: false,
      graphError: null as string | null,
      fetchGraph: vi.fn(),
      removeGraphEdge: vi.fn(),
      resetGraph: vi.fn(),
      openBase: {
        id: 'b1',
        name: 'Marka',
        index_summary: { total: 4, indexed: 4 },
        entries_count: 4,
      },
      entriesBySlug: new Map<string, unknown>([['centrum', { id: 'c', slug: 'centrum' }]]),
      entries: [],
      fetchEntries: vi.fn(),
      resetEntries: vi.fn(),
      entry: null as unknown,
      fetchEntry: vi.fn(),
      resetEntry: vi.fn(),
      dismissLink: vi.fn().mockResolvedValue({}),
      undismissLink: vi.fn().mockResolvedValue({}),
    },
  };
});

vi.mock('vue-router', () => ({ useRouter: () => h.router, useRoute: () => h.route }));
vi.mock('../../../app/stores/knowledge', () => ({ useKnowledgeStore: () => h.store }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));

import KnowledgeGraphView from '../KnowledgeGraphView.vue';

const t = translate;

function mountView() {
  return mount(KnowledgeGraphView, { attachTo: document.body });
}

describe('KnowledgeGraphView', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('pl');
    h.router.push.mockClear();
    h.router.replace.mockClear();
    h.store.fetchGraph.mockClear();
    h.store.dismissLink.mockClear();
    h.store.removeGraphEdge.mockClear();
    h.store.graph = h.graph;
    h.store.graphErrored = false;
    h.store.graphLoading = false;
    h.route.query = {};
    h.route.params = { baseId: 'b1', slug: 'centrum' };
  });

  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  // --- Criterion 1: the edge type is not carried by colour -------------------

  it('draws each edge kind with its own line PATTERN, not just its own colour', async () => {
    const wrapper = mountView();
    await nextTick();

    // Scoped to the CANVAS: the legend draws sample lines carrying the same `data-kind`, and an
    // unscoped query silently asserts against those instead of against the drawn graph.
    const kinds = wrapper
      .find('svg.next-kg-svg')
      .findAll('line[data-kind]')
      .map((line) => ({
        kind: line.attributes('data-kind'),
        classes: line.classes().filter((c) => c.startsWith('is-')).sort(),
      }));

    // One class per kind, and they are distinct — the stylesheet turns each into a different
    // dash pattern (see KnowledgeGraphCanvas's scoped styles).
    expect(kinds).toMatchSnapshot();
    // No `manual`: the filter does not offer that layer, so nothing draws it. The enum member
    // survives as a type — the mention scanner still reads it — but nothing writes the source.
    expect(new Set(kinds.map((k) => k.kind))).toEqual(
      new Set(['wikilink', 'similarity', 'mention', 'ghost']),
    );

    // One distinct pattern class per kind: no two share one, which is what makes the legend
    // decodable without colour.
    const patternClasses = kinds.map((k) => k.classes.join(' '));
    expect(new Set(patternClasses).size).toBe(new Set(kinds.map((k) => k.kind)).size);
  });

  it('gives a MENTION its own line class and an arrowhead (it is directional)', async () => {
    const wrapper = mountView();
    await nextTick();

    const canvas = wrapper.find('svg.next-kg-svg');
    const mention = canvas.find('line[data-kind="mention"]');
    expect(mention.exists()).toBe(true);
    expect(mention.classes()).toContain('is-mention');
    // A mention points: A naming B does not mean B names A.
    expect(mention.attributes('marker-end')).toBeTruthy();

    // A similarity is mutual, so it carries no arrow — the two must not look alike.
    expect(canvas.find('line[data-kind="similarity"]').attributes('marker-end')).toBeUndefined();
  });

  it('names the mention relation in the neighbour list, in words', async () => {
    const wrapper = mountView();
    await nextTick();

    const groups = wrapper
      .findAll('[data-graph-neighbours] [role="group"]')
      .map((g) => g.attributes('aria-label'));
    expect(groups).toContain(t('knowledge.graph.edge.mention'));
    expect(wrapper.find('[data-row-id="n"]').exists()).toBe(true);
  });

  it('offers dismiss on a MENTION, with the mention’s own copy', async () => {
    const wrapper = mountView();
    await nextTick();

    await wrapper.find('[data-row-id="n"]').trigger('click');
    await nextTick();

    const dismiss = wrapper.find(
      `[aria-label="${t('knowledge.mentions.dismiss', '', { title: 'Mention target' })}"]`,
    );
    expect(dismiss.exists()).toBe(true);

    await dismiss.trigger('click');
    await nextTick();

    expect(h.store.dismissLink).toHaveBeenCalledWith('e-n');
    expect(h.toast.success).toHaveBeenCalledWith(
      t('knowledge.mentions.dismissed'),
      expect.anything(),
    );
  });

  it('hides the whole SVG from assistive technology and offers the list instead', async () => {
    const wrapper = mountView();
    await nextTick();

    const svg = wrapper.find('svg.next-kg-svg');
    expect(svg.attributes('aria-hidden')).toBe('true');
    expect(svg.attributes('focusable')).toBe('false');
    expect(wrapper.find('[data-graph-neighbours]').exists()).toBe(true);
    expect(wrapper.text()).toContain(t('knowledge.graph.canvasHidden'));
  });

  // --- Criterion 2: re-centring is a URL change ------------------------------

  it('re-centres on double click and pushes the entry’s SLUG into the URL', async () => {
    const wrapper = mountView();
    await nextTick();

    await wrapper.find('[data-node-id="w"]').trigger('dblclick');

    expect(h.router.push).toHaveBeenCalledWith(
      expect.objectContaining({
        name: 'next.knowledge.base.graph',
        params: { baseId: 'b1', slug: 'wiki-target' },
      }),
    );
  });

  it('re-centres from the KEYBOARD too — Enter on the list is the double click', async () => {
    const wrapper = mountView();
    await nextTick();

    const list = wrapper.find('[data-graph-neighbours]');
    await list.trigger('keydown', { key: 'ArrowDown' });
    await list.trigger('keydown', { key: 'Enter' });

    expect(h.router.push).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'next.knowledge.base.graph' }),
    );
  });

  it('writes the depth into the URL rather than into component state', async () => {
    const wrapper = mountView();
    await nextTick();

    const depthButtons = wrapper.findAll('button').filter((b) => b.text() === t('knowledge.graph.depth2'));
    expect(depthButtons.length).toBeGreaterThan(0);
    await depthButtons[0].trigger('click');

    expect(h.router.replace).toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ depth: '2' }) }),
    );
  });

  // --- Criterion 3: ghosts are an invitation ---------------------------------

  it('shows a ghost in the list and offers to create the entry, prefilled from the slug', async () => {
    const wrapper = mountView();
    await nextTick();

    const ghostRow = wrapper.find('[data-row-id="ghost:polityka-zwrotow"]');
    expect(ghostRow.exists()).toBe(true);

    await ghostRow.trigger('click');
    await nextTick();

    const create = wrapper.findAll('button').find((b) => b.text() === t('knowledge.ghosts.create'));
    expect(create).toBeTruthy();

    await create?.trigger('click');
    // Since the AI-only pivot the invitation leads to the COMPOSER, seeded (spec §25.4).
    expect(h.router.push).toHaveBeenCalledWith(
      expect.objectContaining({
        name: 'next.knowledge.base.compose',
        query: { seed: 'polityka-zwrotow' },
      }),
    );
  });

  // --- Criterion 4: the cap is reported --------------------------------------

  it('says how much of the base is hidden, with a way to the rest', async () => {
    const wrapper = mountView();
    await nextTick();

    expect(wrapper.text()).toContain(t('knowledge.graph.showMore', '', { count: 7 }));
    // Ego mode, so the caption talks about the NEIGHBOURHOOD, not about the base's hubs.
    // Five entries drawn (centre + four neighbours) + seven hidden.
    expect(wrapper.text()).toContain(t('knowledge.graph.cappedEgo', '', { shown: 5, total: 12 }));
  });

  // --- Criterion 5: the list is a real, operable listbox ---------------------

  it('is a listbox with exactly one tab stop and a VIRTUAL cursor', async () => {
    const wrapper = mountView();
    await nextTick();

    const list = wrapper.find('[data-graph-neighbours]');
    expect(list.attributes('role')).toBe('listbox');
    expect(list.attributes('tabindex')).toBe('0');
    expect(list.attributes('aria-activedescendant')).toBeTruthy();

    // Rows are options and are NOT focusable themselves (focus never leaves the container).
    const rows = wrapper.findAll('[role="option"]');
    expect(rows.length).toBeGreaterThan(0);
    expect(rows.every((row) => row.attributes('tabindex') === undefined)).toBe(true);
  });

  it('moves the cursor with the arrows and jumps with Home/End', async () => {
    const wrapper = mountView();
    await nextTick();

    const list = wrapper.find('[data-graph-neighbours]');
    const first = list.attributes('aria-activedescendant');

    await list.trigger('keydown', { key: 'ArrowDown' });
    const second = wrapper.find('[data-graph-neighbours]').attributes('aria-activedescendant');
    expect(second).not.toBe(first);

    await list.trigger('keydown', { key: 'Home' });
    expect(wrapper.find('[data-graph-neighbours]').attributes('aria-activedescendant')).toBe(first);
  });

  it('groups the rows by relation and names the relation IN WORDS', async () => {
    const wrapper = mountView();
    await nextTick();

    const groups = wrapper
      .findAll('[data-graph-neighbours] [role="group"]')
      .map((g) => g.attributes('aria-label'));

    // The LEGEND's order, so the list and the picture explain themselves the same way round.
    expect(groups).toEqual([
      // `relation` leads the legend, but this fixture has no relation edges, so the group is
      // absent — empty groups are dropped rather than rendered as headings with nothing under them.
      t('knowledge.graph.edge.wikilink'),
      t('knowledge.graph.edge.similarity'),
      t('knowledge.graph.edge.mention'),
      // No `manual` group: the layer is not offered by the filter, so no manual edge is drawn.
      // Nothing writes that source, and the enum member survives only as a type.
      t('knowledge.graph.edge.ghost'),
    ]);
  });

  // --- Filters, states -------------------------------------------------------

  it('picking a layer with nothing in it is an EMPTY STATE, not an empty canvas', async () => {
    // One-of-N has no "everything off" state any more, so the way to reach an empty picture is to
    // select a layer this base has none of — which is the case a reader will actually hit.
    const wrapper = mountView();
    await nextTick();

    await pickLayer(wrapper, 'relation');

    expect(wrapper.text()).toContain(t('knowledge.graph.emptyFiltered.title'));
    expect(wrapper.find('svg.next-kg-svg').exists()).toBe(false);
  });

  it('reports each kind’s count in the LEGEND, from the RESPONSE not the filter', async () => {
    // The counts moved off the toggles: a one-of-N card shows what you can pick, so only the count
    // of the layer already chosen would have stayed visible. The legend lists every kind at once.
    const wrapper = mountView();
    await nextTick();

    const legend = wrapper.findComponent({ name: 'KnowledgeGraphLegend' });
    expect(legend.text()).toContain(
      t('knowledge.graph.edge.count', '', { label: t('knowledge.graph.edge.similarity'), count: 1 }),
    );

    // Narrowing the filter must NOT change the census — it counts what the base HAS.
    await pickLayer(wrapper, 'mention');

    expect(wrapper.findComponent({ name: 'KnowledgeGraphLegend' }).text()).toContain(
      t('knowledge.graph.edge.count', '', { label: t('knowledge.graph.edge.similarity'), count: 1 }),
    );
  });

  it('offers a retry when the graph fails to load', async () => {
    h.store.graph = null;
    h.store.graphErrored = true;

    const wrapper = mountView();
    await nextTick();

    expect(wrapper.text()).toContain(t('knowledge.common.loadError'));
    const retry = wrapper.findAll('button').find((b) => b.text() === t('knowledge.common.retry'));
    expect(retry).toBeTruthy();
    await retry?.trigger('click');
    expect(h.store.fetchGraph).toHaveBeenCalled();
  });

  it('says "not enough connections" — with a way out — instead of drawing a dot cloud', async () => {
    h.store.graph = {
      center: null,
      nodes: [
        { id: 'a', slug: 'a', title: 'A', status: 'draft', is_stale: false, degree: 0, distance: null },
        { id: 'b', slug: 'b', title: 'B', status: 'draft', is_stale: false, degree: 0, distance: null },
      ],
      edges: [],
      ghosts: [],
      truncated: { hidden_nodes: 0, hidden_edges: 0 },
    };

    const wrapper = mountView();
    await nextTick();

    expect(wrapper.text()).toContain(t('knowledge.graph.empty.title'));
    expect(wrapper.text()).toContain(t('knowledge.graph.empty.action'));
  });

  it('dismissing a similarity drops the LINE and offers an undo, keeping the node', async () => {
    const wrapper = mountView();
    await nextTick();

    await wrapper.find('[data-row-id="s"]').trigger('click');
    await nextTick();

    const dismiss = wrapper.find(
      `[aria-label="${t('knowledge.similar.dismiss', '', { title: 'Similar target' })}"]`,
    );
    expect(dismiss.exists()).toBe(true);

    await dismiss.trigger('click');
    await nextTick();

    expect(h.store.dismissLink).toHaveBeenCalledWith('e-s');
    expect(h.store.removeGraphEdge).toHaveBeenCalledWith('e-s');
    expect(h.toast.success).toHaveBeenCalledWith(
      t('knowledge.similar.dismissed'),
      expect.objectContaining({ action: expect.objectContaining({ label: t('knowledge.common.undo') }) }),
    );
  });

  it('asks the server for every source kind — the chips are a VIEW filter, not a query', async () => {
    mountView();
    await nextTick();

    // All four server kinds, `mention` included — and NO `min_score`: the server's default is the
    // lowest bar that can produce an edge (0.60 since the linker became length-aware), so sending
    // the standard threshold would silently hide the short-text pairs stored under the lower one.
    const [, filters] = h.store.fetchGraph.mock.calls[0];
    expect(filters.sources).toEqual(['wikilink', 'similarity', 'mention', 'manual']);
    expect(filters.minScore).toBeUndefined();
  });

  // --- The layer filter: one of N -------------------------------------------
  //
  // This was a row of independent toggles, and the reason it changed is what multi-select could not
  // answer: four switches make sixteen states and the picture never says which one it is in. A graph
  // is read by comparing shapes, and comparing them means holding one variable at a time.

  it('draws ONE layer at a time — choosing a kind replaces the previous one', async () => {
    const wrapper = mountView();
    await nextTick();

    await pickLayer(wrapper, 'mention');

    const drawn = new Set(
      wrapper.findAll('line.next-kg-edge').map((line) => line.attributes('data-kind')),
    );
    expect(drawn).toEqual(new Set(['mention']));
    wrapper.unmount();
  });

  it('shows the WHOLE picture under "All", which is the default', async () => {
    // Kept as a choice on purpose: without it the complete graph — today's default, and the only
    // view of a base's actual shape — would be unreachable without unioning layers in your head.
    const wrapper = mountView();
    await nextTick();

    const drawn = new Set(
      wrapper.findAll('line.next-kg-edge').map((line) => line.attributes('data-kind')),
    );
    expect(drawn.size).toBeGreaterThan(1);
    wrapper.unmount();
  });

  it('filters the NEIGHBOUR LIST the same way — the canvas is aria-hidden', async () => {
    // The SVG is decoration; this list is the content. A filter that moved only one of them would
    // show a keyboard reader a different graph from the one on screen.
    const wrapper = mountView();
    await nextTick();

    await pickLayer(wrapper, 'mention');

    // Scoped to the LIST: the legend is also a `role="group"`, and matching it here would make
    // this assert about the wrong surface entirely.
    const groups = wrapper
      .findComponent({ name: 'KnowledgeGraphNeighbourList' })
      .findAll('[role="group"]')
      .map((group) => group.attributes('aria-label'));
    expect(groups).toEqual([t('knowledge.graph.edge.mention')]);
    wrapper.unmount();
  });

  it('never offers the "manual" layer', async () => {
    // Nothing writes that source, so a filter for it teaches a user the base is missing something
    // it cannot have.
    const wrapper = mountView();
    await nextTick();

    const labels = wrapper
      .find('[data-layer-filter]')
      .findAll('[role="radio"]')
      .map((option) => option.text());
    // Asserted POSITIVELY, against the exact set. A `not.toContain(t('…edge.manual'))` would have
    // passed on nothing: the key is deleted, so `translate` echoes the dotted path back and the
    // rendered labels never contain it whatever the filter offers.
    expect(labels).toEqual([
      t('knowledge.graph.layer.all'),
      t('knowledge.graph.edge.relation'),
      t('knowledge.graph.edge.wikilink'),
      t('knowledge.graph.edge.similarity'),
      t('knowledge.graph.edge.mention'),
    ]);
    wrapper.unmount();
  });

  // --- The ghost sub-filter --------------------------------------------------

  it('offers the ghost sub-filter ONLY under wikilinks', async () => {
    const wrapper = mountView();
    await nextTick();

    // A red link is a wikilink whose target was never written, so it governs nothing elsewhere —
    // and a control that stays on screen while it governs nothing has to be tested to be understood.
    expect(wrapper.find('[data-ghost-filter]').exists()).toBe(false);

    await pickLayer(wrapper, 'wikilink');
    expect(wrapper.find('[data-ghost-filter]').exists()).toBe(true);

    await pickLayer(wrapper, 'similarity');
    expect(wrapper.find('[data-ghost-filter]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('walks its three states: with, without, only', async () => {
    const wrapper = mountView();
    await nextTick();
    await pickLayer(wrapper, 'wikilink');

    const drawn = () =>
      new Set(wrapper.findAll('line.next-kg-edge').map((l) => l.attributes('data-kind')));

    expect(drawn()).toEqual(new Set(['wikilink', 'ghost']));

    await pickGhosts(wrapper, 'without');
    expect(drawn()).toEqual(new Set(['wikilink']));

    await pickGhosts(wrapper, 'only');
    expect(drawn()).toEqual(new Set(['ghost']));
    wrapper.unmount();
  });
});
