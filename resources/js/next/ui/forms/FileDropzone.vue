<script setup lang="ts">
// FileDropzone — a file upload input for the "next" frontend.
//
// NOT FieldShell-shaped: it's a dashed dropzone REGION. But it MIRRORS the same
// state-line tokens (focus = ring, error = danger, success = success, disabled =
// muted/dimmed) so it reads as part of the same control family.
//
// Features:
//   • click-to-browse + drag-and-drop (dragover highlight),
//   • `multiple`, `accept` (mime / extension filter + validation),
//   • `maxSize` (per file) + `maxFiles` validation with messaging,
//   • a selected-files list (name, size, type icon, remove button; image
//     thumbnails via URL.createObjectURL, revoked on remove / unmount),
//   • optional per-file progress bars driven by a `:progress` prop MAP keyed by
//     a stable file id — the parent feeds real upload progress; this component
//     does NOT upload anything itself.
//
// Model = `File[]` when `multiple`, else `File | null`. Each kept file gets a
// stable `id` (exposed via `@change` / the `files` payload) so a parent can map
// progress and removals back to the right entry.
//
// A11y: the dropzone is a focusable `role="button"`; Enter/Space open the native
// file dialog. Constraints are wired with `aria-describedby`. Added / rejected
// files are announced via a polite live region. Remove buttons are real
// keyboard-operable buttons. Respects disabled / readonly.
import { computed, onBeforeUnmount, ref } from 'vue';
import Icon, { type IconName } from '../primitives/Icon.vue';
import { useFormField, nextId } from './formField';
import { useI18n } from '../../app/i18n';

const { t } = useI18n();

/** A tracked file kept by the dropzone, with a stable id + optional thumbnail. */
export interface DropzoneFile {
  id: string;
  file: File;
  /** objectURL for image previews (revoked on remove / unmount). */
  thumbnail?: string;
}

const props = withDefaults(
  defineProps<{
    /** Allow selecting more than one file (model becomes File[]). */
    multiple?: boolean;
    /** Accept filter (e.g. "image/*,.pdf"); also validated on drop/select. */
    accept?: string;
    /** Max size per file in BYTES. */
    maxSize?: number;
    /** Max number of files kept (multiple only). */
    maxFiles?: number;
    /**
     * Per-file upload progress, keyed by the file id, 0–100. Drive this from a
     * parent that uploads via the api; the dropzone only renders the bar.
     */
    progress?: Record<string, number>;
    disabled?: boolean;
    readonly?: boolean;
    /** Standalone error styling + message line (FormField provides this otherwise). */
    error?: boolean;
    /** Standalone success styling (FormField provides this otherwise). */
    success?: boolean;
    id?: string;
    describedById?: string;
    ariaLabel?: string;
    /** Headline shown inside the dropzone. */
    title?: string;
    /** Sub-text under the headline. */
    hint?: string;
  }>(),
  {
    multiple: false,
    progress: () => ({}),
    disabled: false,
    readonly: false,
    error: false,
    success: false,
  },
);

const emit = defineEmits<{
  /** Fires with the current tracked files whenever the selection changes. */
  (e: 'change', files: DropzoneFile[]): void;
  /** Fires when one file is removed (so a parent can cancel its upload). */
  (e: 'remove', file: DropzoneFile): void;
  /** Fires with the list of human-readable rejection reasons. */
  (e: 'reject', reasons: string[]): void;
}>();

// Model: File[] in multiple mode, File|null otherwise.
const model = defineModel<File[] | File | null>({ default: null });

const field = useFormField();
const generatedId = nextId('next-dropzone');
const resolvedId = computed(() => props.id ?? field?.id.value ?? generatedId);
const constraintsId = computed(() => `${resolvedId.value}-constraints`);
const liveId = computed(() => `${resolvedId.value}-live`);
const invalidProp = computed(() => props.error || (field?.invalid.value ?? false));
const success = computed(() => props.success || (field?.valid.value ?? false));
const disabled = computed(() => props.disabled || (field?.disabled.value ?? false));
const readonly = computed(() => props.readonly || (field?.readonly.value ?? false));
const inert = computed(() => disabled.value || readonly.value);

// describedBy: our own constraints text + anything the FormField provides.
const describedBy = computed(() => {
  const ids = [constraintsId.value, props.describedById ?? field?.describedById.value].filter(
    Boolean,
  );
  return ids.length ? ids.join(' ') : undefined;
});

// --- Tracked files ---------------------------------------------------------
const tracked = ref<DropzoneFile[]>([]);
const inputRef = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
const liveMessage = ref('');
// Local validation message (separate from a FormField verdict).
const localError = ref('');

function uid(): string {
  return `f_${Math.random().toString(16).slice(2)}_${Date.now().toString(16)}`;
}

function makeThumbnail(file: File): string | undefined {
  if (file.type.startsWith('image/')) return URL.createObjectURL(file);
  return undefined;
}

function revoke(entry: DropzoneFile): void {
  if (entry.thumbnail) URL.revokeObjectURL(entry.thumbnail);
}

onBeforeUnmount(() => tracked.value.forEach(revoke));

