// @vitest-environment happy-dom
// Unit tests for the "next" tasks store — specifically the per-status list
// reconciliation that keeps the board/list in sync after create / update /
// status-change / delete WITHOUT a full refetch. The api client is mocked so no
// real HTTP happens; we assert the bucket contents + totals after each action.
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
import { useTasksStore } from '../tasks';
import type { TaskDetail } from '../../../pages/tasks/types';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

const USER = { id: 'u1', name: 'Ada' };

function detail(overrides: Partial<TaskDetail> = {}): TaskDetail {
  return {
    id: 't1',
    title: 'Task',
    description: null,
    status: 'to_do',
    priority: 'medium',
    deadline: null,
    deadline_overdue: null,
    is_overdue: false,
    is_at_risk: false,
    attachments: [],
    creator: USER,
    assigned: USER,
    labels: [],
    form_id: null,
    approval_pipeline_id: null,
    is_in_approval: false,
    available_status_transitions: [],
    can_update: true,
    can_delete: true,
    can_restore: true,
    can_force_delete: true,
    approval_run_id: null,
    ...overrides,
  };
}

describe('next tasks store — list reconciliation', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('createTask prepends into the matching (initialized) bucket and bumps the total', async () => {
    const store = useTasksStore();
    // Initialize the `to_do` bucket as if a first page had loaded.
    store.itemsByStatus.to_do = [];
    store.totalByStatus.to_do = 0;

    apiMock.post.mockResolvedValueOnce({ data: detail({ id: 'new', status: 'to_do' }) });
    await store.createTask({ title: 'Task', priority: 'medium', assigned_id: 'u1' });

    expect(store.itemsByStatus.to_do.map((t) => t.id)).toEqual(['new']);
    expect(store.totalByStatus.to_do).toBe(1);
  });

  it('createTask leaves an un-initialized bucket untouched (first fetch is authoritative)', async () => {
    const store = useTasksStore();
    apiMock.post.mockResolvedValueOnce({ data: detail({ id: 'new', status: 'in_progress' }) });
    await store.createTask({ title: 'Task', priority: 'medium', assigned_id: 'u1' });

    expect(store.itemsByStatus.in_progress).toBeUndefined();
  });

  it('changeStatus moves the task between buckets and adjusts both totals', async () => {
    const store = useTasksStore();
    store.itemsByStatus.to_do = [
      { ...detail({ id: 't1' }) } as never,
    ];
    store.totalByStatus.to_do = 1;
    store.itemsByStatus.in_progress = [];
    store.totalByStatus.in_progress = 0;

    apiMock.patch.mockResolvedValueOnce({
      data: detail({ id: 't1', status: 'in_progress' }),
    });
    await store.changeStatus('t1', 'in_progress');

    expect(store.itemsByStatus.to_do.map((t) => t.id)).toEqual([]);
    expect(store.totalByStatus.to_do).toBe(0);
    expect(store.itemsByStatus.in_progress.map((t) => t.id)).toEqual(['t1']);
    expect(store.totalByStatus.in_progress).toBe(1);
  });

  it('updateTask replaces the row in place when the status is unchanged', async () => {
    const store = useTasksStore();
    store.itemsByStatus.to_do = [{ ...detail({ id: 't1', title: 'Old' }) } as never];
    store.totalByStatus.to_do = 1;

    apiMock.put.mockResolvedValueOnce({
      data: detail({ id: 't1', title: 'New', status: 'to_do' }),
    });
    await store.updateTask('t1', { title: 'New', priority: 'medium', assigned_id: 'u1' });

    expect(store.itemsByStatus.to_do).toHaveLength(1);
    expect(store.itemsByStatus.to_do[0].title).toBe('New');
    expect(store.totalByStatus.to_do).toBe(1);
  });

  it('deleteTask removes the task from its bucket and decrements the total', async () => {
    const store = useTasksStore();
    store.itemsByStatus.to_do = [{ ...detail({ id: 't1' }) } as never];
    store.totalByStatus.to_do = 1;

    apiMock.delete.mockResolvedValueOnce({});
    await store.deleteTask('t1');

    expect(store.itemsByStatus.to_do).toEqual([]);
    expect(store.totalByStatus.to_do).toBe(0);
  });

  it('submitTaskForm POSTs { data } to /tasks/{id}/form-submission and replaces detail in place', async () => {
    const store = useTasksStore();
    // The task is open in the detail drawer AND present in its status bucket.
    store.detail = detail({ id: 't1', status: 'in_progress' });
    store.itemsByStatus.in_progress = [{ ...detail({ id: 't1' }) } as never];
    store.totalByStatus.in_progress = 1;

    const answers = { q1: 'hello', q2: 42 };
    const updated = detail({
      id: 't1',
      status: 'in_progress',
      form_submission: { id: 's1', data: answers },
    });
    apiMock.post.mockResolvedValueOnce({ data: updated });

    const result = await store.submitTaskForm('t1', answers);

    // POSTs to the verified endpoint with the `{ data }` envelope.
    expect(apiMock.post).toHaveBeenCalledWith('/tasks/t1/form-submission', {
      data: answers,
    });
    // Returns the updated TaskDetail and replaces the open detail.
    expect(result.id).toBe('t1');
    expect(store.detail?.form_submission).toEqual({ id: 's1', data: answers });
    // Status is unchanged → the row stays in the same bucket (replaced in place).
    expect(store.itemsByStatus.in_progress.map((t) => t.id)).toEqual(['t1']);
    expect(store.totalByStatus.in_progress).toBe(1);
  });

  it('addComment prepends the new comment (DESC order)', async () => {
    const store = useTasksStore();
    store.comments = [
      { id: 'c1', content: 'old', author: USER, created_at: '', updated_at: '', is_edited: false },
    ];
    apiMock.post.mockResolvedValueOnce({
      data: { id: 'c2', content: 'new', author: USER, created_at: '', updated_at: '', is_edited: false },
    });
    await store.addComment('t1', 'new');

    expect(store.comments.map((c) => c.id)).toEqual(['c2', 'c1']);
  });
});

describe('next tasks store — filter serialization', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    apiMock.get.mockResolvedValue({ data: [], meta: { next_cursor: null } });
  });

  /** Parse the query string of the single `api.get` call the fetch made. */
  function lastGetParams(): URLSearchParams {
    expect(apiMock.get).toHaveBeenCalledTimes(1);
    const url = apiMock.get.mock.calls[0][0] as string;
    return new URLSearchParams(url.slice(url.indexOf('?') + 1));
  }

  it('serializes labelOperator as the backend `label_operator` param', async () => {
    const store = useTasksStore();
    await store.fetchByStatus('to_do', {
      labels: ['l1', 'l2'],
      labelOperator: 'AND',
    });

    const params = lastGetParams();
    // The backend reads `label_operator` (HasLabels::scopeFilterByLabels); the
    // camelCase key must NOT leak through or the operator is silently dropped.
    expect(params.get('label_operator')).toBe('AND');
    expect(params.has('labelOperator')).toBe(false);
    // Sanity: the already-snake_case array key is untouched.
    expect(params.getAll('labels[]')).toEqual(['l1', 'l2']);
  });

  it('omits the operator entirely when it is undefined', async () => {
    const store = useTasksStore();
    await store.fetchByStatus('to_do', { labels: ['l1'] });

    const params = lastGetParams();
    expect(params.has('label_operator')).toBe(false);
    expect(params.has('labelOperator')).toBe(false);
  });
});
