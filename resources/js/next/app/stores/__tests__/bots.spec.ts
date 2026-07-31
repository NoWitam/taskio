// @vitest-environment happy-dom
// Unit tests for the "next" bots store — filter serialization, cursor reset/
// append (NO total), the stale-token guard, the in-place list reconciliation that
// keeps the grid in sync after create/update/delete/restore WITHOUT a full
// refetch, and the retryable load-more path. The api client is mocked so no real
// HTTP happens. Mirrors the approval-pipelines store spec.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));

import { api } from '../../lib/api';
import { useBotsStore, serializeFilters } from '../bots';
import type { BotDetail, BotListItem } from '../../../pages/bots/types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function listItem(overrides: Partial<BotListItem> = {}): BotListItem {
  return {
    id: 'b1',
    name: 'Bot',
    status: 'inactive',
    description: null,
    icon: null,
    has_text_module: true,
    task_execution_enabled: false,
    visual_enabled: false,
    visual_has_image: false,
    is_owner: true,
    created_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

function detail(overrides: Partial<BotDetail> = {}): BotDetail {
  return {
    id: 'b1',
    name: 'Bot',
    status: 'inactive',
    description: null,
    icon: null,
    persona: 'You are a helpful bot.',
    style: null,
    dictionary: [],
    phrases: [],
    prohibitions: [],
    task_execution: null,
    visual: null,
    audio: null,
    knowledge: { enabled: false, entries: [] },
    creator: { type: 'user', id: 'u1', name: 'Ada', email: 'ada@example.com', avatar: null },
    is_owner: true,
    can_execute_tasks: false,
    can_be_edited: true,
    can_be_deleted: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

/** Parse the query string of the last `api.get` call into a plain object. */
function lastGetParams(): Record<string, string> {
  const calls = apiMock.get.mock.calls;
  const url = calls[calls.length - 1]?.[0] as string;
  const qs = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
  return Object.fromEntries(new URLSearchParams(qs));
}

describe('next bots store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('serializeFilters: includes a non-empty search, skips empty/undefined', () => {
    expect(Object.fromEntries(serializeFilters({ search: 'hi' }))).toEqual({ search: 'hi' });
    expect(Object.fromEntries(serializeFilters({ search: '' }))).toEqual({});
    expect(Object.fromEntries(serializeFilters({}))).toEqual({});
  });

  it('fetchBots sends the search filter and tracks cursor/hasMore (no total)', async () => {
    const store = useBotsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' }), listItem({ id: 'b' })],
      meta: { next_cursor: 'cur2' },
    });

    await store.fetchBots({ search: 'ava' });

    expect(lastGetParams()).toEqual({ search: 'ava' });
    expect(store.items.map((b) => b.id)).toEqual(['a', 'b']);
    expect(store.cursor).toBe('cur2');
    expect(store.hasMore).toBe(true);
    expect((store as unknown as Record<string, unknown>).total).toBeUndefined();
  });

  it('loadMore appends, sends the cursor, and tracks hasMore=false at the end', async () => {
    const store = useBotsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' })],
      meta: { next_cursor: 'cur2' },
    });
    await store.fetchBots();

    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'b' })],
      meta: { next_cursor: null },
    });
    await store.loadMore();

    expect(store.items.map((b) => b.id)).toEqual(['a', 'b']);
    expect(lastGetParams().cursor).toBe('cur2');
    expect(store.hasMore).toBe(false);
  });

  it('an append failure is retryable: keeps the grid + cursor + hasMore, pauses then re-fetches', async () => {
    const store = useBotsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' })],
      meta: { next_cursor: 'cur2' },
    });
    await store.fetchBots();

    apiMock.get.mockRejectedValueOnce({ response: { status: 500, data: { message: 'boom' } } });
    await store.loadMore();

    expect(store.errored).toBe(false);
    expect(store.loadMoreErrored).toBe(true);
    expect(store.hasMore).toBe(true);
    expect(store.cursor).toBe('cur2');
    expect(store.items.map((b) => b.id)).toEqual(['a']);

    apiMock.get.mockResolvedValueOnce({ data: [listItem({ id: 'b' })], meta: { next_cursor: null } });
    await store.retryLoadMore();

    expect(lastGetParams().cursor).toBe('cur2');
    expect(store.loadMoreErrored).toBe(false);
    expect(store.items.map((b) => b.id)).toEqual(['a', 'b']);
    expect(store.hasMore).toBe(false);
  });

  it('drops a superseded in-flight page when a newer reset arrives (token guard)', async () => {
    const store = useBotsStore();
    let resolveFirst!: (v: unknown) => void;
    apiMock.get.mockReturnValueOnce(
      new Promise((res) => {
        resolveFirst = res;
      }),
    );
    const first = store.fetchBots({ search: 'old' });

    apiMock.get.mockResolvedValueOnce({ data: [listItem({ id: 'new' })], meta: { next_cursor: null } });
    await store.fetchBots({ search: 'new' });

    resolveFirst({ data: [listItem({ id: 'stale' })], meta: { next_cursor: null } });
    await first;

    expect(store.items.map((b) => b.id)).toEqual(['new']);
  });

  it('resetAll clears list + detail state', async () => {
    const store = useBotsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' })],
      meta: { next_cursor: 'cur2' },
    });
    await store.fetchBots();

    store.resetAll();

    expect(store.items).toEqual([]);
    expect(store.cursor).toBeNull();
    expect(store.hasMore).toBe(true);
    expect(store.detail).toBeNull();
    expect(store.errored).toBe(false);
  });

  it('createBot prepends + reconciles a list item from the detail response', async () => {
    const store = useBotsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' })],
      meta: { next_cursor: null },
    });
    await store.fetchBots();

    apiMock.post.mockResolvedValueOnce({
      data: detail({
        id: 'new',
        name: 'Fresh',
        task_execution: { enabled: true, tools: ['search'] },
      }),
    });
    const created = await store.createBot({
      name: 'Fresh',
      persona: 'You are fresh.',
      task_execution: { enabled: true, tools: ['search'] },
    });

    expect(created.id).toBe('new');
    expect(store.items.map((b) => b.id)).toEqual(['new', 'a']);
    // The prepended row is projected to the list shape with the derived flags.
    expect(store.items[0].task_execution_enabled).toBe(true);
    expect(store.items[0].has_text_module).toBe(true);
  });

  it('updateBot replaces the row in the list and updates the detail cache', async () => {
    const store = useBotsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'b1', name: 'Old' })],
      meta: { next_cursor: null },
    });
    await store.fetchBots();
    // Seed the detail cache (as a prefetch would).
    apiMock.get.mockResolvedValueOnce({ data: detail({ id: 'b1', name: 'Old' }) });
    await store.fetchBot('b1');

    apiMock.put.mockResolvedValueOnce({ data: detail({ id: 'b1', name: 'Renamed', status: 'active' }) });
    await store.updateBot('b1', { name: 'Renamed', persona: 'p' });

    expect(store.items).toHaveLength(1);
    expect(store.items[0].name).toBe('Renamed');
    expect(store.items[0].status).toBe('active');
    expect(store.detail?.name).toBe('Renamed');
  });

  // A reconciled row is projected from the FULL detail, so every compact flag the list resource emits
  // has to be derived. One left out reads `undefined` on the card the moment a bot is edited — the chip
  // silently disappears, and nothing else fails. Hence a flag-by-flag pin.
  it('toListItem derives the VISUAL flags when projecting a detail onto a list row', async () => {
    const store = useBotsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'b1', visual_enabled: false, visual_has_image: false })],
      meta: { next_cursor: null },
    });
    await store.fetchBots();

    apiMock.put.mockResolvedValueOnce({
      data: detail({
        id: 'b1',
        visual: {
          enabled: true,
          descriptor: null,
          aesthetic: null,
          wardrobe: null,
          prohibitions: [],
          reference_file_id: null,
          candidates: ['c1'],
          canonical_file_id: 'c1',
          prompt: null,
        },
      }),
    });
    await store.updateBot('b1', { name: 'Ada', persona: 'p' });

    expect(store.items[0].visual_enabled).toBe(true);
    expect(store.items[0].visual_has_image).toBe(true);
  });

  it('toListItem reads both visual flags as false for a bot whose module was never configured', async () => {
    const store = useBotsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'b1', visual_enabled: true, visual_has_image: true })],
      meta: { next_cursor: null },
    });
    await store.fetchBots();

    apiMock.put.mockResolvedValueOnce({ data: detail({ id: 'b1', visual: null }) });
    await store.updateBot('b1', { name: 'Ada', persona: 'p' });

    expect(store.items[0].visual_enabled).toBe(false);
    expect(store.items[0].visual_has_image).toBe(false);
  });

  // --- The visual module's three endpoints ---------------------------------
  it('generateVisual posts multipart (mode + the chosen source) and returns the job id to follow', async () => {
    const store = useBotsStore();
    apiMock.post.mockResolvedValueOnce({ data: { id: 'edit-1', status: 'queued' } });

    const id = await store.generateVisual('b1', {
      mode: 'reference',
      reference_file_id: 'disk-9',
      instruction: 'waist-up',
    });

    expect(id).toBe('edit-1');
    const [url, body] = apiMock.post.mock.calls[0];
    expect(url).toBe('/bots/b1/visual/generate');
    expect(body).toBeInstanceOf(FormData);
    const form = body as FormData;
    expect(form.get('mode')).toBe('reference');
    expect(form.get('reference_file_id')).toBe('disk-9');
    expect(form.get('instruction')).toBe('waist-up');
    // The identity itself is NOT sent — the server composes the prompt from the saved module.
    expect(form.get('descriptor')).toBeNull();
  });

  it('approveVisualCandidate reconciles both the detail cache and the list row', async () => {
    const store = useBotsStore();
    apiMock.get.mockResolvedValueOnce({ data: [listItem({ id: 'b1' })], meta: { next_cursor: null } });
    await store.fetchBots();
    apiMock.get.mockResolvedValueOnce({ data: detail({ id: 'b1' }) });
    await store.fetchBot('b1');

    apiMock.post.mockResolvedValueOnce({
      data: detail({
        id: 'b1',
        visual: {
          enabled: true,
          descriptor: null,
          aesthetic: null,
          wardrobe: null,
          prohibitions: [],
          reference_file_id: null,
          candidates: ['c1'],
          canonical_file_id: 'c1',
          prompt: null,
        },
      }),
    });
    await store.approveVisualCandidate('b1', 'c1');

    expect(apiMock.post).toHaveBeenLastCalledWith('/bots/b1/visual/approve', { file_id: 'c1' });
    expect(store.detail?.visual?.canonical_file_id).toBe('c1');
    expect(store.items[0].visual_has_image).toBe(true);
  });

  it('deleteVisualCandidate calls the scoped endpoint and reconciles from the returned bot', async () => {
    const store = useBotsStore();
    apiMock.get.mockResolvedValueOnce({ data: [listItem({ id: 'b1' })], meta: { next_cursor: null } });
    await store.fetchBots();

    apiMock.delete.mockResolvedValueOnce({ data: detail({ id: 'b1' }) });
    await store.deleteVisualCandidate('b1', 'c1');

    expect(apiMock.delete).toHaveBeenCalledWith('/bots/b1/visual/candidates/c1');
    expect(store.items[0].visual_has_image).toBe(false);
  });

  it('deleteBot removes the row from the list', async () => {
    const store = useBotsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'b1' }), listItem({ id: 'b2' })],
      meta: { next_cursor: null },
    });
    await store.fetchBots();

    apiMock.delete.mockResolvedValueOnce({ message: 'ok' });
    await store.deleteBot('b1');

    expect(store.items.map((b) => b.id)).toEqual(['b2']);
  });

  it('restoreBot prepends the bot back into the list', async () => {
    const store = useBotsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'b2' })],
      meta: { next_cursor: null },
    });
    await store.fetchBots();

    apiMock.post.mockResolvedValueOnce({ data: detail({ id: 'b1', name: 'Restored' }) });
    const restored = await store.restoreBot('b1');

    expect(restored.id).toBe('b1');
    expect(store.items.map((b) => b.id)).toEqual(['b1', 'b2']);
    expect(store.detail?.name).toBe('Restored');
  });
});
