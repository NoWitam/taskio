// @vitest-environment happy-dom
// Unit tests for the "next" workflow RUNS store — filter serialization (state +
// origin), cursor reset/append (NO total), the stale-token guard, the retryable
// load-more path, resetAll, and the single-run detail fetch (including the
// cross-workflow 404 guard the nested run route enforces). The api client is
// mocked so no real HTTP happens. Mirrors the workflows store spec conventions.
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
import { useWorkflowRunsStore, serializeRunFilters } from '../workflowRuns';
import type { WorkflowRun } from '../../../pages/workflows/types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

function run(overrides: Partial<WorkflowRun> = {}): WorkflowRun {
  return {
    id: 'r1',
    state: 'completed',
    state_label: 'Completed',
    state_tone: 'success',
    origin: 'manual',
    trigger_type: 'form_submitted',
    depth: 0,
    origin_run_id: null,
    error: null,
    started_at: '2026-01-01T00:00:00Z',
    finished_at: '2026-01-01T00:00:05Z',
    created_at: '2026-01-01T00:00:00Z',
    duration_seconds: 5,
    steps_count: 2,
    ...overrides,
  };
}

/** The URLSearchParams of the last `api.get` call (use `.getAll` for array params). */
function lastGetSearchParams(): URLSearchParams {
  const calls = apiMock.get.mock.calls;
  const url = calls[calls.length - 1]?.[0] as string;
  const qs = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
  return new URLSearchParams(qs);
}

/**
 * Parse the query string of the last `api.get` call into a plain object. NOTE
 * duplicate (array) keys collapse to the LAST value — use `lastGetSearchParams`
 * for array params.
 */
function lastGetParams(): Record<string, string> {
  return Object.fromEntries(lastGetSearchParams());
}

/** The path (before any query) of the last `api.get` call. */
function lastGetPath(): string {
  const calls = apiMock.get.mock.calls;
  const url = calls[calls.length - 1]?.[0] as string;
  return url.includes('?') ? url.slice(0, url.indexOf('?')) : url;
}

