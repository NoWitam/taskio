// Tasks store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the task BROWSE experience, keyed per status (the list
// API is one request per status). The PAGE owns the filter state and passes it
// in; this store only fetches, appends, and tracks cursors/totals/loading/error.
//
// Backend contract (verified — do NOT invent fields):
//   GET /api/tasks?status=<status>&cursor=<cursor>&search=&priority=
//       &user_id[]=&labels[]=&label_operator=AND|OR
//       &date_from=&date_to=&date_preset=&hide_without_deadline=1
//     → { data: TaskListItem[], meta: { next_cursor: string|null, total?: number|null } }
//   `total` is present ONLY on the first page (no cursor).
//
// Filter serialization: scalars are appended as-is; arrays are appended one entry
// per `key[]` (e.g. `user_id[]`, `labels[]`); empty / null / '' values are skipped;
// `hide_without_deadline` is sent as `1`. Page-side filter keys are already the
// backend param names EXCEPT `labelOperator`, which the backend reads as
// `label_operator` (see `FILTER_PARAM_KEYS` + `HasLabels::scopeFilterByLabels`).
//
// Self-contained: NO import from the legacy `resources/js/` (the legacy
// `store/tasks.ts` is reference only).
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  ChangelogEntry,
  ChangelogResponse,
  TaskComment,
  TaskCommentsResponse,
  TaskDetail,
  TaskDetailResponse,
  TaskFilters,
  TaskListItem,
  TaskListResponse,
  TaskStatus,
  TaskWritePayload,
} from '../../pages/tasks/types';

/** Optional flags for a fetch (reset clears the bucket + cursor first). */
interface FetchOptions {
  reset?: boolean;
}

/**
 * Project a full `TaskDetail` down to the `TaskListItem` shape the per-status
 * board/list buckets hold, so a create/update/status-change can keep the lists
 * in sync without a full refetch.
 */
function toListItem(task: TaskDetail): TaskListItem {
  return {
    id: task.id,
    title: task.title,
    description: task.description ?? null,
    priority: task.priority,
    // The list resource formats deadline as `dd.mm.yyyy`; the detail uses ISO.
    // We keep the ISO value here (the card just renders the string); a later
    // list refetch will normalize the format if it matters.
    deadline: task.deadline,
    is_overdue: task.is_overdue,
    is_at_risk: task.is_at_risk,
    assigned: task.assigned,
    labels: task.labels,
    is_in_approval: task.is_in_approval,
  };
}

/**
 * Page-side filter keys that do NOT match their backend query-param name 1:1.
 * Every other key is already the backend param (`user_id`, `labels`, `date_*`,
 * `hide_without_deadline`, …); the camelCase `labelOperator` is the exception —
 * the backend reads it as `label_operator` (`TaskService::listQuery` →
 * `HasLabels::scopeFilterByLabels`). Without this map the operator is dropped and
 * multi-label filtering silently falls back to the backend default (`OR`).
 */
const FILTER_PARAM_KEYS: Partial<Record<keyof TaskFilters, string>> = {
  labelOperator: 'label_operator',
};

/**
 * Serialize the page's filter object into URLSearchParams using the `key[]`
 * convention for arrays. Returns the params (status + cursor are added by the
 * caller). Skips undefined / null / '' and empty arrays. Keys are mapped to their
 * backend param name via `FILTER_PARAM_KEYS` (see above).
 */
function serializeFilters(filters: TaskFilters): URLSearchParams {
  const params = new URLSearchParams();

  (Object.entries(filters) as Array<[keyof TaskFilters, unknown]>).forEach(
    ([key, value]) => {
      if (value === undefined || value === null || value === '') return;

      const paramKey = FILTER_PARAM_KEYS[key] ?? key;

      if (Array.isArray(value)) {
        if (value.length === 0) return;
        value.forEach((element) => params.append(`${paramKey}[]`, String(element)));
        return;
      }

      if (typeof value === 'boolean') {
        // hide_without_deadline → send `1` when on, omit when off.
        if (value) params.append(paramKey, '1');
        return;
      }

      params.append(paramKey, String(value));
    },
  );

  return params;
}

