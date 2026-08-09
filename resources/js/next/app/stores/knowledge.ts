// Knowledge store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the whole module: knowledge BASES (a cursor-paginated list, live OR
// trashed — the page owns which, via the `trashed` filter), ENTRIES, revisions, links, the graph,
// and the AI composer's drafting sessions. Each section below carries the endpoints it speaks to.
//
// The PAGE owns filter state and passes it in; this store fetches, appends, tracks
// cursor/loading/error, and reconciles lists IN PLACE after a write (bases by NAME, entries by
// `position`, mirroring the backend's own ordering) so the UI updates without a full refetch.
// Mirrors `stores/templates.ts`.
//
// Backend contract (VERIFIED against app/modules/Knowledge — do NOT invent fields):
//   All routes are prefixed `/knowledge` and gated by `auth:sanctum` + RequireWorkspace, so the
//   `X-Workspace-Id` header (added by lib/api) is MANDATORY — a header-less request is refused
//   before anything binds.
//
//   GET    /knowledge/bases?search=&trashed=1&cursor=
//            → { data: KnowledgeBase[], meta: { next_cursor } }   NO `total`.
//            cursorPaginate(25), orderBy name then id; `withCount('entries')` + `with('creator')`;
//            `search` matches name + description; `trashed=1` swaps the page to onlyTrashed().
//   POST   /knowledge/bases              → { data: KnowledgeBase }
//   GET    /knowledge/bases/{base}       → { data: KnowledgeBase }   (also loadCount entries)
//   PATCH  /knowledge/bases/{base}       → { data: KnowledgeBase }
//   DELETE /knowledge/bases/{base}       → 204 NO CONTENT (no body)
//   POST   /knowledge/bases/{id}/restore → { data: KnowledgeBase }
//   DELETE /knowledge/bases/{id}/force   → 204 NO CONTENT (no body)
//
//   PATCH semantics that this store must not break: an ABSENT key means UNCHANGED
//   (UpdateKnowledgeBaseRequest::resolved*). The caller therefore sends ONLY what changed — the
//   store passes the payload through verbatim and never fills in defaults, because echoing back
//   `charter` / `metadata_schema` is both a wipe hazard and the difference between needing the
//   `update` ability and needing `manage`.
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api } from '../lib/api';
import type {
  KnowledgeBase,
  KnowledgeBaseFilters,
  KnowledgeBaseListResponse,
  KnowledgeBaseResponse,
  KnowledgeBaseWritePayload,
  KnowledgeEntry,
  KnowledgeEntryFilters,
  KnowledgeEntryListItem,
  KnowledgeEntryListResponse,
  KnowledgeEntryResponse,
  KnowledgeEntryRevision,
  KnowledgeEntryStatus,
  KnowledgeEntryWritePayload,
  KnowledgeComposeAvailability,
  KnowledgeDraftAcceptResult,
  KnowledgeDraftDiff,
  KnowledgeDraftDiffBaseline,
  KnowledgeDraftRelations,
  KnowledgeDraftRelationsResponse,
  KnowledgeDraftEntry,
  KnowledgeDraftSession,
  KnowledgeDraftSessionPayload,
  KnowledgeDraftSessionResponse,
  KnowledgeGraphData,
  KnowledgeGraphFilters,
  KnowledgeGraphResponse,
  KnowledgeLink,
  KnowledgeLinkResponse,
  KnowledgeRelation,
  KnowledgeRevisionListResponse,
  KnowledgeSearchResponse,
  KnowledgeSearchResult,
  KnowledgeVectorSkipReason,
} from '../../pages/knowledge/types';

/** Optional flags for a fetch (reset clears the list + cursor first). */
interface FetchOptions {
  reset?: boolean;
}

/**
 * Serialize the page's filter object into URLSearchParams. The ONLY server filters are `search`
 * (name + description) and `trashed`; undefined / null / '' / false are skipped. (`cursor` is
 * added by the caller.)
 */
export function serializeFilters(filters: KnowledgeBaseFilters): URLSearchParams {
  const params = new URLSearchParams();
  if (filters.search != null && filters.search !== '') {
    params.append('search', String(filters.search));
  }
  if (filters.trashed) {
    params.append('trashed', '1');
  }
  return params;
}

/** Pull a human message out of an axios error (best-effort). */
function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'knowledge.common.loadError';
}

/** Insert/replace a base into a name-sorted list, keeping the backend's ordering. */
function sortByName(items: KnowledgeBase[]): KnowledgeBase[] {
  return [...items].sort((a, b) => a.name.localeCompare(b.name));
}

/**
 * Serialize the entry-list filters. The server reads exactly four (KnowledgeEntryService::filtered):
 * repeated `status[]`, boolean `stale`, `search`, and — since B6c — boolean `trashed`. Nothing else
 * is sent: a param the backend ignores is a filter the UI would appear to apply and silently not.
 */
export function serializeEntryFilters(filters: KnowledgeEntryFilters): URLSearchParams {
  const params = new URLSearchParams();
  for (const status of filters.status ?? []) {
    params.append('status[]', status);
  }
  if (filters.stale) {
    params.append('stale', '1');
  }
  if (filters.search != null && filters.search !== '') {
    params.append('search', String(filters.search));
  }
  // B6c: the base's TRASH instead of its live entries. Composes with the three above.
  if (filters.trashed) {
    params.append('trashed', '1');
  }
  return params;
}

