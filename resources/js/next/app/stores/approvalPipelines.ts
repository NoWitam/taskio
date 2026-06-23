// Approval Pipelines store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the Approvals → Pipelines BROWSE + builder experience: a
// single cursor-paginated list plus the per-pipeline CRUD actions. The PAGE owns
// the filter state and passes it in; this store fetches, appends, tracks
// cursor/loading/error, and reconciles the list in place after a write so the UI
// updates without a full refetch.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET    /approval-pipelines?search=&cursor=<cursor>  (cursorPaginate(8))
//     → { data: ApprovalPipelineListItem[], meta: { next_cursor } }   NO `total`.
//   GET    /approval-pipelines/{id}      → { data: ApprovalPipeline }
//   POST   /approval-pipelines           → { data: ApprovalPipeline }
//   PUT    /approval-pipelines/{id}      → { data: ApprovalPipeline }
//   DELETE /approval-pipelines/{id}      → { message }
//   Update/Delete on a pipeline WITH active processes → 422 whose validation bag
//   carries an (already-translated) message under the `pipeline` field; we detect
//   that field structurally and translate with the UI catalog (surfaced structured).
//
// Response wrapping: NO Approvals resource declares a `data` key, so EVERY body
// is `{ data: ... }`. Single resource → `res.data`; collection → `res.data` +
// `res.meta`.
//
// Self-contained: NO import from the legacy `resources/js/` (the legacy
// `store/approvals.ts` is reference only). Uses the SAME `next` api singleton the
// forms store uses.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  ApprovalPipeline,
  ApprovalPipelineListItem,
  PipelineActiveProcessesError,
  PipelineDetailResponse,
  PipelineFilters,
  PipelineListResponse,
  PipelineWritePayload,
} from '../../pages/approvals/types';

/**
 * Frontend i18n key for the "pipeline has active processes" message. We translate
 * with the UI catalog (not the server string) so the toast follows the UI locale.
 */
const ACTIVE_PROCESSES_KEY = 'approvals.validation.pipeline_has_active_processes';

/**
 * The validation-bag FIELD the backend uses for the active-processes 422. It is NOT
 * a real form field (form fields are name/icon/description/stages.*), so its presence
 * uniquely identifies this case and won't collide with ordinary field errors.
 */
const ACTIVE_PROCESSES_FIELD = 'pipeline';

/** Optional flags for a fetch (reset clears the list + cursor first). */
interface FetchOptions {
  reset?: boolean;
}

/**
 * Serialize the page's filter object into URLSearchParams. The ONLY server
 * filter is `search`; undefined / null / '' are skipped. (cursor is added by the
 * caller.)
 */
export function serializeFilters(filters: PipelineFilters): URLSearchParams {
  const params = new URLSearchParams();
  if (filters.search != null && filters.search !== '') {
    params.append('search', String(filters.search));
  }
  return params;
}

/** Pull a human message out of an axios error (best-effort). */
function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'approvals.errors.description';
}

/**
 * Detect the 422 "pipeline has active processes" response and return a structured
 * error the UI can translate. The backend resolves the message through `__()` at the
 * PHP layer, so the body carries the ALREADY-TRANSLATED string under the `pipeline`
 * validation field (NOT the raw key) — detect it structurally by that field, then let
 * the UI translate with its own catalog so the toast follows the UI locale.
 */
export function activeProcessesError(err: unknown): PipelineActiveProcessesError | null {
  const res = (err as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } })?.response;
  if (res?.status !== 422) return null;
  const pipelineErrors = res.data?.errors?.[ACTIVE_PROCESSES_FIELD];
  if (Array.isArray(pipelineErrors) && pipelineErrors.length > 0) {
    return { kind: 'active_processes', messageKey: ACTIVE_PROCESSES_KEY };
  }
  return null;
}

