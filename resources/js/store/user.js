import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export const useUserStore = defineStore('user', () => {
  const user = ref(null);
  const permissions = ref([]);
  const modules = ref([]);
  const csrfToken = ref('');

  const isAuthenticated = computed(() => user.value !== null);
  const userName = computed(() => user.value?.name || 'Guest');

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

  const setCsrfToken = (token) => {
    csrfToken.value = token;
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
    isAuthenticated,
    userName,
    setUser,
    clearUser,
    setPermissions,
    setModules,
    setCsrfToken,
    initializeFromWindow,
  };
});
