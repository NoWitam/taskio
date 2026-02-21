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
</script>

<template>
  <NodeViewWrapper
    class="mention-chip"
    :data-id="node.attrs.id"
    :data-label="node.attrs.label"
    contenteditable="false"
  >
    <Icon name="at-sign" size="xs" variant="stroke" />
    {{ node.attrs.label }}
  </NodeViewWrapper>
</template>

<style scoped>
.mention-chip {
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
</style>

