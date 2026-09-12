// @vitest-environment happy-dom
// publishingConnections.spec — one request per module visit, and nothing carried across a
// workspace switch.
//
// FOUR SCREENS ASK FOR THIS LIST ON THE WAY IN: the module shell (whose aside badge is the
// only place a broken account is visible while somebody is looking at publications), the
// publications list, the detail, and the composer. They mount inside the same tick, so
// entering the module fired two or three identical `GET`s in a row — invisible in the UI and
// paid for on every visit. The guard is `loaded` plus the in-flight promise, and BOTH halves
// matter: `loaded` alone does nothing while the first request is still on the wire.
//
// The forced path is the other half of the contract: after a disconnect, after a Refresh, and
// after the return from a consent screen, the cached list is KNOWN to be stale — the account
// that was just connected is not in it.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import { api } from '../../lib/api';
import { usePublishingConnectionsStore } from '../publishingConnections';
import type { PlatformConnection } from '../../../pages/publishing/types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function connection(overrides: Partial<PlatformConnection> = {}): PlatformConnection {
  return {
    id: 'c1',
    platform: 'youtube',
    platform_label: 'YouTube',
    external_account_id: 'UCxxxx',
    account_name: 'Taskio Demo',
    status: 'active',
    status_label: 'Connected',
    status_tone: 'success',
    needs_attention: false,
    can_publish: true,
    scopes: [],
    expires_at: null,
    last_refreshed_at: null,
    failure_code: null,
    credentials_readable: true,
    creator: null,
    is_owner: true,
    can_be_disconnected: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe('one request per module visit', () => {
  it('serves every screen that asks while the FIRST request is still in flight', async () => {
    let resolve: ((value: unknown) => void) | null = null;
    apiMock.get.mockImplementationOnce(
      () =>
        new Promise((r) => {
          resolve = r;
        }),
    );

    const store = usePublishingConnectionsStore();
    // The shell, the list and the composer, in the same tick.
    const all = Promise.all([
      store.fetchConnections(),
      store.fetchConnections(),
      store.fetchConnections(),
    ]);
    expect(apiMock.get).toHaveBeenCalledTimes(1);

    resolve!({ data: [connection()] });
    await all;

    expect(apiMock.get).toHaveBeenCalledTimes(1);
    expect(store.connections).toHaveLength(1);
  });

  it('asks for nothing once the list is loaded', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [connection()] });
    const store = usePublishingConnectionsStore();
    await store.fetchConnections();
    await store.fetchConnections();
    expect(apiMock.get).toHaveBeenCalledTimes(1);
  });

  it('DOES ask again after a failure — the guard must not cache "we could not load it"', async () => {
    apiMock.get.mockRejectedValueOnce({ response: { status: 500, data: {} } });
    const store = usePublishingConnectionsStore();
    await expect(store.fetchConnections()).rejects.toBeTruthy();
    expect(store.errored).toBe(true);

    apiMock.get.mockResolvedValueOnce({ data: [connection()] });
    await store.fetchConnections();
    expect(apiMock.get).toHaveBeenCalledTimes(2);
    expect(store.loaded).toBe(true);
  });

  it('goes to the server whenever the caller knows the list changed', async () => {
    apiMock.get.mockResolvedValue({ data: [connection()] });
    const store = usePublishingConnectionsStore();
    await store.fetchConnections();
    await store.fetchConnections({ force: true });
    expect(apiMock.get).toHaveBeenCalledTimes(2);
  });

  it('re-reads after a disconnect, because the account it removed is still in the cache', async () => {
    apiMock.get.mockResolvedValue({ data: [connection()] });
    const store = usePublishingConnectionsStore();
    await store.fetchConnections();

    apiMock.delete.mockResolvedValueOnce(undefined);
    apiMock.get.mockResolvedValueOnce({ data: [] });
    await store.disconnect('c1');

    expect(apiMock.delete).toHaveBeenCalledWith('/publishing/connections/c1');
    expect(apiMock.get).toHaveBeenCalledTimes(2);
    expect(store.connections).toEqual([]);
  });
});

describe('resetAll — a workspace switch', () => {
  it('drops the accounts AND the guard, so the next workspace is actually asked about', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [connection({ needs_attention: true })] });
    const store = usePublishingConnectionsStore();
    await store.fetchConnections();
    expect(store.needsAttentionCount).toBe(1);

    store.resetAll();

    // Accounts are per workspace: the aside badge must not go on counting somebody else's
    // broken tokens, and the Connections screen must not open on another team's accounts.
    expect(store.connections).toEqual([]);
    expect(store.loaded).toBe(false);
    expect(store.needsAttentionCount).toBe(0);

    apiMock.get.mockResolvedValueOnce({ data: [connection({ id: 'c2' })] });
    await store.fetchConnections();
    expect(apiMock.get).toHaveBeenCalledTimes(2);
    expect(store.connections.map((c) => c.id)).toEqual(['c2']);
  });
});
