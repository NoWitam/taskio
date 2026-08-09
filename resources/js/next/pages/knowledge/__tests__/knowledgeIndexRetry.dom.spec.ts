// @vitest-environment happy-dom
// knowledgeIndexRetry.dom.spec — the B6c "retry indexing" loop, end to end through the UI.
//
// Three rules, and each of them is a bug this pins shut:
//   1. the button is shown ONLY on the server's `index.can_retry`. It used to be derived from
//      `status === 'failed'`, which left `partial` and `pending_budget` — the two states a retry
//      exists FOR — with a dead-end message.
//   2. a 200 is rendered FROM THE RESPONSE, never from a refetch. The endpoint hands back the
//      entry already moved to `pending`; a follow-up GET would race the worker and could show the
//      state going backwards.
//   3. a 422 shows the SERVER's message (`errors.index`), which names the state that disqualified
//      the entry — more useful than a generic failure line.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h as vh, nextTick, type VNode } from 'vue';
import { setLocale, translate } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const h = vi.hoisted(() => {
  const entry = {
    id: 'e1',
    knowledge_base_id: 'b1',
    title: 'Polityka zwrotów',
    slug: 'polityka-zwrotow',
    content: 'Treść.',
    excerpt: 'Treść.',
    metadata: {},
    status: 'approved',
    stale_at: null,
    is_stale: false,
    position: 0,
    current_revision_id: 'r1',
    index: { status: 'partial', chunks_count: 8, indexed_chunks_count: 5, needs_indexing: false, can_retry: true },
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_be_purged: true,
    created_at: null,
    updated_at: null,
    deleted_at: null,
  };

  return {
    entry,
    router: { push: vi.fn(), replace: vi.fn() },
    toast: { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() },
    store: {
      entry,
      entries: [entry],
      entriesBySlug: new Map([[entry.slug, entry]]),
      entriesLoading: false,
      entriesTruncated: false,
      entryLoading: false,
      entryError: null,
      openBase: { id: 'b1', name: 'Marka', can_be_edited: true, metadata_schema: [] },
      fetchEntries: vi.fn(),
      fetchEntry: vi.fn(),
      resetEntries: vi.fn(),
      resetEntry: vi.fn(),
      // Typed relations are fetched separately from the entry (they belong to TWO entries, so they
      // were never part of one entry's payload). The reader asks on mount, so the stub needs them.
      relations: [],
      relationsError: null,
      fetchRelations: vi.fn(),
      resetRelations: vi.fn(),
      retryIndex: vi.fn(),
      updateEntry: vi.fn(),
      dismissLink: vi.fn(),
      undismissLink: vi.fn(),
      deleteEntry: vi.fn(),
      reorderEntries: vi.fn(),
    },
  };
});

vi.mock('vue-router', () => ({
  useRouter: () => h.router,
  useRoute: () => ({ params: { baseId: 'b1', slug: 'polityka-zwrotow' }, query: {}, hash: '' }),
}));
vi.mock('../../../app/stores/knowledge', () => ({
  useKnowledgeStore: () => h.store,
  conflictCodeOf: () => null,
}));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => vi.fn() }));

import KnowledgeArticleBody from '../reader/KnowledgeArticleBody.vue';
import KnowledgeReaderView from '../KnowledgeReaderView.vue';

const t = translate;

/** The heavy reader children, replaced so this spec is about the RETRY, not about markdown. */
function readerStubs() {
  return {
    KnowledgeTocPanel: true,
    KnowledgeEntryRail: true,
    KnowledgeVersionsDrawer: true,
    KnowledgeArticleBody: {
      name: 'KnowledgeArticleBody',
      props: ['entry', 'entriesBySlug', 'hrefFor', 'jumpAnchor', 'retrying'],
      emits: ['navigate', 'create-ghost', 'retry-index'],
      setup(_p: unknown, { emit }: { emit: (e: string) => void }) {
        return (): VNode =>
          vh('button', { 'data-emit-retry': '', onClick: () => emit('retry-index') }, 'retry');
      },
    },
  };
}

function httpError(status: number, data: unknown) {
  return { response: { status, data } };
}

function articleEntry(index: Record<string, unknown>) {
  return { ...h.entry, index: { ...h.entry.index, ...index } };
}

