<script setup lang="ts">
// FolderStage — a folder's preview: the folder glyph with its chosen icon nested inside
// (the same composition the tiles and the old folder drawer used) + its description.
import { computed } from 'vue';
import Icon, { type IconName } from '../../../../ui/primitives/Icon.vue';
import type { DiskFolder } from '../../types';

const props = defineProps<{ folder: DiskFolder }>();

const nestedIcon = computed<IconName | null>(() => (props.folder.icon as IconName | null) ?? null);
</script>

<template>
  <div class="flex min-h-[50vh] flex-col items-center justify-center gap-next-4 rounded-next-lg border border-next-border bg-next-primary/5 p-next-6">
    <span class="relative flex items-center justify-center text-next-primary" aria-hidden="true">
      <Icon name="folder" class="text-[8rem]" />
      <Icon
        v-if="nestedIcon"
        :name="nestedIcon"
        class="absolute left-1/2 top-[57%] -translate-x-1/2 -translate-y-1/2 text-next-3xl text-next-primary"
      />
    </span>
    <p v-if="folder.description" class="max-w-prose whitespace-pre-wrap text-center text-next-sm text-next-muted-foreground">
      {{ folder.description }}
    </p>
  </div>
</template>
