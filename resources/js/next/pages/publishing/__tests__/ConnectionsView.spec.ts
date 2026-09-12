// @vitest-environment happy-dom
// ConnectionsView.spec — the arrival from a consent screen, read ONCE and then erased.
//
// `oauthReturn.spec.ts` pins the READING of the callback's answer; nothing pinned what the
// screen does with it, and three separate things there fail silently:
//
//   • THE PARAMETERS MUST LEAVE THE URL. `?connection=failed&platform=…&reason=…` survives in
//     the history entry: a Back, a refresh, a shared link, and the banner reappears about a
//     handshake that ended a quarter of an hour ago — with "Connect again" beside it. Every
//     one of the three keys has to go, and `reason` is the one that reads as an explanation
//     while saying nothing true.
//   • WHAT ELSE IS IN THE QUERY MUST STAY. The strip is by key, never a reset to `{}`: a
//     filter or a drawer key that vanished when somebody came back from Google would look
//     like the application had forgotten what they were doing.
//   • THE ACCOUNTS MUST BE ASKED FOR AGAIN, forced past the store's idempotence guard — the
//     account that was just connected is, by definition, not in the cached list.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';

// --- Router -----------------------------------------------------------------
const routeMock = reactive({ query: {} as Record<string, unknown>, path: '/publishing/connections' });
const replaceMock = vi.fn();
vi.mock('vue-router', () => ({
  useRoute: () => routeMock,
  useRouter: () => ({ replace: replaceMock, push: vi.fn() }),
}));

// --- Stores -----------------------------------------------------------------
const fetchConnections = vi.fn();
const authorize = vi.fn();
const disconnect = vi.fn();
const connectionsMock = reactive({
  connections: [] as unknown[],
  loading: false,
  loaded: true,
  errored: false,
  error: null as string | null,
  authorizing: null as string | null,
  byPlatform: {} as Record<string, unknown[]>,
  needsAttentionCount: 0,
  hasUnreadableCredentials: false,
  fetchConnections,
  authorize,
  disconnect,
});
vi.mock('../../../app/stores/publishingConnections', () => ({
  usePublishingConnectionsStore: () => connectionsMock,
}));

const loadTimezone = vi.fn();
vi.mock('../../../app/stores/publishing', () => ({
  usePublishingStore: () => reactive({ timezone: null, loadTimezone }),
}));

const toast = { success: vi.fn(), danger: vi.fn(), info: vi.fn(), warning: vi.fn() };
vi.mock('../../../app/composables/useToast', () => ({ useToast: () => toast }));
vi.mock('../../../app/composables/useConfirm', () => ({ useConfirm: () => vi.fn() }));

import ConnectionsView from '../ConnectionsView.vue';
import { setLocale } from '../../../app/i18n';

async function mountView(query: Record<string, unknown>) {
  routeMock.query = query;
  const wrapper = mount(ConnectionsView, { global: { stubs: { RouterLink: true } } });
  await flushPromises();
  return wrapper;
}

beforeEach(() => {
  setLocale('en');
  routeMock.query = {};
  replaceMock.mockReset();
  fetchConnections.mockReset().mockResolvedValue(undefined);
  authorize.mockReset();
  disconnect.mockReset();
  loadTimezone.mockReset().mockResolvedValue(undefined);
  toast.success.mockReset();
  toast.danger.mockReset();
  connectionsMock.connections = [];
  connectionsMock.byPlatform = {};
  connectionsMock.loading = false;
  connectionsMock.loaded = true;
  connectionsMock.errored = false;
});

describe('coming back from a FAILED handshake', () => {
  it('strips all three callback keys, keeps the rest, and says what happened', async () => {
    const wrapper = await mountView({
      connection: 'failed',
      platform: 'youtube',
      reason: 'oauth_state_expired',
      foo: '1',
    });

    // The URL keeps everything that was not the callback's.
    expect(replaceMock).toHaveBeenCalledTimes(1);
    expect(replaceMock.mock.calls[0][0]).toEqual({ query: { foo: '1' } });

    // The banner stays on screen (a failure is not a toast) with the server-catalog sentence.
    expect(wrapper.text()).toContain('That link expired');
    expect(toast.success).not.toHaveBeenCalled();

    // And the accounts are re-read, past the idempotence guard.
    expect(fetchConnections).toHaveBeenCalledWith({ force: true });
  });

  it('renders a sentence for a code this build has never heard of, never the raw key', async () => {
    // The callback passes ANY platform error through verbatim, truncated to 64 chars.
    const wrapper = await mountView({ connection: 'failed', platform: 'youtube', reason: 'server_error' });
    expect(wrapper.text()).toContain('The platform refused the connection');
    expect(wrapper.text()).not.toContain('publishing.oauth.failures');
  });
});

describe('coming back from a SUCCESSFUL handshake', () => {
  it('announces it in a toast and forces a refetch, because the new account is not cached', async () => {
    const wrapper = await mountView({ connection: 'connected', platform: 'youtube' });

    expect(toast.success).toHaveBeenCalledWith('Account connected.');
    expect(fetchConnections).toHaveBeenCalledWith({ force: true });
    expect(replaceMock.mock.calls[0][0]).toEqual({ query: {} });
    // Success does NOT leave a banner: the proof is the card that appears after the refetch.
    expect(wrapper.text()).not.toContain('The account could not be connected');
  });
});

describe('an ordinary arrival', () => {
  it('touches neither the URL nor the cache — the guard in the store owns the request', async () => {
    await mountView({});

    expect(replaceMock).not.toHaveBeenCalled();
    // Not forced: the module shell has normally loaded this already.
    expect(fetchConnections).toHaveBeenCalledWith({ force: false });
  });

  it('ignores a `connection` value that is neither outcome', async () => {
    // Something else put it there; inventing a third outcome would be a banner about an event
    // that did not happen.
    await mountView({ connection: 'maybe' });
    expect(replaceMock).not.toHaveBeenCalled();
  });
});
