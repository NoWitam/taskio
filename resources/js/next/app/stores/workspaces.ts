// Workspaces store for the isolated "next" frontend (Pinia setup store).
//
// Owns the CREATE + PROVISIONING-POLL flow for workspaces. A user can create a
// `shared` workspace (instant — `status:'ready'`) or an `own` workspace (a
// dedicated database provisioned ASYNCHRONOUSLY — `status:'provisioning'` until a
// worker finishes, then `ready` or `failed`). The create modal owns the UI state;
// this store does the HTTP, mirrors the new workspace into the auth context (so the
// switcher shows it), and polls a provisioning workspace until it settles.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   POST /workspaces  { name, db_mode: 'shared' | 'own' }
//     → 201 { data: Workspace }   (shared → status:'ready'; own → 'provisioning')
//   GET  /workspaces/{id}         → { data: Workspace }   (poll this for `status`)
//   Workspace = { id, name, db_mode, status, is_owner, can_manage_members,
//                 member_count, created_at }
// The api singleton attaches the Bearer token + X-Workspace-Id automatically.
//
// Self-contained: NO import from the legacy `resources/js/`. Mirrors the
// approval-pipelines store's loading/error + single-resource (`res.data`) style.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import { useAuthStore, type Workspace } from './auth';

/** The `db_mode` choice when creating a workspace. */
export type WorkspaceDbMode = 'shared' | 'own';

/** Body for `POST /workspaces`. */
export interface CreateWorkspacePayload {
  name: string;
  db_mode: WorkspaceDbMode;
}

/** Single-resource response wrapper (`{ data: Workspace }`). */
interface WorkspaceResponse {
  data: Workspace;
}

/** Options for {@link pollUntilReady}. */
export interface PollOptions {
  /** Called with each freshly-fetched workspace while still provisioning. */
  onUpdate?: (workspace: Workspace) => void;
  /** Delay between polls (ms). */
  intervalMs?: number;
  /** Give up after this long (ms) — resolves with `{ timedOut: true }`. */
  timeoutMs?: number;
}

/** Terminal outcome of a provisioning poll. */
export interface PollResult {
  /** The last workspace fetched (null only if the very first fetch failed). */
  workspace: Workspace | null;
  /** True when the poll was stopped before the workspace settled (timeout/cancel). */
  timedOut: boolean;
  /** True when polling was cancelled via the returned stop fn / a new poll. */
  cancelled: boolean;
}

const DEFAULT_INTERVAL_MS = 2000;
const DEFAULT_TIMEOUT_MS = 120000;

/** A workspace that is no longer provisioning has settled (ready or failed). */
function isSettled(workspace: Workspace): boolean {
  return workspace.status !== 'provisioning';
}

export const useWorkspacesStore = defineStore('next-workspaces', () => {
  // --- State ---------------------------------------------------------------
  /** True while a `POST /workspaces` create request is in flight. */
  const creating = ref(false);
  /** Last create/fetch error message (best-effort human string), or null. */
  const error = ref<string | null>(null);

  // A single in-flight poll is tracked so a new poll (or stop) supersedes it and
  // its loop exits on the next tick. The modal also gets an explicit stop fn.
  let pollToken = 0;

  // --- Actions -------------------------------------------------------------
  /**
   * Create a workspace (`POST /workspaces`). Reads `res.data`, mirrors the new
   * workspace into the auth context (so the switcher lists it immediately) WITHOUT
   * switching into it, and returns it so the modal can branch on `status`.
   */
  async function createWorkspace(payload: CreateWorkspacePayload): Promise<Workspace> {
    creating.value = true;
    error.value = null;
    try {
      const res = await api.post<WorkspaceResponse>('/workspaces', payload);
      const created = res.data;
      mirrorIntoAuth(created);
      return created;
    } finally {
      creating.value = false;
    }
  }

  /** Fetch one workspace (`GET /workspaces/{id}`) — used for provisioning polls. */
  async function fetchWorkspace(id: string | number): Promise<Workspace> {
    const res = await api.get<WorkspaceResponse>(`/workspaces/${id}`);
    const workspace = res.data;
    mirrorIntoAuth(workspace);
    return workspace;
  }

  /**
   * Poll `fetchWorkspace(id)` until the workspace settles (`status !== 'provisioning'`)
   * or the timeout elapses. Cancellable: call the returned `stop()` (or start a new
   * poll) to abort — the in-flight loop exits and the promise resolves with
   * `cancelled: true`. The promise NEVER rejects on a transient fetch error: a failed
   * poll is retried on the next interval (the worker may not have written yet); the
   * timeout still bounds the whole thing.
   *
   * Returns `{ promise, stop }`. Resolution shapes:
   *   - settled:   { workspace: <ready|failed>, timedOut: false, cancelled: false }
   *   - timed out: { workspace: <last seen|null>, timedOut: true,  cancelled: false }
   *   - cancelled: { workspace: <last seen|null>, timedOut: false, cancelled: true }
   */
  function pollUntilReady(
    id: string | number,
    options: PollOptions = {},
  ): { promise: Promise<PollResult>; stop: () => void } {
    const intervalMs = options.intervalMs ?? DEFAULT_INTERVAL_MS;
    const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
    const myToken = (pollToken += 1);
    let stopped = false;

    function stop(): void {
      stopped = true;
    }

    const promise = (async (): Promise<PollResult> => {
      const startedAt = Date.now();
      let last: Workspace | null = null;

      // Loop: fetch → settle? → wait → repeat, bounded by stop/token/timeout.
      // eslint-disable-next-line no-constant-condition
      while (true) {
        if (stopped || myToken !== pollToken) {
          return { workspace: last, timedOut: false, cancelled: true };
        }
        try {
          const workspace = await fetchWorkspace(id);
          last = workspace;
          if (stopped || myToken !== pollToken) {
            return { workspace: last, timedOut: false, cancelled: true };
          }
          if (isSettled(workspace)) {
            return { workspace, timedOut: false, cancelled: false };
          }
          options.onUpdate?.(workspace);
        } catch {
          // Transient: the worker may not have created the row yet, or a flaky
          // network. Swallow and let the next interval retry (timeout bounds it).
        }

        if (Date.now() - startedAt >= timeoutMs) {
          return { workspace: last, timedOut: true, cancelled: false };
        }
        await delay(intervalMs);
      }
    })();

    return { promise, stop };
  }

  /**
   * Insert or replace a workspace in the auth context's `workspaces` list (so the
   * switcher reflects creates + provisioning-status changes) without switching the
   * active workspace. New entries are appended; existing ones are updated in place.
   */
  function mirrorIntoAuth(workspace: Workspace): void {
    const auth = useAuthStore();
    const idx = auth.workspaces.findIndex((w) => String(w.id) === String(workspace.id));
    if (idx >= 0) {
      const next = [...auth.workspaces];
      next[idx] = { ...next[idx], ...workspace };
      auth.workspaces = next;
    } else {
      auth.workspaces = [...auth.workspaces, workspace];
    }
  }

  return {
    // state
    creating,
    error,
    // actions
    createWorkspace,
    fetchWorkspace,
    pollUntilReady,
    mirrorIntoAuth,
  };
});

/** Promise-based delay (cancellation is handled by the loop's token/flag checks). */
function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
