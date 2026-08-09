<script setup lang="ts">
// KnowledgeStaleWriteModal — what the editor shows on a 409 `knowledge_stale_write`.
//
// A Modal, NOT a ConfirmDialog, because there are THREE ways out, not two, and a confirm dialog
// would force one of them to hide behind "cancel".
//
// The ordering is the design: actions run from safest to riskiest, and the PRIMARY one is the
// safest. The user just lost a race they did not know they were in — the default must not be the
// one that destroys someone else's work.
//   1. Keep my changes   → close, nothing sent, the draft stays in the editor (primary)
//   2. Open the latest   → a new tab, so the current draft is not navigated away from
//   3. Overwrite         → danger, and gated behind a SECOND confirmation
//
// Escape and the scrim both resolve to (1). Dismissal must never be the destructive branch.
import Modal from '../../ui/overlay/Modal.vue';
import Alert from '../../ui/feedback/Alert.vue';
import Button from '../../ui/primitives/Button.vue';
import Text from '../../ui/primitives/Text.vue';
import { useConfirm } from '../../app/composables/useConfirm';
import { useI18n } from '../../app/i18n';

defineProps<{
  /** Where "open the latest version" points. */
  latestHref?: string | null;
  overwriting?: boolean;
}>();

const emit = defineEmits<{
  (e: 'keep'): void;
  (e: 'overwrite'): void;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const confirm = useConfirm();

function onKeep(): void {
  emit('keep');
  open.value = false;
}

async function onOverwrite(): Promise<void> {
  // Second gate: the only branch that can cost somebody else their text.
  const ok = await confirm({
    title: t('knowledge.conflict.overwriteConfirm.title'),
    message: t('knowledge.conflict.overwriteConfirm.message'),
    confirmLabel: t('knowledge.conflict.overwrite'),
    cancelLabel: t('common.cancel'),
    variant: 'danger',
  });
  if (!ok) return;
  emit('overwrite');
}
</script>

<template>
  <Modal v-model:open="open" size="md" :aria-label="t('knowledge.conflict.title')" @close="onKeep">
    <template #title>{{ t('knowledge.conflict.title') }}</template>

    <div class="flex flex-col gap-next-3">
      <Text variant="body">{{ t('knowledge.conflict.message') }}</Text>

      <!-- Say it plainly: nothing has been lost, and nothing will be until they choose. -->
      <Alert variant="info" size="sm">{{ t('knowledge.conflict.safe') }}</Alert>
    </div>

    <template #footer>
      <div class="flex flex-wrap items-center justify-end gap-next-2">
        <Button
          v-if="latestHref"
          variant="outline"
          :href="latestHref"
          target="_blank"
          leading-icon="external-link"
        >
          {{ t('knowledge.conflict.openLatest') }}
        </Button>
        <Button variant="danger" :loading="overwriting" @click="onOverwrite">
          {{ t('knowledge.conflict.overwrite') }}
        </Button>
        <Button :disabled="overwriting" @click="onKeep">
          {{ t('knowledge.conflict.keep') }}
        </Button>
      </div>
    </template>
  </Modal>
</template>
