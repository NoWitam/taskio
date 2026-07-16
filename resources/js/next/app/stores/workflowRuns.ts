// Workflow RUNS store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for workflow RUN monitoring: a cursor-paginated runs list (the
// per-workflow detail's Runs section AND the cross-workflow global Runs list) filtered
// by `state[]`/`origin[]`/`trigger_type[]` + a date range (and `workflow_id` on the
// global feed), plus a single-run detail fetch (the step timeline). This is a SINGLETON
// store shared by both list surfaces, so the loaded page is keyed by `scope`; a
// filter/scope/route change resets the list. Mirrors the request-token guard +
// retryable-append pattern of the workflows/bots stores.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET /workflows/{id}/runs?state[]=&origin[]=&trigger_type[]=&date_from=&date_to=&date_preset=&cursor=
//   GET /workflows/runs?...same filters + workflow_id[]=  (both cursorPaginate(15))
//     → { data: WorkflowRun[], meta: { next_cursor } }   NO `total`.
//     The GLOBAL feed adds `workflow { id, name, icon, status, trigger_type }` per row.
//   GET /workflows/{id}/runs/{run}  → { data: WorkflowRun (+ trigger_payload + steps) }
//
// Self-contained: NO import from the legacy `resources/js/`.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  WorkflowRun,
  WorkflowRunFilters,
  WorkflowRunListResponse,
  WorkflowRunResponse,
} from '../../pages/workflows/types';

/** Which feed a fetch targets: the per-workflow list or the cross-workflow list. */
export type WorkflowRunScope = 'workflow' | 'global';

interface FetchOptions {
  reset?: boolean;
  /** `'workflow'` → `/workflows/{id}/runs`; `'global'` → `/workflows/runs`. */
  scope?: WorkflowRunScope;
}

/**
 * Append a multi-value filter as REPEATED array params (`key[]=a&key[]=b`), the shape
 * the backend list expects. A legacy single scalar (an older saved view / deep link
 * `?state=failed`) is tolerated by wrapping it into a one-element list. Empty / null
 * entries are skipped.
 */
function appendArrayParam(params: URLSearchParams, key: string, value: string[] | string | undefined): void {
  if (value == null) return;
  const list = Array.isArray(value) ? value : [value];
  for (const v of list) {
    if (v != null && String(v) !== '') params.append(`${key}[]`, String(v));
  }
}

/**
 * Serialize the runs-list filter object into URLSearchParams. `state[]` / `origin[]` /
 * `trigger_type[]` / `workflow_id[]` are repeated array params (tolerating a legacy
 * scalar for each); `workflow_id[]` is honored by the global feed only; `date_from` /
 * `date_to` / `date_preset` are scalars. undefined / null / '' are skipped. (cursor is
 * added by the caller.)
 */
export function serializeRunFilters(filters: WorkflowRunFilters): URLSearchParams {
  const params = new URLSearchParams();
  appendArrayParam(params, 'state', filters.state);
  appendArrayParam(params, 'origin', filters.origin);
  appendArrayParam(params, 'trigger_type', filters.trigger_type);
  appendArrayParam(params, 'workflow_id', filters.workflow_id);
  if (filters.date_from != null && filters.date_from !== '') {
    params.append('date_from', String(filters.date_from));
  }
  if (filters.date_to != null && filters.date_to !== '') {
    params.append('date_to', String(filters.date_to));
  }
  if (filters.date_preset != null && filters.date_preset !== '') {
    params.append('date_preset', String(filters.date_preset));
  }
  return params;
}

/** Build the runs endpoint for a scope: per-workflow vs the global cross-workflow feed. */
function runsPath(id: string | null, scope: WorkflowRunScope): string {
  return scope === 'global' ? '/workflows/runs' : `/workflows/${id}/runs`;
}

function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'workflows.runs.loadError';
}

