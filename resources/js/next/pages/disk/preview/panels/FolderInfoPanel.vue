<script setup lang="ts">
// FolderInfoPanel — the "Informacje" tab for a FOLDER: the metadata + governance form lifted
// from the old folder drawer. One label model: the folder's OWN labels (editable mode toggle)
// plus the enforced labels it INHERITS from ancestors (locked — a lock instead of a toggle,
// non-removable in the picker). Emits `updated` with the fresh Resource and `update:dirty`.
import { computed, reactive, ref, watch } from 'vue';
import Button from '../../../../ui/primitives/Button.vue';
import Icon, { type IconName } from '../../../../ui/primitives/Icon.vue';
import FormField from '../../../../ui/forms/FormField.vue';
import TextInput from '../../../../ui/forms/TextInput.vue';
import Textarea from '../../../../ui/forms/Textarea.vue';
import IconInput from '../../../../ui/forms/IconInput.vue';
import LabelSelect from '../../../../ui/forms/LabelSelect.vue';
import SegmentedControl, { type SegmentOption } from '../../../../ui/forms/SegmentedControl.vue';
import { resolveLabelIcon } from '../../../../ui/forms/labelIcon';
import { useDiskStore } from '../../../../app/stores/disk';
import { useToast } from '../../../../app/composables/useToast';
import { useI18n } from '../../../../app/i18n';
import type { DiskFolder, DiskFolderLabel, DiskLabel, FolderLabelMode } from '../../types';

const props = defineProps<{ folder: DiskFolder }>();

const emit = defineEmits<{
  (e: 'updated', folder: DiskFolder): void;
  (e: 'update:dirty', dirty: boolean): void;
}>();

const { t, locale } = useI18n();
const toast = useToast();
const store = useDiskStore();

const editName = ref('');
const editDescription = ref('');
const editIcon = ref<IconName | null>(null);
// LabelSelect model = own + inherited ids; inherited are locked chips excluded from saves.
const labelIds = ref<string[]>([]);
const lockedIds = ref<string[]>([]);
const modeById = reactive<Record<string, FolderLabelMode>>({});
const labelMeta = reactive<Record<string, DiskLabel>>({});
const saving = ref(false);

const lockedSet = computed(() => new Set(lockedIds.value));
function ownLabels(folder: DiskFolder): DiskFolderLabel[] {
  return (folder.labels ?? []).filter((l) => !l.locked);
}

function seedFrom(folder: DiskFolder): void {
  editName.value = folder.name;
  editDescription.value = folder.description ?? '';
  editIcon.value = (folder.icon as IconName | null) ?? null;

  const labels = folder.labels ?? [];
  labelIds.value = labels.map((l) => l.id);
  lockedIds.value = labels.filter((l) => l.locked).map((l) => l.id);
  for (const key of Object.keys(modeById)) delete modeById[key];
  for (const key of Object.keys(labelMeta)) delete labelMeta[key];
  for (const l of labels) {
    labelMeta[l.id] = { id: l.id, name: l.name, color: l.color, icon: l.icon };
    if (!l.locked) modeById[l.id] = l.mode; // only OWN labels carry an editable mode
  }
}

watch(() => props.folder, seedFrom, { immediate: true });

const canEdit = computed(() => !!props.folder.can_be_updated);

const labelSeed = computed(() =>
  (props.folder.labels ?? []).map((l) => ({ id: l.id, name: l.name, color: l.color, icon: l.icon })),
);

const modeOptions = computed<SegmentOption<FolderLabelMode>[]>(() => [
  { value: 'recommended', label: t('disk.folderDrawer.mode.recommended', 'Recommended') },
  { value: 'enforced', label: t('disk.folderDrawer.mode.enforced', 'Enforced') },
]);

const governanceRows = computed(() =>
  labelIds.value.map((id) => {
    const meta = labelMeta[id];
    return {
      id,
      name: meta?.name ?? id,
      color: meta?.color ?? null,
      icon: resolveLabelIcon(meta?.icon ?? null),
      locked: lockedSet.value.has(id),
    };
  }),
);

function onLabelsSelected(options: Array<{ value: string; label: string; color: string | null; icon?: IconName }>): void {
  for (const opt of options) {
    labelMeta[opt.value] = { id: opt.value, name: opt.label, color: opt.color, icon: opt.icon ?? null };
    if (!lockedSet.value.has(opt.value) && !(opt.value in modeById)) modeById[opt.value] = 'recommended';
  }
}

function setMode(id: string, mode: FolderLabelMode): void {
  modeById[id] = mode;
}

