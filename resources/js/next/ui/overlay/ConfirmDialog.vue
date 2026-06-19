<script setup lang="ts">
// ConfirmDialog — a confirm/cancel dialog built on Modal (next frontend).
//
// Two ways to use it:
//   1. Declaratively with `v-model:open` + `@confirm` / `@cancel`.
//   2. Imperatively via `useConfirm()` (see useConfirm.ts) which mounts a single
//      shared host and resolves a promise.
//
// `variant="danger"` styles the confirm button as destructive and focuses the
// CANCEL button by default (safer for irreversible actions). `loading` keeps the
// dialog open and shows a spinner on the confirm button while an async action
// runs; Escape / scrim / cancel are blocked while loading.
//
// A11y: inherits Modal's dialog semantics (role, aria-modal, labelled title +
// described message, focus trap, topmost-only dismissal).
import { computed, nextTick, ref, watch } from 'vue';
import Modal from './Modal.vue';
import Button from '../primitives/Button.vue';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    title?: string;
    message?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    variant?: 'default' | 'danger';
    /** Confirm button shows a spinner + blocks dismissal while true. */
    loading?: boolean;
  }>(),
  {
    variant: 'default',
    loading: false,
  },
);

// i18n-defaulted labels (overridable via props).
const titleText = computed(() => props.title ?? t('confirm.title', 'Are you sure?'));
const confirmLabelText = computed(() => props.confirmLabel ?? t('confirm.confirm', 'Confirm'));
const cancelLabelText = computed(() => props.cancelLabel ?? t('confirm.cancel', 'Cancel'));

const emit = defineEmits<{
  (e: 'confirm'): void;
  (e: 'cancel'): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const confirmWrap = ref<HTMLElement | null>(null);
const cancelWrap = ref<HTMLElement | null>(null);

const confirmVariant = computed(() => (props.variant === 'danger' ? 'danger' : 'primary'));

// Focus the safe default: cancel for destructive actions, confirm otherwise.
watch(open, (isOpen) => {
  if (!isOpen) return;
  nextTick(() => {
    requestAnimationFrame(() => {
      const wrap = props.variant === 'danger' ? cancelWrap.value : confirmWrap.value;
      wrap?.querySelector('button')?.focus({ preventScroll: true });
    });
  });
});

function onConfirm(): void {
  if (props.loading) return;
  emit('confirm');
}

// Cancel button just requests close; the Modal `@close` is the single source of
// the `cancel` event (so Esc / scrim / button all funnel through one path).
function onCancel(): void {
  if (props.loading) return;
  open.value = false;
}
</script>

<template>
  <Modal
    v-model:open="open"
    size="sm"
    :show-close="false"
    :close-on-esc="!loading"
    :close-on-scrim="!loading"
    @close="emit('cancel')"
  >
    <template #title>{{ titleText }}</template>
    <template v-if="message || $slots.default" #description>
      <slot>{{ message }}</slot>
    </template>

    <template #footer>
      <span ref="cancelWrap" class="contents">
        <Button variant="outline" :disabled="loading" @click="onCancel">
          {{ cancelLabelText }}
        </Button>
      </span>
      <span ref="confirmWrap" class="contents">
        <Button :variant="confirmVariant" :loading="loading" @click="onConfirm">
          {{ confirmLabelText }}
        </Button>
      </span>
    </template>
  </Modal>
</template>
