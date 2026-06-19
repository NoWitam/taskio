<script setup lang="ts">
// Button primitive for the "next" frontend.
//
// Renders a real <button> by default, or an <a> when `href` is provided (used by
// the `link` variant and any "button that navigates"). All color comes from
// semantic tokens; dark mode is handled entirely by token overrides.
//
// Loading: shows a Spinner in place of the leading icon, keeps the label, sets
// `aria-busy` and blocks activation. The control keeps its width (the label is
// not removed) so there is no layout shift.
//
// A11y: icon-only buttons (size `icon`, or no default-slot content) REQUIRE an
// `ariaLabel`. Disabled real buttons use the native `disabled` attribute;
// disabled links use `aria-disabled` + a prevented handler (anchors can't be
// natively disabled). Enter/Space activation is native to <button>.
import { computed, useSlots } from 'vue';
import Icon, { type IconName } from './Icon.vue';
import Spinner from './Spinner.vue';

type ButtonVariant =
  | 'primary'
  | 'secondary'
  | 'outline'
  | 'ghost'
  | 'subtle'
  | 'danger'
  | 'link';
type ButtonSize = 'xs' | 'sm' | 'md' | 'lg' | 'icon';

const props = withDefaults(
  defineProps<{
    variant?: ButtonVariant;
    size?: ButtonSize;
    /** Render an <a> instead of <button>. */
    href?: string;
    /** Anchor target; when `_blank`, adds rel="noopener noreferrer". */
    target?: string;
    /** Native button type (ignored for anchors). */
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
    /** Shows a spinner, blocks activation, sets aria-busy, preserves width. */
    loading?: boolean;
    /** Icon name shown before the label (replaced by the spinner while loading). */
    leadingIcon?: IconName;
    /** Icon name shown after the label. */
    trailingIcon?: IconName;
    /** Stretch to the full width of the container. */
    fullWidth?: boolean;
    /** Required for icon-only buttons (no visible text). */
    ariaLabel?: string;
  }>(),
  {
    variant: 'primary',
    size: 'md',
    type: 'button',
    disabled: false,
    loading: false,
    fullWidth: false,
  },
);

const emit = defineEmits<{ (e: 'click', event: MouseEvent): void }>();

const slots = useSlots();

const isLink = computed(() => props.href !== undefined);
const isIconOnly = computed(() => props.size === 'icon');
// "Inert" = visually/behaviorally disabled, whether via disabled or loading.
const isInert = computed(() => props.disabled || props.loading);

const VARIANT_CLASS: Record<ButtonVariant, string> = {
  primary:
    'bg-next-primary text-next-primary-foreground hover:bg-next-primary-hover active:bg-next-primary-active',
  secondary:
    'bg-next-muted text-next-fg hover:bg-next-accent hover:text-next-accent-foreground active:bg-next-accent',
  outline:
    'border border-next-input bg-transparent text-next-fg hover:bg-next-accent hover:text-next-accent-foreground active:bg-next-accent',
  ghost:
    'bg-transparent text-next-fg hover:bg-next-accent hover:text-next-accent-foreground active:bg-next-accent',
  subtle:
    'bg-next-primary-subtle text-next-primary-subtle-foreground hover:bg-next-accent hover:text-next-accent-foreground active:bg-next-accent',
  danger:
    'bg-next-danger text-next-danger-foreground hover:opacity-90 active:opacity-80',
  link:
    'bg-transparent text-next-primary underline-offset-4 hover:underline active:text-next-primary-active px-next-0 h-auto',
};

// Sizes: control height + horizontal padding + text size. `icon` is square.
const SIZE_CLASS: Record<ButtonSize, string> = {
  xs: 'h-7 px-next-2 text-next-xs gap-next-1 rounded-next-md',
  sm: 'h-8 px-next-3 text-next-sm gap-next-1_5 rounded-next-md',
  md: 'h-10 px-next-4 text-next-sm gap-next-2 rounded-next-md',
  lg: 'h-12 px-next-5 text-next-base gap-next-2 rounded-next-md',
  icon: 'h-10 w-10 text-next-lg justify-center rounded-next-md',
};

const baseClass =
  'next-button relative inline-flex items-center justify-center font-next-medium ' +
  'whitespace-nowrap select-none transition-colors duration-[var(--duration-next-fast)] ' +
  'ease-[var(--ease-next-standard)]';

const classes = computed(() => [
  baseClass,
  VARIANT_CLASS[props.variant],
  // `link` variant manages its own height/padding.
  props.variant === 'link' ? '' : SIZE_CLASS[props.size],
  props.variant === 'link' && props.size === 'icon' ? SIZE_CLASS.icon : '',
  props.fullWidth ? 'w-full' : '',
  isInert.value ? 'opacity-60 pointer-events-none cursor-not-allowed' : 'cursor-pointer',
]);

// Spinner size tuned to each control size.
const spinnerSize = computed<'xs' | 'sm' | 'md'>(() => {
  if (props.size === 'xs' || props.size === 'sm') return 'xs';
  if (props.size === 'lg') return 'md';
  return 'sm';
});

function onClick(event: MouseEvent): void {
  if (isInert.value) {
    event.preventDefault();
    event.stopPropagation();
    return;
  }
  emit('click', event);
}

// Validate icon-only a11y in dev: a button with no visible text needs ariaLabel.
if (import.meta.env?.DEV) {
  const hasText = !!slots.default;
  if (!hasText && !props.ariaLabel) {
    // eslint-disable-next-line no-console
    console.warn(
      '[next/Button] An icon-only button (no default-slot text) must have an `ariaLabel` for screen readers.',
    );
  }
}
</script>

<template>
  <component
    :is="isLink ? 'a' : 'button'"
    :class="classes"
    :type="isLink ? undefined : type"
    :href="isLink && !isInert ? href : undefined"
    :target="isLink ? target : undefined"
    :rel="isLink && target === '_blank' ? 'noopener noreferrer' : undefined"
    :disabled="!isLink && disabled ? true : undefined"
    :aria-disabled="isInert ? 'true' : undefined"
    :aria-busy="loading ? 'true' : undefined"
    :aria-label="ariaLabel"
    @click="onClick"
  >
    <!-- Leading slot: spinner while loading, else leading icon. -->
    <Spinner v-if="loading" :size="spinnerSize" tone="current" decorative />
    <Icon v-else-if="leadingIcon" :name="leadingIcon" />

    <span v-if="!isIconOnly"><slot /></span>
    <slot v-else />

    <Icon v-if="trailingIcon && !isIconOnly" :name="trailingIcon" />
  </component>
</template>