/**
 * Serialize the GRAPH filters (`KnowledgeGraphQuery` 1:1).
 *
 * `sources` goes over as the comma form the DTO explicitly accepts (`sources=wikilink,similarity`);
 * an EMPTY list is deliberately NOT sent, because the server reads "no sources" as "the default
 * two" — a caller who wants nothing drawn must not ask at all.
 */
export function serializeGraphFilters(filters: KnowledgeGraphFilters): URLSearchParams {
  const params = new URLSearchParams();
  if (filters.entry) params.set('entry', filters.entry);
  if (filters.depth != null) params.set('depth', String(filters.depth));
  if (filters.sources && filters.sources.length > 0) params.set('sources', filters.sources.join(','));
  if (filters.minScore != null) params.set('min_score', String(filters.minScore));
  if (filters.includeDismissed) params.set('include_dismissed', '1');
  return params;
}

/** The HTTP status of an axios-style error, or null. */
function statusOf(err: unknown): number | null {
  return (err as { response?: { status?: number } })?.response?.status ?? null;
}

/** The `code` a Knowledge 409 body carries (`knowledge_stale_write` / `knowledge_slug_conflict`). */
export function conflictCodeOf(err: unknown): string | null {
  if (statusOf(err) !== 409) return null;
  const code = (err as { response?: { data?: { code?: unknown } } })?.response?.data?.code;
  return typeof code === 'string' ? code : null;
}

/**
 * The `current_revision_id` a 409 `knowledge_stale_write` reports — the revision the SERVER holds.
 * The editor re-arms its optimistic lock with this before an intentional overwrite; without it the
 * overwrite would 409 again against the same stale token, forever.
 */
/**
 * The relation a `knowledge_relation_duplicate` 422 points at, or null.
 *
 * The server refuses a second identical statement and says WHICH one already exists, in
 * `context.existing_relation_id`. That id is the difference between "this already exists" — which
 * leaves the user hunting through a panel for a row they cannot see — and "this already exists,
 * here it is".
 */
export function duplicateRelationOf(err: unknown): string | null {
  if (statusOf(err) !== 422) return null;
  const data = (err as {
    response?: { data?: { code?: unknown; context?: { existing_relation_id?: unknown } } };
  })?.response?.data;
  if (data?.code !== 'knowledge_relation_duplicate') return null;
  const id = data?.context?.existing_relation_id;

  return typeof id === 'string' ? id : null;
}

export function staleWriteRevisionOf(err: unknown): string | null {
  const data = (err as { response?: { data?: { current_revision_id?: unknown } } })?.response?.data;
  const id = data?.current_revision_id;
  return typeof id === 'string' ? id : null;
}

