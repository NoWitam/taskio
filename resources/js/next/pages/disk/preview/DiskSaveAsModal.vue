<script setup lang="ts">
// DiskSaveAsModal — "Zapisz jako…": the edited content (a Blob the stage encoded) is uploaded
// as a NEW disk file under a chosen name + folder. The same name+destination UX as the copy
// modal (FolderPickerPanel reused); the ORIGINAL file is never touched — this is the only save
// available for resource-owned files (their bytes belong to the owning module).
import { ref, watch } from 'vue';
import Modal from '../../../ui/overlay/Modal.vue';
import Button from '../../../ui/primitives/Button.vue';
import FormField from '../../../ui/forms/FormField.vue';
import TextInput from '../../../ui/forms/TextInput.vue';
import FolderPickerPanel from '../FolderPickerPanel.vue';
import { useDiskStore } from '../../../app/stores/disk';
import { useToast } from '../../../app/composables/useToast';
import { useI18n } from '../../../app/i18n';

const props = defineProps<{
  /** The encoded content to upload (set by the stage that requested the save-as). */
  blob: Blob | null;
  defaultName: string;
  /** Pre-selected destination (the file's folder; null = the disk root). */
  defaultFolderId: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{ (e: 'saved'): void }>();

const { t } = useI18n();
const toast = useToast();
const store = useDiskStore();

const name = ref('');
const destination = ref<string | null>(null);
const saving = ref(false);

watch(open, (isOpen) => {
  if (!isOpen) return;
  name.value = props.defaultName;
  destination.value = props.defaultFolderId;
});

async function submit(): Promise<void> {
  const trimmed = name.value.trim();
  if (!trimmed || !props.blob || saving.value) return;
  saving.value = true;
  try {
    await store.uploadFile(new File([props.blob], trimmed, { type: props.blob.type }), destination.value);
    toast.success(t('disk.preview.savedAs', 'Saved as a new file.'));
    open.value = false;
    emit('saved');
  } catch (err: unknown) {
    const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
    toast.danger(message ?? t('disk.preview.saveError', 'Could not save the file.'));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Modal v-model:open="open" size="md" :aria-label="t('disk.preview.saveAsTitle', 'Save as')">
    <template #title>{{ t('disk.preview.saveAsTitle', 'Save as') }}</template>

    <div class="flex flex-col gap-next-4">
      <FormField :label="t('disk.browser.name', 'Name')" required>
        <TextInput v-model="name" :aria-label="t('disk.browser.name', 'Name')" />
      </FormField>

      <div class="flex flex-col gap-next-2">
        <span class="text-next-sm font-next-medium text-next-fg">{{ t('disk.browser.copyDestination', 'Destination folder') }}</span>
        <!-- Re-mounted per open so the picker starts at the default folder. -->
        <FolderPickerPanel v-if="open" v-model="destination" />
      </div>
    </div>

    <template #footer>
      <Button variant="outline" type="button" @click="open = false">
        {{ t('common.cancel', 'Cancel') }}
      </Button>
      <Button variant="primary" type="button" :disabled="!name.trim() || !blob" :loading="saving" @click="submit">
        {{ t('disk.preview.saveAsConfirm', 'Save here') }}
      </Button>
    </template>
  </Modal>
</template>
