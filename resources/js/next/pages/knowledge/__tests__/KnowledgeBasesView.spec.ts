// @vitest-environment happy-dom
// KnowledgeBasesView.spec — the knowledge BASES list.
//
// Covers the state matrix the batch owes: skeletons → data → empty (both variants) → error, plus
// the two things a list can quietly get wrong — that the mandatory Saved Views FilterTabBar is
// actually mounted, and that trashing a base CONFIRMS first. Every assertion goes through `t()`
// so the copy can change without the test lying about what the user reads.
//
// The store + the saved-views / toast / confirm composables are mocked, so this is isolated from
// HTTP and from Pinia. Mirrors SessionsView.spec / TemplatesView.spec.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { h as vh, nextTick, type VNode } from 'vue';
import { setLocale, translate } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

// Render the trigger + the menu items inline (skip the teleported Popover) so a menuitem is
// queryable — mirrors WorkflowCard.spec / SessionsView.spec.
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
    description: null,
    charter: null,
    language: 'pl',
    metadata_schema: [],
    creator: null,
    is_owner: true,
    can_be_edited: true,
    can_be_managed: true,
    can_be_deleted: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-07-31T00:00:00Z',
    deleted_at: null,
  };
  return {
    store: {
      items: [
        { ...base, id: 'b1', name: 'Brand', entries_count: 12, charter: 'How we speak. Nothing about pricing.' },
        { ...base, id: 'b2', name: 'Product', entries_count: 0, can_be_deleted: false },
      ],
      loading: false,
      loadingMore: false,
      errored: false,
      loadMoreErrored: false,
      hasMore: false,
      fetchBases: vi.fn(),
      loadMore: vi.fn(),
      retryLoadMore: vi.fn(),
      resetAll: vi.fn(),
      createBase: vi.fn(),
      updateBase: vi.fn(),
      deleteBase: vi.fn().mockResolvedValue(undefined),
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
    // B5: the card and the kebab both NAVIGATE now, so the view needs a router.
    router: { push: vi.fn(), replace: vi.fn() },
  };
});

vi.mock('vue-router', () => ({ useRouter: () => h.router, useRoute: () => ({ params: {}, query: {} }) }));
vi.mock('../../../app/stores/knowledge', () => ({ useKnowledgeStore: () => h.store }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => h.confirm }));
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => h.toast }));
vi.mock('../../../app/composables/useFilterTabs', () => ({ useFilterTabs: () => h.savedViews }));
vi.mock('../../../app/stores/filterTabs', () => ({ useFilterTabsStore: () => h.filterTabsStore }));
vi.mock('../../../app/composables/useInfiniteScroll', () => ({
  useInfiniteScroll: () => ({ sentinelRef: { value: null } }),
}));

import KnowledgeBasesView from '../KnowledgeBasesView.vue';

const t = translate;

function mountView(stubs: Record<string, unknown> = {}) {
  return mount(KnowledgeBasesView, { attachTo: document.body, global: { stubs } });
}

