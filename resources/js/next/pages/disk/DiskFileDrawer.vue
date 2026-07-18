<script setup lang="ts">
// DiskFileDrawer — a file's detail panel: inline preview, editable metadata (name /
// description / labels), download, and its history (the shared changelog Timeline).
//
// The binary can only be served WITH the workspace/auth headers the api client sends (a
// top-level navigation cannot), so the preview and download both fetch a blob and open a
// same-origin object URL. Metadata edits gate on the server's can_be_updated flag.
import { computed, ref, watch } from 'vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import Button from '../../ui/primitives/Button.vue';
import Badge from '../../ui/primitives/Badge.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import Textarea from '../../ui/forms/Textarea.vue';
import LabelSelect from '../../ui/forms/LabelSelect.vue';
import Timeline, { type TimelineEntry } from '../../ui/patterns/Timeline.vue';
import ImageEditor from './ImageEditor.vue';
import { useDiskStore } from '../../app/stores/disk';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useToast } from '../../app/composables/useToast';
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';
import { fileTypeIcon, isImageFile } from './fileIcon';
import type { DiskFile } from './types';

const props = defineProps<{ file: DiskFile | null }>();
const open = defineModel<boolean>('open', { default: false });

const { t, locale } = useI18n();
const toast = useToast();
const store = useDiskStore();

// A local editable copy so an in-flight save never fights the reactive prop.
const current = ref<DiskFile | null>(null);
const editName = ref('');
const editDescription = ref('');
const editLabels = ref<string[]>([]);
const saving = ref(false);

function seedFrom(file: DiskFile): void {
  current.value = file;
  editName.value = file.name;
  editDescription.value = file.description ?? '';
  editLabels.value = (file.labels ?? []).map((l) => l.id);
}

const canEdit = computed(() => !!current.value?.can_be_updated);
const labelSeed = computed(() =>
  (current.value?.labels ?? []).map((l) => ({ id: l.id, name: l.name, color: l.color, icon: l.icon })),
);

const dirty = computed(() => {
  const f = current.value;
  if (!f) return false;
  const labelsChanged =
    editLabels.value.length !== (f.labels?.length ?? 0) ||
    editLabels.value.some((id) => !(f.labels ?? []).some((l) => l.id === id));
  return editName.value.trim() !== f.name || editDescription.value !== (f.description ?? '') || labelsChanged;
});

async function save(): Promise<void> {
  const f = current.value;
  const name = editName.value.trim();
  if (!f || !name || saving.value) return;
  saving.value = true;
  try {
    const updated = await store.updateFile(f.id, {
      name,
      description: editDescription.value === '' ? null : editDescription.value,
      labels: editLabels.value,
    });
    seedFrom(updated);
    toast.success(t('disk.drawer.saved', 'Changes saved.'));
    void store.fetchChangelog(f.id); // reflect the new history entry
  } catch (err: unknown) {
    const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
    toast.danger(message ?? t('disk.drawer.saveError', 'Could not save changes.'));
  } finally {
    saving.value = false;
  }
}

// --- Preview / download (blob via the api client, then an object URL) --------
const previewUrl = ref<string | null>(null);
const previewLoading = ref(false);
const busy = ref(false);

function inlineUrl(path: string): string {
  return path + (path.includes('?') ? '&' : '?') + 'inline=1';
}

function revokePreview(): void {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
  previewUrl.value = null;
}

async function loadPreview(file: DiskFile): Promise<void> {
  revokePreview();
  if (!isImageFile(file.type, file.mime_type)) return; // only images preview inline for now
  previewLoading.value = true;
  try {
    const blob = await api.get<Blob>(inlineUrl(file.path), { responseType: 'blob' });
    previewUrl.value = URL.createObjectURL(blob);
  } catch {
    previewUrl.value = null; // fall back to the type glyph
  } finally {
    previewLoading.value = false;
  }
}

async function download(): Promise<void> {
  const file = current.value;
  if (!file || busy.value) return;
  busy.value = true;
  let url: string | null = null;
  try {
    const blob = await api.get<Blob>(file.path, { responseType: 'blob' });
    url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = file.name;
    document.body.appendChild(link);
    link.click();
    link.remove();
  } catch {
    toast.danger(t('disk.browser.openError', 'Could not open the file.'));
  } finally {
    busy.value = false;
    if (url) setTimeout((u: string) => URL.revokeObjectURL(u), 10_000, url);
  }
}

// --- History (changelog) → Timeline -----------------------------------------
function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(d);
}

const historyEntries = computed<TimelineEntry[]>(() =>
  store.changelog.map((entry) => ({
    id: entry.id,
    title: entry.event_description,
    description: entry.causer?.name ?? t('disk.drawer.system', 'System'),
    time: formatDateTime(entry.created_at),
    datetime: entry.created_at,
    icon: 'clock',
    tone: 'neutral',
  })),
);

const historyScroll = ref<HTMLElement | null>(null);
const { sentinelRef: historySentinel } = useInfiniteScroll({
  root: historyScroll,
  onLoadMore: () => {
    if (current.value) void store.fetchChangelog(current.value.id, { reset: false });
  },
  canLoadMore: () =>
    store.changelogHasMore && !store.changelogLoading && !store.changelogLoadingMore && !store.changelogError,
});

