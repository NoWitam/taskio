<script setup lang="ts">
// DiskPreviewTopBar — the preview's header strip: [type icon][name] | shared actions
// (rename / copy / move / delete / download) | prev | next | close X. Actions are
// capability-gated exactly like the tiles; the shell owns the handlers.
import { computed } from 'vue';
import Button from '../../../ui/primitives/Button.vue';
import Icon, { type IconName } from '../../../ui/primitives/Icon.vue';
import Tooltip from '../../../ui/overlay/Tooltip.vue';
import { useI18n } from '../../../app/i18n';
import { fileTypeIcon } from '../fileIcon';
import type { DiskFile, DiskFolder } from '../types';

const props = defineProps<{
  kind: 'file' | 'folder';
  file?: DiskFile | null;
  folder?: DiskFolder | null;
  hasPrev: boolean;
  hasNext: boolean;
}>();

const emit = defineEmits<{
  (e: 'rename'): void;
  (e: 'copy'): void;
  (e: 'move'): void;
  (e: 'delete'): void;
  (e: 'download'): void;
  (e: 'prev'): void;
  (e: 'next'): void;
  (e: 'close'): void;
}>();

const { t } = useI18n();

const glyph = computed<IconName>(() =>
  props.kind === 'folder' ? 'folder' : fileTypeIcon(props.file?.type),
);
const name = computed(() => (props.kind === 'folder' ? props.folder?.name : props.file?.name) ?? '');

const canRename = computed(() =>
  props.kind === 'folder' ? !!props.folder?.can_be_updated : !!props.file?.can_be_updated,
);
// Copy + download are member-level and file-only (a folder copy would be a recursive job).
const canCopy = computed(() => props.kind === 'file');
const canMove = computed(() =>
  props.kind === 'folder' ? !!props.folder?.can_be_moved : !!props.file?.can_be_moved,
);
const canDelete = computed(() =>
  props.kind === 'folder' ? !!props.folder?.can_be_deleted : !!props.file?.can_be_deleted,
);
</script>

<template>
  <div class="flex flex-wrap items-center gap-next-2 border-b border-next-border pb-next-2">
    <!-- Type icon + name (7 + 8). -->
    <span
      class="flex h-9 w-9 shrink-0 items-center justify-center rounded-next-md bg-next-primary text-next-lg text-next-primary-foreground"
      aria-hidden="true"
    >
      <Icon :name="glyph" />
    </span>
    <h2 class="min-w-0 flex-1 truncate text-next-base font-next-semibold text-next-fg">{{ name }}</h2>

    <!-- Shared actions (6). -->
    <div class="flex items-center gap-next-1">
      <Tooltip v-if="canRename" :label="t('disk.browser.rename', 'Rename')">
        <Button variant="ghost" size="icon-sm" leading-icon="pencil" :aria-label="t('disk.browser.rename', 'Rename')" @click="emit('rename')" />
      </Tooltip>
      <Tooltip v-if="canCopy" :label="t('disk.browser.copy', 'Copy')">
        <Button variant="ghost" size="icon-sm" leading-icon="copy" :aria-label="t('disk.browser.copy', 'Copy')" @click="emit('copy')" />
      </Tooltip>
      <Tooltip v-if="canMove" :label="t('disk.browser.move', 'Move')">
        <Button variant="ghost" size="icon-sm" leading-icon="arrow-right" :aria-label="t('disk.browser.move', 'Move')" @click="emit('move')" />
      </Tooltip>
      <Tooltip v-if="kind === 'file'" :label="t('disk.preview.download', 'Download')">
        <Button variant="ghost" size="icon-sm" leading-icon="download" :aria-label="t('disk.preview.download', 'Download')" @click="emit('download')" />
      </Tooltip>
      <Tooltip v-if="canDelete" :label="t('disk.browser.delete', 'Delete')">
        <Button variant="ghost" size="icon-sm" leading-icon="trash" :aria-label="t('disk.browser.delete', 'Delete')" @click="emit('delete')" />
      </Tooltip>
    </div>

    <span class="mx-next-1 h-5 w-px shrink-0 bg-next-border" aria-hidden="true" />

    <!-- Prev / next through the list (5 + 4) and close (3). -->
    <div class="flex items-center gap-next-1">
      <Button
        variant="outline"
        size="icon-sm"
        leading-icon="arrow-left"
        :disabled="!hasPrev"
        :aria-label="t('disk.preview.prev', 'Previous item')"
        @click="emit('prev')"
      />
      <Button
        variant="outline"
        size="icon-sm"
        leading-icon="arrow-right"
        :disabled="!hasNext"
        :aria-label="t('disk.preview.next', 'Next item')"
        @click="emit('next')"
      />
      <Button
        variant="ghost"
        size="icon-sm"
        leading-icon="x"
        :aria-label="t('disk.preview.close', 'Close preview')"
        @click="emit('close')"
      />
    </div>
  </div>
</template>
