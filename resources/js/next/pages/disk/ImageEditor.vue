<script setup lang="ts">
// ImageEditor (P8) — crop / rotate / flip / classic filters, saved as a COPY.
//
// The image being edited lives in a `base` offscreen canvas. Rotate/flip/filter are
// NON-destructive: every redraw re-renders `base` under the current transform onto the
// visible canvas, then runs the chosen classic filter over the raw pixels (NOT ctx.filter —
// Safari's support is unreliable). CROP is destructive by design: applying it bakes the
// current transform+filter into a new, smaller `base` and resets the transform — the natural
// "commit" an editor performs. The base is capped at MAX_EDGE on its longest side. Save never
// touches the original: canvas.toBlob → upload a NEW disk file. The AI slot is present but
// disabled (R2).
import { computed, ref, watch } from 'vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Tooltip from '../../ui/overlay/Tooltip.vue';
import Skeleton from '../../ui/data/Skeleton.vue';
import { useDiskStore } from '../../app/stores/disk';
import { useToast } from '../../app/composables/useToast';
import { api } from '../../app/lib/api';
import { useI18n } from '../../app/i18n';
import {
  applyFilter,
  cropToPixels,
  fitScale,
  normalizeCrop,
  rotatedSize,
  IMAGE_FILTERS,
  type ImageFilter,
} from './imageOps';
import type { DiskFile } from './types';

/** Longest-edge cap for the working image — keeps a huge upload from ballooning. */
const MAX_EDGE = 4096;
/** Minimum crop selection, in fractions of the canvas, below which "Apply" is a no-op. */
const MIN_CROP_FRACTION = 0.03;

const props = defineProps<{ file: DiskFile | null }>();
const open = defineModel<boolean>('open', { default: false });

const { t } = useI18n();
const toast = useToast();
const store = useDiskStore();

const canvasRef = ref<HTMLCanvasElement | null>(null);
const loading = ref(false);
const loadFailed = ref(false);
const saving = ref(false);

// The committed image (already cropped/capped); rotate/flip/filter re-render from it.
let base: HTMLCanvasElement | null = null;

// Edit state.
const quarterTurns = ref(0); // 0..3 (× 90°)
const flipH = ref(false);
const flipV = ref(false);
const filter = ref<ImageFilter>('none');

function resetTransforms(): void {
  quarterTurns.value = 0;
  flipH.value = false;
  flipV.value = false;
  filter.value = 'none';
}

/** Re-render `base` under the current transform + filter onto the visible canvas. */
function redraw(): void {
  const src = base;
  const canvas = canvasRef.value;
  const ctx = canvas?.getContext('2d');
  if (!src || !canvas || !ctx) return;

  const baseW = src.width;
  const baseH = src.height;
  const { width, height } = rotatedSize(baseW, baseH, quarterTurns.value);
  canvas.width = width;
  canvas.height = height;

  ctx.clearRect(0, 0, width, height);
  ctx.save();
  ctx.translate(width / 2, height / 2);
  ctx.rotate((quarterTurns.value * Math.PI) / 2);
  ctx.scale(flipH.value ? -1 : 1, flipV.value ? -1 : 1);
  ctx.drawImage(src, -baseW / 2, -baseH / 2, baseW, baseH);
  ctx.restore();

  if (filter.value !== 'none') {
    const data = ctx.getImageData(0, 0, width, height);
    applyFilter(data.data, filter.value);
    ctx.putImageData(data, 0, 0);
  }
}

/** Build the initial `base` canvas from a decoded image, downscaled to the cap. */
function baseFromImage(img: HTMLImageElement): HTMLCanvasElement {
  const scale = fitScale(img.naturalWidth, img.naturalHeight, MAX_EDGE);
  const c = document.createElement('canvas');
  c.width = Math.max(1, Math.round(img.naturalWidth * scale));
  c.height = Math.max(1, Math.round(img.naturalHeight * scale));
  c.getContext('2d')?.drawImage(img, 0, 0, c.width, c.height);
  return c;
}

async function load(file: DiskFile): Promise<void> {
  resetTransforms();
  cancelCrop();
  base = null;
  loadFailed.value = false;
  loading.value = true;
  let url: string | null = null;
  try {
    const inline = file.path + (file.path.includes('?') ? '&' : '?') + 'inline=1';
    const blob = await api.get<Blob>(inline, { responseType: 'blob' });
    url = URL.createObjectURL(blob);
    const img = new Image();
    await new Promise<void>((resolve, reject) => {
      img.onload = () => resolve();
      img.onerror = () => reject(new Error('decode failed'));
      img.src = url as string;
    });
    base = baseFromImage(img);
    await Promise.resolve(); // let the canvas mount
    redraw();
  } catch {
    loadFailed.value = true;
  } finally {
    loading.value = false;
    if (url) setTimeout((u: string) => URL.revokeObjectURL(u), 5_000, url);
  }
}

