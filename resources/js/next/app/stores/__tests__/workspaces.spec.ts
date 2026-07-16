// @vitest-environment happy-dom
// Unit tests for the "next" workspaces store — the create + provisioning-poll
// flow. createWorkspace must POST the right body, read `res.data`, and mirror the
// new workspace into the auth context (so the switcher lists it). pollUntilReady
// must resolve on `ready`, on `failed`, time out, and stop when cancelled. The api
// client is mocked so no real HTTP happens; fake timers drive the poll interval.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
  TOKEN_KEY: 'taskio_token',
  WORKSPACE_KEY: 'taskio_workspace',
}));

import { api } from '../../lib/api';
import { useWorkspacesStore } from '../workspaces';
import { useAuthStore, type Workspace } from '../auth';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function workspace(overrides: Partial<Workspace> = {}): Workspace {
  return {
    id: 'w1',
    name: 'Acme',
    db_mode: 'shared',
    status: 'ready',
    is_owner: true,
    can_manage_members: true,
    member_count: 1,
    created_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

describe('next workspaces store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  describe('createWorkspace', () => {
    it('posts { name, db_mode }, reads res.data, and pushes into auth.workspaces', async () => {
      const store = useWorkspacesStore();
      const auth = useAuthStore();
      const created = workspace({ id: 'new', name: 'Fresh', db_mode: 'shared', status: 'ready' });
      apiMock.post.mockResolvedValueOnce({ data: created });

      const result = await store.createWorkspace({ name: 'Fresh', db_mode: 'shared' });

      expect(apiMock.post).toHaveBeenCalledWith('/workspaces', {
        name: 'Fresh',
        db_mode: 'shared',
      });
      expect(result.id).toBe('new');
      // Mirrored into the auth context so the switcher lists it immediately.
      expect(auth.workspaces.map((w) => w.id)).toContain('new');
      expect(store.creating).toBe(false);
    });

    it('replaces (does not duplicate) an existing workspace already in auth', async () => {
      const store = useWorkspacesStore();
      const auth = useAuthStore();
      auth.workspaces = [workspace({ id: 'w1', name: 'Old' })];

      apiMock.post.mockResolvedValueOnce({
        data: workspace({ id: 'w1', name: 'Renamed' }),
      });
      await store.createWorkspace({ name: 'Renamed', db_mode: 'shared' });

      expect(auth.workspaces).toHaveLength(1);
      expect(auth.workspaces[0].name).toBe('Renamed');
    });

    it('clears creating + leaves no mirror when the create fails', async () => {
      const store = useWorkspacesStore();
      const auth = useAuthStore();
      apiMock.post.mockRejectedValueOnce({ response: { status: 422 } });

      await expect(
        store.createWorkspace({ name: '', db_mode: 'shared' }),
      ).rejects.toBeTruthy();
      expect(store.creating).toBe(false);
      expect(auth.workspaces).toEqual([]);
    });
  });

  describe('fetchWorkspace', () => {
    it('reads res.data and mirrors the latest status into auth', async () => {
      const store = useWorkspacesStore();
      const auth = useAuthStore();
      auth.workspaces = [workspace({ id: 'w1', status: 'provisioning' })];

      apiMock.get.mockResolvedValueOnce({ data: workspace({ id: 'w1', status: 'ready' }) });
      const ws = await store.fetchWorkspace('w1');

      expect(apiMock.get).toHaveBeenCalledWith('/workspaces/w1');
      expect(ws.status).toBe('ready');
      expect(auth.workspaces[0].status).toBe('ready');
    });
  });

  describe('pollUntilReady', () => {
    it('resolves with the workspace once it becomes ready', async () => {
      vi.useFakeTimers();
      const store = useWorkspacesStore();

      apiMock.get
        .mockResolvedValueOnce({ data: workspace({ id: 'w1', status: 'provisioning' }) })
        .mockResolvedValueOnce({ data: workspace({ id: 'w1', status: 'ready' }) });

      const { promise } = store.pollUntilReady('w1', { intervalMs: 1000, timeoutMs: 60000 });
      // Drain the interval waits between polls.
      await vi.runAllTimersAsync();
      const result = await promise;

      expect(result.cancelled).toBe(false);
      expect(result.timedOut).toBe(false);
      expect(result.workspace?.status).toBe('ready');
    });

    it('resolves on failed (settled, not ready)', async () => {
      vi.useFakeTimers();
      const store = useWorkspacesStore();

      apiMock.get
        .mockResolvedValueOnce({ data: workspace({ id: 'w1', status: 'provisioning' }) })
        .mockResolvedValueOnce({ data: workspace({ id: 'w1', status: 'failed' }) });

      const { promise } = store.pollUntilReady('w1', { intervalMs: 1000 });
      await vi.runAllTimersAsync();
      const result = await promise;

      expect(result.cancelled).toBe(false);
      expect(result.timedOut).toBe(false);
      expect(result.workspace?.status).toBe('failed');
    });

    it('calls onUpdate while still provisioning', async () => {
      vi.useFakeTimers();
      const store = useWorkspacesStore();
      const onUpdate = vi.fn();

      apiMock.get
        .mockResolvedValueOnce({ data: workspace({ id: 'w1', status: 'provisioning' }) })
        .mockResolvedValueOnce({ data: workspace({ id: 'w1', status: 'provisioning' }) })
        .mockResolvedValueOnce({ data: workspace({ id: 'w1', status: 'ready' }) });

      const { promise } = store.pollUntilReady('w1', { intervalMs: 1000, onUpdate });
      await vi.runAllTimersAsync();
      await promise;

      // Two provisioning fetches → two onUpdate calls; the ready fetch does not.
      expect(onUpdate).toHaveBeenCalledTimes(2);
    });

    it('times out while still provisioning (resolves timedOut, never settles)', async () => {
      vi.useFakeTimers();
      const store = useWorkspacesStore();
      apiMock.get.mockResolvedValue({ data: workspace({ id: 'w1', status: 'provisioning' }) });

      const { promise } = store.pollUntilReady('w1', { intervalMs: 1000, timeoutMs: 2500 });
      await vi.runAllTimersAsync();
      const result = await promise;

      expect(result.timedOut).toBe(true);
      expect(result.cancelled).toBe(false);
      expect(result.workspace?.status).toBe('provisioning');
    });

    it('stops polling when cancelled via the returned stop fn', async () => {
      vi.useFakeTimers();
      const store = useWorkspacesStore();
      apiMock.get.mockResolvedValue({ data: workspace({ id: 'w1', status: 'provisioning' }) });

      const { promise, stop } = store.pollUntilReady('w1', { intervalMs: 1000 });
      // Let the first fetch happen, then cancel before the next interval fires.
      await vi.advanceTimersByTimeAsync(0);
      stop();
      await vi.runAllTimersAsync();
      const result = await promise;

      expect(result.cancelled).toBe(true);
      const callsAfterStop = apiMock.get.mock.calls.length;
      // No further polling after cancellation.
      await vi.advanceTimersByTimeAsync(5000);
      expect(apiMock.get.mock.calls.length).toBe(callsAfterStop);
    });

    it('a new poll supersedes the previous one (token guard)', async () => {
      vi.useFakeTimers();
      const store = useWorkspacesStore();
      // First poll never settles (always provisioning); second settles to ready.
      apiMock.get.mockResolvedValue({ data: workspace({ id: 'w1', status: 'provisioning' }) });

      const first = store.pollUntilReady('w1', { intervalMs: 1000 });
      // Let the first poll's initial fetch resolve so it's mid-interval.
      await vi.advanceTimersByTimeAsync(0);

      // A second poll bumps the token; the first loop must bail as cancelled on its
      // next tick (after its pending interval delay elapses).
      apiMock.get.mockResolvedValue({ data: workspace({ id: 'w1', status: 'ready' }) });
      const second = store.pollUntilReady('w1', { intervalMs: 1000, timeoutMs: 5000 });

      // Drain every pending timer so both loops run to completion.
      await vi.runAllTimersAsync();

      const firstResult = await first.promise;
      const secondResult = await second.promise;

      expect(firstResult.cancelled).toBe(true);
      expect(secondResult.cancelled).toBe(false);
      expect(secondResult.workspace?.status).toBe('ready');
    });
  });
});
