<script setup lang="ts">
// DiskFilePickerModal — browse the disk and pick ONE existing file for a file field.
//
// Self-contained: it does NOT touch the singleton disk store (which drives the main
// browser and would collide), it fetches folders + files a level at a time via the same
// endpoints. Selecting a file and confirming EMITS it; the host copies it into a temp
// (POST /disk/{id}/copy-to-temp) so a pick and an upload are identical downstream.
//
// Files that don't satisfy the field's accepted types / max size are shown but not
// selectable (dimmed, with a hint) so the user understands why — the same restriction the
// upload dropzone enforces, re-applied here.
import { computed, ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';
import { fileTypeIcon } from './fileIcon';
import { matchesAccept } from './pickerMatch';
import type { DiskFile, DiskFolder, FileListResponse, FolderListResponse } from './types';

const props = withDefaults(
  defineProps<{
    /** The field's accepted mime types / extensions (empty = anything). */
    acceptedTypes?: string[];
    /** Max size in MEGABYTES (the field stores MB); null = no cap. */
    maxSize?: number | null;
  }>(),
  { acceptedTypes: () => [], maxSize: null },
);

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ (e: 'select', file: DiskFile): void }>();

const { t } = useI18n();

/** The trail down to the current level, root-first ({id, name} pairs). */
const trail = ref<Array<{ id: string; name: string }>>([]);
const folders = ref<DiskFolder[]>([]);
const files = ref<DiskFile[]>([]);
const selected = ref<DiskFile | null>(null);
const loading = ref(false);
const errored = ref(false);
const filesCursor = ref<string | null>(null);
const loadingMore = ref(false);

/** Supersedes work in flight so a rapid descend/jump can never render a stale level. */
let token = 0;

const currentFolderId = computed(() => (trail.value.length ? trail.value[trail.value.length - 1].id : null));

/** The files query — mirrors the disk store: folder_id ALWAYS sent (empty = root), source=disk. */
function filesUrl(folderId: string | null, cursor?: string | null): string {
  const params = new URLSearchParams();
  params.set('folder_id', folderId ?? '');
  params.set('source', 'disk');
  if (cursor) params.set('cursor', cursor);
  return `/disk?${params.toString()}`;
}

/** Whether a file may be picked: within the size cap AND matching the accepted types. */
function isEligible(file: DiskFile): boolean {
  if (props.maxSize != null && file.size > props.maxSize * 1024 * 1024) return false;
  return matchesAccept(file.name, file.mime_type, props.acceptedTypes);
}

async function openLevel(folderId: string | null): Promise<void> {
  const myToken = (token += 1);
  loading.value = true;
  errored.value = false;
  selected.value = null;
  folders.value = [];
  files.value = [];
  filesCursor.value = null;
  try {
    const folderQuery = folderId ? `?parent_id=${encodeURIComponent(folderId)}` : '';
    const [folderRes, fileRes] = await Promise.all([
      api.get<FolderListResponse>(`/disk/folders${folderQuery}`),
      api.get<FileListResponse>(filesUrl(folderId)),
    ]);
    if (myToken !== token) return;
    folders.value = folderRes.data ?? [];
    files.value = fileRes.data ?? [];
    filesCursor.value = fileRes.meta?.next_cursor ?? null;
  } catch {
    if (myToken !== token) return;
    errored.value = true;
  } finally {
    if (myToken === token) loading.value = false;
  }
}

async function loadMore(): Promise<void> {
  if (!filesCursor.value || loadingMore.value) return;
  const myToken = token;
  loadingMore.value = true;
  try {
    const res = await api.get<FileListResponse>(filesUrl(currentFolderId.value, filesCursor.value));
    if (myToken !== token) return; // level switched mid-flight
    files.value = [...files.value, ...(res.data ?? [])];
    filesCursor.value = res.meta?.next_cursor ?? null;
  } catch {
    // A failed page just leaves the button in place to retry; keep what we have.
  } finally {
    if (myToken === token) loadingMore.value = false;
  }
}

function descend(folder: DiskFolder): void {
  trail.value = [...trail.value, { id: folder.id, name: folder.name }];
  void openLevel(folder.id);
}

/** Jump to a crumb: index -1 = the root, else that trail entry. */
function jumpTo(index: number): void {
  trail.value = trail.value.slice(0, index + 1);
  void openLevel(index < 0 ? null : trail.value[index].id);
}

function pick(file: DiskFile): void {
  if (isEligible(file)) selected.value = file;
}

function confirm(): void {
  if (!selected.value) return;
  emit('select', selected.value);
  open.value = false;
}

function retry(): void {
  void openLevel(currentFolderId.value);
}

