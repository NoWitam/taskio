// @vitest-environment happy-dom
// Unit tests for the "next" forms store — filter serialization, cursor
// reset/append, first-page-only `total`, and the in-place list reconciliation
// that keeps the grid in sync after a lifecycle action WITHOUT a full refetch.
// The api client is mocked so no real HTTP happens.
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
import { useFormsStore } from '../forms';
import type { FormDetail, FormSummary } from '../../../pages/forms/types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function summary(overrides: Partial<FormSummary> = {}): FormSummary {
  return {
    id: 'f1',
    name: 'Form',
    icon: null,
    description: null,
    is_anonymous: false,
    enabled_at: null,
    is_enabled: false,
    indexed_at: null,
    is_indexed: false,
    is_indexing: false,
    content_version: 0,
    content_updated_at: null,
    can_be_edited: true,
    can_be_filled: false,
    can_be_enabled: true,
    can_be_disabled: false,
    can_be_indexed: false,
    can_be_unindexed: false,
    can_restore_index: false,
    has_index_backup: false,
    is_draft: true,
    creator: { type: 'user', id: 'u1', name: 'Ada', email: 'ada@example.com', avatar: null },
    submissions_count: 0,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

function detail(overrides: Partial<FormDetail> = {}): FormDetail {
  return { ...summary(overrides), content: [], ...overrides };
}

/** Parse the query string of the last `api.get` call into a plain object. */
function lastGetParams(): Record<string, string> {
  const calls = apiMock.get.mock.calls;
  const url = calls[calls.length - 1]?.[0] as string;
  const qs = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
  return Object.fromEntries(new URLSearchParams(qs));
}

describe('next forms store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('serializes filters: scalars as-is, booleans as 1/0, skips empty/undefined', async () => {
    const store = useFormsStore();
    apiMock.get.mockResolvedValueOnce({ data: [], meta: { next_cursor: null } });

    await store.fetchForms({ search: 'hi', trashed: true, enabled: false, indexed: true });

    expect(lastGetParams()).toEqual({
      search: 'hi',
      trashed: '1',
      enabled: '0',
      indexed: '1',
    });
  });

  it('omits filter params that are undefined or empty', async () => {
    const store = useFormsStore();
    apiMock.get.mockResolvedValueOnce({ data: [], meta: { next_cursor: null } });

    await store.fetchForms({ search: '', enabled: undefined });

    expect(lastGetParams()).toEqual({});
  });

  it('captures items + total on the first page (reset)', async () => {
    const store = useFormsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [summary({ id: 'a' }), summary({ id: 'b' })],
      meta: { next_cursor: 'cur2', total: 5 },
    });

    await store.fetchForms();

    expect(store.items.map((f) => f.id)).toEqual(['a', 'b']);
    expect(store.total).toBe(5);
    expect(store.hasMore).toBe(true);
    expect(store.cursor).toBe('cur2');
  });

  it('appends on loadMore, sends the cursor, and preserves the first-page total', async () => {
    const store = useFormsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [summary({ id: 'a' })],
      meta: { next_cursor: 'cur2', total: 3 },
    });
    await store.fetchForms();

    apiMock.get.mockResolvedValueOnce({
      data: [summary({ id: 'b' })],
      meta: { next_cursor: null },
    });
    await store.loadMore();

    expect(store.items.map((f) => f.id)).toEqual(['a', 'b']);
    expect(lastGetParams().cursor).toBe('cur2');
    expect(store.total).toBe(3); // unchanged — no total on the 2nd page
    expect(store.hasMore).toBe(false);
  });

  it('drops a superseded in-flight page when a newer reset arrives', async () => {
    const store = useFormsStore();
    let resolveFirst!: (v: unknown) => void;
    apiMock.get.mockReturnValueOnce(
      new Promise((res) => {
        resolveFirst = res;
      }),
    );
    const first = store.fetchForms({ search: 'old' });

    // A newer reset supersedes the first before it resolves.
    apiMock.get.mockResolvedValueOnce({ data: [summary({ id: 'new' })], meta: { next_cursor: null } });
    await store.fetchForms({ search: 'new' });

    // Late resolution of the first request must NOT clobber the newer result.
    resolveFirst({ data: [summary({ id: 'stale' })], meta: { next_cursor: null } });
    await first;

    expect(store.items.map((f) => f.id)).toEqual(['new']);
  });

  it('enableForm replaces the row in place', async () => {
    const store = useFormsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [summary({ id: 'f1', is_enabled: false })],
      meta: { next_cursor: null, total: 1 },
    });
    await store.fetchForms();

    apiMock.post.mockResolvedValueOnce({ data: detail({ id: 'f1', is_enabled: true }) });
    await store.enableForm('f1');

    expect(store.items).toHaveLength(1);
    expect(store.items[0].is_enabled).toBe(true);
    expect(store.total).toBe(1);
  });

  it('deleteForm removes the row and decrements the total', async () => {
    const store = useFormsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [summary({ id: 'f1' }), summary({ id: 'f2' })],
      meta: { next_cursor: null, total: 2 },
    });
    await store.fetchForms();

    apiMock.delete.mockResolvedValueOnce({ message: 'ok' });
    await store.deleteForm('f1');

    expect(store.items.map((f) => f.id)).toEqual(['f2']);
    expect(store.total).toBe(1);
  });

  it('restoreForm removes the row from the (trashed) list', async () => {
    const store = useFormsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [summary({ id: 'f1' })],
      meta: { next_cursor: null, total: 1 },
    });
    await store.fetchForms();

    apiMock.post.mockResolvedValueOnce({ data: detail({ id: 'f1' }) });
    await store.restoreForm('f1');

    expect(store.items).toHaveLength(0);
    expect(store.total).toBe(0);
  });

  it('createForm prepends a normal form and bumps the total', async () => {
    const store = useFormsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [summary({ id: 'a' })],
      meta: { next_cursor: null, total: 1 },
    });
    await store.fetchForms();

    apiMock.post.mockResolvedValueOnce({ data: detail({ id: 'new', is_anonymous: false }) });
    await store.createForm({ name: 'X', content: [], is_anonymous: false });

    expect(store.items.map((f) => f.id)).toEqual(['new', 'a']);
    expect(store.total).toBe(2);
  });

  it('createForm does NOT inject an anonymous form into the browse list', async () => {
    const store = useFormsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [summary({ id: 'a' })],
      meta: { next_cursor: null, total: 1 },
    });
    await store.fetchForms();

    apiMock.post.mockResolvedValueOnce({ data: detail({ id: 'anon', is_anonymous: true }) });
    const created = await store.createForm({ name: '', content: [], is_anonymous: true });

    expect(created.id).toBe('anon');
    expect(store.items.map((f) => f.id)).toEqual(['a']); // list unchanged
    expect(store.total).toBe(1); // total unchanged
  });
});