export const useApprovalPipelinesStore = defineStore('next-approval-pipelines', () => {
  // --- List state ----------------------------------------------------------
  const items = ref<ApprovalPipelineListItem[]>([]);
  const cursor = ref<string | null>(null);
  const hasMore = ref(true);
  const loading = ref(false);
  const loadingMore = ref(false);
  const errored = ref(false);
  const error = ref<string | null>(null);
  /**
   * Append (load-more) failure is tracked SEPARATELY from the first-page `errored`
   * so a failed page can be retried: on an append error we keep `hasMore`/`cursor`
   * intact (the page still exists) and only set this flag, which pauses the
   * infinite-scroll sentinel until the user retries. (Mirrors the queue store.)
   */
  const loadMoreErrored = ref(false);

  // Request token: a reset always supersedes work in flight so rapid filter
  // changes can never leave stale pages.
  let token = 0;

  // --- Detail (single pipeline) cache --------------------------------------
  const detail = ref<ApprovalPipeline | null>(null);
  const detailLoading = ref(false);
  const detailError = ref<string | null>(null);

  // --- List helpers --------------------------------------------------------
  /** Replace a pipeline in the list in place (after an update). */
  function replaceInList(pipeline: ApprovalPipeline): void {
    const idx = items.value.findIndex((p) => p.id === pipeline.id);
    if (idx >= 0) {
      const next = [...items.value];
      next[idx] = toListItem(pipeline);
      items.value = next;
    }
  }

  /** Remove a pipeline from the list (after a delete). */
  function removeFromList(id: string): void {
    items.value = items.value.filter((p) => p.id !== id);
  }

  /**
   * Project a full ApprovalPipeline (from a create/update response) onto the
   * lighter LIST item shape so the grid row stays consistent — the list resource
   * only carries the compact stage summary (name/icon/order) + a stages_count.
   */
  function toListItem(pipeline: ApprovalPipeline): ApprovalPipelineListItem {
    return {
      id: pipeline.id,
      name: pipeline.name,
      icon: pipeline.icon,
      description: pipeline.description,
      stages_count: pipeline.stages.length,
      stages: pipeline.stages.map((s) => ({ name: s.name, icon: s.icon, order: s.order })),
      is_owner: pipeline.is_owner,
      can_be_edited: pipeline.can_be_edited,
      can_be_deleted: pipeline.can_be_deleted,
      created_at: pipeline.created_at,
    };
  }

  // --- List actions --------------------------------------------------------
  /**
   * Fetch one page with the given `filters`. With `{ reset: true }` (the default
   * for a filter change) the list + cursor are cleared first; otherwise the page
   * is appended for infinite scroll.
   */
  async function fetchPipelines(
    filters: PipelineFilters = {},
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    // Appends are no-ops while a page is in flight or when exhausted.
    if (!reset && (loadingMore.value || loading.value || !hasMore.value)) return;

    const myToken = (token += 1);
    if (reset) {
      loading.value = true;
      items.value = [];
      cursor.value = null;
      hasMore.value = true;
    } else {
      loadingMore.value = true;
    }
    errored.value = false;
    error.value = null;
    loadMoreErrored.value = false;

    try {
      const params = serializeFilters(filters);
      if (cursor.value && !reset) params.set('cursor', cursor.value);

      const qs = params.toString();
      // First real call: the body is `{ data: [...], meta: { next_cursor } }`
      // (Approvals resources have no `data` key → Laravel wraps the collection).
      const response = await api.get<PipelineListResponse>(`/approval-pipelines${qs ? `?${qs}` : ''}`);
      if (myToken !== token) return; // superseded by a newer reset

      const incoming = response.data ?? [];
      items.value = reset ? incoming : [...items.value, ...incoming];

      cursor.value = response.meta?.next_cursor ?? null;
      hasMore.value = (response.meta?.next_cursor ?? null) !== null;
    } catch (err: unknown) {
      if (myToken !== token) return;
      error.value = extractMessage(err);
      if (reset) {
        // First page failed: nothing is loaded, show the full error state and
        // stop infinite scroll from hammering a failing endpoint.
        errored.value = true;
        hasMore.value = false;
      } else {
        // Append failed: keep the loaded grid + `hasMore`/`cursor` so the page can
        // be retried; just pause the sentinel until the user clicks retry.
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
  async function loadMore(filters: PipelineFilters = {}): Promise<void> {
    await fetchPipelines(filters, { reset: false });
  }

  /**
   * Retry a failed append. `loadMore` alone would early-return while
   * `loadMoreErrored` is set (the sentinel is paused), so clear it first; `hasMore`
   * + `cursor` were preserved, so the same page is re-fetched. (Mirrors the queue.)
   */
  async function retryLoadMore(filters: PipelineFilters = {}): Promise<void> {
    loadMoreErrored.value = false;
    await fetchPipelines(filters, { reset: false });
  }

  /** Drop all cached list + detail state. */
  function resetAll(): void {
    items.value = [];
    cursor.value = null;
    hasMore.value = true;
    loading.value = false;
    loadingMore.value = false;
    errored.value = false;
    error.value = null;
    loadMoreErrored.value = false;
    detail.value = null;
    detailLoading.value = false;
    detailError.value = null;
  }

  // --- Detail --------------------------------------------------------------
  /** Fetch the FULL pipeline (`GET /approval-pipelines/{id}`) — includes stages. */
  async function fetchPipeline(id: string): Promise<ApprovalPipeline | null> {
    detailLoading.value = true;
    detailError.value = null;
    try {
      const res = await api.get<PipelineDetailResponse>(`/approval-pipelines/${id}`);
      detail.value = res.data;
      return res.data;
    } catch (err: unknown) {
      detailError.value = extractMessage(err);
      return null;
    } finally {
      detailLoading.value = false;
    }
  }

  // --- Create / update / delete --------------------------------------------
  /** Create a pipeline (`POST /approval-pipelines`). Prepends + reconciles. */
  async function createPipeline(payload: PipelineWritePayload): Promise<ApprovalPipeline> {
    const res = await api.post<PipelineDetailResponse>('/approval-pipelines', payload);
    const created = res.data;
    // Prepend to the list (sort is created_at desc) once it has been initialized.
    if (items.value.length > 0 || cursor.value !== null || !hasMore.value) {
      items.value = [toListItem(created), ...items.value];
    }
    detail.value = created;
    return created;
  }

  /** Update a pipeline (`PUT /approval-pipelines/{id}`). Reconciles list + detail. */
  async function updatePipeline(id: string, payload: PipelineWritePayload): Promise<ApprovalPipeline> {
    const res = await api.put<PipelineDetailResponse>(`/approval-pipelines/${id}`, payload);
    const updated = res.data;
    replaceInList(updated);
    if (detail.value && detail.value.id === id) detail.value = updated;
    return updated;
  }

  /** Delete a pipeline (`DELETE /approval-pipelines/{id}`). Drops it from the list. */
  async function deletePipeline(id: string): Promise<void> {
    await api.delete<{ message: string }>(`/approval-pipelines/${id}`);
    removeFromList(id);
    if (detail.value && detail.value.id === id) detail.value = null;
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
    // detail state
    detail,
    detailLoading,
    detailError,
    // list actions
    fetchPipelines,
    loadMore,
    retryLoadMore,
    resetAll,
    // list mutations (exposed for tests / advanced callers)
    replaceInList,
    removeFromList,
    // detail
    fetchPipeline,
    // create / update / delete
    createPipeline,
    updatePipeline,
    deletePipeline,
  };
});
