// Publishing store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the publications list + detail: one cursor-paginated list, the
// per-status counts, and the writes (create / update / schedule / reconcile / delete). The
// PAGE owns filter state and passes it in — the same division `stores/workflows.ts` and
// `stores/tasks.ts` use.
//
// Backend contract (VERIFIED against app/modules/Publishing + docs/backend/publishing-api.md
// — do NOT invent fields):
//   GET    /publishing/counts                        → { data: { counts{7}, total, needs_attention } }
//   GET    /publishing/publications?status&platform[]&search&scheduled_from&scheduled_to&cursor
//                                                    → { data: Publication[], meta: { next_cursor } }
//   POST   /publishing/publications                  → 201 { data: Publication }   (always a DRAFT)
//   GET    /publishing/publications/{id}             → { data: Publication }
//   PUT    /publishing/publications/{id}             → { data: Publication }   (WHOLE-ROW write)
//   POST   /publishing/publications/{id}/schedule    → { data: Publication }   ({scheduled_at} required)
//   POST   /publishing/publications/{id}/reconcile   → { data: Publication }   (no body, throttled 6/min)
//   DELETE /publishing/publications/{id}             → 204
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// THE COUNTS ARE NOT ALLOWED TO DEFAULT TO ZERO
// ─────────────────────────────────────────────────────────────────────────────────────────
// `counts` is `null` until a successful response and goes back to `null` on failure. A
// `?? 0` anywhere in this file would turn "we could not count" into "there is nothing" — on
// a tab bar whose whole job is to say how much is waiting. The tabs render no badge at all
// when this is null, and the page says why.
//
// ─────────────────────────────────────────────────────────────────────────────────────────
// ARMING HAS TWO OUTCOMES AND BOTH ARE 200
// ─────────────────────────────────────────────────────────────────────────────────────────
// `POST …/schedule` on a draft with an approval pipeline attached does NOT arm it: it parks
// the moment and opens a review, answering 200 with the row still a `draft` and
// `is_in_approval: true`. The store returns the resource unchanged and lets
// `scheduleOutcomeOf()` name what happened — a store that reported "scheduled" for both
// would make the screen promise a publication nobody has approved.
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api } from '../lib/api';
import { useAuthStore } from './auth';
import type {
  Publication,
  PublicationCounts,
  PublicationCountsResponse,
  PublicationFilters,
  PublicationListResponse,
  PublicationResponse,
  PublicationWritePayload,
} from '../../pages/publishing/types';

interface FetchOptions {
  reset?: boolean;
}

/**
 * Serialize the page's filters into URLSearchParams, arrays as REPEATED `key[]=` entries —
 * the convention `stores/tasks.ts` established and the shape Laravel's validator reads.
 * Empty / null / '' are skipped so a cleared control never narrows the query.
 */
export function serializeFilters(filters: PublicationFilters): URLSearchParams {
  const params = new URLSearchParams();

  // `all` is the ABSENCE of the param, never `status=all` — the server knows no such value.
  if (filters.status) params.append('status', filters.status);
  if (filters.search != null && filters.search !== '') params.append('search', filters.search);
  if (filters.scheduled_from) params.append('scheduled_from', filters.scheduled_from);
  if (filters.scheduled_to) params.append('scheduled_to', filters.scheduled_to);
  for (const platform of filters.platform ?? []) {
    if (platform) params.append('platform[]', platform);
  }

  return params;
}

/** The same query WITHOUT `status` — what `GET /counts` answers for (it ignores it anyway). */
function countsParams(filters: PublicationFilters): URLSearchParams {
  return serializeFilters({ ...filters, status: undefined });
}

