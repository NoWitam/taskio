<script setup lang="ts">
// Skeleton — a loading placeholder primitive for the "next" frontend.
//
// Renders a token-driven, subtly pulsing shape that graphically MIMICS the real
// element it stands in for while content loads. Three variants:
//   • `text`   — a line with rounded ends; `width` (CSS length / %) configurable.
//   • `circle` — a circle of `diameter` (icon / avatar placeholder).
//   • `rect`   — a `width` × `height` block with a `radius` token.
//
// PROJECT RULES (now permanent design-system rules):
//   1. Skeletons REPLACE Spinner + "Loading…" for cursor-pagination loading
//      (e.g. Select's async initial + "loading more" rows render option-shaped
//      skeletons, not a spinner).
//   2. Every skeleton placement must graphically mimic the actual element that
//      will replace it, and usually SEVERAL are shown — compose skeletons in
//      item-shaped layouts, or pass `count` to repeat this shape N times.
//
// A11y: individual shapes are `aria-hidden` (pure decoration). Wrap a loading
// REGION by passing `label` (+ optional `role="status"`, the default for a region)
// so screen-reader users hear a single polite "Loading…" announcement instead of
// nothing. The pulse animation respects reduced motion (the global rule in
// `.next-root` collapses it; a local fallback is included for isolated rendering).
import { computed } from 'vue';

type SkeletonVariant = 'text' | 'circle' | 'rect';

const props = withDefaults(
  defineProps<{
    /** Shape. `text` (line), `circle` (diameter), `rect` (w×h). */
    variant?: SkeletonVariant;
    /** Width for `text`/`rect` (CSS length or %). Default 100%. */
    width?: string | number;
    /** Height for `rect` (CSS length). `text` derives height from the line. */
    height?: string | number;
    /** Diameter for `circle` (CSS length). Default 2.5rem. */
    diameter?: string | number;
    /** Corner radius token for `rect`. */
    radius?: 'sm' | 'md' | 'lg' | 'xl' | 'full' | 'none';
    /**
     * Repeat this shape N times in a vertical stack (the common "several lines /
     * several rows" case). Each repeat is `aria-hidden`.
     */
    count?: number;
    /**
     * Accessible label for a loading REGION. When set, the wrapper becomes a live
     * region (`role` below, default `status`) announcing this text; the shapes
     * stay `aria-hidden`. Omit for a single decorative shape inside an already
     * announced region.
     */
    label?: string;
    /** Region role when `label` is set. Default `status` (polite). */
    role?: 'status' | 'alert' | 'none';
  }>(),
  {
    variant: 'text',
    radius: 'md',
    count: 1,
    role: 'status',
  },
);

const RADIUS_VAR: Record<NonNullable<typeof props.radius>, string> = {
  none: '0',
  sm: 'var(--radius-next-sm)',
  md: 'var(--radius-next-md)',
  lg: 'var(--radius-next-lg)',
  xl: 'var(--radius-next-xl)',
  full: '9999px',
};

function len(value: string | number | undefined, fallback: string): string {
  if (value == null) return fallback;
  return typeof value === 'number' ? `${value}px` : value;
}

const shapeStyle = computed(() => {
  if (props.variant === 'circle') {
    const d = len(props.diameter, '2.5rem');
    return { width: d, height: d, borderRadius: '9999px' };
  }
  if (props.variant === 'rect') {
    return {
      width: len(props.width, '100%'),
      height: len(props.height, '4rem'),
      borderRadius: RADIUS_VAR[props.radius],
    };
  }
  // text: a line with rounded ends; height tracks the surrounding line-height.
  return {
    width: len(props.width, '100%'),
    height: len(props.height, '0.75em'),
    borderRadius: '9999px',
  };
});

const repeats = computed(() => Math.max(1, props.count));
const isRegion = computed(() => !!props.label);
const regionRole = computed(() => (props.role === 'none' ? undefined : props.role));
</script>

<template>
  <!-- Region: announces a single label; shapes inside stay decorative. -->
  <div
    v-if="isRegion"
    class="flex flex-col gap-next-2"
    :role="regionRole"
    aria-live="polite"
  >
    <span class="sr-only">{{ label }}</span>
    <span
      v-for="n in repeats"
      :key="n"
      class="next-skeleton block shrink-0"
      :style="shapeStyle"
      aria-hidden="true"
    />
  </div>

  <!-- Multiple shapes, no region wrapper. -->
  <div v-else-if="repeats > 1" class="flex flex-col gap-next-2">
    <span
      v-for="n in repeats"
      :key="n"
      class="next-skeleton block shrink-0"
      :style="shapeStyle"
      aria-hidden="true"
    />
  </div>

  <!-- Single decorative shape. -->
  <span
    v-else
    class="next-skeleton block shrink-0"
    :style="shapeStyle"
    aria-hidden="true"
  />
</template>

<style scoped>
/* Token-driven tint that works in light + dark; a subtle pulse via motion tokens.
   The base fill is the muted surface; a faint accent sheen reads as "shimmering".
   Color is never load-bearing here (it's decorative), so a plain muted tint is
   enough and stays legible on both card and muted backgrounds. */
.next-skeleton {
  background-color: var(--color-next-muted);
  background-image: linear-gradient(
    90deg,
    transparent 0%,
    color-mix(in srgb, var(--color-next-accent) 60%, transparent) 50%,
    transparent 100%
  );
  background-size: 200% 100%;
  animation: next-skeleton-pulse var(--duration-next-slow, 320ms) ease-in-out
    infinite alternate;
}

@keyframes next-skeleton-pulse {
  from {
    opacity: 0.7;
    background-position: 0% 0;
  }
  to {
    opacity: 1;
    background-position: 100% 0;
  }
}

/* Belt-and-braces reduced-motion fallback (the global `.next-root` rule already
   collapses token-driven animations). */
@media (prefers-reduced-motion: reduce) {
  .next-skeleton {
    animation: none;
    opacity: 0.85;
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
