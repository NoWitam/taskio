<script setup lang="ts">
  import { computed } from "vue";

  type Size = "sm" | "md" | "lg";

  const props = withDefaults(
    defineProps<{
      size?: Size;
      label?: string;
      class?: string;
    }>(),
    { size: "md" }
  );

  const sizes: Record<Size, string> = {
    sm: "w-4 h-4",
    md: "w-8 h-8",
    lg: "w-12 h-12",
  };

  const sizeClass = computed(() => sizes[props.size]);
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-2" :class="class">
    <svg
      :class="sizeClass"
      class="animate-spin text-primary"
      fill="none"
      viewBox="0 0 24 24"
      aria-hidden="true"
    >
      <circle
        class="opacity-25"
        cx="12"
        cy="12"
        r="10"
        stroke="currentColor"
        stroke-width="4"
      />
      <path
        class="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
      />
    </svg>
    <span v-if="label" class="text-sm text-muted-foreground">{{ label }}</span>
  </div>
</template>
