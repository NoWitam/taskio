<script setup lang="ts">
// TaskAttachmentsField — task-specific attachments control.
//
// Composes the design-system FileDropzone (picker + new-file list + progress)
// and adds the two things the dropzone is deliberately agnostic about:
//   1. Uploading newly picked files to POST /disk/temp and collecting their
//      temp ids into v-model (`string[]`). These ids are sent in the task
//      payload and attached ADDITIVELY on the backend.
//   2. Rendering already-persisted attachments (`seed`) with a download link
//      and a remove button that emits `remove-existing` — the parent calls the
//      dedicated delete endpoint; existing files are never auto-removed here.
import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import FileDropzone, { type DropzoneFile } from '../../ui/forms/FileDropzone.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Button from '../../ui/primitives/Button.vue';
import Modal from '../../ui/overlay/Modal.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import { api } from '../../app/lib/api';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import { type TaskAttachment } from './types';

const props = withDefaults(
  defineProps<{
    /** Attachments already persisted on the task. */
    seed?: TaskAttachment[];
    /** Combined cap (existing + new). */
    max?: number;
    disabled?: boolean;
    readonly?: boolean;
  }>(),
  {
    seed: () => [],
    max: 5,
    disabled: false,
    readonly: false,
  },
);

/** v-model: temp file ids of the NEW uploads only. */
const model = defineModel<string[]>({ default: () => [] });

const emit = defineEmits<{
  (e: 'remove-existing', fileId: string): void;
}>();

const { t } = useI18n();
const toast = useToast();

const dropzone = ref<InstanceType<typeof FileDropzone> | null>(null);

const progress = reactive<Record<string, number>>({});
const controllers = new Map<string, AbortController>();
const tempIds = new Map<string, string>();
const started = ref<Set<string>>(new Set());

// Object URLs for authenticated image previews + per-file download state.
const previews = reactive<Record<string, string>>({});
const downloading = reactive<Record<string, boolean>>({});

// Lightbox preview: the image currently shown enlarged in the modal.
const previewFile = ref<TaskAttachment | null>(null);
const previewOpen = ref(false);

function canPreview(file: TaskAttachment): boolean {
  return isImage(file.type) && !!previews[String(file.id)];
}

function openPreview(file: TaskAttachment): void {
  if (!canPreview(file)) return;
  previewFile.value = file;
  previewOpen.value = true;
}

function remainingSlots(): number {
  return Math.max(0, props.max - props.seed.length);
}

function onChange(files: DropzoneFile[]): void {
  for (const entry of files) {
    if (!started.value.has(entry.id)) {
      started.value.add(entry.id);
      void upload(entry);
    }
  }
}

async function upload(entry: DropzoneFile): Promise<void> {
  const controller = new AbortController();
  controllers.set(entry.id, controller);
  progress[entry.id] = 0;

  try {
    const fd = new FormData();
    fd.append('file', entry.file, entry.file.name);

    const res = await api.post<{ data: TaskAttachment }>('/disk/temp', fd, {
      signal: controller.signal,
      onUploadProgress: (e) => {
        if (e.total && e.total > 0) {
          progress[entry.id] = Math.max(0, Math.min(100, Math.round((e.loaded / e.total) * 100)));
        }
      },
    });

    const id = String(res.data.id);
    tempIds.set(entry.id, id);
    progress[entry.id] = 100;
    if (!model.value.includes(id)) {
      model.value = [...model.value, id];
    }
  } catch {
    // Aborted or failed: drop the progress entry; FileDropzone still lists the
    // file, but no temp id was collected so it won't be sent.
    delete progress[entry.id];
    toast.danger(t('attachments.uploadError', 'Could not upload the file.'));
  } finally {
    controllers.delete(entry.id);
  }
}

function onRemove(entry: DropzoneFile): void {
  controllers.get(entry.id)?.abort();
  controllers.delete(entry.id);
  started.value.delete(entry.id);
  delete progress[entry.id];

  const id = tempIds.get(entry.id);
  if (id) {
    if (model.value.includes(id)) {
      model.value = model.value.filter((x) => x !== id);
    }
    tempIds.delete(entry.id);
  }
}

function isImage(type?: string | null): boolean {
  return type === 'image';
}

function previewSrc(file: TaskAttachment): string | undefined {
  return previews[String(file.id)];
}

function inlineUrl(path: string): string {
  return path + (path.includes('?') ? '&' : '?') + 'inline=1';
}

async function loadPreview(file: TaskAttachment): Promise<void> {
  const key = String(file.id);
  if (!isImage(file.type) || previews[key]) {
    return;
  }
  try {
    const blob = await api.get<Blob>(inlineUrl(file.path), { responseType: 'blob' });
    previews[key] = URL.createObjectURL(blob);
  } catch {
    // Leave the icon fallback in place.
  }
}

function syncPreviews(seed: TaskAttachment[]): void {
  const ids = new Set(seed.map((f) => String(f.id)));
  for (const key of Object.keys(previews)) {
    if (!ids.has(key)) {
      URL.revokeObjectURL(previews[key]);
      delete previews[key];
    }
  }
  for (const file of seed) {
    void loadPreview(file);
  }
}

