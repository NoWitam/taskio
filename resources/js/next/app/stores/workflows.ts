// Workflow (automation) store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the Workflows BROWSE + detail experience: a single
// cursor-paginated list plus the per-workflow CRUD/status/run actions. The PAGE
// owns the filter state and passes it in; this store fetches, appends, tracks
// cursor/loading/error, caches the detail, and reconciles the list in place after
// a write so the UI updates without a full refetch. Mirrors `stores/bots.ts`.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET    /workflows?search=&status=&cursor=  (cursorPaginate(8), created_at desc)
//     → { data: WorkflowListItem[], meta: { next_cursor } }   NO `total`.
//   GET    /workflows/{id}          → { data: WorkflowDetail }
//   POST   /workflows               → { data: WorkflowDetail }     (201)
//   PUT    /workflows/{id}          → { data: WorkflowDetail }      (200)
//   PATCH  /workflows/{id}/status   → { data: WorkflowDetail }      (200)
//   DELETE /workflows/{id}          → { message }                   (200, soft delete)
//   POST   /workflows/{id}/restore  → { data: WorkflowDetail }      (200)
//   POST   /workflows/{id}/run      → { data: WorkflowRun }         (202)
//
// Response wrapping: Workflow resources declare no `data` key, so EVERY body is
// `{ data: ... }`. Single resource → `res.data`; collection → `res.data` +
// `res.meta`.
//
// Self-contained: NO import from the legacy `resources/js/`. Uses the SAME `next`
// api singleton the bots/approvals stores use, with the request-token guard +
// retryable-append pattern.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  ScheduleAssistEnvelope,
  ScheduleAssistResponse,
  SchedulePreviewResponse,
  WorkflowScheduleConfig,
  WorkflowCatalog,
  WorkflowCatalogResponse,
  WorkflowDetail,
  WorkflowDetailResponse,
  WorkflowFilters,
  WorkflowListItem,
  WorkflowListResponse,
  WorkflowRun,
  WorkflowRunPayload,
  WorkflowRunResponse,
  WorkflowStatus,
  WorkflowStatusPayload,
  WorkflowWritePayload,
} from '../../pages/workflows/types';

/**
 * A schedule-assist failure the composer can disambiguate (§4.5.5d): `throttled`
 * (HTTP 429 — too many attempts) vs `failed` (network / parse / any other error).
 * The store throws this so the caller shows FE-owned copy and NEVER the raw backend
 * message. The originating error is attached for logging/debugging only.
 */
export class ScheduleAssistError extends Error {
  constructor(
    public readonly kind: 'throttled' | 'failed',
    public readonly cause?: unknown,
  ) {
    super(`schedule-assist ${kind}`);
    this.name = 'ScheduleAssistError';
  }
}

/** The HTTP status of an axios-style error, or null. */
function statusOf(err: unknown): number | null {
  return (err as { response?: { status?: number } })?.response?.status ?? null;
}

/** Optional flags for a fetch (reset clears the list + cursor first). */
interface FetchOptions {
  reset?: boolean;
}

/**
 * Serialize the page's filter object into URLSearchParams. Server filters are
 * `search` (name/description) and `status`; undefined / null / '' are skipped.
 * (cursor is added by the caller.)
 */
export function serializeFilters(filters: WorkflowFilters): URLSearchParams {
  const params = new URLSearchParams();
  if (filters.search != null && filters.search !== '') {
    params.append('search', String(filters.search));
  }
  if (filters.status != null && filters.status !== ('' as WorkflowStatus)) {
    params.append('status', String(filters.status));
  }
  return params;
}

/** Pull a human message out of an axios error (best-effort). */
function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'workflows.errors.description';
}

