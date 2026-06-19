<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import FileDropzone, { type DropzoneFile } from '../../ui/forms/FileDropzone.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

// Basic multi-file demo.
const files = ref<File[]>([]);

// Single-file demo.
const avatar = ref<File | null>(null);

// Validation demo (images only, small max).
const images = ref<File[]>([]);

// --- Faked progress demo --------------------------------------------------
// A self-contained, no-backend simulation: when a file is added we tick its
// progress 0→100 on a local timer and feed it back through :progress. This is
// exactly the shape a real parent would use while uploading via the api.
const uploadFiles = ref<File[]>([]);
const progress = ref<Record<string, number>>({});
const timers = new Map<string, ReturnType<typeof setInterval>>();

function onUploadChange(tracked: DropzoneFile[]): void {
  // Start a fake upload for any file we haven't seen yet.
  for (const entry of tracked) {
    if (progress.value[entry.id] === undefined) {
      progress.value = { ...progress.value, [entry.id]: 0 };
      const timer = setInterval(() => {
        const current = progress.value[entry.id] ?? 0;
        const next = Math.min(100, current + Math.round(8 + Math.random() * 14));
        progress.value = { ...progress.value, [entry.id]: next };
        if (next >= 100) {
          clearInterval(timer);
          timers.delete(entry.id);
        }
      }, 350);
      timers.set(entry.id, timer);
    }
  }
}
function onUploadRemove(entry: DropzoneFile): void {
  const timer = timers.get(entry.id);
  if (timer) {
    clearInterval(timer);
    timers.delete(entry.id);
  }
  const { [entry.id]: _drop, ...rest } = progress.value;
  progress.value = rest;
}
onBeforeUnmount(() => timers.forEach((t) => clearInterval(t)));

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'File[] | File | null', default: 'null', description: 'File[] when :multiple, else File | null.' },
  { name: 'multiple', type: 'boolean', default: 'false', description: 'Allow multiple files (model becomes File[]).' },
  { name: 'accept', type: 'string', default: '—', description: 'Mime/extension filter (e.g. "image/*,.pdf"), also validated.' },
  { name: 'maxSize', type: 'number (bytes)', default: '—', description: 'Per-file size limit; oversize files are rejected with a message.' },
  { name: 'maxFiles', type: 'number', default: '—', description: 'Cap on kept files (multiple mode).' },
  { name: 'progress', type: 'Record<id, 0-100>', default: '{}', description: 'Per-file upload progress keyed by file id. Parent-driven; no upload here.' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Inert dropzone (no browse/drop/remove).' },
  { name: 'error / success', type: 'boolean', default: 'false', description: 'Mirror the FieldShell danger / success line on the dashed border.' },
  { name: 'title / hint', type: 'string', default: '—', description: 'Headline + sub-text inside the dropzone (hint falls back to a constraints summary).' },
  { name: 'id / describedById / ariaLabel', type: 'string', default: '—', description: 'Standalone wiring; provided inside a FormField.' },
];

const eventRows: ApiRow[] = [
  { name: 'change', type: 'DropzoneFile[]', description: 'Current tracked files (each { id, file, thumbnail? }) after any add/remove.' },
  { name: 'remove', type: 'DropzoneFile', description: 'One file was removed (cancel its upload in the parent).' },
  { name: 'reject', type: 'string[]', description: 'Human-readable rejection reasons (type/size/count).' },
];
</script>

<template>
  <StoryPage
    title="FileDropzone"
    description="A dashed dropzone region (NOT FieldShell-shaped) that mirrors the same state-line tokens for focus/error/success/disabled. Click-to-browse + drag-and-drop, accept/size/count validation, a selected-files list with image thumbnails and remove buttons, and optional parent-driven per-file progress bars. The component never uploads — it renders progress you feed it."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The dropzone is a focusable <code>role="button"</code>; <kbd>Enter</kbd>/<kbd>Space</kbd> open the native file dialog. The real <code>&lt;input type="file"&gt;</code> is visually hidden.</li>
        <li>Constraints (accept / max size / max files) are wired via <code>aria-describedby</code>.</li>
        <li>Added / rejected files are announced through a polite <code>aria-live</code> region.</li>
        <li>Each progress bar is a <code>role="progressbar"</code> with <code>aria-valuenow/min/max</code>. Remove buttons are real, labelled, keyboard-operable buttons.</li>
        <li>Disabled / readonly make the region inert (non-focusable, no drop/browse/remove).</li>
      </ul>
    </template>

    <StorySection title="Single file" description="Model is File | null; a new pick replaces the previous file.">
      <div class="max-w-md">
        <FileDropzone v-model="avatar" accept="image/*" :max-size="2 * 1024 * 1024" title="Drop an image or click to browse" />
      </div>
    </StorySection>

    <StorySection title="Multiple files" description="Model is File[]; drag a few in or browse. Each shows name, size, type icon, remove.">
      <div class="max-w-md">
        <FileDropzone v-model="files" multiple :max-files="5" />
      </div>
    </StorySection>

    <StorySection title="Validation" description="Images only, max 1 MB each, up to 3. Try a wrong type or an oversize file to see the rejection message + live announcement.">
      <div class="max-w-md">
        <FileDropzone
          v-model="images"
          multiple
          accept="image/png,image/jpeg"
          :max-size="1024 * 1024"
          :max-files="3"
          hint="PNG or JPEG · max 1 MB each · up to 3 files"
        />
      </div>
    </StorySection>

    <StorySection title="Faked progress" description="Self-contained simulation (local timers) — exactly the :progress shape a real uploader would feed. Add files to watch the bars fill.">
      <div class="max-w-md">
        <FileDropzone
          v-model="uploadFiles"
          multiple
          :progress="progress"
          title="Drop files to simulate an upload"
          @change="onUploadChange"
          @remove="onUploadRemove"
        />
      </div>
    </StorySection>

    <StorySection title="States (error / success / disabled)">
      <div class="grid max-w-md gap-next-4">
        <FormField label="Attachments" :error="'At least one file is required.'">
          <FileDropzone error multiple />
        </FormField>
        <FormField label="Attachments" success="2 files ready.">
          <FileDropzone success multiple />
        </FormField>
        <FormField label="Attachments" disabled>
          <FileDropzone disabled multiple />
        </FormField>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
        <p class="text-next-sm text-next-muted-foreground">
          Image thumbnails use <code>URL.createObjectURL</code> and are revoked on remove and on unmount.
          The component does not upload; pass a <code>:progress</code> map keyed by the file
          <code>id</code> (from the <code>change</code> payload) to render upload progress.
        </p>
      </div>
    </StorySection>
  </StoryPage>
</template>
