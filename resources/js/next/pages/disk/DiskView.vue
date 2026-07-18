<script setup lang="ts">
// DiskView — the workspace file manager (P1: a Windows-style tile grid).
//
// The current folder is URL-driven via `?folder=<id>` (deep-linkable, back-button
// friendly); the store owns the level's server state. The grid reads, top to
// bottom: an "up" tile (except at the root), then folder tiles, then file tiles,
// with the folder path shown as breadcrumbs above. Files paginate (infinite
// scroll); folders come a level at a time.
//
// Actions (upload / new folder / move / trash) and the file drawer land in the
// next slices — a file tile currently opens the binary in a new tab (it carries
// the session cookie, so inline-safe types render and the rest download).
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { storeToRefs } from 'pinia';
import PageHeader from '../../ui/patterns/PageHeader.vue';
import Breadcrumbs, { type BreadcrumbItem } from '../../ui/navigation/Breadcrumbs.vue';
import EmptyState from '../../ui/data/EmptyState.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import Button from '../../ui/primitives/Button.vue';
import Modal from '../../ui/overlay/Modal.vue';
import ConfirmDialog from '../../ui/overlay/ConfirmDialog.vue';
import FormField from '../../ui/forms/FormField.vue';
import TextInput from '../../ui/forms/TextInput.vue';
import DiskTile from './DiskTile.vue';
import DiskRestoreModal from './DiskRestoreModal.vue';
import DiskMoveModal, { type MoveTarget } from './DiskMoveModal.vue';
import DiskCopyModal from './DiskCopyModal.vue';
import DiskFileDrawer from './DiskFileDrawer.vue';
import { useDiskStore, isVirtualId, isTrashId, TRASH_ID } from '../../app/stores/disk';
import { useInfiniteScroll } from '../../app/composables/useInfiniteScroll';
import { useToast } from '../../app/composables/useToast';
import { useI18n } from '../../app/i18n';
import { bucketLabel } from './bucketLabel';
import type { DiskFile, DiskFolder, DiskVirtualNode } from './types';

const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();
const toast = useToast();

const store = useDiskStore();
const { folders, files, breadcrumbs, loading, error, filesHasMore, filesLoading, virtualNodes, resourceTree } =
  storeToRefs(store);

/** The level id from the query: null (root), a folder uuid, or a `sys:res…` virtual id. */
const folderId = computed<string | null>(() => {
  const q = route.query.folder;
  return typeof q === 'string' && q ? q : null;
});

/** In the read-only resources tree (rather than a real folder). */
const isVirtual = computed(() => isVirtualId(folderId.value));
/** In the disk trash. */
const isTrash = computed(() => isTrashId(folderId.value));
/** The real disk root: shows the Zasoby + Kosz entries + the top-level folders. */
const atDiskRoot = computed(() => folderId.value === null);
/** The up tile appears everywhere except the very top. */
const showUp = computed(() => folderId.value !== null);

// Load whenever the target folder changes (initial mount included).
watch(
  folderId,
  (id) => void store.open(id),
  { immediate: true },
);

/** Navigate to a folder (null = root) by pushing the `?folder=` query. */
function goTo(id: string | null): void {
  router.push({ name: 'next.disk', query: id ? { folder: id } : {} });
}

/** The parent of the current level (null = the disk root). */
const parentId = computed<string | null>(() => {
  const id = folderId.value;
  if (isTrash.value) return null; // the trash sits directly under the root
  if (isVirtual.value) {
    // sys:res[:<type>[:<key>]] → drop the last segment; the Zasoby root's parent is the disk root.
    if (id === 'sys:res') return null;
    return id!.slice(0, id!.lastIndexOf(':'));
  }
  // A real folder's breadcrumbs end at itself, so its parent is the one before it.
  const trail = breadcrumbs.value;
  return trail.length >= 2 ? trail[trail.length - 2].id : null;
});

