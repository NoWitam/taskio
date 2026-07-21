<script setup lang="ts">
// ImageStage — the preview's image EDITOR, redesigned (F1-3) into three zones over the canvas:
//   1. EditorActionBar — global undo / redo / reset + the Save split button (mode-independent).
//   2. a Tabs-pills MODE selector — Przekształć / Korekcja / Filtry / AI — showing only the active
//      group's controls (progressive disclosure, instead of one crammed toolbar row).
//   3. the canvas stage (+ the crop overlay/action bar while cropping).
// All editing state + history lives in useImageEditor; the shell owns the save calls (this stage
// emits `save`/`save-as` with the encoded canvas) and gets `update:dirty` for the unsaved guard.
import { computed, nextTick, ref, toRef, watch } from 'vue';
import Button from '../../../../ui/primitives/Button.vue';
import Icon from '../../../../ui/primitives/Icon.vue';
import Tooltip from '../../../../ui/overlay/Tooltip.vue';
import Skeleton from '../../../../ui/data/Skeleton.vue';
import Tabs, { type TabItem } from '../../../../ui/navigation/Tabs.vue';
import SegmentedControl, { type SegmentOption } from '../../../../ui/forms/SegmentedControl.vue';
import EditorActionBar from '../editor/EditorActionBar.vue';
import EditorAdjustPanel from '../editor/EditorAdjustPanel.vue';
import DraftRestoreBanner from '../DraftRestoreBanner.vue';
import { useI18n } from '../../../../app/i18n';
import { useToast } from '../../../../app/composables/useToast';
import { IMAGE_FILTERS } from '../../imageOps';
import { useImageEditor, type ImagePayload } from '../useImageEditor';
import { useDraftAutosave } from '../useDraftAutosave';
import type { DiskFile } from '../../types';

const props = defineProps<{
  file: DiskFile;
  canOverwrite: boolean;
  saving: boolean;
}>();

const emit = defineEmits<{
  (e: 'update:dirty', dirty: boolean): void;
  (e: 'save', payload: { blob: Blob; filename: string }): void;
  (e: 'save-as', payload: { blob: Blob; defaultName: string }): void;
}>();

const { t } = useI18n();
const toast = useToast();

const editor = useImageEditor(toRef(props, 'file'));
const {
  canvasRef,
  overlayRef,
  loading,
  loadFailed,
  dirty,
  filter,
  cropMode,
  cropUsable,
  selectionStyle,
  maskMode,
  maskOverlayRef,
  canUndo,
  canRedo,
} = editor;

// Server-side autosave draft: the full edit history is debounced-serialized so a refresh/crash never
// loses work; on reopen a pending draft drives the restore banner (below). `revision` is the change
// pulse (its debounce collapses slider bursts). See useDraftAutosave.
const draftAutosave = useDraftAutosave({
  fileId: () => props.file.id,
  fileVersion: () => props.file.updated_at_iso,
  kind: 'image',
  isDirty: dirty,
  changeSignal: editor.revision,
  serialize: editor.serializeDraft,
  hydrate: editor.hydrateDraft,
  // The list/info `has_draft` gates the restore probe: GET /{file}/draft fires ONLY when a draft
  // is known to exist.
  hasDraft: () => props.file.has_draft,
});
const { pendingDraft, stale: draftStale } = draftAutosave;

async function onRestoreDraft(): Promise<void> {
  try {
    await draftAutosave.restore(); // hydrates the engine from the draft
    toast.success(t('disk.preview.draft.restored', 'Draft restored.'));
  } catch {
    // A base blob was missing/undecodable — fall back to the saved file.
    toast.danger(t('disk.preview.draft.restoreError', 'Could not restore the draft. Showing the saved file instead.'));
    void editor.load();
  }
}
async function onDiscardDraft(): Promise<void> {
  await draftAutosave.discard();
  toast.info(t('disk.preview.draft.discarded', 'Draft discarded.'));
}

