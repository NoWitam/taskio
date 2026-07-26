<script setup lang="ts">
// DiskPreview — the multi-panel PREVIEW MODAL of a Disk file/folder (replaces the old drawers).
// A full-viewport Modal (size="full") over the browser grid, still driven by the `?preview=<id>`
// query on the disk route: the shell resolves the item (current level's lists first, deep links
// via /info / folders show), renders the TYPE-appropriate stage in the main column (image editor
// / text editor / media / pdf / glyph / folder) and the right sidebar tabs (Informacje / Historia
// / Komentarze) — the sidebar panel always fills the remaining viewport height and scrolls
// internally. The shell owns the shared actions (rename / copy / move / delete / download),
// prev/next through the grid-ordered list, the save flows (overwrite via replaceFileContent,
// "Zapisz jako…" via upload) and the unsaved-changes guard — which hooks BOTH
// onBeforeRouteUpdate (prev/next/close/Back are query-only changes that never fire
// onBeforeRouteLeave) and onBeforeRouteLeave (leaving the disk entirely). The Modal's own
// dismissals (Esc / scrim) are CONTROLLED: they route through the same guarded close, so the
// modal never closes past unsaved changes.
import { computed, ref, watch } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router';
import Tabs, { type TabItem } from '../../../ui/navigation/Tabs.vue';
import CommentsPanel from '../../../ui/patterns/CommentsPanel.vue';
import Modal from '../../../ui/overlay/Modal.vue';
import ConfirmDialog from '../../../ui/overlay/ConfirmDialog.vue';
import Button from '../../../ui/primitives/Button.vue';
import FormField from '../../../ui/forms/FormField.vue';
import TextInput from '../../../ui/forms/TextInput.vue';
import Skeleton from '../../../ui/data/Skeleton.vue';
import DiskPreviewTopBar from './DiskPreviewTopBar.vue';
import DiskSaveAsModal from './DiskSaveAsModal.vue';
import DiskMoveModal, { type MoveTarget } from '../DiskMoveModal.vue';
import DiskCopyModal from '../DiskCopyModal.vue';
import ImageStage from './stages/ImageStage.vue';
import ImageAiPanel from './ImageAiPanel.vue';
import TextStage from './stages/TextStage.vue';
import MediaStage from './stages/MediaStage.vue';
import PdfStage from './stages/PdfStage.vue';
import GlyphStage from './stages/GlyphStage.vue';
import FolderStage from './stages/FolderStage.vue';
import FileInfoPanel from './panels/FileInfoPanel.vue';
import FolderInfoPanel from './panels/FolderInfoPanel.vue';
import HistoryPanel from './panels/HistoryPanel.vue';
import { useDiskStore } from '../../../app/stores/disk';
import { useToast } from '../../../app/composables/useToast';
import { useI18n } from '../../../app/i18n';
import { isImageFile } from '../fileIcon';
import { downloadFile } from '../download';
import type { DiskFile, DiskFolder } from '../types';

const props = defineProps<{ itemId: string }>();

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const toast = useToast();
const store = useDiskStore();

// --- Resolution --------------------------------------------------------------
type Resolved = { kind: 'file'; file: DiskFile } | { kind: 'folder'; folder: DiskFolder };

const item = ref<Resolved | null>(null);
const resolving = ref(true);
/** Whether the item came from the loaded level (drives prev/next + the vanish-close watch). */
const fromList = ref(false);

