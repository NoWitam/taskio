// @vitest-environment happy-dom
// Unit tests for the "next" members store — the current-workspace roster + remove.
// fetch must hit the workspace-scoped URL, read `res.data`, and surface a load
// error flag; remove must drop the member in place and surface the owner-guard 422
// structurally (`cannot_remove_owner`) so the page can toast the right message and
// keep the row. The api client is mocked so no real HTTP happens.
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
import { useMembersStore, removeMemberError, type WorkspaceMember } from '../members';
import { useAuthStore } from '../auth';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

function member(overrides: Partial<WorkspaceMember> = {}): WorkspaceMember {
  return { id: 'u1', name: 'Ada', email: 'ada@example.com', is_owner: false, ...overrides };
}

/** Set a current workspace so the store builds the scoped URL. */
function withWorkspace(id = 'w1'): void {
  const auth = useAuthStore();
  auth.currentWorkspaceId = id;
}

describe('next members store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
    vi.clearAllMocks();
  });

  describe('fetch', () => {
    it('GETs the workspace-scoped members and reads res.data', async () => {
      withWorkspace('w1');
      const store = useMembersStore();
      const owner = member({ id: 'o1', name: 'Owner', is_owner: true });
      apiMock.get.mockResolvedValueOnce({ data: [owner, member()] });

      await store.fetch();

      expect(apiMock.get).toHaveBeenCalledWith('/workspaces/w1/members');
      expect(store.members.map((m) => m.id)).toEqual(['o1', 'u1']);
      expect(store.loading).toBe(false);
      expect(store.error).toBeNull();
    });

    it('flips the error flag and leaves members intact on failure', async () => {
      withWorkspace('w1');
      const store = useMembersStore();
      store.members = [member()];
      apiMock.get.mockRejectedValueOnce({ response: { status: 500 } });

      await store.fetch();

      expect(store.error).toBe('members.errors.load');
      expect(store.members).toHaveLength(1);
      expect(store.loading).toBe(false);
    });

    it('no-ops without a current workspace', async () => {
      const store = useMembersStore();
      await store.fetch();
      expect(apiMock.get).not.toHaveBeenCalled();
    });
  });

  describe('remove', () => {
    it('DELETEs and drops the member in place', async () => {
      withWorkspace('w1');
      const store = useMembersStore();
      store.members = [member({ id: 'o1', is_owner: true }), member({ id: 'u1' })];
      apiMock.delete.mockResolvedValueOnce(undefined);

      await store.remove('u1');

      expect(apiMock.delete).toHaveBeenCalledWith('/workspaces/w1/members/u1');
      expect(store.members.map((m) => m.id)).toEqual(['o1']);
    });

    it('re-throws the owner-guard 422 as a structured error and keeps the row', async () => {
      withWorkspace('w1');
      const store = useMembersStore();
      store.members = [member({ id: 'o1', is_owner: true })];
      apiMock.delete.mockRejectedValueOnce({
        response: { status: 422, data: { errors: { user: ['cannot_remove_owner'] } } },
      });

      await expect(store.remove('o1')).rejects.toEqual({ kind: 'cannot_remove_owner' });
      // The list is left intact so the owner row stays visible.
      expect(store.members).toHaveLength(1);
    });

    it('re-throws a generic error for non-owner-guard failures', async () => {
      withWorkspace('w1');
      const store = useMembersStore();
      store.members = [member({ id: 'u1' })];
      apiMock.delete.mockRejectedValueOnce({ response: { status: 500 } });

      await expect(store.remove('u1')).rejects.toEqual({ kind: 'generic' });
      expect(store.members).toHaveLength(1);
    });
  });

  describe('removeMemberError', () => {
    it('maps the user/cannot_remove_owner 422 to a structured kind', () => {
      expect(
        removeMemberError({
          response: { status: 422, data: { errors: { user: ['cannot_remove_owner'] } } },
        }),
      ).toEqual({ kind: 'cannot_remove_owner' });
    });

    it('maps anything else to generic', () => {
      expect(removeMemberError({ response: { status: 403 } })).toEqual({ kind: 'generic' });
      expect(removeMemberError(new Error('boom'))).toEqual({ kind: 'generic' });
    });
  });
});
