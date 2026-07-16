// Bot Inbox store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for a bot's operational inbox (Batch 7):
//   GET  /api/bots/{id}/inbox?state=&cursor=
//     → { data: BotInboxTask[], meta: { next_cursor }, buckets, runs_this_month }
//   POST /api/bots/{id}/tasks/{taskId}/retry  → 200 {message} (task → running)
//                                               422 → no longer failed (refetch)
//
// Mirrors the request-token guard + retryable-append pattern of the botActions /
// bots stores: a reset supersedes work in flight; an append failure is tracked
// SEPARATELY so the same page can be retried without wiping the list. `buckets` +
// `runs_this_month` come on every page — the store keeps the latest. The active
// `state` filter (a bucket or null = all) drives `?state=`. Reset when the bot
// changes. Self-contained: NO import from the legacy `resources/js/`.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  BotInboxBuckets,
  BotInboxResponse,
  BotInboxState,
  BotInboxTask,
} from '../../pages/bots/types';

interface FetchOptions {
  reset?: boolean;
}

/** A zeroed bucket-count map (used until the first fetch lands). */
function emptyBuckets(): BotInboxBuckets {
  return {
    queued: 0,
    running: 0,
    waiting: 0,
    in_approval: 0,
    revision: 0,
    failed: 0,
    done: 0,
  };
}

function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'bots.inbox.loadError';
}

/** The HTTP status of an axios-style error (or 0). */
function statusOf(err: unknown): number {
  return (err as { response?: { status?: number } })?.response?.status ?? 0;
}

export const useBotInboxStore = defineStore('next-bot-inbox', () => {
  // --- List state (cursor-paginated for the active bucket / all) ------------
  const items = ref<BotInboxTask[]>([]);
  const cursor = ref<string | null>(null);
  const hasMore = ref(true);
  const loading = ref(false);
  const loadingMore = ref(false);
  const error = ref<string | null>(null);
  const loadMoreErrored = ref(false);

  // --- Header / bucket-bar state -------------------------------------------
  const buckets = ref<BotInboxBuckets>(emptyBuckets());
  const runsThisMonth = ref(0);
  /** The active bucket filter, or null for "all". */
  const state = ref<BotInboxState | null>(null);

  // Retry-in-flight, keyed by task id so several rows can be independent.
  const retrying = ref<Record<string, boolean>>({});

  let token = 0;

  /**
   * Fetch a page of the inbox for `botId` at the current `state`. With
   * `{ reset: true }` (default) the list + cursor are cleared first; otherwise the
   * page is appended. `buckets` + `runs_this_month` are refreshed on every page.
   */
  async function fetchInbox(
    botId: string,
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
    error.value = null;
    loadMoreErrored.value = false;

    try {
      const params = new URLSearchParams();
      if (state.value) params.set('state', state.value);
      if (cursor.value && !reset) params.set('cursor', cursor.value);
      const qs = params.toString();
      const res = await api.get<BotInboxResponse>(
        `/bots/${botId}/inbox${qs ? `?${qs}` : ''}`,
      );
      if (myToken !== token) return; // superseded

      const incoming = res.data ?? [];
      items.value = reset ? incoming : [...items.value, ...incoming];
      cursor.value = res.meta?.next_cursor ?? null;
      hasMore.value = (res.meta?.next_cursor ?? null) !== null;
      // Buckets + runs come on every page; merge onto a zeroed base so an omitted
      // key reads as 0 rather than stale.
      buckets.value = { ...emptyBuckets(), ...(res.buckets ?? {}) };
      runsThisMonth.value = res.runs_this_month ?? 0;
    } catch (err: unknown) {
      if (myToken !== token) return;
      error.value = extractMessage(err);
      if (reset) {
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

  async function loadMore(botId: string): Promise<void> {
    await fetchInbox(botId, { reset: false });
  }

  async function retryLoadMore(botId: string): Promise<void> {
    loadMoreErrored.value = false;
    await fetchInbox(botId, { reset: false });
  }

  /** Switch the active bucket (or null = all) and refetch from the first page. */
  async function setState(botId: string, next: BotInboxState | null): Promise<void> {
    if (state.value === next && items.value.length) return;
    state.value = next;
    await fetchInbox(botId, { reset: true });
  }

  /**
   * Retry a FAILED task (`POST /bots/{id}/tasks/{taskId}/retry`). On success the
   * task moves to `running`; on 422 it is no longer failed (stale UI). BOTH cases
   * refetch the inbox so the counts + list reconcile. Returns the outcome so the UI
   * can pick the right toast: 'retried' | 'stale' | 'error'.
   */
  async function retryTask(
    botId: string,
    taskId: string,
  ): Promise<'retried' | 'stale' | 'error'> {
    if (retrying.value[taskId]) return 'error';
    retrying.value = { ...retrying.value, [taskId]: true };
    try {
      await api.post<{ message: string }>(`/bots/${botId}/tasks/${taskId}/retry`, {});
      await fetchInbox(botId, { reset: true });
      return 'retried';
    } catch (err: unknown) {
      // 422 → the task is no longer failed; refetch to drop the stale row/button.
      if (statusOf(err) === 422) {
        await fetchInbox(botId, { reset: true });
        return 'stale';
      }
      return 'error';
    } finally {
      const next = { ...retrying.value };
      delete next[taskId];
      retrying.value = next;
    }
  }

  function isRetrying(taskId: string): boolean {
    return retrying.value[taskId] === true;
  }

  /** Drop all inbox state (e.g. when the bot changes / leaving the detail). */
  function reset(): void {
    items.value = [];
    cursor.value = null;
    hasMore.value = true;
    loading.value = false;
    loadingMore.value = false;
    error.value = null;
    loadMoreErrored.value = false;
    buckets.value = emptyBuckets();
    runsThisMonth.value = 0;
    state.value = null;
    retrying.value = {};
  }

  return {
    // list state
    items,
    cursor,
    hasMore,
    loading,
    loadingMore,
    error,
    loadMoreErrored,
    // header / bucket state
    buckets,
    runsThisMonth,
    state,
    // actions
    fetchInbox,
    loadMore,
    retryLoadMore,
    setState,
    retryTask,
    isRetrying,
    reset,
  };
});
