// @vitest-environment happy-dom
// silentGaps.spec — two places where SILENCE LOOKED LIKE AN ANSWER.
//
// Neither of these broke a render, which is why both survived so long. They each took a state the
// user could act on and rendered it as a confident statement that nothing was there:
//
//   • the contents panel has no fetch of its own, so a FAILED entry list arrived as an empty array
//     and the panel said "this base has no entries" — about a base that was perfectly fine;
//   • the graph-updates panel showed "the agent proposed no relations" while the proposals were
//     still in flight, and that empty state carries an editorial claim ("normal for text without
//     clear links"), so it was not merely early — it was wrong, and then silently replaced.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import KnowledgeTocPanel from '../reader/KnowledgeTocPanel.vue';
import KnowledgeGraphUpdatesPanel from '../compose/KnowledgeGraphUpdatesPanel.vue';
import { setLocale, translate as t } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

describe('the contents panel when the entry LIST failed', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  function mountToc(props: Record<string, unknown> = {}) {
    return mount(KnowledgeTocPanel, {
      attachTo: document.body,
      props: { base: null, entries: [], activeSlug: null, ...props },
    });
  }

  it('says the load FAILED instead of claiming the base is empty', () => {
    const wrapper = mountToc({ error: 'boom' });

    expect(wrapper.find('[data-toc-error]').exists()).toBe(true);
    // THE assertion: not the empty-state wording, which is a claim about the BASE. Keyed on the
    // string the panel actually renders — a wrong key here would make this pass on nothing.
    expect(wrapper.text()).not.toContain(t('knowledge.reader.empty.title'));
    wrapper.unmount();
  });

  it('offers a retry, because re-running the fetch is the whole fix', async () => {
    const wrapper = mountToc({ error: 'boom' });

    await wrapper.find('[data-toc-retry]').trigger('click');

    expect(wrapper.emitted('reload')).toBeTruthy();
    wrapper.unmount();
  });

  it('still shows the empty state when the list really is empty', () => {
    // The failure branch must not swallow the honest one.
    const wrapper = mountToc({ error: null });

    expect(wrapper.find('[data-toc-error]').exists()).toBe(false);
    expect(wrapper.text()).toContain(t('knowledge.reader.empty.title'));
    wrapper.unmount();
  });

  it('prefers LOADING over the error, so a retry in flight does not still read as broken', () => {
    const wrapper = mountToc({ error: 'boom', loading: true });

    expect(wrapper.find('[data-toc-error]').exists()).toBe(false);
    wrapper.unmount();
  });
});

describe('the graph-updates panel while proposals are in flight', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  function mountPanel(props: Record<string, unknown> = {}) {
    return mount(KnowledgeGraphUpdatesPanel, {
      attachTo: document.body,
      props: { proposals: [], ...props },
    });
  }

  it('shows the SHAPE of the proposals, not a verdict about them', () => {
    const wrapper = mountPanel({ loading: true });

    expect(wrapper.find('[data-updates-loading]').exists()).toBe(true);
    // The empty state is an editorial claim; announcing it before the answer arrives is a lie
    // that then quietly corrects itself.
    expect(wrapper.text()).not.toContain(t('knowledge.relations.emptyProposals'));
    wrapper.unmount();
  });

  it('says the agent proposed nothing only once it has actually answered', () => {
    const wrapper = mountPanel({ loading: false });

    expect(wrapper.find('[data-updates-loading]').exists()).toBe(false);
    expect(wrapper.text()).toContain(t('knowledge.relations.emptyProposals'));
    wrapper.unmount();
  });

  it('drops the skeleton once the proposals land', async () => {
    const wrapper = mountPanel({ loading: true });

    await wrapper.setProps({
      loading: false,
      proposals: [
        {
          kind: 'relation' as const,
          id: null,
          key: 'graph:0',
          pair_with: null,
          replaces: null,
          op: 'create' as const,
          from: 'E1',
          to: 'E2',
          relation: null,
          from_title: 'Anna',
          to_title: 'Acme',
          relation_type: 'member_of' as const,
          description: null,
          properties: {},
          valid_from: null,
          valid_to: null,
          depends_on_draft: [],
        },
      ],
    });

    expect(wrapper.find('[data-updates-loading]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Anna');
    wrapper.unmount();
  });
});
