<script setup lang="ts">
    import { computed } from "vue";
    import { cn } from "@/lib/helpers";
    import Tooltip from "./Tooltip.vue";

    type Size = "sm" | "md" | "lg";

    const props = withDefaults(
        defineProps<{
            name: string;
            src?: string;
            size?: Size;
            class?: string;
            tooltip?: boolean;
        }>(),
        { size: "md", tooltip: false }
    );

    const initials = computed(() => {
        const parts = props.name.trim().split(/\s+/).filter(Boolean);
        const a = parts[0]?.[0] ?? "?";
        const b = parts.length > 1 ? parts[parts.length - 1]?.[0] : "";
        return (a + b).toUpperCase();
    });

    const sizeCls: Record<Size, string> = {
        sm: "h-8 w-8 text-xs",
        md: "h-10 w-10 text-sm",
        lg: "h-12 w-12 text-base",
    };
</script>

<template>
  <Tooltip v-if="tooltip">
    <span
      :class="cn(
        'inline-flex select-none items-center justify-center rounded-full border border-secondary/60 bg-secondary text-secondary-foreground shadow-sm overflow-hidden',
        sizeCls[size],
        props.class
      )"
    >
      <img v-if="src" :src="src" :alt="name" class="h-full w-full object-cover" />
      <span v-else class="font-bold" aria-hidden="true">{{ initials }}</span>
    </span>
    
    <template #content>
      {{ name }}
    </template>
  </Tooltip>

  <span
    v-else
    :class="cn(
      'inline-flex select-none items-center justify-center rounded-full border border-secondary/60 bg-secondary text-secondary-foreground shadow-sm overflow-hidden',
      sizeCls[size],
      props.class
    )"
    :title="name"
  >
    <img v-if="src" :src="src" :alt="name" class="h-full w-full object-cover" />
    <span v-else class="font-bold" aria-hidden="true">{{ initials }}</span>
  </span>
</template>
