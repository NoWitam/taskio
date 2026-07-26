// @vitest-environment happy-dom
// FunctionsView.spec — the custom-functions management surface. Asserts the list RENDERS
// functions from a (mocked) store, the first-run EMPTY + ERROR states, and that DELETING a row
// CONFIRMS first then calls the store delete — surfacing the backend's 422 in-use guard as a danger
// toast. The store + workflows-catalog + saved-views / toast / confirm composables are mocked so the
// test is isolated from HTTP + Pinia. Mirrors ConstantsView.spec.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const h = vi.hoisted(() => {
  const base = {
    description: null,
    input_type: 'number',
    return_type: 'number',
    body: [],
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    creator: null,
    created_at: null,
    updated_at: null,
  };
  const items = [
    { ...base, id: 'f1', name: 'Double', args: [{ name: 'factor', type: 'number' }] },
    { ...base, id: 'f2', name: 'Shout', input_type: 'text', return_type: 'text', args: [] },
  ];
  return {
    store: {
      items,
      loading: false,
      loadingMore: false,
      errored: false,
      loadMoreErrored: false,
      hasMore: false,
      fetchFunctions: vi.fn(),
      loadMore: vi.fn(),
      retryLoadMore: vi.fn(),
      resetAll: vi.fn(),
      createFunction: vi.fn(),
      updateFunction: vi.fn(),
      deleteFunction: vi.fn().mockResolvedValue(undefined),
    },
    workflows: { fetchWorkflowCatalog: vi.fn().mockResolvedValue({ variables: [], fields: [], operations: [] }) },
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

vi.mock('../../../app/stores/functions', () => ({ useFunctionsStore: () => h.store }));
vi.mock('../../../app/stores/workflows', () => ({ useWorkflowsStore: () => h.workflows }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => h.confirm }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../app/composables/useFilterTabs', () => ({ useFilterTabs: () => h.savedViews }));
vi.mock('../../../app/stores/filterTabs', () => ({ useFilterTabsStore: () => h.filterTabsStore }));
vi.mock('../../../app/composables/useInfiniteScroll', () => ({ useInfiniteScroll: () => ({ sentinelRef: { value: null } }) }));

import FunctionsView from '../FunctionsView.vue';

describe('FunctionsView', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    // Restore a populated, healthy store between tests (specs below mutate it).
    Object.assign(h.store, { errored: false, loading: false });
    h.store.items = [
      { id: 'f1', name: 'Double', description: null, input_type: 'number', return_type: 'number', args: [{ name: 'factor', type: 'number' }], body: [], is_owner: true, can_be_edited: true, can_be_deleted: true, creator: null, created_at: null, updated_at: null },
      { id: 'f2', name: 'Shout', description: null, input_type: 'text', return_type: 'text', args: [], body: [], is_owner: true, can_be_edited: true, can_be_deleted: true, creator: null, created_at: null, updated_at: null },
    ] as never;
    h.store.deleteFunction = vi.fn().mockResolvedValue(undefined);
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders the functions from the store + loads on mount', () => {
    const wrapper = mount(FunctionsView, { attachTo: document.body });

    expect(wrapper.text()).toContain('Double');
    expect(wrapper.text()).toContain('Shout');
    expect(h.store.fetchFunctions).toHaveBeenCalled();

    wrapper.unmount();
  });

  it('shows the first-run EMPTY state when there are no functions', () => {
    h.store.items = [] as never;
    const wrapper = mount(FunctionsView, { attachTo: document.body });

    expect(wrapper.text()).toContain('No functions yet');

    wrapper.unmount();
  });

  it('shows the ERROR state with retry when the first load failed', async () => {
    h.store.items = [] as never;
    h.store.errored = true;
    const wrapper = mount(FunctionsView, { attachTo: document.body });

    expect(wrapper.text()).toContain('Couldn’t load functions');
    // Retry re-fetches.
    const retry = wrapper.findAll('button').find((b) => b.text().includes('Retry'));
    await retry!.trigger('click');
    expect(h.store.fetchFunctions).toHaveBeenCalled();

    wrapper.unmount();
  });

  it('CONFIRMS before deleting a row, then calls the store delete', async () => {
    const wrapper = mount(FunctionsView, { attachTo: document.body });

    const deleteButtons = wrapper.findAll('button[aria-label="Delete"]');
    expect(deleteButtons.length).toBe(2);
    await deleteButtons[0].trigger('click');
    await nextTick();
    await Promise.resolve();

    expect(h.confirm).toHaveBeenCalledTimes(1);
    expect(h.store.deleteFunction).toHaveBeenCalledWith('f1');

    wrapper.unmount();
  });

  it('surfaces the backend 422 in-use guard as a danger toast (no success)', async () => {
    h.store.deleteFunction = vi.fn().mockRejectedValue({ response: { status: 422, data: { errors: { function: ['in use'] } } } });
    const wrapper = mount(FunctionsView, { attachTo: document.body });

    await wrapper.findAll('button[aria-label="Delete"]')[0].trigger('click');
    await nextTick();
    await Promise.resolve();
    await Promise.resolve();

    expect(h.store.deleteFunction).toHaveBeenCalledWith('f1');
    expect(h.toast.danger).toHaveBeenCalledTimes(1);
    expect(h.toast.success).not.toHaveBeenCalled();

    wrapper.unmount();
  });
});
