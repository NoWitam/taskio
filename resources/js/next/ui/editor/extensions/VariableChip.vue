<script setup lang="ts">
// VariableChip — inline atomic NodeView for a `variable` node. Renders the
// (current) name + a type icon reflecting `resultType` (after the pipeline) as a
// single token-styled, selectable, deletable unit. Clicking the chip opens the
// VariablePanel inside a Modal (full pipeline editor); saving updates the node
// attrs (name, locked, pipeline, resultType).
import { computed, ref } from 'vue';
import { NodeViewWrapper } from '@tiptap/vue-3';
import VariablePanel from './VariablePanel.vue';
import VariableTypeIcon from './VariableTypeIcon.vue';
import { getVariableIconLabel, getVariableIconName } from './operationHelpers';
import { variableFeedTree, variableSourceFeed } from './variableFeed';
import { findNodeByPath } from '../../variables/variableTree';
import type { VariableNodeAttrs, VariableStorage } from './types';

const props = defineProps<{
  editor: { storage?: Record<string, unknown> };
  node: { attrs: VariableNodeAttrs };
  updateAttributes: (attrs: Partial<VariableNodeAttrs>) => void;
  deleteNode: () => void;
  selected?: boolean;
}>();

const open = ref(false);

const label = computed(() => props.node.attrs.name || props.node.attrs.id || 'variable');
const resultType = computed(() => props.node.attrs.resultType ?? 'text');

// Feature config (offered variables + operations catalog) read from the extension
// storage so the panel can build the pipeline + resolve the base type.
//
// LIVE, not a snapshot: we call the storage's `getDefinitions()` / `getCatalog()`
// readers INSIDE these computeds, so (a) the host's CURRENT feed is read on every
// evaluation and (b) any reactive source behind the getter is tracked as a dependency —
// an async catalog, a renamed step key or a switched trigger form now reaches an
// already-open editor. The frozen arrays remain the fallback for an older host.
const storage = computed(
  () => (props.editor.storage?.variable as Partial<VariableStorage> | undefined) ?? {},
);
const definitions = computed(
  () => storage.value.getDefinitions?.() ?? storage.value.definitions ?? [],
);
const catalog = computed(() => storage.value.getCatalog?.() ?? storage.value.catalog ?? []);

// The SHARED-model feed behind this editor: the host's live `VariableSourceVar[]` when it has one,
// else its flat definitions promoted back (see ./variableFeed). It is what the panel browses AND
// the pool an op ARGUMENT inside the panel's pipeline may reference.
const sourceVars = computed(() => variableSourceFeed(storage.value.getSource?.(), definitions.value));
/** The offered TREE — ONE resolution path for the chip, its panel and the `{` popup alike. */
const nodes = computed(() => variableFeedTree(sourceVars.value, definitions.value));

// The referenced variable's tree node carries the type-icon MODIFIERS (§refinement 3): nullable when
// the variable may resolve empty, array when it is a collection. Null for an off-catalog / stale ref
// (the chip then falls back to its own stored attrs, as it always did).
const referenced = computed(() => findNodeByPath(nodes.value, props.node.attrs.id));

/** The host-injected value-or-variable control for ONE pipeline argument (B4; may be absent). */
const argVariableField = computed(() => storage.value.argVariableField);

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
      <VariableTypeIcon
        :icon="getVariableIconName(resultType)"
        :type-label="getVariableIconLabel(resultType)"
        :nullable="referenced?.nullable"
        :array="referenced?.array"
        class="next-var-chip__type"
      />
      <span class="next-var-chip__label">{{ label }}</span>
    </button>

    <VariablePanel
      v-model:open="open"
      :state="node.attrs"
      :nodes="nodes"
      :catalog="catalog"
      :arg-variables="sourceVars"
      :arg-variable-field="argVariableField"
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
