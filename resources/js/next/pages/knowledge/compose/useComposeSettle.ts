// useComposeSettle — WAIT (never POLL) for a drafting session to reach a TERMINAL state.
//
// A 1:1 clone of `pages/generator/session/useSessionSettle.ts`, against the Knowledge module's own
// channel. Cloned rather than generalised on purpose: the two differ in channel name, event name
// and store, and a shared abstraction parameterised by all three would be harder to read than the
// 60 lines it saved — while coupling two modules' realtime paths so that changing one risks the
// other. If a THIRD appears, that is the moment to extract.
//
// The backend broadcasts (verified — `Events/KnowledgeDraftSessionUpdated` + `routes/channels.php`):
//   • private channel  `knowledge.workspace.{workspaceId}`  (authorized by workspace membership),
//   • event (Echo)     `.knowledge-draft-session.updated`,
//   • payload          { id, status }  — STATUS ONLY, never the drafts.
// The push carries only status, so on a match we `fetchDraftSession(id)` once to load the board.
//
// NO POLLING FALLBACK ANYWHERE. That is a standing user requirement in this project, recorded in
// `useSessionSettle.ts`'s own header: generation must settle on the websocket event, not a poll
// loop. The brief for this batch said "polling"; the project convention is stronger and wins (spec
// DC1 / R15). Every path below ends in ONE fetch and then stops:
//   • the event arrives              → one fetch, resolve,
//   • the safety window expires      → one fetch, resolve (the caller shows a "refresh" hint),
//   • Reverb is not configured       → one DELAYED fetch, resolve.
import { getCurrentInstance, onUnmounted } from 'vue';
import { subscribePrivate, type RealtimeChannel } from '../../../app/lib/echo';
import { WORKSPACE_KEY } from '../../../app/lib/api';
import { useKnowledgeStore } from '../../../app/stores/knowledge';
import type { KnowledgeDraftSession, KnowledgeDraftSessionStatus } from '../types';

/** The Echo event name the backend broadcasts as (`broadcastAs()` → dot-prefixed for `.listen`). */
const EVENT = '.knowledge-draft-session.updated';

/**
 * Safety window before we stop waiting on a silent socket. Generous on purpose: it only fires when
 * a run ends WITHOUT a push. On expiry we re-fetch ONCE and give up; we never start a poll.
 */
const DEFAULT_TIMEOUT_MS = 330_000;

/**
 * Reverb-not-configured fallback delay. With Echo unavailable (local dev without a Reverb server)
 * the no-polling requirement still holds, so we do exactly ONE delayed re-fetch as a best-effort
 * catch for a quick run; if it has not settled by then the caller surfaces a "refresh" hint.
 */
const DEFAULT_REVERB_ABSENT_MS = 4000;

/** The two terminal states a composition settles into. */
function isTerminal(status: KnowledgeDraftSessionStatus): boolean {
  return status === 'ready' || status === 'failed';
}

export interface ComposeSettleOutcome {
  /** The re-fetched session, or the last-known one if even the fetch failed. */
  session: KnowledgeDraftSession | null;
  /** `true` when it reached `ready`/`failed`; `false` on the safety timeout / Reverb-absent give-up. */
  settled: boolean;
}

export interface UseComposeSettleOptions {
  /** Safety-timeout window in ms (injectable so tests can drive it fast). */
  timeoutMs?: number;
  /** Reverb-absent single-refetch delay in ms (injectable). */
  reverbAbsentMs?: number;
}

interface Waiter {
  onEvent: () => void;
  onGiveUp: () => void;
}

/** A tiny promise sleep — used ONLY for the single Reverb-absent delay, never in a loop. */
function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Owns the composer's realtime subscription and exposes `waitForSettle(id)`. Call it once in the
 * view's setup; it registers its own unmount teardown.
 */
