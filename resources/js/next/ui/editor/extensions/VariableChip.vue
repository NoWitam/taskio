<script setup lang="ts">
// VariableChip — inline atomic NodeView for a `variable` node. Renders the
// (current) name + a type icon reflecting `resultType` (after the pipeline) as a
// single token-styled, selectable, deletable unit. Clicking the chip opens the
// VariablePanel inside a Modal (full pipeline editor); saving updates the node
// attrs (name, locked, pipeline, resultType).
import { computed, ref } from 'vue';
import { NodeViewWrapper } from '@tiptap/vue-3';
import Icon from '../../primitives/Icon.vue';
import VariablePanel from './VariablePanel.vue';
import { useI18n } from '../../../app/i18n';
import { getVariableIconLabel, getVariableIconName } from './operationHelpers';
import type {
  VariableDefinition,
  VariableNodeAttrs,
  VariableOperationDefinition,
} from './types';

const props = defineProps<{
  editor: { storage?: Record<string, unknown> };
  node: { attrs: VariableNodeAttrs };
  updateAttributes: (attrs: Partial<VariableNodeAttrs>) => void;
  deleteNode: () => void;
  selected?: boolean;
}>();

const { t } = useI18n();

const open = ref(false);

const label = computed(() => props.node.attrs.name || props.node.attrs.id || 'variable');
const resultType = computed(() => props.node.attrs.resultType ?? 'text');

// Feature config (predefined variables + operations catalog) seeded by the
// extension storage so the panel can build the pipeline + resolve the base type.
const storage = computed(
  () =>
    (props.editor.storage?.variable as {
      definitions?: VariableDefinition[];
      catalog?: VariableOperationDefinition[];
    }) ?? {},
);
const definitions = computed(() => storage.value.definitions ?? []);
const catalog = computed(() => storage.value.catalog ?? []);

function onSave(attrs: VariableNodeAttrs): void {
  props.updateAttributes(attrs);
  open.value = false;
}
function onRemove(): void {
  open.value = false;
  props.deleteNode();
}
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    open.value = true;
  }
}
</script>

<template>
  <NodeViewWrapper as="span" class="next-var-chip-wrap" contenteditable="false" data-variable>
    <button
      type="button"
      class="next-var-chip"
      :class="selected ? 'is-selected' : ''"
      :aria-expanded="open"
      aria-haspopup="dialog"
      @click="open = true"
      @keydown="onKeydown"
    >
      <Icon :name="getVariableIconName(resultType)" class="next-var-chip__type" :label="t('editor.types.typeLabel', 'Type: {type}', { type: getVariableIconLabel(resultType) })" />
      <span class="next-var-chip__label">{{ label }}</span>
    </button>

    <VariablePanel
      v-model:open="open"
      :state="node.attrs"
      :definitions="definitions"
      :catalog="catalog"
      @save="onSave"
      @remove="onRemove"
    />
  </NodeViewWrapper>
</template>

<style scoped>
.next-var-chip-wrap {
  display: inline-block;
  vertical-align: baseline;
}
.next-var-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.05rem 0.45rem;
  border-radius: var(--radius-next-md);
  background-color: var(--color-next-accent);
  color: var(--color-next-accent-foreground);
  font-weight: var(--font-weight-next-medium);
  font-size: 0.9em;
  line-height: 1.4;
  white-space: nowrap;
  cursor: pointer;
}
.next-var-chip.is-selected {
  box-shadow: 0 0 0 2px var(--color-next-ring);
}
.next-var-chip__type {
  opacity: 0.85;
}
</style>
