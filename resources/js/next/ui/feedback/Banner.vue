<script setup lang="ts">
// Banner — a PAGE/APP-LEVEL full-width announcement bar for the "next" frontend.
//
// Distinct from the inline Alert: a Banner spans the full width of its container
// (full-bleed), is denser, and is meant for app-wide notices (maintenance, trial
// expiry, a new feature). Variants neutral|info|primary|warning|danger drive a
// solid-ish tinted bar + a matching leading icon (color is never the only signal).
// Optional `#actions`, dismissible ✕, and `sticky` to pin it to the top of the
// scroll container.
//
// A11y: `role="status"` (polite) by default; `role="alert"` for warning/danger.
// The dismiss ✕ has a translated aria-label and emits `dismiss`.
import { computed } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import { useI18n } from '../../app/i18n';

type BannerVariant = 'neutral' | 'info' | 'primary' | 'warning' | 'danger';

const props = withDefaults(
  defineProps<{
    variant?: BannerVariant;
    /** Override the default leading icon. Pass `null`-like by omitting + hideIcon. */
    icon?: IconName;
    /** Hide the leading icon entirely. */
    hideIcon?: boolean;
    /** Render a focusable ✕ that emits `dismiss`. */
    dismissible?: boolean;
    /** Pin to the top of the scroll container. */
    sticky?: boolean;
    /** aria-label for the dismiss control (defaults to a translated "Dismiss"). */
    dismissLabel?: string;
  }>(),
  {
    variant: 'info',
    hideIcon: false,
    dismissible: false,
    sticky: false,
  },
);

const emit = defineEmits<{ (e: 'dismiss'): void }>();

const { t } = useI18n();

// Banners read a touch stronger than inline alerts: subtle surface for neutral/
// info, the subtle status tint for warning/danger, and the primary-subtle band
// for primary. All token-driven so dark mode is automatic.
const SURFACE_CLASS: Record<BannerVariant, string> = {
  neutral: 'bg-next-muted text-next-fg',
  info: 'bg-next-info-subtle text-next-info-subtle-foreground',
  primary: 'bg-next-primary-subtle text-next-primary-subtle-foreground',
  warning: 'bg-next-warning-subtle text-next-warning-subtle-foreground',
  danger: 'bg-next-danger-subtle text-next-danger-subtle-foreground',
};

const DEFAULT_ICON: Record<BannerVariant, IconName> = {
  neutral: 'info',
  info: 'info',
  primary: 'sparkles',
  warning: 'alert-triangle',
  danger: 'alert-circle',
};

const iconName = computed<IconName>(() => props.icon ?? DEFAULT_ICON[props.variant]);
const role = computed(() =>
  props.variant === 'warning' || props.variant === 'danger' ? 'alert' : 'status',
);
const ariaLive = computed(() =>
  props.variant === 'warning' || props.variant === 'danger' ? 'assertive' : 'polite',
);
const dismissAria = computed(() => props.dismissLabel ?? t('banner.dismiss', 'Dismiss'));
</script>

<template>
  <div
    :role="role"
    :aria-live="ariaLive"
    class="next-banner flex w-full items-center gap-next-3 border-b border-next-border px-next-4 py-next-2_5 text-next-sm"
    :class="[
      SURFACE_CLASS[variant],
      sticky ? 'sticky top-0 z-[var(--z-next-sticky)]' : '',
    ]"
  >
    <Icon v-if="!hideIcon" :name="iconName" class="shrink-0 text-[1.15em]" />

    <div class="min-w-0 flex-1">
      <slot />
    </div>

    <div v-if="$slots.actions" class="flex shrink-0 items-center gap-next-2">
      <slot name="actions" />
    </div>

    <button
      v-if="dismissible"
      type="button"
      class="-mr-next-1 shrink-0 rounded-next-md p-next-1 text-current opacity-70 transition-opacity duration-[var(--duration-next-fast)] hover:opacity-100"
      :aria-label="dismissAria"
      @click="emit('dismiss')"
    >
      <Icon name="x" class="text-[1.15em]" />
    </button>
  </div>
</template>
