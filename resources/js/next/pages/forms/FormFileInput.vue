<script setup lang="ts">
// FormFileInput — the fill-mode control for a form's file field (element type
// 'image', historically an AI-prompt placeholder, now a real single-file upload).
//
// Composes the design-system FileDropzone in SINGLE mode and adds the upload the
// dropzone is deliberately agnostic about: a picked file is POSTed to /disk/temp
// and the returned temp-file id becomes the model value (the string the form
// submission sends as this field's answer; the backend validates it, then binds
// the temp to the submission). Removing the file clears the id and aborts an
// in-flight upload. Disabled (builder preview) shows the dropzone but never
// uploads.
import { onBeforeUnmount, reactive, ref } from 'vue';
import FileDropzone, { type DropzoneFile } from '../../ui/forms/FileDropzone.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Button from '../../ui/primitives/Button.vue';
import DiskFilePickerModal from '../disk/DiskFilePickerModal.vue';
import type { DiskFile } from '../disk/types';
import { api } from '../../app/lib/api';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';

const props = withDefaults(
  defineProps<{
    /** Accepted mime types / extensions (FileDropzone `accept`). */
    acceptedTypes?: string[];
    /** Max size in MEGABYTES (the builder stores MB; the dropzone wants bytes). */
    maxSize?: number;
    disabled?: boolean;
  }>(),
  {
    acceptedTypes: () => [],
    disabled: false,
  },
);

/** v-model: the uploaded temp-file id, or null when empty. */
const model = defineModel<string | null>({ default: null });

const { t } = useI18n();
const toast = useToast();

const dropzone = ref<InstanceType<typeof FileDropzone> | null>(null);
const progress = reactive<Record<string, number>>({});
const started = ref<Set<string>>(new Set());
let controller: AbortController | null = null;
/** Monotonic id of the LATEST upload; a stale upload's continuation checks it before acting. */
let uploadToken = 0;

/** Pick-from-Disk state: the modal, the name of a picked file (shown as a chip), and its copy. */
const pickerOpen = ref(false);
const pickedName = ref<string | null>(null);
const picking = ref(false);

const accept = () => (props.acceptedTypes.length ? props.acceptedTypes.join(',') : undefined);
const maxSizeBytes = () => (props.maxSize != null ? props.maxSize * 1024 * 1024 : undefined);

function onChange(files: DropzoneFile[]): void {
  const entry = files[0];
  if (!entry || started.value.has(entry.id)) return;
  started.value.add(entry.id);
  void upload(entry);
}

/** Whether a rejection is an aborted request (controller.abort → axios CanceledError). */
function isAbort(err: unknown): boolean {
  const code = (err as { code?: string })?.code;
  const name = (err as { name?: string })?.name;
  return code === 'ERR_CANCELED' || name === 'CanceledError' || name === 'AbortError';
}

async function upload(entry: DropzoneFile): Promise<void> {
  // Per-upload token: replacing the file starts upload(B) which aborts A; A's rejection then
  // settles LATER, and without this guard A's finally would null B's controller (making B
  // unabortable) and A's catch would toast/clear over B's legitimate upload.
  const myToken = ++uploadToken;
  controller?.abort();
  const myController = new AbortController();
  controller = myController;
  progress[entry.id] = 0;
  pickedName.value = null; // the value now comes from an upload, not a Disk pick

  try {
    const fd = new FormData();
    fd.append('file', entry.file, entry.file.name);

    const res = await api.post<{ data: { id: string | number } }>('/disk/temp', fd, {
      signal: myController.signal,
      onUploadProgress: (e) => {
        if (e.total && e.total > 0) {
          progress[entry.id] = Math.max(0, Math.min(100, Math.round((e.loaded / e.total) * 100)));
        }
      },
    });

    if (myToken !== uploadToken) return; // superseded by a newer upload
    progress[entry.id] = 100;
    model.value = String(res.data.id);
  } catch (err: unknown) {
    // A deliberate cancel (remove / replace / unmount) is not a failure — no toast, no clear.
    if (isAbort(err) || myToken !== uploadToken) return;
    delete progress[entry.id];
    model.value = null;
    toast.danger(t('forms.fileInput.uploadError', 'Could not upload the file.'));
  } finally {
    // Only clear the shared controller if it is still OURS (a newer upload may own it now).
    if (controller === myController) controller = null;
  }
}

