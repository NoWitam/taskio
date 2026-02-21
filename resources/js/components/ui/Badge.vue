<script setup lang="ts">
    import { computed } from "vue";
    import { cn } from "@/lib/helpers";

    type Tone = "neutral" | "primary" | "success" | "warning" | "danger" | "custom";

    const props = withDefaults(
        defineProps<{ tone?: Tone; dot?: boolean; color?: string; class?: string }>(),
        { tone: "neutral", dot: false }
    );

    const toneClass: Record<Tone, string> = {
        neutral: "bg-secondary text-secondary-foreground border-border",
        primary: "bg-primary/10 text-primary border-primary/20",
        success: "bg-emerald-500/10 text-emerald-700 border-emerald-500/20",
        warning: "bg-amber-500/10 text-amber-700 border-amber-500/20",
        danger: "bg-danger/10 text-danger border-danger/20",
        custom: "",
    };

    const cls = computed(() =>
        cn(
            "inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-semibold",
            props.tone === "custom" ? "" : toneClass[props.tone],
            props.class
        )
    );

    const customStyle = computed(() => {
        if (props.tone !== "custom" || !props.color) return {};
        
        const hexToRgb = (hex: string) => {
            const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? {
                r: parseInt(result[1], 16),
                g: parseInt(result[2], 16),
                b: parseInt(result[3], 16)
            } : { r: 0, g: 0, b: 0 };
        };

        const rgb = hexToRgb(props.color);
        
        return {
            backgroundColor: `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.1)`,
            color: props.color,
            borderColor: `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, 0.2)`,
        };
    });
</script>

<template>
  <span :class="cls" :style="customStyle">
    <span v-if="dot" class="h-1.5 w-1.5 rounded-full bg-current opacity-80" aria-hidden="true" />
    <slot />
  </span>
</template>