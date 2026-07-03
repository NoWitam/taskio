<script setup lang="ts">
// BotsModuleLayout — the Bots (AI Character) module shell (next, Batch 1): a LEFT
// inner sub-nav + a content area that renders the module's pages (the list + the
// per-bot detail), and it HOSTS the query-driven editor DRAWER (`?bot=new` ·
// `?bot=<id>`), the same pattern as the Approvals / Forms module layouts.
//
// The list / detail sub-view stays mounted behind the drawer, and the query-sync
// helpers PRESERVE the `?bot` overlay key so a filter change never closes the
// editor. A single "Bots" sub-nav item is enough for now; the structure is ready
// for future sub-views.
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Surface from '../../ui/layout/Surface.vue';
import Icon, { type IconName } from '../../ui/primitives/Icon.vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import BotEditorDrawer from './BotEditorDrawer.vue';
import { useI18n } from '../../app/i18n';
import type { BotDetail } from './types';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

interface SubNavItem {
  key: string;
  label: string;
  icon: IconName;
  to: { name: string };
}
const subNav = computed<SubNavItem[]>(() => [
  { key: 'list', label: t('bots.module.allBots'), icon: 'sparkles', to: { name: 'next.bots' } },
]);

function isActive(name?: string): boolean {
  return !!name && route.name === name;
}

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

const editorOpen = computed<boolean>({
  get: () => botParam.value !== null,
  set: (open) => {
    if (!open) dropQuery(['bot']);
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
    <!-- Inner sub-navigation (hidden on narrow screens; content stays usable). -->
    <Surface
      as="aside"
      bg="card"
      border
      elevation="sm"
      radius="lg"
      class="hidden w-64 shrink-0 min-h-0 flex-col overflow-y-auto next-lg:flex"
    >
      <div class="flex items-start gap-next-3 border-b border-next-border p-next-4">
        <span
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-next-lg bg-next-primary text-next-primary-foreground"
          aria-hidden="true"
        >
          <Icon name="sparkles" class="text-next-lg" />
        </span>
        <div class="min-w-0">
          <h2 class="truncate text-next-sm font-next-semibold text-next-fg">
            {{ t('bots.title') }}
          </h2>
          <p class="mt-next-0_5 text-next-xs text-next-muted-foreground">
            {{ t('bots.module.selectHint') }}
          </p>
        </div>
      </div>

      <nav class="flex flex-col gap-next-0_5 p-next-2">
        <RouterLink
          v-for="item in subNav"
          :key="item.key"
          :to="item.to"
          class="flex items-center gap-next-2 rounded-next-md px-next-3 py-next-2 text-next-sm transition-colors"
          :class="isActive(item.to.name)
            ? 'bg-next-primary-subtle text-next-primary-subtle-foreground font-next-medium'
            : 'text-next-fg hover:bg-next-accent hover:text-next-accent-foreground'"
        >
          <Icon :name="item.icon" class="shrink-0" />
          {{ item.label }}
        </RouterLink>
      </nav>
    </Surface>

    <!-- Content: the list (or the per-bot detail). -->
    <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto">
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