/**
 * Pick an existing Disk file: copy it into a fresh temp the caller owns (POST copy-to-temp)
 * and hold that temp's id, exactly as an upload would. Referencing the Disk file directly
 * would be refused by the submit-time claim rules (or share one blob), so the server copies.
 */
async function onPick(file: DiskFile): Promise<void> {
  // A pick supersedes any in-flight upload: bump the token and abort so a late upload's
  // continuation can't clobber the value we are about to set.
  uploadToken += 1;
  controller?.abort();
  controller = null;
  started.value.clear();
  for (const key of Object.keys(progress)) delete progress[key];

  picking.value = true;
  try {
    const res = await api.post<{ data: { id: string | number; name: string } }>(
      `/disk/${encodeURIComponent(file.id)}/copy-to-temp`,
    );
    model.value = String(res.data.id);
    pickedName.value = res.data.name;
  } catch {
    toast.danger(t('forms.fileInput.pickError', 'Could not attach the file from Disk.'));
  } finally {
    picking.value = false;
  }
}

function onRemove(): void {
  uploadToken += 1; // invalidate any in-flight upload's continuation
  controller?.abort();
  controller = null;
  started.value.clear();
  for (const key of Object.keys(progress)) delete progress[key];
  pickedName.value = null;
  model.value = null;
}

onBeforeUnmount(() => {
  uploadToken += 1;
  controller?.abort();
});
</script>

<template>
  <div class="flex flex-col gap-next-2">
    <FileDropzone
      ref="dropzone"
      :accept="accept()"
      :max-size="maxSizeBytes()"
      :progress="progress"
      :disabled="disabled"
      @change="onChange"
      @remove="onRemove"
    />

    <!-- Or reuse a file that already lives on the Disk (copied into a temp on pick). -->
    <div class="flex items-center gap-next-2 text-next-xs text-next-muted-foreground">
      <span>{{ t('forms.fileInput.or', 'or') }}</span>
      <Button
        type="button"
        variant="outline"
        size="sm"
        :disabled="disabled || picking"
        :loading="picking"
        @click="pickerOpen = true"
      >
        <Icon name="folder" aria-hidden="true" />
        {{ t('forms.fileInput.pickFromDisk', 'Choose from Disk') }}
      </Button>
    </div>

    <!-- A file picked from the Disk: its NAME (the dropzone shows nothing for a pick) + remove. -->
    <div
      v-if="pickedName"
      class="flex items-center gap-next-2 rounded-next-md border border-next-border px-next-2 py-next-1 text-next-sm"
    >
      <Icon name="check" class="shrink-0 text-next-success" aria-hidden="true" />
      <span class="min-w-0 flex-1 truncate">{{ pickedName }}</span>
      <Button
        v-if="!disabled"
        type="button"
        variant="ghost"
        size="icon-xs"
        :aria-label="t('common.remove', 'Remove')"
        @click="onRemove"
      >
        <Icon name="x" aria-hidden="true" />
      </Button>
    </div>

    <!-- An UPLOADED id held after a remount cleared the dropzone's file list. -->
    <p
      v-else-if="model && !Object.keys(progress).length"
      class="flex items-center gap-next-1 text-next-xs text-next-muted-foreground"
    >
      <Icon name="check" class="text-next-success" aria-hidden="true" />
      <span>{{ t('forms.fileInput.uploaded', 'File uploaded') }}</span>
    </p>

    <DiskFilePickerModal
      v-model:open="pickerOpen"
      :accepted-types="acceptedTypes"
      :max-size="maxSize ?? null"
      @select="onPick"
    />
  </div>
</template>
