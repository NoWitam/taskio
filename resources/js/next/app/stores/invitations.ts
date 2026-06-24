// Workspace Invitations store for the isolated "next" frontend (Pinia setup store).
//
// Owns SERVER STATE for the CURRENT workspace's PENDING invitations + the admin
// invite/revoke/resend actions. The CURRENT workspace is the one the api singleton
// already scopes every request to; the store reads `auth.currentWorkspaceId` only
// to build the URL.
//
// Backend contract (VERIFIED — do NOT invent fields):
//   GET    /workspaces/{ws}/invitations
//     → { data: [{ id, email, status, invited_by:{id,name}, expires_at, created_at }] }
//   POST   /workspaces/{ws}/invitations   { email }
//     → 201 { data: invitation };  422 errors.email[0] ∈ 'already_member' | 'already_invited'
//   DELETE /workspaces/{ws}/invitations/{id}            → 204 (revoke)
//   POST   /workspaces/{ws}/invitations/{id}/resend     → 200 { data: invitation } (token rotated)
//
// The invite 422 is surfaced STRUCTURALLY (a typed `kind`) so the UI can bind it
// to the email field and translate with its own catalog (the body carries the raw
// key, not a translated string). Self-contained: NO legacy `resources/js/` import.
import { defineStore } from 'pinia';
import { ref } from 'vue';
import { api } from '../lib/api';
import { useAuthStore } from './auth';

/** Invitation status the backend reports for an admin-listed invite. */
export type InvitationStatus = 'pending' | 'accepted' | 'revoked' | 'expired';

/** A pending workspace invitation (admin shape — never carries the token). */
export interface WorkspaceInvitation {
  id: string | number;
  email: string;
  status: InvitationStatus | string;
  invited_by: { id: string | number | null; name: string | null };
  expires_at: string | null;
  created_at: string | null;
}

/** Single-resource response wrapper (`{ data: WorkspaceInvitation }`). */
interface InvitationResponse {
  data: WorkspaceInvitation;
}

/** Collection response wrapper (`{ data: WorkspaceInvitation[] }`). */
interface InvitationsResponse {
  data: WorkspaceInvitation[];
}

/** The structured invite-422 `kind`s the email field can carry. */
export type InviteErrorKind = 'already_member' | 'already_invited' | 'generic';

/** Structured invite error surfaced to the UI (bound to the email field). */
export interface InviteError {
  kind: InviteErrorKind;
}

/**
 * Detect the invite 422 and return a typed `kind`. The backend adds the raw key
 * (`already_member` / `already_invited`) under the `email` validation field, so we
 * match it structurally and let the UI translate. Other failures map to `generic`.
 */
export function inviteError(err: unknown): InviteError {
  const res = (err as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } })
    ?.response;
  if (res?.status === 422) {
    const emailErrors = res.data?.errors?.email ?? [];
    if (emailErrors.includes('already_member')) return { kind: 'already_member' };
    if (emailErrors.includes('already_invited')) return { kind: 'already_invited' };
  }
  return { kind: 'generic' };
}

export const useInvitationsStore = defineStore('next-invitations', () => {
  // --- State ---------------------------------------------------------------
  const invitations = ref<WorkspaceInvitation[]>([]);
  const loading = ref(false);
  /** First-load error flag (drives the error+retry state). */
  const error = ref<string | null>(null);
  /** True while a `POST /invitations` is in flight (the invite-form button). */
  const inviting = ref(false);

  // Request token so a refetch supersedes any in-flight list request.
  let token = 0;

  function workspaceId(): string | number | null {
    return useAuthStore().currentWorkspaceId;
  }

  // --- Actions -------------------------------------------------------------
  /** Fetch the pending invitations (`GET /workspaces/{ws}/invitations`). */
  async function fetch(): Promise<void> {
    const ws = workspaceId();
    if (ws == null) {
      invitations.value = [];
      return;
    }
    const myToken = (token += 1);
    loading.value = true;
    error.value = null;
    try {
      const res = await api.get<InvitationsResponse>(`/workspaces/${ws}/invitations`);
      if (myToken !== token) return;
      invitations.value = res.data ?? [];
    } catch {
      if (myToken !== token) return;
      error.value = 'invitations.errors.load';
    } finally {
      if (myToken === token) loading.value = false;
    }
  }

  /**
   * Invite by email (`POST /workspaces/{ws}/invitations`). On success the new
   * invitation is PREPENDED (newest-first) and returned. On a 422 the structured
   * {@link InviteError} is RE-THROWN so the form can bind it to the email field.
   */
  async function invite(email: string): Promise<WorkspaceInvitation> {
    const ws = workspaceId();
    if (ws == null) throw { kind: 'generic' } satisfies InviteError;
    inviting.value = true;
    try {
      const res = await api.post<InvitationResponse>(`/workspaces/${ws}/invitations`, { email });
      const created = res.data;
      invitations.value = [created, ...invitations.value];
      return created;
    } catch (err: unknown) {
      throw inviteError(err);
    } finally {
      inviting.value = false;
    }
  }

  /** Revoke an invitation (`DELETE /...`). Drops it from the list in place. */
  async function revoke(id: string | number): Promise<void> {
    const ws = workspaceId();
    if (ws == null) return;
    await api.delete(`/workspaces/${ws}/invitations/${id}`);
    invitations.value = invitations.value.filter((inv) => String(inv.id) !== String(id));
  }

  /**
   * Resend an invitation (`POST /.../resend`). The backend rotates the token and
   * returns the refreshed invitation; we replace it in place (e.g. its new
   * `expires_at`). Returns the refreshed invitation.
   */
  async function resend(id: string | number): Promise<WorkspaceInvitation> {
    const ws = workspaceId();
    if (ws == null) throw { kind: 'generic' } satisfies InviteError;
    const res = await api.post<InvitationResponse>(`/workspaces/${ws}/invitations/${id}/resend`);
    const refreshed = res.data;
    const idx = invitations.value.findIndex((inv) => String(inv.id) === String(id));
    if (idx >= 0) {
      const next = [...invitations.value];
      next[idx] = refreshed;
      invitations.value = next;
    }
    return refreshed;
  }

  /** Drop all cached invitation state (e.g. on a workspace switch). */
  function reset(): void {
    invitations.value = [];
    loading.value = false;
    error.value = null;
    inviting.value = false;
  }

  return {
    // state
    invitations,
    loading,
    error,
    inviting,
    // actions
    fetch,
    invite,
    revoke,
    resend,
    reset,
  };
});
