// @vitest-environment happy-dom
// Unit tests for the "next" bot-actions store — the two cursor-paginated feeds
// (the bot's whole history + one task's bot activity). The api client is mocked so
// no real HTTP happens; we assert the URL, the cursor handoff, the append on
// load-more, and the optional `type` filter param.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: { get: vi.fn() },
}));

import { api } from '../../lib/api';
import { useBotActionsStore } from '../botActions';
import type { BotAction } from '../../../pages/bots/types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

function action(overrides: Partial<BotAction> = {}): BotAction {
  return {
    id: 'a1',
    bot_id: 'b1',
    task_id: 't1',
    type: 'task_started',
    payload: null,
    status: null,
    error: null,
    created_at: '2026-06-25T10:00:00Z',
    updated_at: '2026-06-25T10:00:00Z',
    ...overrides,
  };
}

describe('next bot-actions store — bot history feed', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('fetches /bots/{id}/actions and stores the first page + cursor', async () => {
    const store = useBotActionsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [action({ id: 'a1' }), action({ id: 'a2' })],
      meta: { next_cursor: 'CUR1' },
    });

    await store.fetchBotActions('b1');

    expect(apiMock.get).toHaveBeenCalledWith('/bots/b1/actions');
    expect(store.botActions.map((a) => a.id)).toEqual(['a1', 'a2']);
    expect(store.botCursor).toBe('CUR1');
    expect(store.botHasMore).toBe(true);
  });

  it('passes the optional ?type= filter param', async () => {
    const store = useBotActionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [], meta: { next_cursor: null } });

    await store.fetchBotActions('b1', 'execution_failed');

    const url = apiMock.get.mock.calls[0][0] as string;
    expect(url).toContain('/bots/b1/actions?');
    const params = new URLSearchParams(url.slice(url.indexOf('?') + 1));
    expect(params.get('type')).toBe('execution_failed');
    expect(store.botTypeFilter).toBe('execution_failed');
  });

  it('appends the next page on load-more using the stored cursor', async () => {
    const store = useBotActionsStore();
    apiMock.get
      .mockResolvedValueOnce({ data: [action({ id: 'a1' })], meta: { next_cursor: 'CUR1' } })
      .mockResolvedValueOnce({ data: [action({ id: 'a2' })], meta: { next_cursor: null } });

    await store.fetchBotActions('b1');
    await store.loadMoreBotActions('b1');

    expect(store.botActions.map((a) => a.id)).toEqual(['a1', 'a2']);
    expect(store.botHasMore).toBe(false);
    const secondUrl = apiMock.get.mock.calls[1][0] as string;
    expect(secondUrl).toContain('cursor=CUR1');
  });

  it('flags a first-page error and stops infinite scroll', async () => {
    const store = useBotActionsStore();
    apiMock.get.mockRejectedValueOnce({ response: { data: { message: 'boom' } } });

    await store.fetchBotActions('b1');

    expect(store.botError).toBe('boom');
    expect(store.botHasMore).toBe(false);
  });
});

describe('next bot-actions store — task feed', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('fetches /tasks/{id}/bot-actions and stores the page', async () => {
    const store = useBotActionsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [action({ id: 'a1', task_id: 't9' })],
      meta: { next_cursor: null },
    });

    await store.fetchTaskBotActions('t9');

    expect(apiMock.get).toHaveBeenCalledWith('/tasks/t9/bot-actions');
    expect(store.taskActions.map((a) => a.id)).toEqual(['a1']);
    expect(store.taskHasMore).toBe(false);
  });

  it('appends task feed pages on load-more with the cursor', async () => {
    const store = useBotActionsStore();
    apiMock.get
      .mockResolvedValueOnce({ data: [action({ id: 'a1' })], meta: { next_cursor: 'C2' } })
      .mockResolvedValueOnce({ data: [action({ id: 'a2' })], meta: { next_cursor: null } });

    await store.fetchTaskBotActions('t9');
    await store.loadMoreTaskBotActions('t9');

    expect(store.taskActions.map((a) => a.id)).toEqual(['a1', 'a2']);
    const secondUrl = apiMock.get.mock.calls[1][0] as string;
    expect(secondUrl).toContain('cursor=C2');
  });

  it('retains the task feed independently of the bot feed', async () => {
    const store = useBotActionsStore();
    apiMock.get
      .mockResolvedValueOnce({ data: [action({ id: 'bot1' })], meta: { next_cursor: null } })
      .mockResolvedValueOnce({ data: [action({ id: 'task1' })], meta: { next_cursor: null } });

    await store.fetchBotActions('b1');
    await store.fetchTaskBotActions('t1');

    expect(store.botActions.map((a) => a.id)).toEqual(['bot1']);
    expect(store.taskActions.map((a) => a.id)).toEqual(['task1']);
  });
});
