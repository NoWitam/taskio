<script setup lang="ts">
// Text primitive for the "next" frontend.
//
// A single component for body / UI / caption / lead / mono text with a tone
// scale, plus overflow controls (truncate single line, clamp N lines, no-wrap).
// All color comes from semantic tokens; dark mode is automatic.
//
// The rendered element is controlled by `as` (defaults to `<p>`; use `span`
// inline). Visual style is fully decoupled from the element so semantics stay
// correct.
import { computed } from 'vue';

type TextVariant = 'body' | 'ui' | 'caption' | 'lead' | 'mono';
type TextTone =
  | 'default'
  | 'muted'
  | 'primary'
  | 'danger'
  | 'success'
  | 'inverted';

const props = withDefaults(
  defineProps<{
    /** Visual role: maps to size + family + default weight. */
    variant?: TextVariant;
    /** Color role. */
    tone?: TextTone;
    /** Rendered element. */
    as?: string;
    /** Single-line ellipsis. */
    truncate?: boolean;
    /** Clamp to N lines (overrides truncate). */
    clamp?: number;
    /** Prevent wrapping (no ellipsis). */
    noWrap?: boolean;
  }>(),
  {
    variant: 'body',
    tone: 'default',
    as: 'p',
    truncate: false,
    noWrap: false,
  },
);

const VARIANT_CLASS: Record<TextVariant, string> = {
  body: 'text-next-base',
  ui: 'text-next-sm',
  caption: 'text-next-xs',
  lead: 'text-next-lg',
  mono: 'font-next-mono text-next-sm',
};

const TONE_CLASS: Record<TextTone, string> = {
  default: 'text-next-fg',
  muted: 'text-next-muted-foreground',
  primary: 'text-next-primary',
  danger: 'text-next-danger',
  success: 'text-next-success',
  inverted: 'text-next-primary-foreground',
};

const clampStyle = computed(() =>
  props.clamp
    ? {
        display: '-webkit-box',
        WebkitLineClamp: String(props.clamp),
        WebkitBoxOrient: 'vertical' as const,
        overflow: 'hidden',
      }
    : undefined,
);

const classes = computed(() => [
  VARIANT_CLASS[props.variant],
  TONE_CLASS[props.tone],
  // caption defaults to muted unless tone explicitly set non-default.
  props.variant === 'caption' && props.tone === 'default'
    ? 'text-next-muted-foreground'
    : '',
  props.truncate && !props.clamp ? 'truncate' : '',
  props.noWrap ? 'whitespace-nowrap' : '',
]);
</script>

<template>
  <component :is="as" :class="classes" :style="clampStyle">
    <slot />
  </component>
</template>
