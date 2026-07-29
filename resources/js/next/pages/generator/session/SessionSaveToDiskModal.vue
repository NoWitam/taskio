<script setup lang="ts">
// SessionSaveToDiskModal — "Zapisz na Dysk": promote a produced session image onto the user's Disk
// (R2 sub-stage 2c). Deliberately mirrors the Disk copy / save-as dialogs (DiskCopyModal /
// DiskSaveAsModal): a NAME field seeded from the session/part name + the SAME inline FolderPickerPanel
// whose current level IS the destination (default = the Disk root). On confirm it calls the sessions
// store's `saveResultToDisk` (POST → 201 File) and toasts success; there is NO business logic here —
// the backend re-reads the produced PNG and owns the Disk create.
import { ref, watch } from 'vue';
import Modal from '../../../ui/overlay/Modal.vue';
import Button from '../../../ui/primitives/Button.vue';
import FormField from '../../../ui/forms/FormField.vue';
import TextInput from '../../../ui/forms/TextInput.vue';
import FolderPickerPanel from '../../disk/FolderPickerPanel.vue';
import { useSessionsStore } from '../../../app/stores/sessions';
import { useToast } from '../../../app/composables/useToast';
import { useI18n } from '../../../app/i18n';

const props = defineProps<{
  sessionId: string;
  /** The produced image's storage key (a part key or a scene's `scene_plan.<i>`); null closes the dialog. */
  partKey: string | null;
  /** The pre-filled (editable) default file name. */
  defaultName: string;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ (e: 'saved'): void }>();

const { t } = useI18n();
const toast = useToast();
const store = useSessionsStore();

const name = ref('');
const destination = ref<string | null>(null);
const saving = ref(false);

// Seed the name + reset the destination (the Disk root) each time the dialog opens. `immediate` so a
// dialog mounted already-open (a test) seeds too, not only on a later toggle.
watch(
  open,
  (isOpen) => {
    if (isOpen) {
      name.value = props.defaultName;
      destination.value = null;
    }
  },
  { immediate: true },
);

async function confirmSave(): Promise<void> {
  const trimmed = name.value.trim();
  if (!props.partKey || !trimmed || saving.value) return;
  saving.value = true;
  try {
    await store.saveResultToDisk(props.sessionId, props.partKey, { name: trimmed, folder_id: destination.value });
    open.value = false;
    toast.success(t('generator.sessions.result.saveToDiskSuccess'));
    emit('saved');
  } catch (err: unknown) {
    const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
    toast.danger(message ?? t('generator.sessions.result.saveToDiskError'));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Modal v-model:open="open" size="md" :aria-label="t('generator.sessions.result.saveToDiskTitle')">
    <template #title>{{ t('generator.sessions.result.saveToDiskTitle') }}</template>

    <div class="flex flex-col gap-next-4">
      <FormField :label="t('generator.sessions.result.saveToDiskName')" required>
        <TextInput
          v-model="name"
          :aria-label="t('generator.sessions.result.saveToDiskName')"
          @keydown.enter="confirmSave"
        />
      </FormField>

      <div class="flex flex-col gap-next-2">
        <span class="text-next-sm font-next-medium text-next-fg">
          {{ t('generator.sessions.result.saveToDiskDestination') }}
        </span>
        <!-- Re-mount per open (keyed on the target) so the picker starts fresh at the Disk root. -->
        <FolderPickerPanel v-if="open && partKey" :key="partKey" v-model="destination" />
      </div>
    </div>

    <template #footer>
      <Button variant="outline" type="button" @click="open = false">
        {{ t('common.cancel', 'Cancel') }}
      </Button>
      <Button variant="primary" type="button" :disabled="!name.trim()" :loading="saving" @click="confirmSave">
        {{ t('generator.sessions.result.saveToDiskConfirm') }}
      </Button>
    </template>
  </Modal>
</template>