// --- Transform toolbar -------------------------------------------------------
function rotate(): void {
  quarterTurns.value = (quarterTurns.value + 1) % 4;
}
function toggleFlipH(): void {
  flipH.value = !flipH.value;
}
function toggleFlipV(): void {
  flipV.value = !flipV.value;
}
function setFilter(f: ImageFilter): void {
  filter.value = f;
}

// --- Crop (draw-a-marquee, then Apply) ---------------------------------------
const cropMode = ref(false);
const overlayRef = ref<HTMLElement | null>(null);
// Normalized selection in 0..1 canvas fractions while drawing (x0,y0 = start; x1,y1 = current).
const cropRaw = ref<{ x0: number; y0: number; x1: number; y1: number } | null>(null);
const dragging = ref(false);

/** The selection with corners ordered (min→max), or null when there is none. */
const cropRect = computed(() => (cropRaw.value ? normalizeCrop(cropRaw.value) : null));
const cropUsable = computed(() => {
  const r = cropRect.value;
  return !!r && r.w >= MIN_CROP_FRACTION && r.h >= MIN_CROP_FRACTION;
});

function toggleCrop(): void {
  cropMode.value = !cropMode.value;
  if (!cropMode.value) cancelCrop();
}
function cancelCrop(): void {
  cropRaw.value = null;
  dragging.value = false;
  cropMode.value = false;
}

/** Pointer position as 0..1 fractions of the overlay (clamped). */
function fractionAt(e: PointerEvent): { x: number; y: number } {
  const el = overlayRef.value;
  if (!el) return { x: 0, y: 0 };
  const rect = el.getBoundingClientRect();
  const x = (e.clientX - rect.left) / rect.width;
  const y = (e.clientY - rect.top) / rect.height;
  return { x: Math.min(1, Math.max(0, x)), y: Math.min(1, Math.max(0, y)) };
}

function onPointerDown(e: PointerEvent): void {
  if (!cropMode.value) return;
  (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
  const p = fractionAt(e);
  cropRaw.value = { x0: p.x, y0: p.y, x1: p.x, y1: p.y };
  dragging.value = true;
}
function onPointerMove(e: PointerEvent): void {
  if (!dragging.value || !cropRaw.value) return;
  const p = fractionAt(e);
  cropRaw.value = { ...cropRaw.value, x1: p.x, y1: p.y };
}
function onPointerUp(): void {
  dragging.value = false;
}

/** Bake the current transform+filter, crop to the selection, and continue on the result. */
function applyCrop(): void {
  const canvas = canvasRef.value;
  const r = cropRect.value;
  if (!canvas || !r || !cropUsable.value) return;

  const { sx, sy, sw, sh } = cropToPixels(r, canvas.width, canvas.height);

  const cropped = document.createElement('canvas');
  cropped.width = sw;
  cropped.height = sh;
  cropped.getContext('2d')?.drawImage(canvas, sx, sy, sw, sh, 0, 0, sw, sh);

  base = cropped; // the crop commits the current transform+filter into the new base
  resetTransforms();
  cancelCrop();
  redraw();
}

// The dim-outside + selection box style, in overlay %.
const selectionStyle = computed(() => {
  const r = cropRect.value;
  if (!r) return { display: 'none' };
  return {
    left: `${r.x * 100}%`,
    top: `${r.y * 100}%`,
    width: `${r.w * 100}%`,
    height: `${r.h * 100}%`,
  };
});

// --- Save (as a copy) --------------------------------------------------------
function copyName(name: string, ext: string): string {
  const dot = name.lastIndexOf('.');
  const stem = dot > 0 ? name.slice(0, dot) : name;
  return `${stem}-edited.${ext}`;
}

async function save(): Promise<void> {
  const canvas = canvasRef.value;
  const file = props.file;
  if (!canvas || !file || saving.value) return;
  saving.value = true;
  try {
    const mime = /^image\/(jpeg|png|webp)$/.test(file.mime_type ?? '') ? (file.mime_type as string) : 'image/png';
    const ext = mime.split('/')[1] === 'jpeg' ? 'jpg' : mime.split('/')[1];
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, mime, 0.92));
    if (!blob) throw new Error('encode failed');

    const copy = new File([blob], copyName(file.name, ext), { type: mime });
    await store.uploadFile(copy, file.folder_id);
    toast.success(t('disk.editor.saved', 'Saved as a copy.'));
    open.value = false;
  } catch {
    toast.danger(t('disk.editor.saveError', 'Could not save the edited image.'));
  } finally {
    saving.value = false;
  }
}

// Load on open (or when the file changes); redraw on every edit.
watch(
  [open, () => props.file?.id],
  ([isOpen]) => {
    if (isOpen && props.file) void load(props.file);
  },
  { immediate: true },
);
watch([quarterTurns, flipH, flipV, filter], redraw);
</script>

