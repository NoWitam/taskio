// useAiImageJob — wait for ONE queued AI image job (`GET /api/disk/ai/image/{id}`) to settle.
//
// The Disk's async image machinery is shared by every producer of AI images: the Disk preview editor's
// masked edits AND the bot's visual-identity generations both queue a `DiskAiEdit` row and follow it on
// the SAME status endpoint, with the SAME realtime push (`.disk-ai-edit.updated` on the workspace
// channel) and the SAME HTTP-poll fallback. This composable is the single implementation of that wait —
// extracted from `pages/disk/preview/useImageEditor.ts`, which now consumes it, so there is exactly one
// place that knows the vocabulary, the poll cadence and the fallback rules.
//
// The wait PREFERS the realtime push: subscribe to the workspace channel and, on the done/failed
// notification for THIS job, read the result with a single GET (the push carries only a status, never the
// multi-MB image). It falls back to polling when Reverb isn't configured, when the subscription errors
// (auth/403) or when the socket drops — never on a blind timer, because a real job can legitimately take
// far longer than any short timeout.
//
// `keepImage` DEFAULTS TO FALSE and that default is load-bearing: a `done` payload carries the produced
// PNG as a multi-megabyte base64 STRING. A caller that only needs to know "it finished" (the bot's visual
// module refetches the bot instead — the candidate is already filed server-side) must never hold that
// string, let alone assign it to reactive state where Vue would track it and keep it alive.
//
// The reactive `status` / `error` / `errorCode` mirror the run so a UI can render a live status line
// without owning any of the machinery. `errorCode` is the server's MACHINE-READABLE failure reason (today
// `safety_rejected` — the provider's moderation refused the content), additive to the human `error`.
import { computed, ref, watch, type ComputedRef, type Ref } from 'vue';
import { api, WORKSPACE_KEY } from '../lib/api';
import { subscribePrivate, whenConnectionFails } from '../lib/echo';

/** The lifecycle a caller renders. `idle` also covers "cancelled" (the caller stopped waiting). */
export type AiImageJobStatus = 'idle' | 'queued' | 'processing' | 'done' | 'failed';

/** Why a run did not produce an image. */
export type AiImageJobReason = 'failed' | 'cancelled' | 'timeout';

/** How often the HTTP fallback re-asks for a status. */
export const AI_IMAGE_POLL_INTERVAL = 2_000;

/** How long the HTTP fallback keeps asking before giving up. */
export const AI_IMAGE_JOB_DEADLINE = 180_000;

/**
 * The safety net on the REALTIME path: a socket that connected but silently never delivers. Set well past
 * the server-side job timeout so it can never pre-empt a normal (tens-of-seconds) run.
 */
export const AI_IMAGE_SOCKET_SAFETY = 210_000;

/** The settled outcome of one run. `image` is present only when `keepImage` was requested. */
export interface AiImageJobOutcome {
  status: 'done' | 'failed';
  /** The produced image as a base64 PNG — ONLY when the caller opted in via `keepImage`. */
  image?: string;
  /** The server's human, localized failure message. */
  error?: string;
  /** The server's machine-readable failure reason (e.g. `safety_rejected`), when it has one. */
  errorCode?: string;
  reason?: AiImageJobReason;
}

export interface AiImageJobStartOptions {
  /**
   * Resolve with the `done` payload's base64 `image`. DEFAULT FALSE — see the file header: the string is
   * multi-megabyte, and a caller that re-reads the produced artifact from its own endpoint must not hold it.
   */
  keepImage?: boolean;
}

export interface UseAiImageJobReturn {
  status: Ref<AiImageJobStatus>;
  error: Ref<string | null>;
  errorCode: Ref<string | null>;
  /** The run is queued or processing. */
  busy: ComputedRef<boolean>;
  /** Epoch ms the current run started (null when idle) — a caller's "taking longer than usual" hint. */
  startedAt: Ref<number | null>;
  start: (id: string, options?: AiImageJobStartOptions) => Promise<AiImageJobOutcome>;
  /** Stop waiting. The queued job still runs server-side; we simply stop following it. */
  cancel: () => void;
  /** Drop the reactive run state back to idle (without touching a run in flight). */
  reset: () => void;
}

/** The status endpoint's payload (`queued|processing|done|failed` + the optional extras). */
interface AiEditStatusPayload {
  status: string;
  image?: string;
  error?: string;
  error_code?: string;
}

/** The realtime push for one job — status only, never the image. */
interface AiEditPushPayload {
  id?: string;
  status?: string;
  error?: string;
  error_code?: string;
}

/** The active workspace id (the Reverb channel scope), read where the api singleton stores it. */
function currentWorkspaceId(): string | null {
  try {
    return localStorage.getItem(WORKSPACE_KEY);
  } catch {
    return null;
  }
}

