// Approval Queue store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the Approvals → QUEUE (my pending decisions) experience:
// a single cursor-paginated list of MY pending items plus the per-process detail
// + run-history caches and the decide action. The queue has ZERO filterable
// params (the server filters to approver=ME, status=pending) so — unlike the
// Pipelines store — there is no filter serialization and no FilterBar.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET  /approvals/queue?cursor=<cursor>   (cursorPaginate(8), created_at desc)
//     → { data: ApprovalQueueItem[], meta: { next_cursor, total? } }
//        `total` (count of MY pending items) is present ONLY on the FIRST page
//        (no `cursor`); captured on the initial fetch, NEVER overwritten after.
//   GET  /approvals/queue/count             → { count }
//   GET  /approvals/processes/{process}     → { data: ApprovalProcess }
//   GET  /approvals/runs/{runId}            → { data: ApprovalProcess[] }
//   POST /approvals/processes/{process}/decide
//        body: { decision: 'approved'|'rejected', note? }  (note required_if
//        decision=rejected, ≤2500) → { data: ApprovalProcess }. A second decide
//        on an already-decided process → 422.
//
// Response wrapping: NO Approvals resource declares a `data` key, so EVERY body
// is `{ data: ... }`. Single resource → `res.data`; collection → `res.data` +
// `res.meta`.
//
// Self-contained: NO import from the legacy `resources/js/` (the legacy
// `store/approvals.ts` is reference only). Uses the SAME `next` api singleton the
// pipelines store uses.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  ApprovalProcess,
  ApprovalProcessResponse,
  ApprovalQueueItem,
  DecisionError,
  DecisionPayload,
  QueueCountResponse,
  QueueListResponse,
  RunHistoryResponse,
} from '../../pages/approvals/queue-types';

/** Frontend i18n key surfaced when a decision is no longer possible (422). */
const ALREADY_DECIDED_KEY = 'approvals.review.errors.alreadyDecided';

/** Frontend i18n key surfaced when a reject is attempted with a blank note. */
const NOTE_REQUIRED_KEY = 'approvals.review.errors.noteRequired';

/** Optional flags for a fetch (reset clears the list + cursor first). */
interface FetchOptions {
  reset?: boolean;
}

/** Pull a human message out of an axios error (best-effort). */
function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'approvals.errors.description';
}

/** True when the error is a 422 (a decision that is no longer possible). */
export function isAlreadyDecided(err: unknown): boolean {
  const status = (err as { response?: { status?: number } })?.response?.status;
  return status === 422;
}

/**
 * Group a flat run-history array (ApprovalProcess[]) by the STAGE it targeted, in
 * stage `order`, returning an ordered list of buckets. Tolerates `stage === null`
 * (a historical process whose stage FK was nulled when the pipeline was re-saved):
 * those are bucketed under a single `null`-keyed "unknown stage" group placed LAST.
 *
 * Exported (not just used internally) so it can be unit-tested in isolation.
 */
export interface RunHistoryGroup {
  /** Stage id, or null for the "unknown stage" bucket. */
  stageId: string | null;
  /** Stage name, or null when unknown. */
  stageName: string | null;
  /** Stage order used for sorting (null buckets sort last). */
  order: number | null;
  processes: ApprovalProcess[];
}

export function groupRunHistory(history: ApprovalProcess[]): RunHistoryGroup[] {
  const buckets = new Map<string, RunHistoryGroup>();
  const UNKNOWN = '__unknown__';

  for (const proc of history) {
    const stage = proc.stage ?? null;
    const key = stage?.id ?? UNKNOWN;
    let bucket = buckets.get(key);
    if (!bucket) {
      bucket = {
        stageId: stage?.id ?? null,
        stageName: stage?.name ?? null,
        order: stage?.order ?? null,
        processes: [],
      };
      buckets.set(key, bucket);
    }
    bucket.processes.push(proc);
  }

  return [...buckets.values()].sort((a, b) => {
    // The null ("unknown") bucket always sorts last.
    if (a.order == null && b.order == null) return 0;
    if (a.order == null) return 1;
    if (b.order == null) return -1;
    return a.order - b.order;
  });
}

