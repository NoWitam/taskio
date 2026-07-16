<script setup lang="ts">
// Progress — a LINEAR progress bar for the "next" frontend.
//
// Determinate: `value` / `max` drive a smooth width transition. Indeterminate:
// `indeterminate` shows an animated travelling bar (respects reduced motion — the
// global `.next-root` rule collapses the animation). Sizes sm|md; tones
// primary|success|warning|danger. Optional inline label and/or percentage.
//
// A11y: `role="progressbar"` with `aria-valuemin` / `aria-valuemax` and, when
// determinate, `aria-valuenow` (omitted when indeterminate). The optional label is
// associated via `aria-label` / `aria-describedby`.
import { computed } from 'vue';

type ProgressSize = 'sm' | 'md';
type ProgressTone = 'primary' | 'success' | 'warning' | 'danger';

const props = withDefaults(
  defineProps<{
    /** Current value (ignored when `indeterminate`). */
    value?: number;
    /** Maximum value. */
    max?: number;
    /** Show an animated indeterminate bar instead of a determinate fill. */
    indeterminate?: boolean;
    size?: ProgressSize;
    tone?: ProgressTone;
    /** Visible text label rendered above the track. */
    label?: string;
    /** Show the computed percentage (determinate only). */
    showPercentage?: boolean;
    /** Accessible label when no visible `label` is given. */
    ariaLabel?: string;
  }>(),
  {
    value: 0,
    max: 100,
    indeterminate: false,
    size: 'md',
    tone: 'primary',
    showPercentage: false,
  },
);

const clamped = computed(() => Math.min(Math.max(props.value, 0), props.max));
const percent = computed(() =>
  props.max <= 0 ? 0 : Math.round((clamped.value / props.max) * 100),
);

const TRACK_H: Record<ProgressSize, string> = {
  sm: 'h-1_5',
  md: 'h-2_5',
};

const FILL_TONE: Record<ProgressTone, string> = {
  primary: 'bg-next-primary',
  success: 'bg-next-success',
  warning: 'bg-next-warning',
  danger: 'bg-next-danger',
};

const hasHeader = computed(() => !!props.label || (props.showPercentage && !props.indeterminate));
</script>

<template>
  <div class="next-progress flex w-full flex-col gap-next-1">
    <div
      v-if="hasHeader"
      class="flex items-center justify-between text-next-xs text-next-muted-foreground"
    >
      <span v-if="label" class="font-next-medium text-next-fg">{{ label }}</span>
      <span v-else />
      <span v-if="showPercentage && !indeterminate" class="tabular-nums">{{ percent }}%</span>
    </div>

    <div
      class="next-progress__track w-full overflow-hidden rounded-next-full bg-next-muted"
      :class="TRACK_H[size]"
      role="progressbar"
      :aria-valuemin="0"
      :aria-valuemax="max"
      :aria-valuenow="indeterminate ? undefined : clamped"
      :aria-label="!label ? ariaLabel : undefined"
      :aria-busy="indeterminate ? 'true' : undefined"
    >
      <!-- Determinate fill: smooth width transition. -->
      <div
        v-if="!indeterminate"
        class="h-full rounded-next-full transition-[width] duration-[var(--duration-next-normal)] ease-[var(--ease-next-standard)]"
        :class="FILL_TONE[tone]"
        :style="{ width: `${percent}%` }"
      />
      <!-- Indeterminate: a travelling segment. -->
      <div
        v-else
        class="next-progress__indeterminate h-full w-2/5 rounded-next-full"
        :class="FILL_TONE[tone]"
      />
    </div>
  </div>
</template>

<style scoped>
.next-progress__indeterminate {
  animation: next-progress-slide 1.4s var(--ease-next-standard) infinite;
}

@keyframes next-progress-slide {
  0% {
    transform: translateX(-120%);
  }
  100% {
    transform: translateX(320%);
  }
}

@media (prefers-reduced-motion: reduce) {
  .next-progress__indeterminate {
    animation: none;
    width: 100%;
    opacity: 0.6;
  }
}
</style>
