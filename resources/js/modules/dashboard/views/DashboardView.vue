<template>
  <div>
    <div class="bg-white rounded-lg shadow p-6">
      <h1 class="text-3xl font-bold text-gray-800 mb-4">{{ t('navigation.dashboard') }}</h1>
      <p class="text-gray-600 mb-4">{{ t('dashboard.welcome') }}</p>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mt-6">
        <div class="bg-blue-50 rounded-lg p-4 border border-blue-200">
          <h3 class="font-semibold text-blue-800">{{ t('users.title') }}</h3>
          <p class="text-gray-600 text-sm mt-2">
            {{ isAuthenticated ? t('users.loggedIn') : t('users.notLoggedIn') }}
          </p>
        </div>

        <div class="bg-green-50 rounded-lg p-4 border border-green-200">
          <h3 class="font-semibold text-green-800">{{ t('dashboard.availableModules') }}</h3>
          <p class="text-gray-600 text-sm mt-2">{{ availableModules.length }}</p>
        </div>

        <div class="bg-purple-50 rounded-lg p-4 border border-purple-200">
          <h3 class="font-semibold text-purple-800">{{ t('users.role') }}</h3>
          <p class="text-gray-600 text-sm mt-2">{{ permissions.length }}</p>
        </div>
      </div>

      <div class="mt-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
        <h3 class="font-semibold text-gray-800 mb-2">{{ t('dashboard.debugInfo') }}:</h3>
        <pre class="text-xs text-gray-600 overflow-auto max-h-40">{{ debugInfo }}</pre>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useAuth } from '@/composables/useAuth';
import { usePermissions } from '@/composables/usePermissions';
import { useI18n } from '@/composables/useI18n';
import { useUserStore } from '@/store/user';

const { t } = useI18n();
const { isAuthenticated, userName } = useAuth();
const { permissions } = usePermissions();
const userStore = useUserStore();

const availableModules = computed(() => userStore.modules);

const debugInfo = computed(() => {
  return JSON.stringify(
    {
      user: userStore.user,
      permissions: userStore.permissions,
      modules: userStore.modules,
    },
    null,
    2
  );
});
</script>

<style scoped>
/* Styles will be handled by Tailwind CSS */
</style>
