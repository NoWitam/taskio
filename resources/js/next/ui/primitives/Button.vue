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
//
// Split button („przybornik"): pass `menuItems` to attach a chevron segment on the
// right that opens a DropdownMenu of secondary actions (e.g. Save / Save as…).
// The main segment keeps emitting `click`; picking an item emits
// `menu-select(value)`. Without `menuItems` the component renders EXACTLY as
// before (single root) — the group wrapper exists only for split buttons.
// Unsupported with `href`/the `link` variant (dev-warn, falls back to a plain
// button). `menuAriaLabel` is required — it names the chevron for screen readers.
import { computed, useSlots } from 'vue';
import Icon, { type IconName } from './Icon.vue';
import Spinner from './Spinner.vue';
import DropdownMenu from '../overlay/DropdownMenu.vue';
import DropdownMenuItem from '../overlay/DropdownMenuItem.vue';

/** One secondary action in a split button's menu. */
export interface ButtonMenuItem {
  value: string;
  label: string;
  icon?: IconName;
  disabled?: boolean;
  /** Danger styling for destructive actions (maps to DropdownMenuItem's destructive). */
  destructive?: boolean;
}

type ButtonVariant =
  | 'primary'
  | 'secondary'
  | 'outline'
  | 'ghost'
  | 'subtle'
  | 'danger'
  | 'link';
// `icon` is the standard 40px square icon button; `icon-sm` (32px) / `icon-xs`
// (28px) are the compact square variants for dense affordances (overlay close
// buttons, inline row actions) where the 40px control is too heavy.
type ButtonSize = 'xs' | 'sm' | 'md' | 'lg' | 'icon' | 'icon-sm' | 'icon-xs';

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
    /** Split button: secondary actions behind a chevron segment on the right. */
    menuItems?: ButtonMenuItem[];
    /** Accessible name for the chevron segment (required with `menuItems`). */
    menuAriaLabel?: string;
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

const emit = defineEmits<{
  (e: 'click', event: MouseEvent): void;
  (e: 'menu-select', value: string): void;
}>();

const slots = useSlots();

const isLink = computed(() => props.href !== undefined);
const isIconOnly = computed(
  () =>
    props.size === 'icon' || props.size === 'icon-sm' || props.size === 'icon-xs',
);
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
  'icon-sm': 'h-8 w-8 text-next-base justify-center rounded-next-md',
  'icon-xs': 'h-7 w-7 text-next-sm justify-center rounded-next-md',
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
  props.variant === 'link' && isIconOnly.value ? SIZE_CLASS[props.size] : '',
  props.fullWidth ? 'w-full' : '',
  isInert.value ? 'opacity-60 pointer-events-none cursor-not-allowed' : 'cursor-pointer',
]);

// Spinner size tuned to each control size.
const spinnerSize = computed<'xs' | 'sm' | 'md'>(() => {
  if (props.size === 'xs' || props.size === 'sm') return 'xs';
  if (props.size === 'lg') return 'md';
  return 'sm';
});

// --- Split button („przybornik") --------------------------------------------
// Active only for real buttons: anchors and the link variant have no sensible
// split shape, so they warn (dev) and fall back to the single-root render.
const hasMenu = computed(
  () => !!props.menuItems?.length && !isLink.value && props.variant !== 'link',
);

// The main segment inside the group: same look, squared right edge; fullWidth
// stretches the SEGMENT (the wrapper owns w-full), never the whole classes list.
const mainSegmentClasses = computed(() => [
  baseClass,
  VARIANT_CLASS[props.variant],
  SIZE_CLASS[props.size],
  'rounded-r-none',
  props.fullWidth ? 'flex-1' : '',
  isInert.value ? 'opacity-60 pointer-events-none cursor-not-allowed' : 'cursor-pointer',
]);

