<script setup lang="ts">
// Gallery story-grid: a flex/grid of labelled cells, each wrapping a single
// rendered state. Used inside StorySection to show, e.g., one cell per variant
// or per state. `cols` switches between an auto-flow row (wrap) and a fixed
// column grid for denser matrices.
withDefaults(
  defineProps<{
    /** Fixed column count; omit for a wrapping inline-flow layout. */
    cols?: number;
    /** Vertically align cells (e.g. baseline for text). */
    align?: 'start' | 'center' | 'end' | 'baseline';
  }>(),
  { align: 'end' },
);
</script>

<template>
  <div
    class="gap-next-4"
    :class="[
      cols ? 'grid' : 'flex flex-wrap',
      align === 'start' ? 'items-start' : '',
      align === 'center' ? 'items-center' : '',
      align === 'end' ? 'items-end' : '',
      align === 'baseline' ? 'items-baseline' : '',
    ]"
    :style="cols ? { gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` } : undefined"
  >
    <slot />
  </div>
</template>
