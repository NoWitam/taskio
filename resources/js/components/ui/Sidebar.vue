<template>
  <aside
    :class="[
      'sidebar bg-white border-r border-gray-200 dark:bg-gray-800 transition-all duration-300',
      isOpen ? 'w-64' : 'w-0',
    ]"
  >
    <!-- Logo Section -->
    <div class="h-16 flex items-center justify-between px-4 border-b border-gray-200">
      <div v-if="isOpen" class="flex items-center gap-2">
        <span class="font-bold text-gray-800 dark:text-gray-200 text-lg">TaskIO</span>
      </div>
    </div>

    <!-- Main Menu -->
    <nav class="p-3 space-y-1">
      <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider">
        {{ t('navigation.mainMenu') }}
      </div>
      
      <div class="flex flex-col gap-2">
        <router-link
          v-for="module in availableModules"
          :key="module"
          :to="`/app/${module}`"
          :class="[
            'flex items-center gap-3 px-3 py-3 rounded-lg transition-colors hover:bg-primary/10 hover:text-primary',
            isActiveModule(module)
              ? 'bg-primary/20 text-primary'
              : 'text-gray-600 dark:text-gray-200',
          ]"
        >
          <Icon
            :name="getModuleIcon(module)"
            size="sm"
            :class="[
              'shrink-0',
            ]"
          />
          <span class="font-medium text-sm">
            {{ getModuleLabel(module) }}
          </span>
        </router-link>
      </div>

    </nav>
    <!-- Footer -->
    <div class="absolute bottom-0 left-0 right-0 p-3 border-t border-gray-200 bg-gray-50">
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-full bg-linear-to-br from-blue-400 to-purple-500" />
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-gray-800 truncate">{{ userName }}</p>
          <p class="text-xs text-gray-500 truncate">User</p>
        </div>
      </div>
    </div>
  </aside>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import Icon from '@/components/ui/Icon.vue';
import { useAuth } from '@/composables/useAuth';
import { useI18n } from '@/composables/useI18n';
import { useUserStore } from '@/store/user';

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: true,
  },
});

const emit = defineEmits(['toggle']);

const route = useRoute();
const { userName } = useAuth();
const { t } = useI18n();
const userStore = useUserStore();

const availableModules = computed(() => {
  return userStore.modules.length > 0 ? userStore.modules : ['dashboard'];
});

const isActiveModule = (module) => {
  return route.path.includes(`/app/${module}`);
};

const getModuleIcon = (module) => {
  const iconMap = {
    dashboard: 'home',
    tasks: 'check-circle',
    forms: 'file-text',
    users: 'users',
    settings: 'settings',
  };
  return iconMap[module] || 'check-circle';
};

const formatModuleName = (module) => {
  return module.charAt(0).toUpperCase() + module.slice(1);
};

const getModuleLabel = (module) => {
  const label = t(`modules.${module}`, '');
  return label || formatModuleName(module);
};

const toggleSidebar = () => {
  emit('toggle');
};

const handleSettings = () => {
  // Settings functionality to be implemented
};
</script>

<style scoped>
.sidebar {
  overflow-y: auto;
  position: relative;
  padding-bottom: 120px;
}

/* Custom scrollbar */
.sidebar::-webkit-scrollbar {
  width: 6px;
}

.sidebar::-webkit-scrollbar-track {
  background: transparent;
}

.sidebar::-webkit-scrollbar-thumb {
  background: #d1d5db;
  border-radius: 3px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
  background: #9ca3af;
}
</style>