export const useTasksStore = defineStore('next-tasks', () => {
  // --- State (per status) --------------------------------------------------
  const itemsByStatus = ref<Record<string, TaskListItem[]>>({});
  const cursorByStatus = ref<Record<string, string | null>>({});
  const hasMoreByStatus = ref<Record<string, boolean>>({});
  const totalByStatus = ref<Record<string, number | null>>({});
  const loadingByStatus = ref<Record<string, boolean>>({});
  /** The last status that errored carries the message; cleared on retry. */
  const errorByStatus = ref<Record<string, boolean>>({});
  /** A single human-readable error string for the most recent failure. */
  const error = ref<string | null>(null);

  // Per-status request token: a reset always supersedes work in flight so rapid
  // filter changes can never leave stale pages.
  const tokens: Record<string, number> = {};

  // --- Detail (single task) ------------------------------------------------
  const detail = ref<TaskDetail | null>(null);
  const detailLoading = ref(false);
  const detailError = ref<string | null>(null);

  // --- Comments (cursor-paginated, per the open task) ----------------------
  const comments = ref<TaskComment[]>([]);
  const commentsCursor = ref<string | null>(null);
  const commentsHasMore = ref(true);
  const commentsLoading = ref(false);
  const commentsError = ref<string | null>(null);
  let commentsToken = 0;

  // --- Changelog (cursor-paginated) ----------------------------------------
  const changelog = ref<ChangelogEntry[]>([]);
  const changelogCursor = ref<string | null>(null);
  const changelogHasMore = ref(true);
  const changelogLoading = ref(false);
  const changelogLoadingMore = ref(false);
  const changelogError = ref<string | null>(null);
  let changelogToken = 0;

  // --- Getters (plain helpers; the page reads the maps directly) -----------
  function itemsFor(status: TaskStatus): TaskListItem[] {
    return itemsByStatus.value[status] ?? [];
  }
  function hasMoreFor(status: TaskStatus): boolean {
    return hasMoreByStatus.value[status] ?? true;
  }
  function totalFor(status: TaskStatus): number | null {
    return totalByStatus.value[status] ?? null;
  }
  function isLoading(status: TaskStatus): boolean {
    return loadingByStatus.value[status] ?? false;
  }
  function hasError(status: TaskStatus): boolean {
    return errorByStatus.value[status] ?? false;
  }

  // --- Actions -------------------------------------------------------------
  /**
   * Fetch one page of tasks for `status` with the given `filters`. With
   * `{ reset: true }` (the default for a filter change) the bucket + cursor are
   * cleared first and `total` is captured; otherwise the page is appended for
   * infinite scroll. Builds the query incl. `key[]` arrays.
   */
  async function fetchByStatus(
    status: TaskStatus,
    filters: TaskFilters = {},
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    // Appends are no-ops while a page is in flight or when exhausted.
    if (!reset && (loadingByStatus.value[status] || !hasMoreFor(status))) return;

    const token = (tokens[status] = (tokens[status] ?? 0) + 1);
    loadingByStatus.value[status] = true;
    errorByStatus.value[status] = false;
    error.value = null;

    if (reset) {
      itemsByStatus.value[status] = [];
      cursorByStatus.value[status] = null;
      hasMoreByStatus.value[status] = true;
    }

    try {
      const params = serializeFilters(filters);
      params.set('status', status);

      const cursor = cursorByStatus.value[status];
      if (cursor && !reset) params.set('cursor', cursor);

      const response = await api.get<TaskListResponse>(`/tasks?${params.toString()}`);
      if (token !== tokens[status]) return; // superseded by a newer reset

      const incoming = response.data ?? [];
      itemsByStatus.value[status] = reset
        ? incoming
        : [...(itemsByStatus.value[status] ?? []), ...incoming];

      cursorByStatus.value[status] = response.meta?.next_cursor ?? null;
      hasMoreByStatus.value[status] = (response.meta?.next_cursor ?? null) !== null;

      // `total` only ships on the first page; keep the captured value otherwise.
      if (
        response.meta &&
        Object.prototype.hasOwnProperty.call(response.meta, 'total') &&
        response.meta.total != null
      ) {
        totalByStatus.value[status] = response.meta.total;
      }
    } catch (err: unknown) {
      if (token !== tokens[status]) return;
      errorByStatus.value[status] = true;
      error.value = extractMessage(err);
      // Stop infinite scroll from hammering a failing endpoint.
      hasMoreByStatus.value[status] = false;
    } finally {
      if (token === tokens[status]) loadingByStatus.value[status] = false;
    }
  }

  /** Append the next page for `status` (infinite scroll). */
  async function loadMore(status: TaskStatus, filters: TaskFilters = {}): Promise<void> {
    await fetchByStatus(status, filters, { reset: false });
  }

  /** Drop all cached state (e.g. when filters change wholesale). */
  function resetAll(): void {
    itemsByStatus.value = {};
    cursorByStatus.value = {};
    hasMoreByStatus.value = {};
    totalByStatus.value = {};
    loadingByStatus.value = {};
    errorByStatus.value = {};
    error.value = null;
  }

  // --- List-bucket mutations (keep board/list in sync without a refetch) ----
  /** Remove a task id from every status bucket; decrement that bucket's total. */
  function removeFromLists(id: string | number): void {
    Object.keys(itemsByStatus.value).forEach((status) => {
      const before = itemsByStatus.value[status] ?? [];
      const after = before.filter((t) => String(t.id) !== String(id));
      if (after.length !== before.length) {
        itemsByStatus.value[status] = after;
        const total = totalByStatus.value[status];
        if (total != null) totalByStatus.value[status] = Math.max(0, total - 1);
      }
    });
  }

  /**
   * Insert/replace a task into the bucket matching its status, removing it from
   * any other bucket it was in (a move). Prepends on insert and adjusts totals.
   * Buckets that have never been fetched (undefined) are left untouched so a
   * later first fetch is authoritative.
   */
  function upsertIntoLists(task: TaskDetail): void {
    const item = toListItem(task);
    const target = task.status;

    // Drop the task from any bucket that is NOT its current status.
    Object.keys(itemsByStatus.value).forEach((status) => {
      if (status === target) return;
      const before = itemsByStatus.value[status] ?? [];
      const after = before.filter((t) => String(t.id) !== String(item.id));
      if (after.length !== before.length) {
        itemsByStatus.value[status] = after;
        const total = totalByStatus.value[status];
        if (total != null) totalByStatus.value[status] = Math.max(0, total - 1);
      }
    });

    // Only touch the target bucket if it has been initialized (fetched at least
    // once); otherwise let its first fetch populate it authoritatively.
    const current = itemsByStatus.value[target];
    if (current === undefined) return;

    const idx = current.findIndex((t) => String(t.id) === String(item.id));
    if (idx >= 0) {
      // Replace in place (an update that kept the same status).
      const next = [...current];
      next[idx] = item;
      itemsByStatus.value[target] = next;
    } else {
      // New arrival in this bucket → prepend + bump the total.
      itemsByStatus.value[target] = [item, ...current];
      const total = totalByStatus.value[target];
      if (total != null) totalByStatus.value[target] = total + 1;
    }
  }

  // --- Detail + CRUD + status ----------------------------------------------
  /** Fetch the FULL task for the detail Drawer (`GET /api/tasks/{id}`). */
  async function fetchTask(id: string | number): Promise<TaskDetail | null> {
    detailLoading.value = true;
    detailError.value = null;
    try {
      const res = await api.get<TaskDetailResponse>(`/tasks/${id}`);
      detail.value = res.data;
      return res.data;
    } catch (err: unknown) {
      detailError.value = extractMessage(err);
      return null;
    } finally {
      detailLoading.value = false;
    }
  }

  /** Create a task (`POST /api/tasks`). Inserts it into the matching bucket. */
  async function createTask(payload: TaskWritePayload): Promise<TaskDetail> {
    const res = await api.post<TaskDetailResponse>('/tasks', payload);
    upsertIntoLists(res.data);
    return res.data;
  }

  /** Update a task (`PUT /api/tasks/{id}`). Reconciles the buckets. */
  async function updateTask(
    id: string | number,
    payload: TaskWritePayload,
  ): Promise<TaskDetail> {
    const res = await api.put<TaskDetailResponse>(`/tasks/${id}`, payload);
    upsertIntoLists(res.data);
    if (detail.value && String(detail.value.id) === String(id)) {
      detail.value = res.data;
    }
    return res.data;
  }

  /** Remove a single attachment from a task (`DELETE /api/tasks/{id}/attachments/{fileId}`). */
  async function removeAttachment(
    id: string | number,
    fileId: string | number,
  ): Promise<TaskDetail> {
    const res = await api.delete<TaskDetailResponse>(`/tasks/${id}/attachments/${fileId}`);
    upsertIntoLists(res.data);
    if (detail.value && String(detail.value.id) === String(id)) {
      detail.value = res.data;
    }
    return res.data;
  }

  /** Move a task to trash (`DELETE /api/tasks/{id}`). */
  async function deleteTask(id: string | number): Promise<void> {
    await api.delete(`/tasks/${id}`);
    removeFromLists(id);
    if (detail.value && String(detail.value.id) === String(id)) {
      detail.value = { ...detail.value, status: 'trash' };
    }
  }

  /** Permanently delete a trashed task (`DELETE /api/tasks/{id}/force`). */
  async function forceDeleteTask(id: string | number): Promise<void> {
    await api.delete(`/tasks/${id}/force`);
    removeFromLists(id);
  }

  /** Restore a trashed task (`POST /api/tasks/{id}/restore`). */
  async function restoreTask(id: string | number): Promise<TaskDetail> {
    const res = await api.post<TaskDetailResponse>(`/tasks/${id}/restore`);
    upsertIntoLists(res.data);
    if (detail.value && String(detail.value.id) === String(id)) {
      detail.value = res.data;
    }
    return res.data;
  }

  /** Change a task's status (`PATCH /api/tasks/{id}/status/{status}`). */
  async function changeStatus(
    id: string | number,
    status: TaskStatus,
  ): Promise<TaskDetail> {
    const res = await api.patch<TaskDetailResponse>(`/tasks/${id}/status/${status}`);
    upsertIntoLists(res.data);
    if (detail.value && String(detail.value.id) === String(id)) {
      detail.value = res.data;
    }
    return res.data;
  }

  /**
   * Submit (create-or-update) the task's form answers
   * (`POST /api/tasks/{id}/form-submission` with `{ data }`). The backend returns a
   * full TaskResource (with the refreshed `form_submission`) and does NOT change
   * the task status — so we reconcile exactly like `updateTask`: replace the open
   * `detail` and replace the row in place in its (unchanged) status bucket. Returns
   * the updated TaskDetail.
   */
  async function submitTaskForm(
    id: string | number,
    data: Record<string, unknown>,
  ): Promise<TaskDetail> {
    const res = await api.post<TaskDetailResponse>(`/tasks/${id}/form-submission`, {
      data,
    });
    upsertIntoLists(res.data);
    if (detail.value && String(detail.value.id) === String(id)) {
      detail.value = res.data;
    }
    return res.data;
  }

  // --- Comments ------------------------------------------------------------
  /**
   * Fetch a page of comments for a task (`GET /api/tasks/{id}/comments`).
   * With `{ reset: true }` (default) the list + cursor are cleared first.
   */
  async function fetchComments(
    id: string | number,
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    if (!reset && (commentsLoading.value || !commentsHasMore.value)) return;

    const token = (commentsToken += 1);
    commentsLoading.value = true;
    commentsError.value = null;
    if (reset) {
      comments.value = [];
      commentsCursor.value = null;
      commentsHasMore.value = true;
    }
    try {
      const params = new URLSearchParams();
      const cursor = commentsCursor.value;
      if (cursor && !reset) params.set('cursor', cursor);
      const qs = params.toString();
      const res = await api.get<TaskCommentsResponse>(
        `/tasks/${id}/comments${qs ? `?${qs}` : ''}`,
      );
      if (token !== commentsToken) return;

      const incoming = res.data ?? [];
      comments.value = reset ? incoming : [...comments.value, ...incoming];
      commentsCursor.value = res.meta?.next_cursor ?? null;
      commentsHasMore.value = (res.meta?.next_cursor ?? null) !== null;
    } catch (err: unknown) {
      if (token !== commentsToken) return;
      commentsError.value = extractMessage(err);
      commentsHasMore.value = false;
    } finally {
      if (token === commentsToken) commentsLoading.value = false;
    }
  }

  /** Append the next page of comments. */
  async function loadMoreComments(id: string | number): Promise<void> {
    await fetchComments(id, { reset: false });
  }

  /** Add a comment (`POST /api/tasks/{id}/comments`). Prepends (DESC order). */
  async function addComment(
    id: string | number,
    content: string,
  ): Promise<TaskComment> {
    const res = await api.post<{ data: TaskComment }>(`/tasks/${id}/comments`, {
      content,
    });
    comments.value = [res.data, ...comments.value];
    return res.data;
  }

  /** Edit a comment (`PATCH /api/comments/{id}`). */
  async function updateComment(
    commentId: string | number,
    content: string,
  ): Promise<TaskComment> {
    const res = await api.patch<{ data: TaskComment }>(`/comments/${commentId}`, {
      content,
    });
    const idx = comments.value.findIndex((c) => String(c.id) === String(commentId));
    if (idx >= 0) {
      const next = [...comments.value];
      next[idx] = res.data;
      comments.value = next;
    }
    return res.data;
  }

  /** Delete a comment (`DELETE /api/comments/{id}`). */
  async function deleteComment(commentId: string | number): Promise<void> {
    await api.delete(`/comments/${commentId}`);
    comments.value = comments.value.filter(
      (c) => String(c.id) !== String(commentId),
    );
  }

  // --- Changelog (optional) ------------------------------------------------
  /** Fetch the task changelog (`GET /api/task/{id}/changelog`, morph alias `task`). */
  async function fetchChangelog(
    id: string | number,
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    if (!reset && (changelogLoadingMore.value || !changelogHasMore.value)) return;

    const token = (changelogToken += 1);
    if (reset) {
      changelogLoading.value = true;
      changelog.value = [];
      changelogCursor.value = null;
      changelogHasMore.value = true;
    } else {
      changelogLoadingMore.value = true;
    }
    changelogError.value = null;
    try {
      const params = new URLSearchParams();
      const cursor = changelogCursor.value;
      if (cursor && !reset) params.set('cursor', cursor);
      const qs = params.toString();
      const res = await api.get<ChangelogResponse>(
        `/task/${id}/changelog${qs ? `?${qs}` : ''}`,
      );
      if (token !== changelogToken) return;

      const incoming = res.data ?? [];
      changelog.value = reset ? incoming : [...changelog.value, ...incoming];
      changelogCursor.value = res.meta?.next_cursor ?? null;
      changelogHasMore.value = (res.meta?.next_cursor ?? null) !== null;
    } catch (err: unknown) {
      if (token !== changelogToken) return;
      changelogError.value = extractMessage(err);
      changelogHasMore.value = false;
    } finally {
      if (token === changelogToken) {
        changelogLoading.value = false;
        changelogLoadingMore.value = false;
      }
    }
  }

  /** Append the next page of changelog entries. */
  async function loadMoreChangelog(id: string | number): Promise<void> {
    await fetchChangelog(id, { reset: false });
  }

  /** Clear the detail/comment/changelog state (e.g. on Drawer close). */
  function clearDetail(): void {
    detail.value = null;
    detailError.value = null;
    comments.value = [];
    commentsCursor.value = null;
    commentsHasMore.value = true;
    commentsError.value = null;
    changelog.value = [];
    changelogCursor.value = null;
    changelogHasMore.value = true;
    changelogError.value = null;
  }

  function extractMessage(err: unknown): string {
    const res = (err as { response?: { data?: { message?: string } } })?.response;
    return res?.data?.message ?? 'tasks.error.fetch';
  }

  return {
    // state
    itemsByStatus,
    cursorByStatus,
    hasMoreByStatus,
    totalByStatus,
    loadingByStatus,
    errorByStatus,
    error,
    // detail state
    detail,
    detailLoading,
    detailError,
    // comments state
    comments,
    commentsCursor,
    commentsHasMore,
    commentsLoading,
    commentsError,
    // changelog state
    changelog,
    changelogLoading,
    changelogLoadingMore,
    changelogHasMore,
    changelogError,
    // getters
    itemsFor,
    hasMoreFor,
    totalFor,
    isLoading,
    hasError,
    // list actions
    fetchByStatus,
    loadMore,
    resetAll,
    // list mutations (exposed for tests / advanced callers)
    upsertIntoLists,
    removeFromLists,
    // detail + CRUD + status
    fetchTask,
    createTask,
    updateTask,
    removeAttachment,
    deleteTask,
    forceDeleteTask,
    restoreTask,
    changeStatus,
    submitTaskForm,
    clearDetail,
    // comments
    fetchComments,
    loadMoreComments,
    addComment,
    updateComment,
    deleteComment,
    // changelog
    fetchChangelog,
    loadMoreChangelog,
  };
});
