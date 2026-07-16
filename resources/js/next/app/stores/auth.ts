// Auth store for the isolated "next" frontend (Pinia setup store).
//
// Owns the session: the Bearer token (persisted to `localStorage['taskio_token']`)
// and the auth CONTEXT returned by the backend — user, permissions, workspaces and
// the active workspace id (persisted to `localStorage['taskio_workspace']`). It is
// the single source of truth the router guard and the App Shell read from.
//
// Backend contract (verified — do NOT invent fields):
//   POST /api/auth/login  { email, password, remember }
//        → { token, user, permissions, workspaces, current_workspace }
//   GET  /api/auth/me      → { user, permissions, workspaces, current_workspace }
//   POST /api/auth/logout
// The api client (../lib/api) attaches `Authorization: Bearer <token>` and
// `X-Workspace-Id` from the same localStorage keys on every request.
//
// Self-contained: NO import from the legacy `resources/js/` (the legacy
// `store/user.js` is reference only — the storage keys + flow are reused, the file
// is not).
import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api, TOKEN_KEY, WORKSPACE_KEY } from '../lib/api';

/** Provisioning lifecycle of a workspace (own-DB workspaces provision async). */
export type WorkspaceStatus = 'provisioning' | 'ready' | 'failed';

/** A workspace entry from the auth context (only the fields the UI reads). */
export interface Workspace {
  id: string | number;
  name: string;
  /**
   * Provisioning status. Shared workspaces are created `ready`; own-DB workspaces
   * start `provisioning` and transition to `ready`/`failed`. Optional because older
   * payloads (pre-async-provisioning) may omit it — treat an absent status as ready.
   */
  status?: WorkspaceStatus;
  /** Storage mode. Optional — older payloads may omit it. */
  db_mode?: 'shared' | 'own';
  [key: string]: unknown;
}

/** The authenticated user from the auth context (open shape; UI reads name/email). */
export interface AuthUser {
  id: string | number;
  name?: string;
  email?: string;
  avatar?: string;
  locale?: string;
  [key: string]: unknown;
}

/** The shared context payload from /auth/login and /auth/me. */
export interface AuthContext {
  user?: AuthUser | null;
  permissions?: string[];
  workspaces?: Workspace[];
  current_workspace?: string | number | null;
}

interface LoginResponse extends AuthContext {
  token: string;
}

