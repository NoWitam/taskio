<script setup lang="ts">
// ElementPalette — the "add element" palette for the form builder (next).
//
// Grouped by category (layout / content / input). Each item is DRAGGABLE — drag
// it onto the canvas and drop it at the position you want (legacy behaviour) —
// and also CLICKABLE as a shortcut (adds into the selected container, else root).
// Shares the injected BuilderContext for the drag state. All labels via i18n.
import { inject } from 'vue';
import { PALETTE, type FormElementCategory } from './elements';
import { BUILDER_CTX } from './dnd';
import type { FormElementType } from '../types';
import Icon from '../../../ui/primitives/Icon.vue';
import { useI18n } from '../../../app/i18n';

defineProps<{
  /** When set, the container the click-shortcut adds INTO (shown as a hint). */
  targetLabel?: string;
}>();

const emit = defineEmits<{ (e: 'add', type: FormElementType): void }>();

const { t } = useI18n();
const ctx = inject(BUILDER_CTX);

const categoryLabel: Record<FormElementCategory, string> = {
  layout: 'forms.categories.layout',
  content: 'forms.categories.content',
  input: 'forms.categories.input',
};

function onDragStart(type: FormElementType, event: DragEvent): void {
  ctx?.startNew(type);
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'copy';
}
</script>

<template>
  <div class="flex flex-col gap-next-5">
    <p v-if="targetLabel" class="text-next-xs text-next-muted-foreground">
      {{ t('forms.builder.addingInto', '', { name: targetLabel }) }}
    </p>

    <div v-for="group in PALETTE" :key="group.category" class="flex flex-col gap-next-2">
      <h4 class="text-next-2xs font-next-semibold uppercase tracking-next-wide text-next-muted-foreground">
        {{ t(categoryLabel[group.category]) }}
      </h4>
      <div class="flex flex-col gap-next-1">
        <button
          v-for="item in group.items"
          :key="item.type"
          type="button"
          draggable="true"
          class="flex w-full cursor-grab items-center gap-next-2 rounded-next-md border border-transparent px-next-2 py-next-1_5 text-left text-next-sm text-next-fg transition-colors hover:border-next-border hover:bg-next-accent hover:text-next-accent-foreground active:cursor-grabbing"
          @click="emit('add', item.type)"
          @dragstart="onDragStart(item.type, $event)"
          @dragend="ctx?.endDnd()"
        >
          <Icon :name="item.icon" class="shrink-0 text-next-muted-foreground" />
          <span class="truncate">{{ t(item.labelKey) }}</span>
        </button>
      </div>
    </div>
  </div>
</template>
