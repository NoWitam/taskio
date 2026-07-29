// useSessionSettle — WAIT (never POLL) for an async generation-session run to reach a TERMINAL state.
//
// R2 Generator / Templatki: a whole `generate`, a per-part `regenerate`, and a per-part `refine` all return
// 202 with the session now `generating`, then run on a queue. Instead of re-fetching on a timer, the chat
// WAITS for the backend's realtime push and re-fetches ONCE when it lands. The backend broadcasts (see
// {@see \App\Modules\Generator\Events\GenerationSessionUpdated}):
//   • private channel  `generator.workspace.{workspaceId}`  (authorized by workspace membership),
//   • event (Echo)     `.generation-session.updated`,
//   • payload          { id, status: 'ready'|'failed', last_op_status?: 'ok'|'failed' }  (NO produced content).
// The push carries only status, so on a match we `fetchSession(id)` to load the results + last_op_status.
//
// Mirrors the Disk-AI realtime path in `disk/preview/useImageEditor.ts` (subscribePrivate → channel.listen →
// stopListening on teardown), but with NO polling fallback anywhere — the user requirement is that generation
// and refine/regenerate must settle on the websocket event, not a poll loop.
//
// Lifecycle: ONE subscription per open chat. The channel is subscribed LAZILY on the first `waitForSettle`
// (i.e. when the first op starts, or on a reload that finds a run already in flight) and torn down on unmount.
// Every open chat in the workspace shares the same per-workspace channel and filters events by `payload.id`.
import { getCurrentInstance, onUnmounted } from 'vue';
import { subscribePrivate, type RealtimeChannel } from '../../../app/lib/echo';
import { WORKSPACE_KEY } from '../../../app/lib/api';
import { useSessionsStore } from '../../../app/stores/sessions';
import type { Session, SessionStatus } from '../sessionTypes';

/** The Echo event name the backend broadcasts as (`broadcastAs()` → prefixed with a dot for `.listen`). */
const EVENT = '.generation-session.updated';

/**
 * Safety window before we stop waiting on a silent socket. Generous on purpose: a single run's job timeout is
 * 300s, so this sits past it — it only fires in the rare case where a run ends WITHOUT a push (e.g. the reaper
 * failing a run with a cleared broadcast context). On expiry we re-fetch ONCE and resolve; we never start a poll.
 */
const DEFAULT_TIMEOUT_MS = 330_000;

/**
 * Reverb-not-configured fallback delay. When Echo is unavailable (e.g. local dev without a Reverb server) the
 * user requirement forbids polling, so we do exactly ONE delayed re-fetch as a best-effort catch for a quick
 * run; if it hasn't settled by then, the caller surfaces a "refresh" hint. This is a single fetch, not a loop.
 */
const DEFAULT_REVERB_ABSENT_MS = 4000;

/** The two terminal states a run settles into. */
function isTerminal(status: SessionStatus): boolean {
  return status === 'ready' || status === 'failed';
}

/** The outcome of a wait: the freshest session (post-fetch), and whether it actually reached a terminal state. */
export interface SettleOutcome {
  /** The re-fetched session, or the last-known one if even the fetch failed (null only if nothing is known). */
  session: Session | null;
  /** `true` when the session reached `ready`/`failed`; `false` on the safety timeout / Reverb-absent give-up. */
  settled: boolean;
}

export interface UseSessionSettleOptions {
  /** Safety-timeout window in ms (injectable so tests can drive it fast). */
  timeoutMs?: number;
  /** Reverb-absent single-refetch delay in ms (injectable). */
  reverbAbsentMs?: number;
}

interface Waiter {
  /** A matching terminal event arrived (or the race-safe check ran) → re-fetch; resolve only IF terminal. */
  onEvent: () => void;
  /** The socket is a dead end (safety timeout / subscription error) → re-fetch ONCE; resolve either way. */
  onGiveUp: () => void;
}

/** A tiny promise sleep (used only for the single Reverb-absent re-fetch delay — never in a loop). */
function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Owns the per-chat realtime subscription and exposes `waitForSettle(id)`. Call it once in the chat view's
 * setup; it registers its own unmount teardown. The returned `dispose` is exposed for tests / manual cleanup.
 */
