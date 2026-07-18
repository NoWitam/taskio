<script setup lang="ts">
// DiskTile — one tile in the Windows-style file manager grid (P1).
//
// Four shapes share the same square affordance so the grid reads uniformly:
//   • 'up'       — the FIRST tile (except at the root): go up a level.
//   • 'folder'   — a subfolder: its name + a "N items" hint; activating opens it.
//   • 'resource' — a node in the read-only "Zasoby" tree (a resource type or a date
//                  bucket): a host-localized label + item count; activating opens it.
//   • 'file'     — a file: a type glyph (later an image thumbnail) + name + size.
//
// The tile body is a real <button> (keyboard-operable) that emits `activate`. Folder
// and file tiles ALSO carry a kebab menu (rename / delete) gated by the server's
// can_be_* capability flags — the menu is a sibling overlay, never nested in the button.
import { computed } from 'vue';
import Icon, { type IconName } from '../../ui/primitives/Icon.vue';
import Button from '../../ui/primitives/Button.vue';
import DropdownMenu from '../../ui/overlay/DropdownMenu.vue';
import DropdownMenuItem from '../../ui/overlay/DropdownMenuItem.vue';
import DiskThumbnail from './DiskThumbnail.vue';
import { useI18n } from '../../app/i18n';
import { fileTypeIcon } from './fileIcon';
import type { DiskFile, DiskFolder } from './types';

const props = withDefaults(
  defineProps<{
    kind: 'up' | 'folder' | 'resource' | 'file';
    folder?: DiskFolder | null;
    file?: DiskFile | null;
    /** For a 'resource' tile: the host pre-localizes these (the store keeps labels raw). */
    label?: string;
    meta?: string;
    icon?: string;
    /** 'trash' swaps the kebab items to restore / delete-forever (the trash view). */
    menu?: 'default' | 'trash';
  }>(),
  { menu: 'default' },
);

const emit = defineEmits<{
  (e: 'activate'): void;
  (e: 'rename'): void;
  (e: 'copy'): void;
  (e: 'move'): void;
  (e: 'delete'): void;
  (e: 'restore'): void;
  (e: 'force-delete'): void;
}>();

const { t } = useI18n();

const glyph = computed<IconName>(() => {
  if (props.kind === 'up') return 'arrow-up';
  if (props.kind === 'folder') return 'folder';
  if (props.kind === 'resource') return (props.icon as IconName | undefined) ?? 'folder';
  return fileTypeIcon(props.file?.type);
});

const label = computed(() => {
  if (props.kind === 'up') return t('disk.browser.up', 'Up a level');
  if (props.kind === 'folder') return props.folder?.name ?? '';
  if (props.kind === 'resource') return props.label ?? '';
  return props.file?.name ?? '';
});

/** The secondary line: item count for a folder/resource, size for a file. */
const meta = computed(() => {
  if (props.kind === 'folder') {
    // "Items" = subfolders + files; counting files alone mislabels a folder of only subfolders.
    const count = (props.folder?.children_count ?? 0) + (props.folder?.files_count ?? 0);
    return t('disk.browser.itemsCount', '{count} items', { count });
  }
  if (props.kind === 'resource') return props.meta ?? '';
  if (props.kind === 'file') return props.file?.size_human ?? '';
  return '';
});

// Actions are only for the mutable shapes (a real folder or a disk file), and only
// where the server says it is allowed.
const inTrash = computed(() => props.menu === 'trash');
const canRename = computed(
  () =>
    !inTrash.value &&
    ((props.kind === 'folder' && !!props.folder?.can_be_updated) ||
      (props.kind === 'file' && !!props.file?.can_be_updated)),
);
const canMove = computed(
  () =>
    !inTrash.value &&
    ((props.kind === 'folder' && !!props.folder?.can_be_moved) ||
      (props.kind === 'file' && !!props.file?.can_be_moved)),
);
// Copy is FILE-only (a folder copy would mean recursively duplicating a subtree) and needs no
// ownership flag: it reads a file anyone in the workspace may read and creates a NEW disk file.
const canCopy = computed(() => !inTrash.value && props.kind === 'file' && !!props.file);
const canDelete = computed(
  () =>
    !inTrash.value &&
    ((props.kind === 'folder' && !!props.folder?.can_be_deleted) ||
      (props.kind === 'file' && !!props.file?.can_be_deleted)),
);
// Trash actions are OWNERSHIP-gated on the server (the trash lists the whole workspace's
// deletions, but a member may only act on what they own), so gate on the server flags —
// never assume — or a non-owner would be offered a Restore/Delete-forever that 403s.
const canRestore = computed(
  () =>
    inTrash.value &&
    ((props.kind === 'folder' && !!props.folder?.can_be_restored) ||
      (props.kind === 'file' && !!props.file?.can_be_restored)),
);
// Only files have a force-delete endpoint (a trashed folder is empty by construction, so
// restoring is its one exit).
const canForceDelete = computed(() => inTrash.value && props.kind === 'file' && !!props.file?.can_be_force_deleted);
const hasActions = computed(
  () =>
    canRename.value ||
    canCopy.value ||
    canMove.value ||
    canDelete.value ||
    canRestore.value ||
    canForceDelete.value,
);
</script>