describe('index retry — the article body decides WHETHER to offer it', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('pl');
    vi.clearAllMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  function mountBody(index: Record<string, unknown>) {
    return mount(KnowledgeArticleBody, {
      attachTo: document.body,
      props: {
        entry: articleEntry(index),
        entriesBySlug: new Map(),
        hrefFor: (slug: string) => `/next/knowledge/b1/reader/${slug}`,
      },
    });
  }

  it('offers the retry on EVERY server-retryable state, not just on `failed`', () => {
    for (const status of ['failed', 'partial', 'pending_budget']) {
      const wrapper = mountBody({ status, can_retry: true });
      expect(wrapper.find('[data-retry-index]').exists()).toBe(true);
      wrapper.unmount();
    }
  });

  it('hides it whenever the server says the state does not qualify', () => {
    for (const status of ['indexed', 'indexing', 'pending']) {
      const wrapper = mountBody({ status, can_retry: false });
      expect(wrapper.find('[data-retry-index]').exists()).toBe(false);
      wrapper.unmount();
    }
  });

  it('emits `retry-index` — it never makes the request itself', async () => {
    const wrapper = mountBody({ status: 'failed', can_retry: true });
    await wrapper.find('[data-retry-index]').trigger('click');

    expect(wrapper.emitted('retry-index')).toBeTruthy();
    wrapper.unmount();
  });

  it('counts a PARTIAL index as N/M once both numbers are known', () => {
    const wrapper = mountBody({ status: 'partial', chunks_count: 8, indexed_chunks_count: 5 });
    expect(wrapper.text()).toContain(t('knowledge.index.partial', '', { done: 5, total: 8 }));
    wrapper.unmount();
  });

  it('NULL is not zero: an uncountable index falls back to the bare word', () => {
    // `indexed_chunks_count: null` means "this connection cannot count them". Rendering 0/8 would
    // tell the user their index was wiped.
    const wrapper = mountBody({ status: 'partial', chunks_count: 8, indexed_chunks_count: null });
    expect(wrapper.text()).toContain(t('knowledge.index.partialShort'));
    expect(wrapper.text()).not.toContain(t('knowledge.index.partial', '', { done: 0, total: 8 }));
    wrapper.unmount();
  });
});

describe('index retry — the reader performs it', () => {
  beforeEach(() => {
    installBrowserMocks();
    setLocale('pl');
    vi.clearAllMocks();
    h.store.entry = h.entry;
  });
  afterEach(() => {
    restoreBrowserMocks();
    document.body.innerHTML = '';
  });

  function mountReader() {
    return mount(KnowledgeReaderView, { attachTo: document.body, global: { stubs: readerStubs() } });
  }

  it('renders the 200 FROM THE RESPONSE — no refetch of the entry', async () => {
    h.store.retryIndex.mockResolvedValue({
      ...h.entry,
      index: { status: 'pending', chunks_count: 8, indexed_chunks_count: 5, needs_indexing: false, can_retry: false },
    });

    const wrapper = mountReader();
    await nextTick();
    h.store.fetchEntry.mockClear();

    await wrapper.find('[data-emit-retry]').trigger('click');
    await nextTick();

    expect(h.store.retryIndex).toHaveBeenCalledWith('e1');
    expect(h.store.fetchEntry).not.toHaveBeenCalled(); // a GET here would race the worker
    expect(h.toast.success).toHaveBeenCalledWith(t('knowledge.index.retryQueued'));
    wrapper.unmount();
  });

  it('shows the SERVER’s refusal on a 422', async () => {
    h.store.retryIndex.mockRejectedValue(
      httpError(422, { errors: { index: ['This entry is already queued (pending).'] } }),
    );

    const wrapper = mountReader();
    await nextTick();

    await wrapper.find('[data-emit-retry]').trigger('click');
    await nextTick();

    expect(h.toast.danger).toHaveBeenCalledWith('This entry is already queued (pending).');
    wrapper.unmount();
  });

  it('falls back to the generic message when a failure carries none', async () => {
    h.store.retryIndex.mockRejectedValue(new Error('network'));

    const wrapper = mountReader();
    await nextTick();

    await wrapper.find('[data-emit-retry]').trigger('click');
    await nextTick();

    expect(h.toast.danger).toHaveBeenCalledWith(t('knowledge.common.saveError'));
    wrapper.unmount();
  });
});
