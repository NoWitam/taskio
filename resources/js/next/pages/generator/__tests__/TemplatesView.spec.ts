// @vitest-environment happy-dom
// TemplatesView.spec — the templates management surface. Asserts the list RENDERS templates from a
// (mocked) store and that DELETING a row CONFIRMS first, then calls the store's delete. The store +
// the saved-views / toast / confirm composables are mocked so the test is isolated from HTTP + Pinia.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const h = vi.hoisted(() => {
  const base = {
    description: null,
    slots: [],
    content: {},
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    created_at: null,
    updated_at: null,
  };
  const items = [
    { ...base, id: 't1', name: 'Launch post', content_type: 'post' },
    { ...base, id: 't2', name: 'Weekly recap', content_type: 'video_script' },
  ];
  return {
    store: {
      items,
      loading: false,
      loadingMore: false,
      errored: false,
      loadMoreErrored: false,
      hasMore: false,
      fetchTemplates: vi.fn(),
      loadMore: vi.fn(),
      retryLoadMore: vi.fn(),
      resetAll: vi.fn(),
      createTemplate: vi.fn(),
      updateTemplate: vi.fn(),
      deleteTemplate: vi.fn().mockResolvedValue(undefined),
      fetchContentTypes: vi.fn().mockResolvedValue([]),
      fetchCatalog: vi.fn().mockResolvedValue({ variables: [], operations: [], types: [] }),
      preview: vi.fn().mockResolvedValue({ parts: {} }),
    },
    sessionsStore: {
      createSession: vi.fn().mockResolvedValue({ id: 's-new' }),
    },
    router: { push: vi.fn() },
    confirm: vi.fn().mockResolvedValue(true),
    toast: { success: vi.fn(), danger: vi.fn() },
    savedViews: {
      tabs: { value: [] },
      activeTabId: { value: null },
      dirty: { value: false },
      loading: { value: false },
      loadError: { value: null },
      decorateActiveFilters: (x: unknown) => x,
      load: vi.fn(),
      clearActive: vi.fn(),
      applyTab: vi.fn(),
      saveActive: vi.fn(),
      saveAs: vi.fn(),
      restoreFilter: vi.fn(),
    },
    filterTabsStore: { update: vi.fn(), remove: vi.fn(), reorder: vi.fn() },
  };
});

vi.mock('../../../app/stores/templates', () => ({ useTemplatesStore: () => h.store }));
vi.mock('../../../app/stores/sessions', () => ({ useSessionsStore: () => h.sessionsStore }));
vi.mock('vue-router', () => ({ useRouter: () => h.router }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => h.confirm }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../app/composables/useFilterTabs', () => ({ useFilterTabs: () => h.savedViews }));
vi.mock('../../../app/stores/filterTabs', () => ({ useFilterTabsStore: () => h.filterTabsStore }));
vi.mock('../../../app/composables/useInfiniteScroll', () => ({ useInfiniteScroll: () => ({ sentinelRef: { value: null } }) }));

import TemplatesView from '../TemplatesView.vue';

describe('TemplatesView', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders the templates from the store (name + type badge)', () => {
    const wrapper = mount(TemplatesView, { attachTo: document.body });

    expect(wrapper.text()).toContain('Launch post');
    expect(wrapper.text()).toContain('Weekly recap');
    // The type badge is localized.
    expect(wrapper.text()).toContain('Video script');
    // The store was asked to load on mount.
    expect(h.store.fetchTemplates).toHaveBeenCalled();

    wrapper.unmount();
  });

  it('CONFIRMS before deleting a row, then calls the store delete', async () => {
    const wrapper = mount(TemplatesView, { attachTo: document.body });

    const deleteButtons = wrapper.findAll('button[aria-label="Delete"]');
    expect(deleteButtons.length).toBe(2);
    await deleteButtons[0].trigger('click');
    await nextTick();
    await Promise.resolve();

    expect(h.confirm).toHaveBeenCalledTimes(1);
    expect(h.store.deleteTemplate).toHaveBeenCalledWith('t1');

    wrapper.unmount();
  });

  it('creates a session straight from a row and navigates to its chat', async () => {
    const wrapper = mount(TemplatesView, { attachTo: document.body });

    const startButtons = wrapper.findAll('button[aria-label="Start session"]');
    expect(startButtons.length).toBe(2);
    await startButtons[0].trigger('click');
    await nextTick();
    await Promise.resolve();
    await Promise.resolve();

    expect(h.sessionsStore.createSession).toHaveBeenCalledWith({ template_id: 't1' });
    expect(h.router.push).toHaveBeenCalledWith({
      name: 'next.generator.sessions.detail',
      params: { id: 's-new' },
    });
    expect(h.toast.success).toHaveBeenCalled();

    wrapper.unmount();
  });
});
