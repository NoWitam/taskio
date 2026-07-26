// @vitest-environment happy-dom
// Unit tests for the "next" custom-functions store — filter serialization, cursor reset/append
// (NO total), the name-sorted in-place reconciliation after create/update/delete, and the
// ALL-catalogs invalidation that lets every pipeline add-menu pick up a new/edited/removed
// `fn:<uuid>` operation. The api client is mocked (no real HTTP). Mirrors the consts store spec.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import { api } from '../../lib/api';
import { useFunctionsStore, serializeFilters } from '../functions';
import { useWorkflowsStore } from '../workflows';
import type { CustomFunction } from '../../../pages/workflows/types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function fn(overrides: Partial<CustomFunction> = {}): CustomFunction {
  return {
    id: 'f1',
    name: 'Double',
    description: null,
    input_type: 'number',
    args: [],
    return_type: 'number',
    body: [{ op: 'num_multiply', args: { value: 2 } }],
    creator: { type: 'user', id: 'u1', name: 'Ada', email: 'ada@example.com', avatar: null },
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

beforeEach(() => {
  setActivePinia(createPinia());
  vi.clearAllMocks();
});

describe('serializeFilters', () => {
  it('serializes only a non-empty search', () => {
    expect(serializeFilters({}).toString()).toBe('');
    expect(serializeFilters({ search: 'fmt' }).toString()).toBe('search=fmt');
  });
});

describe('fetchFunctions', () => {
  it('resets the list + reads cursor from meta (NO total)', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [fn()], meta: { next_cursor: 'c2' } });
    const store = useFunctionsStore();

    await store.fetchFunctions({ search: 'do' }, { reset: true });

    expect(apiMock.get).toHaveBeenCalledWith('/functions?search=do');
    expect(store.items).toHaveLength(1);
    expect(store.cursor).toBe('c2');
    expect(store.hasMore).toBe(true);
  });

  it('appends the next page', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [fn({ id: 'f1', name: 'A' })], meta: { next_cursor: 'c2' } });
    const store = useFunctionsStore();
    await store.fetchFunctions({}, { reset: true });

    apiMock.get.mockResolvedValueOnce({ data: [fn({ id: 'f2', name: 'B' })], meta: { next_cursor: null } });
    await store.loadMore({});

    expect(store.items.map((f) => f.id)).toEqual(['f1', 'f2']);
    expect(store.hasMore).toBe(false);
  });

  it('records a first-page error', async () => {
    apiMock.get.mockRejectedValueOnce({ response: { data: { message: 'boom' } } });
    const store = useFunctionsStore();

    await store.fetchFunctions({}, { reset: true });

    expect(store.errored).toBe(true);
    expect(store.items).toHaveLength(0);
    expect(store.error).toBe('boom');
  });
});

describe('mutations reconcile the list (name-sorted) + refresh every catalog', () => {
  /** Seed a cached catalog so we can prove a mutation invalidates it. */
  function seedCatalog(): ReturnType<typeof useWorkflowsStore> {
    const workflows = useWorkflowsStore();
    workflows.catalogByKey = { 'schedule:': { variables: [], fields: [] } };
    return workflows;
  }

  it('createFunction inserts in NAME order + clears all catalogs', async () => {
    const store = useFunctionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [fn({ id: 'f1', name: 'Beta' })], meta: { next_cursor: null } });
    await store.fetchFunctions({}, { reset: true });

    const workflows = seedCatalog();
    apiMock.post.mockResolvedValueOnce({ data: fn({ id: 'f2', name: 'Alpha' }) });

    await store.createFunction({
      name: 'Alpha',
      input_type: 'number',
      return_type: 'number',
      args: [],
      body: [],
    });

    expect(apiMock.post).toHaveBeenCalledWith('/functions', expect.objectContaining({ name: 'Alpha' }));
    // Inserted and re-sorted by name (Alpha before Beta).
    expect(store.items.map((f) => f.name)).toEqual(['Alpha', 'Beta']);
    // The cached catalog was invalidated.
    expect(workflows.catalogByKey).toEqual({});
  });

  it('updateFunction replaces in place + clears all catalogs', async () => {
    const store = useFunctionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [fn({ id: 'f1', name: 'Double' })], meta: { next_cursor: null } });
    await store.fetchFunctions({}, { reset: true });

    const workflows = seedCatalog();
    apiMock.put.mockResolvedValueOnce({ data: fn({ id: 'f1', name: 'Triple' }) });

    await store.updateFunction('f1', {
      name: 'Triple',
      input_type: 'number',
      return_type: 'number',
      args: [],
      body: [],
    });

    expect(apiMock.put).toHaveBeenCalledWith('/functions/f1', expect.objectContaining({ name: 'Triple' }));
    expect(store.items[0].name).toBe('Triple');
    expect(workflows.catalogByKey).toEqual({});
  });

  it('deleteFunction drops the row + clears all catalogs', async () => {
    const store = useFunctionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [fn({ id: 'f1' }), fn({ id: 'f2', name: 'Other' })], meta: { next_cursor: null } });
    await store.fetchFunctions({}, { reset: true });

    const workflows = seedCatalog();
    apiMock.delete.mockResolvedValueOnce({ message: 'ok' });

    await store.deleteFunction('f1');

    expect(apiMock.delete).toHaveBeenCalledWith('/functions/f1');
    expect(store.items.map((f) => f.id)).toEqual(['f2']);
    expect(workflows.catalogByKey).toEqual({});
  });

  it('deleteFunction propagates a 422 in-use guard to the caller', async () => {
    const store = useFunctionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [fn({ id: 'f1' })], meta: { next_cursor: null } });
    await store.fetchFunctions({}, { reset: true });

    apiMock.delete.mockRejectedValueOnce({ response: { status: 422, data: { errors: { function: ['in use'] } } } });

    await expect(store.deleteFunction('f1')).rejects.toMatchObject({ response: { status: 422 } });
    // The row is KEPT (the delete failed).
    expect(store.items.map((f) => f.id)).toEqual(['f1']);
  });
});
