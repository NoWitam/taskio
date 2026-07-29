<script setup lang="ts">
// AppLayout — the authenticated app shell for the "next" frontend.
//
// Wraps every authed page with AppShell: a Sidebar (brand + nav, with the IA for
// not-yet-built modules shown as disabled "coming soon" rows) and a Navbar (mobile
// menu button, a breadcrumb trail, and trailing language switcher + theme toggle +
// a user menu with an optional workspace switcher and Sign out). The page renders
// through <router-view>.
//
// The Navbar renders a BREADCRUMB, not an h1 (D5): the page itself owns its
// single h1 (PageHeader). The trail is the module title (from route.meta.titleKey,
// linked to the module root when an entity is open) plus the open entity's name
// from pageContextLabel — a plain reactive ref the module layouts feed, so this
// shell stays free of feature-store imports (it touches only auth + approvalQueue,
// deliberately).
//
// All labels are translated; the active nav item is driven by the router and marked
// with aria-current via SidebarItem. The mobile drawer / focus trap / scrim are
// owned by AppShell.
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore, type Workspace } from '../app/stores/auth';
import { useApprovalQueueStore } from '../app/stores/approvalQueue';
import CreateWorkspaceModal from './workspaces/CreateWorkspaceModal.vue';
import { useI18n } from '../app/i18n';
import { useTheme } from '../app/lib/theme';
import AppShell from '../ui/layout/AppShell.vue';
import Sidebar from '../ui/layout/Sidebar.vue';
import SidebarSection from '../ui/layout/SidebarSection.vue';
import SidebarItem from '../ui/layout/SidebarItem.vue';
import Navbar from '../ui/layout/Navbar.vue';
import Icon from '../ui/primitives/Icon.vue';
import Button from '../ui/primitives/Button.vue';
import Avatar from '../ui/primitives/Avatar.vue';
import Badge from '../ui/primitives/Badge.vue';
import LocaleSwitcher from '../ui/LocaleSwitcher.vue';
import Breadcrumbs, { type BreadcrumbItem } from '../ui/navigation/Breadcrumbs.vue';
import DropdownMenu from '../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../ui/overlay/DropdownMenuItem.vue';
import DropdownMenuLabel from '../ui/overlay/DropdownMenuLabel.vue';
import DropdownMenuSeparator from '../ui/overlay/DropdownMenuSeparator.vue';
import type { IconName } from '../ui/primitives/icons';
import { isPathActive } from '../app/router/isPathActive';
import { pageContextLabel } from '../app/lib/pageContext';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const approvalQueue = useApprovalQueueStore();
const { t } = useI18n();
const { isDark, toggle: toggleTheme } = useTheme();

// Warm the pending-approvals count on app-shell mount so the nav badge is
// accurate even before the user opens the Approvals module. The module layout
// also warms it on its own mount; this app-shell fetch is complementary (the
// store keeps `count` decremented after a decision, so the badge self-updates).
// Best-effort — a failure is silent (the badge simply stays hidden).
onMounted(() => {
  void approvalQueue.fetchCount().catch(() => undefined);
});

/** The pending-approvals badge count (hidden when null / 0). */
const approvalsCount = computed(() => approvalQueue.count);
function showApprovalsBadge(item: NavLink): boolean {
  return item.key === 'approvals' && approvalsCount.value != null && approvalsCount.value > 0;
}

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
  { key: 'disk', labelKey: 'nav.disk', icon: 'folder', to: '/disk' },
  { key: 'forms', labelKey: 'nav.forms', icon: 'file-text', to: '/forms' },
  { key: 'approvals', labelKey: 'nav.approvals', icon: 'git-branch', to: '/approvals' },
  { key: 'workflows', labelKey: 'nav.workflows', icon: 'workflow', to: '/workflows' },
  { key: 'variables', labelKey: 'nav.variables', icon: 'braces', to: '/variables' },
  { key: 'generator', labelKey: 'nav.generator', icon: 'palette', to: '/generator' },
  { key: 'bots', labelKey: 'nav.bots', icon: 'sparkles', to: '/bots' },
];

