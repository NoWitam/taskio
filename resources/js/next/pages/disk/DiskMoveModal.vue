<script setup lang="ts">
// DiskMoveModal — move a file or folder to another folder.
//
// The inline FolderPickerPanel's current level IS the destination (root included), so the
// footer confirms "Move here". When moving a FOLDER, the folder itself is excluded from the
// picker (excludeId) — you cannot navigate into it, which also keeps its descendants
// unreachable as targets; the backend still guards self/descendant/depth/name-collision and
// its 422 message is surfaced verbatim.
import { computed, ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import FolderPickerPanel from './FolderPickerPanel.vue';
import { useDiskStore } from '../../app/stores/disk';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';

export interface MoveTarget {
  kind: 'folder' | 'file';
  id: string;
  name: string;
}

const props = withDefaults(
  defineProps<{
    target: MoveTarget | null;
    /** The folder to pre-select as the destination (the level the move was started from). */
    defaultFolderId?: string | null;
  }>(),
  { defaultFolderId: null },
);

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const toast = useToast();
const store = useDiskStore();

const destination = ref<string | null>(null);
const moving = ref(false);

// Start the picker AT the current folder each time the dialog opens (the picker rebuilds its
// breadcrumb from there); the user navigates elsewhere to move.
watch(open, (isOpen) => {
  if (isOpen) destination.value = props.defaultFolderId;
});

const excludeId = computed(() => (props.target?.kind === 'folder' ? props.target.id : null));

async function confirmMove(): Promise<void> {
  const target = props.target;
  if (!target || moving.value) return;
  moving.value = true;
  try {
    if (target.kind === 'folder') await store.moveFolder(target.id, destination.value);
    else await store.moveFile(target.id, destination.value);
    open.value = false;
    toast.success(t('disk.browser.moved', 'Moved.'));
  } catch (err: unknown) {
    // The backend rejects a move into self / a descendant / past the depth cap / a name
    // collision with a granular message — show it rather than a generic failure.
    const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
    toast.danger(message ?? t('disk.browser.moveError', 'Could not move.'));
  } finally {
    moving.value = false;
  }
}
</script>

<template>
  <Modal v-model:open="open" size="md" :aria-label="t('disk.browser.moveTitle', 'Move')">
    <template #title>{{ t('disk.browser.moveTitle', 'Move') }}</template>

    <div class="flex flex-col gap-next-3">
      <p class="text-next-sm text-next-muted-foreground">
        {{ t('disk.browser.moveHint', 'Choose the destination folder for “{name}”.', { name: target?.name ?? '' }) }}
      </p>
      <!-- Re-mount per open (keyed on the target) so the picker starts at the root each time. -->
      <FolderPickerPanel
        v-if="open && target"
        :key="target.id"
        v-model="destination"
        :exclude-id="excludeId"
      />
    </div>

    <template #footer>
      <Button variant="outline" type="button" @click="open = false">
        {{ t('common.cancel', 'Cancel') }}
      </Button>
      <Button variant="primary" type="button" :loading="moving" @click="confirmMove">
        {{ t('disk.browser.moveHere', 'Move here') }}
      </Button>
    </template>
  </Modal>
</template>