<template>
  <div class="group relative">
    <button
      type="button"
      class="flex w-full flex-col overflow-hidden rounded-next-lg border border-next-border bg-next-card text-center transition-colors hover:border-next-ring/60 hover:bg-next-accent/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-next-ring"
      :aria-label="label"
      @click="emit('activate')"
    >
      <!-- Media area (fills the tile width, 4:3): a big thumbnail for a file — a real preview
           where cheap (image / text), else its type glyph — or a centered glyph for a folder /
           virtual node. -->
      <span class="flex aspect-[4/3] w-full items-center justify-center overflow-hidden">
        <DiskThumbnail v-if="kind === 'file' && file" :file="file" />
        <span
          v-else
          class="flex h-full w-full items-center justify-center bg-next-primary/5 text-next-4xl text-next-primary"
          aria-hidden="true"
        >
          <Icon :name="glyph" />
        </span>
      </span>
      <!-- Footer: name + a secondary line (item count / size). -->
      <span class="w-full min-w-0 border-t border-next-border/60 px-next-2 py-next-2">
        <span class="block truncate text-next-sm font-next-medium text-next-fg">{{ label }}</span>
        <span v-if="meta" class="block truncate text-next-xs text-next-muted-foreground">{{ meta }}</span>
      </span>
    </button>

    <!-- Kebab menu: appears on hover / focus-within; a sibling of the button, not nested. -->
    <div
      v-if="hasActions"
      class="absolute right-next-1 top-next-1 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100"
    >
      <DropdownMenu placement="bottom-end" :aria-label="t('disk.browser.actions', 'Actions')">
        <template #trigger="{ props: triggerProps }">
          <Button
            v-bind="triggerProps"
            variant="secondary"
            size="icon-sm"
            leading-icon="more-vertical"
            :aria-label="t('disk.browser.actions', 'Actions')"
          />
        </template>
        <DropdownMenuItem v-if="canRename" icon="pencil" :label="t('disk.browser.rename', 'Rename')" @select="emit('rename')">
          {{ t('disk.browser.rename', 'Rename') }}
        </DropdownMenuItem>
        <DropdownMenuItem v-if="canCopy" icon="copy" :label="t('disk.browser.copy', 'Copy')" @select="emit('copy')">
          {{ t('disk.browser.copy', 'Copy') }}
        </DropdownMenuItem>
        <DropdownMenuItem v-if="canMove" icon="arrow-right" :label="t('disk.browser.move', 'Move')" @select="emit('move')">
          {{ t('disk.browser.move', 'Move') }}
        </DropdownMenuItem>
        <DropdownMenuItem v-if="canDelete" icon="trash" destructive :label="t('disk.browser.delete', 'Delete')" @select="emit('delete')">
          {{ t('disk.browser.delete', 'Delete') }}
        </DropdownMenuItem>
        <DropdownMenuItem v-if="canRestore" icon="rotate-ccw" :label="t('disk.trash.restore', 'Restore')" @select="emit('restore')">
          {{ t('disk.trash.restore', 'Restore') }}
        </DropdownMenuItem>
        <DropdownMenuItem
          v-if="canForceDelete"
          icon="trash"
          destructive
          :label="t('disk.trash.deleteForever', 'Delete forever')"
          @select="emit('force-delete')"
        >
          {{ t('disk.trash.deleteForever', 'Delete forever') }}
        </DropdownMenuItem>
      </DropdownMenu>
    </div>
  </div>
</template>
