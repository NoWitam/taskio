// @vitest-environment happy-dom
// graphOpPairs.spec — "she moved from Acme to Nowa Firma" is ONE decision, not two.
//
// A replacement reaches the client as two operations: an `end` on the old relation and a `create`
// for the new one. The server refuses to apply half of it, and the refusal is not fussiness — an
// `end` alone asserts that Anna works nowhere, a `create` alone that she works in two places at
// once. Both are confident falsehoods about the world, produced by a reviewer who thought they
// were being careful.
//
// So the pair is ONE control. Two checkboxes, where one of the four combinations always 422s,
// would be an interface offering a choice it then punishes — the same failure the earlier
// no-checkbox cut of this panel existed to avoid, arrived at from the opposite direction.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import KnowledgeGraphUpdatesPanel from '../KnowledgeGraphUpdatesPanel.vue';
import { mergePairs } from '../../relations/relationProposals';
import { setLocale } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';
import type { KnowledgeProposedRelation } from '../../types';

function proposal(over: Partial<KnowledgeProposedRelation> = {}): KnowledgeProposedRelation {
  return {
    kind: 'relation',
    id: null,
    key: 'graph:0',
    pair_with: null,
    replaces: null,
    op: 'create',
    from: 'E1',
    to: 'E2',
    relation: null,
    from_title: 'Anna',
    to_title: 'Nowa Firma',
    relation_type: 'member_of',
    description: null,
    properties: {},
    valid_from: '2026-07-02',
    valid_to: null,
    depends_on_draft: [],
    ...over,
  };
}

/** The two halves of "Anna moved from Acme to Nowa Firma", as the server sends them. */
const PAIR: KnowledgeProposedRelation[] = [
  proposal({
    key: 'graph:0',
    op: 'end',
    pair_with: 'graph:1',
    relation: 'R7',
    to_title: 'Acme',
    valid_from: null,
    valid_to: '2026-07-01',
  }),
  proposal({ key: 'graph:1', op: 'create', pair_with: 'graph:0', replaces: 'R7' }),
];

function mountPanel(props: Record<string, unknown> = {}) {
  return mount(KnowledgeGraphUpdatesPanel, {
    attachTo: document.body,
    props: { proposals: PAIR, ...props },
  });
}

function keys(wrapper: ReturnType<typeof mountPanel>): string[] {
  return (wrapper.vm as unknown as { selectedKeys: string[] }).selectedKeys;
}

describe('mergePairs — the pure half', () => {
  it('collapses the two halves into ONE unit committing BOTH keys', () => {
    const units = mergePairs(PAIR);

    expect(units).toHaveLength(1);
    expect(units[0].keys).toEqual(['graph:0', 'graph:1']);
  });

  it('makes the CREATE the primary — a replacement is read forwards', () => {
    // "She now works at Nowa Firma" is the statement that will be true; the ending is context.
    const units = mergePairs(PAIR);

    expect(units[0].primary.op).toBe('create');
    expect(units[0].ended?.op).toBe('end');
  });

  it('does not care which half arrives first', () => {
    const reversed = mergePairs([...PAIR].reverse());

    expect(reversed).toHaveLength(1);
    // Sorted, so the request body is the same whichever order the server listed them in.
    expect(reversed[0].keys).toEqual(['graph:0', 'graph:1']);
    expect(reversed[0].primary.op).toBe('create');
  });

  it('leaves an unpaired operation exactly as it was', () => {
    const units = mergePairs([proposal({ key: 'graph:5' })]);

    expect(units).toEqual([
      expect.objectContaining({ key: 'graph:5', keys: ['graph:5'], ended: null }),
    ]);
  });

  it('degrades to independent units when a partner is MISSING from the list', () => {
    // A half-rendered pair would be a control committing a key the reviewer cannot see. Two plain
    // rows are wrong-ish and honest; one row hiding an invisible commitment is neither.
    const units = mergePairs([proposal({ key: 'graph:0', pair_with: 'graph:99' })]);

    expect(units).toHaveLength(1);
    expect(units[0].keys).toEqual(['graph:0']);
    expect(units[0].ended).toBeNull();
  });
});

describe('a replacement pair in the panel', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders ONE checkbox, not two', () => {
    const wrapper = mountPanel();

    // THE assertion. Two would offer a combination the server always refuses.
    expect(wrapper.findAll('[data-select-op]')).toHaveLength(1);
    expect(wrapper.find('[data-select-op="graph:1"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('shows BOTH halves in that one row, so the change reads as one sentence', () => {
    const wrapper = mountPanel();
    const text = wrapper.text();

    expect(wrapper.find('[data-paired-with="graph:0"]').exists()).toBe(true);
    expect(text).toContain('Nowa Firma');
    expect(text).toContain('Acme');
    wrapper.unmount();
  });

  it('counts the pair as ONE decision', () => {
    const wrapper = mountPanel();

    expect(wrapper.find('[data-ready-count]').text()).toContain('1');
    wrapper.unmount();
  });

  it('sends BOTH keys when the pair is ticked', () => {
    const wrapper = mountPanel();

    expect(keys(wrapper)).toEqual(['graph:0', 'graph:1']);
    wrapper.unmount();
  });

  it('omits BOTH keys when the pair is refused — rejecting the whole change is allowed', async () => {
    const wrapper = mountPanel();

    await wrapper.find('[data-select-op="graph:1"] input').setValue(false);

    expect(keys(wrapper)).toEqual([]);
    wrapper.unmount();
  });

  it('never lets a pair contribute exactly one key, whatever is ticked around it', async () => {
    const wrapper = mountPanel({
      proposals: [...PAIR, proposal({ key: 'graph:2', to_title: 'Orion', relation_type: 'works_on' })],
    });

    await wrapper.find('[data-select-op="graph:2"] input').setValue(false);

    // The invariant the server enforces, held here so it never has to.
    const sent = keys(wrapper);
    expect(sent).toEqual(['graph:0', 'graph:1']);
    expect(sent.includes('graph:0')).toBe(sent.includes('graph:1'));
    wrapper.unmount();
  });

  it('declares what the control commits, for anyone reading the DOM', () => {
    const wrapper = mountPanel();

    expect(wrapper.find('[data-select-op="graph:1"]').attributes('data-commits')).toBe(
      'graph:0 graph:1',
    );
    wrapper.unmount();
  });

  it('offers NOTHING when either half is blocked by an unaccepted draft', () => {
    // Half of a change cannot be applied, so a pair with a waiting end is not a decision yet.
    const wrapper = mountPanel({
      proposals: [
        PAIR[0],
        { ...PAIR[1], depends_on_draft: ['E1'] },
      ],
      entities: [
        { id: null, handle: 'E1', title: 'Nowa Firma', slug: null, entry_type: 'organization', is_draft: true },
      ],
    });

    expect(keys(wrapper)).toEqual([]);
    expect(wrapper.find('[data-select-op]').exists()).toBe(false);
    wrapper.unmount();
  });
});

describe('a refusal about the selection', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it("shows the server's own sentence, which names the operations that belong together", () => {
    // Rendered verbatim rather than replaced by a generic failure: the message identifies WHICH
    // pair, and a screen full of checkboxes with "could not save" identifies nothing.
    const message = 'These describe one change and must be accepted together (graph:0 + graph:1).';
    const wrapper = mountPanel({ error: message });

    expect(wrapper.find('[data-selection-error]').text()).toContain('graph:0 + graph:1');
    wrapper.unmount();
  });

  it('shows no banner when there is nothing to say', () => {
    expect(mountPanel().find('[data-selection-error]').exists()).toBe(false);
  });
});
