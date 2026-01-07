import { useUserStore } from '@/store/user';
import { computed } from 'vue';

export function useAuth() {
  const userStore = useUserStore();

  const user = computed(() => userStore.user);
  const isAuthenticated = computed(() => userStore.isAuthenticated);
  const userName = computed(() => userStore.userName);

  const login = (userData) => {
    userStore.setUser(userData);
  };

  const logout = () => {
    userStore.clearUser();
  };

  return {
    user,
    isAuthenticated,
    userName,
    login,
    logout,
  };
}
