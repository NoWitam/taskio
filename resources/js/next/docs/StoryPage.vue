<script setup lang="ts">
// Consistent story-page shell: title, lead description, and an a11y/keyboard
// notes callout, followed by the page's sections (slot). Keeps every component
// page structured the same way for the gallery.
defineProps<{
  title: string;
  description?: string;
}>();
</script>

<template>
  <article class="flex max-w-5xl flex-col gap-next-8">
    <header class="flex flex-col gap-next-2">
      <!-- Explicit weight: Tailwind preflight resets h1 to font-weight 400. -->
      <h1 class="text-next-3xl font-next-semibold">{{ title }}</h1>
      <p v-if="description" class="max-w-3xl text-next-base text-next-muted-foreground">
        {{ description }}
      </p>
    </header>

    <div v-if="$slots.a11y" class="rounded-next-lg border border-next-info-subtle bg-next-info-subtle p-next-4">
      <p class="mb-next-1 text-next-sm font-next-semibold text-next-info-subtle-foreground">
        Keyboard &amp; ARIA
      </p>
      <div class="text-next-sm text-next-info-subtle-foreground">
        <slot name="a11y" />
      </div>
    </div>

    <slot />
  </article>
</template>
