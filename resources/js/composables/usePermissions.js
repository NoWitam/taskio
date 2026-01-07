import { useUserStore } from '@/store/user';
import { computed } from 'vue';

export function usePermissions() {
  const userStore = useUserStore();

  const permissions = computed(() => userStore.permissions);

  const hasPermission = (permission) => {
    if (!Array.isArray(permissions.value)) {
      return false;
    }
    return permissions.value.includes(permission);
  };

  const hasAnyPermission = (permissionList) => {
    if (!Array.isArray(permissionList)) {
      return false;
    }
    return permissionList.some((permission) => hasPermission(permission));
  };

  const hasAllPermissions = (permissionList) => {
    if (!Array.isArray(permissionList)) {
      return false;
    }
    return permissionList.every((permission) => hasPermission(permission));
  };

  const can = (permission) => {
    return hasPermission(permission);
  };

  const canAny = (permissionList) => {
    return hasAnyPermission(permissionList);
  };

  const canAll = (permissionList) => {
    return hasAllPermissions(permissionList);
  };

  return {
    permissions,
    hasPermission,
    hasAnyPermission,
    hasAllPermissions,
    can,
    canAny,
    canAll,
  };
}
