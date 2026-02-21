<script setup lang="ts">
import { ref } from 'vue';
import { NodeViewWrapper } from '@tiptap/vue-3';
import Icon from '@/components/ui/Icon.vue';
import OperationsPanel from './OperationsPanel.vue';

const props = defineProps<{
  node: any;
  editor: any;
  getPos: () => number;
  updateAttributes: (attrs: Record<string, any>) => void;
  deleteNode: () => void;
  selected: boolean;
}>();

// Map variable types to icons
const typeIcons: Record<string, string> = {
  text: 'list',
  number: 'equal',
  boolean: 'numeric',
  date: 'calendar',
};

const showOperationsPanel = ref(false);

function handleClick() {
  showOperationsPanel.value = true;
}

function handleSaveOperations(operations: Array<{ op: string; args?: any[] }>) {
  props.updateAttributes({
    ops: operations,
  });
  showOperationsPanel.value = false;
}

function handleClosePanel() {
  showOperationsPanel.value = false;
}
</script>

<template>
  <NodeViewWrapper
    class="variable-chip"
    :data-var-id="node.attrs.varId"
    contenteditable="false"
    @click="handleClick"
  >
    <Icon name="curly-braces" size="xs" variant="stroke" />
    <span class="var-name">{{ node.attrs.varName || node.attrs.varId }}</span>
    <span v-if="node.attrs.varType" class="var-type-badge">
      <Icon :name="typeIcons[node.attrs.varType] || 'variable'" size="xs" variant="stroke" />
    </span>
    <span v-if="node.attrs.ops && node.attrs.ops.length > 0" class="ops-indicator">
      {{ node.attrs.ops.length }}
    </span>

    <OperationsPanel
      v-if="showOperationsPanel"
      :variable-name="node.attrs.varName || node.attrs.varId"
      :variable-type="node.attrs.varType"
      :current-operations="node.attrs.ops"
      @save="handleSaveOperations"
      @close="handleClosePanel"
    />
  </NodeViewWrapper>
</template>

<style scoped>
.variable-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  background-color: var(--color-primary);
  color: var(--color-primary-foreground);
  padding: 0.25rem 0.5rem;
  border-radius: 0.25rem;
  font-weight: 500;
  cursor: pointer;
  font-family: monospace;
  font-size: 0.875em;
  transition: opacity 0.15s;
}

.variable-chip:hover {
  opacity: 0.9;
  text-decoration: underline;
}

.var-name {
  margin: 0 0.125rem;
}

.var-type-badge {
  display: inline-flex;
  align-items: center;
  opacity: 0.85;
}

.ops-indicator {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-left: 0.25rem;
  min-width: 1.25rem;
  height: 1.25rem;
  background-color: rgba(255, 255, 255, 0.2);
  border-radius: 50%;
  font-size: 0.625rem;
  font-weight: bold;
}
</style>
