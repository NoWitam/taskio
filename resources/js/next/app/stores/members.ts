// Workspace Members store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the CURRENT workspace's member roster: the list and the
// per-member remove action. The CURRENT workspace is the one the api singleton
// already scopes every request to (`X-Workspace-Id` from the auth store); the
// store reads `auth.currentWorkspaceId` only to build the URL, never to scope.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET    /workspaces/{ws}/members
//     → { data: [{ id, name, email, is_owner }] }   (owner included, deduped)
//   DELETE /workspaces/{ws}/members/{userId}
//     → 204; removing the OWNER → 422 errors.user[0] = 'cannot_remove_owner'
//
// The owner-guard 422 is surfaced STRUCTURALLY (a typed `kind`) so the UI can
// translate it with its own catalog (the body carries the raw key, not a
// translated string). Self-contained: NO import from the legacy `resources/js/`.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import { useAuthStore } from './auth';

/** A workspace member (the only fields the members resource returns). */
export interface WorkspaceMember {
  id: string | number;
  name: string;
  email: string;
  is_owner: boolean;
}

/** Collection response wrapper (`{ data: WorkspaceMember[] }`). */
interface MembersResponse {
  data: WorkspaceMember[];
}

/**
 * Structured remove error. The only known structured case is the owner guard
 * (`cannot_remove_owner` under the `user` field); anything else is `generic`.
 */
export interface RemoveMemberError {
  kind: 'cannot_remove_owner' | 'generic';
}

/**
 * Detect the owner-guard 422 and return a typed `kind`. The backend adds the raw
 * key `cannot_remove_owner` under the `user` validation field (NOT a translated
 * string), so we match it structurally and let the UI translate. Any other error
 * maps to `generic`.
 */
export function removeMemberError(err: unknown): RemoveMemberError {
  const res = (err as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } })
    ?.response;
  const userErrors = res?.data?.errors?.user;
  if (
    res?.status === 422 &&
    Array.isArray(userErrors) &&
    userErrors.includes('cannot_remove_owner')
  ) {
    return { kind: 'cannot_remove_owner' };
  }
  return { kind: 'generic' };
}

export const useMembersStore = defineStore('next-members', () => {
  // --- State ---------------------------------------------------------------
  const members = ref<WorkspaceMember[]>([]);
  const loading = ref(false);
  /** First-load error flag (drives the error+retry state). */
  const error = ref<string | null>(null);

  // Request token so a refetch supersedes any in-flight request.
  let token = 0;

  /** The current-workspace id segment for the member endpoints. */
  function workspaceId(): string | number | null {
    return useAuthStore().currentWorkspaceId;
  }

  // --- Actions -------------------------------------------------------------
  /**
   * Fetch the current workspace's members (`GET /workspaces/{ws}/members`).
   * Reads `res.data`. A failure leaves `members` untouched and flips `error`.
   */
  async function fetch(): Promise<void> {
    const ws = workspaceId();
    if (ws == null) {
      members.value = [];
      return;
    }
    const myToken = (token += 1);
    loading.value = true;
    error.value = null;
    try {
      const res = await api.get<MembersResponse>(`/workspaces/${ws}/members`);
      if (myToken !== token) return;
      members.value = res.data ?? [];
    } catch {
      if (myToken !== token) return;
      error.value = 'members.errors.load';
    } finally {
      if (myToken === token) loading.value = false;
    }
  }

  /**
   * Remove a member (`DELETE /workspaces/{ws}/members/{userId}`). On success the
   * member is dropped in place (no refetch). On the owner-guard 422 the structured
   * error is RE-THROWN so the caller can toast the right message and keep the row.
   */
  async function remove(userId: string | number): Promise<void> {
    const ws = workspaceId();
    if (ws == null) return;
    try {
      await api.delete(`/workspaces/${ws}/members/${userId}`);
      members.value = members.value.filter((m) => String(m.id) !== String(userId));
    } catch (err: unknown) {
      // Surface a structured error for the caller's toast; leave the list intact.
      throw removeMemberError(err);
    }
  }

  /** Drop all cached member state (e.g. on a workspace switch). */
  function reset(): void {
    members.value = [];
    loading.value = false;
    error.value = null;
  }

  return {
    // state
    members,
    loading,
    error,
    // actions
    fetch,
    remove,
    reset,
  };
});
