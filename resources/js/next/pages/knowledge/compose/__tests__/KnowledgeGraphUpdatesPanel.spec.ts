// @vitest-environment happy-dom
// KnowledgeGraphUpdatesPanel.spec — the review of what a run would do to the graph.
//
// The claims worth defending, in order of what they cost to get wrong:
//
//   1. THE BLOCK IS VISIBLE BEFORE THE PRESS. A relation whose end is an unaccepted draft says so
//      on the row, and stops saying it the moment that draft is accepted. Discovering the same fact
//      afterwards as a `dependency_not_accepted` skip is the identical information delivered as a
//      surprise, after the reviewer has already committed.
//   2. REFUSALS ARE VISIBLE AT ALL. Without them a reviewer cannot tell "the agent found nothing"
//      from "the agent found things and the server threw them away" — the same empty screen and
//      completely different news.
//   3. `skipped` READS AS INFORMATION. A 200 with four saved and two skipped is the ordinary result
//      of approving a subset, and colouring it as failure teaches people to fear partial approval.
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
    key: 'graph:0',
    pair_with: null,
    replaces: null,
    ...over,
  };
}

function entity(over: Partial<KnowledgeProposedEntity> = {}): KnowledgeProposedEntity {
  return { id: null, handle: 'E1', title: 'Anna', slug: null, entry_type: 'person', is_draft: true, ...over };
}

function mountPanel(props: Record<string, unknown> = {}) {
  return mount(KnowledgeGraphUpdatesPanel, {
    attachTo: document.body,
    props: { proposals: [proposal()], ...props },
  });
}

