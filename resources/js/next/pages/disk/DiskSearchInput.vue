<script setup lang="ts">
// DiskSearchInput — the disk's search field with its two "modes" living INSIDE the input:
//   • WHERE  — this folder | this folder + subfolders | the whole disk
//   • SCOPE  — search name only | name + description
// A non-default mode highlights (primary) on its trailing button and also surfaces as a filter-bar
// chip (the parent renders those). The text is debounced (300ms); the mode pickers apply at once.
import { onBeforeUnmount, ref, watch } from 'vue';
import Icon, { type IconName } from '../../ui/primitives/Icon.vue';
import Button from '../../ui/primitives/Button.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import { useI18n } from '../../app/i18n';
import type { DiskSearchWhere } from './types';

const model = defineModel<string>({ default: '' });
const where = defineModel<DiskSearchWhere>('where', { default: 'folder' });
const searchIn = defineModel<'name' | 'name_description'>('searchIn', { default: 'name' });

const { t } = useI18n();

const WHERE_ICON: Record<DiskSearchWhere, IconName> = {
  folder: 'folder',
  subtree: 'git-branch',
  everywhere: 'layout-dashboard',
};
const WHERE_MODES: DiskSearchWhere[] = ['folder', 'subtree', 'everywhere'];

// Local mirror + debounce so every keystroke doesn't refetch.
const local = ref(model.value);
let timer: ReturnType<typeof setTimeout> | null = null;
watch(model, (v) => {
  if (v !== local.value) local.value = v; // external reset (clear-all / saved view)
});
function commit(): void {
  if (timer) clearTimeout(timer);
  timer = null;
  if (model.value !== local.value) model.value = local.value;
}
function onInput(): void {
  if (timer) clearTimeout(timer);
  timer = setTimeout(commit, 300);
}
function clear(): void {
  local.value = '';
  commit();
}
onBeforeUnmount(() => {
  if (timer) clearTimeout(timer);
});
</script>

<template>
  <div class="flex min-w-56 items-center gap-next-1 rounded-next-md border border-next-border bg-next-card pl-next-2 pr-next-1 transition-colors focus-within:border-next-ring/60 focus-within:ring-2 focus-within:ring-next-ring">
    <Icon name="search" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
    <input
      v-model="local"
      type="search"
      class="min-w-0 flex-1 bg-transparent py-next-1_5 text-next-sm text-next-fg outline-none placeholder:text-next-muted-foreground"
      :placeholder="t('disk.filters.searchPlaceholder', 'Search…')"
      :aria-label="t('disk.filters.search', 'Search')"
      @input="onInput"
      @keydown.enter="commit"
    />
    <Button
      v-if="local"
      variant="ghost"
      size="icon-xs"
      class="shrink-0 text-next-muted-foreground"
      :aria-label="t('common.clear', 'Clear')"
      @click="clear"
    >
      <Icon name="x" aria-hidden="true" />
    </Button>

    <span class="h-4 w-px shrink-0 bg-next-border" aria-hidden="true" />

    <!-- WHERE picker (inside the input). -->
    <DropdownMenu placement="bottom-end" :aria-label="t('disk.filters.whereLabel', 'Where to search')">
      <template #trigger="{ props }">
        <Button
          v-bind="props"
          variant="ghost"
          size="icon-sm"
          class="shrink-0"
          :class="where !== 'folder' ? 'text-next-primary' : 'text-next-muted-foreground'"
          :aria-label="t('disk.filters.whereLabel', 'Where to search')"
        >
          <Icon :name="WHERE_ICON[where]" aria-hidden="true" />
        </Button>
      </template>
      <DropdownMenuItem
        v-for="w in WHERE_MODES"
        :key="w"
        :icon="WHERE_ICON[w]"
        :label="t(`disk.filters.where.${w}`)"
        @select="where = w"
      >
        {{ t(`disk.filters.where.${w}`) }}
      </DropdownMenuItem>
    </DropdownMenu>

    <!-- SCOPE picker (name vs name + description). -->
    <DropdownMenu placement="bottom-end" :aria-label="t('disk.filters.scopeLabel', 'Search in')">
      <template #trigger="{ props }">
        <Button
          v-bind="props"
          variant="ghost"
          size="icon-sm"
          class="shrink-0"
          :class="searchIn !== 'name' ? 'text-next-primary' : 'text-next-muted-foreground'"
          :aria-label="t('disk.filters.scopeLabel', 'Search in')"
        >
          <Icon :name="searchIn === 'name' ? 'type' : 'file-text'" aria-hidden="true" />
        </Button>
      </template>
      <DropdownMenuItem icon="type" :label="t('disk.filters.scope.name', 'Name')" @select="searchIn = 'name'">
        {{ t('disk.filters.scope.name', 'Name') }}
      </DropdownMenuItem>
      <DropdownMenuItem
        icon="file-text"
        :label="t('disk.filters.scope.name_description', 'Name & description')"
        @select="searchIn = 'name_description'"
      >
        {{ t('disk.filters.scope.name_description', 'Name & description') }}
      </DropdownMenuItem>
    </DropdownMenu>
  </div>
</template>