function readStored(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function writeStored(key: string, value: string | null): void {
  try {
    if (value === null) localStorage.removeItem(key);
    else localStorage.setItem(key, value);
  } catch {
    /* localStorage may be unavailable (private mode) */
  }
}

export const useAuthStore = defineStore('next-auth', () => {
  // --- State ---------------------------------------------------------------
  const user = ref<AuthUser | null>(null);
  const permissions = ref<string[]>([]);
  const workspaces = ref<Workspace[]>([]);
  const currentWorkspaceId = ref<string | null>(readStored(WORKSPACE_KEY));
  const token = ref<string | null>(readStored(TOKEN_KEY));
  /** True once init()/fetchMe has resolved (so the guard can wait on hydration). */
  const ready = ref(false);
  /** In-flight init() promise so concurrent callers (main.ts + guard) share one. */
  let initPromise: Promise<void> | null = null;

  // --- Getters -------------------------------------------------------------
  const isAuthenticated = computed(() => user.value !== null);
  const userName = computed(() => user.value?.name ?? user.value?.email ?? '');
  const currentWorkspace = computed<Workspace | null>(
    () =>
      workspaces.value.find((w) => String(w.id) === String(currentWorkspaceId.value)) ?? null,
  );

  /** Permission gate — `can('forms.create')`. */
  function can(permission: string): boolean {
    return permissions.value.includes(permission);
  }

  // --- Persistence helpers -------------------------------------------------
  function persistToken(value: string | null): void {
    token.value = value;
    writeStored(TOKEN_KEY, value);
  }

  function persistWorkspace(id: string | number | null): void {
    const normalized = id === null || id === undefined ? null : String(id);
    currentWorkspaceId.value = normalized;
    writeStored(WORKSPACE_KEY, normalized);
  }

  // --- Actions -------------------------------------------------------------
  /** Apply the shared auth context payload from /auth/login and /auth/me. */
  function applyContext(data: AuthContext): void {
    if (data.user !== undefined) user.value = data.user;
    if (data.permissions !== undefined) permissions.value = data.permissions ?? [];
    if (data.workspaces !== undefined) workspaces.value = data.workspaces ?? [];
    if (data.current_workspace !== undefined) persistWorkspace(data.current_workspace);
  }

  async function login(email: string, password: string, remember = false): Promise<void> {
    const data = await api.post<LoginResponse>('/auth/login', { email, password, remember });
    persistToken(data.token);
    applyContext(data);
    ready.value = true;
  }

  async function fetchMe(): Promise<void> {
    const data = await api.get<AuthContext>('/auth/me');
    applyContext(data);
  }

  function clear(): void {
    user.value = null;
    permissions.value = [];
    workspaces.value = [];
    persistToken(null);
    persistWorkspace(null);
  }

  async function logout(): Promise<void> {
    try {
      if (token.value) await api.post('/auth/logout');
    } finally {
      clear();
    }
  }

  /**
   * Whether a workspace can be switched into. A workspace that is still
   * `provisioning` (own-DB not ready) or `failed` (provisioning errored) has no
   * usable database/context, so switching into it must be blocked. An absent
   * status is treated as ready (older payloads).
   */
  function canSwitchInto(workspace: Workspace | null | undefined): boolean {
    if (!workspace) return false;
    const status = workspace.status;
    return status === undefined || status === 'ready';
  }

  /**
   * Switch the active workspace and refresh the context for the new scope.
   * Refuses to switch into a `provisioning`/`failed` workspace (no usable DB);
   * the caller already created/owns the workspace so the entry is guaranteed
   * present in `workspaces` once the context carries it. Returns `false` when the
   * switch was blocked, `true` once the new context is loaded.
   */
  async function setCurrentWorkspace(id: string | number): Promise<boolean> {
    const target = workspaces.value.find((w) => String(w.id) === String(id));
    if (target && !canSwitchInto(target)) return false;
    persistWorkspace(id);
    await fetchMe();
    return true;
  }

  /**
   * Re-hydrate the session on app start when a token is already stored. On a
   * failure (expired/invalid token) the token is cleared so the guard treats the
   * user as logged out. Idempotent — safe to call once at boot.
   */
  async function init(): Promise<void> {
    if (ready.value) return;
    if (initPromise) return initPromise;

    initPromise = (async () => {
      if (!token.value) return;
      try {
        await fetchMe();
      } catch {
        persistToken(null);
        clear();
      }
    })().finally(() => {
      ready.value = true;
      initPromise = null;
    });

    return initPromise;
  }

  return {
    // state
    user,
    permissions,
    workspaces,
    currentWorkspaceId,
    token,
    ready,
    // getters
    isAuthenticated,
    userName,
    currentWorkspace,
    can,
    canSwitchInto,
    // actions
    applyContext,
    persistToken,
    login,
    fetchMe,
    logout,
    setCurrentWorkspace,
    init,
  };
});

/**
 * Permission helper composable — a thin wrapper over the store's `can`, mirroring
 * the legacy `usePermissions` ergonomics for gating UI.
 */
export function usePermissions(): {
  can: (permission: string) => boolean;
  canAny: (permissions: string[]) => boolean;
  canAll: (permissions: string[]) => boolean;
} {
  const auth = useAuthStore();
  return {
    can: (permission: string) => auth.can(permission),
    canAny: (perms: string[]) => perms.some((p) => auth.can(p)),
    canAll: (perms: string[]) => perms.every((p) => auth.can(p)),
  };
}
