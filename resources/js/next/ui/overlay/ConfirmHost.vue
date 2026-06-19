<script setup lang="ts">
// ConfirmHost — the single mounted host that renders imperative confirm dialogs
// requested via `useConfirm()`. Mount it ONCE near the app root (App.vue) or the
// gallery shell. It renders the current (last) request in the shared queue as a
// ConfirmDialog and drives confirm/cancel back through the queue helpers.
//
// Stacking: only the most-recent request is shown; if several are queued they
// resolve in LIFO order as each is dismissed. (Typical apps confirm one thing at
// a time, so this keeps the UX simple.)
import { computed } from 'vue';
import ConfirmDialog from './ConfirmDialog.vue';
import {
  useConfirmQueue,
  settleConfirm,
  dismissConfirm,
  type ConfirmRequest,
} from '../../app/composables/useConfirm';

const queue = useConfirmQueue();
const current = computed<ConfirmRequest | null>(
  () => queue.value[queue.value.length - 1] ?? null,
);

async function onConfirm(): Promise<void> {
  if (!current.value) return;
  try {
    await settleConfirm(current.value);
  } catch {
    /* rejection already propagated to the caller; keep host stable */
  }
}

function onCancel(): void {
  if (current.value) dismissConfirm(current.value);
}
</script>

<template>
  <ConfirmDialog
    v-if="current"
    :key="current.id"
    :open="true"
    :title="current.title"
    :message="current.message"
    :confirm-label="current.confirmLabel"
    :cancel-label="current.cancelLabel"
    :variant="current.variant"
    :loading="current.loading"
    @confirm="onConfirm"
    @cancel="onCancel"
  />
</template>
