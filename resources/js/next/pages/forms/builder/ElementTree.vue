<script setup lang="ts">
// ElementTree — the form's STRUCTURE outline (next), shown in the right column.
//
// A recursive, indented, read-only-ish list mirroring the element hierarchy.
// Its job is navigation + overview: click a row to SELECT that element (which
// opens its editor). Reorder / duplicate / delete live on the canvas cards, so
// the tree stays uncluttered (no cramped inline controls). Self-recursive.
import { childrenOf, iconOf } from './elements';
import type { FormElement } from '../types';
import Icon from '../../../ui/primitives/Icon.vue';
import { useI18n } from '../../../app/i18n';

withDefaults(
  defineProps<{
    elements: FormElement[];
    selectedId: string | null;
    level?: number;
  }>(),
  { level: 0 },
);

const emit = defineEmits<{ (e: 'select', id: string): void }>();

const { t } = useI18n();

function rowLabel(element: FormElement): string {
  const c = element.config;
  return c.name || c.label || c.text || t(`forms.elementTypes.${element.type}`);
}
</script>

<template>
  <ul class="flex flex-col gap-next-0_5">
    <li v-for="element in elements" :key="element.id" class="flex flex-col">
      <button
        type="button"
        class="flex items-center gap-next-2 rounded-next-md px-next-2 py-next-1_5 text-left text-next-sm outline-none transition-colors"
        :class="
          selectedId === element.id
            ? 'bg-next-primary-subtle text-next-primary-subtle-foreground'
            : 'text-next-fg hover:bg-next-accent hover:text-next-accent-foreground'
        "
        :style="{ paddingLeft: `${level * 12 + 8}px` }"
        @click="emit('select', element.id)"
      >
        <Icon :name="iconOf(element.type)" class="shrink-0 text-next-muted-foreground" />
        <span class="min-w-0 flex-1 truncate">{{ rowLabel(element) }}</span>
        <span v-if="childrenOf(element).length" class="shrink-0 text-next-2xs text-next-muted-foreground">
          {{ childrenOf(element).length }}
        </span>
      </button>

      <ElementTree
        v-if="childrenOf(element).length"
        :elements="childrenOf(element)"
        :selected-id="selectedId"
        :level="(level ?? 0) + 1"
        @select="emit('select', $event)"
      />
    </li>
  </ul>
</template>
