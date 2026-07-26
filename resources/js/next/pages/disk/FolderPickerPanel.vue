<script setup lang="ts">
// FolderPickerPanel — an INLINE folder browser whose current level IS the selection.
//
// v-model is the folder the user is looking at (null = the workspace root); the host
// renders its own confirm button against that value ("Restore here" / "Move here").
// Kept an inline panel rather than a nested modal so hosts can embed it inside their
// own dialog without stacking overlays. Reused by the restore dialog (B6e) and the
// move dialog (B6f).
//
// Fetches one level at a time via the folders index; `excludeId` hides one folder
// (a future move dialog must not offer the moved folder as its own target).
import { onMounted, ref } from 'vue';
import Icon from '../../ui/primitives/Icon.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';
import type { DiskFolder, FolderListResponse, FolderShowResponse } from './types';

const props = withDefaults(
  defineProps<{
    /** A folder to hide from every listed level (e.g. the folder being moved). */
    excludeId?: string | null;
  }>(),
  { excludeId: null },
);

/** The folder currently open in the panel — the SELECTION (null = the root). */
const model = defineModel<string | null>({ default: null });

const { t } = useI18n();

/** The trail down to the current level, root-first ({id, name} pairs). */
const trail = ref<Array<{ id: string; name: string }>>([]);
const children = ref<DiskFolder[]>([]);
const loading = ref(false);
const errored = ref(false);

let token = 0;

async function openLevel(folderId: string | null): Promise<void> {
  const myToken = (token += 1);
  loading.value = true;
  errored.value = false;
  model.value = folderId;
  try {
    const query = folderId ? `?parent_id=${encodeURIComponent(folderId)}` : '';
    const res = await api.get<FolderListResponse>(`/disk/folders${query}`);
    if (myToken !== token) return;
    children.value = (res.data ?? []).filter((f) => f.id !== props.excludeId);
  } catch {
    if (myToken !== token) return;
    // Surface the failure — a swallowed error rendered as "No subfolders here." would tell the
    // user this level is empty and quietly steer a restore/move to the wrong (usually root) place.
    children.value = [];
    errored.value = true;
  } finally {
    if (myToken === token) loading.value = false;
  }
}

function retry(): void {
  void openLevel(model.value);
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

onMounted(async () => {
  const initial = model.value;
  // When the panel opens AT a folder (a preset default, e.g. the current folder for copy/move),
  // rebuild the trail from that folder's ancestors so the breadcrumb reflects where we are —
  // otherwise it would read just "Disk" while listing a deep folder's children.
  if (initial) {
    try {
      const res = await api.get<FolderShowResponse>(`/disk/folders/${encodeURIComponent(initial)}`);
      // breadcrumbs are the ANCESTORS (root first); the folder itself rides in `data`.
      trail.value = [...(res.breadcrumbs ?? []), res.data].map((f) => ({ id: f.id, name: f.name }));
    } catch {
      // Fall back to an empty trail; the level's children still load below.
    }
  }
  void openLevel(initial);
});
</script>

<template>
  <div class="flex flex-col gap-next-2 rounded-next-md border border-next-border p-next-3">
    <!-- Trail: Dysk / A / B — each crumb jumps back; the LAST level is the selection. -->
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

    <!-- One level of subfolders; clicking descends (and thereby re-selects). -->
    <div v-if="loading" class="flex flex-col gap-next-1">
      <Skeleton v-for="n in 3" :key="n" class="h-8 w-full rounded-next-sm" />
    </div>
    <div v-else-if="errored" class="flex items-center justify-between gap-next-2 px-next-1 py-next-2 text-next-sm text-next-danger" role="alert">
      <span class="flex items-center gap-next-1">
        <Icon name="alert-circle" class="shrink-0" aria-hidden="true" />
        {{ t('disk.picker.loadError', 'Couldn’t load folders.') }}
      </span>
      <button
        type="button"
        class="shrink-0 rounded-next-sm px-next-1 font-next-medium text-next-fg underline hover:bg-next-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
        @click="retry"
      >
        {{ t('common.retry', 'Retry') }}
      </button>
    </div>
    <ul v-else-if="children.length" class="flex max-h-48 flex-col gap-next-0_5 overflow-y-auto">
      <li v-for="folder in children" :key="folder.id">
        <button
          type="button"
          class="flex w-full items-center gap-next-2 rounded-next-sm px-next-2 py-next-1 text-left text-next-sm text-next-fg hover:bg-next-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
          @click="descend(folder)"
        >
          <Icon name="folder" class="shrink-0 text-next-primary" aria-hidden="true" />
          <span class="min-w-0 flex-1 truncate">{{ folder.name }}</span>
          <Icon name="chevron-right" class="shrink-0 text-next-muted-foreground" aria-hidden="true" />
        </button>
      </li>
    </ul>
    <p v-else class="px-next-1 py-next-2 text-next-sm text-next-muted-foreground">
      {{ t('disk.picker.noSubfolders', 'No subfolders here.') }}
    </p>
  </div>
</template>
