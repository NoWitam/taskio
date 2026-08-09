// @vitest-environment happy-dom
// graphOpSelection.spec — choosing WHICH graph operations to apply.
//
// The keys are the SERVER's (`proposed_relations[].key`), never computed here, and that is the
// whole safety property: a refinement RENUMBERS the operations, so a client-minted key would still
// look valid and would apply the wrong one, while the server's simply stops matching and `accept`
// answers 422.
//
// The wire contract is THREE-VALUED and two of its cases look identical from the outside —
// omitting the field applies everything, sending `[]` applies nothing. This panel is the thing
// that must never confuse them, so both are pinned below.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import KnowledgeGraphUpdatesPanel from '../KnowledgeGraphUpdatesPanel.vue';
import { setLocale, translate as t } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';
import type { KnowledgeProposedEntity, KnowledgeProposedRelation } from '../../types';

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
    to_title: 'Acme',
    relation_type: 'member_of',
    description: null,
    properties: {},
    valid_from: null,
    valid_to: null,
    depends_on_draft: [],
    ...over,
  };
}

function entity(over: Partial<KnowledgeProposedEntity> = {}): KnowledgeProposedEntity {
  return {
    id: null,
    handle: 'E1',
    title: 'Anna',
    slug: null,
    entry_type: 'person',
    is_draft: true,
    ...over,
  };
}

function mountPanel(props: Record<string, unknown> = {}) {
  return mount(KnowledgeGraphUpdatesPanel, {
    attachTo: document.body,
    props: { proposals: [proposal()], ...props },
  });
}

/** What the view will send as `graph_op_keys`. */
function keys(wrapper: ReturnType<typeof mountPanel>): string[] {
  return (wrapper.vm as unknown as { selectedKeys: string[] }).selectedKeys;
}

const THREE = [
  proposal({ key: 'graph:0', to_title: 'Acme' }),
  proposal({ key: 'graph:1', to_title: 'Orion', relation_type: 'works_on' }),
  proposal({ key: 'graph:2', to_title: 'Zenit', relation_type: 'created' }),
];

describe('selecting which operations to apply', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('starts with every ready operation TICKED', () => {
    // The reviewer's job is to spot the one proposal that is wrong, not to re-approve the twelve
    // that are right. An empty default turns a review into data entry.
    const wrapper = mountPanel({ proposals: THREE });

    expect(keys(wrapper)).toEqual(['graph:0', 'graph:1', 'graph:2']);
    wrapper.unmount();
  });

  it('drops a key when its row is unticked', async () => {
    const wrapper = mountPanel({ proposals: THREE });

    await wrapper.find('[data-select-op="graph:1"] input').setValue(false);

    expect(keys(wrapper)).toEqual(['graph:0', 'graph:2']);
    wrapper.unmount();
  });

  it('reports how many were turned off, so the exclusion is visible', async () => {
    const wrapper = mountPanel({ proposals: THREE });

    await wrapper.find('[data-select-op="graph:1"] input').setValue(false);

    expect(wrapper.find('[data-excluded-count]').text()).toContain('1');
    wrapper.unmount();
  });

  it('yields an EMPTY list when everything is unticked — not "no opinion"', async () => {
    const wrapper = mountPanel({ proposals: THREE });

    await wrapper.find('[data-select-all] input').setValue(false);

    // THE distinction. An empty array means "apply none"; omitting the field means "apply all".
    // A panel that reported the two the same way would turn a total refusal into a total
    // acceptance, and nothing on screen would say so.
    expect(keys(wrapper)).toEqual([]);
    expect(wrapper.find('[data-none-selected]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('never offers a BLOCKED row for selection', () => {
    const wrapper = mountPanel({
      proposals: [
        proposal({ key: 'graph:0' }),
        proposal({ key: 'graph:1', depends_on_draft: ['E1'] }),
      ],
      entities: [entity({ handle: 'E1' })],
    });

    expect(keys(wrapper)).toEqual(['graph:0']);
    // A disabled checkbox invites a click that cannot work; the row shows a status glyph instead.
    expect(wrapper.find('[data-select-op="graph:1"] input').exists()).toBe(false);
    wrapper.unmount();
  });

  it('picks up a row the moment its blocking draft is accepted', async () => {
    const wrapper = mountPanel({
      proposals: [proposal({ key: 'graph:0', depends_on_draft: ['E1'] })],
      entities: [entity({ handle: 'E1' })],
      acceptedHandles: [],
    });

    expect(keys(wrapper)).toEqual([]);

    await wrapper.setProps({ acceptedHandles: ['E1'] });
    await nextTick();

    expect(keys(wrapper)).toEqual(['graph:0']);
    wrapper.unmount();
  });

  it('re-seeds when a refinement renumbers the operations', async () => {
    const wrapper = mountPanel({ proposals: THREE });
    await wrapper.find('[data-select-op="graph:0"] input').setValue(false);

    // A new run: different keys entirely. Carrying the old tick forward would leave an exclusion
    // pointing at an operation that no longer exists.
    await wrapper.setProps({ proposals: [proposal({ key: 'graph:7', to_title: 'Nowy' })] });
    await nextTick();

    expect(keys(wrapper)).toEqual(['graph:7']);
    wrapper.unmount();
  });

  it('ticks and unticks a whole GROUP from its header', async () => {
    const wrapper = mountPanel({
      proposals: [
        proposal({ key: 'graph:0', from: 'E1' }),
        proposal({ key: 'graph:1', from: 'E1', to_title: 'Orion' }),
        proposal({ key: 'graph:2', from: 'E9', from_title: 'Bartek' }),
      ],
    });

    await wrapper.find('[data-select-group="E1"] input').setValue(false);

    expect(keys(wrapper)).toEqual(['graph:2']);
    wrapper.unmount();
  });
});

describe('a stale preview', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('offers a RELOAD rather than an error, because nothing went wrong', async () => {
    // The server refused the WHOLE request, so nothing was written and nothing was lost — the run
    // was simply refined under an open review, which renumbers the operations.
    const wrapper = mountPanel({ stale: true });

    expect(wrapper.find('[data-selection-stale]').exists()).toBe(true);

    const button = wrapper
      .findAll('button')
      .find((b) => b.text() === t('knowledge.relations.staleSelectionAction'));
    await button?.trigger('click');

    expect(wrapper.emitted('reload')).toBeTruthy();
    wrapper.unmount();
  });

  it('shows no banner in the ordinary case', () => {
    expect(mountPanel().find('[data-selection-stale]').exists()).toBe(false);
  });
});

describe('an unticked operation is REPORTED back', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders `not_selected` as a confirmation, not as a fault', () => {
    // A refusal that leaves no trace is indistinguishable from a refusal that did not take effect,
    // so the reviewer would have no way to confirm their own decision landed.
    const wrapper = mountPanel({
      result: { relations: 2, skipped: [{ code: 'not_selected' }] },
    });

    expect(wrapper.find('[data-skip="not_selected"]').exists()).toBe(true);
    expect(wrapper.find('[data-accept-result]').text()).toContain(
      t('knowledge.relations.skippedHint'),
    );
    wrapper.unmount();
  });
});
