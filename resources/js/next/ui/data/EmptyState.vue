<script setup lang="ts">
// EmptyState — a centered "nothing here" panel for the "next" frontend.
//
// Renders an icon in a tinted bubble, a title, an optional description, and
// action affordances: a primary action via the `#action` slot (drop a Button in)
// plus an optional secondary link via the `#secondary` slot.
//
// Variants:
//   • `default`          — neutral, for empty lists / first-run states.
//   • `search`           — "no results"; pass a `clear-filters` handler to expose
//                          a "Clear filters" affordance.
//   • `error`            — danger tint + an `#action` retry button.
//
// Sizes:
//   • `sm` — compact, for in-table / in-card empties (the Table empty state).
//   • `md` — page-level (default).
//
// A11y: the icon is decorative; the title is a real heading. The error variant is
// announced via `role="alert"` so a failed load is read out.
import { computed } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';

type EmptyVariant = 'default' | 'search' | 'error';
type EmptySize = 'sm' | 'md';

const props = withDefaults(
  defineProps<{
    variant?: EmptyVariant;
    size?: EmptySize;
    title: string;
    description?: string;
    /** Icon override; each variant has a sensible default. */
    icon?: IconName;
  }>(),
  {
    variant: 'default',
    size: 'md',
  },
);

const DEFAULT_ICON: Record<EmptyVariant, IconName> = {
  default: 'inbox',
  search: 'search',
  error: 'alert-triangle',
};

const resolvedIcon = computed(() => props.icon ?? DEFAULT_ICON[props.variant]);
const isError = computed(() => props.variant === 'error');

const bubbleClass = computed(() =>
  isError.value
    ? 'bg-next-danger-subtle text-next-danger-subtle-foreground'
    : 'bg-next-muted text-next-muted-foreground',
);

const SIZE: Record<EmptySize, { pad: string; bubble: string; icon: string; title: string }> = {
  sm: {
    pad: 'gap-next-2 py-next-6 px-next-4',
    bubble: 'h-10 w-10',
    icon: 'text-next-lg',
    title: 'text-next-sm',
  },
  md: {
    pad: 'gap-next-3 py-next-10 px-next-6',
    bubble: 'h-14 w-14',
    icon: 'text-next-2xl',
    title: 'text-next-base',
  },
};

const sz = computed(() => SIZE[props.size]);
</script>

<template>
  <div
    class="next-empty-state flex flex-col items-center text-center"
    :class="sz.pad"
    :role="isError ? 'alert' : undefined"
  >
    <span
      class="flex shrink-0 items-center justify-center rounded-next-full"
      :class="[bubbleClass, sz.bubble]"
      aria-hidden="true"
    >
      <Icon :name="resolvedIcon" :class="sz.icon" />
    </span>

    <h3 class="font-next-semibold text-next-fg" :class="sz.title">{{ title }}</h3>

    <p
      v-if="description"
      class="max-w-sm text-next-sm text-next-muted-foreground"
    >
      {{ description }}
    </p>

    <!-- Primary action (drop a Button in) — e.g. "New item" / "Retry" / "Clear filters". -->
    <div v-if="$slots.action" class="mt-next-2 flex flex-col items-center gap-next-2">
      <slot name="action" />
      <slot name="secondary" />
    </div>
    <div v-else-if="$slots.secondary" class="mt-next-1">
      <slot name="secondary" />
    </div>
  </div>
</template>
