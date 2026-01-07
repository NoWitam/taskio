<script setup>
import Icon from '@/components/ui/Icon.vue';
import { useTheme } from '@/composables/useTheme';

const emit = defineEmits(['toggle-sidebar']);

const { isDarkMode, toggleTheme } = useTheme();

const toggleSidebar = () => emit('toggle-sidebar');
</script>

<template>
  <header class="p-4 flex justify-between bg-background border-b border-gray-200">
    <button
        @click="toggleSidebar"
        class="p-2 rounded-md cursor-pointer text-gray-700 hover:bg-primary/20 hover:text-primary dark:text-gray-200 transition-colors"
        aria-label="Toggle sidebar"
        title="Toggle sidebar"
    >
        <Icon name="menu" size="sm" class="text-gray-700 dark:text-gray-200" />
    </button>

    <!-- Right: theme toggle -->
    <button
        @click="toggleTheme"
        class="p-2 rounded-md cursor-pointer text-gray-700 hover:bg-primary/20 hover:text-primary dark:text-gray-200 transition-colors"
        :title="isDarkMode ? 'Light mode' : 'Dark mode'"
        aria-label="Toggle theme"
    >
        <Icon :name="isDarkMode ? 'sun' : 'moon'" size="sm" />
    </button>
  </header>
</template>