<template>
  <Modal v-model:open="open" size="xl" :aria-label="t('disk.editor.title', 'Edit image')">
    <template #title>{{ t('disk.editor.title', 'Edit image') }}</template>

    <div class="flex flex-col gap-next-4">
      <!-- Transform toolbar. -->
      <div class="flex flex-wrap items-center gap-next-2">
        <Tooltip :label="t('disk.editor.crop', 'Crop')">
          <Button
            variant="outline"
            size="icon-sm"
            leading-icon="table"
            :aria-pressed="cropMode ? 'true' : 'false'"
            :class="cropMode ? 'ring-2 ring-next-ring' : ''"
            :aria-label="t('disk.editor.crop', 'Crop')"
            @click="toggleCrop"
          />
        </Tooltip>
        <Tooltip :label="t('disk.editor.rotate', 'Rotate 90°')">
          <Button variant="outline" size="icon-sm" leading-icon="rotate-ccw" :aria-label="t('disk.editor.rotate', 'Rotate 90°')" @click="rotate" />
        </Tooltip>
        <Tooltip :label="t('disk.editor.flipH', 'Flip horizontally')">
          <Button variant="outline" size="icon-sm" leading-icon="arrow-right" :aria-label="t('disk.editor.flipH', 'Flip horizontally')" @click="toggleFlipH" />
        </Tooltip>
        <Tooltip :label="t('disk.editor.flipV', 'Flip vertically')">
          <Button variant="outline" size="icon-sm" leading-icon="arrow-down" :aria-label="t('disk.editor.flipV', 'Flip vertically')" @click="toggleFlipV" />
        </Tooltip>

        <span class="mx-next-1 h-5 w-px bg-next-border" aria-hidden="true" />

        <!-- Classic filters. -->
        <Button
          v-for="f in IMAGE_FILTERS"
          :key="f"
          size="sm"
          :variant="filter === f ? 'secondary' : 'ghost'"
          :aria-pressed="filter === f ? 'true' : 'false'"
          @click="setFilter(f)"
        >
          {{ t(`disk.editor.filter.${f}`, f) }}
        </Button>

        <span class="mx-next-1 h-5 w-px bg-next-border" aria-hidden="true" />

        <!-- AI filters land in R2. -->
        <Tooltip :label="t('disk.editor.aiSoon', 'AI filters are coming soon.')">
          <Button size="sm" variant="ghost" leading-icon="sparkles" disabled>
            {{ t('disk.editor.ai', 'AI filter') }}
          </Button>
        </Tooltip>
      </div>

      <!-- Crop action bar (only while cropping). -->
      <div v-if="cropMode" class="flex items-center gap-next-2 rounded-next-md border border-next-border bg-next-muted/40 px-next-3 py-next-2">
        <span class="flex-1 text-next-sm text-next-muted-foreground">{{ t('disk.editor.cropHint', 'Drag on the image to select an area.') }}</span>
        <Button variant="ghost" size="sm" @click="cancelCrop">{{ t('common.cancel', 'Cancel') }}</Button>
        <Button variant="primary" size="sm" :disabled="!cropUsable" @click="applyCrop">
          {{ t('disk.editor.applyCrop', 'Apply crop') }}
        </Button>
      </div>

      <!-- Canvas stage. -->
      <div class="flex min-h-64 items-center justify-center overflow-auto rounded-next-lg border border-next-border bg-next-muted/40 p-next-3">
        <Skeleton v-if="loading" class="h-64 w-full rounded-next-md" />
        <p v-else-if="loadFailed" class="flex items-center gap-next-2 text-next-sm text-next-danger" role="alert">
          <Icon name="alert-circle" aria-hidden="true" />
          {{ t('disk.editor.loadError', 'Could not load the image.') }}
        </p>
        <!-- Wrapper shrink-wraps the displayed canvas so the crop overlay aligns exactly. -->
        <div v-show="!loading && !loadFailed" class="relative inline-block">
          <canvas
            ref="canvasRef"
            class="block max-h-[60vh] max-w-full rounded-next-md"
            :aria-label="t('disk.editor.canvas', 'Image preview')"
          />
          <div
            v-if="cropMode"
            ref="overlayRef"
            class="absolute inset-0 cursor-crosshair touch-none select-none"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointerleave="onPointerUp"
          >
            <!-- The selection: a bright box whose huge box-shadow dims everything outside it. -->
            <div
              class="pointer-events-none absolute border-2 border-next-ring"
              :style="{ ...selectionStyle, boxShadow: '0 0 0 9999px rgba(0,0,0,0.5)' }"
            />
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <Button variant="outline" type="button" @click="open = false">
        {{ t('common.cancel', 'Cancel') }}
      </Button>
      <Button variant="primary" type="button" :disabled="loading || loadFailed" :loading="saving" @click="save">
        {{ t('disk.editor.saveCopy', 'Save as copy') }}
      </Button>
    </template>
  </Modal>
</template>
