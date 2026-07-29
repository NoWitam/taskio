// @vitest-environment happy-dom
// SessionsView.spec — the generation-sessions list. Asserts the list RENDERS sessions from a (mocked)
// store with their status, and that DELETING a row CONFIRMS first, then calls the store's delete. The
// stores + the saved-views / toast / confirm / router composables are mocked so the test is isolated
// from HTTP + Pinia. Mirrors TemplatesView.spec.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { h as vh, nextTick, type VNode } from 'vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

// Render the trigger + the menu items inline (skip the teleported Popover) so a menuitem is queryable —
// mirrors WorkflowCard.spec. Used only where the row's overflow menu is under test.
const DropdownMenuStub = {
  name: 'DropdownMenu',
  setup(_props: unknown, { slots }: { slots: Record<string, ((arg?: unknown) => VNode[]) | undefined> }) {
    return () =>
      vh('div', { class: 'dm' }, [
        slots.trigger ? slots.trigger({ props: {} }) : null,
        vh('ul', { class: 'dm-list' }, slots.default ? slots.default() : []),
      ]);
  },
};
const DropdownMenuItemStub = {
  name: 'DropdownMenuItem',
  props: ['icon', 'label', 'disabled', 'destructive'],
  emits: ['select'],
  setup(
    props: Record<string, unknown>,
    { slots, emit }: { slots: Record<string, (() => VNode[]) | undefined>; emit: (e: string) => void },
  ) {
    return () =>
      vh(
        'button',
        {
          class: 'dm-item',
          'data-label': props.label,
          disabled: props.disabled ? true : undefined,
          onClick: () => emit('select'),
        },
        slots.default ? slots.default() : [],
      );
  },
};

const h = vi.hoisted(() => {
  const base = {
    template_id: 't1',
    slot_values: {},
    results: null,
    part_history: {},
    last_op_status: null,
    last_op_error: null,
    creator: null,
    is_owner: true,
    can_generate: true,
    can_edit: true,
    can_be_deleted: true,
    is_archived: false,
    can_archive: true,
    archived_at: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  };
  const items = [
    { ...base, id: 's1', name: 'Spring promo', content_type: 'post_with_image', status: 'ready' },
    { ...base, id: 's2', name: 'Weekly recap', content_type: 'video_script', status: 'draft' },
  ];
  return {
    store: {
      items,
      loading: false,
      loadingMore: false,
      errored: false,
      loadMoreErrored: false,
      hasMore: false,
      detail: null,
      fetchSessions: vi.fn(),
      loadMore: vi.fn(),
      retryLoadMore: vi.fn(),
      resetAll: vi.fn(),
      createSession: vi.fn(),
      deleteSession: vi.fn().mockResolvedValue(undefined),
      archiveSession: vi.fn().mockResolvedValue(undefined),
      unarchiveSession: vi.fn().mockResolvedValue(undefined),
    },
    templatesStore: {
      items: [],
      loading: false,
      errored: false,
      fetchTemplates: vi.fn(),
    },
    confirm: vi.fn().mockResolvedValue(true),
    toast: { success: vi.fn(), danger: vi.fn() },
    router: { push: vi.fn() },
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

vi.mock('../../../app/stores/sessions', () => ({ useSessionsStore: () => h.store }));
vi.mock('../../../app/stores/templates', () => ({ useTemplatesStore: () => h.templatesStore }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => h.confirm }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../app/composables/useFilterTabs', () => ({ useFilterTabs: () => h.savedViews }));
vi.mock('../../../app/stores/filterTabs', () => ({ useFilterTabsStore: () => h.filterTabsStore }));
vi.mock('../../../app/composables/useInfiniteScroll', () => ({ useInfiniteScroll: () => ({ sentinelRef: { value: null } }) }));
vi.mock('vue-router', () => ({ useRouter: () => h.router }));

import SessionsView from '../SessionsView.vue';

describe('SessionsView', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('renders the sessions from the store (name + status badge) and loads on mount', () => {
    const wrapper = mount(SessionsView, { attachTo: document.body });

    expect(wrapper.text()).toContain('Spring promo');
    expect(wrapper.text()).toContain('Weekly recap');
    // Status badges are localized (never color-only).
    expect(wrapper.text()).toContain('Ready');
    expect(wrapper.text()).toContain('Draft');
    expect(h.store.fetchSessions).toHaveBeenCalled();

    wrapper.unmount();
  });

  it('CONFIRMS before deleting a row, then calls the store delete', async () => {
    const wrapper = mount(SessionsView, { attachTo: document.body });

    const deleteButtons = wrapper.findAll('button[aria-label="Delete"]');
    expect(deleteButtons.length).toBe(2);
    await deleteButtons[0].trigger('click');
    await nextTick();
    await Promise.resolve();

    expect(h.confirm).toHaveBeenCalledTimes(1);
    expect(h.store.deleteSession).toHaveBeenCalledWith('s1');

    wrapper.unmount();
  });

  it('opens a session via the router when its Open action is clicked', async () => {
    const wrapper = mount(SessionsView, { attachTo: document.body });

    const openButtons = wrapper.findAll('button').filter((b) => b.text() === 'Open');
    expect(openButtons.length).toBe(2);
    await openButtons[0].trigger('click');

    expect(h.router.push).toHaveBeenCalledWith({ name: 'next.generator.sessions.detail', params: { id: 's1' } });

    wrapper.unmount();
  });

  it('archives a session from the row overflow menu (2d)', async () => {
    const wrapper = mount(SessionsView, {
      attachTo: document.body,
      global: { stubs: { DropdownMenu: DropdownMenuStub, DropdownMenuItem: DropdownMenuItemStub } },
    });

    // The inline-stubbed menu items are queryable; pick the first row's Archive.
    const archiveItems = wrapper.findAll('.dm-item[data-label="Archive"]');
    expect(archiveItems.length).toBe(2);
    expect(archiveItems[0].attributes('disabled')).toBeUndefined();
    await archiveItems[0].trigger('click');
    await nextTick();

    expect(h.store.archiveSession).toHaveBeenCalledWith('s1');
    wrapper.unmount();
  });
});