const crumbs = computed<BreadcrumbItem[]>(() => {
  const base: BreadcrumbItem[] = [
    { label: t('disk.title', 'Disk'), to: { name: 'next.disk', query: {} }, icon: 'folder' },
  ];
  if (isTrash.value) {
    return [...base, { label: t('disk.trash.root', 'Trash'), to: { name: 'next.disk', query: { folder: TRASH_ID } }, icon: 'trash' }];
  }
  if (!isVirtual.value) {
    return [
      ...base,
      ...breadcrumbs.value.map((f) => ({ label: f.name, to: { name: 'next.disk', query: { folder: f.id } } })),
    ];
  }
  // Zasoby path: Dysk → Zasoby → [type] → [bucket], all localized here (the store keeps labels raw).
  const [, , typeAlias, bucketKey] = (folderId.value ?? '').split(':');
  const out: BreadcrumbItem[] = [
    ...base,
    { label: t('disk.resources.root', 'Resources'), to: { name: 'next.disk', query: { folder: 'sys:res' } }, icon: 'inbox' },
  ];
  const type = typeAlias ? resourceTree.value.find((r) => r.type === typeAlias) : undefined;
  if (typeAlias) {
    out.push({ label: type ? t(type.label_key) : typeAlias, to: { name: 'next.disk', query: { folder: `sys:res:${typeAlias}` } } });
  }
  if (typeAlias && bucketKey) {
    const gran = type?.buckets.find((b) => b.key === bucketKey)?.granularity;
    out.push({ label: bucketLabel(bucketKey, gran, locale.value), to: { name: 'next.disk', query: { folder: `sys:res:${typeAlias}:${bucketKey}` } } });
  }
  return out;
});

function openFolder(folder: DiskFolder): void {
  goTo(folder.id);
}

/**
 * A file tile's primary click. In a normal folder/bucket it opens the detail drawer (preview +
 * metadata + history); in the trash it opens the restore dialog — but only when the server says
 * this member may restore it (the trash lists the whole workspace's deletions).
 */
function onFileActivate(file: DiskFile): void {
  if (!isTrash.value) {
    drawerFile.value = file;
    drawerOpen.value = true;
  } else if (file.can_be_restored) {
    openRestoreFor(file);
  }
}

// --- File detail drawer ------------------------------------------------------
const drawerOpen = ref(false);
const drawerFile = ref<DiskFile | null>(null);


// --- Resource ("Zasoby") node tiles — the host localizes their raw labels. ---
function nodeLabel(node: DiskVirtualNode): string {
  if (node.labelKey) return t(node.labelKey);
  if (node.bucketKey) return bucketLabel(node.bucketKey, node.granularity, locale.value);
  return '';
}
function nodeMeta(node: DiskVirtualNode): string {
  return t('disk.browser.itemsCount', '{count} items', { count: node.files_count });
}
function nodeIcon(node: DiskVirtualNode): string {
  return node.icon ?? (node.bucketKey ? 'calendar' : 'folder');
}

// The disk root always carries the Zasoby entry, so it is never "empty".
const isEmpty = computed(
  () =>
    !loading.value &&
    !error.value &&
    !atDiskRoot.value &&
    folders.value.length === 0 &&
    files.value.length === 0 &&
    virtualNodes.value.length === 0,
);

// --- Upload (into the current folder; the root is a valid target now) --------
const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);

function triggerUpload(): void {
  fileInput.value?.click();
}

async function onFilesPicked(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const picked = Array.from(input.files ?? []);
  input.value = ''; // allow re-picking the same file
  if (!picked.length) return;

  // Capture the destination ONCE: the grid stays interactive during the batch, so reading the
  // reactive folderId per-iteration would re-target later files if the user navigates mid-batch
  // (into another folder, or into a sys:trash/sys:res level whose id is not a valid folder).
  const target = folderId.value;

  uploading.value = true;
  let failed = 0;
  for (const file of picked) {
    try {
      await store.uploadFile(file, target);
    } catch {
      failed += 1;
    }
  }
  uploading.value = false;

  if (failed) {
    toast.danger(t('disk.browser.uploadError', 'Some files could not be uploaded.'));
  } else {
    toast.success(t('disk.browser.uploaded', 'Upload complete.'));
  }
}

// --- New folder --------------------------------------------------------------
const newFolderOpen = ref(false);
const newFolderName = ref('');
const newFolderParent = ref<string | null>(null);
const creatingFolder = ref(false);

function openNewFolder(): void {
  newFolderName.value = '';
  // Freeze the parent at open time — the modal can outlive a navigation (browser Back changes
  // the query while it is open), and we must not create the folder under a since-changed level.
  newFolderParent.value = folderId.value;
  newFolderOpen.value = true;
}

async function submitNewFolder(): Promise<void> {
  const name = newFolderName.value.trim();
  if (!name || creatingFolder.value) return;
  creatingFolder.value = true;
  try {
    await store.createFolder(name, newFolderParent.value);
    newFolderOpen.value = false;
    toast.success(t('disk.browser.folderCreated', 'Folder created.'));
  } catch {
    toast.danger(t('disk.browser.folderError', 'Could not create the folder.'));
  } finally {
    creatingFolder.value = false;
  }
}

