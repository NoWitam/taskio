// Workflow RUNS store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for one workflow's RUN monitoring (the detail's Runs section,
// Batch 6c): a cursor-paginated runs list filtered by `state`/`origin`, plus a
// single-run detail fetch (the step timeline). The runs list is scoped to ONE
// workflow id, so a filter/workflow change resets the list. Mirrors the
// request-token guard + retryable-append pattern of the workflows/bots stores.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET /workflows/{id}/runs?state=&origin=&cursor=  (cursorPaginate(15))
//     → { data: WorkflowRun[], meta: { next_cursor } }   NO `total`.
//   GET /workflows/{id}/runs/{run}  → { data: WorkflowRun (+ trigger_payload + steps) }
//
// This slice (6a) DEFINES the store; the Runs view + run-detail drawer that consume
// it ship in 6c. Self-contained: NO import from the legacy `resources/js/`.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  WorkflowRun,
  WorkflowRunFilters,
  WorkflowRunListResponse,
  WorkflowRunResponse,
} from '../../pages/workflows/types';

interface FetchOptions {
  reset?: boolean;
}

/**
 * Serialize the runs-list filter object into URLSearchParams. Server filters are
 * `state` and `origin`; undefined / null / '' are skipped. (cursor added by caller.)
 */
export function serializeRunFilters(filters: WorkflowRunFilters): URLSearchParams {
  const params = new URLSearchParams();
  if (filters.state != null && (filters.state as string) !== '') {
    params.append('state', String(filters.state));
  }
  if (filters.origin != null && (filters.origin as string) !== '') {
    params.append('origin', String(filters.origin));
  }
  return params;
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
  /** The workflow the currently-loaded list belongs to (guards cross-workflow reuse). */
  const workflowId = ref<string | null>(null);

  let token = 0;

  // --- Run detail (single run) cache ---------------------------------------
  const detail = ref<WorkflowRun | null>(null);
  const detailLoading = ref(false);
  const detailError = ref<string | null>(null);

  // --- List actions --------------------------------------------------------
  /**
   * Fetch one page of a workflow's runs. With `{ reset: true }` (the default for a
   * filter/workflow change) the list + cursor are cleared first; otherwise the
   * page is appended for infinite scroll. Always scoped to `id`.
   */
  async function fetchRuns(
    id: string,
    filters: WorkflowRunFilters = {},
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    if (!reset && (loadingMore.value || loading.value || !hasMore.value)) return;

    const myToken = (token += 1);
    if (reset) {
      loading.value = true;
      items.value = [];
      cursor.value = null;
      hasMore.value = true;
      workflowId.value = id;
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
        `/workflows/${id}/runs${qs ? `?${qs}` : ''}`,
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

  /** Append the next page (infinite scroll). */
  async function loadMore(id: string, filters: WorkflowRunFilters = {}): Promise<void> {
    await fetchRuns(id, filters, { reset: false });
  }

  /** Retry a failed append (clears the pause flag, re-fetches the same page). */
  async function retryLoadMore(id: string, filters: WorkflowRunFilters = {}): Promise<void> {
    loadMoreErrored.value = false;
    await fetchRuns(id, filters, { reset: false });
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
  };
});