// Load everything when the drawer opens for a file (or the file changes).
watch(
  [open, () => props.file?.id],
  ([isOpen]) => {
    if (!isOpen || !props.file) return;
    seedFrom(props.file);
    void loadPreview(props.file);
    void store.fetchChangelog(props.file.id);
  },
  { immediate: true },
);

// Free the object URL when the drawer closes.
watch(open, (isOpen) => {
  if (!isOpen) revokePreview();
});

const typeGlyph = computed(() => fileTypeIcon(current.value?.type));

// Image editing (P8) saves a COPY, so it only needs create — offered for any image file.
const isImage = computed(() => isImageFile(current.value?.type, current.value?.mime_type));
const editorOpen = ref(false);
</script>

<template>
  <Drawer v-model:open="open" side="right" size="xl" show-close :aria-label="t('disk.drawer.title', 'File')">
    <template #title>
      <span class="truncate">{{ current?.name }}</span>
    </template>

    <div v-if="current" class="flex flex-col gap-next-5">
      <!-- Preview -->
      <div class="flex items-center justify-center overflow-hidden rounded-next-lg border border-next-border bg-next-muted/40 p-next-3">
        <Skeleton v-if="previewLoading" class="h-40 w-full rounded-next-md" />
        <img
          v-else-if="previewUrl"
          :src="previewUrl"
          :alt="current.name"
          class="max-h-64 max-w-full rounded-next-md object-contain"
        />
        <span v-else class="flex h-40 w-full items-center justify-center text-next-4xl text-next-muted-foreground" aria-hidden="true">
          <Icon :name="typeGlyph" />
        </span>
      </div>

      <!-- Actions -->
      <div class="flex flex-wrap gap-next-2">
        <Button variant="outline" leading-icon="download" :loading="busy" @click="download">
          {{ t('disk.drawer.download', 'Download') }}
        </Button>
        <Button v-if="isImage" variant="outline" leading-icon="pencil" @click="editorOpen = true">
          {{ t('disk.drawer.edit', 'Edit image') }}
        </Button>
      </div>

      <!-- Metadata facts -->
      <dl class="grid grid-cols-2 gap-x-next-4 gap-y-next-2 text-next-sm">
        <div>
          <dt class="text-next-xs text-next-muted-foreground">{{ t('disk.drawer.size', 'Size') }}</dt>
          <dd class="text-next-fg">{{ current.size_human }}</dd>
        </div>
        <div>
          <dt class="text-next-xs text-next-muted-foreground">{{ t('disk.drawer.type', 'Type') }}</dt>
          <dd class="truncate text-next-fg">{{ current.mime_type ?? current.type }}</dd>
        </div>
        <div>
          <dt class="text-next-xs text-next-muted-foreground">{{ t('disk.drawer.created', 'Added') }}</dt>
          <dd class="text-next-fg">{{ formatDateTime(current.created_at_iso) || current.created_at }}</dd>
        </div>
        <div>
          <dt class="text-next-xs text-next-muted-foreground">{{ t('disk.drawer.source', 'Source') }}</dt>
          <dd><Badge variant="neutral" tone="subtle" size="sm">{{ current.source }}</Badge></dd>
        </div>
      </dl>

      <!-- Editable metadata (gated on can_be_updated) -->
      <div class="flex flex-col gap-next-3 border-t border-next-border pt-next-4">
        <FormField :label="t('disk.browser.name', 'Name')">
          <TextInput v-model="editName" :disabled="!canEdit" :aria-label="t('disk.browser.name', 'Name')" />
        </FormField>
        <FormField :label="t('disk.drawer.description', 'Description')">
          <Textarea v-model="editDescription" :rows="3" :disabled="!canEdit" :aria-label="t('disk.drawer.description', 'Description')" />
        </FormField>
        <FormField :label="t('disk.drawer.labels', 'Labels')">
          <LabelSelect v-model="editLabels" :seed="labelSeed" :disabled="!canEdit" :aria-label="t('disk.drawer.labels', 'Labels')" />
        </FormField>
        <div v-if="canEdit" class="flex justify-end">
          <Button variant="primary" :disabled="!dirty || !editName.trim()" :loading="saving" @click="save">
            {{ t('common.save', 'Save') }}
          </Button>
        </div>
      </div>

      <!-- History -->
      <div class="flex flex-col gap-next-2 border-t border-next-border pt-next-4">
        <h3 class="text-next-sm font-next-semibold text-next-fg">{{ t('disk.drawer.history', 'History') }}</h3>
        <div ref="historyScroll" class="max-h-64 overflow-y-auto pr-next-1">
          <p v-if="store.changelogError" class="text-next-sm text-next-danger" role="alert">
            {{ t('disk.drawer.historyError', 'Could not load the history.') }}
          </p>
          <template v-else>
            <Timeline
              :items="historyEntries"
              :loading="store.changelogLoading"
              compact
              :aria-label="t('disk.drawer.history', 'History')"
              :empty-title="t('disk.drawer.historyEmpty', 'No history yet')"
            />
            <div ref="historySentinel" class="h-px w-full" aria-hidden="true" />
          </template>
        </div>
      </div>
    </div>

    <!-- Image editor (P8) — opens over the drawer; saves a copy. -->
    <ImageEditor v-model:open="editorOpen" :file="current" />
  </Drawer>
</template>