describe('next workflow runs store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('serializeRunFilters: emits repeated array params (incl. workflow_id[]) + scalars, skips empty/undefined', () => {
    const p = serializeRunFilters({
      state: ['failed', 'running'],
      origin: ['manual'],
      trigger_type: ['schedule'],
      workflow_id: ['wf-9', 'wf-10'],
      date_from: '2026-01-01',
      date_to: '2026-01-31',
      date_preset: 'this_month',
    });
    expect(p.getAll('state[]')).toEqual(['failed', 'running']);
    expect(p.getAll('origin[]')).toEqual(['manual']);
    expect(p.getAll('trigger_type[]')).toEqual(['schedule']);
    expect(p.getAll('workflow_id[]')).toEqual(['wf-9', 'wf-10']);
    expect(p.get('date_from')).toBe('2026-01-01');
    expect(p.get('date_to')).toBe('2026-01-31');
    expect(p.get('date_preset')).toBe('this_month');

    expect([...serializeRunFilters({})]).toEqual([]);
    // Empty entries within an array are skipped.
    expect([...serializeRunFilters({ state: ['', undefined as never] })]).toEqual([]);
  });

  it('serializeRunFilters: tolerates a LEGACY single scalar for state/origin/workflow_id', () => {
    // An older saved view / deep link `?state=failed` (a bare string, not an array).
    const p = serializeRunFilters({
      state: 'failed' as unknown as string[],
      origin: 'manual' as unknown as string[],
      workflow_id: 'wf-9' as unknown as string[],
    });
    expect(p.getAll('state[]')).toEqual(['failed']);
    expect(p.getAll('origin[]')).toEqual(['manual']);
    expect(p.getAll('workflow_id[]')).toEqual(['wf-9']);
  });

  it('fetchRuns sends state[] + origin[] arrays, scopes to the workflow, tracks cursor/hasMore (no total)', async () => {
    const store = useWorkflowRunsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [run({ id: 'a' }), run({ id: 'b' })],
      meta: { next_cursor: 'cur2' },
    });

    await store.fetchRuns('w1', { state: ['completed'], origin: ['manual'] });

    expect(lastGetPath()).toBe('/workflows/w1/runs');
    const params = lastGetSearchParams();
    expect(params.getAll('state[]')).toEqual(['completed']);
    expect(params.getAll('origin[]')).toEqual(['manual']);
    expect(store.items.map((r) => r.id)).toEqual(['a', 'b']);
    expect(store.cursor).toBe('cur2');
    expect(store.hasMore).toBe(true);
    expect(store.workflowId).toBe('w1');
    expect(store.scope).toBe('workflow');
    expect((store as unknown as Record<string, unknown>).total).toBeUndefined();
  });

  it('fetchRuns GLOBAL scope hits /workflows/runs, sends workflow_id + dates, workflowId stays null', async () => {
    const store = useWorkflowRunsStore();
    apiMock.get.mockResolvedValueOnce({
      data: [run({ id: 'g', workflow: { id: 'wf-3', name: 'Nightly', icon: null, status: 'active', trigger_type: 'schedule' } })],
      meta: { next_cursor: null },
    });

    await store.fetchRuns(null, { workflow_id: ['wf-3'], date_from: '2026-02-01', date_to: '2026-02-28' }, { scope: 'global' });

    expect(lastGetPath()).toBe('/workflows/runs');
    const params = lastGetSearchParams();
    expect(params.getAll('workflow_id[]')).toEqual(['wf-3']);
    expect(params.get('date_from')).toBe('2026-02-01');
    expect(params.get('date_to')).toBe('2026-02-28');
    expect(store.workflowId).toBeNull();
    expect(store.scope).toBe('global');
    expect(store.items[0].workflow?.name).toBe('Nightly');
  });

  it('a filter change resets the list + cursor + workflowId', async () => {
    const store = useWorkflowRunsStore();
    apiMock.get.mockResolvedValueOnce({ data: [run({ id: 'a' })], meta: { next_cursor: 'cur2' } });
    await store.fetchRuns('w1', {});

    apiMock.get.mockResolvedValueOnce({ data: [run({ id: 'z' })], meta: { next_cursor: null } });
    await store.fetchRuns('w1', { state: ['failed'] });

    expect(store.items.map((r) => r.id)).toEqual(['z']);
    expect(lastGetSearchParams().getAll('state[]')).toEqual(['failed']);
    expect(store.hasMore).toBe(false);
  });

  it('loadMore appends, sends the cursor, and tracks hasMore=false at the end', async () => {
    const store = useWorkflowRunsStore();
    apiMock.get.mockResolvedValueOnce({ data: [run({ id: 'a' })], meta: { next_cursor: 'cur2' } });
    await store.fetchRuns('w1', {});

    apiMock.get.mockResolvedValueOnce({ data: [run({ id: 'b' })], meta: { next_cursor: null } });
    await store.loadMore('w1', {});

    expect(store.items.map((r) => r.id)).toEqual(['a', 'b']);
    expect(lastGetParams().cursor).toBe('cur2');
    expect(store.hasMore).toBe(false);
  });

  it('an append failure is retryable: keeps the list + cursor + hasMore, pauses then re-fetches', async () => {
    const store = useWorkflowRunsStore();
    apiMock.get.mockResolvedValueOnce({ data: [run({ id: 'a' })], meta: { next_cursor: 'cur2' } });
    await store.fetchRuns('w1', {});

    apiMock.get.mockRejectedValueOnce({ response: { status: 500, data: { message: 'boom' } } });
    await store.loadMore('w1', {});

    expect(store.errored).toBe(false);
    expect(store.loadMoreErrored).toBe(true);
    expect(store.hasMore).toBe(true);
    expect(store.cursor).toBe('cur2');
    expect(store.items.map((r) => r.id)).toEqual(['a']);

    apiMock.get.mockResolvedValueOnce({ data: [run({ id: 'b' })], meta: { next_cursor: null } });
    await store.retryLoadMore('w1', {});

    expect(lastGetParams().cursor).toBe('cur2');
    expect(store.loadMoreErrored).toBe(false);
    expect(store.items.map((r) => r.id)).toEqual(['a', 'b']);
    expect(store.hasMore).toBe(false);
  });

  it('drops a superseded in-flight page when a newer reset arrives (token guard)', async () => {
    const store = useWorkflowRunsStore();
    let resolveFirst!: (v: unknown) => void;
    apiMock.get.mockReturnValueOnce(
      new Promise((res) => {
        resolveFirst = res;
      }),
    );
    const first = store.fetchRuns('w1', { state: ['pending'] });

    apiMock.get.mockResolvedValueOnce({ data: [run({ id: 'new' })], meta: { next_cursor: null } });
    await store.fetchRuns('w1', { state: ['completed'] });

    resolveFirst({ data: [run({ id: 'stale' })], meta: { next_cursor: null } });
    await first;

    expect(store.items.map((r) => r.id)).toEqual(['new']);
  });

  it('resetAll clears list + detail state', async () => {
    const store = useWorkflowRunsStore();
    apiMock.get.mockResolvedValueOnce({ data: [run({ id: 'a' })], meta: { next_cursor: 'cur2' } });
    await store.fetchRuns('w1', {});

    store.resetAll();

    expect(store.items).toEqual([]);
    expect(store.cursor).toBeNull();
    expect(store.hasMore).toBe(true);
    expect(store.workflowId).toBeNull();
    expect(store.detail).toBeNull();
  });

  it('fetchRun hits the nested run route and caches the detail (with steps)', async () => {
    const store = useWorkflowRunsStore();
    apiMock.get.mockResolvedValueOnce({
      data: run({
        id: 'r9',
        trigger_payload: { 'task.id': 'task-42' },
        steps: [
          { id: 's1', position: 1, type: 'create_task', key: 'make', status: 'succeeded', status_label: 'Succeeded', status_tone: 'success', payload: { task_id: 'task-42' }, error: null, created_at: null },
        ],
      }),
    });

    const result = await store.fetchRun('w1', 'r9');

    expect(lastGetPath()).toBe('/workflows/w1/runs/r9');
    expect(result?.id).toBe('r9');
    expect(store.detail?.steps?.[0].key).toBe('make');
    expect(store.detailError).toBeNull();
  });

  it('fetchRun returns null + sets detailError for a cross-workflow run (404)', async () => {
    const store = useWorkflowRunsStore();
    apiMock.get.mockRejectedValueOnce({ response: { status: 404, data: { message: 'Not found' } } });

    const result = await store.fetchRun('w1', 'r-from-other-workflow');

    expect(result).toBeNull();
    expect(store.detail).toBeNull();
    expect(store.detailError).toBe('Not found');
  });
});
