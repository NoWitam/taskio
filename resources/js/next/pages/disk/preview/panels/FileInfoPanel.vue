<script setup lang="ts">
// FileInfoPanel — the "Informacje" tab for a FILE: the facts + editable metadata form lifted
// from the old file drawer (name / description / labels with folder-enforced ones locked),
// gated on can_be_updated. Emits `updated` with the fresh Resource and `update:dirty` for the
// shell's unsaved-changes guard.
import { computed, ref, watch } from 'vue';
import Button from '../../../../ui/primitives/Button.vue';
import Badge from '../../../../ui/primitives/Badge.vue';
import FormField from '../../../../ui/forms/FormField.vue';
import TextInput from '../../../../ui/forms/TextInput.vue';
import Textarea from '../../../../ui/forms/Textarea.vue';
import LabelSelect from '../../../../ui/forms/LabelSelect.vue';
import { useDiskStore } from '../../../../app/stores/disk';
import { useToast } from '../../../../app/composables/useToast';
import { useI18n } from '../../../../app/i18n';
import type { DiskFile } from '../../types';

const props = defineProps<{ file: DiskFile }>();

const emit = defineEmits<{
  (e: 'updated', file: DiskFile): void;
  (e: 'update:dirty', dirty: boolean): void;
}>();

const { t, locale } = useI18n();
const toast = useToast();
const store = useDiskStore();

const editName = ref('');
const editDescription = ref('');
const editLabels = ref<string[]>([]);
const saving = ref(false);

function seedFrom(file: DiskFile): void {
  editName.value = file.name;
  editDescription.value = file.description ?? '';
  editLabels.value = (file.labels ?? []).map((l) => l.id);
}

watch(() => props.file, seedFrom, { immediate: true });

const canEdit = computed(() => !!props.file.can_be_updated);
const labelSeed = computed(() =>
  (props.file.labels ?? []).map((l) => ({ id: l.id, name: l.name, color: l.color, icon: l.icon })),
);
// Folder-enforced labels: shown locked in the picker (no ✕), and kept in the model on save.
const lockedLabelIds = computed(() => (props.file.labels ?? []).filter((l) => l.locked).map((l) => l.id));

const dirty = computed(() => {
  const f = props.file;
  const labelsChanged =
    editLabels.value.length !== (f.labels?.length ?? 0) ||
    editLabels.value.some((id) => !(f.labels ?? []).some((l) => l.id === id));
  return editName.value.trim() !== f.name || editDescription.value !== (f.description ?? '') || labelsChanged;
});
watch(dirty, (d) => emit('update:dirty', d), { immediate: true });

async function save(): Promise<void> {
  const name = editName.value.trim();
  if (!name || saving.value) return;
  saving.value = true;
  try {
    const updated = await store.updateFile(props.file.id, {
      name,
      description: editDescription.value === '' ? null : editDescription.value,
      labels: editLabels.value,
    });
    seedFrom(updated);
    emit('updated', updated);
    toast.success(t('disk.drawer.saved', 'Changes saved.'));
  } catch (err: unknown) {
    const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
    toast.danger(message ?? t('disk.drawer.saveError', 'Could not save changes.'));
  } finally {
    saving.value = false;
  }
}

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(d);
}
</script>

<template>
  <div class="flex flex-col gap-next-4">
    <!-- Facts. -->
    <dl class="grid grid-cols-2 gap-x-next-4 gap-y-next-2 text-next-sm">
      <div>
        <dt class="text-next-xs text-next-muted-foreground">{{ t('disk.drawer.size', 'Size') }}</dt>
        <dd class="text-next-fg">{{ file.size_human }}</dd>
      </div>
      <div>
        <dt class="text-next-xs text-next-muted-foreground">{{ t('disk.drawer.type', 'Type') }}</dt>
        <dd class="truncate text-next-fg">{{ file.mime_type ?? file.type }}</dd>
      </div>
      <div>
        <dt class="text-next-xs text-next-muted-foreground">{{ t('disk.drawer.created', 'Added') }}</dt>
        <dd class="text-next-fg">{{ formatDateTime(file.created_at_iso) || file.created_at }}</dd>
      </div>
      <div>
        <dt class="text-next-xs text-next-muted-foreground">{{ t('disk.drawer.source', 'Source') }}</dt>
        <dd><Badge variant="neutral" tone="subtle" size="sm">{{ file.source }}</Badge></dd>
      </div>
    </dl>

    <!-- Editable metadata (gated on can_be_updated). -->
    <div class="flex flex-col gap-next-3 border-t border-next-border pt-next-4">
      <FormField :label="t('disk.browser.name', 'Name')">
        <TextInput v-model="editName" :disabled="!canEdit" :aria-label="t('disk.browser.name', 'Name')" />
      </FormField>
      <FormField :label="t('disk.drawer.description', 'Description')">
        <Textarea v-model="editDescription" :rows="3" :disabled="!canEdit" :aria-label="t('disk.drawer.description', 'Description')" />
      </FormField>
      <FormField :label="t('disk.drawer.labels', 'Labels')">
        <LabelSelect
          v-model="editLabels"
          :seed="labelSeed"
          :locked="lockedLabelIds"
          :disabled="!canEdit"
          :aria-label="t('disk.drawer.labels', 'Labels')"
        />
      </FormField>
      <div v-if="canEdit" class="flex justify-end">
        <Button variant="primary" :disabled="!dirty || !editName.trim()" :loading="saving" @click="save">
          {{ t('common.save', 'Save') }}
        </Button>
      </div>
    </div>
  </div>
</template>
