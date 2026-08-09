// @vitest-environment happy-dom
// graphOnlyAccept.spec — approving a run that creates no entries at all.
//
// "Anna left Acme in July" touches two entries that already exist and proposes no new one. That is
// not an edge case, it is the commonest incremental update a knowledge base receives — and until
// this batch it was UNAPPROVABLE from the UI: the only accept button lived on the draft board and
// was disabled whenever no draft was ticked. The reviewer saw relations on screen and had no way
// to say yes to them.
//
// The server has taken `entry_ids: []` all along. The gap was entirely on this side.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import KnowledgeDraftBoard from '../KnowledgeDraftBoard.vue';
import { setLocale, translate as t } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';
import type { KnowledgeDraftEntry } from '../../types';

function draft(over: Partial<KnowledgeDraftEntry> = {}): KnowledgeDraftEntry {
  return {
    id: 'd1',
    knowledge_base_id: 'b1',
    title: 'Nowy wpis',
    slug: 'nowy-wpis',
    aliases: [],
    entry_type: null,
    content: 'Treść.',
    metadata: {},
    status: 'draft',
    stale_at: null,
    is_stale: false,
    position: 0,
    current_revision_id: null,
    index: { status: 'pending', chunks_count: null, needs_indexing: true },
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_be_purged: true,
    created_at: null,
    updated_at: null,
    deleted_at: null,
    draft_session_id: 's1',
    ...over,
  } as KnowledgeDraftEntry;
}

function mountBoard(props: Record<string, unknown> = {}) {
  return mount(KnowledgeDraftBoard, {
    attachTo: document.body,
    props: { sessionId: 's1', drafts: [], base: null, ...props },
    global: { stubs: { KnowledgeDraftRelationsPanel: true } },
  });
}

function acceptButton(wrapper: ReturnType<typeof mountBoard>) {
  return wrapper.find('[data-accept-selected]');
}

describe('the accept button counts BOTH halves', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('is ENABLED when only graph operations are ticked', () => {
    // THE regression. With no drafts and three relations waiting, this button was dead.
    const wrapper = mountBoard({ drafts: [], graphOpCount: 3 });

    expect(acceptButton(wrapper).attributes('disabled')).toBeUndefined();
    wrapper.unmount();
  });

  it('stays disabled only when BOTH halves are empty', () => {
    const wrapper = mountBoard({ drafts: [], graphOpCount: 0 });

    expect(acceptButton(wrapper).attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });

  it('counts drafts and operations together in its label', async () => {
    const wrapper = mountBoard({ drafts: [draft()], graphOpCount: 2 });

    // Nothing ticked among the drafts yet — the two operations are what it would commit.
    expect(acceptButton(wrapper).text()).toContain('2');

    await wrapper.find('[data-draft-id="d1"] input[type="checkbox"]').setValue(true);
    await nextTick();

    expect(acceptButton(wrapper).text()).toContain('3');
    wrapper.unmount();
  });

  it('explains the disabled state in terms of both halves', () => {
    const wrapper = mountBoard({ drafts: [], graphOpCount: 0 });

    // "Select drafts to accept" would be a dead end on a run that produced no drafts at all.
    expect(acceptButton(wrapper).attributes('aria-label')).toBe(
      t('knowledge.compose.acceptSelectedNone'),
    );
    wrapper.unmount();
  });

  it('emits an EMPTY id list when only operations are ticked', async () => {
    const wrapper = mountBoard({ drafts: [], graphOpCount: 2 });

    await acceptButton(wrapper).trigger('click');

    // The view turns this into `entry_ids: []` plus the operation keys.
    expect(wrapper.emitted('accept-selected')?.[0]).toEqual([[]]);
    wrapper.unmount();
  });
});

describe('the empty state does not contradict the panel below it', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('does NOT say "no proposals" while graph operations are waiting', () => {
    // It used to announce that the agent produced nothing, directly above a list of things the
    // agent produced. A reviewer resolves that contradiction by trusting neither.
    const wrapper = mountBoard({ drafts: [], graphOpCount: 2 });

    expect(wrapper.text()).not.toContain(t('knowledge.compose.noDrafts.title'));
    wrapper.unmount();
  });

  it('says what DID happen instead', () => {
    const wrapper = mountBoard({ drafts: [], graphOpCount: 2 });

    expect(wrapper.find('[data-graph-only]').exists()).toBe(true);
    expect(wrapper.find('[data-graph-only]').text()).toContain('2');
    wrapper.unmount();
  });

  it('still says "no proposals" when the run really produced nothing', () => {
    const wrapper = mountBoard({ drafts: [], graphOpCount: 0 });

    expect(wrapper.text()).toContain(t('knowledge.compose.noDrafts.title'));
    expect(wrapper.find('[data-graph-only]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('shows neither line when there are drafts to read', () => {
    const wrapper = mountBoard({ drafts: [draft()], graphOpCount: 2 });

    expect(wrapper.text()).not.toContain(t('knowledge.compose.noDrafts.title'));
    expect(wrapper.find('[data-graph-only]').exists()).toBe(false);
    wrapper.unmount();
  });
});
