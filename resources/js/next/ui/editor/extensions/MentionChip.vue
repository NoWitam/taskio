<script setup lang="ts">
// MentionChip — the inline atomic NodeView for a `mention` node.
//
// Renders an avatar/initial + label as a single token-styled, selectable,
// deletable unit (Tiptap treats the whole node as one selectable atom). When the
// node is the current ProseMirror NodeSelection it gets a ring so keyboard users
// see it's selected before Backspace/Delete removes it as one unit.
import { computed } from 'vue';
import { NodeViewWrapper } from '@tiptap/vue-3';
import Avatar from '../../primitives/Avatar.vue';
import type { MentionNodeAttrs } from './types';

const props = defineProps<{
  node: { attrs: MentionNodeAttrs };
  selected?: boolean;
}>();

const label = computed(() => props.node.attrs.name || 'Unknown');
</script>

<template>
  <NodeViewWrapper
    as="span"
    class="next-mention-chip"
    :class="selected ? 'is-selected' : ''"
    contenteditable="false"
    data-mention
  >
    <Avatar
      :src="node.attrs.avatar ?? undefined"
      :name="label"
      size="xs"
      class="next-mention-chip__avatar"
    />
    <span class="next-mention-chip__label">@{{ label }}</span>
  </NodeViewWrapper>
</template>

<style scoped>
.next-mention-chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.05rem 0.4rem 0.05rem 0.15rem;
  border-radius: var(--radius-next-full, 9999px);
  background-color: var(--color-next-primary-subtle);
  color: var(--color-next-primary-subtle-foreground);
  font-weight: var(--font-weight-next-medium);
  font-size: 0.9em;
  line-height: 1.4;
  vertical-align: baseline;
  white-space: nowrap;
  cursor: default;
  user-select: none;
}
.next-mention-chip.is-selected {
  box-shadow: 0 0 0 2px var(--color-next-ring);
}
.next-mention-chip__avatar {
  width: 1.1em;
  height: 1.1em;
}
.next-mention-chip__label {
  font-size: inherit;
}
</style>