// Chevron segment: a square of the control's height, squared left edge, with a
// subtle divider toward the main segment (tone follows the variant's surface).
const CHEVRON_SIZE: Record<ButtonSize, string> = {
  xs: 'h-7 w-7 text-next-xs rounded-next-md',
  sm: 'h-8 w-8 text-next-sm rounded-next-md',
  md: 'h-10 w-10 text-next-sm rounded-next-md',
  lg: 'h-12 w-12 text-next-base rounded-next-md',
  icon: 'h-10 w-10 text-next-lg rounded-next-md',
  'icon-sm': 'h-8 w-8 text-next-base rounded-next-md',
  'icon-xs': 'h-7 w-7 text-next-sm rounded-next-md',
};

const MENU_DIVIDER: Record<ButtonVariant, string> = {
  primary: 'border-l border-next-primary-foreground/25',
  secondary: 'border-l border-next-border',
  outline: 'border-l border-next-input',
  ghost: 'border-l border-next-border/60',
  subtle: 'border-l border-next-border/60',
  danger: 'border-l border-next-danger-foreground/25',
  link: '',
};

const chevronClasses = computed(() => [
  baseClass,
  VARIANT_CLASS[props.variant],
  CHEVRON_SIZE[props.size],
  'rounded-l-none',
  MENU_DIVIDER[props.variant],
  isInert.value ? 'opacity-60 pointer-events-none cursor-not-allowed' : 'cursor-pointer',
]);

// DropdownMenu's trigger props type 'aria-expanded' as a plain string; a native <button> wants
// Booleanish — narrow the shape so v-bind type-checks (the runtime values are already 'true'/'false').
type TriggerAttrs = Record<string, unknown> & {
  'aria-expanded'?: 'true' | 'false';
  'aria-haspopup'?: 'menu' | 'dialog' | 'listbox' | 'true';
};
const asTriggerAttrs = (attrs: object): TriggerAttrs => attrs as TriggerAttrs;

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
  if (props.menuItems?.length && (props.href !== undefined || props.variant === 'link')) {
    // eslint-disable-next-line no-console
    console.warn(
      '[next/Button] `menuItems` is not supported with `href` or the link variant — rendering a plain control.',
    );
  }
  if (props.menuItems?.length && !props.menuAriaLabel) {
    // eslint-disable-next-line no-console
    console.warn(
      '[next/Button] A split button requires `menuAriaLabel` to name the chevron segment for screen readers.',
    );
  }
}
</script>

<template>
  <div v-if="hasMenu" class="next-button-group inline-flex items-stretch" :class="fullWidth ? 'w-full' : ''">
    <button
      :class="mainSegmentClasses"
      :type="type"
      :disabled="disabled ? true : undefined"
      :aria-disabled="isInert ? 'true' : undefined"
      :aria-busy="loading ? 'true' : undefined"
      :aria-label="ariaLabel"
      @click="onClick"
    >
      <Spinner v-if="loading" :size="spinnerSize" tone="current" decorative />
      <Icon v-else-if="leadingIcon" :name="leadingIcon" />

      <span v-if="!isIconOnly"><slot /></span>
      <slot v-else />

      <Icon v-if="trailingIcon && !isIconOnly" :name="trailingIcon" />
    </button>

    <DropdownMenu placement="bottom-end" :aria-label="menuAriaLabel">
      <template #trigger="{ props: triggerProps }">
        <button
          v-bind="asTriggerAttrs(triggerProps)"
          type="button"
          :class="chevronClasses"
          :disabled="isInert ? true : undefined"
          :aria-label="menuAriaLabel"
        >
          <Icon name="chevron-down" />
        </button>
      </template>

      <DropdownMenuItem
        v-for="item in menuItems"
        :key="item.value"
        :icon="item.icon"
        :disabled="item.disabled"
        :destructive="item.destructive"
        :label="item.label"
        @select="emit('menu-select', item.value)"
      >
        {{ item.label }}
      </DropdownMenuItem>
    </DropdownMenu>
  </div>

  <component
    v-else
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
