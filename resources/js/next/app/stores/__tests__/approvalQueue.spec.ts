// @vitest-environment happy-dom
// Unit tests for the "next" approval-queue store — first-page `total` capture
// (and its preservation across cursor pages), cursor append + stale-token guard,
// the optimistic decision flow (remove + decrement total/count), the client-side
// reject-note guard, the 422 "already decided" resync, the detail/run-history
// unwrapping, and the run-history grouping helper (incl. a null stage).
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
import { useApprovalQueueStore, groupRunHistory } from '../approvalQueue';
import type {
  ApprovalProcess,
  ApprovalQueueItem,
} from '../../../pages/approvals/queue-types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function queueItem(processId: string, overrides: Partial<ApprovalQueueItem> = {}): ApprovalQueueItem {
  return {
    process: {
      id: processId,
      run_id: `run-${processId}`,
      status: 'pending',
      approver_type: 'user',
      created_at: '2026-01-01T00:00:00Z',
    },
    pipeline: { id: 'pl1', name: 'Pipeline', icon: null },
    stage: { id: 's1', name: 'Stage 1', icon: null, description: null, order: 0 },
    entity: null,
    ...overrides,
  };
}

function process(overrides: Partial<ApprovalProcess> = {}): ApprovalProcess {
  return {
    id: 'pr1',
    run_id: 'run-pr1',
    status: 'approved',
    note: null,
    approver_type: 'user',
    decided_at: '2026-01-02T00:00:00Z',
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

describe('next approval-queue store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('captures meta.total on the first page and does NOT overwrite it on a cursor page', async () => {
    const store = useApprovalQueueStore();
    apiMock.get.mockResolvedValueOnce({
      data: [queueItem('a'), queueItem('b')],
      meta: { next_cursor: 'cur2', total: 9 },
    });
    await store.fetchQueue();

    expect(store.items.map((i) => i.process.id)).toEqual(['a', 'b']);
    expect(store.total).toBe(9);
    expect(store.cursor).toBe('cur2');
    expect(store.hasMore).toBe(true);

    // A subsequent (cursor) page has NO total — it must not clobber the captured 9.
    apiMock.get.mockResolvedValueOnce({
      data: [queueItem('c')],
      meta: { next_cursor: null },
    });
    await store.loadMore();

    expect(lastGetParams().cursor).toBe('cur2');
    expect(store.items.map((i) => i.process.id)).toEqual(['a', 'b', 'c']);
    expect(store.total).toBe(9);
    expect(store.hasMore).toBe(false);
  });

  it('drops a superseded in-flight page when a newer reset arrives (token guard)', async () => {
    const store = useApprovalQueueStore();
    let resolveFirst!: (v: unknown) => void;
    apiMock.get.mockReturnValueOnce(
      new Promise((res) => {
        resolveFirst = res;
      }),
    );
    const first = store.fetchQueue();

    apiMock.get.mockResolvedValueOnce({ data: [queueItem('new')], meta: { next_cursor: null } });
    await store.fetchQueue();

    resolveFirst({ data: [queueItem('stale')], meta: { next_cursor: null } });
    await first;

    expect(store.items.map((i) => i.process.id)).toEqual(['new']);
  });

  it('an append failure is retryable: keeps the grid + cursor, pauses then re-fetches', async () => {
    const store = useApprovalQueueStore();
    apiMock.get.mockResolvedValueOnce({
      data: [queueItem('a')],
      meta: { next_cursor: 'cur2', total: 3 },
    });
    await store.fetchQueue();

    // The append (load-more) page fails.
    apiMock.get.mockRejectedValueOnce({ response: { status: 500, data: { message: 'boom' } } });
    await store.loadMore();

    // First-page error state is NOT set; the grid + cursor + hasMore survive so the
    // page can be retried — only the dedicated load-more flag is raised.
    expect(store.errored).toBe(false);
    expect(store.loadMoreErrored).toBe(true);
    expect(store.hasMore).toBe(true);
    expect(store.cursor).toBe('cur2');
    expect(store.items.map((i) => i.process.id)).toEqual(['a']);

    // A bare loadMore() would early-return while the flag is set — retryLoadMore
    // clears it and re-fetches the same cursor page.
    apiMock.get.mockResolvedValueOnce({ data: [queueItem('b')], meta: { next_cursor: null } });
    await store.retryLoadMore();

    expect(lastGetParams().cursor).toBe('cur2');
    expect(store.loadMoreErrored).toBe(false);
    expect(store.items.map((i) => i.process.id)).toEqual(['a', 'b']);
    expect(store.total).toBe(3); // preserved across the failed + retried page
    expect(store.hasMore).toBe(false);
  });

  it('fetchCount reads res.count', async () => {
    const store = useApprovalQueueStore();
    apiMock.get.mockResolvedValueOnce({ count: 7 });
    const count = await store.fetchCount();
    expect(count).toBe(7);
    expect(store.count).toBe(7);
  });

  it('makeDecision(approved) removes the item, decrements total + count, returns the process', async () => {
    const store = useApprovalQueueStore();
    apiMock.get.mockResolvedValueOnce({
      data: [queueItem('a'), queueItem('b')],
      meta: { next_cursor: null, total: 2 },
    });
    await store.fetchQueue();
    apiMock.get.mockResolvedValueOnce({ count: 2 });
    await store.fetchCount();

    apiMock.post.mockResolvedValueOnce({ data: process({ id: 'a', status: 'approved' }) });
    const updated = await store.makeDecision('a', { decision: 'approved' });

    expect(updated.id).toBe('a');
    expect(updated.status).toBe('approved');
    // Only 'a' is removed; 'b' is untouched.
    expect(store.items.map((i) => i.process.id)).toEqual(['b']);
    expect(store.total).toBe(1);
    expect(store.count).toBe(1);
    // The decide POST hit the right endpoint with the caller's body (forwarded as-is).
    expect(apiMock.post).toHaveBeenCalledWith('/approvals/processes/a/decide', {
      decision: 'approved',
    });
  });

  it('makeDecision(rejected) WITHOUT a note is rejected client-side (no request fired)', async () => {
    const store = useApprovalQueueStore();
    apiMock.get.mockResolvedValueOnce({
      data: [queueItem('a')],
      meta: { next_cursor: null, total: 1 },
    });
    await store.fetchQueue();

    await expect(
      store.makeDecision('a', { decision: 'rejected', note: '   ' }),
    ).rejects.toMatchObject({ kind: 'note_required' });

    expect(apiMock.post).not.toHaveBeenCalled();
    // The list + total are untouched.
    expect(store.items.map((i) => i.process.id)).toEqual(['a']);
    expect(store.total).toBe(1);
  });

  it('makeDecision 422 (already decided) refetches the queue + count and surfaces a structured error', async () => {
    const store = useApprovalQueueStore();
    apiMock.get.mockResolvedValueOnce({
      data: [queueItem('a')],
      meta: { next_cursor: null, total: 1 },
    });
    await store.fetchQueue();

    // The decide POST fails with a 422 (the process was already decided).
    apiMock.post.mockRejectedValueOnce({ response: { status: 422, data: { message: 'gone' } } });
    // The store then resyncs: a queue refetch + a count refetch.
    apiMock.get.mockResolvedValueOnce({ data: [], meta: { next_cursor: null, total: 0 } });
    apiMock.get.mockResolvedValueOnce({ count: 0 });

    await expect(
      store.makeDecision('a', { decision: 'approved' }),
    ).rejects.toMatchObject({ kind: 'already_decided' });

    // The resync GETs fired (queue + count).
    const getUrls = apiMock.get.mock.calls.map((c) => c[0] as string);
    expect(getUrls).toContain('/approvals/queue');
    expect(getUrls).toContain('/approvals/queue/count');
    expect(store.items).toEqual([]);
    expect(store.count).toBe(0);
  });

  it('fetchProcess unwraps res.data; fetchRunHistory maps the res.data array', async () => {
    const store = useApprovalQueueStore();

    apiMock.get.mockResolvedValueOnce({ data: process({ id: 'pr1', run_id: 'run-1' }) });
    const proc = await store.fetchProcess('pr1');
    expect(proc?.id).toBe('pr1');
    expect(store.processCache['pr1'].id).toBe('pr1');

    apiMock.get.mockResolvedValueOnce({
      data: [process({ id: 'h1', run_id: 'run-1' }), process({ id: 'h2', run_id: 'run-1' })],
    });
    const history = await store.fetchRunHistory('run-1');
    expect(history.map((p) => p.id)).toEqual(['h1', 'h2']);
    expect(store.runHistoryCache['run-1'].map((p) => p.id)).toEqual(['h1', 'h2']);
  });

  it('resetAll clears list + detail + count state', async () => {
    const store = useApprovalQueueStore();
    apiMock.get.mockResolvedValueOnce({
      data: [queueItem('a')],
      meta: { next_cursor: 'cur2', total: 5 },
    });
    await store.fetchQueue();

    store.resetAll();

    expect(store.items).toEqual([]);
    expect(store.cursor).toBeNull();
    expect(store.hasMore).toBe(true);
    expect(store.total).toBeNull();
    expect(store.count).toBeNull();
    expect(store.processCache).toEqual({});
    expect(store.runHistoryCache).toEqual({});
  });
});

