import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { api } from '@/lib/api';

const TOKEN_KEY = 'taskio_token';
const WORKSPACE_KEY = 'taskio_workspace';

export const useUserStore = defineStore('user', () => {
  const user = ref(null);
  const permissions = ref([]);
  const modules = ref([]);
  const csrfToken = ref('');
  const token = ref(localStorage.getItem(TOKEN_KEY));
  const workspaces = ref([]);
  const currentWorkspaceId = ref(localStorage.getItem(WORKSPACE_KEY));

  const isAuthenticated = computed(() => user.value !== null);
  const userName = computed(() => user.value?.name || 'Guest');
  const currentWorkspace = computed(
    () => workspaces.value.find((workspace) => workspace.id === currentWorkspaceId.value) || null
  );

  const setUser = (userData) => {
    user.value = userData;
  };

  const clearUser = () => {
    user.value = null;
    permissions.value = [];
    modules.value = [];
  };

  const setPermissions = (perms) => {
    permissions.value = perms;
  };

  const setModules = (mods) => {
    modules.value = mods;
  };

  const setCsrfToken = (value) => {
    csrfToken.value = value;
  };

  const persistToken = (value) => {
    token.value = value;
    if (value) {
      localStorage.setItem(TOKEN_KEY, value);
    } else {
      localStorage.removeItem(TOKEN_KEY);
    }
  };

  const persistWorkspace = (id) => {
    currentWorkspaceId.value = id;
    if (id) {
      localStorage.setItem(WORKSPACE_KEY, id);
    } else {
      localStorage.removeItem(WORKSPACE_KEY);
    }
  };

  // Apply the shared auth context payload returned by /auth/login and /auth/me.
  const applyContext = (data) => {
    if (data.user !== undefined) {
      user.value = data.user;
    }
    if (data.permissions !== undefined) {
      permissions.value = data.permissions;
    }
    if (data.workspaces !== undefined) {
      workspaces.value = data.workspaces;
    }
    if (data.current_workspace !== undefined) {
      persistWorkspace(data.current_workspace);
    }
  };

  const login = async (email, password, remember = false) => {
    const data = await api.post('/auth/login', { email, password, remember });
    persistToken(data.token);
    applyContext(data);
    return data;
  };

  const fetchMe = async () => {
    const data = await api.get('/auth/me');
    applyContext(data);
    return data;
  };

  // Switch the active workspace and refresh the context so permissions reflect it.
  const setCurrentWorkspace = async (id) => {
    persistWorkspace(id);
    await fetchMe();
  };

  const logout = async () => {
    try {
      if (token.value) {
        await api.post('/auth/logout');
      }
    } finally {
      persistToken(null);
      persistWorkspace(null);
      workspaces.value = [];
      clearUser();
    }
  };

  // Re-hydrate the session on app start when a token is already stored.
  const init = async () => {
    if (!token.value) {
      return;
    }
    try {
      await fetchMe();
    } catch {
      persistToken(null);
    }
  };

  const initializeFromWindow = () => {
    if (typeof window !== 'undefined' && window.__INITIAL_STATE__) {
      const state = window.__INITIAL_STATE__;
      if (state.user) {
        setUser(state.user);
      }
      if (state.permissions) {
        setPermissions(state.permissions);
      }
      if (state.modules) {
        setModules(state.modules);
      }
      if (state.csrfToken) {
        setCsrfToken(state.csrfToken);
      }
    }
  };

  return {
    user,
    permissions,
    modules,
    csrfToken,
    token,
    workspaces,
    currentWorkspaceId,
    currentWorkspace,
    isAuthenticated,
    userName,
    setUser,
    clearUser,
    setPermissions,
    setModules,
    setCsrfToken,
    login,
    logout,
    fetchMe,
    setCurrentWorkspace,
    init,
    initializeFromWindow,
  };
});
