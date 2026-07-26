// Consts store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the workspace-level CONSTS management surface (formerly
// "globals"): a single cursor-paginated list plus the CRUD actions. The PAGE owns the
// filter state and passes it in; this store fetches, appends, tracks cursor/loading/error,
// and reconciles the list IN PLACE after a write (ordered by NAME, mirroring the backend
// `orderBy('name')`) so the UI updates without a full refetch. After every mutation it
// invalidates ALL cached workflow catalogs so the new/edited/removed `globals.<key>`
// variable is reflected in every picker. Mirrors `stores/bots.ts`.
//
// NOTE on the wire: the feature + URL are now "consts", but the RUNTIME reference root
// stays `globals` (a const is still surfaced as `globals.<key>` in every workflow), so the
// catalog invalidation still keeps every `globals.<key>` picker in sync.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET    /consts?search=&cursor=  (cursorPaginate(20), orderBy name)
//     → { data: Constant[], meta: { next_cursor } }   NO `total`.
//   GET    /consts/{id}   → { data: Constant }
//   POST   /consts        → { data: Constant }     (201)
//   PUT    /consts/{id}   → { data: Constant }      (200)
//   DELETE /consts/{id}   → { message }            (200)
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import { useWorkflowsStore } from './workflows';
import type {
  Constant,
  ConstantFilters,
  ConstantListResponse,
  ConstantResponse,
  ConstantWritePayload,
} from '../../pages/workflows/types';

/** Optional flags for a fetch (reset clears the list + cursor first). */
interface FetchOptions {
  reset?: boolean;
}

/**
 * Serialize the page's filter object into URLSearchParams. The ONLY server filter is
 * `search` (name / key); undefined / null / '' are skipped. (cursor is added by the caller.)
 */
export function serializeFilters(filters: ConstantFilters): URLSearchParams {
  const params = new URLSearchParams();
  if (filters.search != null && filters.search !== '') {
    params.append('search', String(filters.search));
  }
  return params;
}

/** Pull a human message out of an axios error (best-effort). */
function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'variables.consts.errors.description';
}

/** Insert/replace a const into a name-sorted list, keeping the backend's ordering. */
function sortByName(items: Constant[]): Constant[] {
  return [...items].sort((a, b) => a.name.localeCompare(b.name));
}

export const useConstsStore = defineStore('next-consts', () => {
  // --- List state ----------------------------------------------------------
  const items = ref<Constant[]>([]);
  const cursor = ref<string | null>(null);
  const hasMore = ref(true);
  const loading = ref(false);
  const loadingMore = ref(false);
  const errored = ref(false);
  const error = ref<string | null>(null);
  /**
   * Append (load-more) failure is tracked SEPARATELY from the first-page `errored`
   * so a failed page can be retried: on an append error we keep `hasMore`/`cursor`
   * intact and only set this flag, which pauses the infinite-scroll sentinel.
   */
  const loadMoreErrored = ref(false);

  // Request token: a reset always supersedes work in flight.
  let token = 0;

  // --- List helpers --------------------------------------------------------
  /** Replace a const in the list in place (after an update), keeping name order. */
  function replaceInList(constant: Constant): void {
    const idx = items.value.findIndex((c) => c.id === constant.id);
    if (idx >= 0) {
      const next = [...items.value];
      next[idx] = constant;
      items.value = sortByName(next);
    }
  }

  /** Remove a const from the list (after a delete). */
  function removeFromList(id: string): void {
    items.value = items.value.filter((c) => c.id !== id);
  }

  /** Invalidate every cached workflow catalog so pickers pick up the change. */
  function refreshCatalog(): void {
    useWorkflowsStore().invalidateAllCatalogs();
  }

  // --- List actions --------------------------------------------------------
  /**
   * Fetch one page with the given `filters`. With `{ reset: true }` (the default for a
   * filter change) the list + cursor are cleared first; otherwise the page is appended.
   */
  async function fetchConstants(
    filters: ConstantFilters = {},
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
      const response = await api.get<ConstantListResponse>(`/consts${qs ? `?${qs}` : ''}`);
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
  async function loadMore(filters: ConstantFilters = {}): Promise<void> {
    await fetchConstants(filters, { reset: false });
  }

  /** Retry a failed append (clears the pause flag, re-fetches the same page). */
  async function retryLoadMore(filters: ConstantFilters = {}): Promise<void> {
    loadMoreErrored.value = false;
    await fetchConstants(filters, { reset: false });
  }

  /** Drop all cached list state. */
  function resetAll(): void {
    items.value = [];
    cursor.value = null;
    hasMore.value = true;
    loading.value = false;
    loadingMore.value = false;
    errored.value = false;
    error.value = null;
    loadMoreErrored.value = false;
  }

  // --- Create / update / delete --------------------------------------------
  /** Create a const (`POST /consts`). Inserts (name order) + refreshes catalog. */
  async function createConstant(payload: ConstantWritePayload): Promise<Constant> {
    const res = await api.post<ConstantResponse>('/consts', payload);
    const created = res.data;
    if (items.value.length > 0 || cursor.value !== null || !hasMore.value) {
      items.value = sortByName([created, ...items.value]);
    }
    refreshCatalog();
    return created;
  }

  /** Update a const (`PUT /consts/{id}`). Reconciles list + refreshes catalog. */
  async function updateConstant(id: string, payload: ConstantWritePayload): Promise<Constant> {
    const res = await api.put<ConstantResponse>(`/consts/${id}`, payload);
    const updated = res.data;
    replaceInList(updated);
    refreshCatalog();
    return updated;
  }

  /** Delete a const (`DELETE /consts/{id}`). Drops it + refreshes catalog. */
  async function deleteConstant(id: string): Promise<void> {
    await api.delete<{ message: string }>(`/consts/${id}`);
    removeFromList(id);
    refreshCatalog();
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
    // list actions
    fetchConstants,
    loadMore,
    retryLoadMore,
    resetAll,
    // list mutations (exposed for tests / advanced callers)
    replaceInList,
    removeFromList,
    // create / update / delete
    createConstant,
    updateConstant,
    deleteConstant,
  };
});