async function resolve(): Promise<void> {
  const id = props.itemId;
  resolving.value = true;
  try {
    const folder = store.folders.find((f) => f.id === id);
    if (folder) {
      fromList.value = true;
      item.value = { kind: 'folder', folder };
      // The grid row is lean — hydrate governance labels for the info panel.
      try {
        item.value = { kind: 'folder', folder: await store.fetchFolder(id) };
      } catch {
        /* keep the partial */
      }
      return;
    }
    const file = store.files.find((f) => f.id === id);
    if (file) {
      fromList.value = true;
      item.value = { kind: 'file', file };
      return;
    }
    // Deep link: not in the loaded level — try the JSON endpoints.
    fromList.value = false;
    try {
      item.value = { kind: 'file', file: await store.fetchFile(id) };
      return;
    } catch {
      /* not a file */
    }
    try {
      item.value = { kind: 'folder', folder: await store.fetchFolder(id) };
      return;
    } catch {
      /* not a folder either */
    }
    item.value = null;
    toast.danger(t('disk.preview.notFound', 'This item no longer exists.'));
    forceClose();
  } finally {
    resolving.value = false;
  }
}

watch(
  () => [props.itemId, store.loading] as const,
  ([, loading]) => {
    if (!loading) void resolve();
  },
  { immediate: true },
);

// A list-resolved item that LEFT the level (deleted / moved away) closes the preview.
watch(
  () => (fromList.value && item.value ? store.folders.some((f) => f.id === props.itemId) || store.files.some((f) => f.id === props.itemId) : true),
  (present) => {
    if (!present) forceClose();
  },
);

const file = computed(() => (item.value?.kind === 'file' ? item.value.file : null));
const folder = computed(() => (item.value?.kind === 'folder' ? item.value.folder : null));

// --- Stage selection ----------------------------------------------------------
const stage = computed<'image' | 'text' | 'media' | 'pdf' | 'glyph' | 'folder' | null>(() => {
  if (!item.value) return null;
  if (item.value.kind === 'folder') return 'folder';
  const f = item.value.file;
  if (isImageFile(f.type, f.mime_type)) return 'image';
  if (f.type === 'text') return 'text';
  if (f.type === 'video' || f.type === 'audio') return 'media';
  if (f.mime_type === 'application/pdf') return 'pdf';
  return 'glyph';
});

// "Zapisz" (overwrite) only for disk-native, editable files — resource files save as a copy.
const canOverwrite = computed(() => !!file.value && file.value.source === 'disk' && file.value.can_be_updated);

// --- Save flows ---------------------------------------------------------------
const saving = ref(false);
const stageRef = ref<{ markSaved?: () => void } | null>(null);
const historyRef = ref<{ refresh?: () => void } | null>(null);

async function onStageSave(payload: { blob: Blob; filename: string }): Promise<void> {
  const current = file.value;
  if (!current || saving.value) return;
  saving.value = true;
  try {
    const updated = await store.replaceFileContent(current.id, payload.blob, payload.filename);
    item.value = { kind: 'file', file: updated };
    stageRef.value?.markSaved?.();
    stageDirty.value = false;
    historyRef.value?.refresh?.();
    toast.success(t('disk.preview.savedContent', 'File saved.'));
  } catch (err: unknown) {
    const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
    toast.danger(message ?? t('disk.preview.saveError', 'Could not save the file.'));
  } finally {
    saving.value = false;
  }
}

const saveAsOpen = ref(false);
const saveAsBlob = ref<Blob | null>(null);
const saveAsName = ref('');

function onStageSaveAs(payload: { blob: Blob; defaultName: string }): void {
  saveAsBlob.value = payload.blob;
  saveAsName.value = payload.defaultName;
  saveAsOpen.value = true;
}

// --- Dirty tracking + the unsaved-changes guard --------------------------------
const stageDirty = ref(false);
const infoDirty = ref(false);
const isDirty = computed(() => stageDirty.value || infoDirty.value);

const guardOpen = ref(false);
let pendingPath: string | null = null;
let bypassGuard = false;

onBeforeRouteUpdate((to, from) => {
  if (bypassGuard) {
    bypassGuard = false;
    return true;
  }
  if (to.query.preview === from.query.preview || !isDirty.value) return true;
  pendingPath = to.fullPath;
  guardOpen.value = true;
  return false;
});

onBeforeRouteLeave((to) => {
  if (bypassGuard) {
    bypassGuard = false;
    return true;
  }
  if (!isDirty.value) return true;
  pendingPath = to.fullPath;
  guardOpen.value = true;
  return false;
});

