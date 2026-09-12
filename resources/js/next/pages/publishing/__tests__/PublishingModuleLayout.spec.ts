// @vitest-environment happy-dom
// PublishingModuleLayout.spec — switching workspace does NOT navigate, and that is the trap.
//
// ═════════════════════════════════════════════════════════════════════════════════════════
// THE LAYOUT STAYS MOUNTED WITH SOMEBODY ELSE'S DATA ON SCREEN
// ═════════════════════════════════════════════════════════════════════════════════════════
// `auth.setCurrentWorkspace()` swaps the context and refetches `/auth/me`. It does not route
// anywhere, so this layout is never re-created and every cached row, account and number stays
// exactly where it was. Publications, accounts, counts and THE CLOCK are all per workspace.
//
// THE CLOCK IS THE ENTRY THAT MATTERS. `timezone` is LATCHED — loaded once per module visit
// behind a `timezoneLoaded` flag — so a layout that only cleared the lists would go on
// reading every moment in this module on the PREVIOUS workspace's zone. Nothing on screen
// says so: "Scheduled for 09:00" simply looks plausible and is wrong by an hour, or nine.
// That is the failure this test exists for, and it is invisible in a browser.
//
// The aside badge is the second half. It counts accounts that need a person, and it is the
// only way a broken account is visible while somebody is looking at publications — so a
// badge carrying the last workspace's number sends them to repair an account they cannot see.
// The assertion is deliberately taken while the NEW workspace's request is still in the air:
// that is the window in which stale data would show, and it is the whole window.
//
// The stores here are REAL (only `api` and the auth context are mocked), because `resetAll()`
// is precisely the behaviour under test — a mock of it would only test the mock.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { h, reactive, ref, type VNode } from 'vue';

vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

// --- Router -----------------------------------------------------------------
const routeMock = reactive({
  path: '/publishing/publications',
  name: 'next.publishing.publications',
  params: {} as Record<string, string>,
  query: {} as Record<string, unknown>,
});
const replaceMock = vi.fn();
vi.mock('vue-router', () => ({
  useRoute: () => routeMock,
  useRouter: () => ({ replace: replaceMock, push: vi.fn() }),
}));

// --- The auth context (the only thing a workspace switch changes) -----------
const authMock = reactive({ currentWorkspaceId: 'ws-1' as string | null });
vi.mock('../../../app/stores/auth', () => ({ useAuthStore: () => authMock }));

import PublishingModuleLayout from '../PublishingModuleLayout.vue';
import { api } from '../../../app/lib/api';
import { usePublishingStore } from '../../../app/stores/publishing';
import { usePublishingConnectionsStore } from '../../../app/stores/publishingConnections';
import { setLocale } from '../../../app/i18n';

const apiGet = vi.mocked(api.get);

/** A broken account — the thing the aside badge counts. */
function brokenConnection(id: string) {
  return {
    id,
    platform: 'youtube',
    platform_label: 'YouTube',
    external_account_id: 'UCxxxx',
    account_name: 'Taskio Demo',
    status: 'needs_reauth',
    status_label: 'Needs reconnecting',
    status_tone: 'warning',
    needs_attention: true,
    can_publish: false,
    scopes: [],
    expires_at: null,
    last_refreshed_at: null,
    failure_code: 'refresh_failed',
    credentials_readable: true,
    creator: null,
    is_owner: true,
    can_be_disconnected: true,
    created_at: null,
    updated_at: null,
  };
}

/** How many times the routed screen has been created — the `:key` remount, observed. */
let routerViewMounts = 0;
const RouterViewStub = {
  name: 'RouterView',
  setup() {
    routerViewMounts += 1;
    return () => h('div', { class: 'routed-screen' }, 'screen');
  },
};

/** RouterLink has to render its slot: the aside's badge lives inside one. */
const RouterLinkStub = {
  name: 'RouterLink',
  props: ['to'],
  setup(_p: unknown, { slots }: { slots: Record<string, (() => VNode[]) | undefined> }) {
    return () => h('a', slots.default ? slots.default() : []);
  },
};