export const useKnowledgeStore = defineStore('next-knowledge', () => {
  // --- List state ----------------------------------------------------------
  const items = ref<KnowledgeBase[]>([]);
  const cursor = ref<string | null>(null);
  const hasMore = ref(true);
  const loading = ref(false);
  const loadingMore = ref(false);
  const errored = ref(false);
  const error = ref<string | null>(null);
  /**
   * Append (load-more) failure is tracked SEPARATELY from the first-page `errored` so a failed
   * page can be retried: on an append error we keep `hasMore`/`cursor` intact and only set this
   * flag, which pauses the infinite-scroll sentinel.
   */
  const loadMoreErrored = ref(false);

  // Request token: a reset always supersedes work in flight.
  let token = 0;

  // --- List helpers --------------------------------------------------------
  /** Replace a base in the list in place (after an update), keeping name order. */
  function replaceInList(base: KnowledgeBase): void {
    const idx = items.value.findIndex((b) => b.id === base.id);
    if (idx >= 0) {
      const next = [...items.value];
      // Preserve `entries_count`: a create/update response does NOT carry it (it is
      // `whenCounted`), so a naive replace would blank the counter the list just showed.
      next[idx] = base.entries_count === undefined && next[idx].entries_count !== undefined
        ? { ...base, entries_count: next[idx].entries_count }
        : base;
      items.value = sortByName(next);
    }
  }

  /** Remove a base from the list (after a delete / restore out of the current view). */
  function removeFromList(id: string): void {
    items.value = items.value.filter((b) => b.id !== id);
  }

  // --- List actions --------------------------------------------------------
  /**
   * Fetch one page with the given `filters`. With `{ reset: true }` (the default for a filter
   * change) the list + cursor are cleared first; otherwise the page is appended.
   */
  async function fetchBases(
    filters: KnowledgeBaseFilters = {},
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
      const response = await api.get<KnowledgeBaseListResponse>(`/knowledge/bases${qs ? `?${qs}` : ''}`);
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
  async function loadMore(filters: KnowledgeBaseFilters = {}): Promise<void> {
    await fetchBases(filters, { reset: false });
  }

  /** Retry a failed append (clears the pause flag, re-fetches the same page). */
  async function retryLoadMore(filters: KnowledgeBaseFilters = {}): Promise<void> {
    loadMoreErrored.value = false;
    await fetchBases(filters, { reset: false });
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

  // --- Single read ----------------------------------------------------------
  /**
   * Fetch ONE base (`GET /knowledge/bases/{id}`). Read-only, NOT cached and NOT merged into the
   * list — the caller owns the result. Unlike the list rows, this response is guaranteed to
   * carry `entries_count` (the controller loads it explicitly).
   */
  async function fetchBase(id: string): Promise<KnowledgeBase> {
    const res = await api.get<KnowledgeBaseResponse>(`/knowledge/bases/${id}`);
    return res.data;
  }

  // --- The OPEN base --------------------------------------------------------
  /**
   * The base whose sections are being viewed. Held in the store, not in the layout, because all
   * four surfaces (reader / table / settings / editor) need the same copy — including
   * `metadata_schema`, which the metadata form renders from — and a deep link may reach any of them
   * with no list ever having been fetched.
   */
  const openBase = ref<KnowledgeBase | null>(null);
  const openBaseLoading = ref(false);
  const openBaseError = ref<string | null>(null);
  let openBaseToken = 0;

  async function fetchOpenBase(id: string): Promise<void> {
    const myToken = (openBaseToken += 1);
    openBaseLoading.value = true;
    openBaseError.value = null;
    try {
      const res = await api.get<KnowledgeBaseResponse>(`/knowledge/bases/${id}`);
      if (myToken !== openBaseToken) return;
      openBase.value = res.data;
    } catch (err: unknown) {
      if (myToken !== openBaseToken) return;
      openBase.value = null;
      openBaseError.value = extractMessage(err);
    } finally {
      if (myToken === openBaseToken) openBaseLoading.value = false;
    }
  }

  function clearOpenBase(): void {
    openBaseToken += 1;
    openBase.value = null;
    openBaseLoading.value = false;
    openBaseError.value = null;
  }

  // --- Create / update / delete --------------------------------------------
  /** Create a base (`POST /knowledge/bases`). Inserts it in name order. */
  async function createBase(payload: KnowledgeBaseWritePayload): Promise<KnowledgeBase> {
    const res = await api.post<KnowledgeBaseResponse>('/knowledge/bases', payload);
    const created = res.data;
    if (items.value.length > 0 || cursor.value !== null || !hasMore.value) {
      items.value = sortByName([created, ...items.value]);
    }
    return created;
  }

  /**
   * Update a base (`PATCH /knowledge/bases/{id}`). The payload is passed through UNTOUCHED —
   * see the contract note above: an absent key means unchanged, and adding one here would
   * silently escalate the request's required ability.
   */
  async function updateBase(id: string, payload: KnowledgeBaseWritePayload): Promise<KnowledgeBase> {
    const res = await api.patch<KnowledgeBaseResponse>(`/knowledge/bases/${id}`, payload);
    const updated = res.data;
    replaceInList(updated);
    // Keep the OPEN base in step, or the aside would keep showing the old name / schema after a
    // settings save — and the metadata form renders from that schema.
    if (openBase.value?.id === id) openBase.value = updated;
    return updated;
  }

  /** Trash a base (`DELETE /knowledge/bases/{id}`, 204). Drops it from the live list. */
  async function deleteBase(id: string): Promise<void> {
    await api.delete<void>(`/knowledge/bases/${id}`);
    removeFromList(id);
  }

  /**
   * Restore a trashed base (`POST /knowledge/bases/{id}/restore`). Drops it from the list, since
   * the only list that can be showing a trashed base is the TRASH — where a restored base no
   * longer belongs.
   */
  async function restoreBase(id: string): Promise<KnowledgeBase> {
    const res = await api.post<KnowledgeBaseResponse>(`/knowledge/bases/${id}/restore`, {});
    removeFromList(id);
    return res.data;
  }

  /** Permanently destroy a trashed base and everything under it (`DELETE …/force`, 204). */
  async function forceDeleteBase(id: string): Promise<void> {
    await api.delete<void>(`/knowledge/bases/${id}/force`);
    removeFromList(id);
  }

  // =========================================================================
  // ENTRIES
  //
  // ONE list state serves BOTH consumption modes of `GET /bases/{base}/entries`, because the two
  // surfaces that use it are different routes and never coexist:
  //   • the READER drains every cursor page (`drainAll`) — a contents panel that stops at 25 would
  //     give wrong prev/next at the page boundary and a wikilink whose target sits on page 2 would
  //     render as a red link that is not actually missing;
  //   • the TABLE appends page by page on scroll, like every other list in the app.
  // =========================================================================
  const entries = ref<KnowledgeEntryListItem[]>([]);
  const entriesCursor = ref<string | null>(null);
  const entriesHasMore = ref(true);
  const entriesLoading = ref(false);
  const entriesLoadingMore = ref(false);
  const entriesErrored = ref(false);
  const entriesError = ref<string | null>(null);
  const entriesLoadMoreErrored = ref(false);
  /**
   * True when a `drainAll` stopped at the page cap rather than at the end of the data. The reader
   * SAYS so instead of pretending its contents list is complete.
   */
  const entriesTruncated = ref(false);

  let entriesToken = 0;

  /**
   * Cap on how many pages one `drainAll` will follow (25/page → 1000 entries). A bound, not a
   * target: past it the reader tells the user to use the table, which is genuinely paginated.
   */
  const MAX_DRAIN_PAGES = 40;

  /** Entries keyed by slug — the wikilink resolver and the hover preview both read this. */
  const entriesBySlug = computed<Map<string, KnowledgeEntryListItem>>(() => {
    const map = new Map<string, KnowledgeEntryListItem>();
    for (const entry of entries.value) map.set(entry.slug, entry);
    return map;
  });

  /**
   * Fetch entries of a base.
   *
   * `drainAll` follows `next_cursor` until it runs out (or hits MAX_DRAIN_PAGES) and is what the
   * reader uses; without it exactly one page is fetched/appended.
   */
  async function fetchEntries(
    baseId: string,
    filters: KnowledgeEntryFilters = {},
    { reset = true, drainAll = false }: FetchOptions & { drainAll?: boolean } = {},
  ): Promise<void> {
    if (!reset && (entriesLoadingMore.value || entriesLoading.value || !entriesHasMore.value)) return;

    const myToken = (entriesToken += 1);
    if (reset) {
      entriesLoading.value = true;
      entries.value = [];
      entriesCursor.value = null;
      entriesHasMore.value = true;
      entriesTruncated.value = false;
    } else {
      entriesLoadingMore.value = true;
    }
    entriesErrored.value = false;
    entriesError.value = null;
    entriesLoadMoreErrored.value = false;

    try {
      let pages = 0;
      let cursorForPage = reset ? null : entriesCursor.value;

      do {
        const params = serializeEntryFilters(filters);
        if (cursorForPage) params.set('cursor', cursorForPage);
        const qs = params.toString();

        const response = await api.get<KnowledgeEntryListResponse>(
          `/knowledge/bases/${baseId}/entries${qs ? `?${qs}` : ''}`,
        );
        if (myToken !== entriesToken) return; // superseded

        const incoming = response.data ?? [];
        entries.value =
          reset && pages === 0 ? incoming : [...entries.value, ...incoming];

        cursorForPage = response.meta?.next_cursor ?? null;
        entriesCursor.value = cursorForPage;
        entriesHasMore.value = cursorForPage !== null;
        pages += 1;

        if (drainAll && cursorForPage !== null && pages >= MAX_DRAIN_PAGES) {
          entriesTruncated.value = true;
          break;
        }
      } while (drainAll && cursorForPage !== null);
    } catch (err: unknown) {
      if (myToken !== entriesToken) return;
      entriesError.value = extractMessage(err);
      if (reset) {
        entriesErrored.value = true;
        entriesHasMore.value = false;
      } else {
        entriesLoadMoreErrored.value = true;
      }
    } finally {
      if (myToken === entriesToken) {
        entriesLoading.value = false;
        entriesLoadingMore.value = false;
      }
    }
  }

  /** Append the next entry page (table infinite scroll). */
  async function loadMoreEntries(baseId: string, filters: KnowledgeEntryFilters = {}): Promise<void> {
    await fetchEntries(baseId, filters, { reset: false });
  }

  /** Retry a failed entry append. */
  async function retryLoadMoreEntries(baseId: string, filters: KnowledgeEntryFilters = {}): Promise<void> {
    entriesLoadMoreErrored.value = false;
    await fetchEntries(baseId, filters, { reset: false });
  }

  /** Replace one entry row in the list, in place (after a write). Keeps `position` order. */
  function replaceEntryInList(entry: KnowledgeEntry | KnowledgeEntryListItem): void {
    const idx = entries.value.findIndex((e) => e.id === entry.id);
    if (idx < 0) return;
    const next = [...entries.value];
    // The full resource has `content` where a row has `excerpt`; keep the row's excerpt rather
    // than dropping it, so the contents panel and hover preview do not blank after a save.
    const excerpt = (entry as KnowledgeEntryListItem).excerpt ?? next[idx].excerpt;
    next[idx] = { ...(entry as KnowledgeEntryListItem), excerpt };
    entries.value = next.sort((a, b) => a.position - b.position || a.id.localeCompare(b.id));
  }

  function removeEntryFromList(id: string): void {
    entries.value = entries.value.filter((e) => e.id !== id);
  }

  function resetEntries(): void {
    entriesToken += 1; // orphan any flight
    entries.value = [];
    entriesCursor.value = null;
    entriesHasMore.value = true;
    entriesLoading.value = false;
    entriesLoadingMore.value = false;
    entriesErrored.value = false;
    entriesError.value = null;
    entriesLoadMoreErrored.value = false;
    entriesTruncated.value = false;
  }

  // --- One entry ------------------------------------------------------------
  const entry = ref<KnowledgeEntry | null>(null);
  const entryLoading = ref(false);
  const entryError = ref<string | null>(null);
  let entryToken = 0;

  /** Fetch ONE entry in full (`GET /knowledge/entries/{id}`) — content + links + backlinks. */
  async function fetchEntry(id: string): Promise<void> {
    const myToken = (entryToken += 1);
    entryLoading.value = true;
    entryError.value = null;
    try {
      const res = await api.get<KnowledgeEntryResponse>(`/knowledge/entries/${id}`);
      if (myToken !== entryToken) return;
      entry.value = res.data;
    } catch (err: unknown) {
      if (myToken !== entryToken) return;
      entry.value = null;
      entryError.value = extractMessage(err);
    } finally {
      if (myToken === entryToken) entryLoading.value = false;
    }
  }

  /** Drop the open entry (route change). */
  function resetEntry(): void {
    entryToken += 1;
    entry.value = null;
    entryLoading.value = false;
    entryError.value = null;
  }





  /**
   * Re-queue indexing for an entry whose last run did not finish (B6c).
   *
   * The response is the FULL entry with `index.status` already moved to `pending` and `can_retry`
   * already false, so the caller renders the change from THIS payload and never refetches — a
   * refetch would race the worker and could show the state moving backwards.
   *
   * 422 `{errors: {index: [msg]}}` when the state does not qualify; it bubbles up for the caller to
   * show the server's own message.
   */
  async function retryIndex(entryId: string): Promise<KnowledgeEntry> {
    const res = await api.post<KnowledgeEntryResponse>(`/knowledge/entries/${entryId}/retry-index`, {});
    entry.value = res.data;
    replaceEntryInList(res.data);
    return res.data;
  }

  /** Permanently destroy a trashed entry, its history and its index (204). */
  async function forceDeleteEntry(id: string): Promise<void> {
    await api.delete<void>(`/knowledge/entries/${id}/force`);
    removeEntryFromList(id);
  }


  // --- Revisions ------------------------------------------------------------
  const revisions = ref<KnowledgeEntryRevision[]>([]);
  const revisionsLoading = ref(false);
  const revisionsError = ref<string | null>(null);
  let revisionsToken = 0;

  /** The whole history of an entry, newest first (unpaginated by contract). */
  async function fetchRevisions(entryId: string): Promise<void> {
    const myToken = (revisionsToken += 1);
    revisionsLoading.value = true;
    revisionsError.value = null;
    revisions.value = [];
    try {
      const res = await api.get<KnowledgeRevisionListResponse>(`/knowledge/entries/${entryId}/revisions`);
      if (myToken !== revisionsToken) return;
      revisions.value = res.data ?? [];
    } catch (err: unknown) {
      if (myToken !== revisionsToken) return;
      revisionsError.value = extractMessage(err);
    } finally {
      if (myToken === revisionsToken) revisionsLoading.value = false;
    }
  }


  function resetRevisions(): void {
    revisionsToken += 1;
    revisions.value = [];
    revisionsLoading.value = false;
    revisionsError.value = null;
  }

  // --- Links (similarity dismiss / undo) ------------------------------------
  /**
   * Dismiss a machine-proposed edge. The row STAYS on screen carrying `dismissed_at` — a dismissal
   * is a reversible stamp, and a row that vanished would leave the undo with nothing to attach to.
   */
  async function dismissLink(linkId: string): Promise<KnowledgeLink> {
    const res = await api.post<KnowledgeLinkResponse>(`/knowledge/links/${linkId}/dismiss`, {});
    patchLink(res.data);
    return res.data;
  }

  /** Undo a dismissal — the same path with DELETE, since nothing was created or destroyed. */
  async function undismissLink(linkId: string): Promise<KnowledgeLink> {
    const res = await api.delete<KnowledgeLinkResponse>(`/knowledge/links/${linkId}/dismiss`);
    patchLink(res.data);
    return res.data;
  }

  // --- Typed relations -------------------------------------------------------------------
  //
  // Kept as their OWN slice rather than folded into the open entry, because a relation is not a
  // property of one entry: it is a statement about two, and both ends see it. Hanging it off
  // `entry.links` would have made "the relations of the entry I am looking at" the only question
  // this store could answer, and the graph and the composer both ask others.

  /** The relations of the entry currently open in the reader. */
  const relations = ref<KnowledgeRelation[]>([]);
  const relationsLoading = ref(false);
  const relationsError = ref<string | null>(null);
  /** Whether the last fetch asked for ended/retracted rows too. */
  const relationsIncludeHistorical = ref(false);

  async function fetchRelations(entryId: string, includeHistorical = false): Promise<void> {
    relationsLoading.value = true;
    relationsError.value = null;
    relationsIncludeHistorical.value = includeHistorical;
    try {
      const res = await api.get<{ data: KnowledgeRelation[] }>(
        `/knowledge/entries/${entryId}/relations${includeHistorical ? '?include_historical=1' : ''}`,
      );
      relations.value = res.data ?? [];
    } catch (err: unknown) {
      relationsError.value = extractMessage(err);
      relations.value = [];
    } finally {
      relationsLoading.value = false;
    }
  }

  function resetRelations(): void {
    relations.value = [];
    relationsError.value = null;
    relationsLoading.value = false;
    relationsIncludeHistorical.value = false;
  }




  function upsertRelation(relation: KnowledgeRelation): void {
    const index = relations.value.findIndex((row) => row.id === relation.id);
    if (index === -1) {
      relations.value = [...relations.value, relation];

      return;
    }
    const next = [...relations.value];
    next[index] = relation;
    relations.value = next;
  }

  /**
   * Drop a link from the open entry entirely, rather than marking it dismissed.
   *
   * Used for a PROMOTION: the suggestion has not been "refused pending reconsideration", it has
   * been answered, and leaving it in the list with an undo would offer to un-answer a decision that
   * created a durable statement.
   */
  function removeLinkLocally(linkId: string): void {
    const current = entry.value;
    if (!current) return;
    entry.value = {
      ...current,
      links: current.links?.filter((l) => l.id !== linkId),
      backlinks: current.backlinks?.filter((l) => l.id !== linkId),
    };
  }

  /** Replace an edge inside the open entry's `links` / `backlinks`, in place. */
  function patchLink(link: KnowledgeLink): void {
    const current = entry.value;
    if (!current) return;
    const swap = (list?: KnowledgeLink[]): KnowledgeLink[] | undefined =>
      list?.map((l) => (l.id === link.id ? { ...l, ...link } : l));
    entry.value = { ...current, links: swap(current.links), backlinks: swap(current.backlinks) };
  }

  // --- Graph ----------------------------------------------------------------
  //
  //   GET /knowledge/bases/{base}/graph?entry=&depth=&sources=a,b&min_score=&include_dismissed=1
  //     → { data: { center, nodes[], edges[], ghosts[], truncated } }
  //
  // ONE payload, held whole: the response is already capped at 60 nodes server-side, so there is
  // no pagination to reconcile and no partial state to merge.
  const graph = ref<KnowledgeGraphData | null>(null);
  const graphLoading = ref(false);
  /**
   * A re-centre / depth change while a graph is ALREADY on screen. Tracked separately from
   * `graphLoading` so the canvas can dim instead of collapsing to a skeleton — a full-screen
   * flicker on every double-click is what makes a graph feel like it forgets where you were.
   */
  const graphRefreshing = ref(false);
  const graphErrored = ref(false);
  const graphError = ref<string | null>(null);
  let graphToken = 0;

  async function fetchGraph(
    baseId: string,
    filters: KnowledgeGraphFilters = {},
    { refresh = false }: { refresh?: boolean } = {},
  ): Promise<void> {
    const myToken = (graphToken += 1);
    // A refresh keeps the old picture on screen; a cold load shows the skeleton.
    if (refresh && graph.value) graphRefreshing.value = true;
    else graphLoading.value = true;
    graphErrored.value = false;
    graphError.value = null;

    try {
      const qs = serializeGraphFilters(filters).toString();
      const res = await api.get<KnowledgeGraphResponse>(
        `/knowledge/bases/${baseId}/graph${qs ? `?${qs}` : ''}`,
      );
      if (myToken !== graphToken) return; // superseded by a newer request
      graph.value = res.data;
    } catch (err: unknown) {
      if (myToken !== graphToken) return;
      graph.value = null;
      graphErrored.value = true;
      graphError.value = extractMessage(err);
    } finally {
      if (myToken === graphToken) {
        graphLoading.value = false;
        graphRefreshing.value = false;
      }
    }
  }

  /**
   * Drop one edge from the graph in place — the optimistic half of a similarity dismissal.
   *
   * The NODES stay: a dismissal rejects a suggested relation, not the entry at the other end, and
   * a node that vanished with its edge would make an undo look like it resurrects a deleted entry.
   */
  function removeGraphEdge(linkId: string): void {
    const current = graph.value;
    if (!current) return;
    graph.value = { ...current, edges: current.edges.filter((edge) => edge.id !== linkId) };
  }

  function resetGraph(): void {
    graphToken += 1;
    graph.value = null;
    graphLoading.value = false;
    graphRefreshing.value = false;
    graphErrored.value = false;
    graphError.value = null;
  }

  // --- The AI COMPOSER -------------------------------------------------------
  //
  //   GET    /knowledge/bases/{base}/compose-availability → availability (asked BEFORE the form)
  //   POST   /knowledge/bases/{base}/draft-sessions       → 201 session (already `generating`)
  //   GET    /knowledge/draft-sessions/{id}               → the session + its drafts
  //   POST   /knowledge/draft-sessions/{id}/refine        → 200 session
  //   POST   /knowledge/draft-sessions/{id}/accept        → 200 { accepted[], conflicts[] }
  //   GET    /knowledge/draft-sessions/{id}/relations     → { data: graph-shaped preview }
  //   DELETE /knowledge/draft-sessions/{id}               → 204 (abandon)
  //   DELETE /knowledge/entries/{id}/draft                → 204 (reject ONE draft)
  //   GET    /knowledge/entries/{id}/draft-diff?baseline= → the two texts to diff
  //
  // NO POLLING ANYWHERE. The session settles on the `knowledge-draft-session.updated` broadcast —
  // see `pages/knowledge/compose/useComposeSettle.ts`. This store only ever fetches on demand.
  const composeAvailability = ref<KnowledgeComposeAvailability | null>(null);
  const composeAvailabilityLoading = ref(false);
  const composeAvailabilityErrored = ref(false);

  const session = ref<KnowledgeDraftSession | null>(null);
  const sessionLoading = ref(false);
  const sessionErrored = ref(false);
  const sessionError = ref<string | null>(null);
  /** True when the session id in the URL is one the server no longer holds (404 on fetch). */
  const sessionMissing = ref(false);
  let sessionToken = 0;

  /**
   * Ask whether the composer can run. Never throws: a failed availability check must not blank the
   * screen, so it resolves to "errored" and the view falls back to letting the user try (the POST
   * is authoritative and will refuse honestly).
   */
  async function fetchComposeAvailability(baseId: string): Promise<void> {
    composeAvailabilityLoading.value = true;
    composeAvailabilityErrored.value = false;
    try {
      composeAvailability.value = await api.get<KnowledgeComposeAvailability>(
        `/knowledge/bases/${baseId}/compose-availability`,
      );
    } catch {
      composeAvailability.value = null;
      composeAvailabilityErrored.value = true;
    } finally {
      composeAvailabilityLoading.value = false;
    }
  }

  /** Open a session and queue the first composition. A 429 budget refusal bubbles up typed. */
  async function startDraftSession(
    baseId: string,
    payload: KnowledgeDraftSessionPayload,
  ): Promise<KnowledgeDraftSession> {
    const res = await api.post<KnowledgeDraftSessionResponse>(
      `/knowledge/bases/${baseId}/draft-sessions`,
      payload,
    );
    session.value = res.data;
    sessionMissing.value = false;
    return res.data;
  }

  /**
   * Fetch one session. A 404 is a DISTINCT state (`sessionMissing`), not an error: an expired or
   * abandoned session is a normal thing to deep-link into, and it has its own copy.
   */
  async function fetchDraftSession(id: string): Promise<KnowledgeDraftSession | null> {
    const myToken = (sessionToken += 1);
    sessionLoading.value = true;
    sessionErrored.value = false;
    sessionError.value = null;
    try {
      const res = await api.get<KnowledgeDraftSessionResponse>(`/knowledge/draft-sessions/${id}`);
      if (myToken !== sessionToken) return null;
      session.value = res.data;
      sessionMissing.value = false;
      return res.data;
    } catch (err: unknown) {
      if (myToken !== sessionToken) return null;
      if (statusOf(err) === 404) {
        session.value = null;
        sessionMissing.value = true;
        return null;
      }
      sessionErrored.value = true;
      sessionError.value = extractMessage(err);
      return null;
    } finally {
      if (myToken === sessionToken) sessionLoading.value = false;
    }
  }

  /**
   * Ask for a revision of the whole board. When a run is already in flight the server answers with
   * the session still `generating` — a no-op rather than a 409, so the client simply keeps waiting.
   */
  async function refineDraftSession(id: string, instruction: string): Promise<KnowledgeDraftSession> {
    const res = await api.post<KnowledgeDraftSessionResponse>(
      `/knowledge/draft-sessions/${id}/refine`,
      { instruction },
    );
    // A revision CONSUMES the widened context, and the server has already cleared
    // `context_expanded_at` in the payload it just returned — so adopting this response is all it
    // takes to re-offer the action. No second fetch, no client-side bookkeeping to drift.
    session.value = res.data;
    return res.data;
  }

  /**
   * Publish the chosen drafts. PARTIAL success is the normal case: shadows whose target moved come
   * back as `conflicts` next to everything that did publish.
   */
  /**
   * Publish the chosen drafts, and the chosen graph operations.
   *
   * `graphOpKeys` is THREE-VALUED and the middle case is the trap:
   *
   *   undefined  the key is OMITTED → the server applies every operation. This is what a caller
   *              that has nothing to say about relations wants, and it is the backward-compatible
   *              reading.
   *   []         the key is SENT EMPTY → apply NONE. Deliberately not the same as omitting it.
   *   [a, b]     exactly those; the rest come back as `skipped[].code = 'not_selected'`.
   *
   * Collapsing the first two — the natural `if (keys.length) …` — would make "I unticked
   * everything" silently mean "apply everything", which is the exact inversion of the reviewer's
   * intent and would be invisible until somebody audited the graph.
   *
   * `entryIds` may be EMPTY: a graph-only acceptance is a real case now.
   */
  async function acceptDrafts(
    id: string,
    entryIds: string[],
    status: 'approved' | 'draft',
    graphOpKeys?: string[],
  ): Promise<KnowledgeDraftAcceptResult> {
    return api.post<KnowledgeDraftAcceptResult>(`/knowledge/draft-sessions/${id}/accept`, {
      entry_ids: entryIds,
      status,
      ...(graphOpKeys === undefined ? {} : { graph_op_keys: graphOpKeys }),
    });
  }

  /** Throw ONE draft away (204). The board drops it locally so the undo has something to restore. */
  async function rejectDraft(entryId: string): Promise<void> {
    await api.delete<void>(`/knowledge/entries/${entryId}/draft`);
  }

  /**
   * Point a SHADOW draft at its target's CURRENT revision.
   *
   * COSTS NO AI, and the UI says so: the server only rewrites `target_revision_id` — the proposal's
   * text is untouched. That is the honest division of labour. A machine cannot know whether a
   * human's edit and a pending proposal conflict in MEANING, so it does not pretend to merge them;
   * it re-points the comparison and hands the reviewer a diff against what is really there.
   *
   * Returns the refreshed DRAFT (not the session), so the caller swaps one card rather than the board.
   */
  async function rebaseDraft(sessionId: string, entryId: string): Promise<KnowledgeDraftEntry> {
    const res = await api.post<{ data: KnowledgeDraftEntry }>(
      `/knowledge/draft-sessions/${sessionId}/rebase`,
      { entry_id: entryId },
    );
    return res.data;
  }

  /**
   * Retrieve more context for the NEXT run.
   *
   * SPENDS: one metered embedding pass, which is exactly why it is an explicit action instead of
   * something a refinement does quietly. A 429 comes back typed and the caller shows the budget
   * banner rather than a generic failure.
   */
  async function expandDraftContext(sessionId: string): Promise<KnowledgeDraftSession> {
    const res = await api.post<KnowledgeDraftSessionResponse>(
      `/knowledge/draft-sessions/${sessionId}/expand-context`,
      {},
    );
    // The response carries `context_expanded_at`, which is what disables the paid action — for
    // every reviewer looking at this session, and across a reload. Nothing is remembered here.
    session.value = res.data;
    return res.data;
  }

  /** Abandon the whole session and every draft on it (204). */
  async function abandonDraftSession(id: string): Promise<void> {
    await api.delete<void>(`/knowledge/draft-sessions/${id}`);
    if (session.value?.id === id) session.value = null;
  }

  /** The two TEXTS a draft diff compares. The CLIENT renders the diff — never per keystroke. */
  async function fetchDraftDiff(
    entryId: string,
    baseline: KnowledgeDraftDiffBaseline,
  ): Promise<KnowledgeDraftDiff> {
    return api.get<KnowledgeDraftDiff>(
      `/knowledge/entries/${entryId}/draft-diff?baseline=${encodeURIComponent(baseline)}`,
    );
  }

  /** The relations PREVIEW — the base graph's shape, so the same canvas draws it. */
  async function fetchDraftRelations(id: string): Promise<KnowledgeDraftRelations> {
    const res = await api.get<KnowledgeDraftRelationsResponse>(
      `/knowledge/draft-sessions/${id}/relations`,
    );
    return res.data;
  }

  function resetComposeSession(): void {
    sessionToken += 1;
    session.value = null;
    sessionLoading.value = false;
    sessionErrored.value = false;
    sessionError.value = null;
    sessionMissing.value = false;
  }

  // --- Search ---------------------------------------------------------------
  const searchResults = ref<KnowledgeSearchResult[]>([]);
  const searchLoading = ref(false);
  const searchErrored = ref(false);
  const searchError = ref<string | null>(null);
  const searchQuery = ref('');
  const searchHasMore = ref(false);
  const searchCount = ref(0);
  const searchLimit = ref(0);
  /** True when the semantic leg did not run — the UI says results are keyword-only, and why. */
  const searchVectorSkipped = ref(false);
  const searchVectorReason = ref<KnowledgeVectorSkipReason>(null);
  let searchToken = 0;

  /**
   * Run a hybrid search. `baseId` scopes it to one base; omit it for the workspace-wide endpoint.
   *
   * COST NOTE: every call embeds the query, i.e. spends real money. Callers MUST debounce (the
   * search screen uses a hard 300 ms) — this store deliberately does not debounce for them, so the
   * spend stays visible at the call site rather than hidden behind a helper.
   */
  async function searchKnowledge(
    query: string,
    { baseId, statuses, limit }: { baseId?: string; statuses?: KnowledgeEntryStatus[]; limit?: number } = {},
  ): Promise<void> {
    const term = query.trim();
    if (term === '') {
      resetSearch();
      return;
    }

    const myToken = (searchToken += 1);
    searchLoading.value = true;
    searchErrored.value = false;
    searchError.value = null;

    const params = new URLSearchParams();
    params.set('q', term);
    for (const status of statuses ?? []) params.append('status[]', status);
    if (limit != null) params.set('limit', String(limit));

    const path = baseId ? `/knowledge/bases/${baseId}/search` : '/knowledge/search';

    try {
      const res = await api.get<KnowledgeSearchResponse>(`${path}?${params.toString()}`);
      if (myToken !== searchToken) return;
      searchResults.value = res.data ?? [];
      searchQuery.value = res.meta?.query ?? term;
      searchCount.value = res.meta?.count ?? searchResults.value.length;
      searchLimit.value = res.meta?.limit ?? 0;
      searchHasMore.value = res.meta?.has_more ?? false;
      searchVectorSkipped.value = res.meta?.vector_search_skipped ?? false;
      searchVectorReason.value = res.meta?.vector_search_reason ?? null;
    } catch (err: unknown) {
      if (myToken !== searchToken) return;
      searchResults.value = [];
      searchErrored.value = true;
      searchError.value = extractMessage(err);
    } finally {
      if (myToken === searchToken) searchLoading.value = false;
    }
  }

  function resetSearch(): void {
    searchToken += 1;
    searchResults.value = [];
    searchLoading.value = false;
    searchErrored.value = false;
    searchError.value = null;
    searchQuery.value = '';
    searchHasMore.value = false;
    searchCount.value = 0;
    searchLimit.value = 0;
    searchVectorSkipped.value = false;
    searchVectorReason.value = null;
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
    fetchBases,
    loadMore,
    retryLoadMore,
    resetAll,
    // list mutations (exposed for tests / advanced callers)
    replaceInList,
    removeFromList,
    // single read
    fetchBase,
    // the open base (module shell + every section)
    openBase,
    openBaseLoading,
    openBaseError,
    fetchOpenBase,
    clearOpenBase,
    // create / update / delete / lifecycle
    createBase,
    updateBase,
    deleteBase,
    restoreBase,
    forceDeleteBase,

    // --- entries: list state
    entries,
    entriesCursor,
    entriesHasMore,
    entriesLoading,
    entriesLoadingMore,
    entriesErrored,
    entriesError,
    entriesLoadMoreErrored,
    entriesTruncated,
    entriesBySlug,
    // --- entries: list actions
    fetchEntries,
    loadMoreEntries,
    retryLoadMoreEntries,
    resetEntries,
    replaceEntryInList,
    removeEntryFromList,
    // --- one entry
    entry,
    entryLoading,
    entryError,
    fetchEntry,
    resetEntry,
    forceDeleteEntry,
    retryIndex,
    // --- revisions
    revisions,
    revisionsLoading,
    revisionsError,
    fetchRevisions,
    resetRevisions,
    // --- links
    dismissLink,
    undismissLink,
    // --- typed relations
    relations,
    relationsLoading,
    relationsError,
    relationsIncludeHistorical,
    fetchRelations,
    resetRelations,
    // --- the AI composer
    composeAvailability,
    composeAvailabilityLoading,
    composeAvailabilityErrored,
    session,
    sessionLoading,
    sessionErrored,
    sessionError,
    sessionMissing,
    fetchComposeAvailability,
    startDraftSession,
    fetchDraftSession,
    refineDraftSession,
    acceptDrafts,
    rejectDraft,
    rebaseDraft,
    expandDraftContext,
    abandonDraftSession,
    fetchDraftDiff,
    fetchDraftRelations,
    resetComposeSession,
    // --- graph
    graph,
    graphLoading,
    graphRefreshing,
    graphErrored,
    graphError,
    fetchGraph,
    removeGraphEdge,
    resetGraph,
    // --- search
    searchResults,
    searchLoading,
    searchErrored,
    searchError,
    searchQuery,
    searchHasMore,
    searchCount,
    searchLimit,
    searchVectorSkipped,
    searchVectorReason,
    searchKnowledge,
    resetSearch,
  };
});
