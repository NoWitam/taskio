// @vitest-environment happy-dom
// Unit tests for the "next" bot-inbox store (Batch 7): the first fetch populates
// the list + buckets + runs_this_month; `setState` refetches with `?state=`;
// load-more appends (+ retryable); `retryTask` calls the endpoint and refetches on
// BOTH 200 and 422; the request-token guard drops a superseded fetch. The api
// client is mocked so no real HTTP happens.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({ api: { get: vi.fn(), post: vi.fn() } }));

import { api } from '../../lib/api';
import { useBotInboxStore } from '../botInbox';
import type { BotInboxTask } from '../../../pages/bots/types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
};

function row(id: string, inbox_state: BotInboxTask['inbox_state'] = 'queued'): BotInboxTask {
  return {
    id,
    title: `Task ${id}`,
    priority: 'medium',
    deadline: null,
    is_overdue: false,
    is_at_risk: false,
    assigned: { id: 'u1', name: 'Ada' },
    labels: [],
    is_in_approval: false,
    inbox_state,
  } as BotInboxTask;
}

const BUCKETS = {
  queued: 2,
  running: 1,
  waiting: 0,
  in_approval: 0,
  revision: 0,
  failed: 1,
  done: 3,
};

describe('next bot-inbox store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('fetches the inbox and populates list + buckets + runs_this_month', async () => {
    const store = useBotInboxStore();
    apiMock.get.mockResolvedValueOnce({
      data: [row('t1'), row('t2')],
      meta: { next_cursor: 'C1' },
      buckets: BUCKETS,
      runs_this_month: 12,
    });

    await store.fetchInbox('b1');

    expect(apiMock.get).toHaveBeenCalledWith('/bots/b1/inbox');
    expect(store.items.map((r) => r.id)).toEqual(['t1', 't2']);
    expect(store.buckets.done).toBe(3);
    expect(store.runsThisMonth).toBe(12);
    expect(store.hasMore).toBe(true);
  });

  it('defaults omitted bucket keys to 0', async () => {
    const store = useBotInboxStore();
    apiMock.get.mockResolvedValueOnce({
      data: [],
      meta: { next_cursor: null },
      buckets: { failed: 2 },
      runs_this_month: 0,
    });

    await store.fetchInbox('b1');

    expect(store.buckets.failed).toBe(2);
    expect(store.buckets.queued).toBe(0);
    expect(store.buckets.done).toBe(0);
  });

  it('setState refetches with the ?state= filter', async () => {
    const store = useBotInboxStore();
    apiMock.get.mockResolvedValue({
      data: [row('f1', 'failed')],
      meta: { next_cursor: null },
      buckets: BUCKETS,
      runs_this_month: 5,
    });

    await store.fetchInbox('b1');
    await store.setState('b1', 'failed');

    expect(store.state).toBe('failed');
    const lastUrl = apiMock.get.mock.calls[apiMock.get.mock.calls.length - 1][0] as string;
    expect(lastUrl).toContain('/bots/b1/inbox?');
    expect(new URLSearchParams(lastUrl.slice(lastUrl.indexOf('?') + 1)).get('state')).toBe('failed');
  });

  it('load-more appends the next page using the cursor', async () => {
    const store = useBotInboxStore();
    apiMock.get
      .mockResolvedValueOnce({ data: [row('t1')], meta: { next_cursor: 'C1' }, buckets: BUCKETS, runs_this_month: 1 })
      .mockResolvedValueOnce({ data: [row('t2')], meta: { next_cursor: null }, buckets: BUCKETS, runs_this_month: 1 });

    await store.fetchInbox('b1');
    await store.loadMore('b1');

    expect(store.items.map((r) => r.id)).toEqual(['t1', 't2']);
    expect(store.hasMore).toBe(false);
    const secondUrl = apiMock.get.mock.calls[1][0] as string;
    expect(secondUrl).toContain('cursor=C1');
  });

  it('tracks a failed append separately and can retry it', async () => {
    const store = useBotInboxStore();
    apiMock.get
      .mockResolvedValueOnce({ data: [row('t1')], meta: { next_cursor: 'C1' }, buckets: BUCKETS, runs_this_month: 1 })
      .mockRejectedValueOnce({ response: { data: { message: 'boom' } } })
      .mockResolvedValueOnce({ data: [row('t2')], meta: { next_cursor: null }, buckets: BUCKETS, runs_this_month: 1 });

    await store.fetchInbox('b1');
    await store.loadMore('b1');
    expect(store.loadMoreErrored).toBe(true);
    expect(store.items.map((r) => r.id)).toEqual(['t1']); // page preserved

    await store.retryLoadMore('b1');
    expect(store.loadMoreErrored).toBe(false);
    expect(store.items.map((r) => r.id)).toEqual(['t1', 't2']);
  });

  it('retryTask POSTs the retry endpoint and refetches on 200 → "retried"', async () => {
    const store = useBotInboxStore();
    apiMock.post.mockResolvedValueOnce({ message: 'ok' });
    apiMock.get.mockResolvedValueOnce({
      data: [row('t1', 'running')],
      meta: { next_cursor: null },
      buckets: BUCKETS,
      runs_this_month: 2,
    });

    const outcome = await store.retryTask('b1', 't1');

    expect(apiMock.post).toHaveBeenCalledWith('/bots/b1/tasks/t1/retry', {});
    expect(apiMock.get).toHaveBeenCalledTimes(1); // the refetch
    expect(outcome).toBe('retried');
  });

  it('retryTask refetches AND returns "stale" on a 422 (no longer failed)', async () => {
    const store = useBotInboxStore();
    apiMock.post.mockRejectedValueOnce({ response: { status: 422, data: { message: 'not failed' } } });
    apiMock.get.mockResolvedValueOnce({
      data: [],
      meta: { next_cursor: null },
      buckets: BUCKETS,
      runs_this_month: 2,
    });

    const outcome = await store.retryTask('b1', 't1');

    expect(outcome).toBe('stale');
    expect(apiMock.get).toHaveBeenCalledTimes(1); // still refetched
  });

  it('retryTask returns "error" on a non-422 failure and does NOT refetch', async () => {
    const store = useBotInboxStore();
    apiMock.post.mockRejectedValueOnce({ response: { status: 500 } });

    const outcome = await store.retryTask('b1', 't1');

    expect(outcome).toBe('error');
    expect(apiMock.get).not.toHaveBeenCalled();
  });

  it('a reset supersedes an in-flight fetch (token guard)', async () => {
    const store = useBotInboxStore();
    let resolveFirst!: (v: unknown) => void;
    apiMock.get
      .mockImplementationOnce(() => new Promise((res) => { resolveFirst = res; }))
      .mockResolvedValueOnce({ data: [row('new')], meta: { next_cursor: null }, buckets: BUCKETS, runs_this_month: 9 });

    const first = store.fetchInbox('b1'); // in-flight
    await store.fetchInbox('b1'); // supersedes with a fresh token
    // Late-resolve the first request: it must be ignored.
    resolveFirst({ data: [row('stale')], meta: { next_cursor: 'X' }, buckets: BUCKETS, runs_this_month: 1 });
    await first;

    expect(store.items.map((r) => r.id)).toEqual(['new']);
    expect(store.runsThisMonth).toBe(9);
  });
});
