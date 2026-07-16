<script setup lang="ts">
// A single labelled cell inside a StoryGrid: renders its slot above a small
// caption so each state in the matrix is self-describing in the gallery.
defineProps<{
  label?: string;
}>();
</script>

<template>
  <div class="flex flex-col items-start gap-next-2">
    <div class="flex min-h-8 items-center"><slot /></div>
    <span
      v-if="label"
      class="font-next-mono text-next-2xs text-next-muted-foreground"
    >
      {{ label }}
    </span>
  </div>
</template>