function confirmLeave(): void {
  guardOpen.value = false;
  bypassGuard = true;
  const target = pendingPath;
  pendingPath = null;
  if (target) void router.push(target);
}

// --- Navigation ----------------------------------------------------------------
// Prev/next walks the grid order: folders first, then files (decision 4).
const flatIds = computed(() => [...store.folders.map((f) => f.id), ...store.files.map((f) => f.id)]);
const index = computed(() => flatIds.value.indexOf(props.itemId));
const hasPrev = computed(() => index.value > 0);
const hasNext = computed(() => index.value >= 0 && index.value < flatIds.value.length - 1);

function openAt(id: string): void {
  void router.replace({ query: { ...route.query, preview: id } });
}

// The Modal is CONTROLLED by the route: it is open exactly while this component is mounted
// (?preview present). Its own dismissals (Esc / scrim / any update:open=false) funnel into the
// guarded router close — if the unsaved guard cancels, previewId stays set and the modal simply
// stays open (the setter never wrote anything).
const modalOpen = computed({
  get: () => true,
  set: (value: boolean) => {
    if (!value) close();
  },
});
function goPrev(): void {
  if (hasPrev.value) openAt(flatIds.value[index.value - 1]);
}
function goNext(): void {
  if (hasNext.value) openAt(flatIds.value[index.value + 1]);
}
function close(): void {
  void router.replace({ query: { ...route.query, preview: undefined } });
}
function forceClose(): void {
  bypassGuard = true;
  close();
}

// --- Shared actions -------------------------------------------------------------
const renameOpen = ref(false);
const renameName = ref('');
const renaming = ref(false);

function startRename(): void {
  renameName.value = (file.value?.name ?? folder.value?.name) ?? '';
  renameOpen.value = true;
}

async function confirmRename(): Promise<void> {
  const name = renameName.value.trim();
  if (!name || renaming.value || !item.value) return;
  renaming.value = true;
  try {
    if (item.value.kind === 'file') {
      await store.renameFile(item.value.file.id, name);
    } else {
      await store.renameFolder(item.value.folder.id, name);
    }
    renameOpen.value = false;
    toast.success(t('disk.browser.renamed', 'Renamed.'));
    await resolve();
    historyRef.value?.refresh?.();
  } catch (err: unknown) {
    const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
    toast.danger(message ?? t('disk.browser.renameError', 'Could not rename.'));
  } finally {
    renaming.value = false;
  }
}

const deleteOpen = ref(false);
const deleting = ref(false);

async function confirmDelete(): Promise<void> {
  if (!item.value || deleting.value) return;
  deleting.value = true;
  try {
    if (item.value.kind === 'file') {
      await store.trashFile(item.value.file.id);
    } else {
      await store.trashFolder(item.value.folder.id);
    }
    deleteOpen.value = false;
    toast.success(t('disk.browser.deleted', 'Moved to trash.'));
    forceClose();
  } catch (err: unknown) {
    const message = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
    toast.danger(message ?? t('disk.browser.deleteError', 'Could not delete.'));
  } finally {
    deleting.value = false;
  }
}

const moveOpen = ref(false);
const moveTarget = computed<MoveTarget | null>(() =>
  item.value
    ? item.value.kind === 'file'
      ? { kind: 'file', id: item.value.file.id, name: item.value.file.name }
      : { kind: 'folder', id: item.value.folder.id, name: item.value.folder.name }
    : null,
);
const currentContainer = computed(() => file.value?.folder_id ?? folder.value?.parent_id ?? null);

const copyOpen = ref(false);
const copySource = computed(() => (file.value ? { id: file.value.id, name: file.value.name } : null));

async function onDownload(): Promise<void> {
  const current = file.value;
  if (!current) return;
  try {
    await downloadFile(current);
  } catch {
    toast.danger(t('disk.browser.openError', 'Could not open the file.'));
  }
}