export const useWorkflowsStore = defineStore('next-workflows', () => {
  // --- List state ----------------------------------------------------------
  const items = ref<WorkflowListItem[]>([]);
  const cursor = ref<string | null>(null);
  const hasMore = ref(true);
  const loading = ref(false);
  const loadingMore = ref(false);
  const errored = ref(false);
  const error = ref<string | null>(null);
  /**
   * Append (load-more) failure is tracked SEPARATELY from the first-page `errored`
   * so a failed page can be retried: on an append error we keep `hasMore`/`cursor`
   * intact and only set this flag, which pauses the infinite-scroll sentinel until
   * the user retries.
   */
  const loadMoreErrored = ref(false);

  // Request token: a reset always supersedes work in flight so rapid filter
  // changes can never leave stale pages.
  let token = 0;

  // --- Detail (single workflow) cache --------------------------------------
  const detail = ref<WorkflowDetail | null>(null);
  const detailLoading = ref(false);
  const detailError = ref<string | null>(null);

  // v2 (Phase 4a) has NO schedule-families vocabulary endpoint — the FE owns every
  // label and mirrors the numeric bounds as constants (workflowSchedule.ts). The REV3
  // `scheduleFamilies` cache + `fetchScheduleFamilies` were REMOVED.

  // --- Variable-catalog cache, keyed per form id (§4.7) --------------------
  // Each form's catalog is cached so switching steps/fields on one form doesn't
  // refetch; a per-form-id in-flight guard de-dupes concurrent requests.
  const catalogByForm = ref<Record<string, WorkflowCatalog>>({});
  const catalogInFlight = new Map<string, Promise<WorkflowCatalog>>();

  // --- List helpers --------------------------------------------------------
  /**
   * Project a full WorkflowDetail (from a write response) onto the lighter LIST
   * item shape so the grid row stays consistent — the list resource carries only
   * the compact fields, not the full definition.
   */
  function toListItem(workflow: WorkflowDetail): WorkflowListItem {
    return {
      id: workflow.id,
      name: workflow.name,
      status: workflow.status,
      description: workflow.description,
      icon: workflow.icon,
      trigger_type: workflow.trigger_type,
      step_count: workflow.steps?.length ?? 0,
      next_due_at: workflow.next_due_at,
      is_owner: workflow.is_owner,
      created_at: workflow.created_at,
    };
  }

  /** Replace a workflow in the list in place (after an update). */
  function replaceInList(workflow: WorkflowDetail): void {
    const idx = items.value.findIndex((w) => w.id === workflow.id);
    if (idx >= 0) {
      const next = [...items.value];
      next[idx] = toListItem(workflow);
      items.value = next;
    }
  }

  /** Remove a workflow from the list (after a delete). */
  function removeFromList(id: string): void {
    items.value = items.value.filter((w) => w.id !== id);
  }

  // --- List actions --------------------------------------------------------
  /**
   * Fetch one page with the given `filters`. With `{ reset: true }` (the default
   * for a filter change) the list + cursor are cleared first; otherwise the page
   * is appended for infinite scroll.
   */
  async function fetchWorkflows(
    filters: WorkflowFilters = {},
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
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
      const response = await api.get<WorkflowListResponse>(`/workflows${qs ? `?${qs}` : ''}`);
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
  async function loadMore(filters: WorkflowFilters = {}): Promise<void> {
    await fetchWorkflows(filters, { reset: false });
  }

  /**
   * Retry a failed append. `loadMore` alone would early-return while
   * `loadMoreErrored` is set (the sentinel is paused), so clear it first;
   * `hasMore` + `cursor` were preserved, so the same page is re-fetched.
   */
  async function retryLoadMore(filters: WorkflowFilters = {}): Promise<void> {
    loadMoreErrored.value = false;
    await fetchWorkflows(filters, { reset: false });
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
  /** Fetch the FULL workflow (`GET /workflows/{id}`) — the complete definition. */
  async function fetchWorkflow(id: string): Promise<WorkflowDetail | null> {
    detailLoading.value = true;
    detailError.value = null;
    try {
      const res = await api.get<WorkflowDetailResponse>(`/workflows/${id}`);
      detail.value = res.data;
      return res.data;
    } catch (err: unknown) {
      detailError.value = extractMessage(err);
      return null;
    } finally {
      detailLoading.value = false;
    }
  }

  // --- Create / update / status / delete / restore / run -------------------
  /** Create a workflow (`POST /workflows`). Prepends + reconciles (created_at desc). */
  async function createWorkflow(payload: WorkflowWritePayload): Promise<WorkflowDetail> {
    const res = await api.post<WorkflowDetailResponse>('/workflows', payload);
    const created = res.data;
    if (items.value.length > 0 || cursor.value !== null || !hasMore.value) {
      items.value = [toListItem(created), ...items.value];
    }
    detail.value = created;
    return created;
  }

  /** Update a workflow (`PUT /workflows/{id}`). Reconciles list + detail. */
  async function updateWorkflow(id: string, payload: WorkflowWritePayload): Promise<WorkflowDetail> {
    const res = await api.put<WorkflowDetailResponse>(`/workflows/${id}`, payload);
    const updated = res.data;
    replaceInList(updated);
    if (detail.value && detail.value.id === id) detail.value = updated;
    return updated;
  }

  /**
   * Toggle a workflow's status (`PATCH /workflows/{id}/status`). Returns the
   * refreshed resource and reconciles the detail cache + the list row in place. A
   * 403/422 bubbles up for the caller to toast.
   */
  async function setStatus(id: string, status: WorkflowStatus): Promise<WorkflowDetail> {
    const body: WorkflowStatusPayload = { status };
    const res = await api.patch<WorkflowDetailResponse>(`/workflows/${id}/status`, body);
    const updated = res.data;
    replaceInList(updated);
    if (detail.value && detail.value.id === id) detail.value = updated;
    return updated;
  }

  /** Soft-delete a workflow (`DELETE /workflows/{id}`). Drops it from the list. */
  async function deleteWorkflow(id: string): Promise<void> {
    await api.delete<{ message: string }>(`/workflows/${id}`);
    removeFromList(id);
    if (detail.value && detail.value.id === id) detail.value = null;
  }

  /** Restore a soft-deleted workflow (`POST /workflows/{id}/restore`). Prepends + reconciles. */
  async function restoreWorkflow(id: string): Promise<WorkflowDetail> {
    const res = await api.post<WorkflowDetailResponse>(`/workflows/${id}/restore`, {});
    const restored = res.data;
    if (!items.value.some((w) => w.id === restored.id)) {
      if (items.value.length > 0 || cursor.value !== null || !hasMore.value) {
        items.value = [toListItem(restored), ...items.value];
      }
    } else {
      replaceInList(restored);
    }
    detail.value = restored;
    return restored;
  }

  /**
   * Manually run a workflow (`POST /workflows/{id}/run` → 202). `targetId` is the
   * per-trigger target (Task / FormSubmission id) or omitted for schedule triggers.
   * Returns the created WorkflowRun. The run-now UI (Batch 6c) surfaces 422s; this
   * action just performs the call and returns the run for the caller to react to.
   */
  async function run(id: string, targetId?: string | null): Promise<WorkflowRun> {
    const body: WorkflowRunPayload = {};
    if (targetId != null && targetId !== '') body.target_id = targetId;
    const res = await api.post<WorkflowRunResponse>(`/workflows/${id}/run`, body);
    return res.data;
  }

  // --- Schedule builder catalog / assist / preview (§4.5, §4.7) ------------

  /**
   * Fetch the TYPED variable catalog for a form
   * (`GET /forms/{formId}/workflow-catalog`). CACHED PER FORM ID — the second call
   * for the same form returns the cached catalog; concurrent calls for one form
   * share a request. The catalog feeds every variable-capable control on the
   * workflow (all step editors + add-on fields + the condition builder).
   */
  async function fetchWorkflowCatalog(formId: string): Promise<WorkflowCatalog> {
    const cached = catalogByForm.value[formId];
    if (cached) return cached;

    const inFlight = catalogInFlight.get(formId);
    if (inFlight) return inFlight;

    const request = api
      .get<WorkflowCatalogResponse>(`/forms/${formId}/workflow-catalog`)
      .then((res) => {
        const catalog = res.data ?? { variables: [], fields: [] };
        catalogByForm.value = { ...catalogByForm.value, [formId]: catalog };
        return catalog;
      })
      .finally(() => {
        catalogInFlight.delete(formId);
      });

    catalogInFlight.set(formId, request);
    return request;
  }

  /** Drop the cached catalog for a form (e.g. after a form edit invalidates it). */
  function invalidateCatalog(formId: string): void {
    if (!(formId in catalogByForm.value)) return;
    const next = { ...catalogByForm.value };
    delete next[formId];
    catalogByForm.value = next;
  }

  /**
   * Ask the AI schedule assist to turn a natural-language `prompt` into a structured
   * schedule config (`POST /workflows/schedule-assist`). NOT cached (each prompt is
   * unique). The user's active `tz` is sent as a hint. The re-validated envelope is
   * returned on success; a 429 throttle throws `ScheduleAssistError('throttled')`
   * and any other failure throws `ScheduleAssistError('failed')` so the composer
   * shows FE-owned copy — the raw backend message is NEVER surfaced (§4.5.5d).
   */
  async function scheduleAssist(prompt: string, tz?: string | null): Promise<ScheduleAssistEnvelope> {
    try {
      const body: { prompt: string; tz?: string } = { prompt };
      if (tz != null && tz !== '') body.tz = tz;
      const res = await api.post<ScheduleAssistResponse>('/workflows/schedule-assist', body);
      return res.data;
    } catch (err: unknown) {
      throw new ScheduleAssistError(statusOf(err) === 429 ? 'throttled' : 'failed', err);
    }
  }

  /**
   * Preview the next N occurrences of a v2 schedule
   * (`POST /workflows/meta/schedule-preview`). NOT cached (the result depends on the
   * live draft) and NOT retried — the strip debounces the call and only fires it for
   * a client-valid draft. `count` is 1..12 (default 6); an optional `anchor` (ISO-8601)
   * centres the projection so `occurrences[0]` is the occurrence AT-OR-BEFORE it
   * (prev-or-at) and the rest ascend after it — the strip pages FORWARD by re-calling
   * with `anchor` = the last shown occurrence (§4.5.4). The FLAT response carries
   * `occurrences` (ISO8601 UTC ascending), `empty` (the schedule never fires — NOT a
   * 422), and `approximate` (always false in v2). A 422 is structural and bubbles up
   * for the caller to swallow (quiet, non-blocking).
   */
  async function schedulePreview(
    schedule: WorkflowScheduleConfig,
    { count = 6, anchor }: { count?: number; anchor?: string | null } = {},
  ): Promise<SchedulePreviewResponse> {
    // UNWRAPPED response: unlike the assist (which nests its envelope under `data`),
    // the preview controller returns the flat body — api.post already yields it.
    const body: { schedule: WorkflowScheduleConfig; count: number; anchor?: string } = { schedule, count };
    if (anchor != null && anchor !== '') body.anchor = anchor;
    return api.post<SchedulePreviewResponse>('/workflows/meta/schedule-preview', body);
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
    // schedule builder catalog cache
    catalogByForm,
    // list actions
    fetchWorkflows,
    loadMore,
    retryLoadMore,
    resetAll,
    // list mutations (exposed for tests / advanced callers)
    replaceInList,
    removeFromList,
    // detail
    fetchWorkflow,
    // create / update / status / delete / restore / run
    createWorkflow,
    updateWorkflow,
    setStatus,
    deleteWorkflow,
    restoreWorkflow,
    run,
    // schedule builder catalog / assist / preview (§4.5, §4.7)
    fetchWorkflowCatalog,
    invalidateCatalog,
    scheduleAssist,
    schedulePreview,
  };
});
