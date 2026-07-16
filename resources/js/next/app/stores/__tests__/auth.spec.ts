// @vitest-environment happy-dom
// Unit tests for the "next" auth store.
//
// The store is the session source of truth the router guard + App Shell read from,
// so these tests pin the verified backend CONTRACT to behavior: login persists the
// token + applies context, fetchMe/applyContext map the context shape, logout +
// init clear/hydrate correctly, and the permission helper gates as expected. The
// api client is mocked so no real HTTP happens.
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
import { useAuthStore, usePermissions } from '../auth';

const apiMock = api as unknown as {
  get: ReturnType<typeof vi.fn>;
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  delete: ReturnType<typeof vi.fn>;
};

const CONTEXT = {
  user: { id: 1, name: 'Ada Lovelace', email: 'ada@example.com' },
  permissions: ['forms.view', 'forms.create'],
  workspaces: [
    { id: 10, name: 'Acme' },
    { id: 20, name: 'Globex' },
  ],
  current_workspace: 10,
};

describe('next auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    localStorage.clear();
    vi.clearAllMocks();
  });

  describe('login', () => {
    it('posts the credentials, persists the token, and applies the context', async () => {
      apiMock.post.mockResolvedValueOnce({ token: 'tok_123', ...CONTEXT });
      const auth = useAuthStore();

      await auth.login('ada@example.com', 'secret', true);

      expect(apiMock.post).toHaveBeenCalledWith('/auth/login', {
        email: 'ada@example.com',
        password: 'secret',
        remember: true,
      });
      expect(auth.token).toBe('tok_123');
      expect(localStorage.getItem('taskio_token')).toBe('tok_123');
      expect(auth.isAuthenticated).toBe(true);
      expect(auth.userName).toBe('Ada Lovelace');
      expect(auth.workspaces).toHaveLength(2);
      expect(auth.currentWorkspaceId).toBe('10');
      expect(localStorage.getItem('taskio_workspace')).toBe('10');
    });
  });

  describe('getters', () => {
    it('resolves currentWorkspace from the active id', async () => {
      apiMock.post.mockResolvedValueOnce({ token: 't', ...CONTEXT });
      const auth = useAuthStore();
      await auth.login('a', 'b');
      expect(auth.currentWorkspace?.name).toBe('Acme');
    });

    it('userName falls back to email then empty', () => {
      const auth = useAuthStore();
      expect(auth.userName).toBe('');
      auth.applyContext({ user: { id: 2, email: 'x@y.z' } });
      expect(auth.userName).toBe('x@y.z');
    });
  });

  describe('permissions', () => {
    it('can() reflects granted permissions', () => {
      const auth = useAuthStore();
      auth.applyContext({ permissions: ['forms.view'] });
      expect(auth.can('forms.view')).toBe(true);
      expect(auth.can('forms.delete')).toBe(false);
    });

    it('usePermissions exposes can/canAny/canAll', () => {
      const auth = useAuthStore();
      auth.applyContext({ permissions: ['a', 'b'] });
      const { can, canAny, canAll } = usePermissions();
      expect(can('a')).toBe(true);
      expect(canAny(['x', 'b'])).toBe(true);
      expect(canAll(['a', 'b'])).toBe(true);
      expect(canAll(['a', 'z'])).toBe(false);
    });
  });

  describe('setCurrentWorkspace', () => {
    it('persists the new id and refreshes the context via fetchMe', async () => {
      apiMock.get.mockResolvedValueOnce({ ...CONTEXT, current_workspace: 20 });
      const auth = useAuthStore();

      await auth.setCurrentWorkspace(20);

      expect(localStorage.getItem('taskio_workspace')).toBe('20');
      expect(apiMock.get).toHaveBeenCalledWith('/auth/me');
      expect(auth.currentWorkspaceId).toBe('20');
    });
  });

  describe('logout', () => {
    it('posts logout and clears the session + storage', async () => {
      apiMock.post.mockResolvedValueOnce({ token: 't', ...CONTEXT });
      const auth = useAuthStore();
      await auth.login('a', 'b');

      apiMock.post.mockResolvedValueOnce({});
      await auth.logout();

      expect(apiMock.post).toHaveBeenCalledWith('/auth/logout');
      expect(auth.isAuthenticated).toBe(false);
      expect(auth.token).toBeNull();
      expect(localStorage.getItem('taskio_token')).toBeNull();
      expect(localStorage.getItem('taskio_workspace')).toBeNull();
    });
  });

  describe('init', () => {
    it('does nothing (but marks ready) when no token is stored', async () => {
      const auth = useAuthStore();
      await auth.init();
      expect(apiMock.get).not.toHaveBeenCalled();
      expect(auth.ready).toBe(true);
      expect(auth.isAuthenticated).toBe(false);
    });

    it('hydrates the session from a stored token via /auth/me', async () => {
      localStorage.setItem('taskio_token', 'stored_tok');
      apiMock.get.mockResolvedValueOnce(CONTEXT);
      const auth = useAuthStore();

      await auth.init();

      expect(apiMock.get).toHaveBeenCalledWith('/auth/me');
      expect(auth.isAuthenticated).toBe(true);
      expect(auth.ready).toBe(true);
    });

    it('clears an invalid stored token on /auth/me failure', async () => {
      localStorage.setItem('taskio_token', 'bad_tok');
      apiMock.get.mockRejectedValueOnce(new Error('401'));
      const auth = useAuthStore();

      await auth.init();

      expect(auth.token).toBeNull();
      expect(localStorage.getItem('taskio_token')).toBeNull();
      expect(auth.isAuthenticated).toBe(false);
      expect(auth.ready).toBe(true);
    });

    it('shares one in-flight promise across concurrent callers', async () => {
      localStorage.setItem('taskio_token', 'tok');
      apiMock.get.mockResolvedValueOnce(CONTEXT);
      const auth = useAuthStore();

      await Promise.all([auth.init(), auth.init()]);

      expect(apiMock.get).toHaveBeenCalledTimes(1);
    });
  });
});
