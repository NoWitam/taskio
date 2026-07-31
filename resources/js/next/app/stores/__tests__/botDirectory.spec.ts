// botDirectory.spec — the id → {name,status} resolver behind bot references embedded in content
// (the `@[ai-text]` per-block AUTHOR).
//
// The two verdicts must NOT be conflated: a 404/403 means the author is GONE (the UI may say so and
// offer to clear it); anything else means we simply could not check (the UI must stay neutral and
// offer a retry). The store also has to resolve each id AT MOST ONCE — a document renders one chip
// per block, and a per-instance fan-out would hammer `/bots/{id}`.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

// Partial mock: the store reads the active workspace from the auth store, which imports more than
// `api` from this module (TOKEN_KEY / WORKSPACE_KEY) — keep the real exports, stub only HTTP.
vi.mock('../../lib/api', async (importOriginal) => {
  const actual = await importOriginal<Record<string, unknown>>();
  return {
    ...actual,
    api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  };
});

import { api } from '../../lib/api';
import { useBotDirectoryStore } from '../botDirectory';
import { useAuthStore } from '../auth';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

/** Two real uuids — the store only talks to the server about uuid-shaped ids. */
const B1 = '3f2a1c64-9f1e-4a7b-8c3d-2b6e5f0a1d94';
const B2 = 'c1e77a52-4d3b-4f10-9a86-71b0c9d2e345';

/** An axios-shaped rejection carrying an HTTP status. */
function httpError(status: number): unknown {
  return { response: { status } };
}

