<script setup lang="ts">
// Spinner primitive for the "next" frontend.
//
// An indeterminate loading indicator. Drawn as an inline SVG ring with a single
// rotating arc so it inherits `currentColor` (the `current` tone) and can also
// take explicit semantic tones. Sizing is driven by font-size (`1em` → scales
// with the size utility). Spin animation is token-driven and collapses under
// `prefers-reduced-motion` (handled globally in `.next-root`).
//
// A11y: `role="status"` announces the busy state. A visually-hidden label is
// always rendered (defaults to "Loading…") so screen-reader users hear it; pass
// `showLabel` to surface it visibly next to the spinner.
import { computed } from 'vue';

type SpinnerSize = 'xs' | 'sm' | 'md' | 'lg';
type SpinnerTone = 'current' | 'primary' | 'muted' | 'on-overlay';

const props = withDefaults(
  defineProps<{
    /** Visual scale (drives font-size → svg size). */
    size?: SpinnerSize;
    /** Color source. `current` inherits the surrounding text color. */
    tone?: SpinnerTone;
    /** Accessible status text (also shown when `showLabel`). */
    label?: string;
    /** Render the label visibly next to the spinner. */
    showLabel?: boolean;
    /**
     * Mark the spinner as purely decorative (no `role="status"`, no label).
     * Use when the busy state is already announced by a parent (e.g. a button
     * with `aria-busy`).
     */
    decorative?: boolean;
  }>(),
  {
    size: 'md',
    tone: 'current',
    label: 'Loading…',
    showLabel: false,
    decorative: false,
  },
);

const SIZE_CLASS: Record<SpinnerSize, string> = {
  xs: 'text-next-xs',
  sm: 'text-next-sm',
  md: 'text-next-lg',
  lg: 'text-next-3xl',
};

const TONE_CLASS: Record<SpinnerTone, string> = {
  current: 'text-current',
  primary: 'text-next-primary',
  muted: 'text-next-muted-foreground',
  'on-overlay': 'text-next-primary-foreground',
};

const sizeClass = computed(() => SIZE_CLASS[props.size]);
const toneClass = computed(() => TONE_CLASS[props.tone]);
</script>

<template>
  <span
    class="next-spinner inline-flex items-center gap-next-2"
    :class="toneClass"
    :role="decorative ? undefined : 'status'"
    :aria-hidden="decorative ? 'true' : undefined"
  >
    <svg
      class="next-spinner__svg shrink-0"
      :class="sizeClass"
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
    >
      <circle
        cx="12"
        cy="12"
        r="9"
        stroke="currentColor"
        stroke-width="2.5"
        class="opacity-25"
      />
      <path
        d="M12 3a9 9 0 0 1 9 9"
        stroke="currentColor"
        stroke-width="2.5"
        stroke-linecap="round"
      />
    </svg>
    <template v-if="!decorative">
      <span v-if="showLabel" class="text-next-sm">{{ label }}</span>
      <span v-else class="sr-only">{{ label }}</span>
    </template>
  </span>
</template>

<style scoped>
.next-spinner__svg {
  width: 1em;
  height: 1em;
  animation: next-spin var(--duration-next-slow, 320ms) linear infinite;
  animation-duration: 0.7s;
}

@keyframes next-spin {
  to {
    transform: rotate(360deg);
  }
}

/* The global reduced-motion rule in next.css already collapses this animation;
   this is a belt-and-braces fallback in case the spinner renders outside a
   themed root during isolated testing. */
@media (prefers-reduced-motion: reduce) {
  .next-spinner__svg {
    animation-duration: 0.01ms;
    animation-iteration-count: 1;
  }
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
