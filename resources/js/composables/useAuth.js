import { useUserStore } from '@/store/user';
import { computed } from 'vue';

export function useAuth() {
  const userStore = useUserStore();

  const user = computed(() => userStore.user);
  const isAuthenticated = computed(() => userStore.isAuthenticated);
  const userName = computed(() => userStore.userName);
  const workspaces = computed(() => userStore.workspaces);
  const currentWorkspace = computed(() => userStore.currentWorkspace);

  const login = (email, password, remember = false) => userStore.login(email, password, remember);

  const logout = () => userStore.logout();

  const switchWorkspace = (id) => userStore.setCurrentWorkspace(id);

  return {
    user,
    isAuthenticated,
    userName,
    workspaces,
    currentWorkspace,
    login,
    logout,
    switchWorkspace,
  };
}