describe('botDirectory store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('resolves an id to its name + status', async () => {
    apiMock.get.mockResolvedValue({ data: { id: B1, name: 'Marketing Maven', status: 'active' } });
    const store = useBotDirectoryStore();

    await store.resolve(B1);

    expect(apiMock.get).toHaveBeenCalledWith(`/bots/${B1}`);
    expect(store.entry(B1)).toMatchObject({
      state: 'resolved',
      name: 'Marketing Maven',
      status: 'active',
    });
  });

  it('treats 404 and 403 as MISSING — a definitive "this author is gone"', async () => {
    apiMock.get.mockRejectedValueOnce(httpError(404)).mockRejectedValueOnce(httpError(403));
    const store = useBotDirectoryStore();

    await store.resolve(B2);
    await store.resolve('11111111-2222-3333-4444-555555555555');

    expect(store.entry(B2)?.state).toBe('missing');
    expect(store.entry('11111111-2222-3333-4444-555555555555')?.state).toBe('missing');
  });

  it('treats a network failure / 5xx as UNRESOLVED — never as "deleted"', async () => {
    apiMock.get.mockRejectedValueOnce(new Error('Network Error'));
    const store = useBotDirectoryStore();

    await store.resolve(B1);

    expect(store.entry(B1)?.state).toBe('unresolved');
    expect(store.entry(B1)?.state).not.toBe('missing');

    apiMock.get.mockRejectedValueOnce(httpError(500));
    await store.retry(B1);
    expect(store.entry(B1)?.state).toBe('unresolved');
  });

  it('resolves each id at most once and coalesces concurrent callers', async () => {
    apiMock.get.mockResolvedValue({ data: { id: B1, name: 'Bot', status: 'active' } });
    const store = useBotDirectoryStore();

    // Three chips for the SAME author, all rendering at once.
    await Promise.all([store.resolve(B1), store.resolve(B1), store.resolve(B1)]);
    // …and a later re-render.
    await store.resolve(B1);

    expect(apiMock.get).toHaveBeenCalledTimes(1);
  });

  it('does NOT auto-retry an inconclusive verdict, but `retry()` does', async () => {
    apiMock.get.mockRejectedValueOnce(new Error('offline'));
    const store = useBotDirectoryStore();
    await store.resolve(B1);
    expect(apiMock.get).toHaveBeenCalledTimes(1);

    apiMock.get.mockResolvedValueOnce({ data: { id: B1, name: 'Back', status: 'inactive' } });
    await store.retry(B1);

    expect(apiMock.get).toHaveBeenCalledTimes(2);
    expect(store.entry(B1)).toMatchObject({ state: 'resolved', name: 'Back', status: 'inactive' });
  });

  it('primes from data we already have, without a request', async () => {
    const store = useBotDirectoryStore();
    store.prime({ id: 'b7', name: 'Support Sam', status: 'inactive' });

    expect(store.entry('b7')).toMatchObject({ state: 'resolved', name: 'Support Sam' });
    await store.resolve('b7');
    expect(apiMock.get).not.toHaveBeenCalled();
  });

  it('returns null for an id nobody asked about', () => {
    const store = useBotDirectoryStore();
    expect(store.entry('nope')).toBeNull();
    expect(store.entry(null)).toBeNull();
  });

  // --- The id comes from DOCUMENT CONTENT, so it is untrusted input ---------
  // An `@[ai-text]` author id is whatever the markdown says. Interpolating it straight into the
  // request path let a crafted document aim an authenticated GET at any same-origin endpoint. The
  // backend already refuses anything that is not uuid-shaped (`Str::isUuid`, e.g.
  // BotAuthorVoiceResolver::uuidsOnly), so mirror that verdict here and never send the request.
  describe('untrusted ids', () => {
    it('never requests an id that is not uuid-shaped', async () => {
      const store = useBotDirectoryStore();

      await store.resolve('../workflows/1');
      await store.resolve('me?x=1');
      await store.resolve('b1');
      await store.resolve('');

      expect(apiMock.get).not.toHaveBeenCalled();
    });

    it('calls a malformed id MISSING — the same verdict the backend gives it', async () => {
      const store = useBotDirectoryStore();

      await store.resolve('../workflows/1');

      // Definitive, not "we could not check": no uuid can ever be this, so it is gone for good.
      expect(store.entry('../workflows/1')?.state).toBe('missing');
      expect(store.entry('../workflows/1')?.name).toBeNull();
    });

    it('encodes the id it does send', async () => {
      apiMock.get.mockResolvedValue({ data: { id: B1, name: 'Bot', status: 'active' } });
      const store = useBotDirectoryStore();

      await store.resolve(B1.toUpperCase());

      // Uppercase is a legal uuid spelling (the column matches case-insensitively) and needs no
      // escaping — but the path must be built through encodeURIComponent all the same.
      expect(apiMock.get).toHaveBeenCalledWith(`/bots/${encodeURIComponent(B1.toUpperCase())}`);
    });
  });

  // --- The cache is per-WORKSPACE ------------------------------------------
  // Bot ids are workspace-scoped and content can travel between workspaces (a copied template or
  // markdown). A module-level cache that never resets would render workspace A's bot name for the
  // same author id met in workspace B.
  describe('workspace scope', () => {
    it('does not serve a cached name after a workspace switch', async () => {
      const auth = useAuthStore();
      auth.currentWorkspaceId = 'ws-a';
      apiMock.get.mockResolvedValue({ data: { id: B1, name: 'Alpha Bot', status: 'active' } });
      const store = useBotDirectoryStore();

      await store.resolve(B1);
      expect(store.entry(B1)?.name).toBe('Alpha Bot');

      auth.currentWorkspaceId = 'ws-b';

      // The old verdict is not ours to show any more…
      expect(store.entry(B1)).toBeNull();
      // …and the id has to be asked about again, in the NEW scope.
      apiMock.get.mockResolvedValue({ data: { id: B1, name: 'Beta Bot', status: 'inactive' } });
      await store.resolve(B1);

      expect(apiMock.get).toHaveBeenCalledTimes(2);
      expect(store.entry(B1)?.name).toBe('Beta Bot');
    });

    it('drops the cache on logout (no current workspace)', async () => {
      const auth = useAuthStore();
      auth.currentWorkspaceId = 'ws-a';
      apiMock.get.mockResolvedValue({ data: { id: B1, name: 'Alpha Bot', status: 'active' } });
      const store = useBotDirectoryStore();
      await store.resolve(B1);

      auth.currentWorkspaceId = null;

      expect(store.entry(B1)).toBeNull();
    });

    it('keeps serving the cache while the workspace is unchanged', async () => {
      const auth = useAuthStore();
      auth.currentWorkspaceId = 'ws-a';
      apiMock.get.mockResolvedValue({ data: { id: B1, name: 'Alpha Bot', status: 'active' } });
      const store = useBotDirectoryStore();

      await store.resolve(B1);
      await store.resolve(B1);

      expect(apiMock.get).toHaveBeenCalledTimes(1);
      expect(store.entry(B1)?.name).toBe('Alpha Bot');
    });
  });
});
