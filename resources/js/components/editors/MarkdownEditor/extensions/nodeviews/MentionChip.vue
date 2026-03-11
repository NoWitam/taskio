<script setup lang="ts">
import { computed } from 'vue';
import { NodeViewWrapper } from '@tiptap/vue-3';
import Avatar from '@/components/ui/Avatar.vue';
import type { MentionNodeAttrs } from '../../types/editor';

const props = defineProps<{
  node: { attrs: MentionNodeAttrs };
}>();

const displayName = computed(() => props.node.attrs.name || 'Użytkownik');
</script>

<template>
  <NodeViewWrapper
    as="span"
    class="inline-flex items-center gap-2 rounded-full bg-primary text-white text-xs font-medium px-2 py-0.5 cursor-pointer"
    contenteditable="false"
  >
    <span class="flex items-center gap-1">
      <span class="rounded-full bg-white/20 px-1 font-bold">@</span>
      <span>{{ displayName }}</span>
    </span>
    <div class="flex items-center">
      <Avatar
        v-if="node.attrs.avatar"
        :src="node.attrs.avatar"
        :name="displayName"
        size="xs"
      />
      <Avatar
        v-else
        :name="displayName"
        size="xs"
      />
    </div>
  </NodeViewWrapper>
</template>