describe('the review groups per entity and speaks in sentences', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders one group per subject entity', () => {
    const wrapper = mountPanel({
      proposals: [
        proposal({ from: 'E1', from_title: 'Anna' }),
        proposal({ from: 'E1', from_title: 'Anna', to_title: 'Orion', relation_type: 'works_on' }),
        proposal({ from: 'E9', from_title: 'Bartek' }),
      ],
    });

    expect(wrapper.findAll('[data-group]')).toHaveLength(2);
    wrapper.unmount();
  });

  it('renders each operation as a SENTENCE, never a raw predicate id', () => {
    const wrapper = mountPanel();

    expect(wrapper.text()).toContain('Anna');
    expect(wrapper.text()).toContain('Acme');
    // `member_of` is never shown to a person.
    expect(wrapper.text()).not.toContain('member_of');
    wrapper.unmount();
  });

  it('prefixes each row with a TENSE — this has not happened yet', () => {
    const wrapper = mountPanel({ proposals: [proposal({ op: 'end', relation: 'R1' })] });

    expect(wrapper.text()).toContain(t('knowledge.relations.opEnd'));
    wrapper.unmount();
  });

  it('marks a subject that is itself a draft', () => {
    const wrapper = mountPanel({ entities: [entity({ handle: 'E1' })] });

    expect(wrapper.find('[data-draft-badge]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('has NO empty state when the agent proposed nothing but the server refused something', () => {
    // "No relations found" next to four refusals would be a contradiction the reviewer has to
    // resolve themselves.
    const wrapper = mountPanel({
      proposals: [],
      rejected: [{ code: 'unknown_relation_type' }],
    });

    expect(wrapper.text()).not.toContain(t('knowledge.relations.emptyProposals'));
    expect(wrapper.find('[data-reject]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('says nothing was proposed WITHOUT implying a fault', () => {
    const wrapper = mountPanel({ proposals: [] });
    const text = wrapper.text();

    expect(text).toContain(t('knowledge.relations.emptyProposals'));
    // Deliberately no repair action: plenty of text simply has no relations in it.
    expect(text).toContain(t('knowledge.relations.emptyProposalsHint'));
    wrapper.unmount();
  });
});

describe('the draft dependency, before the press', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('names the entry a blocked relation is waiting for', () => {
    const wrapper = mountPanel({
      proposals: [proposal({ depends_on_draft: ['E1'] })],
      entities: [entity({ handle: 'E1', title: 'Anna Kowalska' })],
    });

    expect(wrapper.find('[data-blocked]').text()).toContain('Anna Kowalska');
    wrapper.unmount();
  });

  it('points the row at its reason with `aria-describedby`, not at a tooltip alone', () => {
    const wrapper = mountPanel({
      proposals: [proposal({ depends_on_draft: ['E1'] })],
      entities: [entity({ handle: 'E1' })],
    });

    const described = wrapper.find('[aria-describedby]');
    expect(described.exists()).toBe(true);
    // The id it points at must actually exist, or the reason is unreachable.
    expect(wrapper.find(`#${described.attributes('aria-describedby')}`).exists()).toBe(true);
    wrapper.unmount();
  });

  it('RELEASES the block live once the draft has been accepted', async () => {
    const wrapper = mountPanel({
      proposals: [proposal({ depends_on_draft: ['E1'] })],
      entities: [entity({ handle: 'E1' })],
      acceptedHandles: [],
    });

    expect(wrapper.find('[data-blocked]').exists()).toBe(true);

    // THE assertion. `depends_on_draft` is frozen at generation time; the row must still unlock.
    await wrapper.setProps({ acceptedHandles: ['E1'] });
    await nextTick();

    expect(wrapper.find('[data-blocked]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('counts only the rows that will actually be written', async () => {
    const wrapper = mountPanel({
      proposals: [proposal(), proposal({ to_title: 'Orion', depends_on_draft: ['E1'] })],
      entities: [entity({ handle: 'E1' })],
    });

    expect(wrapper.find('[data-ready-count]').text()).toContain('1');

    await wrapper.setProps({ acceptedHandles: ['E1'] });
    await nextTick();

    expect(wrapper.find('[data-ready-count]').text()).toContain('2');
    wrapper.unmount();
  });
});

describe('what the server refused, and its advisory half', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('shows a refusal with its reason in words', () => {
    const wrapper = mountPanel({
      rejected: [{ code: 'properties_refused', property: 'salary' }],
    });

    expect(wrapper.find('[data-reject="properties_refused"]').text()).toContain('salary');
    wrapper.unmount();
  });

  it('offers the way to the relation that already exists, on a duplicate', () => {
    const wrapper = mountPanel({
      rejected: [{ code: 'duplicate_relation', existing_relation_id: 'rel-1' }],
    });

    expect(wrapper.find('[data-show-existing]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('emits the relation to show when that link is used', async () => {
    const wrapper = mountPanel({
      rejected: [{ code: 'duplicate_relation', existing_relation_id: 'rel-1' }],
    });

    await wrapper.find('[data-show-existing]').trigger('click');

    expect(wrapper.emitted('show-relation')?.[0]).toEqual(['rel-1']);
    wrapper.unmount();
  });

  it('shows a warning WITH its explanation, and never as a refusal', () => {
    const wrapper = mountPanel({
      warnings: [{ code: 'rewrite_degraded_to_append', reason: 'truncated' }],
    });

    const row = wrapper.find('[data-warning="rewrite_degraded_to_append"]');
    expect(row.exists()).toBe(true);
    expect(row.text().length).toBeGreaterThan(20);
    wrapper.unmount();
  });

  it('renders NO refusal section when nothing was refused', () => {
    // "Nothing was refused" is not information worth a heading.
    const wrapper = mountPanel();

    expect(wrapper.find('[data-reject]').exists()).toBe(false);
    wrapper.unmount();
  });
});

describe('the accept result reads as information', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('reports skipped operations with their reasons, and says it is not an error', () => {
    const wrapper = mountPanel({
      result: {
        relations: 4,
        skipped: [{ code: 'dependency_not_accepted' }, { code: 'already_applied' }],
      },
    });

    const text = wrapper.text();
    expect(wrapper.findAll('[data-skip]')).toHaveLength(2);
    // The sentence that stops a normal outcome reading as a failure.
    expect(text).toContain(t('knowledge.relations.skippedHint'));
    wrapper.unmount();
  });

  it('reports the relations that were written', () => {
    // There is no `updated` count here any more, and its absence is the correct contract rather
    // than a gap: the graph half no longer writes entry CONTENT at all — a change to an existing
    // entry becomes a reviewable shadow draft on the board — so the only entries this section
    // could have counted are ones it never touches.
    const wrapper = mountPanel({ result: { relations: 3, skipped: [] } });

    expect(wrapper.find('[data-accept-result]').text()).toContain(
      t('knowledge.relations.acceptedRelations', '', { count: 3 }),
    );
    wrapper.unmount();
  });

  it('shows no result block before anything has been accepted', () => {
    expect(mountPanel().find('[data-accept-result]').exists()).toBe(false);
  });
});

describe('an ambiguous name is settled IN THE ROW', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  const ambiguous = [
    {
      text: 'Anna',
      kind: 'person' as const,
      context: '…the project lead…',
      candidates: [
        { slug: 'anna-kowalska', title: 'Anna Kowalska', entry_type: 'person' as const, score: 0.9 },
        { slug: 'anna-nowak', title: 'Anna Nowak', entry_type: 'person' as const, score: 0.7 },
      ],
    },
  ];

  it('renders the chooser where the entity would have been', () => {
    const wrapper = mountPanel({
      proposals: [proposal({ from_title: 'Anna' })],
      ambiguous,
    });

    // Not in a separate "needs attention" section: the question and its context are one glance.
    expect(wrapper.find('[data-entity-chooser]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('holds an unsettled row OUT of the ready count', () => {
    const wrapper = mountPanel({ proposals: [proposal({ from_title: 'Anna' })], ambiguous });

    expect(wrapper.find('[data-ready-count]').text()).toContain('0');
    wrapper.unmount();
  });

  it('says how many OTHER rows one answer settles', () => {
    const wrapper = mountPanel({
      proposals: [
        proposal({ from_title: 'Anna' }),
        proposal({ from_title: 'Anna', to_title: 'Orion', relation_type: 'works_on' }),
      ],
      ambiguous,
    });

    // The promise that a session-wide answer makes, stated rather than performed silently.
    expect(wrapper.find('[data-ambiguity-scope]').exists()).toBe(true);
    wrapper.unmount();
  });
});
