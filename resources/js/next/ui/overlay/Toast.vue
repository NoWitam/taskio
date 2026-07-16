<script setup lang="ts">
// Toast — a single transient notification card (next frontend).
//
// Rendered by ToastViewport, not used directly. Shows a variant icon, a title,
// an optional description, an optional action button, and a manual dismiss (✕).
// Auto-dismiss timing + pause-on-hover are owned by the viewport; this component
// emits `dismiss` / `action` and surfaces hover so the viewport can pause.
//
// A11y: status conveyed by icon + text (never color alone); the icon is
// decorative because the title carries the meaning. Each card is its OWN live
// region — `role="alert"` + assertive for danger, `role="status"` + polite
// otherwise — so it announces on insertion.
import { computed } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import Button from '../primitives/Button.vue';
import type { ToastVariant, ToastAction } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();

const props = defineProps<{
  variant: ToastVariant;
  title: string;
  description?: string;
  action?: ToastAction;
}>();

const emit = defineEmits<{
  (e: 'dismiss'): void;
  (e: 'action'): void;
  (e: 'pause'): void;
  (e: 'resume'): void;
}>();

const ICON: Record<ToastVariant, IconName> = {
  success: 'check-circle',
  info: 'info',
  warning: 'alert-triangle',
  danger: 'alert-circle',
};

// Left accent + icon color per variant (text stays on the popover surface so the
// body is always legible; the colored strip + icon carry the status, with the
// title text giving the non-color signal).
const ACCENT: Record<ToastVariant, string> = {
  success: 'text-next-success',
  info: 'text-next-info',
  warning: 'text-next-warning',
  danger: 'text-next-danger',
};
const BORDER: Record<ToastVariant, string> = {
  success: 'before:bg-next-success',
  info: 'before:bg-next-info',
  warning: 'before:bg-next-warning',
  danger: 'before:bg-next-danger',
};

const iconName = computed(() => ICON[props.variant]);
const accentClass = computed(() => ACCENT[props.variant]);
const borderClass = computed(() => BORDER[props.variant]);

// Danger announces assertively (interrupts); everything else is polite.
const isAlert = computed(() => props.variant === 'danger');

function onAction(): void {
  emit('action');
}
</script>

<template>
  <div
    class="next-toast pointer-events-auto relative flex w-full items-start gap-next-3 overflow-hidden rounded-next-lg border border-next-border bg-next-popover px-next-3 py-next-3 pl-next-4 text-next-popover-foreground shadow-next-lg before:absolute before:inset-y-0 before:left-0 before:w-1"
    :class="borderClass"
    :role="isAlert ? 'alert' : 'status'"
    :aria-live="isAlert ? 'assertive' : 'polite'"
    @pointerenter="emit('pause')"
    @pointerleave="emit('resume')"
    @focusin="emit('pause')"
    @focusout="emit('resume')"
  >
    <Icon :name="iconName" class="mt-px shrink-0 text-next-lg" :class="accentClass" />

    <div class="min-w-0 flex-1">
      <p class="text-next-sm font-next-semibold">{{ title }}</p>
      <p v-if="description" class="mt-next-0_5 text-next-sm text-next-muted-foreground">
        {{ description }}
      </p>
      <Button
        v-if="action"
        variant="link"
        class="mt-next-2 text-next-sm"
        @click="onAction"
      >
        {{ action.label }}
      </Button>
    </div>

    <Button
      variant="ghost"
      size="icon-sm"
      class="-mr-next-1 -mt-next-1 shrink-0 text-next-muted-foreground"
      :aria-label="t('toast.dismiss', 'Dismiss notification')"
      @click="emit('dismiss')"
    >
      <Icon name="x" class="text-next-base" />
    </Button>
  </div>
</template>
