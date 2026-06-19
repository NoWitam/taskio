<script setup lang="ts">
// Badge primitive for the "next" frontend.
//
// A compact status/label chip. Each color family has a `solid` and a `subtle`
// tone; all color comes from semantic tokens, so dark mode is automatic.
//
// State is never color-only: pair a status badge with text, a leading icon, or
// a dot. A `removable` badge renders a focusable ✕ with an `aria-label` and
// emits `remove` on click or Enter/Space.
import { computed } from 'vue';
import Icon, { type IconName } from './Icon.vue';

type BadgeVariant =
  | 'neutral'
  | 'primary'
  | 'success'
  | 'warning'
  | 'danger'
  | 'info';
type BadgeTone = 'solid' | 'subtle';
type BadgeSize = 'sm' | 'md';

const props = withDefaults(
  defineProps<{
    variant?: BadgeVariant;
    tone?: BadgeTone;
    size?: BadgeSize;
    /** Leading icon name. */
    icon?: IconName;
    /** Render a small status dot before the label. */
    dot?: boolean;
    /** Render a focusable ✕ that emits `remove`. */
    removable?: boolean;
    /** aria-label for the remove control. */
    removeLabel?: string;
    /** Constrain width + ellipsis the label. */
    truncate?: boolean;
  }>(),
  {
    variant: 'neutral',
    tone: 'subtle',
    size: 'md',
    dot: false,
    removable: false,
    removeLabel: 'Remove',
    truncate: false,
  },
);

const emit = defineEmits<{ (e: 'remove'): void }>();

// solid + subtle token pairs per family. Neutral has no status token, so it
// borrows muted / fg.
const SOLID_CLASS: Record<BadgeVariant, string> = {
  neutral: 'bg-next-muted text-next-fg',
  primary: 'bg-next-primary text-next-primary-foreground',
  success: 'bg-next-success text-next-success-foreground',
  warning: 'bg-next-warning text-next-warning-foreground',
  danger: 'bg-next-danger text-next-danger-foreground',
  info: 'bg-next-info text-next-info-foreground',
};

const SUBTLE_CLASS: Record<BadgeVariant, string> = {
  neutral: 'bg-next-muted text-next-muted-foreground',
  primary: 'bg-next-primary-subtle text-next-primary-subtle-foreground',
  success: 'bg-next-success-subtle text-next-success-subtle-foreground',
  warning: 'bg-next-warning-subtle text-next-warning-subtle-foreground',
  danger: 'bg-next-danger-subtle text-next-danger-subtle-foreground',
  info: 'bg-next-info-subtle text-next-info-subtle-foreground',
};

// The dot color for each family (always reads against the chip background).
const DOT_CLASS: Record<BadgeVariant, string> = {
  neutral: 'bg-next-muted-foreground',
  primary: 'bg-next-primary',
  success: 'bg-next-success',
  warning: 'bg-next-warning',
  danger: 'bg-next-danger',
  info: 'bg-next-info',
};

const SIZE_CLASS: Record<BadgeSize, string> = {
  sm: 'h-5 px-next-1_5 text-next-2xs gap-next-1',
  md: 'h-6 px-next-2 text-next-xs gap-next-1',
};

const toneClass = computed(() =>
  props.tone === 'solid' ? SOLID_CLASS[props.variant] : SUBTLE_CLASS[props.variant],
);
const dotClass = computed(() =>
  props.tone === 'solid' ? 'bg-current opacity-80' : DOT_CLASS[props.variant],
);

const classes = computed(() => [
  'next-badge inline-flex items-center rounded-next-full font-next-medium align-middle',
  SIZE_CLASS[props.size],
  toneClass.value,
  props.truncate ? 'max-w-[12ch]' : '',
]);

function onRemove(): void {
  emit('remove');
}
</script>

<template>
  <span :class="classes">
    <span
      v-if="dot"
      class="inline-block shrink-0 rounded-next-full"
      :class="dotClass"
      style="height: 0.375rem; width: 0.375rem"
      aria-hidden="true"
    />
    <Icon v-if="icon" :name="icon" class="shrink-0" />
    <!-- A chip label is single-line always (never wraps to a second line); when
         truncating it also ellipsizes. -->
    <span :class="truncate ? 'truncate' : 'whitespace-nowrap'"><slot /></span>
    <button
      v-if="removable"
      type="button"
      class="ml-next-0_5 -mr-next-1 inline-flex shrink-0 items-center justify-center rounded-next-full p-[1px] transition-colors duration-[var(--duration-next-fast)] hover:bg-next-fg/15"
      :aria-label="removeLabel"
      @click.stop="onRemove"
    >
      <Icon name="x" class="text-[0.85em]" :stroke-width="2.5" />
    </button>
  </span>
</template>
