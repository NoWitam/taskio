// Bot (AI Character) store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the Bots BROWSE + editor experience (Batch 1): a single
// cursor-paginated list plus the per-bot CRUD actions. The PAGE owns the filter
// state and passes it in; this store fetches, appends, tracks cursor/loading/
// error, caches the detail, and reconciles the list in place after a write so the
// UI updates without a full refetch.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET    /bots?search=&cursor=<cursor>  (cursorPaginate(8), created_at desc)
//     → { data: BotListItem[], meta: { next_cursor } }   NO `total`.
//   GET    /bots/{id}          → { data: BotDetail }
//   POST   /bots               → { data: BotDetail }     (201)
//   PUT    /bots/{id}          → { data: BotDetail }      (200)
//   DELETE /bots/{id}          → { message }              (200, soft delete)
//   POST   /bots/{id}/restore  → { data: BotDetail }      (200)
//
// Response wrapping: Bot resources declare no `data` key, so EVERY body is
// `{ data: ... }`. Single resource → `res.data`; collection → `res.data` +
// `res.meta`.
//
// Self-contained: NO import from the legacy `resources/js/`. Uses the SAME `next`
// api singleton the approvals/forms stores use. Mirrors the request-token guard +
// retryable-append pattern of the approval-pipelines store.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  BotDetail,
  BotDetailResponse,
  BotFilters,
  BotListItem,
  BotListResponse,
  BotStatus,
  BotStatusPayload,
  BotWritePayload,
} from '../../pages/bots/types';

/** Optional flags for a fetch (reset clears the list + cursor first). */
interface FetchOptions {
  reset?: boolean;
}

/**
 * Serialize the page's filter object into URLSearchParams. The ONLY server filter
 * is `search`; undefined / null / '' are skipped. (cursor is added by the caller.)
 */
export function serializeFilters(filters: BotFilters): URLSearchParams {
  const params = new URLSearchParams();
  if (filters.search != null && filters.search !== '') {
    params.append('search', String(filters.search));
  }
  return params;
}

/** Pull a human message out of an axios error (best-effort). */
function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'bots.errors.description';
}