export const useApprovalQueueStore = defineStore('next-approval-queue', () => {
  // --- List state ----------------------------------------------------------
  const items = ref<ApprovalQueueItem[]>([]);
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
  /** Count of MY pending items — captured first-page-only, never overwritten. */
  const total = ref<number | null>(null);

  // Request token: a reset always supersedes work in flight so a rapid
  // refresh / refetch can never leave a stale page.
  let token = 0;

  // --- Nav badge count (independent of the list) ---------------------------
  const count = ref<number | null>(null);

  // --- Detail caches (keyed by id) -----------------------------------------
  const processCache = ref<Record<string, ApprovalProcess>>({});
  const runHistoryCache = ref<Record<string, ApprovalProcess[]>>({});
  const detailLoading = ref(false);
  const detailError = ref<string | null>(null);

  // --- List actions --------------------------------------------------------
  /**
   * Fetch one page. With `{ reset: true }` (the default) the list + cursor are
   * cleared first AND `total` is recaptured from the first page's meta; otherwise
   * the page is appended for infinite scroll and `total` is preserved.
   */
  async function fetchQueue({ reset = true }: FetchOptions = {}): Promise<void> {
    // Appends are no-ops while a page is in flight or when exhausted.
    if (!reset && (loadingMore.value || loading.value || !hasMore.value)) return;

    const myToken = (token += 1);
    if (reset) {
      loading.value = true;
      items.value = [];
      cursor.value = null;
      hasMore.value = true;
      total.value = null;
    } else {
      loadingMore.value = true;
    }
    errored.value = false;
    error.value = null;
    loadMoreErrored.value = false;

    try {
      const params = new URLSearchParams();
      if (cursor.value && !reset) params.set('cursor', cursor.value);
      const qs = params.toString();
      // First real call: the body is `{ data: [...], meta: { next_cursor, total? } }`.
      const response = await api.get<QueueListResponse>(`/approvals/queue${qs ? `?${qs}` : ''}`);
      if (myToken !== token) return; // superseded by a newer reset

      const incoming = response.data ?? [];
      items.value = reset ? incoming : [...items.value, ...incoming];

      cursor.value = response.meta?.next_cursor ?? null;
      hasMore.value = (response.meta?.next_cursor ?? null) !== null;

      // `total` is meaningful ONLY on the first page (no cursor). Capture it on a
      // reset; NEVER overwrite it on subsequent (cursor) pages.
      if (reset && typeof response.meta?.total === 'number') {
        total.value = response.meta.total;
      }
    } catch (err: unknown) {
      if (myToken !== token) return;
      error.value = extractMessage(err);
      if (reset) {
        // First page failed: nothing is loaded, show the full error state.
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
  async function loadMore(): Promise<void> {
    await fetchQueue({ reset: false });
  }

  /**
   * Retry a failed append. `loadMore` alone would early-return while
   * `loadMoreErrored` is set (the sentinel is paused), so clear it first; `hasMore`
   * + `cursor` were preserved, so the same page is re-fetched.
   */
  async function retryLoadMore(): Promise<void> {
    loadMoreErrored.value = false;
    await fetchQueue({ reset: false });
  }

  /** The nav-badge count (`GET /approvals/queue/count`). */
  async function fetchCount(): Promise<number> {
    const res = await api.get<QueueCountResponse>('/approvals/queue/count');
    count.value = res.count;
    return res.count;
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
    total.value = null;
    count.value = null;
    processCache.value = {};
    runHistoryCache.value = {};
    detailLoading.value = false;
    detailError.value = null;
  }

  // --- Detail --------------------------------------------------------------
  /** Fetch a single process (`GET /approvals/processes/{process}`); caches it. */
  async function fetchProcess(id: string): Promise<ApprovalProcess | null> {
    detailLoading.value = true;
    detailError.value = null;
    try {
      const res = await api.get<ApprovalProcessResponse>(`/approvals/processes/${id}`);
      processCache.value = { ...processCache.value, [id]: res.data };
      return res.data;
    } catch (err: unknown) {
      detailError.value = extractMessage(err);
      return null;
    } finally {
      detailLoading.value = false;
    }
  }

  /** Fetch a run's full history (`GET /approvals/runs/{runId}`); caches it. */
  async function fetchRunHistory(runId: string): Promise<ApprovalProcess[]> {
    const res = await api.get<RunHistoryResponse>(`/approvals/runs/${runId}`);
    const history = res.data ?? [];
    runHistoryCache.value = { ...runHistoryCache.value, [runId]: history };
    return history;
  }

  // --- List helpers --------------------------------------------------------
  /** Remove a process's queue item (after it leaves my pending queue). */
  function removeFromQueue(processId: string): void {
    items.value = items.value.filter((it) => it.process.id !== processId);
  }

  /** Decrement a `ref<number | null>` counter, flooring at 0 (null stays null). */
  function decrement(counter: { value: number | null }): void {
    if (typeof counter.value === 'number') {
      counter.value = Math.max(0, counter.value - 1);
    }
  }

  // --- Decision ------------------------------------------------------------
  /**
   * Decide a pending process (`POST /approvals/processes/{process}/decide`).
   *
   * Client-side guards mirror the backend BEFORE the request:
   *   • a `rejected` decision REQUIRES a non-blank note (`required_if`) — reject
   *     locally with a structured `note_required` error, no request fired.
   *
   * On SUCCESS the item leaves my pending queue (whether approved or rejected):
   * optimistically remove it from `items`, decrement `total` + `count` (floor 0),
   * refresh the process cache, and return the updated process.
   *
   * On 422 (already decided / no longer pending): resync by refetching the queue
   * + the count, then throw a structured `already_decided` error so the UI can
   * toast a translatable "this item was already decided" message.
   */
  async function makeDecision(
    processId: string,
    payload: DecisionPayload,
  ): Promise<ApprovalProcess> {
    // Mirror the backend `required_if`: a reject needs a non-blank note.
    if (payload.decision === 'rejected' && !payload.note?.trim()) {
      const structured: DecisionError = { kind: 'note_required', messageKey: NOTE_REQUIRED_KEY };
      throw structured;
    }

    try {
      const res = await api.post<ApprovalProcessResponse>(
        `/approvals/processes/${processId}/decide`,
        payload,
      );
      const updated = res.data;

      // The item left my pending queue regardless of approve/reject.
      removeFromQueue(processId);
      decrement(total);
      decrement(count);
      processCache.value = { ...processCache.value, [processId]: updated };

      return updated;
    } catch (err: unknown) {
      if (isAlreadyDecided(err)) {
        // Resync: the queue + count are now stale. Best-effort — surface the
        // structured error regardless of whether the resync requests succeed.
        await Promise.allSettled([fetchQueue({ reset: true }), fetchCount()]);
        const structured: DecisionError = {
          kind: 'already_decided',
          messageKey: ALREADY_DECIDED_KEY,
        };
        throw structured;
      }
      throw err;
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
    total,
    // nav badge count
    count,
    // detail caches
    processCache,
    runHistoryCache,
    detailLoading,
    detailError,
    // list actions
    fetchQueue,
    loadMore,
    retryLoadMore,
    fetchCount,
    resetAll,
    // list mutations (exposed for tests / advanced callers)
    removeFromQueue,
    // detail
    fetchProcess,
    fetchRunHistory,
    // decision
    makeDecision,
  };
});
