<script setup lang="ts">
import { cn } from "@/lib/helpers";

const props = withDefaults(
  defineProps<{
    checked?: boolean;
    disabled?: boolean;
    class?: string;
  }>(),
  { checked: false, disabled: false }
);
</script>

<template>
  <span
    :class="cn(
      'inline-flex h-5 w-5 items-center justify-center rounded border transition flex-shrink-0',
      checked ? 'bg-primary border-primary' : 'bg-card border-border',
      disabled && 'opacity-50 cursor-not-allowed',
      props.class
    )"
    aria-hidden="true"
  >
    <svg
      v-if="checked"
      viewBox="0 0 20 20"
      class="h-4 w-4 text-primary-foreground"
      fill="none"
      stroke="currentColor"
      stroke-width="2.5"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path d="M4 10.5l3.2 3.2L16 5.9" />
    </svg>
  </span>
</template>
