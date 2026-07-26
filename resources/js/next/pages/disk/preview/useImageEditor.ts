// useImageEditor — the image-editing engine driving the preview's ImageStage. The committed image
// lives in a closure-local `base` canvas; rotate/flip/filter/adjustments are NON-destructive
// re-renders of `base` (pixel filters via imageOps, never ctx.filter — Safari), while CROP and AI
// EDITS are destructive commits that replace `base`.
//
// History (F1-2): every edit is a bounded stack of `{ baseId, state }` snapshots. Cheap edits share
// the SAME base and only record the tiny non-destructive `state`; destructive commits (crop/AI)
// register a new base. So the stack caps STATE entries, not 50 MB pixel buffers — `undo`/`redo`/
// `reset` walk it, `dirty` is derived from the distance to the last saved step. Live slider
// adjustments preview without pushing (the component commits one step on release via `commitAdjust`).
//
// The shell contract is fixed: `dirty`, `applyAi`, `aiBusy`, `load`, `toBlob`, `markSaved` — the
// ImageStage emits/expose and the DiskPreview save/guard depend on them.
import { computed, ref, watch, type Ref } from 'vue';
import { api, WORKSPACE_KEY } from '../../../app/lib/api';
import { subscribePrivate, whenConnectionFails } from '../../../app/lib/echo';
import {
  applyAdjustments,
  applyFilter,
  constrainRatio,
  cropToPixels,
  fitScale,
  maskPointToCanvas,
  normalizeCrop,
  rotatedSize,
  type ImageFilter,
} from '../imageOps';
import type { DiskFile, ImageDraftManifest } from '../types';

/** Longest-edge cap for the working image. 3584 keeps the buffer under iOS Safari's ~16.78 Mpx
 *  canvas ceiling (4096² sits exactly on it, where getImageData/toBlob start failing). */
export const MAX_EDGE = 3584;
/** Minimum crop selection, in fractions of the canvas, below which "Apply" is a no-op. */
export const MIN_CROP_FRACTION = 0.03;
/** Bounded undo depth. Cheap edits share a base, so this caps STATE snapshots, not pixel buffers. */
export const HISTORY_CAP = 40;

export interface ImagePayload {
  blob: Blob;
  mime: string;
  ext: string;
}

/** The non-destructive edit state re-rendered over a base — the unit of an undo step. */
interface EditState {
  quarterTurns: number;
  flipH: boolean;
  flipV: boolean;
  filter: ImageFilter;
  brightness: number;
  contrast: number;
  saturation: number;
}
interface HistoryEntry {
  baseId: number;
  state: EditState;
}

