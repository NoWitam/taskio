<script setup lang="ts">
// AppLayout — the authenticated app shell for the "next" frontend.
//
// Wraps every authed page with AppShell: a Sidebar (brand + nav, with the IA for
// not-yet-built modules shown as disabled "coming soon" rows) and a Navbar (mobile
// menu button, page title, and trailing language switcher + theme toggle + a user
// menu with an optional workspace switcher and Sign out). The page renders through
// <router-view>.
//
// All labels are translated; the active nav item is driven by the router and marked
// with aria-current via SidebarItem. The mobile drawer / focus trap / scrim are
// owned by AppShell.
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../app/stores/auth';
import { useI18n } from '../app/i18n';
import { useTheme } from '../app/lib/theme';
import AppShell from '../ui/layout/AppShell.vue';
import Sidebar from '../ui/layout/Sidebar.vue';
import SidebarSection from '../ui/layout/SidebarSection.vue';
import SidebarItem from '../ui/layout/SidebarItem.vue';
import Navbar from '../ui/layout/Navbar.vue';
import Icon from '../ui/primitives/Icon.vue';
import Avatar from '../ui/primitives/Avatar.vue';
import LocaleSwitcher from '../ui/LocaleSwitcher.vue';
import DropdownMenu from '../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../ui/overlay/DropdownMenuItem.vue';
import DropdownMenuLabel from '../ui/overlay/DropdownMenuLabel.vue';
import DropdownMenuSeparator from '../ui/overlay/DropdownMenuSeparator.vue';
import type { IconName } from '../ui/primitives/icons';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { t } = useI18n();
const { isDark, toggle: toggleTheme } = useTheme();

interface NavLink {
  key: string;
  labelKey: string;
  icon: IconName;
  to?: string;
  comingSoon?: boolean;
}

// Live destinations.
const primaryNav: NavLink[] = [
  { key: 'dashboard', labelKey: 'nav.dashboard', icon: 'layout-dashboard', to: '/dashboard' },
  { key: 'tasks', labelKey: 'nav.tasks', icon: 'list-checks', to: '/tasks' },
];

// IA preview — modules not built yet are shown disabled so the structure is visible.
const upcomingNav: NavLink[] = [
  { key: 'forms', labelKey: 'nav.forms', icon: 'file-text', comingSoon: true },
  { key: 'approvals', labelKey: 'nav.approvals', icon: 'check-circle', comingSoon: true },
  { key: 'labels', labelKey: 'nav.labels', icon: 'hash', comingSoon: true },
];

const pageTitle = computed(() => {
  const titleKey = route.meta.titleKey as string | undefined;
  return titleKey ? t(titleKey) : t('app.name', 'Taskio');
});

const hasMultipleWorkspaces = computed(() => auth.workspaces.length > 1);

async function selectWorkspace(id: string | number): Promise<void> {
  if (String(id) === String(auth.currentWorkspaceId)) return;
  await auth.setCurrentWorkspace(id);
}

async function onLogout(): Promise<void> {
  await auth.logout();
  router.replace('/login');
}
</script>