// --- Rename (folder or file) -------------------------------------------------
type TileTarget = { kind: 'folder' | 'file'; id: string; name: string };
const renameTarget = ref<TileTarget | null>(null);
const renameName = ref('');
const renameOpen = ref(false);
const renaming = ref(false);

function startRename(target: TileTarget): void {
  renameTarget.value = target;
  renameName.value = target.name;
  renameOpen.value = true;
}

async function submitRename(): Promise<void> {
  const target = renameTarget.value;
  const name = renameName.value.trim();
  if (!target || !name || renaming.value) return;
  renaming.value = true;
  try {
    if (target.kind === 'folder') await store.renameFolder(target.id, name);
    else await store.renameFile(target.id, name);
    renameOpen.value = false;
    toast.success(t('disk.browser.renamed', 'Renamed.'));
  } catch {
    toast.danger(t('disk.browser.renameError', 'Could not rename.'));
  } finally {
    renaming.value = false;
  }
}

// --- Move (to another folder) ------------------------------------------------
const moveTarget = ref<MoveTarget | null>(null);
const moveOpen = ref(false);

function startMove(target: MoveTarget): void {
  moveTarget.value = target;
  moveOpen.value = true;
}

// --- Copy (duplicate a file) -------------------------------------------------
const copyTarget = ref<{ id: string; name: string } | null>(null);
const copyOpen = ref(false);
// Copy AND move default their destination into the CURRENT real folder; a virtual/trash level
// has no real folder, so they fall back to the root.
const currentRealFolder = computed<string | null>(() => (isVirtual.value || isTrash.value ? null : folderId.value));

function startCopy(file: DiskFile): void {
  copyTarget.value = { id: file.id, name: file.name };
  copyOpen.value = true;
}

// --- Delete (to the disk trash) ----------------------------------------------
const deleteTarget = ref<TileTarget | null>(null);
const deleteOpen = ref(false);
const deleting = ref(false);

function startDelete(target: TileTarget): void {
  deleteTarget.value = target;
  deleteOpen.value = true;
}

async function confirmDelete(): Promise<void> {
  const target = deleteTarget.value;
  if (!target) return;
  deleting.value = true;
  try {
    if (target.kind === 'folder') await store.trashFolder(target.id);
    else await store.trashFile(target.id);
    deleteOpen.value = false;
    toast.success(t('disk.browser.deleted', 'Moved to trash.'));
  } catch (err: unknown) {
    const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
    toast.danger(message ?? t('disk.browser.deleteError', 'Could not delete.'));
  } finally {
    deleting.value = false;
  }
}

// --- Trash: restore + delete forever (B6e) -----------------------------------
const restoreModalOpen = ref(false);
const restoreModalFile = ref<DiskFile | null>(null);

function openRestoreFor(file: DiskFile): void {
  restoreModalFile.value = file;
  restoreModalOpen.value = true;
}

/** Folder restore is one step (no P5 matrix — a trashed folder is empty by construction). */
async function restoreFolderNow(folder: DiskFolder): Promise<void> {
  try {
    await store.restoreFolder(folder.id);
    toast.success(t('disk.trash.folderRestored', 'Folder restored.'));
  } catch {
    toast.danger(t('disk.trash.folderRestoreError', 'Could not restore the folder.'));
  }
}

const forceTarget = ref<DiskFile | null>(null);
const forceOpen = ref(false);
const forceDeleting = ref(false);

function startForceDelete(file: DiskFile): void {
  forceTarget.value = file;
  forceOpen.value = true;
}

async function confirmForceDelete(): Promise<void> {
  const target = forceTarget.value;
  if (!target) return;
  forceDeleting.value = true;
  try {
    await store.forceDeleteFile(target.id);
    forceOpen.value = false;
    toast.success(t('disk.trash.deletedForever', 'Permanently deleted.'));
  } catch {
    toast.danger(t('disk.trash.deleteForeverError', 'Could not delete the file.'));
  } finally {
    forceDeleting.value = false;
  }
}

