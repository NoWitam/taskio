// Bot actions store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the two cursor-paginated bot-action feeds (Batch 2):
//   GET /bots/{bot}/actions?cursor=&type=   → the bot's whole action history.
//   GET /tasks/{task}/bot-actions?cursor=   → one task's bot activity.
// Both return the SAME BotActionResource shape, so a single store serves both
// (keyed independently) via `fetchBotActions(botId, …)` and
// `fetchTaskBotActions(taskId, …)`.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   { data: BotAction[], meta: { next_cursor: string|null } }   NO `total`.
//
// Mirrors the request-token guard + retryable-append pattern of the bots /
// approval-pipelines stores: a reset supersedes work in flight; an append failure
// is tracked SEPARATELY so the same page can be retried without wiping the feed.
// Self-contained: NO import from the legacy `resources/js/`.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  BotAction,
  BotActionsResponse,
  BotActionType,
} from '../../pages/bots/types';

/** Optional flags for a fetch (reset clears the feed + cursor first). */
interface FetchOptions {
  reset?: boolean;
}

function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'bots.actions.loadError';
}

export const useBotActionsStore = defineStore('next-bot-actions', () => {
  // --- Bot action history (per bot) ----------------------------------------
  const botActions = ref<BotAction[]>([]);
  const botCursor = ref<string | null>(null);
  const botHasMore = ref(true);
  const botLoading = ref(false);
  const botLoadingMore = ref(false);
  const botError = ref<string | null>(null);
  const botLoadMoreErrored = ref(false);
  /** The optional `type` filter currently applied to the bot feed. */
  const botTypeFilter = ref<BotActionType | null>(null);
  let botToken = 0;

  // --- Task bot-actions (per task) -----------------------------------------
  const taskActions = ref<BotAction[]>([]);
  const taskCursor = ref<string | null>(null);
  const taskHasMore = ref(true);
  const taskLoading = ref(false);
  const taskLoadingMore = ref(false);
  const taskError = ref<string | null>(null);
  const taskLoadMoreErrored = ref(false);
  let taskToken = 0;

  /**
   * Fetch a page of a BOT's action history (`GET /bots/{id}/actions`). With
   * `{ reset: true }` (default) the feed + cursor are cleared first and the
   * optional `type` filter is captured; otherwise the page is appended.
   */
  async function fetchBotActions(
    botId: string,
    type: BotActionType | null = botTypeFilter.value,
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    if (!reset && (botLoadingMore.value || botLoading.value || !botHasMore.value)) return;

    const myToken = (botToken += 1);
    if (reset) {
      botLoading.value = true;
      botActions.value = [];
      botCursor.value = null;
      botHasMore.value = true;
      botTypeFilter.value = type;
    } else {
      botLoadingMore.value = true;
    }
    botError.value = null;
    botLoadMoreErrored.value = false;

    try {
      const params = new URLSearchParams();
      if (botCursor.value && !reset) params.set('cursor', botCursor.value);
      if (botTypeFilter.value) params.set('type', botTypeFilter.value);
      const qs = params.toString();
      const res = await api.get<BotActionsResponse>(
        `/bots/${botId}/actions${qs ? `?${qs}` : ''}`,
      );
      if (myToken !== botToken) return;

      const incoming = res.data ?? [];
      botActions.value = reset ? incoming : [...botActions.value, ...incoming];
      botCursor.value = res.meta?.next_cursor ?? null;
      botHasMore.value = (res.meta?.next_cursor ?? null) !== null;
    } catch (err: unknown) {
      if (myToken !== botToken) return;
      botError.value = extractMessage(err);
      if (reset) {
        botHasMore.value = false;
      } else {
        botLoadMoreErrored.value = true;
      }
    } finally {
      if (myToken === botToken) {
        botLoading.value = false;
        botLoadingMore.value = false;
      }
    }
  }

  async function loadMoreBotActions(botId: string): Promise<void> {
    await fetchBotActions(botId, botTypeFilter.value, { reset: false });
  }

  async function retryLoadMoreBotActions(botId: string): Promise<void> {
    botLoadMoreErrored.value = false;
    await fetchBotActions(botId, botTypeFilter.value, { reset: false });
  }

  /**
   * Fetch a page of a TASK's bot activity (`GET /tasks/{id}/bot-actions`). With
   * `{ reset: true }` (default) the feed + cursor are cleared first; otherwise the
   * page is appended.
   */
  async function fetchTaskBotActions(
    taskId: string | number,
    { reset = true }: FetchOptions = {},
  ): Promise<void> {
    if (!reset && (taskLoadingMore.value || taskLoading.value || !taskHasMore.value)) return;

    const myToken = (taskToken += 1);
    if (reset) {
      taskLoading.value = true;
      taskActions.value = [];
      taskCursor.value = null;
      taskHasMore.value = true;
    } else {
      taskLoadingMore.value = true;
    }
    taskError.value = null;
    taskLoadMoreErrored.value = false;

    try {
      const params = new URLSearchParams();
      if (taskCursor.value && !reset) params.set('cursor', taskCursor.value);
      const qs = params.toString();
      const res = await api.get<BotActionsResponse>(
        `/tasks/${taskId}/bot-actions${qs ? `?${qs}` : ''}`,
      );
      if (myToken !== taskToken) return;

      const incoming = res.data ?? [];
      taskActions.value = reset ? incoming : [...taskActions.value, ...incoming];
      taskCursor.value = res.meta?.next_cursor ?? null;
      taskHasMore.value = (res.meta?.next_cursor ?? null) !== null;
    } catch (err: unknown) {
      if (myToken !== taskToken) return;
      taskError.value = extractMessage(err);
      if (reset) {
        taskHasMore.value = false;
      } else {
        taskLoadMoreErrored.value = true;
      }
    } finally {
      if (myToken === taskToken) {
        taskLoading.value = false;
        taskLoadingMore.value = false;
      }
    }
  }

  async function loadMoreTaskBotActions(taskId: string | number): Promise<void> {
    await fetchTaskBotActions(taskId, { reset: false });
  }

  async function retryLoadMoreTaskBotActions(taskId: string | number): Promise<void> {
    taskLoadMoreErrored.value = false;
    await fetchTaskBotActions(taskId, { reset: false });
  }

  /** Drop the per-bot feed (e.g. when leaving a bot detail). */
  function resetBotActions(): void {
    botActions.value = [];
    botCursor.value = null;
    botHasMore.value = true;
    botLoading.value = false;
    botLoadingMore.value = false;
    botError.value = null;
    botLoadMoreErrored.value = false;
    botTypeFilter.value = null;
  }

  /** Drop the per-task feed (e.g. on drawer close). */
  function resetTaskActions(): void {
    taskActions.value = [];
    taskCursor.value = null;
    taskHasMore.value = true;
    taskLoading.value = false;
    taskLoadingMore.value = false;
    taskError.value = null;
    taskLoadMoreErrored.value = false;
  }

  return {
    // bot history state
    botActions,
    botCursor,
    botHasMore,
    botLoading,
    botLoadingMore,
    botError,
    botLoadMoreErrored,
    botTypeFilter,
    // task feed state
    taskActions,
    taskCursor,
    taskHasMore,
    taskLoading,
    taskLoadingMore,
    taskError,
    taskLoadMoreErrored,
    // actions
    fetchBotActions,
    loadMoreBotActions,
    retryLoadMoreBotActions,
    fetchTaskBotActions,
    loadMoreTaskBotActions,
    retryLoadMoreTaskBotActions,
    resetBotActions,
    resetTaskActions,
  };
});