// --- accept matching -------------------------------------------------------
function matchesAccept(file: File): boolean {
  if (!props.accept) return true;
  const rules = props.accept.split(',').map((r) => r.trim().toLowerCase()).filter(Boolean);
  if (!rules.length) return true;
  const name = file.name.toLowerCase();
  const type = file.type.toLowerCase();
  return rules.some((rule) => {
    if (rule.startsWith('.')) return name.endsWith(rule);
    if (rule.endsWith('/*')) return type.startsWith(rule.slice(0, rule.indexOf('/') + 1));
    return type === rule;
  });
}

function formatBytes(n: number): string {
  if (!Number.isFinite(n) || n <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  let v = n;
  let i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i += 1;
  }
  return `${v.toFixed(i <= 1 ? 0 : 1)} ${units[i]}`;
}

function typeIcon(file: File): IconName {
  if (file.type.startsWith('image/')) return 'eye';
  if (file.type === 'application/pdf') return 'file-text';
  return 'file-text';
}

// --- ingest + validation ---------------------------------------------------
function ingest(fileList: FileList | File[] | null): void {
  if (!fileList || inert.value) return;
  const incoming = Array.from(fileList);
  if (!incoming.length) return;

  const accepted: DropzoneFile[] = [];
  const reasons: string[] = [];
  // In single mode we replace; in multiple we append within maxFiles.
  let slots =
    props.multiple && props.maxFiles != null
      ? Math.max(0, props.maxFiles - tracked.value.length)
      : Infinity;

  for (const file of incoming) {
    if (!matchesAccept(file)) {
      reasons.push(t('fileDropzone.typeNotAllowed', '{name}: type not allowed', { name: file.name }));
      continue;
    }
    if (props.maxSize != null && file.size > props.maxSize) {
      reasons.push(
        t('fileDropzone.exceedsSize', '{name}: exceeds {size}', {
          name: file.name,
          size: formatBytes(props.maxSize),
        }),
      );
      continue;
    }
    if (props.multiple && slots <= 0) {
      reasons.push(
        t('fileDropzone.maxFiles', '{name}: max {count} files', {
          name: file.name,
          count: props.maxFiles ?? 0,
        }),
      );
      continue;
    }
    accepted.push({ id: uid(), file, thumbnail: makeThumbnail(file) });
    slots -= 1;
    if (!props.multiple) break; // single mode keeps only the first valid file
  }

  if (accepted.length) {
    if (props.multiple) {
      tracked.value = [...tracked.value, ...accepted];
    } else {
      tracked.value.forEach(revoke);
      tracked.value = accepted.slice(0, 1);
    }
    sync();
  }

  localError.value = reasons[0] ?? '';
  announce(accepted.length, reasons);
  if (reasons.length) emit('reject', reasons);
}

function announce(added: number, reasons: string[]): void {
  const parts: string[] = [];
  if (added) {
    parts.push(
      added === 1
        ? t('fileDropzone.fileAddedOne', '1 file added')
        : t('fileDropzone.filesAddedMany', '{count} files added', { count: added }),
    );
  }
  if (reasons.length) {
    parts.push(
      t('fileDropzone.rejected', '{count} rejected: {reasons}', {
        count: reasons.length,
        reasons: reasons.join('; '),
      }),
    );
  }
  liveMessage.value = parts.join('. ');
}

function sync(): void {
  emit('change', tracked.value);
  if (props.multiple) {
    model.value = tracked.value.map((t) => t.file);
  } else {
    model.value = tracked.value[0]?.file ?? null;
  }
}

function remove(entry: DropzoneFile): void {
  if (inert.value) return;
  revoke(entry);
  tracked.value = tracked.value.filter((t) => t.id !== entry.id);
  emit('remove', entry);
  sync();
  liveMessage.value = t('fileDropzone.fileRemoved', '{name} removed', { name: entry.file.name });
  localError.value = '';
}

// --- events ----------------------------------------------------------------
function openDialog(): void {
  if (inert.value) return;
  inputRef.value?.click();
}
function onInputChange(e: Event): void {
  const input = e.target as HTMLInputElement;
  ingest(input.files);
  // Allow re-selecting the same file.
  input.value = '';
}
function onDrop(e: DragEvent): void {
  e.preventDefault();
  dragging.value = false;
  if (inert.value) return;
  ingest(e.dataTransfer?.files ?? null);
}
function onDragOver(e: DragEvent): void {
  e.preventDefault();
  if (inert.value) return;
  dragging.value = true;
}
function onDragLeave(e: DragEvent): void {
  e.preventDefault();
  dragging.value = false;
}
function onKeydown(e: KeyboardEvent): void {
  if (e.key === 'Enter' || e.key === ' ') {
    e.preventDefault();
    openDialog();
  }
}

const showSuccess = computed(() => success.value && !invalidProp.value);

// Constraints summary text for aria-describedby + visible hint fallback.
const constraintsText = computed(() => {
  const bits: string[] = [];
  if (props.accept) bits.push(props.accept);
  if (props.maxSize != null) {
    bits.push(t('fileDropzone.maxSizeEach', 'max {size} each', { size: formatBytes(props.maxSize) }));
  }
  if (props.multiple && props.maxFiles != null) {
    bits.push(t('fileDropzone.upToFiles', 'up to {count} files', { count: props.maxFiles }));
  }
  return bits.join(' · ');
});