<template>
  <AppShell>
    <template #sidebar>
      <Sidebar :aria-label="t('app.name', 'Taskio')">
        <template #brand>
          <span
            class="flex h-8 w-8 items-center justify-center rounded-next-md bg-next-primary text-next-primary-foreground"
            aria-hidden="true"
          >
            <Icon name="layout-dashboard" class="text-next-lg" />
          </span>
          <span class="text-next-base font-next-semibold">{{ t('app.name', 'Taskio') }}</span>
        </template>

        <SidebarSection :label="t('nav.sectionWorkspace', 'Workspace')">
          <SidebarItem
            v-for="item in primaryNav"
            :key="item.key"
            :label="t(item.labelKey)"
            :icon="item.icon"
            :to="item.to"
            :active="route.path === item.to"
          />
        </SidebarSection>

        <SidebarSection :label="t('nav.sectionComingSoon', 'Coming soon')">
          <SidebarItem
            v-for="item in upcomingNav"
            :key="item.key"
            :label="t(item.labelKey)"
            :icon="item.icon"
            disabled
            :badge="t('nav.comingSoon', 'Coming soon')"
          />
        </SidebarSection>
      </Sidebar>
    </template>

    <template #navbar="{ openDrawer, drawerOpen }">
      <Navbar>
        <template #leading>
          <button
            type="button"
            class="rounded-next-md p-next-2 text-next-fg hover:bg-next-accent hover:text-next-accent-foreground next-md:hidden"
            :aria-label="t('app.openNavigation', 'Open navigation')"
            :aria-expanded="drawerOpen"
            @click="openDrawer"
          >
            <Icon name="menu" class="text-next-xl" />
          </button>
          <h1 class="truncate text-next-lg font-next-semibold">{{ pageTitle }}</h1>
        </template>

        <template #trailing>
          <LocaleSwitcher />

          <button
            type="button"
            class="rounded-next-md border border-next-border bg-next-bg p-next-2 text-next-fg transition-colors duration-[var(--duration-next-fast)] hover:bg-next-accent hover:text-next-accent-foreground"
            :aria-label="isDark ? t('app.themeToLight', 'Switch to light theme') : t('app.themeToDark', 'Switch to dark theme')"
            @click="toggleTheme"
          >
            <Icon :name="isDark ? 'sun' : 'moon'" class="text-next-lg" />
          </button>

          <!-- User menu: avatar + name → dropdown with workspace switcher + logout. -->
          <DropdownMenu placement="bottom-end" :aria-label="t('userMenu.label', 'Account menu')">
            <template #trigger="{ props: triggerProps }">
              <button
                type="button"
                class="flex items-center gap-next-2 rounded-next-md p-next-1 pr-next-2 text-next-fg hover:bg-next-accent hover:text-next-accent-foreground"
                :aria-label="t('userMenu.open', 'Open account menu')"
                :aria-haspopup="triggerProps['aria-haspopup']"
                :aria-expanded="triggerProps['aria-expanded'] === 'true'"
                :aria-controls="triggerProps['aria-controls']"
              >
                <Avatar :name="auth.userName" :src="auth.user?.avatar" size="sm" />
                <span class="hidden max-w-32 truncate text-next-sm font-next-medium next-sm:inline">
                  {{ auth.userName }}
                </span>
                <Icon name="chevron-down" class="hidden text-next-sm text-next-muted-foreground next-sm:inline" />
              </button>
            </template>

            <DropdownMenuLabel>
              <span class="block text-next-2xs uppercase tracking-next-wide text-next-muted-foreground">
                {{ t('userMenu.signedInAs', 'Signed in as') }}
              </span>
              <span class="block truncate font-next-medium text-next-popover-foreground">
                {{ auth.userName }}
              </span>
              <span v-if="auth.user?.email" class="block truncate text-next-xs text-next-muted-foreground">
                {{ auth.user.email }}
              </span>
            </DropdownMenuLabel>

            <template v-if="hasMultipleWorkspaces">
              <DropdownMenuSeparator />
              <DropdownMenuLabel>{{ t('userMenu.switchWorkspace', 'Switch workspace') }}</DropdownMenuLabel>
              <DropdownMenuItem
                v-for="ws in auth.workspaces"
                :key="ws.id"
                :icon="String(ws.id) === String(auth.currentWorkspaceId) ? 'check' : 'folder'"
                :label="ws.name"
                @select="selectWorkspace(ws.id)"
              >
                {{ ws.name }}
              </DropdownMenuItem>
            </template>

            <DropdownMenuSeparator />
            <DropdownMenuItem icon="log-out" :label="t('userMenu.logout', 'Sign out')" @select="onLogout">
              {{ t('userMenu.logout', 'Sign out') }}
            </DropdownMenuItem>
          </DropdownMenu>
        </template>
      </Navbar>
    </template>

    <!-- Standard page gutter so every authed page has consistent breathing room
         from the shell edges (px + py, responsive). Pages should NOT add their
         own outer page padding — only inner rhythm via gap/Stack.

         `min-h-full flex flex-col` lets a page OPT INTO full height: a normal
         document-flow page (Dashboard) sizes to its content and `<main>` scrolls;
         a full-height page (Tasks) gives its top-level child `flex-1 min-h-0` to
         fill the viewport and scroll its own inner regions instead. The gutter
         padding then becomes the comfortable gap around that full-height content. -->
    <div class="flex min-h-full flex-col px-next-4 py-next-6 next-sm:px-next-6">
      <router-view />
    </div>
  </AppShell>
</template>
