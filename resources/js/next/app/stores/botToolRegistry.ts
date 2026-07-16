// Bot tool-registry store for the isolated "next" frontend (Pinia setup store).
//
// Owns the SESSION cache for `GET /api/bots/tool-registry` (Batch 5) — the list of
// tool ids the bot task-execution module may grant, each with an `available` flag.
// A single fetch per session is enough (the registry is static config), so the
// store CACHES the last successful result and only refetches on an explicit reset
// or a retry after an error. Mirrors the request-token guard used across the other
// `next` stores so a rapid re-open can never leave a stale result.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET /api/bots/tool-registry → { data: [ { id: string, available: bool } ] }
//
// Self-contained: NO import from the legacy `resources/js/`.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import type {
  BotToolRegistryEntry,
  BotToolRegistryResponse,
} from '../../pages/bots/types';

function extractMessage(err: unknown): string {
  const res = (err as { response?: { data?: { message?: string } } })?.response;
  return res?.data?.message ?? 'bots.tools.loadError';
}

export const useBotToolRegistryStore = defineStore('next-bot-tool-registry', () => {
  /** The full registry (available + unavailable), or [] until first loaded. */
  const entries = ref<BotToolRegistryEntry[]>([]);
  /** True once a fetch has SUCCEEDED (so we don't refetch on every open). */
  const loaded = ref(false);
  const loading = ref(false);
  const error = ref<string | null>(null);
  let token = 0;

  /**
   * Fetch the registry once. Subsequent calls are no-ops while a result is cached
   * (unless `force` is passed — used by the retry action). A request token guards
   * against overlap so the newest fetch always wins.
   */
  async function fetchRegistry(force = false): Promise<void> {
    if (loading.value) return;
    if (loaded.value && !force) return;

    const myToken = (token += 1);
    loading.value = true;
    error.value = null;
    try {
      const res = await api.get<BotToolRegistryResponse>('/bots/tool-registry');
      if (myToken !== token) return; // superseded
      entries.value = res.data ?? [];
      loaded.value = true;
    } catch (err: unknown) {
      if (myToken !== token) return;
      error.value = extractMessage(err);
    } finally {
      if (myToken === token) loading.value = false;
    }
  }

  /** Retry after an error (clears the error + forces a refetch). */
  async function retry(): Promise<void> {
    error.value = null;
    await fetchRegistry(true);
  }

  /** Drop the cache (e.g. on logout / workspace switch). */
  function reset(): void {
    entries.value = [];
    loaded.value = false;
    loading.value = false;
    error.value = null;
  }

  /** The subset the editor offers as options — the user's "zero dead options" rule. */
  function availableIds(): string[] {
    return entries.value.filter((e) => e.available).map((e) => e.id);
  }

  /** True when the id is a KNOWN + AVAILABLE tool (drives the "unavailable" chip). */
  function isAvailable(id: string): boolean {
    return entries.value.some((e) => e.id === id && e.available);
  }

  return {
    entries,
    loaded,
    loading,
    error,
    fetchRegistry,
    retry,
    reset,
    availableIds,
    isAvailable,
  };
});