// --- Panels / tabs ---------------------------------------------------------------
const activeTab = ref<'info' | 'history' | 'comments'>('info');
const commentsCount = ref(0);
const tabs = computed<TabItem<'info' | 'history' | 'comments'>[]>(() => [
  { value: 'info', label: t('disk.preview.tabs.info', 'Details') },
  { value: 'history', label: t('disk.preview.tabs.history', 'History') },
  { value: 'comments', label: t('disk.preview.tabs.comments', 'Comments'), badge: commentsCount.value || undefined },
]);
// The generic comments endpoint keyed on the morph alias — the same {module} convention as
// the changelog (`/file/{id}/comments`, `/folder/{id}/comments`).
const commentsUrl = computed(() => (item.value ? `/${item.value.kind}/${props.itemId}/comments` : null));

function onUpdated(fresh: DiskFile | DiskFolder): void {
  if (!item.value) return;
  item.value = item.value.kind === 'file' ? { kind: 'file', file: fresh as DiskFile } : { kind: 'folder', folder: fresh as DiskFolder };
  infoDirty.value = false;
  historyRef.value?.refresh?.();
}
</script>

<template>
  <Modal
    v-model:open="modalOpen"
    size="full"
    :show-close="false"
    :aria-label="t('disk.preview.title', 'Preview')"
  >
  <section class="flex h-full min-h-0 flex-col gap-next-4" :aria-label="t('disk.preview.title', 'Preview')">
    <DiskPreviewTopBar
      v-if="item"
      class="shrink-0"
      :kind="item.kind"
      :file="file"
      :folder="folder"
      :has-prev="hasPrev"
      :has-next="hasNext"
      @rename="startRename"
      @copy="copyOpen = true"
      @move="moveOpen = true"
      @delete="deleteOpen = true"
      @download="onDownload"
      @prev="goPrev"
      @next="goNext"
      @close="close"
    />

    <div v-if="resolving && !item" class="flex flex-col gap-next-4">
      <Skeleton class="h-12 rounded-next-lg" />
      <Skeleton class="h-96 rounded-next-lg" />
    </div>

    <div v-else-if="item" class="flex min-h-0 flex-1 flex-col gap-next-4 next-lg:flex-row">
      <!-- Main column: the type-specific stage (with its own toolbar row where applicable);
           scrolls on its own when the stage outgrows the modal. -->
      <div class="min-h-0 min-w-0 flex-1 overflow-y-auto pr-next-1">
        <ImageStage
          v-if="stage === 'image' && file"
          ref="stageRef"
          :key="`img-${file.id}`"
          :file="file"
          :can-overwrite="canOverwrite"
          :saving="saving"
          @update:dirty="stageDirty = $event"
          @save="onStageSave"
          @save-as="onStageSaveAs"
        >
          <template #ai="{ editor, disabled }">
            <ImageAiPanel :editor="editor" :disabled="disabled" />
          </template>
        </ImageStage>
        <TextStage
          v-else-if="stage === 'text' && file"
          ref="stageRef"
          :key="`txt-${file.id}`"
          :file="file"
          :can-overwrite="canOverwrite"
          :saving="saving"
          @update:dirty="stageDirty = $event"
          @save="onStageSave"
          @save-as="onStageSaveAs"
        />
        <MediaStage v-else-if="stage === 'media' && file" :key="`med-${file.id}`" :file="file" />
        <PdfStage v-else-if="stage === 'pdf' && file" :key="`pdf-${file.id}`" :file="file" />
        <GlyphStage v-else-if="stage === 'glyph' && file" :file="file" />
        <FolderStage v-else-if="stage === 'folder' && folder" :folder="folder" />
      </div>

      <!-- Right sidebar (1 + 2): tabs over the info / history / comments panels. The panel
           container ALWAYS fills the remaining viewport height; each panel scrolls internally. -->
      <aside class="flex min-h-0 w-full shrink-0 flex-col next-lg:h-full next-lg:w-96">
        <div class="flex min-h-0 flex-1 flex-col rounded-next-lg border border-next-border bg-next-card p-next-3 shadow-next-xs">
          <Tabs v-model="activeTab" :items="tabs" variant="pills" fill :aria-label="t('disk.preview.tabsLabel', 'Preview sections')">
            <template #panel-info>
              <div class="h-full min-h-0 overflow-y-auto pt-next-3">
                <FileInfoPanel
                  v-if="file"
                  :key="`fi-${file.id}`"
                  :file="file"
                  @updated="onUpdated"
                  @update:dirty="infoDirty = $event"
                />
                <FolderInfoPanel
                  v-else-if="folder"
                  :key="`fo-${folder.id}`"
                  :folder="folder"
                  @updated="onUpdated"
                  @update:dirty="infoDirty = $event"
                />
              </div>
            </template>
            <template #panel-history>
              <div class="h-full min-h-0 pt-next-3">
                <HistoryPanel
                  v-if="item"
                  ref="historyRef"
                  :key="`h-${itemId}`"
                  :item-id="itemId"
                  :module="item.kind"
                />
              </div>
            </template>
            <template #panel-comments>
              <div class="flex h-full min-h-0 flex-col pt-next-3">
                <CommentsPanel
                  v-if="commentsUrl"
                  :key="`c-${itemId}`"
                  :comments-url="commentsUrl"
                  class="min-h-0 flex-1"
                  @count="commentsCount = $event"
                />
              </div>
            </template>
          </Tabs>
        </div>
      </aside>
    </div>

    <!-- Rename. -->
    <Modal v-model:open="renameOpen" size="sm" :aria-label="t('disk.browser.rename', 'Rename')">
      <template #title>{{ t('disk.browser.rename', 'Rename') }}</template>
      <FormField :label="t('disk.browser.name', 'Name')" required>
        <TextInput v-model="renameName" :aria-label="t('disk.browser.name', 'Name')" @keydown.enter="confirmRename" />
      </FormField>
      <template #footer>
        <Button variant="outline" type="button" @click="renameOpen = false">{{ t('common.cancel', 'Cancel') }}</Button>
        <Button variant="primary" type="button" :disabled="!renameName.trim()" :loading="renaming" @click="confirmRename">
          {{ t('common.save', 'Save') }}
        </Button>
      </template>
    </Modal>

    <!-- Delete (to the disk trash). -->
    <ConfirmDialog
      v-model:open="deleteOpen"
      variant="danger"
      :title="t('disk.browser.deleteTitle', 'Move to trash?')"
      :message="t('disk.browser.deleteMessage', 'Move “{name}” to the disk trash.', { name: file?.name ?? folder?.name ?? '' })"
      :confirm-label="t('disk.browser.delete', 'Delete')"
      :loading="deleting"
      @confirm="confirmDelete"
    />

    <!-- Move / copy (the browser's modals, reused). -->
    <DiskMoveModal v-model:open="moveOpen" :target="moveTarget" :default-folder-id="currentContainer" />
    <DiskCopyModal v-model:open="copyOpen" :source="copySource" :default-folder-id="currentContainer" />

    <!-- Save as (a new disk file from the edited content). -->
    <DiskSaveAsModal
      v-model:open="saveAsOpen"
      :blob="saveAsBlob"
      :default-name="saveAsName"
      :default-folder-id="file?.folder_id ?? null"
    />

    <!-- Unsaved-changes guard. -->
    <ConfirmDialog
      v-model:open="guardOpen"
      variant="danger"
      :title="t('disk.preview.unsaved.title', 'Discard unsaved changes?')"
      :message="t('disk.preview.unsaved.message', 'You have unsaved changes. Leaving will discard them.')"
      :confirm-label="t('disk.preview.unsaved.leave', 'Discard and leave')"
      @confirm="confirmLeave"
    />
  </section>
  </Modal>
</template>
