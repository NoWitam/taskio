<script setup lang="ts">
    import { computed } from "vue";
    import { cn } from "@/lib/helpers";

    type Rounded = "none" | "sm" | "md" | "lg" | "full";

    const props = withDefaults(
        defineProps<{
            as?: "div" | "span";
            width?: string;
            height?: string;
            rounded?: Rounded;
            class?: string;
        }>(),
        { as: "div", rounded: "md" }
    );

    const radiusCls: Record<Rounded, string> = {
        none: "rounded-none",
        sm: "rounded",
        md: "rounded-lg",
        lg: "rounded-xl",
        full: "rounded-full",
    };

    const style = computed(() => ({
        width: props.width,
        height: props.height,
    }));
</script>

<template>
  <component
    :is="as"
    aria-hidden="true"
    :style="style"
    :class="cn(
      'relative overflow-hidden bg-secondary',
      radiusCls[rounded],
      props.class
    )"
  >
    <!-- shimmer -->
    <span
      class="absolute inset-0 -translate-x-full animate-[uiShimmer_1.2s_infinite] bg-gradient-to-r from-transparent via-white/35 to-transparent"
    />
  </component>
</template>

<style scoped>
    @keyframes uiShimmer {
        100% {
            transform: translateX(100%);
        }
    }
</style>
