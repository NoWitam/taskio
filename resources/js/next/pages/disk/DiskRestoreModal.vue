<script setup lang="ts">
// DiskRestoreModal — restore a trashed file, per the P5 matrix.
//
// On open it asks the restore-preview endpoint where the file WOULD land:
//   • restorable in place → shows the original location (as breadcrumbs) and offers a
//     choice: original spot, or pick another folder (inline FolderPickerPanel);
//   • NOT restorable in place (a detached attachment, or its folder is gone/trashed) →
//     explains why and REQUIRES picking a target (the picker's level is the selection,
//     so the root is a valid pick).
//
// The wire mirrors the backend's presence contract: restoring in place OMITS
// target_folder_id entirely; picking sends it (null = the workspace root).
import { computed, ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import SegmentedControl, { type SegmentOption } from '../../ui/forms/SegmentedControl.vue';
import FolderPickerPanel from './FolderPickerPanel.vue';
import { useDiskStore } from '../../app/stores/disk';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import type { DiskFile, RestorePreview } from './types';

const props = defineProps<{
  file: DiskFile | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const toast = useToast();
const store = useDiskStore();

const preview = ref<RestorePreview | null>(null);
const previewLoading = ref(false);
const previewFailed = ref(false);
const choice = ref<'original' | 'pick'>('original');
const pickedFolderId = ref<string | null>(null);
const restoring = ref(false);

// (Re)load the preview each time the dialog opens for a file. A per-open token discards a
// slow preview for file A that resolves AFTER the user reopened the dialog for file B — the
// modal is a single persistent instance, so without this a stale A-preview could drive B's
// restore (wrong location, or an omitted/forced target that 422s at the backend).
let previewToken = 0;
watch(
  [open, () => props.file?.id],
  async ([isOpen]) => {
    if (!isOpen || !props.file) return;
    const myToken = ++previewToken;
    preview.value = null;
    previewFailed.value = false;
    previewLoading.value = true;
    choice.value = 'original';
    pickedFolderId.value = null;
    try {
      const data = await store.fetchRestorePreview(props.file.id);
      if (myToken !== previewToken) return; // superseded by a newer open
      preview.value = data;
      if (!data.can_restore_in_place) choice.value = 'pick';
    } catch {
      if (myToken !== previewToken) return;
      previewFailed.value = true;
    } finally {
      if (myToken === previewToken) previewLoading.value = false;
    }
  },
  { immediate: true },
);

const canInPlace = computed(() => preview.value?.can_restore_in_place ?? false);
const showPicker = computed(() => !canInPlace.value || choice.value === 'pick');

/**
 * 'Dysk / A / B' — the original location line (a root file ⇒ just 'Dysk'). The
 * preview's breadcrumbs are ANCESTORS only, so the original folder itself is
 * appended from its own field.
 */
const originalLocation = computed(() => {
  const names = (preview.value?.breadcrumbs ?? []).map((f) => f.name);
  const own = preview.value?.original_folder?.name;
  return [t('disk.title', 'Disk'), ...names, ...(own ? [own] : [])].join(' / ');
});

const reasonText = computed(() => {
  switch (preview.value?.reason) {
    case 'detached':
      return t('disk.trash.reasonDetached', 'This file came from another resource, so it has no folder of its own — pick where to restore it.');
    case 'folder_missing':
      return t('disk.trash.reasonFolderMissing', 'Its original folder no longer exists — pick where to restore it.');
    case 'folder_trashed':
      return t('disk.trash.reasonFolderTrashed', 'Its original folder is in the trash — pick where to restore it.');
    default:
      return '';
  }
});

const choiceSegments = computed<SegmentOption<'original' | 'pick'>[]>(() => [
  { value: 'original', label: t('disk.trash.restoreOriginal', 'Original spot') },
  { value: 'pick', label: t('disk.trash.restoreElsewhere', 'Another folder') },
]);

/** Restorable once the preview is in (in-place, or any picker level — the root counts). */
const canConfirm = computed(() => !!preview.value && !previewLoading.value && !restoring.value);

async function confirmRestore(): Promise<void> {
  if (!props.file || !canConfirm.value) return;
  restoring.value = true;
  try {
    const picking = showPicker.value;
    await store.restoreFile(props.file.id, {
      provided: picking,
      folderId: picking ? pickedFolderId.value : null,
    });
    open.value = false;
    toast.success(t('disk.trash.restored', 'File restored.'));
  } catch (err: unknown) {
    const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
    toast.danger(message ?? t('disk.trash.restoreError', 'Could not restore the file.'));
  } finally {
    restoring.value = false;
  }
}
</script>

<template>
  <Modal v-model:open="open" size="md" :aria-label="t('disk.trash.restoreTitle', 'Restore file')">
    <template #title>{{ t('disk.trash.restoreTitle', 'Restore file') }}</template>

    <div class="flex flex-col gap-next-4">
      <!-- The file being restored. -->
      <p class="truncate text-next-sm font-next-medium text-next-fg">{{ file?.name }}</p>

      <div v-if="previewLoading" class="flex flex-col gap-next-2">
        <Skeleton class="h-5 w-2/3 rounded-next-sm" />
        <Skeleton class="h-24 w-full rounded-next-md" />
      </div>

      <p v-else-if="previewFailed" class="flex items-start gap-next-2 text-next-sm text-next-danger" role="alert">
        <Icon name="alert-circle" class="mt-px shrink-0" aria-hidden="true" />
        {{ t('disk.trash.previewError', 'Could not check where this file would be restored.') }}
      </p>

      <template v-else-if="preview">
        <!-- In-place possible: show the original location + the destination choice. -->
        <template v-if="canInPlace">
          <p class="flex items-center gap-next-2 text-next-sm text-next-muted-foreground">
            <Icon name="folder" class="shrink-0" aria-hidden="true" />
            <span class="min-w-0 truncate">
              {{ t('disk.trash.originalLocation', 'Original location:') }}
              <span class="font-next-medium text-next-fg">{{ originalLocation }}</span>
            </span>
          </p>
          <SegmentedControl
            v-model="choice"
            size="sm"
            :options="choiceSegments"
            :aria-label="t('disk.trash.restoreTitle', 'Restore file')"
          />
        </template>

        <!-- In-place impossible: say why; the picker below is mandatory. -->
        <p v-else class="flex items-start gap-next-2 text-next-sm text-next-muted-foreground">
          <Icon name="info" class="mt-px shrink-0" aria-hidden="true" />
          {{ reasonText }}
        </p>

        <FolderPickerPanel v-if="showPicker" v-model="pickedFolderId" />
      </template>
    </div>

    <template #footer>
      <Button variant="outline" type="button" @click="open = false">
        {{ t('common.cancel', 'Cancel') }}
      </Button>
      <Button variant="primary" type="button" :disabled="!canConfirm" :loading="restoring" @click="confirmRestore">
        {{ t('disk.trash.restore', 'Restore') }}
      </Button>
    </template>
  </Modal>
</template>
