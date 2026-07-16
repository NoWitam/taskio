<script setup lang="ts">
// DashboardView — the first real page of the "next" app.
//
// Built ONLY from the verified auth context (user / permissions / workspaces) —
// no invented backend fields. Stats are counts derived from that context; module
// cards expose the IA (Dashboard is live; the rest are "coming soon"); the Recent
// activity card is an explicit PLACEHOLDER (no endpoint yet) rendered as an
// EmptyState. While the session re-hydrates, the page shows skeletons that mirror
// the real layout (StatsGrid loading + EntityCard loading).
//
// i18n: every visible string via t(); light + dark via tokens.
import { computed } from 'vue';
import { useAuthStore } from '../app/stores/auth';
import { useI18n } from '../app/i18n';
import Container from '../ui/layout/Container.vue';
import Stack from '../ui/layout/Stack.vue';
import Grid from '../ui/layout/Grid.vue';
import Card from '../ui/layout/Card.vue';
import PageHeader from '../ui/patterns/PageHeader.vue';
import StatsGrid from '../ui/patterns/StatsGrid.vue';
import StatCard from '../ui/patterns/StatCard.vue';
import EntityCard from '../ui/patterns/EntityCard.vue';
import EmptyState from '../ui/data/EmptyState.vue';
import Badge from '../ui/primitives/Badge.vue';
import Icon from '../ui/primitives/Icon.vue';
import type { IconName } from '../ui/primitives/icons';

const auth = useAuthStore();
const { t } = useI18n();

// Loading == the very first hydration on a hard refresh (token present, /me pending).
const loading = computed(() => !auth.ready);

const welcome = computed(() =>
  auth.userName
    ? t('dashboard.welcome', 'Welcome back, {name}', { name: auth.userName })
    : t('dashboard.welcomeAnon', 'Welcome back'),
);

interface ModuleCard {
  key: string;
  titleKey: string;
  descKey: string;
  icon: IconName;
  to?: string;
}

// Module count drives a stat; the same list renders the navigation cards below.
// `to` marks a LIVE module (routed in the next app); a card without `to` is a
// genuine "coming soon" placeholder. Keep this list in step with the router +
// the AppLayout nav so the Dashboard never advertises a live module as pending.
const modules: ModuleCard[] = [
  { key: 'dashboard', titleKey: 'nav.dashboard', descKey: 'dashboard.moduleDashboardDesc', icon: 'layout-dashboard', to: '/dashboard' },
  { key: 'tasks', titleKey: 'nav.tasks', descKey: 'dashboard.moduleTasksDesc', icon: 'list-checks', to: '/tasks' },
  { key: 'forms', titleKey: 'nav.forms', descKey: 'dashboard.moduleFormsDesc', icon: 'file-text', to: '/forms' },
  { key: 'approvals', titleKey: 'nav.approvals', descKey: 'dashboard.moduleApprovalsDesc', icon: 'check-circle', to: '/approvals' },
  { key: 'workflows', titleKey: 'nav.workflows', descKey: 'dashboard.moduleWorkflowsDesc', icon: 'workflow', to: '/workflows' },
  { key: 'bots', titleKey: 'nav.bots', descKey: 'dashboard.moduleBotsDesc', icon: 'sparkles', to: '/bots' },
  { key: 'labels', titleKey: 'nav.labels', descKey: 'dashboard.moduleLabelsDesc', icon: 'hash' },
];

const workspaceCount = computed(() => auth.workspaces.length);
const permissionCount = computed(() => auth.permissions.length);
const moduleCount = computed(() => modules.length);
</script>

<template>
  <Container size="xl" as="section" flush>
    <Stack gap="6">
      <PageHeader
        :title="t('dashboard.title', 'Dashboard')"
        icon="layout-dashboard"
      >
        <template #description>
          <span class="font-next-medium text-next-fg">{{ welcome }}</span>
          — {{ t('dashboard.subtitle', 'Here’s an overview of your workspace.') }}
        </template>
      </PageHeader>

      <!-- Stats: counts derived ONLY from the real auth context. -->
      <section :aria-label="t('dashboard.statsLabel', 'Workspace overview')">
        <StatsGrid :cols="3" :loading="loading" :count="3">
          <StatCard
            icon="folder"
            :label="t('dashboard.statWorkspaces', 'Workspaces')"
            :value="workspaceCount"
            :helper="t('dashboard.statWorkspacesHelper', 'Workspaces you can access')"
          />
          <StatCard
            icon="lock-open"
            :label="t('dashboard.statPermissions', 'Permissions')"
            :value="permissionCount"
            :helper="t('dashboard.statPermissionsHelper', 'Granted in this workspace')"
          />
          <StatCard
            icon="layout-dashboard"
            :label="t('dashboard.statModules', 'Modules')"
            :value="moduleCount"
            :helper="t('dashboard.statModulesHelper', 'Available in Taskio')"
          />
        </StatsGrid>
      </section>

      <!-- Module navigation cards. Dashboard is live; the rest are "coming soon". -->
      <section :aria-labelledby="'dashboard-modules-heading'">
        <h2 id="dashboard-modules-heading" class="mb-next-3 text-next-lg font-next-semibold text-next-fg">
          {{ t('dashboard.modulesTitle', 'Explore modules') }}
        </h2>
        <Grid v-if="loading" :cols="{ base: 1, sm: 2, lg: 3 }" gap="4">
          <EntityCard v-for="n in 5" :key="n" loading />
        </Grid>
        <Grid v-else :cols="{ base: 1, sm: 2, lg: 3 }" gap="4">
          <EntityCard
            v-for="m in modules"
            :key="m.key"
            :title="t(m.titleKey)"
            :subtitle="t(m.descKey)"
            :to="m.to"
            :disabled="!m.to"
            :action-label="m.to ? t('dashboard.open', 'Open') + ' ' + t(m.titleKey) : undefined"
          >
            <template #leading>
              <span
                class="flex h-10 w-10 items-center justify-center rounded-next-lg bg-next-muted text-next-muted-foreground"
                aria-hidden="true"
              >
                <Icon :name="m.icon" class="text-next-lg" />
              </span>
            </template>
            <template #status>
              <Badge v-if="!m.to" variant="neutral" size="sm">
                {{ t('dashboard.comingSoon', 'Coming soon') }}
              </Badge>
            </template>
          </EntityCard>
        </Grid>
      </section>

      <!-- Recent activity: explicit placeholder — no endpoint yet. -->
      <section :aria-labelledby="'dashboard-activity-heading'">
        <Card>
          <template #header>
            <div class="flex items-center gap-next-2">
              <h2 id="dashboard-activity-heading" class="text-next-base font-next-semibold text-next-fg">
                {{ t('dashboard.recentActivityTitle', 'Recent activity') }}
              </h2>
              <Badge variant="neutral" size="sm">{{ t('dashboard.placeholderBadge', 'Placeholder') }}</Badge>
            </div>
          </template>
          <EmptyState
            :title="t('dashboard.recentActivityEmptyTitle', 'No activity yet')"
            :description="t('dashboard.recentActivityEmptyDesc', 'Activity will appear here as you work.')"
            icon="clock"
          />
        </Card>
      </section>
    </Stack>
  </Container>
</template>
