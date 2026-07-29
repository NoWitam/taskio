<script setup lang="ts">
// GeneratorModuleLayout — the top-level "Generator" module shell (next): the shared two-level
// ModuleAside (≥ next-lg) + ModuleTabs (below) around a content area that renders the module's pages.
//
// Module pages: Templates (PL "Szablony") — the reusable content recipes — and Sessions (PL "Sesje") —
// generation runs of a recipe into finished content (R2 sub-stage 2b). Opening a session (the
// `next.generator.sessions.detail` route) promotes it to the aside's SELECTED RESOURCE block (icon +
// name + StatusBadge + back-to-list) above a one-item chat nav, mirroring how Forms / Workflows show an
// open resource. The layout PREFETCHES the open session into the store so the aside identity + the chat
// page render immediately.
import { computed, onBeforeUnmount, watch } from 'vue';
import { useRoute } from 'vue-router';
import ModuleAside, { type ModuleNavItem, type ModuleResource } from '../../ui/layout/ModuleAside.vue';
import ModuleTabs from '../../ui/layout/ModuleTabs.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import { sessionStatusMap } from './session/sessionStatus';
import { contentTypeIcon, contentTypeLabel } from './templateMeta';
import { useSessionsStore } from '../../app/stores/sessions';
import { useI18n } from '../../app/i18n';
import { setPageContextLabel } from '../../app/lib/pageContext';

const route = useRoute();
const { t } = useI18n();
const store = useSessionsStore();
const statusMap = computed(() => sessionStatusMap(t));

// A session is open on the detail route (`next.generator.sessions.detail`).
const sessionId = computed(() =>
  route.name === 'next.generator.sessions.detail' && route.params.id ? String(route.params.id) : null,
);
const activeSession = computed(() =>
  sessionId.value && store.detail?.id === sessionId.value ? store.detail : null,
);

// Prefetch the open session so the aside identity block can render immediately.
watch(
  sessionId,
  (id) => {
    if (id && store.detail?.id !== id) void store.fetchSession(id).catch(() => undefined);
  },
  { immediate: true },
);

// The open session's name feeds the Navbar breadcrumb. Clear in onBeforeUnmount (synchronous, before
// the incoming layout's setup) so it doesn't wipe a label the next module layout just set.
watch(
  () => (sessionId.value && store.detail?.id === sessionId.value ? store.detail.name : null),
  (name) => setPageContextLabel(name),
  { immediate: true },
);
onBeforeUnmount(() => setPageContextLabel(null));

// Module-level pages (no resource): Templates + Sessions.
const moduleItems = computed<ModuleNavItem[]>(() => [
  { key: 'templates', label: t('generator.module.templates'), icon: 'file-text', to: { name: 'next.generator.templates' } },
  { key: 'sessions', label: t('generator.module.sessions'), icon: 'sparkles', to: { name: 'next.generator.sessions' } },
]);

// The open session's single "chat" resource nav item (satisfies the aside's selected-resource block).
const resourceItems = computed<ModuleNavItem[]>(() => {
  const id = sessionId.value;
  return [
    {
      key: 'chat',
      label: t('generator.module.sessionChat'),
      icon: 'sparkles',
      ...(id ? { to: { name: 'next.generator.sessions.detail', params: { id } } } : {}),
    },
  ];
});

// The selected-session identity block ('…' while a deep-linked session loads).
const resource = computed<ModuleResource | null>(() =>
  sessionId.value
    ? {
        icon: contentTypeIcon(activeSession.value?.content_type ?? 'post'),
        name: activeSession.value?.name ?? '…',
        description: activeSession.value ? contentTypeLabel(activeSession.value.content_type, t) : null,
      }
    : null,
);

function isItemActive(item: ModuleNavItem): boolean {
  if (item.key === 'chat') return sessionId.value !== null;
  return route.name === (item.to as { name?: string } | undefined)?.name;
}

// Small screens show ONE tab row: the resource chat when a session is open, else the module pages.
const tabItems = computed<ModuleNavItem[]>(() => (sessionId.value ? resourceItems.value : moduleItems.value));
</script>

<template>
  <div class="flex min-h-0 flex-1 gap-next-4">
    <!-- Two-level section nav (≥ next-lg): module block + the open-session resource section. -->
    <ModuleAside
      module-icon="sparkles"
      :module-title="t('generator.title')"
      :module-hint="t('generator.module.selectHint')"
      :module-items="moduleItems"
      :resource-items="resourceItems"
      :resource="resource"
      :resource-placeholder="{
        icon: 'sparkles',
        label: t('generator.module.placeholderLabel'),
        hint: t('generator.module.placeholderHint'),
        to: { name: 'next.generator.sessions' },
      }"
      :resource-back="{ label: t('generator.module.sessions'), to: { name: 'next.generator.sessions' } }"
      :resource-nav-label="t('generator.module.resourceNav')"
      :active-match="isItemActive"
    >
      <template #resource-meta>
        <StatusBadge
          v-if="activeSession"
          :status="activeSession.status"
          :status-map="statusMap"
          size="sm"
        />
      </template>
    </ModuleAside>

    <!-- Content: the small-screen section tabs + the page. -->
    <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-next-4 overflow-y-auto">
      <ModuleTabs
        :items="tabItems"
        :active-match="isItemActive"
        :aria-label="sessionId ? t('generator.module.resourceNav') : undefined"
      />
      <RouterView />
    </div>
  </div>
</template>
