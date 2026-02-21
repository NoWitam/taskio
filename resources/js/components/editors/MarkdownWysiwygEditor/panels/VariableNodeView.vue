<script setup lang="ts">
import { NodeViewWrapper } from '@tiptap/vue-3';
import Icon from '@/components/ui/Icon.vue';

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
</script>

<template>
  <NodeViewWrapper
    class="variable-chip"
    :data-var-id="node.attrs.varId"
    contenteditable="false"
  >
    <Icon name="curly-braces" size="xs" variant="stroke" />
    <span class="var-name">{{ node.attrs.varName || node.attrs.varId }}</span>
    <span v-if="node.attrs.varType" class="var-type-badge">
      <Icon :name="typeIcons[node.attrs.varType] || 'variable'" size="xs" variant="stroke" />
    </span>
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
}

.var-name {
  margin: 0 0.125rem;
}

.var-type-badge {
  display: inline-flex;
  align-items: center;
  opacity: 0.85;
}
</style>
