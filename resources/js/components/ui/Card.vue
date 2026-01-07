<script setup lang="ts">
    import { cn } from "@/lib/helpers";
    type Padding = "none" | "sm" | "md" | "lg";

    const props = withDefaults(
        defineProps<{
            as?: "div" | "section" | "article";
            padding?: Padding;
            class?: string;
        }>(),
        { as: "div", padding: "md" }
    );

    const pad = {
        none: "p-0",
        sm: "p-3",
        md: "p-4",
        lg: "p-6",
    } satisfies Record<Padding, string>;
</script>

<template>
  <component
    :is="as"
    :class="cn(
      'rounded-xl border border-border bg-card shadow-sm',
      pad[padding],
      props.class
    )"
  >
    <slot />
  </component>
</template>
