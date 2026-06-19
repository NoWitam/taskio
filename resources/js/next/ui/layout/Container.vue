<script setup lang="ts">
// Container primitive for the "next" frontend.
//
// A max-width content wrapper that centers its children and applies responsive
// horizontal gutters (page padding that grows from mobile → desktop). Purely
// structural: no background, border, or color of its own. Compose it inside a
// page/main region; nest Stack/Grid inside it for vertical/2-D rhythm.
//
// Sizes cap the readable/measure width; `full` removes the cap (still gutters).
import { computed } from 'vue';

type ContainerSize = 'sm' | 'md' | 'lg' | 'xl' | 'full';

const props = withDefaults(
  defineProps<{
    /** Max content width. `full` removes the cap (gutters still apply). */
    size?: ContainerSize;
    /** Rendered element (use `main`/`section` for landmark semantics). */
    as?: string;
    /** Remove the responsive horizontal gutters (flush to the edges). */
    flush?: boolean;
  }>(),
  {
    size: 'lg',
    as: 'div',
    flush: false,
  },
);

// Max-widths roughly map to the breakpoint scale so containers settle one step
// inside the viewport edge on large screens.
const SIZE_CLASS: Record<ContainerSize, string> = {
  sm: 'max-w-2xl', // ~672px — narrow reading column
  md: 'max-w-4xl', // ~896px — forms / settings
  lg: 'max-w-6xl', // ~1152px — default app content
  xl: 'max-w-7xl', // ~1280px — wide dashboards
  full: 'max-w-none', // no cap
};

// Responsive gutters: 16px mobile → 24px sm → 32px lg.
const GUTTER_CLASS = 'px-next-4 next-sm:px-next-6 next-lg:px-next-8';

const classes = computed(() => [
  'mx-auto w-full',
  SIZE_CLASS[props.size],
  props.flush ? '' : GUTTER_CLASS,
]);
</script>

<template>
  <component :is="as" :class="classes">
    <slot />
  </component>
</template>
