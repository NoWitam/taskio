<script setup lang="ts">
    import { computed } from "vue";
    import { cn } from "@/lib/helpers";

    type Tone = "neutral" | "primary" | "success" | "warning" | "danger";

    const props = withDefaults(
        defineProps<{ tone?: Tone; dot?: boolean; class?: string }>(),
        { tone: "neutral", dot: false }
    );

    const toneClass: Record<Tone, string> = {
        neutral: "bg-secondary text-secondary-foreground border-border",
        primary: "bg-primary/10 text-primary border-primary/20",
        success: "bg-emerald-500/10 text-emerald-700 border-emerald-500/20",
        warning: "bg-amber-500/10 text-amber-700 border-amber-500/20",
        danger: "bg-danger/10 text-danger border-danger/20",
    };

    const cls = computed(() =>
        cn("inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-semibold", toneClass[props.tone], props.class)
    );
</script>

<template>
  <span :class="cls">
    <span v-if="dot" class="h-1.5 w-1.5 rounded-full bg-current opacity-80" aria-hidden="true" />
    <slot />
  </span>
</template>