<script setup lang="ts">
import { ref, watch, computed } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';
import { useTheme } from '@/composables/useTheme';

export type NavItem = {
  label: string;
  href?: string;
  active?: boolean;
  badge?: number;
  disabled?: boolean;
};

const props = withDefaults(
  defineProps<{
    title?: string;
    items?: NavItem[];
    searchable?: boolean;
    searchPlaceholder?: string;
  }>(),
  {
    items: () => [],
    searchable: false,
    searchPlaceholder: 'Szukaj…',
  }
);

const emit = defineEmits<{
  (e: 'toggle-sidebar'): void;
  (e: 'nav-click', item: NavItem): void;
  (e: 'search', query: string): void;
  (e: 'user-menu', action: string): void;
}>();

const { isDarkMode, toggleTheme } = useTheme();

const toggleSidebar = () => emit('toggle-sidebar');

const searchQuery = ref('');
watch(searchQuery, (q) => emit('search', q));

const hasNav = computed(() => (props.items?.length ?? 0) > 0);

function navCls(it: NavItem) {
  return [
    'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
    it.disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:bg-secondary/60',
    it.active ? 'bg-primary/15 text-primary' : 'text-foreground/80',
  ];
}

function onNavClick(it: NavItem) {
  if (it.disabled) return;
  emit('nav-click', it);
}

function fireUserMenu(action: string, closeMenu?: () => void) {
  emit('user-menu', action);
  if (typeof closeMenu === 'function') closeMenu();
}
</script>

<template>
  <header class="p-4 flex items-center justify-between bg-background border-b border-border">
    <div class="flex items-center gap-3 min-w-0">
      <button
        @click="toggleSidebar"
        class="p-2 rounded-md cursor-pointer text-foreground/80 hover:bg-primary/15 hover:text-primary transition-colors"
        aria-label="Toggle sidebar"
        title="Toggle sidebar"
      >
        <Icon name="menu" size="sm" class="text-foreground/80" />
      </button>

      <div v-if="title" class="font-semibold text-foreground truncate">
        {{ title }}
      </div>

      <nav v-if="hasNav" class="hidden md:flex items-center gap-1">
        <button
          v-for="it in items"
          :key="it.label"
          type="button"
          :class="navCls(it)"
          @click="onNavClick(it)"
        >
          <span class="truncate">{{ it.label }}</span>
          <span
            v-if="typeof it.badge === 'number'"
            class="inline-flex min-w-6 items-center justify-center rounded-full bg-secondary px-2 py-0.5 text-xs text-secondary-foreground"
          >
            {{ it.badge }}
          </span>
        </button>
      </nav>
    </div>

    <div class="flex items-center gap-2">
      <div v-if="searchable" class="hidden sm:block">
        <input
          v-model="searchQuery"
          type="search"
          :placeholder="searchPlaceholder"
          class="h-10 w-56 rounded-lg border border-border bg-card px-3 text-sm text-foreground placeholder:text-muted-foreground/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30"
        />
      </div>

      <button
        @click="toggleTheme"
        class="p-2 rounded-md cursor-pointer text-foreground/80 hover:bg-primary/15 hover:text-primary transition-colors"
        :title="isDarkMode ? 'Light mode' : 'Dark mode'"
        aria-label="Toggle theme"
      >
        <Icon :name="isDarkMode ? 'sun' : 'moon'" size="sm" />
      </button>

      <DropdownMenu align="end">
        <template #activator="{ toggle }">
          <button
            type="button"
            class="p-2 rounded-md cursor-pointer text-foreground/80 hover:bg-secondary/60 transition-colors"
            aria-label="User menu"
            title="User menu"
            @click.stop="toggle()"
          >
            <Icon name="user" size="sm" />
          </button>
        </template>

        <template #default="{ closeMenu }">
          <button
            type="button"
            class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition cursor-pointer hover:bg-secondary/60 focus:bg-secondary/60"
            @click="fireUserMenu('profile', closeMenu)"
          >
            Profil
          </button>
          <button
            type="button"
            class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition cursor-pointer hover:bg-secondary/60 focus:bg-secondary/60"
            @click="fireUserMenu('settings', closeMenu)"
          >
            Ustawienia
          </button>
          <button
            type="button"
            class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition cursor-pointer hover:bg-secondary/60 focus:bg-secondary/60 text-danger"
            @click="fireUserMenu('logout', closeMenu)"
          >
            Wyloguj
          </button>
        </template>
      </DropdownMenu>
    </div>
  </header>
</template>
