// @vitest-environment happy-dom
// Unit tests for the "next" workflows store — filter serialization (search +
// status), cursor reset/append (NO total), the stale-token guard, the in-place
// list reconciliation that keeps the grid in sync after create/update/status/
// delete/restore WITHOUT a full refetch, the retryable load-more path, and the
// manual-run action. The api client is mocked so no real HTTP happens. Mirrors the
// bots store spec conventions.
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
import { useWorkflowsStore, serializeFilters, ScheduleAssistError } from '../workflows';
import type { WorkflowDetail, WorkflowListItem } from '../../../pages/workflows/types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function listItem(overrides: Partial<WorkflowListItem> = {}): WorkflowListItem {
  return {
    id: 'w1',
    name: 'Workflow',
    status: 'inactive',
    description: null,
    icon: null,
    trigger_type: 'form_submitted',
    step_count: 1,
    next_due_at: null,
    is_owner: true,
    created_at: '2026-01-01T00:00:00Z',
    ...overrides,
  };
}

function detail(overrides: Partial<WorkflowDetail> = {}): WorkflowDetail {
  return {
    id: 'w1',
    name: 'Workflow',
    status: 'inactive',
    description: null,
    icon: null,
    trigger_type: 'form_submitted',
    trigger_config: {},
    conditions: [],
    steps: [{ type: 'create_task', key: 'make', config: { title: 'Hi' } }],
    last_scheduled_run_at: null,
    next_due_at: null,
    creator: { type: 'user', id: 'u1', name: 'Ada', email: 'ada@example.com', avatar: null },
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    can_change_status: true,
    can_run: true,
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

describe('next workflows store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('serializeFilters: includes non-empty search + status, skips empty/undefined', () => {
    expect(Object.fromEntries(serializeFilters({ search: 'hi', status: 'active' }))).toEqual({
      search: 'hi',
      status: 'active',
    });
    expect(Object.fromEntries(serializeFilters({ search: '' }))).toEqual({});
    expect(Object.fromEntries(serializeFilters({}))).toEqual({});
  });

  it('fetchWorkflows sends search + status and tracks cursor/hasMore (no total)', async () => {
    const store = useWorkflowsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' }), listItem({ id: 'b' })],
      meta: { next_cursor: 'cur2' },
    });

    await store.fetchWorkflows({ search: 'ship', status: 'active' });

    expect(lastGetParams()).toEqual({ search: 'ship', status: 'active' });
    expect(store.items.map((w) => w.id)).toEqual(['a', 'b']);
    expect(store.cursor).toBe('cur2');
    expect(store.hasMore).toBe(true);
    expect((store as unknown as Record<string, unknown>).total).toBeUndefined();
  });

  it('loadMore appends, sends the cursor, and tracks hasMore=false at the end', async () => {
    const store = useWorkflowsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' })],
      meta: { next_cursor: 'cur2' },
    });
    await store.fetchWorkflows();

    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'b' })],
      meta: { next_cursor: null },
    });
    await store.loadMore();

    expect(store.items.map((w) => w.id)).toEqual(['a', 'b']);
    expect(lastGetParams().cursor).toBe('cur2');
    expect(store.hasMore).toBe(false);
  });

  it('an append failure is retryable: keeps the grid + cursor + hasMore, pauses then re-fetches', async () => {
    const store = useWorkflowsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' })],
      meta: { next_cursor: 'cur2' },
    });
    await store.fetchWorkflows();

    apiMock.get.mockRejectedValueOnce({ response: { status: 500, data: { message: 'boom' } } });
    await store.loadMore();

    expect(store.errored).toBe(false);
    expect(store.loadMoreErrored).toBe(true);
    expect(store.hasMore).toBe(true);
    expect(store.cursor).toBe('cur2');
    expect(store.items.map((w) => w.id)).toEqual(['a']);

    apiMock.get.mockResolvedValueOnce({ data: [listItem({ id: 'b' })], meta: { next_cursor: null } });
    await store.retryLoadMore();

    expect(lastGetParams().cursor).toBe('cur2');
    expect(store.loadMoreErrored).toBe(false);
    expect(store.items.map((w) => w.id)).toEqual(['a', 'b']);
    expect(store.hasMore).toBe(false);
  });

  it('drops a superseded in-flight page when a newer reset arrives (token guard)', async () => {
    const store = useWorkflowsStore();
    let resolveFirst!: (v: unknown) => void;
    apiMock.get.mockReturnValueOnce(
      new Promise((res) => {
        resolveFirst = res;
      }),
    );
    const first = store.fetchWorkflows({ search: 'old' });

    apiMock.get.mockResolvedValueOnce({ data: [listItem({ id: 'new' })], meta: { next_cursor: null } });
    await store.fetchWorkflows({ search: 'new' });

    resolveFirst({ data: [listItem({ id: 'stale' })], meta: { next_cursor: null } });
    await first;

    expect(store.items.map((w) => w.id)).toEqual(['new']);
  });

  it('resetAll clears list + detail state', async () => {
    const store = useWorkflowsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' })],
      meta: { next_cursor: 'cur2' },
    });
    await store.fetchWorkflows();

    store.resetAll();

    expect(store.items).toEqual([]);
    expect(store.cursor).toBeNull();
    expect(store.hasMore).toBe(true);
    expect(store.detail).toBeNull();
    expect(store.errored).toBe(false);
  });

  it('createWorkflow prepends + reconciles a list item from the detail response', async () => {
    const store = useWorkflowsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'a' })],
      meta: { next_cursor: null },
    });
    await store.fetchWorkflows();

    apiMock.post.mockResolvedValueOnce({
      data: detail({
        id: 'new',
        name: 'Fresh',
        trigger_type: 'schedule',
        steps: [
          { type: 'create_task', key: 'a', config: {} },
          { type: 'create_form_report', key: 'b', config: {} },
        ],
      }),
    });
    const created = await store.createWorkflow({
      name: 'Fresh',
      trigger_type: 'schedule',
      steps: [
        { type: 'create_task', key: 'a' },
        { type: 'create_form_report', key: 'b' },
      ],
    });

    expect(created.id).toBe('new');
    expect(store.items.map((w) => w.id)).toEqual(['new', 'a']);
    // The prepended row is projected to the list shape with the derived fields.
    expect(store.items[0].trigger_type).toBe('schedule');
    expect(store.items[0].step_count).toBe(2);
  });

  it('updateWorkflow replaces the row in the list and updates the detail cache', async () => {
    const store = useWorkflowsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'w1', name: 'Old' })],
      meta: { next_cursor: null },
    });
    await store.fetchWorkflows();
    apiMock.get.mockResolvedValueOnce({ data: detail({ id: 'w1', name: 'Old' }) });
    await store.fetchWorkflow('w1');

    apiMock.put.mockResolvedValueOnce({ data: detail({ id: 'w1', name: 'Renamed' }) });
    await store.updateWorkflow('w1', {
      name: 'Renamed',
      trigger_type: 'form_submitted',
      steps: [{ type: 'create_task', key: 'make' }],
    });

    expect(store.items).toHaveLength(1);
    expect(store.items[0].name).toBe('Renamed');
    expect(store.detail?.name).toBe('Renamed');
  });

  it('setStatus PATCHes and reconciles the list row + detail cache', async () => {
    const store = useWorkflowsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'w1', status: 'inactive' })],
      meta: { next_cursor: null },
    });
    await store.fetchWorkflows();
    apiMock.get.mockResolvedValueOnce({ data: detail({ id: 'w1', status: 'inactive' }) });
    await store.fetchWorkflow('w1');

    apiMock.patch.mockResolvedValueOnce({ data: detail({ id: 'w1', status: 'active' }) });
    const updated = await store.setStatus('w1', 'active');

    expect(apiMock.patch).toHaveBeenCalledWith('/workflows/w1/status', { status: 'active' });
    expect(updated.status).toBe('active');
    expect(store.items[0].status).toBe('active');
    expect(store.detail?.status).toBe('active');
  });

  it('deleteWorkflow removes the row from the list', async () => {
    const store = useWorkflowsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'w1' }), listItem({ id: 'w2' })],
      meta: { next_cursor: null },
    });
    await store.fetchWorkflows();

    apiMock.delete.mockResolvedValueOnce({ message: 'ok' });
    await store.deleteWorkflow('w1');

    expect(store.items.map((w) => w.id)).toEqual(['w2']);
  });

  it('restoreWorkflow prepends the workflow back into the list', async () => {
    const store = useWorkflowsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [listItem({ id: 'w2' })],
      meta: { next_cursor: null },
    });
    await store.fetchWorkflows();

    apiMock.post.mockResolvedValueOnce({ data: detail({ id: 'w1', name: 'Restored' }) });
    const restored = await store.restoreWorkflow('w1');

    expect(restored.id).toBe('w1');
    expect(store.items.map((w) => w.id)).toEqual(['w1', 'w2']);
    expect(store.detail?.name).toBe('Restored');
  });

  it('run posts the target_id when provided and omits it otherwise (202 run)', async () => {
    const store = useWorkflowsStore();

    apiMock.post.mockResolvedValueOnce({
      data: { id: 'run1', state: 'pending', origin: 'manual', trigger_type: 'form_submitted' },
    });
    await store.run('w1', 'task-42');
    expect(apiMock.post).toHaveBeenLastCalledWith('/workflows/w1/run', { target_id: 'task-42' });

    apiMock.post.mockResolvedValueOnce({
      data: { id: 'run2', state: 'pending', origin: 'manual', trigger_type: 'schedule' },
    });
    const run = await store.run('w1');
    expect(apiMock.post).toHaveBeenLastCalledWith('/workflows/w1/run', {});
    expect(run.id).toBe('run2');
  });

  // --- schedule builder catalog / assist / preview (§4.5, §4.7) ------------
  // v2 (Phase 4a) has NO schedule-families endpoint — the FE owns every label, so the
  // REV3 fetchScheduleFamilies + its cache were removed (and are asserted GONE below).

  it('does not expose the removed families vocabulary (v2)', () => {
    const store = useWorkflowsStore() as unknown as Record<string, unknown>;
    expect(store.fetchScheduleFamilies).toBeUndefined();
    expect(store.scheduleFamilies).toBeUndefined();
  });

  it('fetchWorkflowCatalog caches PER form id (a different form refetches)', async () => {
    const store = useWorkflowsStore();
    const catalogA = { variables: [{ source: 'trigger', path: 'trigger.form.id', name: 'Form ID', type: 'text' }], fields: [] };
    const catalogB = { variables: [], fields: [] };
    apiMock.get.mockResolvedValueOnce({ data: catalogA });

    const a1 = await store.fetchWorkflowCatalog('form-a');
    expect(a1.variables).toHaveLength(1);
    expect(apiMock.get).toHaveBeenCalledWith('/forms/form-a/workflow-catalog');

    // Same form → cache hit, no second request. (Identity differs only because
    // Pinia reactively proxies the cached object; the KEY proof is the request count.)
    const a2 = await store.fetchWorkflowCatalog('form-a');
    expect(a2).toStrictEqual(a1);
    expect(apiMock.get).toHaveBeenCalledTimes(1);

    // Different form → a fresh request.
    apiMock.get.mockResolvedValueOnce({ data: catalogB });
    await store.fetchWorkflowCatalog('form-b');
    expect(apiMock.get).toHaveBeenCalledTimes(2);
    expect(store.catalogByForm['form-a']).toStrictEqual(a1);

    // invalidate drops the cached catalog so the next call refetches.
    store.invalidateCatalog('form-a');
    expect(store.catalogByForm['form-a']).toBeUndefined();
    apiMock.get.mockResolvedValueOnce({ data: catalogA });
    await store.fetchWorkflowCatalog('form-a');
    expect(apiMock.get).toHaveBeenCalledTimes(3);
  });

  it('scheduleAssist returns the envelope and sends the tz hint', async () => {
    const store = useWorkflowsStore();
    const envelope = {
      feasible: true,
      config: { family: 'daily', params: { time: '09:00' }, tz: 'UTC' },
      unsupported: [],
      alternative: null,
      explanation: 'Every day at 9.',
    };
    apiMock.post.mockResolvedValueOnce({ data: envelope });

    const result = await store.scheduleAssist('every day at 9', 'Europe/Warsaw');
    expect(result.feasible).toBe(true);
    expect(apiMock.post).toHaveBeenCalledWith('/workflows/schedule-assist', {
      prompt: 'every day at 9',
      tz: 'Europe/Warsaw',
    });
  });

  it('scheduleAssist omits a blank tz', async () => {
    const store = useWorkflowsStore();
    apiMock.post.mockResolvedValueOnce({ data: { feasible: false, config: null, unsupported: [], alternative: null, explanation: '' } });
    await store.scheduleAssist('nonsense');
    expect(apiMock.post).toHaveBeenCalledWith('/workflows/schedule-assist', { prompt: 'nonsense' });
  });

  it('scheduleAssist maps a 429 to a distinct throttled error', async () => {
    const store = useWorkflowsStore();
    apiMock.post.mockRejectedValueOnce({ response: { status: 429, data: { message: 'PL raw msg' } } });
    await expect(store.scheduleAssist('too many')).rejects.toMatchObject({
      name: 'ScheduleAssistError',
      kind: 'throttled',
    });
  });

  it('scheduleAssist maps any other failure to a generic failed error (never leaks the raw message)', async () => {
    const store = useWorkflowsStore();
    apiMock.post.mockRejectedValueOnce({ response: { status: 500, data: { message: 'PL raw msg' } } });
    await expect(store.scheduleAssist('boom')).rejects.toBeInstanceOf(ScheduleAssistError);
    apiMock.post.mockRejectedValueOnce(new Error('network down'));
    await expect(store.scheduleAssist('boom')).rejects.toMatchObject({ kind: 'failed' });
  });

  it('schedulePreview POSTs the v2 schedule + count and returns the FLAT body', async () => {
    const store = useWorkflowsStore();
    // REGRESSION: the endpoint returns the flat body (no `data` wrapper, unlike the
    // assist) and api.post already unwraps the axios response — mocking a wrapped
    // shape here once masked a real `res.data === undefined` bug in the browser.
    const body = {
      occurrences: ['2026-07-01T06:00:00.000000Z', '2026-07-02T06:00:00.000000Z'],
      count: 2,
      empty: false,
      approximate: false,
    };
    apiMock.post.mockResolvedValueOnce(body);

    const schedule = { time: { mode: 'at' as const, at: ['08:00'] }, tz: 'Europe/Warsaw' };
    const result = await store.schedulePreview(schedule, { count: 6 });

    expect(apiMock.post).toHaveBeenCalledWith('/workflows/meta/schedule-preview', { schedule, count: 6 });
    expect(result.occurrences).toHaveLength(2);
    expect(result.empty).toBe(false);
  });

  it('schedulePreview defaults count to 6 and surfaces empty:true (not an error)', async () => {
    const store = useWorkflowsStore();
    apiMock.post.mockResolvedValueOnce({ occurrences: [], count: 0, empty: true, approximate: false });
    const schedule = { time: { mode: 'at' as const, at: ['08:00'] } };
    const result = await store.schedulePreview(schedule);
    expect(apiMock.post).toHaveBeenCalledWith('/workflows/meta/schedule-preview', { schedule, count: 6 });
    expect(result.empty).toBe(true);
  });

  it('schedulePreview forwards an anchor for prev-or-at paging (§4.5.4)', async () => {
    const store = useWorkflowsStore();
    apiMock.post.mockResolvedValueOnce({
      occurrences: ['2026-07-10T08:00:00Z', '2026-07-11T08:00:00Z'],
      count: 2,
      empty: false,
      approximate: false,
    });
    const schedule = { time: { mode: 'at' as const, at: ['08:00'] } };
    await store.schedulePreview(schedule, { count: 2, anchor: '2026-07-10T12:00:00' });
    expect(apiMock.post).toHaveBeenCalledWith('/workflows/meta/schedule-preview', {
      schedule,
      count: 2,
      anchor: '2026-07-10T12:00:00',
    });
  });
});
