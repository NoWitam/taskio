<script setup lang="ts">
// Alert — an INLINE message block for the "next" frontend.
//
// Stays in document flow (NOT a toast). Variants info|success|warning|danger map
// to a `*-subtle` surface + a `*-subtle-foreground` text + a matching leading
// icon, so meaning is never carried by color alone. Optional title, a default-slot
// body, an `#actions` slot, and an optional dismiss ✕ (translated aria-label,
// emits `dismiss`). Sizes sm|md.
//
// A11y: `role="status"` (polite) for info/success; `role="alert"` (assertive) for
// warning/danger so a problem is announced. The leading icon is decorative (the
// text + role carry the meaning).
import { computed } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import { useI18n } from '../../app/i18n';

type AlertVariant = 'info' | 'success' | 'warning' | 'danger';
type AlertSize = 'sm' | 'md';

const props = withDefaults(
  defineProps<{
    variant?: AlertVariant;
    size?: AlertSize;
    /** Optional bold title above the body. */
    title?: string;
    /** Render a focusable ✕ that emits `dismiss`. */
    dismissible?: boolean;
    /** Override the default leading icon. */
    icon?: IconName;
    /** aria-label for the dismiss control (defaults to a translated "Dismiss"). */
    dismissLabel?: string;
  }>(),
  {
    variant: 'info',
    size: 'md',
    dismissible: false,
  },
);

const emit = defineEmits<{ (e: 'dismiss'): void }>();

const { t } = useI18n();

const SURFACE_CLASS: Record<AlertVariant, string> = {
  info: 'bg-next-info-subtle text-next-info-subtle-foreground border-next-info-subtle',
  success: 'bg-next-success-subtle text-next-success-subtle-foreground border-next-success-subtle',
  warning: 'bg-next-warning-subtle text-next-warning-subtle-foreground border-next-warning-subtle',
  danger: 'bg-next-danger-subtle text-next-danger-subtle-foreground border-next-danger-subtle',
};

const DEFAULT_ICON: Record<AlertVariant, IconName> = {
  info: 'info',
  success: 'check-circle',
  warning: 'alert-triangle',
  danger: 'alert-circle',
};

const SIZE_CLASS: Record<AlertSize, string> = {
  sm: 'gap-next-2 p-next-3 text-next-xs rounded-next-md',
  md: 'gap-next-3 p-next-4 text-next-sm rounded-next-lg',
};

const iconName = computed<IconName>(() => props.icon ?? DEFAULT_ICON[props.variant]);
// Polite for info/success; assertive (role="alert") for warning/danger.
const role = computed(() =>
  props.variant === 'warning' || props.variant === 'danger' ? 'alert' : 'status',
);
const ariaLive = computed(() =>
  props.variant === 'warning' || props.variant === 'danger' ? 'assertive' : 'polite',
);
const dismissAria = computed(() => props.dismissLabel ?? t('alert.dismiss', 'Dismiss'));
</script>

<template>
  <div
    :role="role"
    :aria-live="ariaLive"
    class="next-alert flex items-start border"
    :class="[SURFACE_CLASS[variant], SIZE_CLASS[size]]"
  >
    <Icon :name="iconName" class="mt-px shrink-0 text-[1.1em]" />

    <div class="min-w-0 flex-1">
      <p v-if="title" class="font-next-semibold" :class="$slots.default ? 'mb-next-1' : ''">
        {{ title }}
      </p>
      <div v-if="$slots.default" class="opacity-90">
        <slot />
      </div>
      <div v-if="$slots.actions" class="mt-next-3 flex flex-wrap items-center gap-next-2">
        <slot name="actions" />
      </div>
    </div>

    <button
      v-if="dismissible"
      type="button"
      class="-mr-next-1 -mt-next-1 shrink-0 rounded-next-md p-next-1 text-current opacity-70 transition-opacity duration-[var(--duration-next-fast)] hover:opacity-100"
      :aria-label="dismissAria"
      @click="emit('dismiss')"
    >
      <Icon name="x" class="text-[1.1em]" />
    </button>
  </div>
</template>