// IA preview — modules not built yet are shown disabled so the structure is visible.
const upcomingNav: NavLink[] = [
  { key: 'labels', labelKey: 'nav.labels', icon: 'hash', comingSoon: true },
];

const pageTitle = computed(() => {
  const titleKey = route.meta.titleKey as string | undefined;
  return titleKey ? t(titleKey) : t('app.name', 'Taskio');
});

// Navbar breadcrumb trail (D5): the module title, plus the open entity's name
// (from pageContextLabel, fed by the module layouts) as the current crumb. With
// an entity open the module crumb links back to the module root (looked up from
// the sidebar nav by the shared titleKey); without one the module title is the
// current — and only — crumb.
const breadcrumbs = computed<BreadcrumbItem[]>(() => {
  const context = pageContextLabel.value;
  if (!context) return [{ label: pageTitle.value }];
  const titleKey = route.meta.titleKey as string | undefined;
  const moduleTo = primaryNav.find((item) => item.labelKey === titleKey)?.to;
  return [{ label: pageTitle.value, to: moduleTo }, { label: context }];
});

const hasMultipleWorkspaces = computed(() => auth.workspaces.length > 1);

// Owner-gate the "Manage members" entry. `can_manage_members` is only on the
// per-workspace resource (not the auth-context list), but that list DOES carry
// `is_owner`, and only the owner can manage members — so the owner flag is a
// reliable, fetch-free gate here (the page re-checks `can_manage_members` and the
// backend enforces it regardless).
const canManageMembers = computed(() => {
  const ws = auth.currentWorkspace;
  if (!ws) return false;
  return ws.can_manage_members === true || ws.is_owner === true;
});

function goToMembers(): void {
  void router.push({ name: 'next.settings.members' });
}

/**
 * AI-usage meter (R2 sub-stage 4): visible to ANY member of a current workspace — but the page is
 * workspace-scoped, so gate the entry on HAVING one (with no current workspace the page renders blank),
 * mirroring how `canManageMembers` reads `auth.currentWorkspace`.
 */
const canViewAiUsage = computed(() => !!auth.currentWorkspace);

function goToAiUsage(): void {
  void router.push({ name: 'next.settings.aiUsage' });
}

/** A workspace whose own-DB is still being provisioned can't be switched into. */
function isProvisioning(workspace: Workspace): boolean {
  return workspace.status === 'provisioning';
}
/** A workspace whose provisioning failed has no usable DB to switch into. */
function isFailed(workspace: Workspace): boolean {
  return workspace.status === 'failed';
}
/** Disable the switcher row for any workspace that has no usable context. */
function isSwitchDisabled(workspace: Workspace): boolean {
  return !auth.canSwitchInto(workspace);
}

async function selectWorkspace(workspace: Workspace): Promise<void> {
  if (String(workspace.id) === String(auth.currentWorkspaceId)) return;
  // The store also guards this, but skip the no-op (and the disabled UI prevents it).
  if (!auth.canSwitchInto(workspace)) return;
  await auth.setCurrentWorkspace(workspace.id);
}

