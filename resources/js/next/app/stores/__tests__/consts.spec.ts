// @vitest-environment happy-dom
// Unit tests for the "next" consts store (formerly "workflow-globals") — filter
// serialization, cursor reset/append (NO total), the name-sorted in-place reconciliation
// after create/update/delete, and the ALL-catalogs invalidation that lets pickers pick up a
// new/edited/removed `globals.<key>` variable (the runtime wire root stays `globals`). The
// api client is mocked (no real HTTP).
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

import { api } from '../../lib/api';
import { useConstsStore, serializeFilters } from '../consts';
import { useWorkflowsStore } from '../workflows';
import type { Constant } from '../../../pages/workflows/types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function constant(overrides: Partial<Constant> = {}): Constant {
  return {
    id: 'c1',
    name: 'Brand',
    key: 'brand',
    reference: 'globals.brand',
    descriptor: { base: 'text', nullable: false, array: false },
    value: 'Taskio',
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
    expect(serializeFilters({ search: 'brand' }).toString()).toBe('search=brand');
  });
});

describe('fetchConstants', () => {
  it('resets the list + reads cursor from meta (NO total)', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [constant()], meta: { next_cursor: 'c2' } });
    const store = useConstsStore();

    await store.fetchConstants({ search: 'br' }, { reset: true });

    expect(apiMock.get).toHaveBeenCalledWith('/consts?search=br');
    expect(store.items).toHaveLength(1);
    expect(store.cursor).toBe('c2');
    expect(store.hasMore).toBe(true);
  });

  it('appends the next page', async () => {
    apiMock.get.mockResolvedValueOnce({ data: [constant({ id: 'c1', name: 'A' })], meta: { next_cursor: 'c2' } });
    const store = useConstsStore();
    await store.fetchConstants({}, { reset: true });

    apiMock.get.mockResolvedValueOnce({ data: [constant({ id: 'c2', name: 'B' })], meta: { next_cursor: null } });
    await store.loadMore({});

    expect(store.items.map((c) => c.id)).toEqual(['c1', 'c2']);
    expect(store.hasMore).toBe(false);
  });
});

describe('mutations reconcile the list (name-sorted) + refresh the catalog', () => {
  /** Seed a cached catalog so we can prove a mutation invalidates it. */
  function seedCatalog(): ReturnType<typeof useWorkflowsStore> {
    const workflows = useWorkflowsStore();
    workflows.catalogByKey = { 'schedule:': { variables: [], fields: [] } };
    return workflows;
  }

  it('createConstant inserts in NAME order + clears all catalogs', async () => {
    const store = useConstsStore();
    apiMock.get.mockResolvedValueOnce({ data: [constant({ id: 'c1', name: 'Beta' })], meta: { next_cursor: null } });
    await store.fetchConstants({}, { reset: true });

    const workflows = seedCatalog();
    apiMock.post.mockResolvedValueOnce({ data: constant({ id: 'c2', name: 'Alpha', key: 'alpha' }) });

    await store.createConstant({ name: 'Alpha', descriptor: { base: 'text', nullable: false, array: false }, value: 'x' });

    expect(apiMock.post).toHaveBeenCalledWith('/consts', expect.objectContaining({ name: 'Alpha' }));
    // Inserted and re-sorted by name (Alpha before Beta).
    expect(store.items.map((c) => c.name)).toEqual(['Alpha', 'Beta']);
    // The cached catalog was invalidated.
    expect(workflows.catalogByKey).toEqual({});
  });

  it('updateConstant replaces in place + clears all catalogs', async () => {
    const store = useConstsStore();
    apiMock.get.mockResolvedValueOnce({ data: [constant({ id: 'c1', name: 'Brand' })], meta: { next_cursor: null } });
    await store.fetchConstants({}, { reset: true });

    const workflows = seedCatalog();
    apiMock.put.mockResolvedValueOnce({ data: constant({ id: 'c1', name: 'Brand v2' }) });

    await store.updateConstant('c1', { name: 'Brand v2', descriptor: { base: 'text', nullable: false, array: false }, value: 'x' });

    expect(apiMock.put).toHaveBeenCalledWith('/consts/c1', expect.objectContaining({ name: 'Brand v2' }));
    expect(store.items[0].name).toBe('Brand v2');
    expect(workflows.catalogByKey).toEqual({});
  });

  it('deleteConstant drops the row + clears all catalogs', async () => {
    const store = useConstsStore();
    apiMock.get.mockResolvedValueOnce({ data: [constant({ id: 'c1' }), constant({ id: 'c2', name: 'Other' })], meta: { next_cursor: null } });
    await store.fetchConstants({}, { reset: true });

    const workflows = seedCatalog();
    apiMock.delete.mockResolvedValueOnce({ message: 'ok' });

    await store.deleteConstant('c1');

    expect(apiMock.delete).toHaveBeenCalledWith('/consts/c1');
    expect(store.items.map((c) => c.id)).toEqual(['c2']);
    expect(workflows.catalogByKey).toEqual({});
  });
});
