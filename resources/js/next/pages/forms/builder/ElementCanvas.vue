<script setup lang="ts">
// ElementCanvas — the builder's CENTER editing surface (next), RECURSIVE.
//
// Renders one element list as a column of cards separated by DROP ZONES. You
// drag an element type from the palette and drop it at the exact position you
// want (root, inside a section/repeater, or into a grid column), and you can
// drag an existing card to reorder it within its list — mirroring the legacy
// builder. Each card shows the real field (leaf inputs via InputRenderer) or the
// container chrome (sections/repeaters recurse into a nested ElementCanvas; grids
// render their columns). Click a card to select it (the left panel becomes its
// editor). Self-recursive (by filename); shares the injected BuilderContext.
import { computed, inject, reactive, ref } from 'vue';
import InputRenderer from '../InputRenderer.vue';
import EmptyState from '../../../ui/data/EmptyState.vue';
import Button from '../../../ui/primitives/Button.vue';
import Icon from '../../../ui/primitives/Icon.vue';
import { childrenOf, iconOf, isInputType } from './elements';
import { BUILDER_CTX } from './dnd';
import type { FormElement, FormElementType } from '../types';
import { useI18n } from '../../../app/i18n';

const props = withDefaults(
  defineProps<{
    /** The list this canvas renders (a reference INTO the tree — mutated in place). */
    elements: FormElement[];
    /** root = accept any type; container = accept any EXCEPT section (no nesting). */
    accept?: 'root' | 'container';
  }>(),
  { accept: 'root' },
);

const emit = defineEmits<{
  (e: 'duplicate', id: string): void;
  (e: 'delete', id: string): void;
}>();

const { t } = useI18n();
const ctx = inject(BUILDER_CTX);

// Throwaway state for the disabled field previews.
const formData = reactive<Record<string, unknown>>({});
const repeaterInstances = ref<Record<string, number>>({});
const noError = (): undefined => undefined;

// A drag is in progress anywhere (drop zones only appear/expand then).
const dragActive = computed(() => !!(ctx && (ctx.dnd.type || ctx.dnd.id)));
const overIndex = ref<number | null>(null);

function label(el: FormElement): string {
  return el.config.name || el.config.label || el.config.text || t(`forms.elementTypes.${el.type}`);
}
function selected(id: string): boolean {
  return ctx?.selectedId() === id;
}
function childList(el: FormElement): FormElement[] {
  if (!el.config.children) el.config.children = [];
  return el.config.children;
}

// Whether a NEW element of the dragged type may drop into THIS list.
function canDropNew(type: FormElementType): boolean {
  if (props.accept === 'container' && type === 'section') return false;
  return true;
}

function onDrop(index: number): void {
  overIndex.value = null;
  if (!ctx) return;
  const { type, id } = ctx.dnd;
  if (type) {
    if (!canDropNew(type)) {
      ctx.endDnd();
      return;
    }
    const el = ctx.create(type);
    props.elements.splice(index, 0, el);
    ctx.select(el.id);
  } else if (id) {
    // Reorder within THIS list only (cross-container moves aren't supported).
    const current = props.elements.findIndex((e) => e.id === id);
    if (current !== -1) {
      const target = index > current ? index - 1 : index;
      const [moved] = props.elements.splice(current, 1);
      props.elements.splice(target, 0, moved);
    }
  }
  ctx.endDnd();
}

// --- Grid column drops (input fields only, one element per column) --------
function onDropColumn(column: { element: FormElement | null }): void {
  if (!ctx) return;
  const { type } = ctx.dnd;
  if (type && isInputType(type)) {
    const el = ctx.create(type);
    column.element = el;
    ctx.select(el.id);
  }
  ctx.endDnd();
}
</script>