/**
 * Answer the two requests this layout makes on the way in. `connectionsFor` decides what the
 * accounts list says per workspace; `'hang'` leaves the request in flight, which is the state
 * a stale badge would be visible in.
 */
const connectionsFor: Record<string, 'hang' | unknown[]> = {};
/** The same, for the workspace resource that carries the clock. */
const zoneFor: Record<string, 'hang' | string> = {};

function installApi(): void {
  apiGet.mockImplementation((url: string) => {
    if (url.startsWith('/publishing/connections')) {
      const answer = connectionsFor[String(authMock.currentWorkspaceId)] ?? [];
      if (answer === 'hang') return new Promise(() => {});
      return Promise.resolve({ data: answer });
    }
    if (url.startsWith('/workspaces/')) {
      const id = url.slice('/workspaces/'.length);
      const zone = zoneFor[id] ?? (id === 'ws-1' ? 'Europe/Warsaw' : 'America/New_York');
      if (zone === 'hang') return new Promise(() => {});
      return Promise.resolve({ data: { timezone: zone } });
    }
    if (url.startsWith('/publishing/counts')) {
      return Promise.resolve({
        data: {
          counts: { draft: 1, scheduled: 2, publishing: 0, published: 0, failed: 0, needs_reconcile: 0, blocked: 0 },
          total: 3,
          needs_attention: 0,
        },
      });
    }
    return Promise.resolve({ data: [] });
  });
}

/**
 * Every mounted layout is tracked and unmounted in `afterEach`.
 *
 * NOT housekeeping: `authMock` is module-level, so a layout left mounted from an earlier test
 * still has a live watcher on `currentWorkspaceId`. Switching the workspace in one test then
 * fires every previous test's layout as well — each resetting its own stores, re-keying its
 * own RouterView and issuing its own requests — and the counters this file asserts on would
 * read the whole file's history instead of one test.
 */
const mounted: { unmount: () => void }[] = [];

async function mountLayout() {
  const wrapper = mount(PublishingModuleLayout, {
    global: {
      stubs: {
        RouterView: RouterViewStub,
        RouterLink: RouterLinkStub,
        ModuleTabs: true,
        Drawer: true,
        PublicationEditorDrawer: true,
      },
    },
  });
  mounted.push(wrapper);
  await flushPromises();
  return wrapper;
}

/** The aside's account badges, as digits on screen. */
function badgeText(wrapper: Awaited<ReturnType<typeof mountLayout>>): string {
  return wrapper.findAll('.next-badge').map((b) => b.text().trim()).join(' ');
}

beforeEach(() => {
  setLocale('en');
  setActivePinia(createPinia());
  routeMock.query = {};
  routeMock.path = '/publishing/publications';
  routerViewMounts = 0;
  replaceMock.mockReset();
  apiGet.mockReset();
  authMock.currentWorkspaceId = 'ws-1';
  for (const key of Object.keys(connectionsFor)) delete connectionsFor[key];
  for (const key of Object.keys(zoneFor)) delete zoneFor[key];
  installApi();
});

afterEach(() => {
  while (mounted.length) mounted.pop()!.unmount();
  document.body.innerHTML = '';
});

describe('arriving in the module', () => {
  it('asks for the accounts and the workspace clock once', async () => {
    connectionsFor['ws-1'] = [brokenConnection('c1')];
    await mountLayout();

    const urls = apiGet.mock.calls.map((c) => String(c[0]));
    expect(urls.filter((u) => u.startsWith('/publishing/connections'))).toHaveLength(1);
    expect(urls.some((u) => u === '/workspaces/ws-1')).toBe(true);
    expect(usePublishingStore().timezone).toBe('Europe/Warsaw');
  });

  it('shows the broken-account count in the aside', async () => {
    connectionsFor['ws-1'] = [brokenConnection('c1'), brokenConnection('c2')];
    const wrapper = await mountLayout();
    expect(badgeText(wrapper)).toContain('2');
  });
});

