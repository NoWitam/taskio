// @vitest-environment happy-dom
// AppLayoutNavbar.spec — the Navbar breadcrumb contract (Batch 2, D5): the app
// shell's navbar renders NO h1 (every routed page owns its single h1 via
// PageHeader) — instead it renders a labelled breadcrumb trail: the module title
// alone on a list page, or module (linked to its root) › entity name (from
// pageContextLabel, aria-current) on a detail. AppShell and the heavy trailing
// widgets are stubbed; the REAL i18n + Breadcrumbs render the trail.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { ref } from 'vue';
import AppLayout from '../AppLayout.vue';
import { setPageContextLabel } from '../../app/lib/pageContext';
import { installBrowserMocks, restoreBrowserMocks } from '../../__tests__/helpers/dom';
import { en } from '../../app/i18n/en';

// --- Store / theme / router mocks -------------------------------------------
vi.mock('../../app/stores/auth', () => ({
  useAuthStore: () => ({
    user: { name: 'User', email: 'user@example.com', avatar: null },
    userName: 'User',
    workspaces: [],
    currentWorkspaceId: null,
    currentWorkspace: null,
    canSwitchInto: () => false,
    setCurrentWorkspace: vi.fn(),
    logout: vi.fn(),
  }),
}));
vi.mock('../../app/stores/approvalQueue', () => ({
  useApprovalQueueStore: () => ({
    count: null,
    fetchCount: vi.fn().mockResolvedValue(undefined),
  }),
}));
vi.mock('../../app/lib/theme', () => ({
  useTheme: () => ({ isDark: ref(false), toggle: vi.fn() }),
}));

const routeMeta = ref<Record<string, unknown>>({ titleKey: 'nav.workflows' });
const routePath = ref('/workflows');
vi.mock('vue-router', () => ({
  useRoute: () => ({
    get meta() {
      return routeMeta.value;
    },
    get path() {
      return routePath.value;
    },
    query: {},
    params: {},
  }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

// Slot-passthrough AppShell so the navbar content renders without the drawer chrome.
const AppShellStub = {
  template:
    '<div><slot name="sidebar" /><slot name="navbar" :open-drawer="() => {}" :drawer-open="false" /><slot /></div>',
};
const RouterLinkStub = {
  name: 'RouterLink',
  props: { to: { type: [String, Object], required: true } },
  template: '<a><slot /></a>',
};

function mountLayout() {
  return mount(AppLayout, {
    attachTo: document.body,
    global: {
      components: { RouterLink: RouterLinkStub, RouterView: { template: '<div />' } },
      stubs: {
        AppShell: AppShellStub,
        Sidebar: true,
        SidebarSection: true,
        SidebarItem: true,
        LocaleSwitcher: true,
        DropdownMenu: true,
        DropdownMenuItem: true,
        DropdownMenuLabel: true,
        DropdownMenuSeparator: true,
        CreateWorkspaceModal: true,
      },
    },
  });
}

beforeEach(() => {
  installBrowserMocks();
  setPageContextLabel(null);
  routeMeta.value = { titleKey: 'nav.workflows' };
  routePath.value = '/workflows';
});

afterEach(() => {
  restoreBrowserMocks();
  document.body.innerHTML = '';
});

describe('AppLayout navbar — breadcrumb instead of h1 (Batch 2, D5)', () => {
  it('renders no h1 anywhere in the shell chrome', async () => {
    const wrapper = mountLayout();
    await flushPromises();
    expect(wrapper.find('h1').exists()).toBe(false);
  });

  it('renders a labelled breadcrumb with the module title as the current crumb on a list page', async () => {
    const wrapper = mountLayout();
    await flushPromises();

    const nav = wrapper.find(`nav[aria-label="${en.nav.breadcrumbs}"]`);
    expect(nav.exists()).toBe(true);
    const current = nav.find('[aria-current="page"]');
    expect(current.text()).toBe(en.nav.workflows);
  });

  it('with an open entity: module crumb links to the module root, entity name is current', async () => {
    setPageContextLabel('Invoice flow');
    const wrapper = mountLayout();
    await flushPromises();

    const nav = wrapper.find(`nav[aria-label="${en.nav.breadcrumbs}"]`);
    expect(nav.text()).toContain(en.nav.workflows);
    expect(nav.find('[aria-current="page"]').text()).toBe('Invoice flow');

    const moduleLink = wrapper
      .findAllComponents(RouterLinkStub)
      .find((l) => l.text().includes(en.nav.workflows));
    expect(moduleLink).toBeTruthy();
    expect(moduleLink!.props('to')).toBe('/workflows');
  });
});
