// @vitest-environment happy-dom
// KnowledgeDraftRelationsPanel.spec — the proposal, drawn (B15a/B15b).
//
// The load-bearing facts:
//   • a SHADOW is never a node and never an edge — the pending change is an ANNOTATION on the
//     target (an edge to a shadow would have an endpoint outside `nodes[]`, breaking the invariant
//     the canvas lays itself out on). So `amended_by` has to render, and it has to be reachable;
//   • `vector_skipped` is a quiet NOTE, not an error: what is drawn is true, there is just less;
//   • "widen the context" SPENDS, so it is an explicit button with its price next to it, and a 429
//     is a budget state rather than a failure.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale, translate } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';

const h = vi.hoisted(() => ({
  store: { fetchDraftRelations: vi.fn() },
}));

vi.mock('../../../../app/stores/knowledge', () => ({ useKnowledgeStore: () => h.store }));

import KnowledgeDraftRelationsPanel from '../KnowledgeDraftRelationsPanel.vue';

const t = translate;

function node(id: string, over: Record<string, unknown> = {}) {
  return {
    id,
    slug: id,
    title: id.toUpperCase(),
    status: 'approved',
    is_stale: false,
    degree: 1,
    distance: null,
    is_draft: false,
    amended_by: [] as Array<{ draft_id: string }>,
    ...over,
  };
}

function relations(over: Record<string, unknown> = {}) {
  return {
    center: null,
    nodes: [
      node('d1', { is_draft: true }),
      node('e-target', { amended_by: [{ draft_id: 'd-shadow' }] }),
      node('e-other'),
    ],
    edges: [
      {
        id: null,
        from: 'd1',
        to: 'e-other',
        source: 'similarity',
        score: 0.8,
        evidence: null,
        dismissed: false,
        can_be_dismissed: false,
      },
    ],
    ghosts: [],
    truncated: { hidden_nodes: 0, hidden_edges: 0 },
    duplicates: {},
    vector_skipped: null,
    ...over,
  };
}

function mountPanel() {
  return mount(KnowledgeDraftRelationsPanel, {
    attachTo: document.body,
    props: { sessionId: 's1' },
  });
}

