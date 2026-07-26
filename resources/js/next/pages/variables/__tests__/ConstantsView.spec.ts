// @vitest-environment happy-dom
// ConstantsView.spec — the consts management surface (formerly "globals"). Asserts the list
// RENDERS constants from a (mocked) store and that DELETING a row CONFIRMS first, then calls
// the store's delete. The store + the saved-views / toast / confirm composables are mocked so
// the test is isolated from HTTP + Pinia.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const h = vi.hoisted(() => {
  const base = { descriptor: { base: 'text', nullable: false, array: false }, value: 'x', reference: 'globals.k', is_owner: true, can_be_edited: true, can_be_deleted: true, creator: null, created_at: null, updated_at: null };
  const items = [
    { ...base, id: 'c1', name: 'Brand', key: 'brand', reference: 'globals.brand' },
    { ...base, id: 'c2', name: 'Budget', key: 'budget', reference: 'globals.budget' },
  ];
  return {
    store: {
      items,
      loading: false,
      loadingMore: false,
      errored: false,
      loadMoreErrored: false,
      hasMore: false,
      fetchConstants: vi.fn(),
      loadMore: vi.fn(),
      retryLoadMore: vi.fn(),
      resetAll: vi.fn(),
      createConstant: vi.fn(),
      updateConstant: vi.fn(),
      deleteConstant: vi.fn().mockResolvedValue(undefined),
    },
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

vi.mock('../../../app/stores/consts', () => ({ useConstsStore: () => h.store }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => h.confirm }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../app/composables/useFilterTabs', () => ({ useFilterTabs: () => h.savedViews }));
vi.mock('../../../app/stores/filterTabs', () => ({ useFilterTabsStore: () => h.filterTabsStore }));
vi.mock('../../../app/composables/useInfiniteScroll', () => ({ useInfiniteScroll: () => ({ sentinelRef: { value: null } }) }));

import ConstantsView from '../ConstantsView.vue';

describe('ConstantsView', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders the constants from the store (name + reference chip)', () => {
    const wrapper = mount(ConstantsView, { attachTo: document.body });

    expect(wrapper.text()).toContain('Brand');
    expect(wrapper.text()).toContain('Budget');
    // The reference chip keeps the runtime `globals.<key>` wire.
    expect(wrapper.text()).toContain('globals.brand');
    // The store was asked to load on mount.
    expect(h.store.fetchConstants).toHaveBeenCalled();

    wrapper.unmount();
  });

  it('CONFIRMS before deleting a row, then calls the store delete', async () => {
    const wrapper = mount(ConstantsView, { attachTo: document.body });

    const deleteButtons = wrapper.findAll('button[aria-label="Delete"]');
    expect(deleteButtons.length).toBe(2);
    await deleteButtons[0].trigger('click');
    await nextTick();
    await Promise.resolve();

    expect(h.confirm).toHaveBeenCalledTimes(1);
    expect(h.store.deleteConstant).toHaveBeenCalledWith('c1');

    wrapper.unmount();
  });
});
