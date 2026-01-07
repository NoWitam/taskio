<script setup lang="ts">
    import { computed } from "vue";
    import { cn } from "@/lib/helpers";

    type DeltaTone = "neutral" | "success" | "warning" | "danger";

    const props = withDefaults(
        defineProps<{
            label: string;
            value: string | number;
            hint?: string;
            delta?: string;
            deltaTone?: DeltaTone;
            href?: string;
            class?: string;
        }>(),
        { deltaTone: "neutral" }
        );

    const isLink = computed(() => !!props.href);

    const deltaCls: Record<DeltaTone, string> = {
        neutral: "bg-secondary text-secondary-foreground border-secondary/60",
        success: "bg-emerald-500/10 text-emerald-700 border-emerald-500/20",
        warning: "bg-amber-500/10 text-amber-700 border-amber-500/20",
        danger: "bg-danger/10 text-danger border-danger/20",
    };
</script>

<template>
  <component
    :is="isLink ? 'a' : 'div'"
    :href="isLink ? href : undefined"
    :class="cn(
      'block rounded-2xl border border-secondary/60 bg-background p-4 shadow-sm transition',
      isLink && 'hover:border-secondary/80 hover:bg-secondary/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/30',
      props.class
    )"
  >
    <div class="flex items-start justify-between gap-3">
      <div class="flex min-w-0 items-center gap-3">
        <div v-if="$slots.icon" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-secondary">
          <slot name="icon" />
        </div>

        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <div class="truncate text-sm font-semibold text-foreground">
              {{ label }}
            </div>

            <div v-if="hint" class="truncate text-xs text-foreground/60">
              {{ hint }}
            </div>
          </div>

          <div class="mt-1 text-2xl font-semibold text-foreground">
            {{ value }}
          </div>
        </div>
      </div>

      <div class="flex shrink-0 items-center gap-2">
        <span
          v-if="delta"
          :class="cn('inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-bold', deltaCls[deltaTone])"
        >
          {{ delta }}
        </span>

        <div v-if="$slots.actions" class="ml-1">
          <slot name="actions" />
        </div>
      </div>
    </div>

    <div v-if="$slots.footer" class="mt-3 border-t border-secondary/60 pt-3">
      <slot name="footer" />
    </div>
  </component>
</template>