describe('groupRunHistory', () => {
  function proc(id: string, stage: ApprovalProcess['stage'], status: ApprovalProcess['status'] = 'approved'): ApprovalProcess {
    return {
      id,
      run_id: 'run-1',
      status,
      note: null,
      approver_type: 'user',
      stage,
      decided_at: '2026-01-01T00:00:00Z',
      created_at: '2026-01-01T00:00:00Z',
    };
  }

  it('groups by stage in order and buckets null-stage entries last', () => {
    const history: ApprovalProcess[] = [
      proc('p2', { id: 's2', name: 'Stage 2', icon: null, description: null, approver_type: 'user', order: 1 }),
      proc('pX', null), // a historical process whose stage FK was nulled
      proc('p1', { id: 's1', name: 'Stage 1', icon: null, description: null, approver_type: 'user', order: 0 }),
    ];

    const groups = groupRunHistory(history);

    // Ordered by stage order, with the null/"unknown" bucket last.
    expect(groups.map((g) => g.stageId)).toEqual(['s1', 's2', null]);
    expect(groups[2].stageName).toBeNull();
    expect(groups[2].processes.map((p) => p.id)).toEqual(['pX']);
  });

  it('tolerates an all-null-stage history', () => {
    const groups = groupRunHistory([proc('a', null), proc('b', null)]);
    expect(groups).toHaveLength(1);
    expect(groups[0].stageId).toBeNull();
    expect(groups[0].processes.map((p) => p.id)).toEqual(['a', 'b']);
  });
});
