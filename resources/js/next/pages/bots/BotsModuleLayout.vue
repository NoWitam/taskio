<script setup lang="ts">
// BotsModuleLayout — the Bots (AI Character) module shell (next): the shared
// two-level ModuleAside (≥ next-lg) + ModuleTabs (below) around a content area
// that renders the module's pages (the list + the per-bot detail), and it HOSTS
// the query-driven editor DRAWER (`?bot=new` · `?bot=<id>`), the same pattern
// as the Approvals / Forms module layouts.
//
// The aside carries the module block (+ "All bots") and the bot RESOURCE
// section: a pick-a-bot placeholder linking to the list when nothing is open,
// or the selected bot's identity (icon + name + StatusBadge + description)
// above the section nav (Inbox / Activity / Configuration) once a
// `next.bots.detail.*` child route is active. The page's PageHeader describes
// the PAGE — identity lives here. The layout watches the route id and
// prefetches the detail so the identity block can render immediately.
import { computed, onBeforeUnmount, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ModuleAside, { type ModuleNavItem, type ModuleResource } from '../../ui/layout/ModuleAside.vue';
import ModuleTabs from '../../ui/layout/ModuleTabs.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import BotEditorDrawer from './BotEditorDrawer.vue';
import { botStatusMap } from '../../ui/data/botStatus';
import { useBotsStore } from '../../app/stores/bots';
import { useI18n } from '../../app/i18n';
import { setPageContextLabel } from '../../app/lib/pageContext';
import type { IconName } from '../../ui/primitives/icons';
import type { BotDetail } from './types';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const store = useBotsStore();
const statusMap = computed(() => botStatusMap(t));

// --- Section nav (route-name driven) ---------------------------------------
// A bot is open on any `next.bots.detail*` route (the bare redirect record + the
// section children), so prefix-match the route NAME.
const botId = computed(() =>
  String(route.name ?? '').startsWith('next.bots.detail') && route.params.id
    ? String(route.params.id)
    : null,
);
// The active section is the child route name's suffix (default: inbox).
const currentSection = computed(() => {
  const name = String(route.name ?? '');
  const prefix = 'next.bots.detail.';
  return name.startsWith(prefix) ? name.slice(prefix.length) : 'inbox';
});

const activeBot = computed(() => (botId.value && store.detail?.id === botId.value ? store.detail : null));

// Ensure the open bot is loaded so the aside identity block can render.
watch(
  botId,
  (id) => {
    if (id && store.detail?.id !== id) void store.fetchBot(id);
  },
  { immediate: true },
);

// The open bot's name feeds the Navbar breadcrumb (Batch 2 consumes it).
// Clear in onBeforeUnmount (synchronous, runs BEFORE the incoming layout's
// setup) — onUnmounted is post-flush and would wipe the label the next module
// layout just set for its cached detail.
watch(
  () => (botId.value && store.detail?.id === botId.value ? store.detail.name : null),
  (name) => setPageContextLabel(name),
  { immediate: true },
);
onBeforeUnmount(() => setPageContextLabel(null));

function sectionLink(section: string) {
  // Preserve the query (overlay keys) but never a legacy `section` key — the
  // child route name carries the section now.
  const query = { ...route.query };
  delete query.section;
  return { name: 'next.bots.detail.' + section, params: { id: botId.value ?? '' }, query };
}

const moduleItems = computed<ModuleNavItem[]>(() => [
  { key: 'list', label: t('bots.module.allBots'), icon: 'sparkles', to: { name: 'next.bots' } },
]);
const resourceItems = computed<ModuleNavItem[]>(() => [
  { key: 'inbox', label: t('bots.detail.tabInbox'), icon: 'inbox', to: sectionLink('inbox') },
  { key: 'activity', label: t('bots.detail.tabActivity'), icon: 'clock', to: sectionLink('activity') },
  { key: 'config', label: t('bots.detail.tabConfig'), icon: 'settings', to: sectionLink('config') },
]);

// The selected-bot identity block ('…' while the deep-linked detail loads).
const resource = computed<ModuleResource | null>(() =>
  botId.value
    ? {
        icon: ((activeBot.value?.icon as IconName) || 'sparkles') as IconName,
        name: activeBot.value?.name ?? '…',
        description: activeBot.value?.description ?? null,
      }
    : null,
);

function isItemActive(item: ModuleNavItem): boolean {
  if (item.key === 'list') return route.name === 'next.bots';
  return botId.value !== null && currentSection.value === item.key;
}

// Small screens show ONE tab row: the resource sections when a bot is open,
// otherwise the module pages.
const tabItems = computed<ModuleNavItem[]>(() => (botId.value ? resourceItems.value : moduleItems.value));

// --- Editor DRAWER (query-driven overlay) ---------------------------------
// `?bot=new` → new-bot editor · `?bot=<id>` → edit editor.
const str = (v: unknown): string => (Array.isArray(v) ? String(v[0] ?? '') : String(v ?? ''));
const botParam = computed<string | null>(() => str(route.query.bot) || null);
const isNew = computed(() => botParam.value === 'new');
const editId = computed(() => (botParam.value && !isNew.value ? botParam.value : null));

function dropQuery(keys: string[]): void {
  const query = { ...route.query };
  keys.forEach((k) => delete query[k]);
  void router.replace({ query });
}

// `botModule` is the editor's DEEP-LINK companion (`?bot=<id>&botModule=visual`, used e.g. from a
// generator session's "bot appearance" action). It is dropped WITH the editor so re-opening the drawer
// from the list lands on the default module instead of a module the user never asked for.
const editorOpen = computed<boolean>({
  get: () => botParam.value !== null,
  set: (open) => {
    if (!open) dropQuery(['bot', 'botModule']);
  },
});

function onEditorSaved(_bot: BotDetail): void {
  // The store already reconciled the list (prepend on create, replace on update);
  // just close the drawer.
  editorOpen.value = false;
}
</script>

<template>
  <div class="flex min-h-0 flex-1 gap-next-4">
    <!-- Two-level section nav (≥ next-lg): module block + bot resource section. -->
    <ModuleAside
      module-icon="sparkles"
      :module-title="t('bots.title')"
      :module-hint="t('bots.module.selectHint')"
      :module-items="moduleItems"
      :resource-items="resourceItems"
      :resource="resource"
      :resource-placeholder="{
        icon: 'sparkles',
        label: t('bots.module.placeholderLabel'),
        hint: t('bots.module.placeholderHint'),
        to: { name: 'next.bots' },
      }"
      :resource-back="{ label: t('bots.module.allBots'), to: { name: 'next.bots' } }"
      :resource-nav-label="t('bots.module.resourceNav')"
      :active-match="isItemActive"
    >
      <template #resource-meta>
        <StatusBadge
          v-if="activeBot"
          :status="activeBot.status"
          :status-map="statusMap"
          size="sm"
        />
      </template>
    </ModuleAside>

    <!-- Content: the small-screen section tabs + the list (or the per-bot detail). -->
    <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-next-4 overflow-y-auto">
      <ModuleTabs
        :items="tabItems"
        :active-match="isItemActive"
        :aria-label="botId ? t('bots.module.resourceNav') : undefined"
      />
      <RouterView />
    </div>

    <!-- Bot editor (a large drawer; the editor owns its own header + Save/Cancel,
         so the drawer adds no chrome). -->
    <Drawer
      v-model:open="editorOpen"
      side="right"
      size="cover"
      :scroll-body="false"
      :show-close="false"
      :aria-label="editId ? t('bots.editor.editTitle') : t('bots.editor.createTitle')"
    >
      <BotEditorDrawer
        :key="editId ?? 'new'"
        :bot-id="editId"
        @close="editorOpen = false"
        @saved="onEditorSaved"
      />
    </Drawer>
  </div>
</template>