<template>
  <div class="flex flex-col">
    <!-- Empty list = one big, obvious drop target (root or nested container). -->
    <div
      v-if="elements.length === 0"
      class="flex flex-col items-center justify-center gap-next-1 rounded-next-lg border border-dashed px-next-4 text-center transition-colors"
      :class="[
        accept === 'root' ? 'min-h-40 py-next-10' : 'min-h-24 py-next-6',
        overIndex === 0 ? 'border-next-primary bg-next-primary-subtle/40' : 'border-next-border',
      ]"
      @dragover.prevent="overIndex = 0"
      @dragleave="overIndex = null"
      @drop.prevent="onDrop(0)"
    >
      <Icon name="file-text" class="text-next-xl text-next-muted-foreground" />
      <p class="text-next-sm text-next-muted-foreground">
        {{ accept === 'root' ? t('forms.builder.canvasEmpty') : t('forms.builder.containerEmpty') }}
      </p>
    </div>

    <template v-else>
    <!-- Leading drop zone. -->
    <div
      class="next-dropzone"
      :class="[dragActive ? 'is-active' : '', overIndex === 0 ? 'is-over' : '']"
      @dragover.prevent="overIndex = 0"
      @dragleave="overIndex = null"
      @drop.prevent="onDrop(0)"
    />

    <template v-for="(element, index) in elements" :key="element.id">
      <div
        draggable="true"
        class="rounded-next-lg border bg-next-card transition-colors"
        :class="selected(element.id) ? 'border-next-primary ring-1 ring-next-primary' : 'border-next-border'"
        @dragstart.stop="ctx?.startMove(element.id)"
        @dragend="ctx?.endDnd()"
      >
        <!-- Chrome header: drag handle + label + actions; click selects. -->
        <div
          class="flex items-center gap-next-2 border-b border-next-border px-next-3 py-next-2"
          role="button"
          tabindex="0"
          @click="ctx?.select(element.id)"
          @keydown.enter="ctx?.select(element.id)"
        >
          <Icon name="more-vertical" class="shrink-0 cursor-grab text-next-muted-foreground" :aria-label="t('forms.builder.dragHandle')" />
          <Icon :name="iconOf(element.type)" class="shrink-0 text-next-muted-foreground" />
          <span class="min-w-0 flex-1 truncate text-next-sm font-next-medium text-next-fg">{{ label(element) }}</span>
          <Button variant="ghost" size="icon-xs" leading-icon="plus" :aria-label="t('forms.builder.duplicate')" @click.stop="emit('duplicate', element.id)" />
          <Button variant="ghost" size="icon-xs" leading-icon="trash" :aria-label="t('forms.builder.delete')" @click.stop="emit('delete', element.id)" />
        </div>

        <div class="p-next-4">
          <!-- Section / Repeater: recurse into children. -->
          <template v-if="element.type === 'section' || element.type === 'repeater'">
            <p v-if="element.config.description" class="mb-next-3 text-next-sm text-next-muted-foreground">
              {{ element.config.description }}
            </p>
            <ElementCanvas :elements="childList(element)" accept="container" @duplicate="emit('duplicate', $event)" @delete="emit('delete', $event)" />
            <p v-if="childrenOf(element).length === 0 && !dragActive" class="py-next-2 text-center text-next-xs text-next-muted-foreground">
              {{ t('forms.builder.containerEmpty') }}
            </p>
          </template>

          <!-- Grid: columns, each holds one input (drop target). -->
          <div
            v-else-if="element.type === 'grid'"
            class="grid gap-next-3"
            :style="{ gridTemplateColumns: (element.config.columns ?? []).map((c) => `${c.width}fr`).join(' ') }"
          >
            <div
              v-for="(column, ci) in (element.config.columns ?? [])"
              :key="ci"
              class="min-w-0"
            >
              <div
                v-if="column.element"
                class="rounded-next-md border bg-next-card"
                :class="selected(column.element.id) ? 'border-next-primary ring-1 ring-next-primary' : 'border-next-border'"
              >
                <div class="flex items-center gap-next-2 border-b border-next-border px-next-2 py-next-1_5" role="button" tabindex="0" @click="ctx?.select(column.element.id)" @keydown.enter="ctx?.select(column.element!.id)">
                  <Icon :name="iconOf(column.element.type)" class="shrink-0 text-next-muted-foreground" />
                  <span class="min-w-0 flex-1 truncate text-next-xs font-next-medium">{{ label(column.element) }}</span>
                  <Button variant="ghost" size="icon-xs" leading-icon="trash" :aria-label="t('forms.builder.removeField')" @click.stop="emit('delete', column.element.id)" />
                </div>
                <div class="p-next-3">
                  <InputRenderer :element="column.element" mode="preview" :form-data="formData" :repeater-instances="repeaterInstances" :get-error="noError" />
                </div>
              </div>
              <div
                v-else
                class="flex min-h-16 items-center justify-center rounded-next-md border border-dashed border-next-border text-next-xs text-next-muted-foreground"
                :class="dragActive ? 'border-next-primary/60 bg-next-primary-subtle/40' : ''"
                @dragover.prevent
                @drop.prevent="onDropColumn(column)"
              >
                {{ t('forms.builder.dropFieldHere') }}
              </div>
            </div>
          </div>

          <!-- Leaf input / content element: real field preview. -->
          <InputRenderer
            v-else
            :element="element"
            mode="preview"
            :form-data="formData"
            :repeater-instances="repeaterInstances"
            :get-error="noError"
          />
        </div>
      </div>

      <!-- Trailing drop zone after each element. -->
      <div
        class="next-dropzone"
        :class="[dragActive ? 'is-active' : '', overIndex === index + 1 ? 'is-over' : '']"
        @dragover.prevent="overIndex = index + 1"
        @dragleave="overIndex = null"
        @drop.prevent="onDrop(index + 1)"
      />
    </template>
    </template>
  </div>
</template>

<style scoped>
/* Drop zones are invisible until a drag is active, then they open a gap and show
   a primary line when hovered — so the insertion point is obvious. */
/* A constant small height doubles as the GAP between elements; on hover it grows
   into a clear primary insertion bar. */
.next-dropzone {
  height: 0.75rem;
  border-radius: 9999px;
  transition: height 120ms ease, background-color 120ms ease;
}
.next-dropzone.is-over {
  height: 1.5rem;
  background-color: var(--color-next-primary);
}
</style>
