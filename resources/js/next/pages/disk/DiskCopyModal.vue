<script setup lang="ts">
// DiskCopyModal — duplicate a file into the disk. A blend of the rename and move dialogs: a
// NAME field (defaulted to "Copy of <name>", localized) plus the inline folder picker whose
// current level is the destination (defaulted to the folder the file is being copied from).
import { ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import FolderPickerPanel from './FolderPickerPanel.vue';
import { useDiskStore } from '../../app/stores/disk';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';

const props = defineProps<{
  /** The file to copy (id + current name). */
  source: { id: string; name: string } | null;
  /** The folder to pre-select as the destination (the level the copy was started from). */
  defaultFolderId: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const toast = useToast();
const store = useDiskStore();

const name = ref('');
const destination = ref<string | null>(null);
const copying = ref(false);

// Seed the name ("Copy of X") + destination (the current folder) each time the dialog opens.
// `immediate` so a dialog mounted already-open (a test) seeds too, not only on a later toggle.
watch(
  open,
  (isOpen) => {
    if (isOpen && props.source) {
      name.value = t('disk.browser.copyDefaultName', 'Copy of {name}', { name: props.source.name });
      destination.value = props.defaultFolderId;
    }
  },
  { immediate: true },
);

async function confirmCopy(): Promise<void> {
  const src = props.source;
  const trimmed = name.value.trim();
  if (!src || !trimmed || copying.value) return;
  copying.value = true;
  try {
    await store.copyFile(src.id, { name: trimmed, folderId: destination.value });
    open.value = false;
    toast.success(t('disk.browser.copied', 'Copied.'));
  } catch (err: unknown) {
    const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
    toast.danger(message ?? t('disk.browser.copyError', 'Could not copy.'));
  } finally {
    copying.value = false;
  }
}
</script>

<template>
  <Modal v-model:open="open" size="md" :aria-label="t('disk.browser.copyTitle', 'Copy file')">
    <template #title>{{ t('disk.browser.copyTitle', 'Copy file') }}</template>

    <div class="flex flex-col gap-next-4">
      <FormField :label="t('disk.browser.copyName', 'Name of the copy')">
        <TextInput
          v-model="name"
          :aria-label="t('disk.browser.copyName', 'Name of the copy')"
          @keydown.enter="confirmCopy"
        />
      </FormField>

      <div class="flex flex-col gap-next-2">
        <span class="text-next-sm font-next-medium text-next-fg">{{ t('disk.browser.copyDestination', 'Destination folder') }}</span>
        <!-- Re-mount per open (keyed on the source) so the picker starts at the default folder. -->
        <FolderPickerPanel v-if="open && source" :key="source.id" v-model="destination" />
      </div>
    </div>

    <template #footer>
      <Button variant="outline" type="button" @click="open = false">
        {{ t('common.cancel', 'Cancel') }}
      </Button>
      <Button variant="primary" type="button" :disabled="!name.trim()" :loading="copying" @click="confirmCopy">
        {{ t('disk.browser.copyHere', 'Copy here') }}
      </Button>
    </template>
  </Modal>
</template>