// --- Create-workspace modal ----------------------------------------------
const createWorkspaceOpen = ref(false);
function openCreateWorkspace(): void {
  createWorkspaceOpen.value = true;
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
            :active="item.to != null && isPathActive(route.path, item.to)"
          >
            <!-- Pending-approvals count badge (icon-less, primary): hidden when
                 the count is null or 0. The accessible label carries the count so
                 the number isn't conveyed as a bare digit to assistive tech. -->
            <template v-if="showApprovalsBadge(item)" #badge>
              <Badge variant="primary" tone="solid" size="sm">
                <span aria-hidden="true">{{ approvalsCount }}</span>
                <span class="sr-only">
                  {{ t('nav.approvalsBadge', '', { count: approvalsCount ?? 0 }) }}
                </span>
              </Badge>
            </template>
          </SidebarItem>
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
          <Button
            variant="ghost"
            size="icon"
            class="next-md:hidden"
            leading-icon="menu"
            :aria-label="t('app.openNavigation', 'Open navigation')"
            :aria-expanded="drawerOpen"
            @click="openDrawer"
          />
          <!-- Breadcrumb, NOT an h1 (D5) — the page owns its single h1 (PageHeader). -->
          <Breadcrumbs :items="breadcrumbs" :aria-label="t('nav.breadcrumbs', 'Breadcrumbs')" />
        </template>

        <template #trailing>
          <LocaleSwitcher />

          <Button
            variant="outline"
            size="icon"
            :leading-icon="isDark ? 'sun' : 'moon'"
            :aria-label="isDark ? t('app.themeToLight', 'Switch to light theme') : t('app.themeToDark', 'Switch to dark theme')"
            @click="toggleTheme"
          />

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

            <DropdownMenuSeparator />
            <template v-if="hasMultipleWorkspaces">
              <DropdownMenuLabel>
                {{ t('userMenu.switchWorkspace', 'Switch workspace') }}
              </DropdownMenuLabel>
              <DropdownMenuItem
                v-for="ws in auth.workspaces"
                :key="ws.id"
                :icon="String(ws.id) === String(auth.currentWorkspaceId) ? 'check' : 'folder'"
                :label="ws.name"
                :disabled="isSwitchDisabled(ws)"
                @select="selectWorkspace(ws)"
              >
                <span class="flex min-w-0 flex-1 items-center gap-next-2">
                  <span class="min-w-0 flex-1 truncate">{{ ws.name }}</span>
                  <!-- A half-provisioned own-DB workspace (or a failed one) can't be
                       entered: show a status badge so the disabled row is understandable
                       (state is never conveyed by the disabled style alone). -->
                  <Badge
                    v-if="isProvisioning(ws)"
                    variant="warning"
                    tone="subtle"
                    size="sm"
                    class="shrink-0"
                  >
                    {{ t('workspaces.status.provisioning', 'Provisioning') }}
                  </Badge>
                  <Badge
                    v-else-if="isFailed(ws)"
                    variant="danger"
                    tone="subtle"
                    size="sm"
                    class="shrink-0"
                  >
                    {{ t('workspaces.status.failed', 'Failed') }}
                  </Badge>
                </span>
              </DropdownMenuItem>
            </template>

            <!-- Manage members: owner-only (the page + backend re-check). -->
            <DropdownMenuItem
              v-if="canManageMembers"
              icon="users"
              :label="t('userMenu.manageMembers', 'Manage members')"
              @select="goToMembers"
            >
              {{ t('userMenu.manageMembers', 'Manage members') }}
            </DropdownMenuItem>

            <!-- AI usage: any member (the meter is read-only for members; the cap editor is owner-gated in-page).
                 Gated on a current workspace — the page is workspace-scoped and renders blank without one. -->
            <DropdownMenuItem
              v-if="canViewAiUsage"
              icon="wallet"
              :label="t('userMenu.aiUsage', 'AI usage')"
              @select="goToAiUsage"
            >
              {{ t('userMenu.aiUsage', 'AI usage') }}
            </DropdownMenuItem>

            <DropdownMenuItem
              icon="plus"
              :label="t('userMenu.createWorkspace', 'Create workspace')"
              @select="openCreateWorkspace"
            >
              {{ t('userMenu.createWorkspace', 'Create workspace') }}
            </DropdownMenuItem>

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

  <!-- Create-workspace flow (opened from the user-menu workspace switcher). Owns
       its own create + provisioning-poll state; on success it switches the active
       workspace and routes to the dashboard. -->
  <CreateWorkspaceModal v-model:open="createWorkspaceOpen" />
</template>
