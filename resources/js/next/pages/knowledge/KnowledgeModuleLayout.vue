<script setup lang="ts">
// KnowledgeModuleLayout — the top-level "Knowledge" module shell (next): the shared two-level
// ModuleAside (≥ next-lg) + ModuleTabs (below) around a content area that renders the module's
// pages. Mirrors GeneratorModuleLayout / BotsModuleLayout — zero new navigation patterns.
//
// TWO BLOCKS (B5):
//   • MODULE   — Bases · Search · Trash. Reachable with no base open.
//   • RESOURCE — the open base: Reader · Table · Graph · Settings. The base's identity (name,
//     index badge, red-link chip) renders in the aside, which is why the pages themselves title
//     the SECTION and not the record (ADR-0011).
import { computed, onBeforeUnmount, watch } from 'vue';
import { useRoute } from 'vue-router';
import ModuleAside, { type ModuleNavItem, type ModuleResource } from '../../ui/layout/ModuleAside.vue';
import ModuleTabs from '../../ui/layout/ModuleTabs.vue';
import StatusBadge from '../../ui/data/StatusBadge.vue';
import Badge from '../../ui/primitives/Badge.vue';
import { useKnowledgeStore } from '../../app/stores/knowledge';
import { indexStatusMap } from './statusMaps';
import { baseIndexState } from './baseMeta';
import { useI18n } from '../../app/i18n';
import { setPageContextLabel } from '../../app/lib/pageContext';

const route = useRoute();
const { t } = useI18n();
const store = useKnowledgeStore();

const indexMap = computed(() => indexStatusMap(t));

/** The open base id — any `next.knowledge.base*` route carries it in `:baseId`. */
const baseId = computed(() =>
  String(route.name ?? '').startsWith('next.knowledge.base') && route.params.baseId
    ? String(route.params.baseId)
    : null,
);

/**
 * The open base, held in the store so every section (reader / table / settings) reads ONE copy.
 * Fetched here because the aside must render the base's identity on a cold deep link, before any
 * list has been loaded.
 */
const base = computed(() =>
  baseId.value && store.openBase?.id === baseId.value ? store.openBase : null,
);

watch(
  baseId,
  (id) => {
    if (id && store.openBase?.id !== id) void store.fetchOpenBase(id);
    if (!id) store.clearOpenBase();
  },
  { immediate: true },
);

// The open base's name feeds the Navbar breadcrumb. Cleared in onBeforeUnmount (synchronous, runs
// BEFORE the incoming layout's setup) — onUnmounted is post-flush and would wipe the label the
// next module layout just set.
watch(() => base.value?.name ?? null, (name) => setPageContextLabel(name), { immediate: true });
onBeforeUnmount(() => setPageContextLabel(null));

function sectionLink(section: string) {
  const query = { ...route.query };
  delete query.section;
  return { name: `next.knowledge.base.${section}`, params: { baseId: baseId.value ?? '' }, query };
}

const moduleItems = computed<ModuleNavItem[]>(() => [
  { key: 'bases', label: t('knowledge.module.nav.bases'), icon: 'book-open', to: { name: 'next.knowledge' } },
  { key: 'search', label: t('knowledge.module.nav.search'), icon: 'search', to: { name: 'next.knowledge.search' } },
]);

const resourceItems = computed<ModuleNavItem[]>(() => [
  // FIRST, because after the AI-only pivot this is where an entry begins (spec §25.1 / §2.3).
  { key: 'compose', label: t('knowledge.module.nav.compose'), icon: 'sparkles', to: sectionLink('compose') },
  { key: 'reader', label: t('knowledge.module.nav.reader'), icon: 'book-open', to: sectionLink('reader') },
  { key: 'table', label: t('knowledge.module.nav.table'), icon: 'table', to: sectionLink('table') },
  { key: 'graph', label: t('knowledge.module.nav.graph'), icon: 'network', to: sectionLink('graph') },
  { key: 'settings', label: t('knowledge.module.nav.settings'), icon: 'settings', to: sectionLink('settings') },
]);

/** The selected-base identity block ('…' while a deep-linked base loads). */
const resource = computed<ModuleResource | null>(() =>
  baseId.value
    ? { icon: 'book-open', name: base.value?.name ?? '…', description: base.value?.description ?? null }
    : null,
);

/** The base's aggregate index state + its `{done}/{total}` label (counted in ENTRIES). */
const indexState = computed(() => (base.value ? baseIndexState(base.value, t) : null));

/**
 * Active-item matching. The reader row is matched by more than equality so it stays selected while
 * the user walks entries (`reader/:slug`) AND while the editor is open — the user has not left the
 * base, and a nav that de-selects everything reads as "you are nowhere".
 */
function isItemActive(item: ModuleNavItem): boolean {
  const target = (item.to as { name?: string } | undefined)?.name;
  if (!target) return false;
  const current = String(route.name ?? '');
  if (target === 'next.knowledge.base.reader') {
    // The editor is gone: an entry is written by the composer and read here, so the reader is the
    // only route this tab can be on.
    return current === 'next.knowledge.base.reader';
  }
  return current === target;
}

/** Below next-lg the tabs replace the aside, so they must show whichever block is in play. */
const tabItems = computed<ModuleNavItem[]>(() => (baseId.value ? resourceItems.value : moduleItems.value));
</script>

<template>
  <div class="flex min-h-0 flex-1 gap-next-4">
    <!-- Section nav (≥ next-lg). The module icon bubble is rendered white-on-primary by
         ModuleAside itself — nothing to pass. -->
    <ModuleAside
      module-icon="book-open"
      :module-title="t('knowledge.title')"
      :module-hint="t('knowledge.module.hint')"
      :module-items="moduleItems"
      :resource-items="baseId ? resourceItems : undefined"
      :resource="resource"
      :resource-placeholder="{
        icon: 'book-open',
        label: t('knowledge.module.pickBase'),
        hint: t('knowledge.module.pickBaseHint'),
        to: { name: 'next.knowledge' },
      }"
      :resource-back="{ label: t('knowledge.module.backToList'), to: { name: 'next.knowledge' } }"
      :resource-nav-label="t('knowledge.module.tabs')"
      :active-match="isItemActive"
    >
      <!-- Base health at a glance: the index census, and the red-link chip ONLY when there is
           something to act on. A permanent "0 red links" is noise; a number that appears is a
           signal. -->
      <template #resource-meta>
        <div v-if="base" class="flex flex-wrap items-center gap-next-1_5" aria-live="polite">
          <StatusBadge
            v-if="indexState"
            :status="indexState.status"
            :status-map="indexMap"
            :label="indexState.label"
            size="sm"
          />
          <Badge v-if="base.ghost_links_count > 0" variant="danger" tone="subtle" icon="unlink" size="sm">
            {{ t('knowledge.ghosts.count', '', { count: base.ghost_links_count }) }}
          </Badge>
        </div>
      </template>
    </ModuleAside>

    <!-- Content: the small-screen section tabs + the page. -->
    <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-next-4 overflow-y-auto">
      <ModuleTabs :items="tabItems" :active-match="isItemActive" :aria-label="t('knowledge.module.tabs')" />
      <RouterView />
    </div>
  </div>
</template>