export function useImageEditor(file: Ref<DiskFile | null>) {
  const canvasRef = ref<HTMLCanvasElement | null>(null);
  const overlayRef = ref<HTMLElement | null>(null);
  const maskOverlayRef = ref<HTMLCanvasElement | null>(null);
  const loading = ref(false);
  const loadFailed = ref(false);
  const aiBusy = ref(false);

  // The committed image (already cropped/capped); transform/filter/adjust re-render from it.
  let base: HTMLCanvasElement | null = null;

  // Non-destructive edit state.
  const quarterTurns = ref(0); // 0..3 (× 90°)
  const flipH = ref(false);
  const flipV = ref(false);
  const filter = ref<ImageFilter>('none');
  const brightness = ref(0); // -100..100
  const contrast = ref(0); // -100..100
  const saturation = ref(0); // -100..100

  // --- History (undo / redo / reset) -------------------------------------------
  // Distinct committed bases (crop / AI each register one); most entries share a base.
  const bases = new Map<number, HTMLCanvasElement>();
  let nextBaseId = 0;
  let history: HistoryEntry[] = [];
  const historyIndex = ref(0);
  const historyLen = ref(0);
  const savedIndex = ref(0);
  // A cheap monotonic "something re-rendered" pulse: bumped on every redraw(), so autosave can watch
  // ONE ref and let its debounce collapse a burst of slider ticks / transforms into a single save.
  const revision = ref(0);

  // Reactive pixel size of the current committed base — powers the resize presets (disable a
  // preset once the image is already at/under it, since resize never upscales).
  const baseSize = ref<{ w: number; h: number }>({ w: 0, h: 0 });
  const baseLongest = computed(() => Math.max(baseSize.value.w, baseSize.value.h));
  function syncBaseSize(): void {
    baseSize.value = base ? { w: base.width, h: base.height } : { w: 0, h: 0 };
  }

  const canUndo = computed(() => historyIndex.value > 0);
  const canRedo = computed(() => historyIndex.value < historyLen.value - 1);
  const dirty = computed(() => {
    if (historyIndex.value !== savedIndex.value) return true;
    // An uncommitted LIVE edit (a slider mid-drag, before commitAdjust) diverges from the committed
    // step — it must read dirty so the unsaved-changes guard fires and Save stays enabled.
    const cur = history[historyIndex.value]?.state;
    return !!cur && !sameState(cur, snapshot());
  });

  function snapshot(): EditState {
    return {
      quarterTurns: quarterTurns.value,
      flipH: flipH.value,
      flipV: flipV.value,
      filter: filter.value,
      brightness: brightness.value,
      contrast: contrast.value,
      saturation: saturation.value,
    };
  }
  function restore(s: EditState): void {
    quarterTurns.value = s.quarterTurns;
    flipH.value = s.flipH;
    flipV.value = s.flipV;
    filter.value = s.filter;
    brightness.value = s.brightness;
    contrast.value = s.contrast;
    saturation.value = s.saturation;
  }
  function sameState(a: EditState, b: EditState): boolean {
    return (
      a.quarterTurns === b.quarterTurns &&
      a.flipH === b.flipH &&
      a.flipV === b.flipV &&
      a.filter === b.filter &&
      a.brightness === b.brightness &&
      a.contrast === b.contrast &&
      a.saturation === b.saturation
    );
  }
  function resetEditState(): void {
    quarterTurns.value = 0;
    flipH.value = false;
    flipV.value = false;
    filter.value = 'none';
    brightness.value = 0;
    contrast.value = 0;
    saturation.value = 0;
  }
  function currentBaseId(): number {
    return history[historyIndex.value]?.baseId ?? 0;
  }
  /** Drop any base no longer referenced by a surviving history entry. */
  function gcBases(): void {
    const used = new Set(history.map((e) => e.baseId));
    for (const id of [...bases.keys()]) {
      if (!used.has(id)) bases.delete(id);
    }
  }
  /** Seed the stack with a single baseline entry (the loaded original, or an empty editor). */
  function seedHistory(): void {
    bases.clear();
    nextBaseId = 0;
    if (base) bases.set(0, base);
    resetEditState();
    history = [{ baseId: 0, state: snapshot() }];
    historyIndex.value = 0;
    historyLen.value = 1;
    savedIndex.value = 0;
    syncBaseSize();
  }
  function pushEntry(state: EditState, baseId: number): void {
    // Truncate any redo tail we are branching away from.
    if (historyIndex.value < history.length - 1) {
      history = history.slice(0, historyIndex.value + 1);
    }
    history.push({ baseId, state });
    historyIndex.value = history.length - 1;
    if (history.length > HISTORY_CAP) {
      history.shift();
      historyIndex.value -= 1;
      // The saved baseline may have been evicted — a -1 sentinel keeps `dirty` true (no surviving
      // step equals the file on disk) instead of re-pinning it to an edited step.
      savedIndex.value = savedIndex.value > 0 ? savedIndex.value - 1 : -1;
    }
    // GC after every push so bases orphaned by a truncated redo tail (not only cap overflow) are
    // dropped, rather than lingering until the next reload.
    gcBases();
    historyLen.value = history.length;
  }
  function applyEntry(entry: HistoryEntry): void {
    const b = bases.get(entry.baseId);
    if (b) base = b;
    syncBaseSize();
    restore(entry.state);
    redraw();
  }
  /** Record a discrete cheap edit (rotate / flip / filter) and re-render. */
  function commitDiscrete(): void {
    pushEntry(snapshot(), currentBaseId());
    redraw();
  }
  /** Register a fresh committed base (crop / AI): reset the transform and record one step. */
  function commitBase(newBase: HTMLCanvasElement): void {
    nextBaseId += 1;
    const id = nextBaseId;
    bases.set(id, newBase);
    base = newBase;
    syncBaseSize();
    resetEditState();
    pushEntry(snapshot(), id);
    redraw();
  }

  /** Re-render `base` under the current transform + adjustments + filter onto the visible canvas. */
  function redraw(): void {
    // Pulse BEFORE the canvas guard so autosave still sees a change even before the canvas mounts
    // (e.g. a hydrate on restore) — the pulse is a change signal, not proof pixels were painted.
    revision.value += 1;
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

    const hasAdjust = brightness.value !== 0 || contrast.value !== 0 || saturation.value !== 0;
    if (filter.value !== 'none' || hasAdjust) {
      const data = ctx.getImageData(0, 0, width, height);
      applyAdjustments(data.data, {
        brightness: brightness.value,
        contrast: contrast.value,
        saturation: saturation.value,
      });
      if (filter.value !== 'none') applyFilter(data.data, filter.value);
      ctx.putImageData(data, 0, 0);
    }

    // Any base/transform change invalidates a painted mask (it would misalign with the new pixels),
    // so every redraw clears + resizes the mask to match. This is the single robust hook that makes
    // "rotate → paint → undo" (or any transform) never send a stale mask.
    syncMaskAfterRedraw();
  }

  /** Build a `base` canvas from a decoded image, downscaled to the cap. */
  function baseFromImage(img: HTMLImageElement): HTMLCanvasElement {
    const scale = fitScale(img.naturalWidth, img.naturalHeight, MAX_EDGE);
    const c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(img.naturalWidth * scale));
    c.height = Math.max(1, Math.round(img.naturalHeight * scale));
    c.getContext('2d')?.drawImage(img, 0, 0, c.width, c.height);
    return c;
  }

  /** Decode a blob into an HTMLImageElement (object URL revoked after decode settles). */
  async function decode(blob: Blob): Promise<HTMLImageElement> {
    const url = URL.createObjectURL(blob);
    try {
      const img = new Image();
      await new Promise<void>((resolve, reject) => {
        img.onload = () => resolve();
        img.onerror = () => reject(new Error('decode failed'));
        img.src = url;
      });
      return img;
    } finally {
      setTimeout((u: string) => URL.revokeObjectURL(u), 5_000, url);
    }
  }

  async function load(): Promise<void> {
    const current = file.value;
    if (!current) return;
    cancelCrop();
    setMaskMode(false);
    base = null;
    loadFailed.value = false;
    loading.value = true;
    try {
      const inline = current.path + (current.path.includes('?') ? '&' : '?') + 'inline=1';
      const blob = await api.get<Blob>(inline, { responseType: 'blob' });
      base = baseFromImage(await decode(blob));
      seedHistory(); // baseline = the loaded original (clean)
      await Promise.resolve(); // let the canvas mount
      redraw();
    } catch {
      loadFailed.value = true;
      seedHistory();
    } finally {
      loading.value = false;
    }
  }

  // --- Transforms / filter / adjustments ---------------------------------------
  function rotate(): void {
    quarterTurns.value = (quarterTurns.value + 1) % 4;
    commitDiscrete();
  }
  function rotateCcw(): void {
    quarterTurns.value = (quarterTurns.value + 3) % 4;
    commitDiscrete();
  }
  function toggleFlipH(): void {
    flipH.value = !flipH.value;
    commitDiscrete();
  }
  function toggleFlipV(): void {
    flipV.value = !flipV.value;
    commitDiscrete();
  }
  function setFilter(f: ImageFilter): void {
    if (f === filter.value) return; // selecting the current filter (incl. "none") is a no-op edit
    filter.value = f;
    commitDiscrete();
  }
  // Live adjustment preview: update the ref + re-render WITHOUT recording history — the component
  // commits one undo step on slider release via `commitAdjust`.
  function setBrightness(v: number): void {
    if (v === brightness.value) return;
    brightness.value = v;
    redraw();
  }
  function setContrast(v: number): void {
    if (v === contrast.value) return;
    contrast.value = v;
    redraw();
  }
  function setSaturation(v: number): void {
    if (v === saturation.value) return;
    saturation.value = v;
    redraw();
  }
  /** Record the current adjustment values as one undo step (call on slider release). No-op if
   *  nothing changed since the step at the cursor. */
  function commitAdjust(): void {
    const cur = history[historyIndex.value]?.state;
    if (cur && sameState(cur, snapshot())) return;
    pushEntry(snapshot(), currentBaseId());
  }

  // --- Undo / redo / reset -----------------------------------------------------
  function undo(): void {
    if (!canUndo.value) return;
    historyIndex.value -= 1;
    applyEntry(history[historyIndex.value]);
  }
  function redo(): void {
    if (!canRedo.value) return;
    historyIndex.value += 1;
    applyEntry(history[historyIndex.value]);
  }
  /** Jump back to the loaded original (history[0]). */
  function reset(): void {
    if (historyIndex.value === 0) return;
    historyIndex.value = 0;
    applyEntry(history[0]);
  }
  /** The shell calls this after a successful overwrite so `dirty` rebaselines to the saved step. */
  function markSaved(): void {
    savedIndex.value = historyIndex.value;
  }

  // --- Crop (draw-a-marquee, then Apply) ---------------------------------------
  const cropMode = ref(false);
  const cropRaw = ref<{ x0: number; y0: number; x1: number; y1: number } | null>(null);
  const dragging = ref(false);
  /** Locked crop aspect ratio (w/h) or null for a free selection. */
  const cropAspect = ref<number | null>(null);

  /** Lock (or free) the crop selection to an aspect ratio; re-fits any current selection. */
  function setCropAspect(ratio: number | null): void {
    cropAspect.value = ratio;
    const canvas = canvasRef.value;
    if (ratio && canvas && cropRaw.value) {
      cropRaw.value = constrainRatio(cropRaw.value, ratio, canvas.width, canvas.height);
    }
  }

  const cropRect = computed(() => (cropRaw.value ? normalizeCrop(cropRaw.value) : null));
  const cropUsable = computed(() => {
    const r = cropRect.value;
    return !!r && r.w >= MIN_CROP_FRACTION && r.h >= MIN_CROP_FRACTION;
  });

  function toggleCrop(): void {
    cropMode.value = !cropMode.value;
    if (cropMode.value) setMaskMode(false); // crop + mask are mutually exclusive
    else cancelCrop();
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
    let raw = { ...cropRaw.value, x1: p.x, y1: p.y };
    const canvas = canvasRef.value;
    if (cropAspect.value && canvas) {
      raw = constrainRatio(raw, cropAspect.value, canvas.width, canvas.height);
    }
    cropRaw.value = raw;
  }
  function onPointerUp(): void {
    dragging.value = false;
  }

  /** Bake the current transform+adjust+filter, crop to the selection, and continue on the result. */
  function applyCrop(): void {
    const canvas = canvasRef.value;
    const r = cropRect.value;
    if (!canvas || !r || !cropUsable.value) return;

    const { sx, sy, sw, sh } = cropToPixels(r, canvas.width, canvas.height);

    const cropped = document.createElement('canvas');
    cropped.width = sw;
    cropped.height = sh;
    cropped.getContext('2d')?.drawImage(canvas, sx, sy, sw, sh, 0, 0, sw, sh);

    cancelCrop();
    commitBase(cropped); // the crop bakes the current transform+adjust+filter into the new base
  }

  /** Scale the current image DOWN to a target longest edge (never upscales) and commit it as a new
   *  base — bakes the current transform/adjust/filter like a crop. No-op if already within target. */
  function resize(longestEdge: number): void {
    const canvas = canvasRef.value;
    if (!canvas) return;
    const scale = fitScale(canvas.width, canvas.height, longestEdge);
    if (scale >= 1) return;
    const out = document.createElement('canvas');
    out.width = Math.max(1, Math.round(canvas.width * scale));
    out.height = Math.max(1, Math.round(canvas.height * scale));
    out.getContext('2d')?.drawImage(canvas, 0, 0, out.width, out.height);
    cancelCrop();
    commitBase(out);
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

  // --- Mask brush (paint the AI edit region) -----------------------------------
  // A brushed mask marks WHERE a masked AI edit (object removal / replace) should apply. Painted
  // strokes live on an offscreen canvas (solid, opaque) at the CURRENT canvas pixel size; the visible
  // overlay mirrors them translucently, and `exportMask` turns them into the OpenAI-shaped PNG:
  // fully OPAQUE with the painted pixels erased to ALPHA 0 (= "repaint here"). The mask is invalidated
  // by any base/transform change (`syncMaskAfterRedraw`, run from every `redraw`), so a stroke can
  // never be sent misaligned to the image it was drawn on.
  const maskMode = ref(false);
  const brushSize = ref(48); // brush diameter, in CANVAS pixels
  const hasMaskStrokes = ref(false);
  let maskCanvas: HTMLCanvasElement | null = null;
  let maskPainting = false;
  let lastMaskPoint: { x: number; y: number } | null = null;
  // Only the ALPHA of these strokes matters for the export; the hue is just what the overlay tints.
  const MASK_PAINT = 'rgb(239,68,68)';

  /** Create/resize the offscreen strokes canvas + the visible overlay to the current canvas size. */
  function ensureMaskCanvas(): void {
    const canvas = canvasRef.value;
    if (!canvas) return;
    if (!maskCanvas) maskCanvas = document.createElement('canvas');
    if (maskCanvas.width !== canvas.width || maskCanvas.height !== canvas.height) {
      maskCanvas.width = canvas.width; // (re)sizing a canvas also clears it
      maskCanvas.height = canvas.height;
      hasMaskStrokes.value = false;
    }
    syncMaskOverlay();
  }

  /** Match the visible overlay canvas's backing buffer to the base canvas, then repaint it. */
  function syncMaskOverlay(): void {
    const overlay = maskOverlayRef.value;
    const canvas = canvasRef.value;
    if (!overlay || !canvas) return;
    if (overlay.width !== canvas.width || overlay.height !== canvas.height) {
      overlay.width = canvas.width;
      overlay.height = canvas.height;
    }
    renderMaskOverlay();
  }

  /** Draw the painted strokes onto the visible overlay, translucently (so the user sees the mark). */
  function renderMaskOverlay(): void {
    const overlay = maskOverlayRef.value;
    const ctx = overlay?.getContext('2d');
    if (!overlay || !ctx) return;
    ctx.clearRect(0, 0, overlay.width, overlay.height);
    if (maskCanvas && hasMaskStrokes.value) {
      ctx.save();
      ctx.globalAlpha = 0.4;
      ctx.drawImage(maskCanvas, 0, 0);
      ctx.restore();
    }
  }

  /** Pointer client coords over the overlay → canvas-pixel coords (accounts for CSS scale + zoom). */
  function maskPointAt(e: PointerEvent): { x: number; y: number } | null {
    const overlay = maskOverlayRef.value;
    const canvas = canvasRef.value;
    if (!overlay || !canvas) return null;
    const rect = overlay.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) return null;
    return maskPointToCanvas(e.clientX, e.clientY, rect, canvas.width, canvas.height);
  }

  /** Stamp a round dab at `to`, line-joined from `from` so a drag paints one continuous stroke. */
  function stampMask(from: { x: number; y: number } | null, to: { x: number; y: number }): void {
    ensureMaskCanvas();
    const ctx = maskCanvas?.getContext('2d');
    if (!ctx) return;
    ctx.fillStyle = MASK_PAINT;
    ctx.strokeStyle = MASK_PAINT;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.lineWidth = brushSize.value;
    ctx.beginPath();
    ctx.arc(to.x, to.y, Math.max(1, brushSize.value / 2), 0, Math.PI * 2);
    ctx.fill();
    if (from) {
      ctx.beginPath();
      ctx.moveTo(from.x, from.y);
      ctx.lineTo(to.x, to.y);
      ctx.stroke();
    }
    hasMaskStrokes.value = true;
    renderMaskOverlay();
  }

  function onMaskPointerDown(e: PointerEvent): void {
    if (!maskMode.value) return;
    (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
    ensureMaskCanvas();
    maskPainting = true;
    const p = maskPointAt(e);
    if (p) {
      stampMask(null, p);
      lastMaskPoint = p;
    }
  }
  function onMaskPointerMove(e: PointerEvent): void {
    if (!maskPainting) return;
    const p = maskPointAt(e);
    if (!p) return;
    stampMask(lastMaskPoint, p);
    lastMaskPoint = p;
  }
  function onMaskPointerUp(): void {
    maskPainting = false;
    lastMaskPoint = null;
  }

  /** Wipe the painted strokes (mask mode stays on). */
  function clearMask(): void {
    if (maskCanvas) maskCanvas.getContext('2d')?.clearRect(0, 0, maskCanvas.width, maskCanvas.height);
    hasMaskStrokes.value = false;
    lastMaskPoint = null;
    renderMaskOverlay();
  }

  /** Enter/leave mask mode. Mutually exclusive with crop; leaving discards any strokes. */
  function setMaskMode(on: boolean): void {
    if (on) {
      cancelCrop();
      maskMode.value = true;
      ensureMaskCanvas();
    } else {
      maskMode.value = false;
      clearMask();
    }
  }
  function toggleMask(): void {
    setMaskMode(!maskMode.value);
  }

  /** Called from every `redraw`: a base/transform change invalidates the mask — clear + resync it. */
  function syncMaskAfterRedraw(): void {
    const canvas = canvasRef.value;
    if (maskCanvas && canvas) {
      if (maskCanvas.width !== canvas.width || maskCanvas.height !== canvas.height) {
        maskCanvas.width = canvas.width;
        maskCanvas.height = canvas.height;
      } else {
        maskCanvas.getContext('2d')?.clearRect(0, 0, maskCanvas.width, maskCanvas.height);
      }
    }
    hasMaskStrokes.value = false;
    lastMaskPoint = null;
    syncMaskOverlay();
  }

  /**
   * The painted region as an OpenAI-shaped mask PNG at the canvas pixel size: fully OPAQUE white,
   * with the painted pixels ERASED to transparent (alpha 0 = the area to repaint). Null if nothing
   * is painted (so callers naturally fall back to a whole-image edit).
   */
  async function exportMask(): Promise<Blob | null> {
    const canvas = canvasRef.value;
    if (!canvas || !maskCanvas || !hasMaskStrokes.value) return null;
    const out = document.createElement('canvas');
    out.width = canvas.width;
    out.height = canvas.height;
    const ctx = out.getContext('2d');
    if (!ctx) return null;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, out.width, out.height);
    ctx.globalCompositeOperation = 'destination-out';
    ctx.drawImage(maskCanvas, 0, 0);
    ctx.globalCompositeOperation = 'source-over';
    return new Promise<Blob | null>((resolve) => out.toBlob(resolve, 'image/png'));
  }

  // --- Export / AI --------------------------------------------------------------
  /**
   * The current canvas as an encodable payload. Mime narrows to the ORIGINAL file's format when
   * it is canvas-encodable (jpeg/png/webp), else png — so a .jpg stays jpeg bytes on save.
   */
  async function toBlob(): Promise<ImagePayload | null> {
    const canvas = canvasRef.value;
    const current = file.value;
    if (!canvas || !current) return null;
    const mime = /^image\/(jpeg|png|webp)$/.test(current.mime_type ?? '') ? (current.mime_type as string) : 'image/png';
    const ext = mime.split('/')[1] === 'jpeg' ? 'jpg' : mime.split('/')[1];
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, mime, 0.92));
    return blob ? { blob, mime, ext } : null;
  }

  /** Cancel an in-flight AI edit's polling (the queued job still runs server-side; we stop waiting). */
  const aiAbort = ref(false);
  function cancelAi(): void {
    aiAbort.value = true;
  }

  /** Poll a queued edit until it is done (→ the base64 image) or failed/timed out (→ throws). */
  async function pollAiEdit(id: string): Promise<string> {
    const deadline = Date.now() + 180_000;
    while (Date.now() < deadline) {
      if (aiAbort.value) throw new Error('cancelled');
      await new Promise((resolve) => setTimeout(resolve, 2_000));
      if (aiAbort.value) throw new Error('cancelled');
      const res = await api.get<{ data: { status: string; image?: string; error?: string } }>(`/disk/ai/image/${id}`);
      if (aiAbort.value) throw new Error('cancelled'); // cancelled while this poll was in flight
      if (res.data.status === 'done' && res.data.image) return res.data.image;
      if (res.data.status === 'failed') throw new Error(res.data.error || 'AI edit failed');
    }
    throw new Error('AI edit timed out');
  }

  /** The active workspace id (the Reverb channel scope), read where the api singleton stores it. */
  function currentWorkspaceId(): string | null {
    try {
      return localStorage.getItem(WORKSPACE_KEY);
    } catch {
      return null;
    }
  }

  /** Fetch an edit's current status (+ image when done) — one GET, used by the realtime path. */
  async function fetchAiStatus(id: string): Promise<{ status: string; image?: string; error?: string }> {
    const res = await api.get<{ data: { status: string; image?: string; error?: string } }>(`/disk/ai/image/${id}`);
    return res.data;
  }

  /**
   * Wait for a queued edit to finish, preferring the REALTIME push (Reverb): subscribe to the
   * workspace channel, and on the done/failed notification for THIS edit fetch the result via a
   * single GET (the push carries only status, never the multi-MB image). Falls back to polling when
   * Reverb isn't configured or the socket stays silent, so completion is never lost. Returns the
   * edited image (base64).
   */
  async function awaitAiEdit(id: string): Promise<string> {
    const wsId = currentWorkspaceId();
    const channel = wsId ? subscribePrivate(`disk-ai.workspace.${wsId}`) : null;
    if (!channel) return pollAiEdit(id); // Reverb not configured → poll

    return new Promise<string>((resolve, reject) => {
      let settled = false;
      let safety: ReturnType<typeof setTimeout> | null = null;
      let stopConnWatch: () => void = () => {};
      const stopAbort = watch(aiAbort, (aborted) => {
        if (aborted) finish(() => reject(new Error('cancelled')));
      });

      function finish(run: () => void): void {
        if (settled) return;
        settled = true;
        if (safety) clearTimeout(safety);
        stopAbort();
        stopConnWatch();
        try {
          channel!.stopListening('.disk-ai-edit.updated');
        } catch {
          /* channel teardown is best-effort */
        }
        run();
      }

      // Hand off to HTTP polling — used ONLY on a genuine socket failure (subscription/auth error or
      // a lost connection), never on a blind timer: a real edit can take far longer than any short
      // timeout, so polling must not kick in just because the completion push hasn't arrived yet.
      const fallbackToPoll = (): void => finish(() => resolve(pollAiEdit(id)));

      async function collect(): Promise<void> {
        try {
          const data = await fetchAiStatus(id);
          if (data.status === 'done' && data.image) finish(() => resolve(data.image as string));
          else if (data.status === 'failed') finish(() => reject(new Error(data.error || 'AI edit failed')));
        } catch {
          /* transient — keep waiting for the push */
        }
      }

      channel.listen('.disk-ai-edit.updated', (payload) => {
        const p = payload as { id?: string; status?: string; error?: string };
        if (p.id !== id) return; // another edit sharing the workspace channel
        if (p.status === 'done') void collect();
        else if (p.status === 'failed') finish(() => reject(new Error(p.error || 'AI edit failed')));
      });
      channel.error(() => fallbackToPoll()); // subscription/auth error (e.g. 403) → poll
      stopConnWatch = whenConnectionFails(fallbackToPoll); // socket dropped / server down → poll

      // One immediate check catches an edit that finished BEFORE we subscribed. The long safety net
      // covers a socket that connected but silently never delivers — set well past the server-side
      // edit timeout so it never pre-empts a normal (tens-of-seconds) edit.
      void collect();
      safety = setTimeout(fallbackToPoll, 210_000);
    });
  }

  /** A fresh copy of a canvas (or null if a 2D context isn't available — happy-dom). */
  function snapshotCanvas(src: HTMLCanvasElement): HTMLCanvasElement | null {
    const c = document.createElement('canvas');
    c.width = src.width;
    c.height = src.height;
    const ctx = c.getContext('2d');
    if (!ctx) return null;
    ctx.drawImage(src, 0, 0);
    return c;
  }

  /**
   * Confine a masked AI edit to the painted region: gpt-image regenerates the WHOLE image even with
   * a mask, so we keep the ORIGINAL everywhere and paste the AI result ONLY inside the painted
   * pixels. `maskSnap` is opaque where painted; `destination-in` clips the (scaled) AI result to it,
   * then it lands over the original. Returns null if a context is unavailable (→ whole-image commit).
   */
  function compositeMasked(
    original: HTMLCanvasElement,
    maskSnap: HTMLCanvasElement,
    aiImg: HTMLImageElement,
  ): HTMLCanvasElement | null {
    const out = document.createElement('canvas');
    out.width = original.width;
    out.height = original.height;
    const octx = out.getContext('2d');
    const aiLayer = document.createElement('canvas');
    aiLayer.width = original.width;
    aiLayer.height = original.height;
    const actx = aiLayer.getContext('2d');
    if (!octx || !actx) return null;
    // Feather the mask edge so the AI patch FADES into the original instead of showing a hard,
    // pasted-looking seam (blur is a cosmetic edge-softener; if a browser ignores ctx.filter the
    // edge just stays crisp — graceful degradation).
    const feather = Math.max(4, Math.round(Math.min(out.width, out.height) * 0.02));
    actx.drawImage(aiImg, 0, 0, aiLayer.width, aiLayer.height); // scale AI to the original's size
    actx.globalCompositeOperation = 'destination-in';
    actx.filter = `blur(${feather}px)`;
    actx.drawImage(maskSnap, 0, 0); // keep the AI only where the user painted (soft-edged)
    actx.filter = 'none';
    actx.globalCompositeOperation = 'source-over';
    octx.drawImage(original, 0, 0); // original underneath
    octx.drawImage(aiLayer, 0, 0); // masked AI on top, blended at the edges
    return out;
  }

  async function applyAi(prompt: string, mask?: Blob | null): Promise<void> {
    const canvas = canvasRef.value;
    if (!canvas || !file.value || aiBusy.value) return;
    aiBusy.value = true;
    aiAbort.value = false;
    try {
      // AI edits ALWAYS post PNG: gpt-image returns PNG regardless, and posting JPEG/WebP bytes
      // under a filename-derived `image/png` Content-Type makes the provider reject the source.
      const png = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));
      if (!png) return;

      // Snapshot the original + painted mask NOW so a masked composite is immune to any change
      // during the tens-of-seconds queue wait.
      const original = snapshotCanvas(canvas);
      const maskSnapshot = mask && maskCanvas && hasMaskStrokes.value ? snapshotCanvas(maskCanvas) : null;

      const form = new FormData();
      form.append('image', new File([png], 'canvas.png', { type: 'image/png' }));
      form.append('prompt', prompt);
      if (mask) form.append('mask', new File([mask], 'mask.png', { type: 'image/png' }));

      const res = await api.post<{ data: { id: string } }>('/disk/ai/image', form);
      const image = await awaitAiEdit(res.data.id);

      const bytes = Uint8Array.from(atob(image), (ch) => ch.charCodeAt(0));
      const aiImg = await decode(new Blob([bytes], { type: 'image/png' }));
      cancelCrop();

      const composited = original && maskSnapshot ? compositeMasked(original, maskSnapshot, aiImg) : null;
      commitBase(composited ?? baseFromImage(aiImg));
    } finally {
      aiBusy.value = false;
      aiAbort.value = false;
    }
  }

  // --- Draft (autosave) serialize / hydrate ------------------------------------
  /**
   * Snapshot the FULL edit history for an autosave draft: the manifest (history entries + cursors +
   * the referenced base ids) plus a PNG blob for each DISTINCT base still referenced by history. The
   * caller sends only the bases it has not uploaded yet; the manifest's `baseIds` is authoritative.
   */
  async function serializeDraft(): Promise<{ manifest: ImageDraftManifest; bases: { id: number; blob: Blob }[] }> {
    const referenced = [...new Set(history.map((e) => e.baseId))];
    const bakedBases: { id: number; blob: Blob }[] = [];
    for (const id of referenced) {
      const canvas = bases.get(id);
      if (!canvas) continue; // defensive skip — unreachable in practice: every referenced base has a live canvas
      const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));
      if (blob) bakedBases.push({ id, blob });
    }
    return {
      manifest: {
        kind: 'image',
        history: history.map((e) => ({ baseId: e.baseId, state: e.state })),
        historyIndex: historyIndex.value,
        savedIndex: savedIndex.value,
        baseIds: referenced,
      },
      bases: bakedBases,
    };
  }

  /**
   * Rebuild the editor from an autosaved manifest: decode every referenced base PNG back into a base
   * canvas, restore the history + cursors, and re-render the step at `historyIndex`. Rejects if a base
   * blob is missing/undecodable (so the caller can toast + fall back to the file) — and only commits
   * the rebuilt state AFTER every base decodes, so a mid-way failure leaves the current editor intact.
   */
  async function hydrateDraft(
    manifest: ImageDraftManifest,
    fetchBase: (id: number) => Promise<Blob>,
  ): Promise<void> {
    const rebuilt = new Map<number, HTMLCanvasElement>();
    for (const id of manifest.baseIds) {
      // A stored base is already ≤ MAX_EDGE, so baseFromImage won't rescale it. A throw/undefined here
      // propagates out of hydrateDraft (nothing has been mutated yet).
      rebuilt.set(id, baseFromImage(await decode(await fetchBase(id))));
    }

    cancelCrop();
    setMaskMode(false);
    bases.clear();
    for (const [id, canvas] of rebuilt) bases.set(id, canvas);
    nextBaseId = manifest.baseIds.length ? Math.max(0, ...manifest.baseIds) : 0;
    history = manifest.history.map((e) => ({ baseId: e.baseId, state: e.state as EditState }));
    historyIndex.value = manifest.historyIndex;
    historyLen.value = history.length;
    savedIndex.value = manifest.savedIndex;

    const entry = history[historyIndex.value];
    const canvas = entry ? bases.get(entry.baseId) : undefined;
    if (canvas) base = canvas;
    if (entry) restore(entry.state);
    syncBaseSize();
    redraw();
  }

  // Baseline entry so the editor has a clean history even before the first load().
  seedHistory();

  return {
    canvasRef,
    overlayRef,
    loading,
    loadFailed,
    aiBusy,
    dirty,
    quarterTurns,
    flipH,
    flipV,
    filter,
    brightness,
    contrast,
    saturation,
    cropMode,
    cropRect,
    cropUsable,
    cropAspect,
    setCropAspect,
    selectionStyle,
    baseLongest,
    resize,
    canUndo,
    canRedo,
    // Autosave draft: a change pulse, the history cursors, and full-history serialize / hydrate.
    revision,
    historyIndex,
    historyLen,
    savedIndex,
    serializeDraft,
    hydrateDraft,
    load,
    rotate,
    rotateCcw,
    toggleFlipH,
    toggleFlipV,
    setFilter,
    setBrightness,
    setContrast,
    setSaturation,
    commitAdjust,
    undo,
    redo,
    reset,
    markSaved,
    toggleCrop,
    cancelCrop,
    applyCrop,
    onPointerDown,
    onPointerMove,
    onPointerUp,
    // Mask brush.
    maskMode,
    brushSize,
    hasMaskStrokes,
    maskOverlayRef,
    toggleMask,
    setMaskMode,
    clearMask,
    syncMaskOverlay,
    onMaskPointerDown,
    onMaskPointerMove,
    onMaskPointerUp,
    exportMask,
    toBlob,
    applyAi,
    cancelAi,
  };
}