// Files paginate; the sentinel sits after the grid.
const { sentinelRef } = useInfiniteScroll({
  onLoadMore: () => void store.loadMoreFiles(),
  canLoadMore: () => filesHasMore.value && !filesLoading.value && !loading.value,
});
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-next-6">
    <PageHeader :title="t('disk.title', 'Disk')" :description="t('disk.subtitle', 'Your workspace files and folders.')" icon="folder">
      <!-- The resources tree and the trash are READ-ONLY: no upload / new-folder there. -->
      <template v-if="!isVirtual && !isTrash" #actions>
        <Button variant="outline" leading-icon="folder" @click="openNewFolder">
          {{ t('disk.browser.newFolder', 'New folder') }}
        </Button>
        <Button leading-icon="upload" :loading="uploading" @click="triggerUpload">
          {{ t('disk.browser.upload', 'Upload') }}
        </Button>
        <!-- The real file picker, hidden; the Upload button proxies to it. -->
        <input
          ref="fileInput"
          type="file"
          multiple
          class="sr-only"
          tabindex="-1"
          aria-hidden="true"
          @change="onFilesPicked"
        />
      </template>
    </PageHeader>

    <Breadcrumbs :items="crumbs" :max-visible="5" :aria-label="t('disk.browser.breadcrumbs', 'Folder path')" />

    <!-- Error -->
    <EmptyState
      v-if="error"
      icon="alert-triangle"
      :title="t('disk.browser.errorTitle', 'Couldn’t load this folder')"
      :description="error.startsWith('disk.') ? t(error, 'Something went wrong.') : error"
    />

    <!-- Loading: a grid of skeleton tiles that mimic the real tile. -->
    <div v-else-if="loading" class="grid grid-cols-2 gap-next-3 next-sm:grid-cols-3 next-lg:grid-cols-5">
      <Skeleton v-for="n in 12" :key="n" class="aspect-[4/5] w-full rounded-next-lg" />
    </div>

    <!-- Empty (the trash gets its own copy — you cannot upload into it). The up-tile stays
         reachable so an empty deep folder is never a dead end. -->
    <div v-else-if="isEmpty" class="flex flex-col gap-next-6">
      <div v-if="showUp" class="grid grid-cols-2 gap-next-3 next-sm:grid-cols-3 next-lg:grid-cols-5">
        <DiskTile kind="up" @activate="goTo(parentId)" />
      </div>
      <EmptyState
        :icon="isTrash ? 'trash' : 'folder'"
        :title="isTrash ? t('disk.trash.emptyTitle', 'The trash is empty') : t('disk.browser.emptyTitle', 'This folder is empty')"
        :description="isTrash ? t('disk.trash.emptyBody', 'Files you delete from the disk land here.') : t('disk.browser.emptyBody', 'Upload a file or create a folder to get started.')"
      />
    </div>

    <!-- The tile grid. -->
    <template v-else>
      <div class="grid grid-cols-2 gap-next-3 next-sm:grid-cols-3 next-lg:grid-cols-5">
        <!-- Up a level (never at the very top). -->
        <DiskTile v-if="showUp" kind="up" @activate="goTo(parentId)" />

        <!-- The read-only "Zasoby" entry, only at the disk root. -->
        <DiskTile
          v-if="atDiskRoot"
          kind="resource"
          :label="t('disk.resources.root', 'Resources')"
          icon="inbox"
          @activate="goTo('sys:res')"
        />

        <!-- The trash entry, only at the disk root. -->
        <DiskTile
          v-if="atDiskRoot"
          kind="resource"
          :label="t('disk.trash.root', 'Trash')"
          icon="trash"
          @activate="goTo(TRASH_ID)"
        />

        <!-- Real subfolders (folder view), or trashed folders (trash view — restore only). -->
        <DiskTile
          v-for="folder in folders"
          :key="`folder-${folder.id}`"
          kind="folder"
          :folder="folder"
          :menu="isTrash ? 'trash' : 'default'"
          @activate="isTrash ? undefined : openFolder(folder)"
          @rename="startRename({ kind: 'folder', id: folder.id, name: folder.name })"
          @move="startMove({ kind: 'folder', id: folder.id, name: folder.name })"
          @delete="startDelete({ kind: 'folder', id: folder.id, name: folder.name })"
          @restore="restoreFolderNow(folder)"
        />

        <!-- Resource-tree nodes: types, then their date buckets. -->
        <DiskTile
          v-for="node in virtualNodes"
          :key="`node-${node.id}`"
          kind="resource"
          :label="nodeLabel(node)"
          :meta="nodeMeta(node)"
          :icon="nodeIcon(node)"
          @activate="goTo(node.id)"
        />

        <!-- Files (folder view + resource bucket view + trash). A trashed file's binary
             cannot be served (its binding excludes soft-deleted rows), so activation in the
             trash opens the restore dialog instead (only when the user may restore it). -->
        <DiskTile
          v-for="file in files"
          :key="`file-${file.id}`"
          kind="file"
          :file="file"
          :menu="isTrash ? 'trash' : 'default'"
          @activate="onFileActivate(file)"
          @rename="startRename({ kind: 'file', id: file.id, name: file.name })"
          @copy="startCopy(file)"
          @move="startMove({ kind: 'file', id: file.id, name: file.name })"
          @delete="startDelete({ kind: 'file', id: file.id, name: file.name })"
          @restore="openRestoreFor(file)"
          @force-delete="startForceDelete(file)"
        />

        <!-- Next-page load (cursor pagination): skeleton tiles that mimic the real tile,
             per the design-system rule (a spinner + "Loading…" is the named anti-pattern). -->
        <template v-if="filesLoading">
          <Skeleton v-for="n in 6" :key="`more-${n}`" class="aspect-[4/5] w-full rounded-next-lg" />
        </template>
      </div>

      <!-- Infinite-scroll sentinel (after the grid). -->
      <div ref="sentinelRef" class="h-px w-full" aria-hidden="true" />
    </template>

    <!-- New folder modal. -->
    <Modal v-model:open="newFolderOpen" size="sm" :aria-label="t('disk.browser.newFolder', 'New folder')">
      <template #title>{{ t('disk.browser.newFolder', 'New folder') }}</template>
      <FormField :label="t('disk.browser.folderName', 'Folder name')">
        <TextInput
          v-model="newFolderName"
          :placeholder="t('disk.browser.folderNamePlaceholder', 'e.g. Campaigns')"
          :aria-label="t('disk.browser.folderName', 'Folder name')"
          @keydown.enter="submitNewFolder"
        />
      </FormField>
      <template #footer>
        <Button variant="outline" type="button" @click="newFolderOpen = false">
          {{ t('common.cancel', 'Cancel') }}
        </Button>
        <Button variant="primary" type="button" :disabled="!newFolderName.trim()" :loading="creatingFolder" @click="submitNewFolder">
          {{ t('common.create', 'Create') }}
        </Button>
      </template>
    </Modal>

    <!-- Rename modal (folder or file). -->
    <Modal v-model:open="renameOpen" size="sm" :aria-label="t('disk.browser.rename', 'Rename')">
      <template #title>{{ t('disk.browser.rename', 'Rename') }}</template>
      <FormField :label="t('disk.browser.name', 'Name')">
        <TextInput
          v-model="renameName"
          :aria-label="t('disk.browser.name', 'Name')"
          @keydown.enter="submitRename"
        />
      </FormField>
      <template #footer>
        <Button variant="outline" type="button" @click="renameOpen = false">
          {{ t('common.cancel', 'Cancel') }}
        </Button>
        <Button variant="primary" type="button" :disabled="!renameName.trim()" :loading="renaming" @click="submitRename">
          {{ t('common.save', 'Save') }}
        </Button>
      </template>
    </Modal>

    <!-- Delete confirm (moves to the disk trash — recoverable). -->
    <ConfirmDialog
      v-model:open="deleteOpen"
      variant="danger"
      :title="t('disk.browser.deleteTitle', 'Move to trash?')"
      :message="deleteTarget
        ? t('disk.browser.deleteMessage', 'Move “{name}” to the disk trash.', { name: deleteTarget.name })
        : ''"
      :confirm-label="t('disk.browser.delete', 'Delete')"
      :loading="deleting"
      @confirm="confirmDelete"
    />

    <!-- File detail drawer (preview + metadata + tags + history). -->
    <DiskFileDrawer v-model:open="drawerOpen" :file="drawerFile" />

    <!-- Move dialog (pick a destination folder; defaults to the current folder). -->
    <DiskMoveModal v-model:open="moveOpen" :target="moveTarget" :default-folder-id="currentRealFolder" />

    <!-- Copy dialog (name + destination folder; defaults to the current folder). -->
    <DiskCopyModal v-model:open="copyOpen" :source="copyTarget" :default-folder-id="currentRealFolder" />

    <!-- Restore dialog (the P5 matrix: in place, or pick a target). -->
    <DiskRestoreModal v-model:open="restoreModalOpen" :file="restoreModalFile" />

    <!-- Delete forever confirm (irreversible — the row AND the bytes). -->
    <ConfirmDialog
      v-model:open="forceOpen"
      variant="danger"
      :title="t('disk.trash.deleteForeverTitle', 'Delete forever?')"
      :message="forceTarget
        ? t('disk.trash.deleteForeverMessage', '“{name}” will be permanently deleted. This cannot be undone.', { name: forceTarget.name })
        : ''"
      :confirm-label="t('disk.trash.deleteForever', 'Delete forever')"
      :loading="forceDeleting"
      @confirm="confirmForceDelete"
    />
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
