<script setup lang="ts">
  import { computed } from "vue";
  import { cn } from "@/lib/helpers";

  type Variant = "primary" | "secondary" | "ghost" | "danger" | "success" | "warning";
  type Size = "sm" | "md" | "lg";

  const props = withDefaults(
    defineProps<{
      type?: "button" | "submit" | "reset";
      variant?: Variant;
      size?: Size;
      disabled?: boolean;
      loading?: boolean;
      class?: string;
    }>(),
    { type: "button", variant: "secondary", size: "md", disabled: false, loading: false }
  );

  const isDisabled = computed(() => props.disabled || props.loading);

  const base =
    "inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition " +
    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30 cursor-pointer " +
    "disabled:opacity-60 disabled:cursor-not-allowed";

  const sizes: Record<Size, string> = {
    sm: "h-9 px-3 text-sm",
    md: "h-10 px-3.5 text-sm",
    lg: "h-11 px-4 text-base",
  };

  const variants: Record<Variant, string> = {
    primary: "bg-primary text-primary-foreground hover:bg-primary-dark shadow-sm",
    secondary: "bg-secondary text-secondary-foreground hover:bg-secondary/80 border border-border",
    ghost: "bg-transparent hover:bg-secondary/70 text-foreground",
    danger: "bg-danger text-danger-foreground hover:bg-danger/90 shadow-sm",
    success: "bg-success text-success-foreground hover:bg-success/90 shadow-sm",
    warning: "bg-warning text-warning-foreground hover:bg-warning/90 shadow-sm",
  };

  const cls = computed(() =>
    cn(base, sizes[props.size], variants[props.variant], props.class)
  );
</script>

<template>
  <button :type="type" :class="cls" :disabled="isDisabled" :aria-busy="loading || undefined" v-bind="$attrs">
    <svg
      v-if="loading"
      class="h-4 w-4 animate-spin"
      viewBox="0 0 24 24"
      aria-hidden="true"
    >
      <circle class="opacity-25" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="4" />
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
    </svg>

    <span class="flex items-center justify-center gap-2">
      <slot />
    </span>
  </button>
</template>