export const useBotsStore = defineStore('next-bots', () => {
  // --- List state ----------------------------------------------------------
  const items = ref<BotListItem[]>([]);
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
   * infinite-scroll sentinel until the user retries.
   */
  const loadMoreErrored = ref(false);

  // Request token: a reset always supersedes work in flight so rapid filter
  // changes can never leave stale pages.
  let token = 0;

  // --- Detail (single bot) cache -------------------------------------------
  const detail = ref<BotDetail | null>(null);
  const detailLoading = ref(false);
  const detailError = ref<string | null>(null);

  // --- List helpers --------------------------------------------------------
  /**
   * Project a full BotDetail (from a create/update/restore response) onto the
   * lighter LIST item shape so the grid row stays consistent — the list resource
   * carries the compact module flags, not the full module payloads.
   */
  function toListItem(bot: BotDetail): BotListItem {
    return {
      id: bot.id,
      name: bot.name,
      status: bot.status,
      description: bot.description,
      icon: bot.icon,
      has_text_module: true,
      task_execution_enabled: bot.task_execution?.enabled ?? false,
      is_owner: bot.is_owner,
      created_at: bot.created_at,
    };
  }

  /** Replace a bot in the list in place (after an update). */
  function replaceInList(bot: BotDetail): void {
    const idx = items.value.findIndex((b) => b.id === bot.id);
    if (idx >= 0) {
      const next = [...items.value];
      next[idx] = toListItem(bot);
      items.value = next;
    }
  }

  /** Remove a bot from the list (after a delete). */
  function removeFromList(id: string): void {
    items.value = items.value.filter((b) => b.id !== id);
  }

  // --- List actions --------------------------------------------------------
  /**
   * Fetch one page with the given `filters`. With `{ reset: true }` (the default
   * for a filter change) the list + cursor are cleared first; otherwise the page
   * is appended for infinite scroll.
   */
  async function fetchBots(
    filters: BotFilters = {},
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
      const response = await api.get<BotListResponse>(`/bots${qs ? `?${qs}` : ''}`);
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
  async function loadMore(filters: BotFilters = {}): Promise<void> {
    await fetchBots(filters, { reset: false });
  }

  /**
   * Retry a failed append. `loadMore` alone would early-return while
   * `loadMoreErrored` is set (the sentinel is paused), so clear it first; `hasMore`
   * + `cursor` were preserved, so the same page is re-fetched.
   */
  async function retryLoadMore(filters: BotFilters = {}): Promise<void> {
    loadMoreErrored.value = false;
    await fetchBots(filters, { reset: false });
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
  /** Fetch the FULL bot (`GET /bots/{id}`) — includes all module payloads. */
  async function fetchBot(id: string): Promise<BotDetail | null> {
    detailLoading.value = true;
    detailError.value = null;
    try {
      const res = await api.get<BotDetailResponse>(`/bots/${id}`);
      detail.value = res.data;
      return res.data;
    } catch (err: unknown) {
      detailError.value = extractMessage(err);
      return null;
    } finally {
      detailLoading.value = false;
    }
  }

  // --- Create / update / delete / restore ----------------------------------
  /** Create a bot (`POST /bots`). Prepends + reconciles (sort is created_at desc). */
  async function createBot(payload: BotWritePayload): Promise<BotDetail> {
    const res = await api.post<BotDetailResponse>('/bots', payload);
    const created = res.data;
    // Prepend to the list once it has been initialized (skip the un-fetched state).
    if (items.value.length > 0 || cursor.value !== null || !hasMore.value) {
      items.value = [toListItem(created), ...items.value];
    }
    detail.value = created;
    return created;
  }

  /** Update a bot (`PUT /bots/{id}`). Reconciles list + detail. */
  async function updateBot(id: string, payload: BotWritePayload): Promise<BotDetail> {
    const res = await api.put<BotDetailResponse>(`/bots/${id}`, payload);
    const updated = res.data;
    replaceInList(updated);
    if (detail.value && detail.value.id === id) detail.value = updated;
    return updated;
  }

  /**
   * Toggle a bot's live status (`PATCH /bots/{id}/status` — creator-only). Returns
   * the refreshed BotResource and reconciles the detail cache + the list row in
   * place. A 403 (non-creator) / 422 (invalid) bubbles up for the caller to toast.
   */
  async function setStatus(id: string, status: BotStatus): Promise<BotDetail> {
    const body: BotStatusPayload = { status };
    const res = await api.patch<BotDetailResponse>(`/bots/${id}/status`, body);
    const updated = res.data;
    replaceInList(updated);
    if (detail.value && detail.value.id === id) detail.value = updated;
    return updated;
  }

  /** Soft-delete a bot (`DELETE /bots/{id}`). Drops it from the list. */
  async function deleteBot(id: string): Promise<void> {
    await api.delete<{ message: string }>(`/bots/${id}`);
    removeFromList(id);
    if (detail.value && detail.value.id === id) detail.value = null;
  }

  /** Restore a soft-deleted bot (`POST /bots/{id}/restore`). Prepends + reconciles. */
  async function restoreBot(id: string): Promise<BotDetail> {
    const res = await api.post<BotDetailResponse>(`/bots/${id}/restore`, {});
    const restored = res.data;
    // It is no longer in the list (it was removed on delete) → prepend it back.
    if (!items.value.some((b) => b.id === restored.id)) {
      if (items.value.length > 0 || cursor.value !== null || !hasMore.value) {
        items.value = [toListItem(restored), ...items.value];
      }
    } else {
      replaceInList(restored);
    }
    detail.value = restored;
    return restored;
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
    fetchBots,
    loadMore,
    retryLoadMore,
    resetAll,
    // list mutations (exposed for tests / advanced callers)
    replaceInList,
    removeFromList,
    // detail
    fetchBot,
    // create / update / status / delete / restore
    createBot,
    updateBot,
    setStatus,
    deleteBot,
    restoreBot,
  };
});