// (Re)start at the root each time the modal opens. `immediate` so a modal mounted already-open
// (a pick reopened, a test) loads its root too, not only on a later false→true toggle.
watch(
  open,
  (isOpen) => {
    if (isOpen) {
      trail.value = [];
      void openLevel(null);
    }
  },
  { immediate: true },
);
</script>

<template>
  <Modal v-model:open="open" size="lg" :aria-label="t('disk.picker.fileTitle', 'Choose a file')">
    <template #title>{{ t('disk.picker.fileTitle', 'Choose a file') }}</template>

    <div class="flex flex-col gap-next-3">
      <!-- Trail: Dysk / A / B — each crumb jumps back. -->
      <div class="flex flex-wrap items-center gap-next-1 text-next-sm">
        <button
          type="button"
          class="rounded-next-sm px-next-1 font-next-medium text-next-fg hover:bg-next-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
          @click="jumpTo(-1)"
        >
          {{ t('disk.title', 'Disk') }}
        </button>
        <template v-for="(crumb, index) in trail" :key="crumb.id">
          <Icon name="chevron-right" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
          <button
            type="button"
            class="rounded-next-sm px-next-1 font-next-medium text-next-fg hover:bg-next-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
            @click="jumpTo(index)"
          >
            {{ crumb.name }}
          </button>
        </template>
      </div>

      <div class="rounded-next-md border border-next-border">
        <div v-if="loading" class="flex flex-col gap-next-1 p-next-3">
          <Skeleton v-for="n in 5" :key="n" class="h-9 w-full rounded-next-sm" />
        </div>

        <div
          v-else-if="errored"
          class="flex items-center justify-between gap-next-2 p-next-3 text-next-sm text-next-danger"
          role="alert"
        >
          <span class="flex items-center gap-next-1">
            <Icon name="alert-circle" class="shrink-0" aria-hidden="true" />
            {{ t('disk.picker.loadFilesError', 'Couldn’t load files.') }}
          </span>
          <button
            type="button"
            class="shrink-0 rounded-next-sm px-next-1 font-next-medium text-next-fg underline hover:bg-next-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
            @click="retry"
          >
            {{ t('common.retry', 'Retry') }}
          </button>
        </div>

        <div v-else class="max-h-80 overflow-y-auto p-next-2">
          <ul class="flex flex-col gap-next-0_5">
            <!-- Subfolders: click to descend. -->
            <li v-for="folder in folders" :key="folder.id">
              <button
                type="button"
                class="flex w-full items-center gap-next-2 rounded-next-sm px-next-2 py-next-2 text-left text-next-sm text-next-fg hover:bg-next-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
                @click="descend(folder)"
              >
                <Icon name="folder" class="shrink-0 text-next-primary" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate">{{ folder.name }}</span>
                <Icon name="chevron-right" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
              </button>
            </li>

            <!-- Files: click to select (ineligible ones are shown but disabled). -->
            <li v-for="file in files" :key="file.id">
              <button
                type="button"
                :disabled="!isEligible(file)"
                :aria-pressed="selected?.id === file.id"
                :title="!isEligible(file) ? t('disk.picker.fileNotAllowed', 'This file doesn’t match what this field accepts.') : undefined"
                class="flex w-full items-center gap-next-2 rounded-next-sm px-next-2 py-next-2 text-left text-next-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
                :class="[
                  selected?.id === file.id ? 'bg-next-primary/10 ring-1 ring-next-primary' : 'hover:bg-next-accent',
                  isEligible(file) ? 'text-next-fg' : 'cursor-not-allowed opacity-50',
                ]"
                @click="pick(file)"
              >
                <Icon :name="fileTypeIcon(file.type)" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate">{{ file.name }}</span>
                <span class="shrink-0 text-next-xs text-next-muted-foreground">{{ file.size_human }}</span>
                <Icon
                  v-if="selected?.id === file.id"
                  name="check"
                  class="shrink-0 text-next-primary"
                  aria-hidden="true"
                />
              </button>
            </li>
          </ul>

          <!-- Empty level: no subfolders and no files. -->
          <p
            v-if="!folders.length && !files.length"
            class="px-next-2 py-next-4 text-center text-next-sm text-next-muted-foreground"
          >
            {{ t('disk.picker.emptyLevel', 'Nothing here.') }}
          </p>

          <div v-if="filesCursor" class="pt-next-2">
            <Button variant="ghost" size="sm" class="w-full" :loading="loadingMore" @click="loadMore">
              {{ t('disk.picker.loadMore', 'Load more') }}
            </Button>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <Button variant="outline" type="button" @click="open = false">
        {{ t('common.cancel', 'Cancel') }}
      </Button>
      <Button variant="primary" type="button" :disabled="!selected" @click="confirm">
        {{ t('disk.picker.choose', 'Choose') }}
      </Button>
    </template>
  </Modal>
</template>