export function useSessionSettle(options: UseSessionSettleOptions = {}) {
  const store = useSessionsStore();
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
  const reverbAbsentMs = options.reverbAbsentMs ?? DEFAULT_REVERB_ABSENT_MS;

  let channel: RealtimeChannel | null = null;
  let subscribeAttempted = false;
  /** Pending waits keyed by session id — the per-workspace channel is shared, so we filter by id. */
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
   * Ensure the ONE per-chat channel is subscribed. Idempotent. Returns whether a live channel exists — `false`
   * means Reverb isn't configured (or no active workspace), so the caller takes the no-poll fallback path.
   */
  function ensureSubscribed(): boolean {
    if (subscribeAttempted) return channel !== null;
    subscribeAttempted = true;

    const wsId = readWorkspaceId();
    channel = wsId ? subscribePrivate(`generator.workspace.${wsId}`) : null;
    if (!channel) return false;

    channel.listen(EVENT, (payload) => {
      const p = payload as { id?: string };
      if (!p.id) return;
      waiters.get(p.id)?.onEvent(); // another session sharing the workspace channel is ignored
    });
    // A subscription/auth error is a DEFINITIVE "no events will ever arrive" for this channel (it does NOT fire
    // on transient connection blips — those are connection-level and Reverb auto-reconnects). Give up the wait
    // via a single re-fetch rather than hanging until the safety timeout. Still no polling.
    channel.error(() => {
      for (const w of [...waiters.values()]) w.onGiveUp();
    });
    return true;
  }

  /** Re-fetch a session (best-effort); on failure fall back to the store's last-known detail for this id. */
  async function refetch(id: string): Promise<Session | null> {
    try {
      return await store.fetchSession(id);
    } catch {
      return store.detail && store.detail.id === id ? store.detail : null;
    }
  }

  /** Re-fetch once and produce an outcome that is `settled` iff the session is now terminal. */
  async function finalize(id: string): Promise<SettleOutcome> {
    const session = await refetch(id);
    return { session, settled: !!session && isTerminal(session.status) };
  }

  /**
   * Wait for session `id` to settle, WITHOUT polling. Resolves when:
   *  • the matching `.generation-session.updated` event arrives → re-fetch → resolve (settled),
   *  • OR the race-safe SINGLE post-subscribe check finds it already terminal (event fired first) → resolve,
   *  • OR the safety timeout expires → re-fetch ONCE → resolve (settled iff terminal),
   *  • OR (Reverb not configured) after ONE delayed re-fetch → resolve (settled iff terminal).
   */
  async function waitForSettle(id: string): Promise<SettleOutcome> {
    const live = ensureSubscribed();
    if (!live) {
      // Reverb unavailable → one delayed re-fetch, then give up (no continuous polling).
      await sleep(reverbAbsentMs);
      return finalize(id);
    }

    return new Promise<SettleOutcome>((resolve) => {
      let done = false;
      const settle = (outcome: SettleOutcome): void => {
        if (done) return;
        done = true;
        clearTimeout(timer);
        waiters.delete(id);
        resolve(outcome);
      };

      // Event path (and the race-safe single check): re-fetch and resolve ONLY if it is actually terminal. The
      // event only fires on a terminal transition, so the fetch will show terminal; if it doesn't (still
      // `generating`), we simply keep waiting for the next event / the safety timer — we do NOT re-fetch again.
      const onEvent = (): void => {
        void refetch(id).then((session) => {
          if (session && isTerminal(session.status)) settle({ session, settled: true });
        });
      };
      // Give-up path (safety timeout or subscription error): re-fetch ONCE and resolve either way so the
      // spinner never hangs forever. A non-terminal result → `settled: false` → the caller shows a refresh hint.
      const onGiveUp = (): void => {
        void finalize(id).then(settle);
      };

      const timer = setTimeout(onGiveUp, timeoutMs);
      waiters.set(id, { onEvent, onGiveUp });

      // Race safety (NOT a poll): the terminal event can fire BEFORE this listener is attached on a very fast
      // run. One immediate check catches that; if it's not terminal yet, we just wait for the push.
      onEvent();
    });
  }

  /** Tear down the subscription + drop any pending waits. Called on unmount; safe to call more than once. */
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

  // Auto-teardown when used inside a component (the normal case); guarded so the composable is unit-testable
  // outside a component instance without emitting an "onUnmounted with no active instance" warning.
  if (getCurrentInstance()) onUnmounted(dispose);

  return { waitForSettle, dispose };
}
