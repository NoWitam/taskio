<script setup lang="ts">
// VariablesModuleLayout — the top-level "Variables" (PL "Zmienne") module shell (next):
// the shared ModuleAside (≥ next-lg) + ModuleTabs (below) section nav around a content
// area that renders the module's pages. Mirrors ApprovalsModuleLayout — a module with NO
// resource-scoped pages, just its module-level nav.
//
// It owns TWO pages — Consts (PL "Stałe"), the workspace-level typed literal constants
// (formerly "globals"; the runtime wire root stays `globals`), and Functions (PL "Funkcje"),
// user-authored variable transforms surfaced as `fn:<uuid>` ops in every workflow pipeline.
// Each page owns its own editor drawer, so this shell hosts no overlays.
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import ModuleAside, { type ModuleNavItem } from '../../ui/layout/ModuleAside.vue';
import ModuleTabs from '../../ui/layout/ModuleTabs.vue';
import { useI18n } from '../../app/i18n';

const route = useRoute();
const { t } = useI18n();

// Module-level pages (no resource). Consts leads, then Functions.
const moduleItems = computed<ModuleNavItem[]>(() => [
  { key: 'consts', label: t('variables.module.consts'), icon: 'braces', to: { name: 'next.variables.consts' } },
  { key: 'functions', label: t('variables.module.functions'), icon: 'code', to: { name: 'next.variables.functions' } },
]);

function isItemActive(item: ModuleNavItem): boolean {
  return route.name === (item.to as { name?: string } | undefined)?.name;
}
</script>

<template>
  <div class="flex min-h-0 flex-1 gap-next-4">
    <!-- Section nav (≥ next-lg): module block + its pages (Consts today). -->
    <ModuleAside
      module-icon="braces"
      :module-title="t('variables.title')"
      :module-hint="t('variables.module.selectHint')"
      :module-items="moduleItems"
      :active-match="isItemActive"
    />

    <!-- Content: the small-screen section tabs + the page. -->
    <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-next-4 overflow-y-auto">
      <ModuleTabs :items="moduleItems" :active-match="isItemActive" />
      <RouterView />
    </div>
  </div>
</template>