export const useWorkflowRunsStore = defineStore('next-workflow-runs', () => {
  // --- List state ----------------------------------------------------------
  const items = ref<WorkflowRun[]>([]);
  const cursor = ref<string | null>(null);
  const hasMore = ref(true);
  const loading = ref(false);
  const loadingMore = ref(false);
  const errored = ref(false);
  const error = ref<string | null>(null);
  const loadMoreErrored = ref(false);
  /** The workflow the currently-loaded list belongs to (null on the global feed). */
  const workflowId = ref<string | null>(null);
  /**
   * The SCOPE the currently-loaded list belongs to. This is a SINGLETON store shared by
   * the per-workflow Runs section and the global Runs list, so the loaded page is keyed
   * by scope: an append (loadMore) whose scope no longer matches the loaded one is
   * dropped, and every view resets the store on mount/route change so a global page can
   * never bleed into a per-workflow view (or vice-versa).
   */
  const scope = ref<WorkflowRunScope>('workflow');

  let token = 0;

  // --- Run detail (single run) cache ---------------------------------------
  const detail = ref<WorkflowRun | null>(null);
  const detailLoading = ref(false);
  const detailError = ref<string | null>(null);

  // --- List actions --------------------------------------------------------
  /**
   * Fetch one page of runs. `scope` selects the feed: `'workflow'` (default) hits
   * `/workflows/{id}/runs`; `'global'` hits `/workflows/runs` (and ignores `id`). With
   * `{ reset: true }` (the default for a filter/scope change) the list + cursor are
   * cleared first; otherwise the page is appended for infinite scroll. An append is
   * dropped when its scope no longer matches the loaded one (singleton-store guard).
   */
  async function fetchRuns(
    id: string | null,
    filters: WorkflowRunFilters = {},
    { reset = true, scope: fetchScope = 'workflow' }: FetchOptions = {},
  ): Promise<void> {
    if (!reset && (loadingMore.value || loading.value || !hasMore.value)) return;
    // A stray append from a view whose scope changed under it must not mutate the list.
    if (!reset && scope.value !== fetchScope) return;

    const myToken = (token += 1);
    if (reset) {
      loading.value = true;
      items.value = [];
      cursor.value = null;
      hasMore.value = true;
      scope.value = fetchScope;
      workflowId.value = fetchScope === 'workflow' ? id : null;
    } else {
      loadingMore.value = true;
    }
    errored.value = false;
    error.value = null;
    loadMoreErrored.value = false;

    try {
      const params = serializeRunFilters(filters);
      if (cursor.value && !reset) params.set('cursor', cursor.value);

      const qs = params.toString();
      const response = await api.get<WorkflowRunListResponse>(
        `${runsPath(id, fetchScope)}${qs ? `?${qs}` : ''}`,
      );
      if (myToken !== token) return; // superseded by a newer reset

      const incoming = response.data ?? [];
      items.value = reset ? incoming : [...items.value, ...incoming];

      cursor.value = response.meta?.next_cursor ?? null;
      hasMore.value = (response.meta?.next_cursor ?? null) !== null;
    } catch (err: unknown) {
      if (myToken !== token) return;
      error.value = extractMessage(err);
      if (reset) {
        errored.value = true;
        hasMore.value = false;
      } else {
        loadMoreErrored.value = true;
      }
    } finally {
      if (myToken === token) {
        loading.value = false;
        loadingMore.value = false;
      }
    }
  }

  /** Append the next page (infinite scroll). `scope` must match the loaded feed. */
  async function loadMore(
    id: string | null,
    filters: WorkflowRunFilters = {},
    { scope: fetchScope = 'workflow' }: { scope?: WorkflowRunScope } = {},
  ): Promise<void> {
    await fetchRuns(id, filters, { reset: false, scope: fetchScope });
  }

  /** Retry a failed append (clears the pause flag, re-fetches the same page). */
  async function retryLoadMore(
    id: string | null,
    filters: WorkflowRunFilters = {},
    { scope: fetchScope = 'workflow' }: { scope?: WorkflowRunScope } = {},
  ): Promise<void> {
    loadMoreErrored.value = false;
    await fetchRuns(id, filters, { reset: false, scope: fetchScope });
  }

  /** Drop all cached runs + detail state. */
  function resetAll(): void {
    items.value = [];
    cursor.value = null;
    hasMore.value = true;
    loading.value = false;
    loadingMore.value = false;
    errored.value = false;
    error.value = null;
    loadMoreErrored.value = false;
    workflowId.value = null;
    scope.value = 'workflow';
    detail.value = null;
    detailLoading.value = false;
    detailError.value = null;
  }

  // --- Run detail ----------------------------------------------------------
  /**
   * Fetch a single run with its step timeline
   * (`GET /workflows/{id}/runs/{runId}`). The workflow id is required because the
   * run route is nested under its workflow.
   */
  async function fetchRun(id: string, runId: string): Promise<WorkflowRun | null> {
    detailLoading.value = true;
    detailError.value = null;
    try {
      const res = await api.get<WorkflowRunResponse>(`/workflows/${id}/runs/${runId}`);
      detail.value = res.data;
      return res.data;
    } catch (err: unknown) {
      detailError.value = extractMessage(err);
      return null;
    } finally {
      detailLoading.value = false;
    }
  }

  /**
   * Retry a FAILED run (`POST /workflows/{id}/runs/{runId}/retry`). The backend starts a
   * BRAND-NEW manual run reusing the failed run's stored trigger_payload and answers 202
   * with that new run (state `pending`). Returns the new run on 202; the raw axios error
   * is re-thrown on rejection so the caller can surface the 422 field key (`run` = not in
   * FAILED state, `workflow` = run-budget cap) — NO body is sent.
   */
  async function retryRun(id: string, runId: string): Promise<WorkflowRun> {
    const res = await api.post<WorkflowRunResponse>(`/workflows/${id}/runs/${runId}/retry`);
    return res.data;
  }

  return {
    // list state
    items,
    cursor,
    hasMore,
    loading,
    loadingMore,
    errored,
    error,
    loadMoreErrored,
    workflowId,
    scope,
    // detail state
    detail,
    detailLoading,
    detailError,
    // list actions
    fetchRuns,
    loadMore,
    retryLoadMore,
    resetAll,
    // detail
    fetchRun,
    retryRun,
  };
});
