// @vitest-environment happy-dom
// Unit tests for the "next" generation-sessions store — filter serialization (search + single status),
// cursor reset/append (NO total), the stale-token guard, in-place list reconciliation after
// create/patch/delete WITHOUT a full refetch, the retryable load-more path, and the async `generate` /
// per-part refine actions. The store NO LONGER POLLS: a `generating` run settles via the websocket wait in
// `session/useSessionSettle.ts` (its own spec covers the settle path). The api client is mocked so no real
// HTTP happens. Mirrors the bots / templates store specs.
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
import { useSessionsStore, serializeFilters, isBudgetError, AI_BUDGET_ERROR_CODE } from '../sessions';
import type { Session } from '../../../pages/generator/sessionTypes';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function session(overrides: Partial<Session> = {}): Session {
  return {
    id: 's1',
    name: 'Spring promo',
    template_id: 't1',
    content_type: 'post_with_image',
    status: 'draft',
    slot_values: {},
    results: null,
    // DETAIL-only on the wire (the index projection omits the key entirely); null until a run derives one.
    creative_direction: null,
    part_history: {},
    last_op_status: null,
    last_op_error: null,
    creator: null,
    is_owner: true,
    can_generate: true,
    can_edit: true,
    can_be_deleted: true,
    bot_author: null,
    is_delegated: false,
    can_delegate: true,
    can_undo_delegation: false,
    unfilled_required_slots: [],
    is_archived: false,
    can_archive: true,
    archived_at: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

/** Parse the query string of the last `api.get` list call into a plain object. */
function lastListParams(): Record<string, string> {
  const calls = apiMock.get.mock.calls.filter((c) => String(c[0]).startsWith('/generator/sessions?') || c[0] === '/generator/sessions');
  const url = String(calls[calls.length - 1]?.[0] ?? '');
  const qs = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
  return Object.fromEntries(new URLSearchParams(qs));
}

describe('next sessions store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('serializeFilters: includes non-empty search + status, skips empties', () => {
    expect(Object.fromEntries(serializeFilters({ search: 'hi', status: 'ready' }))).toEqual({ search: 'hi', status: 'ready' });
    expect(Object.fromEntries(serializeFilters({ search: '' }))).toEqual({});
    expect(Object.fromEntries(serializeFilters({}))).toEqual({});
  });

  it('fetchSessions sends search + status and tracks cursor/hasMore (no total)', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [session({ id: 'a' }), session({ id: 'b' })],
      meta: { next_cursor: 'cur2' },
    });

    await store.fetchSessions({ search: 'promo', status: 'ready' });

    expect(lastListParams()).toEqual({ search: 'promo', status: 'ready' });
    expect(store.items.map((s) => s.id)).toEqual(['a', 'b']);
    expect(store.cursor).toBe('cur2');
    expect(store.hasMore).toBe(true);
    expect((store as unknown as Record<string, unknown>).total).toBeUndefined();
  });

  it('loadMore appends, sends the cursor, ends hasMore=false', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 'a' })], meta: { next_cursor: 'cur2' } });
    await store.fetchSessions();

    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 'b' })], meta: { next_cursor: null } });
    await store.loadMore();

    expect(store.items.map((s) => s.id)).toEqual(['a', 'b']);
    expect(lastListParams().cursor).toBe('cur2');
    expect(store.hasMore).toBe(false);
  });

  it('an append failure is retryable: keeps the list + cursor + hasMore, pauses then re-fetches', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 'a' })], meta: { next_cursor: 'cur2' } });
    await store.fetchSessions();

    apiMock.get.mockRejectedValueOnce({ response: { status: 500, data: { message: 'boom' } } });
    await store.loadMore();

    expect(store.errored).toBe(false);
    expect(store.loadMoreErrored).toBe(true);
    expect(store.hasMore).toBe(true);
    expect(store.cursor).toBe('cur2');
    expect(store.items.map((s) => s.id)).toEqual(['a']);

    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 'b' })], meta: { next_cursor: null } });
    await store.retryLoadMore();

    expect(store.loadMoreErrored).toBe(false);
    expect(store.items.map((s) => s.id)).toEqual(['a', 'b']);
  });

  it('drops a superseded in-flight page when a newer reset arrives (token guard)', async () => {
    const store = useSessionsStore();
    let resolveFirst!: (v: unknown) => void;
    apiMock.get.mockReturnValueOnce(new Promise((res) => { resolveFirst = res; }));
    const first = store.fetchSessions({ search: 'old' });

    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 'new' })], meta: { next_cursor: null } });
    await store.fetchSessions({ search: 'new' });

    resolveFirst({ data: [session({ id: 'stale' })], meta: { next_cursor: null } });
    await first;

    expect(store.items.map((s) => s.id)).toEqual(['new']);
  });

  it('resetAll clears list + detail state', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 'a' })], meta: { next_cursor: 'cur2' } });
    await store.fetchSessions();

    store.resetAll();

    expect(store.items).toEqual([]);
    expect(store.cursor).toBeNull();
    expect(store.hasMore).toBe(true);
    expect(store.detail).toBeNull();
    expect(store.errored).toBe(false);
  });

  it('createSession prepends the created session to the list', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 'a' })], meta: { next_cursor: null } });
    await store.fetchSessions();

    apiMock.post.mockResolvedValueOnce({ data: session({ id: 'new', name: 'Fresh' }) });
    const created = await store.createSession({ template_id: 't9' });

    expect(apiMock.post).toHaveBeenCalledWith('/generator/sessions', { template_id: 't9' });
    expect(created.id).toBe('new');
    expect(store.items.map((s) => s.id)).toEqual(['new', 'a']);
  });

  it('patchSession replaces the row + updates the detail cache', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 's1', name: 'Old' })], meta: { next_cursor: null } });
    await store.fetchSessions();
    apiMock.get.mockResolvedValueOnce({ data: session({ id: 's1', name: 'Old' }) });
    await store.fetchSession('s1');

    apiMock.patch.mockResolvedValueOnce({ data: session({ id: 's1', name: 'Renamed' }) });
    await store.patchSession('s1', { slot_values: { topic: 'x' } });

    expect(apiMock.patch).toHaveBeenCalledWith('/generator/sessions/s1', { slot_values: { topic: 'x' } });
    expect(store.items[0].name).toBe('Renamed');
    expect(store.detail?.name).toBe('Renamed');
  });

  it('deleteSession removes the row and clears a matching detail', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 's1' }), session({ id: 's2' })], meta: { next_cursor: null } });
    await store.fetchSessions();
    apiMock.get.mockResolvedValueOnce({ data: session({ id: 's1' }) });
    await store.fetchSession('s1');

    apiMock.delete.mockResolvedValueOnce({ message: 'ok' });
    await store.deleteSession('s1');

    expect(store.items.map((s) => s.id)).toEqual(['s2']);
    expect(store.detail).toBeNull();
  });

  it('generate POSTs to the generate endpoint and reconciles the returned state', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 's1', status: 'draft' })], meta: { next_cursor: null } });
    await store.fetchSessions();

    apiMock.post.mockResolvedValueOnce({ data: session({ id: 's1', status: 'generating' }) });
    const res = await store.generate('s1');

    expect(apiMock.post).toHaveBeenCalledWith('/generator/sessions/s1/generate');
    expect(res.status).toBe('generating');
    expect(store.items[0].status).toBe('generating');
  });

  it('does not expose a poll action (settling is the websocket composable\'s job)', () => {
    const store = useSessionsStore();
    expect((store as unknown as Record<string, unknown>).pollUntilSettled).toBeUndefined();
  });

  it('regeneratePart POSTs the regenerate endpoint and reconciles the returned generating state', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 's1', status: 'ready' })], meta: { next_cursor: null } });
    await store.fetchSessions();

    apiMock.post.mockResolvedValueOnce({ data: session({ id: 's1', status: 'generating' }) });
    const res = await store.regeneratePart('s1', 'body');

    expect(apiMock.post).toHaveBeenCalledWith('/generator/sessions/s1/parts/body/regenerate');
    expect(res.status).toBe('generating');
    expect(store.items[0].status).toBe('generating');
  });

  it('refinePart POSTs the refine endpoint with the instruction body and reconciles', async () => {
    const store = useSessionsStore();
    apiMock.post.mockResolvedValueOnce({ data: session({ id: 's1', status: 'generating' }) });

    const res = await store.refinePart('s1', 'scene_plan.0', 'make it punchier');

    expect(apiMock.post).toHaveBeenCalledWith(
      '/generator/sessions/s1/parts/scene_plan.0/refine',
      { instruction: 'make it punchier' },
    );
    expect(res.status).toBe('generating');
  });

  it('undoPart POSTs the undo endpoint (synchronous) and reconciles the reverted session', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 's1', status: 'ready' })], meta: { next_cursor: null } });
    await store.fetchSessions();

    apiMock.post.mockResolvedValueOnce({
      data: session({ id: 's1', status: 'ready', results: { body: { kind: 'text_body', status: 'ok', text: 'old', version: 1 } } }),
    });
    const res = await store.undoPart('s1', 'body');

    expect(apiMock.post).toHaveBeenCalledWith('/generator/sessions/s1/parts/body/undo');
    expect(res.results?.body.version).toBe(1);
    expect(store.items[0].results?.body.version).toBe(1);
  });

  it('archiveSession / unarchiveSession POST their endpoints and reconcile the flag', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 's1', is_archived: false })], meta: { next_cursor: null } });
    await store.fetchSessions();

    apiMock.post.mockResolvedValueOnce({ data: session({ id: 's1', is_archived: true }) });
    await store.archiveSession('s1');
    expect(apiMock.post).toHaveBeenCalledWith('/generator/sessions/s1/archive');
    expect(store.items[0].is_archived).toBe(true);

    apiMock.post.mockResolvedValueOnce({ data: session({ id: 's1', is_archived: false }) });
    await store.unarchiveSession('s1');
    expect(apiMock.post).toHaveBeenCalledWith('/generator/sessions/s1/unarchive');
    expect(store.items[0].is_archived).toBe(false);
  });

  it('a part op returns generating, and a later fetchSession exposes last_op_status for the caller to toast', async () => {
    const store = useSessionsStore();
    // 202 → generating. The websocket settle (composable) later re-fetches the terminal state.
    apiMock.post.mockResolvedValueOnce({ data: session({ id: 's1', status: 'generating' }) });
    const claimed = await store.regeneratePart('s1', 'body');
    expect(claimed.status).toBe('generating');

    apiMock.get.mockResolvedValueOnce({
      data: session({ id: 's1', status: 'ready', last_op_status: 'failed', last_op_error: 'The model refused.' }),
    });
    const settled = await store.fetchSession('s1');

    expect(settled.last_op_status).toBe('failed');
    expect(settled.last_op_error).toBe('The model refused.');
    expect(store.detail?.status).toBe('ready'); // fetchSession seeds/updates the detail cache
  });

  it('delegate POSTs the bot delegate endpoint with auto_generate and returns session + fill report', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({ data: [session({ id: 's1', status: 'draft' })], meta: { next_cursor: null } });
    await store.fetchSessions();

    apiMock.post.mockResolvedValueOnce({
      data: session({
        id: 's1',
        status: 'draft',
        slot_values: { topic: 'spring' },
        is_delegated: true,
        bot_author: { id: 'bot1', name: 'Copy Bot', icon: null },
        unfilled_required_slots: ['hero_image'],
      }),
      fill_report: {
        filled: ['topic'],
        skipped: [{ name: 'mystery', reason: 'unknown_slot' }],
        unfilled_required: ['hero_image'],
        mode: 'gaps',
        nothing_to_fill: false,
      },
    });

    const { session: updated, fillReport } = await store.delegate('s1', 'bot1', false);

    // fill_mode defaults to the non-destructive 'gaps' when the caller doesn't pass one.
    expect(apiMock.post).toHaveBeenCalledWith('/bots/bot1/sessions/s1/delegate', {
      auto_generate: false,
      fill_mode: 'gaps',
    });
    expect(updated.is_delegated).toBe(true);
    expect(updated.bot_author?.name).toBe('Copy Bot');
    expect(fillReport.filled).toEqual(['topic']);
    expect(fillReport.skipped[0]).toEqual({ name: 'mystery', reason: 'unknown_slot' });
    expect(fillReport.unfilled_required).toEqual(['hero_image']);
    expect(fillReport.mode).toBe('gaps');
    expect(fillReport.nothing_to_fill).toBe(false);
    // Reconciled in-place into the list + detail.
    expect(store.items[0].is_delegated).toBe(true);
  });

  it('delegate sends the chosen fill_mode ("fresh") in the body', async () => {
    const store = useSessionsStore();
    apiMock.post.mockResolvedValueOnce({
      data: session({ id: 's1', status: 'draft', is_delegated: true, bot_author: { id: 'b', name: 'B' } }),
      fill_report: {
        filled: ['topic', 'angle'],
        skipped: [],
        unfilled_required: [],
        mode: 'fresh',
        nothing_to_fill: false,
      },
    });

    const { fillReport } = await store.delegate('s1', 'b', false, 'fresh');

    expect(apiMock.post).toHaveBeenCalledWith('/bots/b/sessions/s1/delegate', {
      auto_generate: false,
      fill_mode: 'fresh',
    });
    expect(fillReport.mode).toBe('fresh');
  });

  it('delegate passes through a nothing_to_fill report (gaps mode, no AI call)', async () => {
    const store = useSessionsStore();
    apiMock.post.mockResolvedValueOnce({
      data: session({ id: 's1', status: 'draft', is_delegated: true, bot_author: { id: 'b', name: 'B' } }),
      fill_report: {
        filled: [],
        skipped: [],
        unfilled_required: [],
        mode: 'gaps',
        nothing_to_fill: true,
      },
    });

    const { fillReport } = await store.delegate('s1', 'b', false, 'gaps');

    expect(fillReport.nothing_to_fill).toBe(true);
    expect(fillReport.filled).toEqual([]);
  });

  it('delegate with autoGenerate=true sends the opt-in and reconciles the generating session (settle path)', async () => {
    const store = useSessionsStore();
    apiMock.post.mockResolvedValueOnce({
      data: session({ id: 's1', status: 'generating', is_delegated: true, bot_author: { id: 'b', name: 'B' } }),
      fill_report: {
        filled: ['topic'],
        skipped: [],
        unfilled_required: [],
        mode: 'gaps',
        nothing_to_fill: false,
      },
    });

    const { session: updated } = await store.delegate('s1', 'b', true);

    expect(apiMock.post).toHaveBeenCalledWith('/bots/b/sessions/s1/delegate', {
      auto_generate: true,
      fill_mode: 'gaps',
    });
    expect(updated.status).toBe('generating');
  });

  it('delegate rejects on a 409 (mid-run) so the caller can toast', async () => {
    const store = useSessionsStore();
    apiMock.post.mockRejectedValueOnce({ response: { status: 409, data: { message: 'busy' } } });
    await expect(store.delegate('s1', 'b', false)).rejects.toMatchObject({ response: { status: 409 } });
  });

  it('undoDelegation DELETEs the bot delegate endpoint and reconciles the restored session', async () => {
    const store = useSessionsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [session({ id: 's1', is_delegated: true, bot_author: { id: 'bot1', name: 'Copy Bot' } })],
      meta: { next_cursor: null },
    });
    await store.fetchSessions();

    apiMock.delete.mockResolvedValueOnce({
      data: session({ id: 's1', is_delegated: false, bot_author: null, slot_values: { topic: 'human original' } }),
    });
    const restored = await store.undoDelegation('s1', 'bot1');

    expect(apiMock.delete).toHaveBeenCalledWith('/bots/bot1/sessions/s1/delegate');
    expect(restored.is_delegated).toBe(false);
    expect(restored.bot_author).toBeNull();
    expect(store.items[0].is_delegated).toBe(false);
    expect(store.items[0].slot_values).toEqual({ topic: 'human original' });
  });

  it('undoDelegation rejects on a 409 (generating) for the caller to toast', async () => {
    const store = useSessionsStore();
    apiMock.delete.mockRejectedValueOnce({ response: { status: 409, data: { message: 'busy' } } });
    await expect(store.undoDelegation('s1', 'bot1')).rejects.toMatchObject({ response: { status: 409 } });
  });

  it('saveResultToDisk POSTs the part save endpoint with name + folder and returns the File', async () => {
    const store = useSessionsStore();
    apiMock.post.mockResolvedValueOnce({ data: { id: 'f1', name: 'Spring promo.png' } });

    const file = await store.saveResultToDisk('s1', 'image', { name: 'Spring promo.png', folder_id: 'fold1' });

    expect(apiMock.post).toHaveBeenCalledWith(
      '/generator/sessions/s1/parts/image/save-to-disk',
      { name: 'Spring promo.png', folder_id: 'fold1' },
    );
    expect(file).toEqual({ id: 'f1', name: 'Spring promo.png' });
  });

  it('saveResultToDisk drops an empty name / null folder so the server applies its defaults', async () => {
    const store = useSessionsStore();
    apiMock.post.mockResolvedValueOnce({ data: { id: 'f2' } });

    await store.saveResultToDisk('s1', 'scene_plan.0', { name: '   ', folder_id: null });

    expect(apiMock.post).toHaveBeenCalledWith(
      '/generator/sessions/s1/parts/scene_plan.0/save-to-disk',
      {},
    );
  });

  // Budget-error recognition (R2 sub-stage 4): the composer must tell a budget/over-cap refusal apart from a
  // generic error so it can show the blocked banner instead of a generic toast.
  describe('isBudgetError', () => {
    it('recognizes an HTTP 429 (the shared budget status)', () => {
      expect(isBudgetError({ response: { status: 429 } })).toBe(true);
    });

    it('recognizes the typed budget code in the body (code or error)', () => {
      expect(isBudgetError({ response: { status: 400, data: { code: AI_BUDGET_ERROR_CODE } } })).toBe(true);
      expect(isBudgetError({ response: { status: 400, data: { error: AI_BUDGET_ERROR_CODE } } })).toBe(true);
    });

    it('does NOT flag a generic error (409, 422, network)', () => {
      expect(isBudgetError({ response: { status: 409 } })).toBe(false);
      expect(isBudgetError({ response: { status: 422, data: { message: 'nope' } } })).toBe(false);
      expect(isBudgetError(new Error('network'))).toBe(false);
      expect(isBudgetError(undefined)).toBe(false);
    });
  });
});