export function useComposeSettle(options: UseComposeSettleOptions = {}) {
  const store = useKnowledgeStore();
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
  const reverbAbsentMs = options.reverbAbsentMs ?? DEFAULT_REVERB_ABSENT_MS;

  let channel: RealtimeChannel | null = null;
  let subscribeAttempted = false;
  /** Pending waits keyed by session id — the channel is per-WORKSPACE, so we filter by id. */
  const waiters = new Map<string, Waiter>();

  /** The active workspace id (the channel scope), read where the api singleton persists it. */
  function readWorkspaceId(): string | null {
    try {
      return localStorage.getItem(WORKSPACE_KEY);
    } catch {
      return null;
    }
  }

  /**
   * Ensure the ONE channel is subscribed. Idempotent. `false` means Reverb is not configured (or
   * there is no active workspace), so the caller takes the no-poll fallback path.
   */
  function ensureSubscribed(): boolean {
    if (subscribeAttempted) return channel !== null;
    subscribeAttempted = true;

    const wsId = readWorkspaceId();
    channel = wsId ? subscribePrivate(`knowledge.workspace.${wsId}`) : null;
    if (!channel) return false;

    channel.listen(EVENT, (payload) => {
      const p = payload as { id?: string };
      if (!p.id) return;
      waiters.get(p.id)?.onEvent(); // another composer sharing the workspace channel is ignored
    });
    // A subscription/auth error is a DEFINITIVE "no events will ever arrive" for this channel (it
    // does NOT fire on transient blips — Reverb auto-reconnects those). Give up via a single
    // re-fetch rather than hanging until the safety timeout. Still no polling.
    channel.error(() => {
      for (const w of [...waiters.values()]) w.onGiveUp();
    });
    return true;
  }

  /** Re-fetch (best-effort); on failure fall back to the store's last-known session for this id. */
  async function refetch(id: string): Promise<KnowledgeDraftSession | null> {
    try {
      return await store.fetchDraftSession(id);
    } catch {
      return store.session && store.session.id === id ? store.session : null;
    }
  }

  async function finalize(id: string): Promise<ComposeSettleOutcome> {
    const settled = await refetch(id);
    return { session: settled, settled: !!settled && isTerminal(settled.status) };
  }

  /**
   * Wait for session `id` to settle, WITHOUT polling. Resolves when the matching event arrives, or
   * the race-safe single post-subscribe check finds it already terminal, or the safety timeout
   * expires, or (no Reverb) after one delayed re-fetch.
   */
  async function waitForSettle(id: string): Promise<ComposeSettleOutcome> {
    const live = ensureSubscribed();
    if (!live) {
      await sleep(reverbAbsentMs);
      return finalize(id);
    }

    return new Promise<ComposeSettleOutcome>((resolve) => {
      let done = false;
      const settle = (outcome: ComposeSettleOutcome): void => {
        if (done) return;
        done = true;
        clearTimeout(timer);
        waiters.delete(id);
        resolve(outcome);
      };

      // Event path (and the race-safe single check): re-fetch and resolve ONLY if it really is
      // terminal. If it is not, we keep waiting for the next event / the safety timer — we do NOT
      // re-fetch again, which is what would turn this into a poll.
      const onEvent = (): void => {
        void refetch(id).then((fresh) => {
          if (fresh && isTerminal(fresh.status)) settle({ session: fresh, settled: true });
        });
      };
      // Give-up path: re-fetch ONCE and resolve either way, so the skeletons never hang forever.
      const onGiveUp = (): void => {
        void finalize(id).then(settle);
      };

      const timer = setTimeout(onGiveUp, timeoutMs);
      waiters.set(id, { onEvent, onGiveUp });

      // Race safety (NOT a poll): the terminal event can fire BEFORE this listener is attached on a
      // very fast run. One immediate check catches that; otherwise we just wait for the push.
      onEvent();
    });
  }

  /** Tear down the subscription + drop pending waits. Called on unmount; safe to call twice. */
  function dispose(): void {
    if (channel) {
      try {
        channel.stopListening(EVENT);
      } catch {
        /* channel teardown is best-effort */
      }
    }
    waiters.clear();
    channel = null;
    subscribeAttempted = false;
  }

  // Auto-teardown when used inside a component (the normal case); guarded so the composable stays
  // unit-testable outside a component instance.
  if (getCurrentInstance()) onUnmounted(dispose);

  return { waitForSettle, dispose };
}