// Once an uploaded file is persisted (it shows up in `seed` under the SAME id we
// collected from /disk/temp), retire it from the dropzone so it is not listed
// twice — once at the top (persisted) and once at the bottom (just uploaded).
function retireConsumed(seed: TaskAttachment[]): void {
  const seedIds = new Set(seed.map((f) => String(f.id)));
  const consumed: string[] = [];
  for (const [dropzoneId, tempId] of tempIds) {
    if (seedIds.has(tempId)) {
      consumed.push(dropzoneId);
    }
  }
  for (const dropzoneId of consumed) {
    dropzone.value?.removeById(dropzoneId);
  }
}

watch(
  () => props.seed,
  (seed) => {
    syncPreviews(seed);
    retireConsumed(seed);
  },
  { immediate: true, deep: true },
);

onBeforeUnmount(() => {
  for (const controller of controllers.values()) {
    controller.abort();
  }
  for (const url of Object.values(previews)) {
    URL.revokeObjectURL(url);
  }
});

async function download(file: TaskAttachment): Promise<void> {
  const key = String(file.id);
  if (downloading[key]) {
    return;
  }
  downloading[key] = true;
  try {
    const blob = await api.get<Blob>(file.path, { responseType: 'blob' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = file.name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch {
    toast.danger(t('attachments.downloadError', 'Could not download the file.'));
  } finally {
    downloading[key] = false;
  }
}

function removeExisting(file: TaskAttachment): void {
  emit('remove-existing', String(file.id));
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <!-- Existing attachments -->
    <ul v-if="seed.length" class="flex flex-col gap-next-2">
      <li
        v-for="file in seed"
        :key="file.id"
        class="flex items-center gap-next-3 rounded-next-md border border-next-border bg-next-card p-next-2"
      >
        <!-- Previewable image: a bigger thumbnail that opens the lightbox. A
             zoom-in cursor + a hover overlay with a magnify icon hint that it
             can be enlarged. -->
        <Tooltip v-if="canPreview(file)" :label="t('attachments.previewHint', 'Click to enlarge')">
          <button
            type="button"
            class="group relative flex size-16 shrink-0 cursor-zoom-in items-center justify-center overflow-hidden rounded-next-md bg-next-muted outline-none ring-next-ring transition-shadow focus-visible:ring-2"
            :aria-label="t('attachments.preview', 'Preview {name}', { name: file.name })"
            @click="openPreview(file)"
          >
            <img :src="previewSrc(file)" :alt="file.name" class="size-full object-cover" />
            <span
              class="absolute inset-0 flex items-center justify-center bg-[var(--color-next-overlay)] text-white opacity-0 transition-opacity duration-[var(--duration-next-fast)] group-hover:opacity-100 group-focus-visible:opacity-100"
            >
              <Icon name="search" class="text-next-lg" />
            </span>
          </button>
        </Tooltip>
        <span
          v-else
          class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-next-md bg-next-muted"
        >
          <Icon name="file-text" class="text-next-lg text-next-muted-foreground" />
        </span>
        <span class="flex min-w-0 flex-1 flex-col">
          <span class="truncate text-next-sm font-next-medium text-next-fg">{{ file.name }}</span>
          <span v-if="file.size_human" class="text-next-xs text-next-muted-foreground">{{ file.size_human }}</span>
        </span>
        <Button
          variant="ghost"
          size="icon"
          leading-icon="download"
          :loading="downloading[String(file.id)]"
          :aria-label="t('attachments.download', 'Download {name}', { name: file.name })"
          @click="download(file)"
        />
        <Button
          v-if="!readonly && !disabled"
          variant="ghost"
          size="icon"
          leading-icon="trash"
          :aria-label="t('attachments.remove', 'Remove {name}', { name: file.name })"
          @click="removeExisting(file)"
        />
      </li>
    </ul>

    <!-- Picker + new uploads (hidden once the combined cap is reached) -->
    <FileDropzone
      v-if="!readonly && remainingSlots() > 0"
      ref="dropzone"
      multiple
      :max-files="remainingSlots()"
      :progress="progress"
      :disabled="disabled"
      :title="t('attachments.title', 'Attachments')"
      :hint="t('attachments.hint', 'Up to {max} files', { max })"
      @change="onChange"
      @remove="onRemove"
    />
    <p v-else-if="!readonly" class="text-next-xs text-next-muted-foreground">
      {{ t('attachments.limitReached', 'Attachment limit reached.') }}
    </p>

    <!-- Lightbox: enlarged preview of the clicked image attachment. -->
    <Modal v-model:open="previewOpen" size="xl" :aria-label="t('attachments.previewTitle', 'Attachment preview')">
      <template #title>
        <span class="block truncate">{{ previewFile?.name }}</span>
      </template>
      <div class="flex items-center justify-center">
        <img
          v-if="previewFile && previewSrc(previewFile)"
          :src="previewSrc(previewFile)"
          :alt="previewFile.name"
          class="max-h-[70vh] w-auto max-w-full rounded-next-md object-contain"
        />
      </div>
      <template #footer>
        <Button
          v-if="previewFile"
          variant="outline"
          leading-icon="download"
          :loading="downloading[String(previewFile.id)]"
          @click="download(previewFile)"
        >
          {{ t('attachments.download', 'Download {name}', { name: previewFile.name }) }}
        </Button>
      </template>
    </Modal>
  </div>
</template>
