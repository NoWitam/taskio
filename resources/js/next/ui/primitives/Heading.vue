<script setup lang="ts">
// Heading primitive for the "next" frontend.
//
// Semantic heading level (`level`/`as`) is decoupled from visual size (`size`).
// This lets you keep a correct document outline (never skip levels for styling)
// while choosing the visual scale independently. Headings default to semibold +
// tight leading + tight tracking (set in base styles); color is token-driven.
import { computed } from 'vue';

type HeadingLevel = 1 | 2 | 3 | 4 | 5 | 6;
type HeadingSize = 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6';
type HeadingTone = 'default' | 'muted' | 'primary' | 'danger' | 'success';

const props = withDefaults(
  defineProps<{
    /** Semantic level → renders <h1>…<h6>. */
    level?: HeadingLevel;
    /** Visual size token. Defaults to match `level`. */
    size?: HeadingSize;
    tone?: HeadingTone;
    /** Use text-wrap: balance for tidy multi-line headings. */
    balance?: boolean;
    /** Single-line ellipsis. */
    truncate?: boolean;
  }>(),
  {
    level: 2,
    tone: 'default',
    balance: false,
    truncate: false,
  },
);

// h1 → 4xl … h6 → base (per the matrix).
const SIZE_CLASS: Record<HeadingSize, string> = {
  h1: 'text-next-4xl',
  h2: 'text-next-3xl',
  h3: 'text-next-2xl',
  h4: 'text-next-xl',
  h5: 'text-next-lg',
  h6: 'text-next-base',
};

const TONE_CLASS: Record<HeadingTone, string> = {
  default: 'text-next-fg',
  muted: 'text-next-muted-foreground',
  primary: 'text-next-primary',
  danger: 'text-next-danger',
  success: 'text-next-success',
};

const tag = computed(() => `h${props.level}`);
const resolvedSize = computed<HeadingSize>(
  () => props.size ?? (`h${props.level}` as HeadingSize),
);

const classes = computed(() => [
  'font-next-semibold',
  SIZE_CLASS[resolvedSize.value],
  TONE_CLASS[props.tone],
  props.balance ? '[text-wrap:balance]' : '',
  props.truncate ? 'truncate' : '',
]);
</script>

<template>
  <component :is="tag" :class="classes">
    <slot />
  </component>
</template>
