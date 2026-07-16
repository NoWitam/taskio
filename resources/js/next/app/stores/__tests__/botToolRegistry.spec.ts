// @vitest-environment happy-dom
// Unit tests for the "next" bot tool-registry store (Batch 5): the single-fetch
// session cache, the availability filter (availableIds / isAvailable), the
// no-refetch-when-cached behavior, and the error + retry path. The api client is
// mocked so no real HTTP happens.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({ api: { get: vi.fn() } }));

import { api } from '../../lib/api';
import { useBotToolRegistryStore } from '../botToolRegistry';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

const REGISTRY = [
  { id: 'fetch_url', available: true },
  { id: 'web_search', available: false },
  { id: 'generate_file', available: true },
  { id: 'read_attachments', available: true },
];

describe('next bot tool-registry store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('fetches the registry and marks it loaded', async () => {
    const store = useBotToolRegistryStore();
    apiMock.get.mockResolvedValueOnce({ data: REGISTRY });

    await store.fetchRegistry();

    expect(apiMock.get).toHaveBeenCalledWith('/bots/tool-registry');
    expect(store.loaded).toBe(true);
    expect(store.entries).toHaveLength(4);
  });

  it('availableIds returns only available:true tools (zero dead options)', async () => {
    const store = useBotToolRegistryStore();
    apiMock.get.mockResolvedValueOnce({ data: REGISTRY });
    await store.fetchRegistry();

    // web_search is unavailable (no API key) → excluded.
    expect(store.availableIds()).toEqual(['fetch_url', 'generate_file', 'read_attachments']);
    expect(store.isAvailable('fetch_url')).toBe(true);
    expect(store.isAvailable('web_search')).toBe(false);
    expect(store.isAvailable('unknown_tool')).toBe(false);
  });

  it('does NOT refetch when already cached', async () => {
    const store = useBotToolRegistryStore();
    apiMock.get.mockResolvedValue({ data: REGISTRY });

    await store.fetchRegistry();
    await store.fetchRegistry();
    await store.fetchRegistry();

    expect(apiMock.get).toHaveBeenCalledTimes(1);
  });

  it('captures an error and does not mark loaded', async () => {
    const store = useBotToolRegistryStore();
    apiMock.get.mockRejectedValueOnce({ response: { data: { message: 'boom' } } });

    await store.fetchRegistry();

    expect(store.error).toBe('boom');
    expect(store.loaded).toBe(false);
    expect(store.entries).toEqual([]);
  });

  it('retry clears the error and forces a refetch that can succeed', async () => {
    const store = useBotToolRegistryStore();
    apiMock.get.mockRejectedValueOnce({ response: { data: { message: 'boom' } } });
    await store.fetchRegistry();
    expect(store.error).toBe('boom');

    apiMock.get.mockResolvedValueOnce({ data: REGISTRY });
    await store.retry();

    expect(store.error).toBeNull();
    expect(store.loaded).toBe(true);
    expect(store.availableIds()).toContain('fetch_url');
    expect(apiMock.get).toHaveBeenCalledTimes(2);
  });

  it('reset drops the cache so the next fetch runs again', async () => {
    const store = useBotToolRegistryStore();
    apiMock.get.mockResolvedValue({ data: REGISTRY });
    await store.fetchRegistry();
    store.reset();
    expect(store.loaded).toBe(false);
    await store.fetchRegistry();
    expect(apiMock.get).toHaveBeenCalledTimes(2);
  });
});