function progressFor(id: string): number | undefined {
  const p = props.progress?.[id];
  return typeof p === 'number' ? Math.max(0, Math.min(100, p)) : undefined;
}
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <!-- Dropzone region. State line tokens mirror FieldShell. -->
    <div
      class="next-dropzone relative flex flex-col items-center justify-center gap-next-2 rounded-next-lg border-2 border-dashed px-next-4 py-next-6 text-center transition-colors"
      :class="[
        invalidProp
          ? 'border-next-danger'
          : showSuccess
            ? 'border-next-success'
            : dragging
              ? 'border-next-ring bg-next-accent/40'
              : 'border-next-input',
        inert ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-next-ring/60 hover:bg-next-accent/20',
      ]"
      :role="inert ? undefined : 'button'"
      :tabindex="inert ? -1 : 0"
      :aria-disabled="inert ? 'true' : undefined"
      :aria-describedby="describedBy"
      :aria-label="ariaLabel ?? title ?? t('fileDropzone.label', 'Upload files')"
      @click="openDialog"
      @keydown="onKeydown"
      @dragover="onDragOver"
      @dragleave="onDragLeave"
      @drop="onDrop"
    >
      <input
        :id="resolvedId"
        ref="inputRef"
        type="file"
        class="sr-only"
        :accept="accept"
        :multiple="multiple"
        :disabled="inert"
        tabindex="-1"
        aria-hidden="true"
        @change="onInputChange"
      />

      <span
        class="flex h-10 w-10 items-center justify-center rounded-next-full bg-next-muted text-next-xl text-next-muted-foreground"
        aria-hidden="true"
      >
        <Icon name="upload" />
      </span>
      <p class="text-next-sm font-next-medium text-next-fg">
        {{ title ?? (multiple ? t('fileDropzone.titleMultiple', 'Drop files here or click to browse') : t('fileDropzone.titleSingle', 'Drop a file here or click to browse')) }}
      </p>
      <p v-if="hint || constraintsText" :id="constraintsId" class="text-next-xs text-next-muted-foreground">
        {{ hint ?? constraintsText }}
      </p>
    </div>

    <!-- Local validation message (rejections). -->
    <p
      v-if="localError"
      class="flex items-start gap-next-1 text-next-xs text-next-danger"
      role="alert"
    >
      <Icon name="alert-circle" class="mt-px shrink-0" aria-hidden="true" />
      <span>{{ localError }}</span>
    </p>

    <!-- Selected files list. -->
    <ul v-if="tracked.length" class="flex flex-col gap-next-2">
      <li
        v-for="entry in tracked"
        :key="entry.id"
        class="flex items-center gap-next-3 rounded-next-md border border-next-border bg-next-card p-next-2"
      >
        <!-- Thumbnail / type icon. -->
        <span
          v-if="entry.thumbnail"
          class="h-10 w-10 shrink-0 overflow-hidden rounded-next-sm border border-next-border bg-next-muted"
        >
          <img :src="entry.thumbnail" :alt="entry.file.name" class="h-full w-full object-cover" />
        </span>
        <span
          v-else
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-next-sm bg-next-muted text-next-lg text-next-muted-foreground"
          aria-hidden="true"
        >
          <Icon :name="typeIcon(entry.file)" />
        </span>

        <!-- Name + size + optional progress. -->
        <div class="flex min-w-0 flex-1 flex-col gap-next-1">
          <div class="flex items-baseline justify-between gap-next-2">
            <span class="truncate text-next-sm font-next-medium text-next-fg">{{ entry.file.name }}</span>
            <span class="shrink-0 text-next-xs text-next-muted-foreground">{{ formatBytes(entry.file.size) }}</span>
          </div>
          <div
            v-if="progressFor(entry.id) !== undefined"
            class="h-1.5 w-full overflow-hidden rounded-next-full bg-next-muted"
            role="progressbar"
            :aria-valuenow="progressFor(entry.id)"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="t('fileDropzone.uploading', 'Uploading {name}', { name: entry.file.name })"
          >
            <div
              class="h-full rounded-next-full bg-next-primary transition-[width] duration-[var(--duration-next-normal)]"
              :style="{ width: `${progressFor(entry.id)}%` }"
            />
          </div>
        </div>

        <!-- Remove. -->
        <button
          type="button"
          class="flex shrink-0 items-center justify-center rounded-next-sm p-next-1 text-next-muted-foreground hover:bg-next-accent hover:text-next-fg disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="inert"
          :aria-label="t('fileDropzone.remove', 'Remove {name}', { name: entry.file.name })"
          @click="remove(entry)"
        >
          <Icon name="trash" />
        </button>
      </li>
    </ul>

    <!-- Polite live region announcing added / rejected files. -->
    <p :id="liveId" class="sr-only" role="status" aria-live="polite">{{ liveMessage }}</p>
  </div>
</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