export function useAiImageJob(): UseAiImageJobReturn {
  const status = ref<AiImageJobStatus>('idle');
  const error = ref<string | null>(null);
  const errorCode = ref<string | null>(null);
  const startedAt = ref<number | null>(null);
  const abort = ref(false);

  const busy = computed(() => status.value === 'queued' || status.value === 'processing');

  function cancel(): void {
    abort.value = true;
  }

  function reset(): void {
    status.value = 'idle';
    error.value = null;
    errorCode.value = null;
    startedAt.value = null;
    abort.value = false;
  }

  const cancelled = (): AiImageJobOutcome => ({ status: 'failed', reason: 'cancelled' });
  const timedOut = (): AiImageJobOutcome => ({ status: 'failed', reason: 'timeout' });

  /** One status read. Never carries the image unless the caller kept it. */
  async function fetchStatus(id: string): Promise<AiEditStatusPayload> {
    const res = await api.get<{ data: AiEditStatusPayload }>(`/disk/ai/image/${id}`);
    return res.data;
  }

  /** Turn a terminal payload into an outcome (dropping the image unless the caller asked for it). */
  function settle(data: AiEditStatusPayload, keepImage: boolean): AiImageJobOutcome {
    if (data.status === 'done') {
      return keepImage ? { status: 'done', image: data.image } : { status: 'done' };
    }
    return { status: 'failed', error: data.error, errorCode: data.error_code, reason: 'failed' };
  }

  /**
   * Whether a payload has SETTLED for this caller. A `done` without an image is NOT settled for a caller
   * that wants the bytes (the row is written before the blob lands), so it keeps waiting — the behaviour
   * the Disk editor has always relied on.
   */
  function isSettled(data: AiEditStatusPayload, keepImage: boolean): boolean {
    if (data.status === 'done') return !keepImage || !!data.image;
    return data.status === 'failed';
  }

  /** HTTP fallback: ask until the job settles or the deadline passes. An api error propagates. */
  async function poll(id: string, keepImage: boolean): Promise<AiImageJobOutcome> {
    const deadline = Date.now() + AI_IMAGE_JOB_DEADLINE;
    while (Date.now() < deadline) {
      if (abort.value) return cancelled();
      await new Promise((resolve) => setTimeout(resolve, AI_IMAGE_POLL_INTERVAL));
      if (abort.value) return cancelled();
      const data = await fetchStatus(id);
      if (abort.value) return cancelled(); // cancelled while this poll was in flight
      if (data.status === 'processing') status.value = 'processing';
      if (isSettled(data, keepImage)) return settle(data, keepImage);
    }
    return timedOut();
  }

  /**
   * Wait for the job, preferring the realtime push and falling back to polling on a genuine socket
   * failure. Resolves with an outcome; an api error inside the fallback poll rejects (the caller then sees
   * the original axios error, which some callers read a server message off).
   */
  function awaitJob(id: string, keepImage: boolean): Promise<AiImageJobOutcome> {
    const wsId = currentWorkspaceId();
    const channel = wsId ? subscribePrivate(`disk-ai.workspace.${wsId}`) : null;
    if (!channel) return poll(id, keepImage); // Reverb not configured → poll

    return new Promise<AiImageJobOutcome>((resolve) => {
      let settled = false;
      let safety: ReturnType<typeof setTimeout> | null = null;
      let stopConnWatch: () => void = () => {};
      const stopAbort = watch(abort, (aborted) => {
        if (aborted) finish(() => resolve(cancelled()));
      });

      function finish(run: () => void): void {
        if (settled) return;
        settled = true;
        if (safety) clearTimeout(safety);
        stopAbort();
        stopConnWatch();
        try {
          channel!.stopListening('.disk-ai-edit.updated');
        } catch {
          /* channel teardown is best-effort */
        }
        run();
      }

      // Hand off to HTTP polling — used ONLY on a genuine socket failure (subscription/auth error or a
      // lost connection), never on a blind timer: a real job can take far longer than any short timeout,
      // so polling must not kick in just because the completion push hasn't arrived yet.
      // (`resolve` ADOPTS the poll promise: a transport rejection inside it rejects this one too.)
      const fallbackToPoll = (): void => finish(() => resolve(poll(id, keepImage)));

      async function collect(): Promise<void> {
        try {
          const data = await fetchStatus(id);
          if (data.status === 'processing') status.value = 'processing';
          if (isSettled(data, keepImage)) finish(() => resolve(settle(data, keepImage)));
        } catch {
          /* transient — keep waiting for the push */
        }
      }

      channel.listen('.disk-ai-edit.updated', (payload) => {
        const p = payload as AiEditPushPayload;
        if (p.id !== id) return; // another job sharing the workspace channel
        if (p.status === 'processing') status.value = 'processing';
        if (p.status === 'done') void collect();
        else if (p.status === 'failed') {
          finish(() =>
            resolve({ status: 'failed', error: p.error, errorCode: p.error_code, reason: 'failed' }),
          );
        }
      });
      channel.error(() => fallbackToPoll()); // subscription/auth error (e.g. 403) → poll
      stopConnWatch = whenConnectionFails(fallbackToPoll); // socket dropped / server down → poll

      // One immediate check catches a job that finished BEFORE we subscribed. The long safety net covers a
      // socket that connected but silently never delivers.
      void collect();
      safety = setTimeout(fallbackToPoll, AI_IMAGE_SOCKET_SAFETY);
    });
  }

  /**
   * Follow job `id` until it settles. Resolves with the outcome (a FAILED job is an outcome, not a
   * rejection); rejects only on a transport error the caller should see verbatim.
   */
  async function start(id: string, options: AiImageJobStartOptions = {}): Promise<AiImageJobOutcome> {
    abort.value = false;
    error.value = null;
    errorCode.value = null;
    status.value = 'queued';
    startedAt.value = Date.now();
    try {
      const outcome = await awaitJob(id, options.keepImage === true);
      if (outcome.reason === 'cancelled') {
        status.value = 'idle';
      } else {
        status.value = outcome.status;
        error.value = outcome.error ?? null;
        errorCode.value = outcome.errorCode ?? null;
      }
      return outcome;
    } catch (err) {
      status.value = 'failed';
      throw err;
    } finally {
      abort.value = false;
    }
  }

  return { status, error, errorCode, busy, startedAt, start, cancel, reset };
}
