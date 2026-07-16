// FilterTabs store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for "saved views" (backend resource `FilterTab`) keyed PER
// CONTEXT (e.g. `tasks`). The page/composable owns the filter snapshot shape;
// this store only fetches, mutates, and tracks per-context loading/error.
//
// Backend contract (verified — do NOT invent fields). A saved view is
//   { id, name, icon (IconEnum value|null), filters, sort_order }
// all responses wrapped in `{ data }`:
//   GET    /api/filter-tabs?context=<ctx>            → { data: FilterTab[] }
//   POST   /api/filter-tabs {context,name,icon?,filters} → 201 { data: FilterTab }
//   PUT    /api/filter-tabs/{id} {name?,icon?,filters?}  → 200 { data: FilterTab }
//   DELETE /api/filter-tabs/{id}                     → 204
//   PUT    /api/filter-tabs/reorder {context,ids[]}  → 200 { data: FilterTab[] }
//
// 422 validation errors carry i18n KEYS as messages (e.g.
// `filter_tabs.errors.name_taken`) under `errors.<field>[0]` — surfaced raw so
// callers map them through `t()`.
//
// Self-contained: all HTTP through the `api` singleton (no axios import).
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';

/** A persisted saved view (backend `FilterTabResource`). */
export interface FilterTab {
  id: string | number;
  name: string;
  /** IconEnum value or null. */
  icon: string | null;
  /** Opaque filter snapshot (shape owned by the page/composable). */
  filters: Record<string, unknown>;
  sort_order: number;
}

interface FilterTabResponse {
  data: FilterTab;
}
interface FilterTabListResponse {
  data: FilterTab[];
}

/** Payload for create. */
export interface CreateFilterTabInput {
  name: string;
  icon?: string | null;
  filters: Record<string, unknown>;
}
/** Payload for update (all optional). */
export interface UpdateFilterTabInput {
  name?: string;
  icon?: string | null;
  filters?: Record<string, unknown>;
}

/**
 * A normalized validation failure. `fieldErrors` maps a field name → the FIRST
 * backend message (an i18n key like `filter_tabs.errors.name_taken`). `status`
 * is the HTTP status so callers can branch on 422 vs other failures.
 */
export interface FilterTabError {
  status: number | null;
  /** field → backend i18n key (first message). */
  fieldErrors: Record<string, string>;
  /** Top-level message when present. */
  message: string | null;
}

function toFilterTabError(err: unknown): FilterTabError {
  const res = (err as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } })
    ?.response;
  const fieldErrors: Record<string, string> = {};
  const errors = res?.data?.errors;
  if (errors) {
    for (const [field, messages] of Object.entries(errors)) {
      if (Array.isArray(messages) && messages.length) fieldErrors[field] = messages[0];
    }
  }
  return {
    status: res?.status ?? null,
    fieldErrors,
    message: res?.data?.message ?? null,
  };
}

export const useFilterTabsStore = defineStore('next-filter-tabs', () => {
  // --- State (per context) -------------------------------------------------
  const tabsByContext = ref<Record<string, FilterTab[]>>({});
  const loadingByContext = ref<Record<string, boolean>>({});
  const errorByContext = ref<Record<string, boolean>>({});
  /** Per-context request token so a refetch always supersedes work in flight. */
  const tokens: Record<string, number> = {};

  // --- Getters -------------------------------------------------------------
  function byContext(context: string): FilterTab[] {
    return tabsByContext.value[context] ?? [];
  }
  function isLoading(context: string): boolean {
    return loadingByContext.value[context] ?? false;
  }
  function hasError(context: string): boolean {
    return errorByContext.value[context] ?? false;
  }

  function sortTabs(list: FilterTab[]): FilterTab[] {
    return [...list].sort((a, b) => a.sort_order - b.sort_order);
  }

  // --- Actions -------------------------------------------------------------
  /** Fetch all saved views for a context. Replaces the bucket on success. */
  async function fetch(context: string): Promise<void> {
    const token = (tokens[context] = (tokens[context] ?? 0) + 1);
    loadingByContext.value[context] = true;
    errorByContext.value[context] = false;
    try {
      const res = await api.get<FilterTabListResponse>(
        `/filter-tabs?context=${encodeURIComponent(context)}`,
      );
      if (token !== tokens[context]) return;
      tabsByContext.value[context] = sortTabs(res.data ?? []);
    } catch (err: unknown) {
      if (token !== tokens[context]) return;
      errorByContext.value[context] = true;
      throw toFilterTabError(err);
    } finally {
      if (token === tokens[context]) loadingByContext.value[context] = false;
    }
  }

  /** Create a saved view; appends it to its context bucket. Throws FilterTabError. */
  async function create(context: string, input: CreateFilterTabInput): Promise<FilterTab> {
    try {
      const res = await api.post<FilterTabResponse>('/filter-tabs', { context, ...input });
      const created = res.data;
      tabsByContext.value[context] = sortTabs([...(tabsByContext.value[context] ?? []), created]);
      return created;
    } catch (err: unknown) {
      throw toFilterTabError(err);
    }
  }

  /** Update a saved view in `context`; reconciles it in place. Throws FilterTabError. */
  async function update(
    context: string,
    id: string | number,
    input: UpdateFilterTabInput,
  ): Promise<FilterTab> {
    try {
      const res = await api.put<FilterTabResponse>(`/filter-tabs/${id}`, input);
      const updated = res.data;
      const list = tabsByContext.value[context] ?? [];
      const idx = list.findIndex((t) => String(t.id) === String(id));
      if (idx >= 0) {
        const next = [...list];
        next[idx] = updated;
        tabsByContext.value[context] = sortTabs(next);
      }
      return updated;
    } catch (err: unknown) {
      throw toFilterTabError(err);
    }
  }

  /** Delete a saved view; removes it from its context bucket. Throws FilterTabError. */
  async function remove(context: string, id: string | number): Promise<void> {
    try {
      await api.delete(`/filter-tabs/${id}`);
      tabsByContext.value[context] = (tabsByContext.value[context] ?? []).filter(
        (t) => String(t.id) !== String(id),
      );
    } catch (err: unknown) {
      throw toFilterTabError(err);
    }
  }

  /**
   * Reorder a context's views by id list. Optimistically reorders locally, then
   * replaces with the authoritative server response. Throws FilterTabError (the
   * caller refetches on `invalid_reorder_set`).
   */
  async function reorder(context: string, ids: Array<string | number>): Promise<void> {
    const previous = tabsByContext.value[context] ?? [];
    // Optimistic local reorder so the UI updates immediately.
    const byId = new Map(previous.map((t) => [String(t.id), t]));
    const optimistic = ids
      .map((id) => byId.get(String(id)))
      .filter((t): t is FilterTab => t !== undefined);
    if (optimistic.length === previous.length) {
      tabsByContext.value[context] = optimistic.map((t, i) => ({ ...t, sort_order: i }));
    }
    try {
      const res = await api.put<FilterTabListResponse>('/filter-tabs/reorder', { context, ids });
      tabsByContext.value[context] = sortTabs(res.data ?? []);
    } catch (err: unknown) {
      // Roll back to the pre-reorder order on failure.
      tabsByContext.value[context] = previous;
      throw toFilterTabError(err);
    }
  }

  return {
    // state
    tabsByContext,
    loadingByContext,
    errorByContext,
    // getters
    byContext,
    isLoading,
    hasError,
    // actions
    fetch,
    create,
    update,
    remove,
    reorder,
  };
});