describe('switching workspace', () => {
  it('drops THE CLOCK, so no moment is read on the workspace we just left', async () => {
    // The new workspace's clock request is left in flight: that window is exactly when a
    // layout that kept the old zone would be reading every moment in this module on it, and
    // `null` is the one honest answer there ("inherit the application clock" — the UI has
    // words for that and never substitutes the browser's zone).
    connectionsFor['ws-1'] = [];
    connectionsFor['ws-2'] = [];
    zoneFor['ws-2'] = 'hang';
    await mountLayout();
    const store = usePublishingStore();
    expect(store.timezone).toBe('Europe/Warsaw');

    authMock.currentWorkspaceId = 'ws-2';
    await flushPromises();

    expect(store.timezone).toBeNull();
  });

  it('and RE-ASKS for it — the latch would otherwise keep it unknown forever', async () => {
    // `timezoneLoaded` is a latch. Left standing, the refetch is skipped and the zone stays
    // whatever the reset left behind — silently, for a team on another continent.
    connectionsFor['ws-1'] = [];
    connectionsFor['ws-2'] = [];
    await mountLayout();

    authMock.currentWorkspaceId = 'ws-2';
    await flushPromises();

    expect(usePublishingStore().timezone).toBe('America/New_York');
    expect(apiGet.mock.calls.map((c) => String(c[0]))).toContain('/workspaces/ws-2');
  });

  it('drops the counts — a bar of numbers from somewhere else', async () => {
    connectionsFor['ws-1'] = [];
    connectionsFor['ws-2'] = 'hang';
    await mountLayout();
    const store = usePublishingStore();
    await store.fetchCounts();
    expect(store.counts?.total).toBe(3);

    authMock.currentWorkspaceId = 'ws-2';
    await flushPromises();

    expect(store.counts).toBeNull();
  });

  it('drops the accounts, and the badge does not keep the old number while the new list is in flight', async () => {
    connectionsFor['ws-1'] = [brokenConnection('c1'), brokenConnection('c2')];
    connectionsFor['ws-2'] = 'hang';
    const wrapper = await mountLayout();
    expect(badgeText(wrapper)).toContain('2');

    authMock.currentWorkspaceId = 'ws-2';
    await flushPromises();

    expect(usePublishingConnectionsStore().connections).toEqual([]);
    // Hidden at zero, so the badge is GONE rather than reading 0 — and above all it is not
    // still reading 2.
    expect(badgeText(wrapper)).not.toContain('2');
  });

  it('RE-MOUNTS the routed screen, so it asks for ITS workspace’s data', async () => {
    connectionsFor['ws-1'] = [];
    connectionsFor['ws-2'] = [];
    await mountLayout();
    expect(routerViewMounts).toBe(1);

    authMock.currentWorkspaceId = 'ws-2';
    await flushPromises();

    // A reset WITHOUT a remount leaves the list screen showing "Nothing here yet" for a
    // workspace nobody has asked about yet.
    expect(routerViewMounts).toBe(2);
  });

  it('closes the composer — the row it was editing belongs to the workspace we left', async () => {
    connectionsFor['ws-1'] = [];
    connectionsFor['ws-2'] = [];
    routeMock.query = { edit: 'p1', status: 'draft' };
    await mountLayout();

    authMock.currentWorkspaceId = 'ws-2';
    await flushPromises();

    // The composer's own key goes; everything else in the URL is left alone.
    expect(replaceMock).toHaveBeenCalledWith({ query: { status: 'draft' } });
  });

  it('does nothing at all when the id is re-announced unchanged', async () => {
    connectionsFor['ws-1'] = [];
    const wrapper = await mountLayout();
    const before = apiGet.mock.calls.length;

    authMock.currentWorkspaceId = 'ws-1';
    await flushPromises();

    expect(apiGet.mock.calls.length).toBe(before);
    expect(routerViewMounts).toBe(1);
    expect(usePublishingStore().timezone).toBe('Europe/Warsaw');
    expect(wrapper.exists()).toBe(true);
  });
});