// View zoom (NOT part of the edit history). CSS `zoom` scales the wrapper AND its layout box, so
// the stage's overflow-auto becomes native pan when zoomed in.
const ZOOM_MIN = 0.5;
const ZOOM_MAX = 4;
const ZOOM_STEP = 0.25;
const zoom = ref(1);
const canZoomIn = computed(() => zoom.value < ZOOM_MAX);
const canZoomOut = computed(() => zoom.value > ZOOM_MIN);
function zoomIn(): void {
  zoom.value = Math.min(ZOOM_MAX, Number((zoom.value + ZOOM_STEP).toFixed(2)));
}
function zoomOut(): void {
  zoom.value = Math.max(ZOOM_MIN, Number((zoom.value - ZOOM_STEP).toFixed(2)));
}
function zoomFit(): void {
  zoom.value = 1;
}

// Crop aspect ratio (single choice; drives the engine). free = unconstrained.
const RATIO_MAP: Record<string, number | null> = { free: null, r1: 1, r43: 4 / 3, r169: 16 / 9, r916: 9 / 16 };
const cropRatio = ref<string>('free');
const cropRatioOptions = computed<SegmentOption<string>[]>(() => [
  { value: 'free', label: t('disk.editor.cropRatio.free', 'Free') },
  { value: 'r1', label: '1:1' },
  { value: 'r43', label: '4:3' },
  { value: 'r169', label: '16:9' },
  { value: 'r916', label: '9:16' },
]);
function onCropRatio(v: string | null | string[]): void {
  const key = typeof v === 'string' ? v : 'free';
  cropRatio.value = key;
  editor.setCropAspect(RATIO_MAP[key] ?? null);
}

// Downscale-only resize presets (longest edge, px). A preset is disabled once the image is already
// at/under it — resize never upscales.
const RESIZE_PRESETS = [2048, 1024, 512];

watch(dirty, (d) => emit('update:dirty', d), { immediate: true });
watch(
  () => props.file.id,
  () => {
    zoom.value = 1;
    void editor.load();
  },
  { immediate: true },
);

function copyName(name: string, ext: string): string {
  const dot = name.lastIndexOf('.');
  const stem = dot > 0 ? name.slice(0, dot) : name;
  return `${stem}-edited.${ext}`;
}

async function payload(): Promise<ImagePayload | null> {
  return editor.toBlob();
}

async function onSave(): Promise<void> {
  const p = await payload();
  if (!p) return;
  emit('save', { blob: p.blob, filename: props.file.name });
}

async function onSaveAs(): Promise<void> {
  const p = await payload();
  if (!p) return;
  emit('save-as', { blob: p.blob, defaultName: copyName(props.file.name, p.ext) });
}

/** The shell calls these: reset dirty after an overwrite, or re-load after external changes. */
function markSaved(): void {
  editor.markSaved();
  void draftAutosave.clear(); // the file now equals the edit → the draft is obsolete
}
const reload = (): Promise<void> => editor.load();

const canEditCanvas = computed(() => !loading.value && !loadFailed.value);

// --- Mode selector (progressive disclosure) ----------------------------------
type EditorMode = 'transform' | 'adjust' | 'filters' | 'ai';
const mode = ref<EditorMode>('transform');
const modes = computed<TabItem<EditorMode>[]>(() => [
  { value: 'transform', label: t('disk.editor.modes.transform', 'Transform'), icon: 'table' },
  { value: 'adjust', label: t('disk.editor.modes.adjust', 'Adjust'), icon: 'settings' },
  { value: 'filters', label: t('disk.editor.modes.filters', 'Filters'), icon: 'palette' },
  { value: 'ai', label: t('disk.editor.modes.ai', 'AI'), icon: 'sparkles' },
]);
// Leaving the Transform tab mid-crop would strand the crop overlay — cancel it. Leaving the AI tab
// turns mask mode off (and clears strokes) so a painted mask never lingers into another mode.
watch(mode, (m) => {
  if (m !== 'transform' && cropMode.value) editor.cancelCrop();
  if (m !== 'ai') editor.setMaskMode(false);
});
// When the mask overlay mounts (mask mode on), size its backing buffer to the canvas once the DOM
// has flushed so the first stroke maps and renders correctly.
watch(maskMode, (on) => {
  if (on) void nextTick(() => editor.syncMaskOverlay());
});

defineExpose({ markSaved, reload, editor });
</script>

