<script setup lang="ts">
// Gallery story-section wrapper: a titled block with an optional description and
// a consistent surface, used to group one facet of a component's matrix (e.g.
// "Variants", "Sizes", "Loading"). Keeps every story page visually consistent.
defineProps<{
  title: string;
  description?: string;
}>();
</script>

<template>
  <section class="flex flex-col gap-next-3">
    <div class="flex flex-col gap-next-1">
      <h3 class="text-next-lg font-next-semibold">{{ title }}</h3>
      <p v-if="description" class="text-next-sm text-next-muted-foreground">
        {{ description }}
      </p>
    </div>
    <div
      class="rounded-next-lg border border-next-border bg-next-card p-next-4 shadow-next-xs"
    >
      <slot />
    </div>
  </section>
</template>
