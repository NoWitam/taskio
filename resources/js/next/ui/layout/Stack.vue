<script setup lang="ts">
// Stack primitive for the "next" frontend.
//
// The workhorse spacing primitive: a flexbox that lays its children out in a
// row or column with a token-driven gap, plus alignment / justification / wrap
// controls. Replaces ad-hoc `flex gap-*` clusters so spacing stays on the
// 4px scale and reads consistently everywhere.
//
// `gap` accepts a spacing token key (matching `--spacing-next-*`), so callers
// reference the scale by name (`4` → 16px) rather than raw pixels.
import { computed } from 'vue';

type StackDirection = 'vertical' | 'horizontal';
type SpacingKey =
  | '0'
  | '0_5'
  | '1'
  | '1_5'
  | '2'
  | '2_5'
  | '3'
  | '4'
  | '5'
  | '6'
  | '8'
  | '10'
  | '12'
  | '16';
type StackAlign = 'start' | 'center' | 'end' | 'stretch' | 'baseline';
type StackJustify = 'start' | 'center' | 'end' | 'between' | 'around' | 'evenly';

const props = withDefaults(
  defineProps<{
    /** Main axis: column (vertical) or row (horizontal). */
    direction?: StackDirection;
    /** Gap between children — a `--spacing-next-*` token key. */
    gap?: SpacingKey;
    /** Cross-axis alignment (align-items). */
    align?: StackAlign;
    /** Main-axis distribution (justify-content). */
    justify?: StackJustify;
    /** Allow children to wrap onto multiple lines. */
    wrap?: boolean;
    /** Rendered element. */
    as?: string;
    /** Stretch to fill the parent's main-axis length. */
    inline?: boolean;
  }>(),
  {
    direction: 'vertical',
    gap: '4',
    wrap: false,
    as: 'div',
    inline: false,
  },
);

// Map a spacing key to its namespaced gap utility. Listed explicitly so Tailwind
// can statically detect every class (no dynamic string interpolation).
const GAP_CLASS: Record<SpacingKey, string> = {
  '0': 'gap-next-0',
  '0_5': 'gap-next-0_5',
  '1': 'gap-next-1',
  '1_5': 'gap-next-1_5',
  '2': 'gap-next-2',
  '2_5': 'gap-next-2_5',
  '3': 'gap-next-3',
  '4': 'gap-next-4',
  '5': 'gap-next-5',
  '6': 'gap-next-6',
  '8': 'gap-next-8',
  '10': 'gap-next-10',
  '12': 'gap-next-12',
  '16': 'gap-next-16',
};

const ALIGN_CLASS: Record<StackAlign, string> = {
  start: 'items-start',
  center: 'items-center',
  end: 'items-end',
  stretch: 'items-stretch',
  baseline: 'items-baseline',
};

const JUSTIFY_CLASS: Record<StackJustify, string> = {
  start: 'justify-start',
  center: 'justify-center',
  end: 'justify-end',
  between: 'justify-between',
  around: 'justify-around',
  evenly: 'justify-evenly',
};

const classes = computed(() => [
  props.inline ? 'inline-flex' : 'flex',
  props.direction === 'horizontal' ? 'flex-row' : 'flex-col',
  GAP_CLASS[props.gap],
  props.align ? ALIGN_CLASS[props.align] : '',
  props.justify ? JUSTIFY_CLASS[props.justify] : '',
  props.wrap ? 'flex-wrap' : '',
]);
</script>

<template>
  <component :is="as" :class="classes">
    <slot />
  </component>
</template>
