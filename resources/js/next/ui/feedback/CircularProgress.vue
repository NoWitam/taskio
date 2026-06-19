<script setup lang="ts">
// CircularProgress — an SVG ring progress indicator for the "next" frontend.
//
// Determinate: `value` / `max` set the arc length via stroke-dashoffset (smooth
// transition). Indeterminate: a spinning partial arc (respects reduced motion).
// Sizes sm|md|lg|xl; tones primary|success|warning|danger. Optional center label
// (defaults to the percentage when `showPercentage`).
//
// A11y: `role="progressbar"` with `aria-valuemin` / `aria-valuemax` and, when
// determinate, `aria-valuenow` (omitted when indeterminate). Center text is
// decorative (`aria-hidden`) since the value lives on the progressbar.
import { computed } from 'vue';

type CircularSize = 'sm' | 'md' | 'lg' | 'xl';
type CircularTone = 'primary' | 'success' | 'warning' | 'danger';

const props = withDefaults(
  defineProps<{
    value?: number;
    max?: number;
    indeterminate?: boolean;
    size?: CircularSize;
    tone?: CircularTone;
    /** Show the computed percentage in the center (determinate only). */
    showPercentage?: boolean;
    /** Accessible label for the ring. */
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

// px diameter + stroke width per size.
const DIAMETER: Record<CircularSize, number> = { sm: 32, md: 48, lg: 72, xl: 112 };
const STROKE: Record<CircularSize, number> = { sm: 3, md: 4, lg: 6, xl: 8 };

const TONE_CLASS: Record<CircularTone, string> = {
  primary: 'text-next-primary',
  success: 'text-next-success',
  warning: 'text-next-warning',
  danger: 'text-next-danger',
};

const LABEL_TEXT: Record<CircularSize, string> = {
  sm: 'text-next-2xs',
  md: 'text-next-xs',
  lg: 'text-next-base',
  xl: 'text-next-2xl',
};

const diameter = computed(() => DIAMETER[props.size]);
const stroke = computed(() => STROKE[props.size]);
const radius = computed(() => (diameter.value - stroke.value) / 2);
const circumference = computed(() => 2 * Math.PI * radius.value);
const center = computed(() => diameter.value / 2);

const clamped = computed(() => Math.min(Math.max(props.value, 0), props.max));
const percent = computed(() =>
  props.max <= 0 ? 0 : Math.round((clamped.value / props.max) * 100),
);

// Determinate: offset the dash so the arc length matches the percentage.
const dashOffset = computed(() => circumference.value * (1 - percent.value / 100));
// Indeterminate: a fixed 25% arc that spins.
const indeterminateDash = computed(() => `${circumference.value * 0.25} ${circumference.value}`);
</script>

<template>
  <div
    class="next-circular relative inline-flex items-center justify-center"
    :style="{ width: `${diameter}px`, height: `${diameter}px` }"
    role="progressbar"
    :aria-valuemin="0"
    :aria-valuemax="max"
    :aria-valuenow="indeterminate ? undefined : clamped"
    :aria-label="ariaLabel"
    :aria-busy="indeterminate ? 'true' : undefined"
  >
    <svg
      :width="diameter"
      :height="diameter"
      :viewBox="`0 0 ${diameter} ${diameter}`"
      class="next-circular__svg -rotate-90"
      :class="indeterminate ? 'next-circular__spin' : ''"
      aria-hidden="true"
    >
      <!-- Track -->
      <circle
        :cx="center"
        :cy="center"
        :r="radius"
        fill="none"
        :stroke-width="stroke"
        class="text-next-muted"
        stroke="currentColor"
      />
      <!-- Indicator arc -->
      <circle
        :cx="center"
        :cy="center"
        :r="radius"
        fill="none"
        :stroke-width="stroke"
        stroke-linecap="round"
        :class="TONE_CLASS[tone]"
        stroke="currentColor"
        :stroke-dasharray="indeterminate ? indeterminateDash : circumference"
        :stroke-dashoffset="indeterminate ? 0 : dashOffset"
        :style="indeterminate ? undefined : { transition: 'stroke-dashoffset var(--duration-next-normal) var(--ease-next-standard)' }"
      />
    </svg>

    <span
      v-if="showPercentage && !indeterminate"
      class="absolute font-next-semibold tabular-nums text-next-fg"
      :class="LABEL_TEXT[size]"
      aria-hidden="true"
    >{{ percent }}%</span>
    <span
      v-else-if="$slots.default"
      class="absolute text-next-fg"
      :class="LABEL_TEXT[size]"
      aria-hidden="true"
    ><slot /></span>
  </div>
</template>

<style scoped>
.next-circular__spin {
  animation: next-circular-spin 1.1s linear infinite;
}
@keyframes next-circular-spin {
  /* Start at the -90deg base rotation so the arc spins from the top. */
  from {
    transform: rotate(-90deg);
  }
  to {
    transform: rotate(270deg);
  }
}
@media (prefers-reduced-motion: reduce) {
  .next-circular__spin {
    animation-duration: 2.5s;
  }
}
</style>