export const usePublishingStore = defineStore('next-publishing', () => {
  // --- List ----------------------------------------------------------------
  const items = ref<Publication[]>([]);
  const cursor = ref<string | null>(null);
  const hasMore = ref(true);
  const loading = ref(false);
  const loadingMore = ref(false);
  const errored = ref(false);
  const error = ref<string | null>(null);
  /**
   * An APPEND failure is tracked apart from the first-page `errored` so the pages already
   * on screen stay there and the sentinel pauses until the reader retries.
   */
  const loadMoreErrored = ref(false);

  // A monotonic token: a reset always supersedes work in flight, so rapid filter changes
  // can never leave a stale page behind (the pattern in `stores/tasks.ts`).
  let token = 0;

  // --- Counts --------------------------------------------------------------
  /** `null` means "we do not know" and is NEVER rendered as zero. */
  const counts = ref<PublicationCounts | null>(null);
  const countsErrored = ref(false);
  let countsToken = 0;

  /** The navigation badge's number, or null when unknown. Server-computed, never summed. */
  const needsAttention = computed<number | null>(() => counts.value?.needs_attention ?? null);

  // --- Detail --------------------------------------------------------------
  const detail = ref<Publication | null>(null);
  const detailLoading = ref(false);
  const detailError = ref<string | null>(null);
  /** The HTTP status of the last detail failure — 404 gets its own, concrete screen. */
  const detailStatus = ref<number | null>(null);

  // --- The workspace's clock -----------------------------------------------
  /**
   * The zone every moment in this module is READ IN, fetched from the workspace resource
   * (`GET /workspaces/{id}` → `timezone`), because the auth context does not carry it.
   *
   * `null` is MEANINGFUL and is not a failure: it says "inherit the application clock",
   * which this client genuinely does not know. The composer then says so in words rather
   * than naming a zone — and it must never substitute the BROWSER's zone, which would be a
   * confident answer to a question nobody asked.
   */
  const timezone = ref<string | null>(null);
  const timezoneLoaded = ref(false);

  function statusOf(err: unknown): number | null {
    return (err as { response?: { status?: number } })?.response?.status ?? null;
  }

  function messageOf(err: unknown): string | null {
    const data = (err as { response?: { data?: { message?: string } } })?.response?.data;
    return typeof data?.message === 'string' && data.message !== '' ? data.message : null;
  }

  // --- List reconciliation --------------------------------------------------
  /**
   * Put a fresh row into the list in place, or prepend it when it is new.
   *
   * Every write in this module answers with the FULL resource, so a refetch after a write is
   * never necessary — and after a reconcile it would be actively wrong: the response IS the
   * new truth, and a second GET could race the automatic probe.
   */
  function upsertIntoList(publication: Publication): void {
    const index = items.value.findIndex((p) => p.id === publication.id);
    if (index >= 0) {
      const next = [...items.value];
      next[index] = publication;
      items.value = next;
    } else {
      // The list is newest-CREATED first, so a new row belongs at the top.
      items.value = [publication, ...items.value];
    }
    if (detail.value?.id === publication.id) detail.value = publication;
  }

  function removeFromList(id: string): void {
    items.value = items.value.filter((p) => p.id !== id);
    if (detail.value?.id === id) detail.value = null;
  }

  // --- List actions ---------------------------------------------------------
  async function fetchPublications(
    filters: PublicationFilters = {},
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    if (!reset && (loadingMore.value || loading.value || !hasMore.value)) return;

    const myToken = (token += 1);
    if (reset) {
      loading.value = true;
      errored.value = false;
      error.value = null;
      loadMoreErrored.value = false;
      cursor.value = null;
      hasMore.value = true;
    } else {
      loadingMore.value = true;
      loadMoreErrored.value = false;
    }

    const params = serializeFilters(filters);
    if (!reset && cursor.value) params.append('cursor', cursor.value);

    try {
      const res = await api.get<PublicationListResponse>(`/publishing/publications?${params.toString()}`);
      if (myToken !== token) return;

      const page = res.data ?? [];
      items.value = reset ? page : [...items.value, ...page];
      cursor.value = res.meta?.next_cursor ?? null;
      hasMore.value = cursor.value !== null;
    } catch (err) {
      if (myToken !== token) return;
      if (reset) {
        errored.value = true;
        error.value = messageOf(err);
        items.value = [];
      } else {
        // Keep `cursor`/`hasMore` intact so the same page can be asked for again.
        loadMoreErrored.value = true;
      }
      throw err;
    } finally {
      if (myToken === token) {
        loading.value = false;
        loadingMore.value = false;
      }
    }
  }

  async function loadMore(filters: PublicationFilters = {}): Promise<void> {
    await fetchPublications(filters, { reset: false }).catch(() => undefined);
  }

  async function retryLoadMore(filters: PublicationFilters = {}): Promise<void> {
    loadMoreErrored.value = false;
    await loadMore(filters);
  }

  /**
   * The tab counts. Refetched WITH the list on every filter change: `counts` honours every
   * filter except `status`, so a bar left un-refetched lies in a way nobody can see.
   */
  async function fetchCounts(filters: PublicationFilters = {}): Promise<void> {
    const myToken = (countsToken += 1);
    try {
      const params = countsParams(filters);
      const query = params.toString();
      const res = await api.get<PublicationCountsResponse>(
        `/publishing/counts${query ? `?${query}` : ''}`,
      );
      if (myToken !== countsToken) return;
      counts.value = res.data;
      countsErrored.value = false;
    } catch (err) {
      if (myToken !== countsToken) return;
      // Back to "we do not know" — the badges disappear rather than claiming zero.
      counts.value = null;
      countsErrored.value = true;
      throw err;
    }
  }

  // --- Detail ---------------------------------------------------------------
  async function fetchPublication(id: string): Promise<Publication | null> {
    detailLoading.value = true;
    detailError.value = null;
    detailStatus.value = null;
    try {
      const res = await api.get<PublicationResponse>(`/publishing/publications/${id}`);
      detail.value = res.data;
      // Keep an open list row in step with what the detail just learned.
      if (items.value.some((p) => p.id === id)) upsertIntoList(res.data);
      return res.data;
    } catch (err) {
      detail.value = null;
      detailStatus.value = statusOf(err);
      detailError.value = messageOf(err);
      throw err;
    } finally {
      detailLoading.value = false;
    }
  }

  // --- Writes ---------------------------------------------------------------
  async function createPublication(payload: PublicationWritePayload): Promise<Publication> {
    const res = await api.post<PublicationResponse>('/publishing/publications', payload);
    upsertIntoList(res.data);
    return res.data;
  }

  /**
   * A WHOLE-ROW write. The payload type makes that unavoidable: `PublicationService::
   * attributesFrom()` assigns title, body, platform, platform_connection_id, scheduled_at,
   * media and options on EVERY update, so a key the caller omits is nulled in the database.
   * The quietest way to lose a publication is a `PUT` without `scheduled_at` on a
   * `scheduled` row: the status stays `scheduled`, the moment becomes null, and the sweep
   * (`scheduled_at <= now()`) never picks it up again — armed forever, going nowhere,
   * without one message anywhere.
   */
  async function updatePublication(id: string, payload: PublicationWritePayload): Promise<Publication> {
    const res = await api.put<PublicationResponse>(`/publishing/publications/${id}`, payload);
    upsertIntoList(res.data);
    return res.data;
  }

  /**
   * Arm it — or, when a pipeline is attached and unapproved, send it to a reviewer. Both are
   * a 200 with the full resource; the caller names the outcome with `scheduleOutcomeOf()`.
   */
  async function schedulePublication(id: string, scheduledAt: string): Promise<Publication> {
    const res = await api.post<PublicationResponse>(`/publishing/publications/${id}/schedule`, {
      scheduled_at: scheduledAt,
    });
    upsertIntoList(res.data);
    return res.data;
  }

  /**
   * Ask the platform whether the post exists. NO BODY: the whole act is "go and look", and
   * the one input this endpoint could take — a remote id from the client — is the one value
   * that must never arrive from outside.
   *
   * Three outcomes, all 200, told apart by the RESPONSE's `status`: `published` (found),
   * `failed` (proven absent — good news), or `needs_reconcile` unchanged (nothing was
   * learned, which is not an error).
   */
  async function reconcilePublication(id: string): Promise<Publication> {
    const res = await api.post<PublicationResponse>(`/publishing/publications/${id}/reconcile`);
    upsertIntoList(res.data);
    return res.data;
  }

  async function deletePublication(id: string): Promise<void> {
    await api.delete(`/publishing/publications/${id}`);
    removeFromList(id);
  }

  /**
   * Load the workspace's zone once. Best-effort: a failure leaves `timezone` null, which the
   * UI already has honest words for, and never blocks a screen.
   */
  async function loadTimezone(): Promise<void> {
    if (timezoneLoaded.value) return;
    const auth = useAuthStore();
    const id = auth.currentWorkspaceId;
    if (!id) return;
    timezoneLoaded.value = true;
    try {
      const res = await api.get<{ data: { timezone?: string | null } }>(`/workspaces/${id}`);
      const zone = res.data?.timezone;
      timezone.value = typeof zone === 'string' && zone !== '' ? zone : null;
    } catch {
      timezone.value = null;
    }
  }

  /**
   * Drop every cached page + the detail (workspace switch, or a screen leaving).
   *
   * THE CLOCK GOES TOO, and it is the entry that matters most. `timezoneLoaded` is a latch:
   * left standing across a workspace switch it keeps the PREVIOUS workspace's zone, and every
   * moment on the new one — the band's "Scheduled for…", the composer's wall clock, the
   * delivery card — is then read on a clock nobody on this team uses. Nothing on screen says
   * so; the times simply look plausible and are wrong by an hour or nine.
   *
   * The counts go for the same reason in miniature: they are per workspace, and a tab bar
   * carrying the old numbers is a bar that says how much is waiting somewhere else.
   */
  function resetAll(): void {
    token += 1;
    countsToken += 1;
    items.value = [];
    cursor.value = null;
    hasMore.value = true;
    loading.value = false;
    loadingMore.value = false;
    errored.value = false;
    error.value = null;
    loadMoreErrored.value = false;
    detail.value = null;
    detailError.value = null;
    detailStatus.value = null;
    counts.value = null;
    countsErrored.value = false;
    timezone.value = null;
    timezoneLoaded.value = false;
  }

  return {
    // list
    items,
    cursor,
    hasMore,
    loading,
    loadingMore,
    errored,
    error,
    loadMoreErrored,
    // counts
    counts,
    countsErrored,
    needsAttention,
    // detail
    detail,
    detailLoading,
    detailError,
    detailStatus,
    // workspace clock
    timezone,
    // actions
    fetchPublications,
    loadMore,
    retryLoadMore,
    fetchCounts,
    fetchPublication,
    createPublication,
    updatePublication,
    schedulePublication,
    reconcilePublication,
    deletePublication,
    loadTimezone,
    upsertIntoList,
    removeFromList,
    resetAll,
  };
});
