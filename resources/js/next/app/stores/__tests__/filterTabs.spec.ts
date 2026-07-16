// @vitest-environment happy-dom
// Unit tests for the "next" filterTabs store (saved views). The api singleton is
// mocked so no real HTTP happens. We assert per-context isolation of state
// (CRUD + reorder keyed by context never bleed across contexts), the getters
// (byContext / isLoading / hasError), 422 error mapping (backend i18n keys
// surfaced under `fieldErrors.<field>`), and the optimistic reorder + rollback
// on failure.
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
import { useFilterTabsStore, type FilterTab, type FilterTabError } from '../filterTabs';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function tab(overrides: Partial<FilterTab> = {}): FilterTab {
  return {
    id: 't1',
    name: 'View',
    icon: null,
    filters: {},
    sort_order: 0,
    ...overrides,
  };
}

/** Build an axios-shaped 422 rejection carrying backend i18n keys. */
function validationError(errors: Record<string, string[]>, message: string | null = null) {
  return { response: { status: 422, data: { message, errors } } };
}

describe('next filterTabs store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  describe('fetch + getters (keyed per context)', () => {
    it('fetch loads + sorts a context, and getters report it', async () => {
      const store = useFilterTabsStore();
      apiMock.get.mockResolvedValueOnce({
        data: [tab({ id: 'b', sort_order: 1 }), tab({ id: 'a', sort_order: 0 })],
      });

      await store.fetch('tasks');

      expect(apiMock.get).toHaveBeenCalledWith('/filter-tabs?context=tasks');
      // sorted by sort_order
      expect(store.byContext('tasks').map((t) => t.id)).toEqual(['a', 'b']);
      expect(store.isLoading('tasks')).toBe(false);
      expect(store.hasError('tasks')).toBe(false);
    });

    it('byContext returns an empty array for an unknown context', () => {
      const store = useFilterTabsStore();
      expect(store.byContext('nope')).toEqual([]);
      expect(store.isLoading('nope')).toBe(false);
      expect(store.hasError('nope')).toBe(false);
    });

    it('keeps two contexts fully independent', async () => {
      const store = useFilterTabsStore();
      apiMock.get
        .mockResolvedValueOnce({ data: [tab({ id: 'tasks-1' })] })
        .mockResolvedValueOnce({ data: [tab({ id: 'forms-1' }), tab({ id: 'forms-2', sort_order: 1 })] });

      await store.fetch('tasks');
      await store.fetch('forms');

      expect(store.byContext('tasks').map((t) => t.id)).toEqual(['tasks-1']);
      expect(store.byContext('forms').map((t) => t.id)).toEqual(['forms-1', 'forms-2']);
    });

    it('sets the per-context error flag and throws a FilterTabError on fetch failure', async () => {
      const store = useFilterTabsStore();
      apiMock.get.mockRejectedValueOnce(validationError({}, 'boom'));

      await expect(store.fetch('tasks')).rejects.toMatchObject({ status: 422 });
      expect(store.hasError('tasks')).toBe(true);
      // an unrelated context is untouched
      expect(store.hasError('forms')).toBe(false);
    });
  });

  describe('create / update / remove (per context)', () => {
    it('create appends to the right context only', async () => {
      const store = useFilterTabsStore();
      store.tabsByContext.tasks = [tab({ id: 'tasks-1' })];
      store.tabsByContext.forms = [tab({ id: 'forms-1' })];

      apiMock.post.mockResolvedValueOnce({ data: tab({ id: 'tasks-2', sort_order: 1 }) });
      const created = await store.create('tasks', { name: 'New', filters: { search: 'x' } });

      expect(apiMock.post).toHaveBeenCalledWith('/filter-tabs', {
        context: 'tasks',
        name: 'New',
        filters: { search: 'x' },
      });
      expect(created.id).toBe('tasks-2');
      expect(store.byContext('tasks').map((t) => t.id)).toEqual(['tasks-1', 'tasks-2']);
      // forms bucket untouched
      expect(store.byContext('forms').map((t) => t.id)).toEqual(['forms-1']);
    });

    it('update reconciles a row in place within its context', async () => {
      const store = useFilterTabsStore();
      store.tabsByContext.tasks = [tab({ id: 't1', name: 'Old' }), tab({ id: 't2', sort_order: 1 })];

      apiMock.put.mockResolvedValueOnce({ data: tab({ id: 't1', name: 'New' }) });
      await store.update('tasks', 't1', { name: 'New' });

      expect(apiMock.put).toHaveBeenCalledWith('/filter-tabs/t1', { name: 'New' });
      const list = store.byContext('tasks');
      expect(list.find((t) => t.id === 't1')?.name).toBe('New');
      expect(list).toHaveLength(2);
    });

    it('remove deletes from its context only', async () => {
      const store = useFilterTabsStore();
      store.tabsByContext.tasks = [tab({ id: 't1' }), tab({ id: 't2', sort_order: 1 })];

      apiMock.delete.mockResolvedValueOnce({});
      await store.remove('tasks', 't1');

      expect(apiMock.delete).toHaveBeenCalledWith('/filter-tabs/t1');
      expect(store.byContext('tasks').map((t) => t.id)).toEqual(['t2']);
    });
  });

  describe('422 error mapping → fieldErrors (i18n keys)', () => {
    it('maps the FIRST backend message per field as a raw i18n key', async () => {
      const store = useFilterTabsStore();
      apiMock.post.mockRejectedValueOnce(
        validationError({ name: ['filter_tabs.errors.name_taken'] }),
      );

      const err = (await store
        .create('tasks', { name: 'Dup', filters: {} })
        .catch((e) => e)) as FilterTabError;

      expect(err.status).toBe(422);
      expect(err.fieldErrors).toEqual({ name: 'filter_tabs.errors.name_taken' });
    });

    it('captures a top-level message and null status when no response is present', async () => {
      const store = useFilterTabsStore();
      apiMock.post.mockRejectedValueOnce(new Error('network down'));

      const err = (await store
        .create('tasks', { name: 'X', filters: {} })
        .catch((e) => e)) as FilterTabError;

      expect(err.status).toBeNull();
      expect(err.fieldErrors).toEqual({});
      expect(err.message).toBeNull();
    });
  });

  describe('reorder (optimistic + rollback)', () => {
    it('optimistically reorders, then replaces with the authoritative server order', async () => {
      const store = useFilterTabsStore();
      store.tabsByContext.tasks = [
        tab({ id: 'a', sort_order: 0 }),
        tab({ id: 'b', sort_order: 1 }),
        tab({ id: 'c', sort_order: 2 }),
      ];

      // Server echoes a canonical order (here matching the request).
      apiMock.put.mockResolvedValueOnce({
        data: [
          tab({ id: 'c', sort_order: 0 }),
          tab({ id: 'a', sort_order: 1 }),
          tab({ id: 'b', sort_order: 2 }),
        ],
      });

      await store.reorder('tasks', ['c', 'a', 'b']);

      expect(apiMock.put).toHaveBeenCalledWith('/filter-tabs/reorder', {
        context: 'tasks',
        ids: ['c', 'a', 'b'],
      });
      expect(store.byContext('tasks').map((t) => t.id)).toEqual(['c', 'a', 'b']);
    });

    it('rolls back to the pre-reorder order and throws on failure', async () => {
      const store = useFilterTabsStore();
      const original = [
        tab({ id: 'a', sort_order: 0 }),
        tab({ id: 'b', sort_order: 1 }),
        tab({ id: 'c', sort_order: 2 }),
      ];
      store.tabsByContext.tasks = original;

      apiMock.put.mockRejectedValueOnce(
        validationError({ ids: ['filter_tabs.errors.invalid_reorder_set'] }),
      );

      const err = (await store
        .reorder('tasks', ['c', 'a', 'b'])
        .catch((e) => e)) as FilterTabError;

      expect(err.fieldErrors).toEqual({ ids: 'filter_tabs.errors.invalid_reorder_set' });
      // restored to the original order
      expect(store.byContext('tasks').map((t) => t.id)).toEqual(['a', 'b', 'c']);
    });
  });
});
