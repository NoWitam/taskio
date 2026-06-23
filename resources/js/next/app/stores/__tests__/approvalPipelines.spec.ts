// @vitest-environment happy-dom
// Unit tests for the "next" approval-pipelines store — filter serialization,
// cursor reset/append (NO total), the stale-token guard, and the in-place list
// reconciliation that keeps the grid in sync after create/update/delete WITHOUT a
// full refetch, plus the structured 422 "active processes" error surfacing.
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
import {
  useApprovalPipelinesStore,
  serializeFilters,
  activeProcessesError,
} from '../approvalPipelines';
import type {
  ApprovalPipeline,
  ApprovalPipelineListItem,
} from '../../../pages/approvals/types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function listItem(overrides: Partial<ApprovalPipelineListItem> = {}): ApprovalPipelineListItem {
  return {
    id: 'p1',
    name: 'Pipeline',
    icon: null,
    description: null,
    stages_count: 1,
    stages: [{ name: 'Stage 1', icon: null, order: 0 }],
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    created_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

function detail(overrides: Partial<ApprovalPipeline> = {}): ApprovalPipeline {
  return {
    id: 'p1',
    name: 'Pipeline',
    icon: null,
    description: null,
    stages: [
      { id: 's1', name: 'Stage 1', icon: null, description: null, approver_type: 'ai', order: 0 },
    ],
    creator: { id: 'u1', name: 'Ada' },
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    created_at: '2026-01-01T00:00:00Z',
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

describe('next approval-pipelines store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('serializeFilters: includes a non-empty search, skips empty/undefined', () => {
    expect(Object.fromEntries(serializeFilters({ search: 'hi' }))).toEqual({ search: 'hi' });
    expect(Object.fromEntries(serializeFilters({ search: '' }))).toEqual({});
    expect(Object.fromEntries(serializeFilters({}))).toEqual({});
  });

  it('fetchPipelines sends the search filter and tracks cursor/hasMore (no total)', async () => {
    const store = useApprovalPipelinesStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' }), listItem({ id: 'b' })],
      meta: { next_cursor: 'cur2' },
    });

    await store.fetchPipelines({ search: 'inv' });

    expect(lastGetParams()).toEqual({ search: 'inv' });
    expect(store.items.map((p) => p.id)).toEqual(['a', 'b']);
    expect(store.cursor).toBe('cur2');
    expect(store.hasMore).toBe(true);
    expect((store as unknown as Record<string, unknown>).total).toBeUndefined();
  });

  it('loadMore appends, sends the cursor, and tracks hasMore=false at the end', async () => {
    const store = useApprovalPipelinesStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' })],
      meta: { next_cursor: 'cur2' },
    });
    await store.fetchPipelines();

    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'b' })],
      meta: { next_cursor: null },
    });
    await store.loadMore();

    expect(store.items.map((p) => p.id)).toEqual(['a', 'b']);
    expect(lastGetParams().cursor).toBe('cur2');
    expect(store.hasMore).toBe(false);
  });

  it('an append failure is retryable: keeps the grid + cursor + hasMore, pauses then re-fetches', async () => {
    const store = useApprovalPipelinesStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' })],
      meta: { next_cursor: 'cur2' },
    });
    await store.fetchPipelines();

    // The append (load-more) page fails.
    apiMock.get.mockRejectedValueOnce({ response: { status: 500, data: { message: 'boom' } } });
    await store.loadMore();

    // First-page error state is NOT set; the grid + cursor + hasMore survive so the
    // page can be retried — only the dedicated load-more flag is raised.
    expect(store.errored).toBe(false);
    expect(store.loadMoreErrored).toBe(true);
    expect(store.hasMore).toBe(true);
    expect(store.cursor).toBe('cur2');
    expect(store.items.map((p) => p.id)).toEqual(['a']);

    // A bare loadMore() would early-return while the flag is set — retryLoadMore
    // clears it and re-fetches the SAME cursor page.
    apiMock.get.mockResolvedValueOnce({ data: [listItem({ id: 'b' })], meta: { next_cursor: null } });
    await store.retryLoadMore();

    expect(lastGetParams().cursor).toBe('cur2');
    expect(store.loadMoreErrored).toBe(false);
    expect(store.items.map((p) => p.id)).toEqual(['a', 'b']);
    expect(store.hasMore).toBe(false);
  });

  it('drops a superseded in-flight page when a newer reset arrives (token guard)', async () => {
    const store = useApprovalPipelinesStore();
    let resolveFirst!: (v: unknown) => void;
    apiMock.get.mockReturnValueOnce(
      new Promise((res) => {
        resolveFirst = res;
      }),
    );
    const first = store.fetchPipelines({ search: 'old' });

    apiMock.get.mockResolvedValueOnce({ data: [listItem({ id: 'new' })], meta: { next_cursor: null } });
    await store.fetchPipelines({ search: 'new' });

    // Late resolution of the first request must NOT clobber the newer result.
    resolveFirst({ data: [listItem({ id: 'stale' })], meta: { next_cursor: null } });
    await first;

    expect(store.items.map((p) => p.id)).toEqual(['new']);
  });

  it('resetAll clears list + detail state', async () => {
    const store = useApprovalPipelinesStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' })],
      meta: { next_cursor: 'cur2' },
    });
    await store.fetchPipelines();

    store.resetAll();

    expect(store.items).toEqual([]);
    expect(store.cursor).toBeNull();
    expect(store.hasMore).toBe(true);
    expect(store.detail).toBeNull();
    expect(store.errored).toBe(false);
  });

  it('createPipeline prepends + reconciles a list item from the detail response', async () => {
    const store = useApprovalPipelinesStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' })],
      meta: { next_cursor: null },
    });
    await store.fetchPipelines();

    apiMock.post.mockResolvedValueOnce({
      data: detail({ id: 'new', name: 'Fresh', stages: [
        { id: 's1', name: 'S1', icon: null, description: null, approver_type: 'ai', order: 0 },
        { id: 's2', name: 'S2', icon: null, description: null, approver_type: 'ai', order: 1 },
      ] }),
    });
    const created = await store.createPipeline({
      name: 'Fresh',
      stages: [
        { name: 'S1', approver_type: 'ai', approver_id: null },
        { name: 'S2', approver_type: 'ai', approver_id: null },
      ],
    });

    expect(created.id).toBe('new');
    expect(store.items.map((p) => p.id)).toEqual(['new', 'a']);
    // The prepended row is projected to the list shape with a derived count.
    expect(store.items[0].stages_count).toBe(2);
  });

  it('updatePipeline replaces the row in the list and updates the detail cache', async () => {
    const store = useApprovalPipelinesStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'p1', name: 'Old' })],
      meta: { next_cursor: null },
    });
    await store.fetchPipelines();
    // Seed the detail cache (as a prefetch would).
    apiMock.get.mockResolvedValueOnce({ data: detail({ id: 'p1', name: 'Old' }) });
    await store.fetchPipeline('p1');

    apiMock.put.mockResolvedValueOnce({ data: detail({ id: 'p1', name: 'Renamed' }) });
    await store.updatePipeline('p1', {
      name: 'Renamed',
      stages: [{ name: 'S1', approver_type: 'ai', approver_id: null }],
    });

    expect(store.items).toHaveLength(1);
    expect(store.items[0].name).toBe('Renamed');
    expect(store.detail?.name).toBe('Renamed');
  });

  it('deletePipeline removes the row from the list', async () => {
    const store = useApprovalPipelinesStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'p1' }), listItem({ id: 'p2' })],
      meta: { next_cursor: null },
    });
    await store.fetchPipelines();

    apiMock.delete.mockResolvedValueOnce({ message: 'ok' });
    await store.deletePipeline('p1');

    expect(store.items.map((p) => p.id)).toEqual(['p2']);
  });

  it('surfaces the 422 active-processes error WITHOUT mutating the list', async () => {
    const store = useApprovalPipelinesStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'p1' })],
      meta: { next_cursor: null },
    });
    await store.fetchPipelines();

    // Real backend body: ValidationException::withMessages(['pipeline' => [__(...)]])
    // resolves the message at the PHP layer, so the bag carries the TRANSLATED string
    // under the `pipeline` field — never the raw i18n key.
    const err = {
      response: {
        status: 422,
        data: {
          message: 'Cannot edit/delete pipeline with active approval processes.',
          errors: { pipeline: ['Cannot edit/delete pipeline with active approval processes.'] },
        },
      },
    };
    apiMock.delete.mockRejectedValueOnce(err);

    await expect(store.deletePipeline('p1')).rejects.toBe(err);
    // The list is untouched — the row is still there.
    expect(store.items.map((p) => p.id)).toEqual(['p1']);
    // …and the error is recognized as the structured active-processes case.
    const structured = activeProcessesError(err);
    expect(structured).toEqual({
      kind: 'active_processes',
      messageKey: 'approvals.validation.pipeline_has_active_processes',
    });
  });

  it('activeProcessesError ignores non-422 / unrelated errors', () => {
    expect(activeProcessesError({ response: { status: 500 } })).toBeNull();
    expect(
      activeProcessesError({ response: { status: 422, data: { message: 'validation.failed' } } }),
    ).toBeNull();
    // An ordinary field-validation 422 (e.g. name required) must NOT be mistaken for
    // the active-processes case — only the `pipeline` field triggers it.
    expect(
      activeProcessesError({ response: { status: 422, data: { errors: { name: ['The name field is required.'] } } } }),
    ).toBeNull();
    expect(activeProcessesError(new Error('network'))).toBeNull();
  });
});