const ownLabelIds = computed(() => labelIds.value.filter((id) => !lockedSet.value.has(id)));

const dirty = computed(() => {
  const f = props.folder;
  const seededOwn = ownLabels(f);
  const seededIds = seededOwn.map((l) => l.id);
  const idsChanged =
    ownLabelIds.value.length !== seededIds.length || ownLabelIds.value.some((id) => !seededIds.includes(id));
  const modesChanged = seededOwn.some((l) => ownLabelIds.value.includes(l.id) && modeById[l.id] !== l.mode);
  return (
    editName.value.trim() !== f.name ||
    editDescription.value !== (f.description ?? '') ||
    (editIcon.value ?? null) !== (f.icon ?? null) ||
    idsChanged ||
    modesChanged
  );
});
watch(dirty, (d) => emit('update:dirty', d), { immediate: true });

async function save(): Promise<void> {
  const name = editName.value.trim();
  if (!name || saving.value) return;
  saving.value = true;
  try {
    const updated = await store.updateFolder(props.folder.id, {
      name,
      description: editDescription.value === '' ? null : editDescription.value,
      icon: editIcon.value,
      labels: ownLabelIds.value.map((id) => ({ id, mode: modeById[id] ?? 'recommended' })),
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

const itemsMeta = computed(() => {
  const folders = props.folder.children_count ?? 0;
  const files = props.folder.files_count ?? 0;
  return t('disk.folderDrawer.items', '{folders} subfolders · {files} files', { folders, files });
});

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
        <dt class="text-next-xs text-next-muted-foreground">{{ t('disk.folderDrawer.contents', 'Contents') }}</dt>
        <dd class="text-next-fg">{{ itemsMeta }}</dd>
      </div>
      <div>
        <dt class="text-next-xs text-next-muted-foreground">{{ t('disk.drawer.created', 'Added') }}</dt>
        <dd class="text-next-fg">{{ formatDateTime(folder.created_at) }}</dd>
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
      <FormField :label="t('disk.folderDrawer.icon', 'Icon')">
        <IconInput v-model="editIcon" :disabled="!canEdit" :aria-label="t('disk.folderDrawer.icon', 'Icon')" />
      </FormField>

      <!-- Governance labels: one model — inherited (locked) + own (editable mode). -->
      <FormField :label="t('disk.folderDrawer.labels', 'Governance labels')">
        <LabelSelect
          v-model="labelIds"
          :seed="labelSeed"
          :locked="lockedIds"
          :disabled="!canEdit"
          :aria-label="t('disk.folderDrawer.labels', 'Governance labels')"
          @update:selected="onLabelsSelected"
        />
      </FormField>
      <ul v-if="governanceRows.length" class="flex flex-col gap-next-2">
        <li
          v-for="row in governanceRows"
          :key="row.id"
          class="flex items-center justify-between gap-next-2 rounded-next-md border px-next-2 py-next-1_5"
          :class="row.locked ? 'border-dashed border-next-border/60 opacity-80' : 'border-next-border/60'"
        >
          <span class="inline-flex min-w-0 items-center gap-next-1 text-next-sm text-next-fg">
            <Icon v-if="row.icon" :name="row.icon" class="shrink-0 text-next-muted-foreground" />
            <span class="truncate">{{ row.name }}</span>
          </span>
          <span
            v-if="row.locked"
            class="inline-flex shrink-0 items-center gap-next-1 text-next-xs text-next-muted-foreground"
            :title="t('disk.folderDrawer.inheritedHint', 'Enforced by a parent folder — cannot be changed here')"
          >
            <Icon name="lock" />
            {{ t('disk.folderDrawer.mode.enforced', 'Enforced') }}
          </span>
          <SegmentedControl
            v-else
            size="sm"
            :model-value="modeById[row.id] ?? 'recommended'"
            :options="modeOptions"
            :disabled="!canEdit"
            :aria-label="t('disk.folderDrawer.modeLabel', 'Label mode')"
            @update:model-value="(v) => setMode(row.id, v as FolderLabelMode)"
          />
        </li>
      </ul>
      <p class="text-next-xs leading-snug text-next-muted-foreground">
        {{ t('disk.folderDrawer.governanceHint', 'Enforced labels are added to every file in this folder and its subfolders and cannot be removed there. Recommended labels are pre-selected on new items.') }}
      </p>

      <div v-if="canEdit" class="flex justify-end">
        <Button variant="primary" :disabled="!dirty || !editName.trim()" :loading="saving" @click="save">
          {{ t('common.save', 'Save') }}
        </Button>
      </div>
    </div>
  </div>
</template>
