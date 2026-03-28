<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import Avatar from '@/components/ui/Avatar.vue';
import type { MentionUser } from '../types/editor';

const props = defineProps<{
  items: MentionUser[];
  command: (item: MentionUser) => void;
}>();

const selectedIndex = ref(0);

const selectItem = (index: number) => {
  const item = props.items[index];
  if (item) {
    props.command(item);
  }
};

const upHandler = () => {
  selectedIndex.value = (selectedIndex.value + props.items.length - 1) % props.items.length;
};

const downHandler = () => {
  selectedIndex.value = (selectedIndex.value + 1) % props.items.length;
};

const enterHandler = () => {
  selectItem(selectedIndex.value);
};

const onKeyDown = (event: KeyboardEvent) => {
  if (event.key === 'ArrowUp') {
    upHandler();
    return true;
  }

  if (event.key === 'ArrowDown') {
    downHandler();
    return true;
  }

  if (event.key === 'Enter') {
    enterHandler();
    return true;
  }

  return false;
};

watch(() => props.items, () => {
  selectedIndex.value = 0;
});

defineExpose({
  onKeyDown,
});
</script>

<template>
  <div class="w-64 rounded-xl border border-secondary/60 bg-background shadow-lg overflow-hidden">
    <div v-if="items.length" class="max-h-64 overflow-y-auto py-1">
      <button
        v-for="(item, index) in items"
        :key="item.id"
        type="button"
        class="flex w-full items-center gap-3 px-3 py-2 text-sm hover:bg-secondary/60 transition-colors"
        :class="{ 'bg-secondary/60': index === selectedIndex }"
        @click="selectItem(index)"
      >
        <Avatar :name="item.name" :src="item.avatar" size="xs" class="shrink-0" />
        <div class="min-w-0 text-left">
          <div class="font-medium text-foreground truncate">{{ item.name }}</div>
          <div v-if="item.email" class="text-xs text-muted-foreground truncate">{{ item.email }}</div>
        </div>
      </button>
    </div>
    <div v-else class="px-3 py-2 text-sm text-muted-foreground">
      Brak użytkowników
    </div>
  </div>
</template>