describe('KnowledgeBasesView', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    // The empty state's "import a bot's knowledge" modal is a real component that reaches for the
    // BOTS store (a genuine Pinia store, unlike the mocked knowledge one), so the tree needs one.
    setActivePinia(createPinia());
    vi.clearAllMocks();
    Object.assign(h.store, {
      loading: false,
      loadingMore: false,
      errored: false,
      loadMoreErrored: false,
      hasMore: false,
      items: [
        {
          id: 'b1',
          name: 'Brand',
          description: null,
          charter: 'How we speak. Nothing about pricing.',
          language: 'pl',
          metadata_schema: [
            { key: 'channel', label: 'Channel', descriptor: { base: 'text', nullable: false, array: false } },
          ],
          entries_count: 12,
          creator: null,
          is_owner: true,
          can_be_edited: true,
          can_be_managed: true,
          can_be_deleted: true,
          created_at: '2026-01-01T00:00:00Z',
          updated_at: '2026-07-31T00:00:00Z',
          deleted_at: null,
        },
        {
          id: 'b2',
          name: 'Product',
          description: 'Everything we sell',
          charter: null,
          language: 'en',
          metadata_schema: [],
          entries_count: 0,
          creator: null,
          is_owner: false,
          can_be_edited: true,
          can_be_managed: false,
          can_be_deleted: false,
          created_at: '2026-01-01T00:00:00Z',
          updated_at: '2026-07-30T00:00:00Z',
          deleted_at: null,
        },
      ],
    });
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('fetches on mount and renders the bases with their metadata', () => {
    const wrapper = mountView();

    expect(h.store.fetchBases).toHaveBeenCalled();
    expect(h.savedViews.load).toHaveBeenCalled();

    expect(wrapper.text()).toContain('Brand');
    expect(wrapper.text()).toContain('Product');
    // The charter's FIRST sentence is the subtitle; a base without one falls back to its description.
    expect(wrapper.text()).toContain('How we speak.');
    expect(wrapper.text()).not.toContain('Nothing about pricing.');
    expect(wrapper.text()).toContain('Everything we sell');
    // Metadata footer: entries · language · schema fields · updated.
    expect(wrapper.text()).toContain(t('knowledge.bases.meta.entries'));
    expect(wrapper.text()).toContain('12');
    expect(wrapper.text()).toContain(t('knowledge.language.pl'));

    wrapper.unmount();
  });

  it('mounts the MANDATORY Saved Views tab bar inside the FilterBar', () => {
    const wrapper = mountView();

    expect(wrapper.findComponent({ name: 'FilterTabBar' }).exists()).toBe(true);

    wrapper.unmount();
  });

  it('shows card-shaped skeletons — not a spinner — while the first page loads', () => {
    h.store.loading = true;
    h.store.items = [];
    const wrapper = mountView();

    const status = wrapper.find('[role="status"]');
    expect(status.exists()).toBe(true);
    expect(status.attributes('aria-label')).toBe(t('knowledge.common.loadingLabel'));
    // Several shapes that mirror the real card, per the skeleton rule.
    expect(status.findAllComponents({ name: 'EntityCard' }).length).toBe(6);
    expect(wrapper.findComponent({ name: 'Spinner' }).exists()).toBe(false);

    wrapper.unmount();
  });

  it('offers TWO paths on the first-run empty state: create, or import a bot’s knowledge', async () => {
    h.store.items = [];
    const wrapper = mountView();

    expect(wrapper.text()).toContain(t('knowledge.bases.empty.title'));
    expect(wrapper.text()).toContain(t('knowledge.bases.empty.description'));

    const create = wrapper.findAll('button').find((b) => b.text() === t('knowledge.bases.empty.create'));
    expect(create).toBeTruthy();

    // The second path is LIVE since B6 (the migration endpoint): it opens the import modal
    // instead of being a disabled "coming soon" button.
    const migrate = wrapper.findAll('button').find((b) => b.text() === t('knowledge.bases.empty.migrate'));
    expect(migrate).toBeTruthy();
    expect(migrate!.attributes('disabled')).toBeUndefined();

    await migrate!.trigger('click');
    await nextTick();
    expect(wrapper.findComponent({ name: 'BotKnowledgeMigrationModal' }).props('open')).toBe(true);

    wrapper.unmount();
  });

  it('switches to the filtered empty state once a search is typed', async () => {
    h.store.items = [];
    const wrapper = mountView();

    // Commit through FilterBar's `v-model:search` rather than typing: the bar debounces
    // keystrokes by design, and this test is about the empty-state SWITCH, not the debounce.
    wrapper.findComponent({ name: 'FilterBar' }).vm.$emit('update:search', 'nothing');
    await nextTick();

    expect(wrapper.text()).toContain(t('knowledge.bases.emptySearch.title'));
    expect(wrapper.text()).not.toContain(t('knowledge.bases.empty.title'));
    // The way OUT of a filtered empty state is always offered.
    expect(
      wrapper.findAll('button').some((b) => b.text() === t('knowledge.bases.emptySearch.action')),
    ).toBe(true);

    wrapper.unmount();
  });

  it('shows an error state with a retry that refetches', async () => {
    h.store.items = [];
    h.store.errored = true;
    const wrapper = mountView();

    expect(wrapper.text()).toContain(t('knowledge.bases.errors.title'));

    const retry = wrapper.findAll('button').find((b) => b.text() === t('knowledge.common.retry'));
    expect(retry).toBeTruthy();
    h.store.fetchBases.mockClear();
    await retry!.trigger('click');

    expect(h.store.fetchBases).toHaveBeenCalled();

    wrapper.unmount();
  });

  // B5 CHANGED THIS ON PURPOSE. In B4 a card click opened the SETTINGS drawer, because the reader
  // did not exist yet and a card that navigated nowhere would have been worse. Now it exists, and
  // clicking a base's name must land on its contents — sending a reader to a configuration form was
  // always the wrong destination. Settings keeps its own route, reachable from the kebab.
  it('navigates to the READER when a base card is activated', async () => {
    const wrapper = mountView();

    const card = wrapper
      .findAll('button, a')
      .find((el) => el.attributes('aria-label') === t('knowledge.bases.card.open', '', { name: 'Brand' }));
    expect(card).toBeTruthy();
    await card!.trigger('click');
    await nextTick();

    expect(h.router.push).toHaveBeenCalledWith({
      name: 'next.knowledge.base.reader',
      params: { baseId: 'b1' },
    });

    // …and it did NOT open the editor drawer on the way.
    const drawer = wrapper.findComponent({ name: 'KnowledgeBaseSettingsDrawer' });
    expect(drawer.props('open')).toBe(false);

    wrapper.unmount();
  });

  it('routes the kebab "Settings" action to the base settings SECTION', async () => {
    const wrapper = mountView({ DropdownMenu: DropdownMenuStub, DropdownMenuItem: DropdownMenuItemStub });

    const settingsItems = wrapper.findAll(
      `.dm-item[data-label="${t('knowledge.bases.menu.settings')}"]`,
    );
    expect(settingsItems.length).toBeGreaterThan(0);
    await settingsItems[0].trigger('click');
    await nextTick();

    expect(h.router.push).toHaveBeenCalledWith({
      name: 'next.knowledge.base.settings',
      params: { baseId: 'b1' },
    });

    wrapper.unmount();
  });

  it('CONFIRMS before trashing a base, then calls the store', async () => {
    const wrapper = mountView({ DropdownMenu: DropdownMenuStub, DropdownMenuItem: DropdownMenuItemStub });

    const trashItems = wrapper.findAll(`.dm-item[data-label="${t('knowledge.bases.menu.trash')}"]`);
    expect(trashItems).toHaveLength(1); // only the deletable base offers the enabled action
    await trashItems[0].trigger('click');
    await nextTick();
    await Promise.resolve();

    expect(h.confirm).toHaveBeenCalledTimes(1);
    expect(h.store.deleteBase).toHaveBeenCalledWith('b1');

    wrapper.unmount();
  });

  it('keeps the trash action VISIBLE but disabled, with the reason as its label, when the policy says no', () => {
    const wrapper = mountView({ DropdownMenu: DropdownMenuStub, DropdownMenuItem: DropdownMenuItemStub });

    const blocked = wrapper.findAll(`.dm-item[data-label="${t('knowledge.bases.menu.trashDisabled')}"]`);
    expect(blocked).toHaveLength(1);
    expect(blocked[0].attributes('disabled')).toBeDefined();

    wrapper.unmount();
  });

  it('creates a base end to end: drawer Save → form payload → store call → toast', async () => {
    h.store.createBase.mockResolvedValue({ id: 'b3', name: 'Support' });
    const wrapper = mountView();

    await wrapper.findAll('button').find((b) => b.text() === t('knowledge.bases.new'))!.trigger('click');
    await nextTick();

    // The Drawer teleports to <body>; scope every query to the dialog, since the view itself is
    // attached to <body> too (a bare body query would find the FilterBar's search input).
    const dialog = document.body.querySelector('[role="dialog"]') as HTMLElement;
    expect(dialog, 'expected the settings drawer to be mounted').toBeTruthy();
    const nameField = dialog.querySelector('input') as HTMLInputElement;
    expect(nameField).toBeTruthy();
    nameField.value = 'Support';
    nameField.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();

    const save = Array.from(dialog.querySelectorAll('button')).find(
      (b) => b.textContent?.trim() === t('knowledge.settings.create'),
    );
    expect(save, 'expected the drawer footer to offer the create action').toBeTruthy();
    save!.dispatchEvent(new Event('click', { bubbles: true }));
    await nextTick();
    await Promise.resolve();
    await nextTick();

    expect(h.store.createBase).toHaveBeenCalledWith(expect.objectContaining({ name: 'Support' }));
    expect(h.toast.success).toHaveBeenCalledWith(t('knowledge.bases.toasts.created'));

    wrapper.unmount();
  });

  it('keeps the drawer OPEN and surfaces 422 field errors when the write fails', async () => {
    h.store.createBase.mockRejectedValue({
      response: { data: { errors: { name: ['That name is taken.'] } } },
    });
    const wrapper = mountView();

    await wrapper.findAll('button').find((b) => b.text() === t('knowledge.bases.new'))!.trigger('click');
    await nextTick();

    const dialog = document.body.querySelector('[role="dialog"]') as HTMLElement;
    const nameField = dialog.querySelector('input') as HTMLInputElement;
    nameField.value = 'Support';
    nameField.dispatchEvent(new Event('input', { bubbles: true }));
    await nextTick();

    const save = Array.from(dialog.querySelectorAll('button')).find(
      (b) => b.textContent?.trim() === t('knowledge.settings.create'),
    );
    save!.dispatchEvent(new Event('click', { bubbles: true }));
    await nextTick();
    await Promise.resolve();
    await nextTick();

    const drawer = wrapper.findComponent({ name: 'KnowledgeBaseSettingsDrawer' });
    expect(drawer.props('open')).toBe(true); // never discard what the user typed
    expect(drawer.props('serverErrors')).toEqual({ name: 'That name is taken.' });
    expect(document.body.textContent).toContain('That name is taken.');

    wrapper.unmount();
  });

  it('opens an empty settings drawer from the "New base" action', async () => {
    const wrapper = mountView();

    const create = wrapper.findAll('button').find((b) => b.text() === t('knowledge.bases.new'));
    await create!.trigger('click');
    await nextTick();

    const drawer = wrapper.findComponent({ name: 'KnowledgeBaseSettingsDrawer' });
    expect(drawer.props('open')).toBe(true);
    expect(drawer.props('base')).toBeNull();

    wrapper.unmount();
  });
});
