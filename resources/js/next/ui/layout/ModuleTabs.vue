<script setup lang="ts">
// ModuleTabs — the small-screen fallback for ModuleAside (Batch 4+6, D2=B): the
// SAME section items as the module aside, rendered as an underline Tabs row and
// synced to the route. Visible only below `next-lg` (the aside takes over
// above); module layouts render it ABOVE their <RouterView> — deliberately NOT
// through PageHeader's #tabs slot, so the section nav belongs to the layout
// chrome rather than to any one page.
//
// Selecting a tab pushes the item's route; the active tab is derived from the
// route via the host's `activeMatch` (same closure the aside uses). Underline
// tabs = NAVIGATION per D3 (solid pills stay reserved for data-scope filters).
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import Tabs, { type TabItem } from '../navigation/Tabs.vue';
import { useI18n } from '../../app/i18n';
import type { ModuleNavItem } from './ModuleAside.vue';

const props = defineProps<{
  items: ModuleNavItem[];
  /** Decides the active tab (same closure the module aside uses). */
  activeMatch: (item: ModuleNavItem) => boolean;
  /** Accessible label for the tablist; defaults to `common.moduleNav`. */
  ariaLabel?: string;
}>();

const router = useRouter();
const { t } = useI18n();

const tabItems = computed<TabItem[]>(() =>
  props.items.map((i) => ({ value: i.key, label: i.label, icon: i.icon, disabled: i.soon })),
);

// Route → active tab via activeMatch; tab click → route push.
const active = computed<string | null>({
  get: () => props.items.find((i) => props.activeMatch(i))?.key ?? null,
  set: (key) => {
    const item = props.items.find((i) => i.key === key);
    if (item?.to && !item.soon) void router.push(item.to);
  },
});
</script>

<template>
  <!-- A single-item nav has nothing to switch — render nothing (e.g. a module
       list whose aside shows just the one "All …" row). -->
  <div v-if="items.length > 1" class="next-lg:hidden">
    <Tabs
      v-model="active"
      :items="tabItems"
      variant="underline"
      size="sm"
      :aria-label="ariaLabel ?? t('common.moduleNav')"
    />
  </div>
</template>
