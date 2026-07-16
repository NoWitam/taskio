// @vitest-environment happy-dom
// Unit tests for the "next" invitations store — pending list + invite/revoke/resend.
// invite must POST { email }, PREPEND the new invitation, and surface the
// already_member / already_invited 422 structurally so the form binds it to the
// email field; revoke removes in place; resend REPLACES in place (token rotated →
// fresh expires_at). The api client is mocked so no real HTTP happens.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';

vi.mock('../../lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
  TOKEN_KEY: 'taskio_token',
  WORKSPACE_KEY: 'taskio_workspace',
}));

import { api } from '../../lib/api';
import {
  useInvitationsStore,
  inviteError,
  type WorkspaceInvitation,
} from '../invitations';
import { useAuthStore } from '../auth';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function invitation(overrides: Partial<WorkspaceInvitation> = {}): WorkspaceInvitation {
  return {
    id: 'i1',
    email: 'new@example.com',
    status: 'pending',
    invited_by: { id: 'o1', name: 'Owner' },
    expires_at: '2026-07-01T00:00:00Z',
    created_at: '2026-06-01T00:00:00Z',
    ...overrides,
  };
}

function withWorkspace(id = 'w1'): void {
  useAuthStore().currentWorkspaceId = id;
}

describe('next invitations store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
    vi.clearAllMocks();
  });

  describe('fetch', () => {
    it('GETs the workspace-scoped invitations and reads res.data', async () => {
      withWorkspace('w1');
      const store = useInvitationsStore();
      apiMock.get.mockResolvedValueOnce({ data: [invitation()] });

      await store.fetch();

      expect(apiMock.get).toHaveBeenCalledWith('/workspaces/w1/invitations');
      expect(store.invitations.map((i) => i.id)).toEqual(['i1']);
      expect(store.error).toBeNull();
    });

    it('flips the error flag on failure', async () => {
      withWorkspace('w1');
      const store = useInvitationsStore();
      apiMock.get.mockRejectedValueOnce({ response: { status: 500 } });

      await store.fetch();

      expect(store.error).toBe('invitations.errors.load');
    });
  });

  describe('invite', () => {
    it('POSTs { email } and PREPENDS the new invitation', async () => {
      withWorkspace('w1');
      const store = useInvitationsStore();
      store.invitations = [invitation({ id: 'old' })];
      apiMock.post.mockResolvedValueOnce({ data: invitation({ id: 'fresh', email: 'a@b.com' }) });

      const created = await store.invite('a@b.com');

      expect(apiMock.post).toHaveBeenCalledWith('/workspaces/w1/invitations', { email: 'a@b.com' });
      expect(created.id).toBe('fresh');
      expect(store.invitations.map((i) => i.id)).toEqual(['fresh', 'old']);
      expect(store.inviting).toBe(false);
    });

    it('re-throws already_member as a structured email error', async () => {
      withWorkspace('w1');
      const store = useInvitationsStore();
      apiMock.post.mockRejectedValueOnce({
        response: { status: 422, data: { errors: { email: ['already_member'] } } },
      });

      await expect(store.invite('a@b.com')).rejects.toEqual({ kind: 'already_member' });
      expect(store.invitations).toHaveLength(0);
      expect(store.inviting).toBe(false);
    });

    it('re-throws already_invited as a structured email error', async () => {
      withWorkspace('w1');
      const store = useInvitationsStore();
      apiMock.post.mockRejectedValueOnce({
        response: { status: 422, data: { errors: { email: ['already_invited'] } } },
      });

      await expect(store.invite('a@b.com')).rejects.toEqual({ kind: 'already_invited' });
    });

    it('re-throws generic for non-422 failures', async () => {
      withWorkspace('w1');
      const store = useInvitationsStore();
      apiMock.post.mockRejectedValueOnce({ response: { status: 500 } });

      await expect(store.invite('a@b.com')).rejects.toEqual({ kind: 'generic' });
    });
  });

  describe('revoke', () => {
    it('DELETEs and removes the invitation in place', async () => {
      withWorkspace('w1');
      const store = useInvitationsStore();
      store.invitations = [invitation({ id: 'i1' }), invitation({ id: 'i2' })];
      apiMock.delete.mockResolvedValueOnce(undefined);

      await store.revoke('i1');

      expect(apiMock.delete).toHaveBeenCalledWith('/workspaces/w1/invitations/i1');
      expect(store.invitations.map((i) => i.id)).toEqual(['i2']);
    });
  });

  describe('resend', () => {
    it('POSTs resend and REPLACES the invitation in place', async () => {
      withWorkspace('w1');
      const store = useInvitationsStore();
      store.invitations = [
        invitation({ id: 'i1', expires_at: '2026-07-01T00:00:00Z' }),
        invitation({ id: 'i2' }),
      ];
      apiMock.post.mockResolvedValueOnce({
        data: invitation({ id: 'i1', expires_at: '2026-08-01T00:00:00Z' }),
      });

      const refreshed = await store.resend('i1');

      expect(apiMock.post).toHaveBeenCalledWith('/workspaces/w1/invitations/i1/resend');
      expect(refreshed.expires_at).toBe('2026-08-01T00:00:00Z');
      // Replaced in place — same position, same count.
      expect(store.invitations.map((i) => i.id)).toEqual(['i1', 'i2']);
      expect(store.invitations[0].expires_at).toBe('2026-08-01T00:00:00Z');
    });
  });

  describe('inviteError', () => {
    it('maps the email 422 keys structurally', () => {
      expect(
        inviteError({ response: { status: 422, data: { errors: { email: ['already_member'] } } } }),
      ).toEqual({ kind: 'already_member' });
      expect(
        inviteError({ response: { status: 422, data: { errors: { email: ['already_invited'] } } } }),
      ).toEqual({ kind: 'already_invited' });
    });

    it('maps anything else to generic', () => {
      expect(inviteError({ response: { status: 500 } })).toEqual({ kind: 'generic' });
    });
  });
});