describe('KnowledgeDraftRelationsPanel', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('pl');
    vi.clearAllMocks();
    h.store.fetchDraftRelations.mockResolvedValue(relations());
  });

  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  it('marks a DRAFT node with a doubled outline and the word "Draft"', async () => {
    const wrapper = mountPanel();
    await nextTick();
    await nextTick();

    // Shape, not just hue — the canvas is aria-hidden, so the list carries the word.
    expect(wrapper.find('[data-draft-id]').exists()).toBe(false); // no cards here
    expect(wrapper.find('circle.next-kg-node-draft-ring').exists()).toBe(true);
    expect(wrapper.find('[data-draft-badge]').text()).toBe(t('knowledge.compose.nodeDraft'));
    wrapper.unmount();
  });

  it('annotates the AMENDED entry — a glyph on the canvas, a word in the list', async () => {
    const wrapper = mountPanel();
    await nextTick();
    await nextTick();

    // The canvas hangs a pencil off the node...
    expect(wrapper.find('[data-amended]').exists()).toBe(true);
    // ...and the list says it, because a glyph is not readable by a screen reader.
    expect(wrapper.find('[data-amended-badge]').text()).toBe(t('knowledge.compose.nodeAmended'));
    wrapper.unmount();
  });

  // TWO proposals against ONE entry (B16). The server ships `amended_by` as a LIST — the shape was
  // chosen so it would not have to change the day something produces two — and until now nothing had
  // ever put two items in it. It is reachable in practice: one session refuses a second amendment of
  // the same entry, but two people composing against the same base at the same time each get one.
  //
  // What is pinned: the target is still ONE node (neither shadow becomes a circle, so the canvas's
  // "every endpoint is a node" invariant survives), the annotation still renders exactly once, and
  // the affordance points at the FIRST proposal. That last part is a LIMITATION recorded on purpose:
  // the row offers one link, so the second proposal is reachable only by scrolling the board. If that
  // becomes a real complaint, this is the test that says what the current answer was.
  it('keeps ONE annotated node when two proposals amend the same entry, and points at the first', async () => {
    h.store.fetchDraftRelations.mockResolvedValue(
      relations({
        nodes: [
          node('e-target', { amended_by: [{ draft_id: 'd-shadow' }, { draft_id: 'd-shadow-2' }] }),
          node('e-other'),
        ],
        edges: [],
      }),
    );

    const wrapper = mountPanel();
    await nextTick();
    await nextTick();

    expect(wrapper.findAll('[data-amended]')).toHaveLength(1);
    expect(wrapper.findAll('[data-amended-badge]')).toHaveLength(1);
    expect(wrapper.find('[data-amended-badge]').text()).toBe(t('knowledge.compose.nodeAmended'));

    // No node was invented for either shadow: the circles are still only the two real entries.
    expect(wrapper.findAll('circle.next-kg-node')).toHaveLength(2);
    expect(wrapper.findAll('circle.next-kg-node-draft-ring')).toHaveLength(0);

    await wrapper.find('[data-open-amendment]').trigger('click');
    expect(wrapper.emitted('focus-draft')?.[0]).toEqual(['d-shadow']);

    wrapper.unmount();
  });

  it('pins the node classes so "draft" and "amended" cannot collapse into one look', async () => {
    const wrapper = mountPanel();
    await nextTick();
    await nextTick();

    const nodes = wrapper.findAll('circle.next-kg-node').map((c) => ({
      draft: c.attributes('data-draft') ?? null,
      classes: c.classes().filter((x) => x.startsWith('is-')).sort(),
    }));
    expect(nodes).toMatchSnapshot();
    wrapper.unmount();
  });

  it('points at the shadow CARD rather than navigating — the proposal is on this board', async () => {
    const wrapper = mountPanel();
    await nextTick();
    await nextTick();

    await wrapper.find('[data-open-amendment]').trigger('click');

    expect(wrapper.emitted('focus-draft')?.[0]).toEqual(['d-shadow']);
    wrapper.unmount();
  });

  it('reports a skipped vector leg as a quiet NOTE, not as an error', async () => {
    h.store.fetchDraftRelations.mockResolvedValue(relations({ vector_skipped: 'budget' }));

    const wrapper = mountPanel();
    await nextTick();
    await nextTick();

    expect(wrapper.text()).toContain(t('knowledge.compose.vectorSkipped.budget'));
    // Not an alert: the drawn edges are still true.
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('offers "widen the context" with its PRICE stated next to it', async () => {
    const wrapper = mountPanel();
    await nextTick();
    await nextTick();

    expect(wrapper.find('[data-expand-context]').exists()).toBe(true);
    expect(wrapper.text()).toContain(t('knowledge.compose.expandContextHint'));

    await wrapper.find('[data-expand-context]').trigger('click');
    expect(wrapper.emitted('expand-context')).toBeTruthy();
    wrapper.unmount();
  });

  it('keeps the rest of the card working when relations fail to load', async () => {
    h.store.fetchDraftRelations.mockRejectedValue(new Error('nope'));

    const wrapper = mountPanel();
    await nextTick();
    await nextTick();

    expect(wrapper.text()).toContain(t('knowledge.compose.relationsError'));
    expect(wrapper.text()).toContain(t('knowledge.common.retry'));
    wrapper.unmount();
  });

  it('says an empty preview is NORMAL rather than a problem to fix', async () => {
    h.store.fetchDraftRelations.mockResolvedValue(relations({ nodes: [], edges: [] }));

    const wrapper = mountPanel();
    await nextTick();
    await nextTick();

    expect(wrapper.text()).toContain(t('knowledge.compose.relationsEmpty'));
    expect(wrapper.text()).toContain(t('knowledge.compose.relationsEmptyHint'));
    wrapper.unmount();
  });
});
