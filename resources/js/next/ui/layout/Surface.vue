<script setup lang="ts">
// Surface primitive for the "next" frontend.
//
// The low-level themed background that higher-level surfaces (Card, popovers,
// panels) build on. It owns four orthogonal visual axes — background role,
// border on/off, corner radius, and elevation (shadow) — all driven by semantic
// tokens, so dark mode is automatic. It carries NO padding or layout of its own;
// compose Stack/Grid inside it.
import { computed } from 'vue';

type SurfaceBg = 'bg' | 'card' | 'muted' | 'popover' | 'accent' | 'transparent';
type SurfaceRadius = 'none' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | 'full';
type SurfaceElevation = 'none' | 'xs' | 'sm' | 'md' | 'lg' | 'xl';

const props = withDefaults(
  defineProps<{
    /** Background role (paired foreground is applied where one exists). */
    bg?: SurfaceBg;
    /** Render a 1px hairline border using the border token. */
    border?: boolean;
    /** Corner radius token. */
    radius?: SurfaceRadius;
    /** Shadow/elevation token. Dark mode leans on border + bg lightness. */
    elevation?: SurfaceElevation;
    /** Rendered element. */
    as?: string;
  }>(),
  {
    bg: 'card',
    border: false,
    radius: 'lg',
    elevation: 'none',
    as: 'div',
  },
);

const BG_CLASS: Record<SurfaceBg, string> = {
  bg: 'bg-next-bg text-next-fg',
  card: 'bg-next-card text-next-card-foreground',
  muted: 'bg-next-muted text-next-fg',
  popover: 'bg-next-popover text-next-popover-foreground',
  accent: 'bg-next-accent text-next-accent-foreground',
  transparent: 'bg-transparent',
};

const RADIUS_CLASS: Record<SurfaceRadius, string> = {
  none: 'rounded-next-none',
  sm: 'rounded-next-sm',
  md: 'rounded-next-md',
  lg: 'rounded-next-lg',
  xl: 'rounded-next-xl',
  '2xl': 'rounded-next-2xl',
  full: 'rounded-next-full',
};

const ELEVATION_CLASS: Record<SurfaceElevation, string> = {
  none: '',
  xs: 'shadow-next-xs',
  sm: 'shadow-next-sm',
  md: 'shadow-next-md',
  lg: 'shadow-next-lg',
  xl: 'shadow-next-xl',
};

const classes = computed(() => [
  BG_CLASS[props.bg],
  RADIUS_CLASS[props.radius],
  ELEVATION_CLASS[props.elevation],
  props.border ? 'border border-next-border' : '',
]);
</script>

<template>
  <component :is="as" :class="classes">
    <slot />
  </component>
</template>