<template>
  <div class="flex flex-col gap-next-3">
    <!-- Restore banner: an unsaved autosave draft was found on reopen. -->
    <DraftRestoreBanner
      v-if="pendingDraft"
      :updated-at="pendingDraft.updated_at"
      :stale="draftStale"
      @restore="onRestoreDraft"
      @discard="onDiscardDraft"
    />

    <!-- 1. Global action bar. -->
    <EditorActionBar
      :can-undo="canUndo"
      :can-redo="canRedo"
      :can-reset="canUndo"
      :can-overwrite="canOverwrite"
      :dirty="dirty"
      :saving="saving"
      :disabled="!canEditCanvas"
      :zoom="zoom"
      :can-zoom-in="canZoomIn"
      :can-zoom-out="canZoomOut"
      @undo="editor.undo"
      @redo="editor.redo"
      @reset="editor.reset"
      @save="onSave"
      @save-as="onSaveAs"
      @zoom-in="zoomIn"
      @zoom-out="zoomOut"
      @zoom-fit="zoomFit"
    />

    <!-- 2. Mode selector + the active mode's controls. -->
    <Tabs v-model="mode" :items="modes" variant="pills" :aria-label="t('disk.editor.modesLabel', 'Editing tools')">
      <template #panel-transform>
        <div class="flex flex-col gap-next-2">
          <div class="flex flex-wrap items-center gap-next-2">
          <Tooltip :label="t('disk.editor.crop', 'Crop')">
            <Button
              variant="outline"
              size="icon-sm"
              leading-icon="crop"
              :disabled="!canEditCanvas"
              :aria-pressed="cropMode ? 'true' : 'false'"
              :class="cropMode ? 'ring-2 ring-next-ring' : ''"
              :aria-label="t('disk.editor.crop', 'Crop')"
              @click="editor.toggleCrop"
            />
          </Tooltip>
          <Tooltip :label="t('disk.editor.rotateLeft', 'Rotate left')">
            <Button variant="outline" size="icon-sm" leading-icon="rotate-ccw" :disabled="!canEditCanvas" :aria-label="t('disk.editor.rotateLeft', 'Rotate left')" @click="editor.rotateCcw" />
          </Tooltip>
          <Tooltip :label="t('disk.editor.rotateRight', 'Rotate right')">
            <Button variant="outline" size="icon-sm" :disabled="!canEditCanvas" :aria-label="t('disk.editor.rotateRight', 'Rotate right')" @click="editor.rotate">
              <Icon name="rotate-ccw" :style="{ transform: 'scaleX(-1)' }" />
            </Button>
          </Tooltip>
          <Tooltip :label="t('disk.editor.flipH', 'Flip horizontally')">
            <Button variant="outline" size="icon-sm" leading-icon="arrow-right" :disabled="!canEditCanvas" :aria-label="t('disk.editor.flipH', 'Flip horizontally')" @click="editor.toggleFlipH" />
          </Tooltip>
          <Tooltip :label="t('disk.editor.flipV', 'Flip vertically')">
            <Button variant="outline" size="icon-sm" leading-icon="arrow-down" :disabled="!canEditCanvas" :aria-label="t('disk.editor.flipV', 'Flip vertically')" @click="editor.toggleFlipV" />
          </Tooltip>
          </div>
          <SegmentedControl
            v-if="cropMode"
            :model-value="cropRatio"
            :options="cropRatioOptions"
            size="sm"
            :aria-label="t('disk.editor.cropRatioLabel', 'Crop ratio')"
            @update:model-value="onCropRatio"
          />
          <div class="flex flex-wrap items-center gap-next-2">
            <span class="text-next-sm text-next-muted-foreground">{{ t('disk.editor.resize', 'Resize') }}</span>
            <Button
              v-for="s in RESIZE_PRESETS"
              :key="s"
              size="sm"
              variant="outline"
              :disabled="!canEditCanvas || editor.baseLongest.value <= s"
              @click="editor.resize(s)"
            >
              {{ s }} px
            </Button>
          </div>
        </div>
      </template>

      <template #panel-adjust>
        <EditorAdjustPanel :editor="editor" :disabled="!canEditCanvas" />
      </template>

      <template #panel-filters>
        <div class="flex flex-wrap items-center gap-next-2">
          <Button
            v-for="f in IMAGE_FILTERS"
            :key="f"
            size="sm"
            :variant="filter === f ? 'secondary' : 'ghost'"
            :disabled="!canEditCanvas"
            :aria-pressed="filter === f ? 'true' : 'false'"
            @click="editor.setFilter(f)"
          >
            {{ t(`disk.editor.filter.${f}`, f) }}
          </Button>
        </div>
      </template>

      <template #panel-ai>
        <div class="flex flex-col gap-next-2">
          <p class="flex items-center gap-next-1_5 text-next-xs text-next-muted-foreground">
            <Icon name="sparkles" aria-hidden="true" />
            {{ t('disk.editor.aiHint', 'AI edits take about 30s and are provider-billed.') }}
          </p>
          <!-- The AI actions panel is provided by the shell (DiskPreview → ImageAiPanel). -->
          <slot name="ai" :editor="editor" :disabled="!canEditCanvas" />
        </div>
      </template>
    </Tabs>

    <!-- Crop action bar (only while cropping). -->
    <div v-if="cropMode" class="flex items-center gap-next-2 rounded-next-md border border-next-border bg-next-muted/40 px-next-3 py-next-2">
      <span class="flex-1 text-next-sm text-next-muted-foreground">{{ t('disk.editor.cropHint', 'Drag on the image to select an area.') }}</span>
      <Button variant="ghost" size="sm" @click="editor.cancelCrop">{{ t('common.cancel', 'Cancel') }}</Button>
      <Button variant="primary" size="sm" :disabled="!cropUsable" @click="editor.applyCrop">
        {{ t('disk.editor.applyCrop', 'Apply crop') }}
      </Button>
    </div>

    <!-- 3. Canvas stage. -->
    <div class="flex min-h-[50vh] items-center justify-center overflow-auto rounded-next-lg border border-next-border bg-next-muted/40 p-next-3">
      <Skeleton v-if="loading" class="h-64 w-full rounded-next-md" />
      <p v-else-if="loadFailed" class="flex items-center gap-next-2 text-next-sm text-next-danger" role="alert">
        <Icon name="alert-circle" aria-hidden="true" />
        {{ t('disk.editor.loadError', 'Could not load the image.') }}
      </p>
      <!-- Wrapper shrink-wraps the displayed canvas so the crop overlay aligns exactly; CSS `zoom`
           scales it (+ its layout box) so the stage's overflow-auto pans when zoomed in. -->
      <div v-show="!loading && !loadFailed" class="relative inline-block" :style="{ zoom }">
        <canvas
          ref="canvasRef"
          class="block max-h-[65vh] max-w-full rounded-next-md"
          :aria-label="t('disk.editor.canvas', 'Image preview')"
        />
        <div
          v-if="cropMode"
          ref="overlayRef"
          class="absolute inset-0 cursor-crosshair touch-none select-none"
          @pointerdown="editor.onPointerDown"
          @pointermove="editor.onPointerMove"
          @pointerup="editor.onPointerUp"
          @pointerleave="editor.onPointerUp"
        >
          <!-- The selection: a bright box whose huge box-shadow dims everything outside it. -->
          <div
            class="pointer-events-none absolute border-2 border-next-ring"
            :style="{ ...selectionStyle, boxShadow: '0 0 0 9999px rgba(0,0,0,0.5)' }"
          />
        </div>
        <!-- Mask paint surface: a canvas overlay that captures the brush AND shows the painted
             (translucent) region. Its backing buffer matches the base canvas pixel size so strokes
             map 1:1; CSS scales it to the displayed box like the main canvas. -->
        <canvas
          v-if="maskMode"
          ref="maskOverlayRef"
          role="img"
          class="absolute inset-0 h-full w-full cursor-crosshair touch-none select-none"
          :aria-label="t('disk.editor.maskOverlay', 'Paint the area to edit')"
          @pointerdown="editor.onMaskPointerDown"
          @pointermove="editor.onMaskPointerMove"
          @pointerup="editor.onMaskPointerUp"
          @pointerleave="editor.onMaskPointerUp"
        />
      </div>
    </div>
  </div>
</template>
