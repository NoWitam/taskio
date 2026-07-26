<script setup lang="ts">
// EditorActionBar — the image editor's top strip of GLOBAL actions (mode-independent): undo / redo
// / reset on the left, the Save split button on the right. The engine owns the history; this bar
// just reflects `canUndo`/`canRedo`/`canReset` and forwards intent. Save mirrors the preview's
// rule: disk-native + editable files get "Zapisz" (overwrite) with a "Zapisz jako…" menu; others
// get a plain "Zapisz jako…". The stage owns the actual encode + emit.
import Button from '../../../../ui/primitives/Button.vue';
import Tooltip from '../../../../ui/overlay/Tooltip.vue';
import { useI18n } from '../../../../app/i18n';

defineProps<{
  canUndo: boolean;
  canRedo: boolean;
  canReset: boolean;
  /** disk-native + can_be_updated → the "Zapisz" (overwrite) split button is offered. */
  canOverwrite: boolean;
  /** There are unsaved edits (drives the overwrite button's enabled state). */
  dirty: boolean;
  saving: boolean;
  /** The canvas is not editable (loading / load failed) — inert everything. */
  disabled: boolean;
  /** Current view zoom factor (1 = fit); the readout is a "fit" button. */
  zoom: number;
  canZoomIn: boolean;
  canZoomOut: boolean;
}>();

const emit = defineEmits<{
  (e: 'undo'): void;
  (e: 'redo'): void;
  (e: 'reset'): void;
  (e: 'save'): void;
  (e: 'save-as'): void;
  (e: 'zoom-in'): void;
  (e: 'zoom-out'): void;
  (e: 'zoom-fit'): void;
}>();

const { t } = useI18n();
</script>

<template>
  <div class="flex flex-wrap items-center gap-next-2 rounded-next-lg border border-next-border bg-next-card px-next-2 py-next-1_5 shadow-next-xs">
    <div class="flex items-center gap-next-1">
      <Tooltip :label="t('disk.editor.undo', 'Undo')">
        <Button variant="ghost" size="icon-sm" leading-icon="undo" :disabled="disabled || !canUndo" :aria-label="t('disk.editor.undo', 'Undo')" @click="emit('undo')" />
      </Tooltip>
      <Tooltip :label="t('disk.editor.redo', 'Redo')">
        <Button variant="ghost" size="icon-sm" leading-icon="redo" :disabled="disabled || !canRedo" :aria-label="t('disk.editor.redo', 'Redo')" @click="emit('redo')" />
      </Tooltip>
      <Tooltip :label="t('disk.editor.reset', 'Reset all edits')">
        <Button variant="ghost" size="icon-sm" leading-icon="remove-formatting" :disabled="disabled || !canReset" :aria-label="t('disk.editor.reset', 'Reset all edits')" @click="emit('reset')" />
      </Tooltip>
    </div>

    <span class="flex-1" aria-hidden="true" />

    <!-- View zoom (the readout is a "fit to screen" button). -->
    <div class="flex items-center gap-next-1">
      <Tooltip :label="t('disk.editor.zoomOut', 'Zoom out')">
        <Button variant="ghost" size="icon-sm" leading-icon="minus" :disabled="disabled || !canZoomOut" :aria-label="t('disk.editor.zoomOut', 'Zoom out')" @click="emit('zoom-out')" />
      </Tooltip>
      <Tooltip :label="t('disk.editor.zoomFit', 'Fit to screen')">
        <Button variant="ghost" size="sm" :disabled="disabled" :aria-label="t('disk.editor.zoomFit', 'Fit to screen')" @click="emit('zoom-fit')">
          {{ Math.round(zoom * 100) }}%
        </Button>
      </Tooltip>
      <Tooltip :label="t('disk.editor.zoomIn', 'Zoom in')">
        <Button variant="ghost" size="icon-sm" leading-icon="plus" :disabled="disabled || !canZoomIn" :aria-label="t('disk.editor.zoomIn', 'Zoom in')" @click="emit('zoom-in')" />
      </Tooltip>
    </div>

    <span class="flex-1" aria-hidden="true" />

    <Button
      v-if="canOverwrite"
      :disabled="!dirty || disabled"
      :loading="saving"
      leading-icon="check"
      :menu-items="[{ value: 'save-as', label: t('disk.preview.saveAs', 'Save as…'), icon: 'copy' }]"
      :menu-aria-label="t('disk.preview.moreSaveOptions', 'More save options')"
      @click="emit('save')"
      @menu-select="emit('save-as')"
    >
      {{ t('disk.preview.save', 'Save') }}
    </Button>
    <Button v-else :loading="saving" leading-icon="copy" variant="outline" :disabled="disabled" @click="emit('save-as')">
      {{ t('disk.preview.saveAs', 'Save as…') }}
    </Button>
  </div>
</template>
