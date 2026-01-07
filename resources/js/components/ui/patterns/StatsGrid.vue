<script setup lang="ts">
    import { computed } from "vue";
    import { cn } from "@/lib/helpers";

    type Layout = "auto" | "2" | "3" | "4";

    const props = withDefaults(
        defineProps<{
            layout?: Layout;
            class?: string;
        }>(),
        { layout: "auto" }
    );

    const gridCls = computed(() => {
        switch (props.layout) {
            case "2":
                return "grid grid-cols-1 gap-3 md:grid-cols-2";
            case "3":
                return "grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3";
            case "4":
                return "grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4";
            case "auto":
            default:
                return "grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4";
        }
    });
</script>

<template>
  <div :class="cn(gridCls, props.class)">
    <slot />
  </div>
</template>
