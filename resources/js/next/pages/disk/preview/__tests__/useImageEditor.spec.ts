// @vitest-environment happy-dom
// useImageEditor — the editing engine's canvas-free logic: dirty flags on every edit, crop
// state gating, and the export guard. (Pixel/geometry math lives in imageOps and has its own
// spec; canvas rendering itself is not exercisable in happy-dom.)
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref } from 'vue';

vi.mock('../../../../app/lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
}));

import { api } from '../../../../app/lib/api';
import { useImageEditor } from '../useImageEditor';
import type { DiskFile } from '../../types';

function file(over: Partial<DiskFile> = {}): DiskFile {
  return {
    id: 'f1', name: 'zdjecie.jpg', path: '/api/disk/f1', type: 'image', size: 5, size_human: '5 B',
    created_at: '2026-07-19 10:00', description: null, mime_type: 'image/jpeg', folder_id: null,
    source: 'disk', created_at_iso: null, updated_at_iso: null, disk_trashed_at: null,
    can_be_updated: true, can_be_moved: true, can_be_deleted: true,
    can_be_restored: false, can_be_force_deleted: false, has_draft: false, ...over,
  };
}

describe('useImageEditor', () => {
  beforeEach(() => vi.clearAllMocks());

  it('starts clean and flips dirty on rotate / flip / filter (but not on filter "none")', () => {
    const editor = useImageEditor(ref(file()));

    expect(editor.dirty.value).toBe(false);

    editor.rotate();
    expect(editor.quarterTurns.value).toBe(1);
    expect(editor.dirty.value).toBe(true);

    const fresh = useImageEditor(ref(file()));
    fresh.setFilter('none');
    expect(fresh.dirty.value).toBe(false);
    fresh.setFilter('sepia');
    expect(fresh.dirty.value).toBe(true);
  });

  it('gates the crop: toggling on/off clears the selection state', () => {
    const editor = useImageEditor(ref(file()));

    editor.toggleCrop();
    expect(editor.cropMode.value).toBe(true);
    expect(editor.cropUsable.value).toBe(false); // nothing selected yet

    editor.toggleCrop();
    expect(editor.cropMode.value).toBe(false);
    expect(editor.cropRect.value).toBeNull();
  });

  it('toBlob returns null before a canvas exists (nothing to export)', async () => {
    const editor = useImageEditor(ref(file()));
    expect(await editor.toBlob()).toBeNull();
  });

  it('undo / redo / reset walk the history and drive canUndo / canRedo / dirty', () => {
    const e = useImageEditor(ref(file()));
    expect(e.canUndo.value).toBe(false);
    expect(e.canRedo.value).toBe(false);

    e.rotate(); // step 1: quarterTurns = 1
    e.toggleFlipH(); // step 2: flipH = true
    expect(e.quarterTurns.value).toBe(1);
    expect(e.flipH.value).toBe(true);
    expect(e.canUndo.value).toBe(true);
    expect(e.dirty.value).toBe(true);

    e.undo(); // back to step 1
    expect(e.flipH.value).toBe(false);
    expect(e.quarterTurns.value).toBe(1);
    expect(e.canRedo.value).toBe(true);

    e.redo(); // forward to step 2
    expect(e.flipH.value).toBe(true);

    e.reset(); // jump to the loaded original
    expect(e.quarterTurns.value).toBe(0);
    expect(e.flipH.value).toBe(false);
    expect(e.dirty.value).toBe(false);
    expect(e.canUndo.value).toBe(false);
    expect(e.canRedo.value).toBe(true);
  });

  it('a new edit after undo truncates the redo tail', () => {
    const e = useImageEditor(ref(file()));
    e.rotate(); // qt 1
    e.rotate(); // qt 2
    e.undo(); // at qt 1, redo available
    expect(e.canRedo.value).toBe(true);
    e.toggleFlipV(); // branch → the qt-2 tail is discarded
    expect(e.canRedo.value).toBe(false);
    expect(e.flipV.value).toBe(true);
    expect(e.quarterTurns.value).toBe(1);
  });

  it('live adjustments read dirty without a history step; commitAdjust records one undoable step', () => {
    const e = useImageEditor(ref(file()));
    e.setBrightness(20);
    expect(e.brightness.value).toBe(20);
    expect(e.canUndo.value).toBe(false); // no history step yet…
    expect(e.dirty.value).toBe(true); // …but the live edit is unsaved (the guard must fire)

    e.commitAdjust();
    expect(e.canUndo.value).toBe(true); // now a discrete undo step exists
    expect(e.dirty.value).toBe(true);

    e.commitAdjust(); // nothing changed → no extra step
    e.undo();
    expect(e.brightness.value).toBe(0); // reverted to the baseline
    expect(e.dirty.value).toBe(false);
  });

  it('markSaved rebaselines dirty to the current step', () => {
    const e = useImageEditor(ref(file()));
    e.rotate();
    expect(e.dirty.value).toBe(true);

    e.markSaved();
    expect(e.dirty.value).toBe(false); // this step is now the clean baseline

    e.rotate();
    expect(e.dirty.value).toBe(true);
    e.undo();
    expect(e.dirty.value).toBe(false); // back at the saved step
  });

  it('stays dirty once the saved baseline is evicted past the history cap', () => {
    const e = useImageEditor(ref(file()));
    // Push well past HISTORY_CAP discrete steps (rotate always changes state → one step each), so
    // the loaded original (the saved baseline at index 0) is trimmed out of the window.
    for (let i = 0; i < 45; i += 1) e.rotate();
    // Undo as far as the trimmed window allows.
    while (e.canUndo.value) e.undo();
    // history[0] is now the oldest SURVIVING rotated state, not the loaded original — so the canvas
    // genuinely differs from the file, and dirty must stay true (guard fires, Save stays enabled).
    expect(e.dirty.value).toBe(true);
  });
});

// --- Mask brush -------------------------------------------------------------------
// happy-dom has no 2D canvas context (getContext('2d') → null), so the actual painting + the exported
// PNG's transparent-where-painted shape are NOT exercisable here (verified in-browser + by the pure
// maskPointToCanvas coordinate test in imageOps.spec). These cover the canvas-free state machine:
// mode toggling, crop↔mask exclusivity, and the "nothing painted → null mask" guard.
describe('useImageEditor — mask brush', () => {
  beforeEach(() => vi.clearAllMocks());

  it('starts with mask mode off and nothing painted', () => {
    const e = useImageEditor(ref(file()));
    expect(e.maskMode.value).toBe(false);
    expect(e.hasMaskStrokes.value).toBe(false);
    expect(e.brushSize.value).toBeGreaterThan(0);
  });

  it('setMaskMode / toggleMask flip mask mode', () => {
    const e = useImageEditor(ref(file()));
    e.setMaskMode(true);
    expect(e.maskMode.value).toBe(true);
    e.toggleMask();
    expect(e.maskMode.value).toBe(false);
  });

  it('crop and mask are mutually exclusive (entering one cancels the other)', () => {
    const e = useImageEditor(ref(file()));
    e.setMaskMode(true);
    expect(e.maskMode.value).toBe(true);

    e.toggleCrop(); // entering crop cancels mask
    expect(e.cropMode.value).toBe(true);
    expect(e.maskMode.value).toBe(false);

    e.setMaskMode(true); // re-entering mask cancels crop
    expect(e.maskMode.value).toBe(true);
    expect(e.cropMode.value).toBe(false);
  });

  it('exportMask resolves to null when nothing is painted', async () => {
    const e = useImageEditor(ref(file()));
    e.setMaskMode(true);
    expect(await e.exportMask()).toBeNull();
  });
});

// --- AI edit (async queue → poll → commit) ----------------------------------------
describe('useImageEditor — applyAi', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers();
    // decode() creates an Image + object URL; stub both so the edited PNG "loads" on a microtask.
    vi.stubGlobal('URL', { createObjectURL: () => 'blob:fake', revokeObjectURL: () => {} });
    vi.stubGlobal(
      'Image',
      class {
        onload: null | (() => void) = null;
        onerror: null | (() => void) = null;
        naturalWidth = 4;
        naturalHeight = 4;
        set src(_v: string) {
          queueMicrotask(() => this.onload?.());
        }
      },
    );
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  /** A minimal stand-in for the visible canvas: toBlob so the export guard passes, getContext null
   *  (like happy-dom) so redraw early-returns without a real 2D context. */
  function fakeCanvas(): HTMLCanvasElement {
    return {
      width: 4,
      height: 4,
      getContext: () => null,
      toBlob: (cb: (b: Blob | null) => void) => cb(new Blob(['x'], { type: 'image/png' })),
    } as unknown as HTMLCanvasElement;
  }

  it('queues the edit with the mask, polls until done, and commits a new base', async () => {
    const e = useImageEditor(ref(file()));
    e.canvasRef.value = fakeCanvas();
    vi.mocked(api.post).mockResolvedValue({ data: { id: 'job-1' } });
    vi.mocked(api.get).mockResolvedValue({ data: { status: 'done', image: btoa('PNG-BYTES') } });

    const mask = new Blob(['m'], { type: 'image/png' });
    const promise = e.applyAi('remove the masked area', mask);

    // aiBusy flips true while queued; run the poll timer(s) + flush the decode microtasks.
    await vi.runAllTimersAsync();
    await promise;

    // Posted to the queue endpoint with image + prompt + mask as a File.
    expect(api.post).toHaveBeenCalledWith('/disk/ai/image', expect.any(FormData));
    const form = vi.mocked(api.post).mock.calls[0][1] as FormData;
    expect(form.get('prompt')).toBe('remove the masked area');
    expect(form.get('mask')).toBeInstanceOf(File);
    // Polled the status endpoint for the returned job id.
    expect(api.get).toHaveBeenCalledWith('/disk/ai/image/job-1');
    // The edited image was registered as a new committed base — a fresh undo step, and no longer busy.
    expect(e.canUndo.value).toBe(true);
    expect(e.aiBusy.value).toBe(false);
  });

  it('is single-flight: applyAi is a no-op while an edit is already in flight', async () => {
    const e = useImageEditor(ref(file()));
    e.canvasRef.value = fakeCanvas();
    e.aiBusy.value = true; // simulate an edit already queued
    await e.applyAi('two'); // guarded out after toBlob (aiBusy) — never reaches the POST
    expect(api.post).not.toHaveBeenCalled();
  });
});

// --- Draft serialize / hydrate round-trip -----------------------------------------
// Autosave persists the FULL edit history: serializeDraft bakes every referenced base to PNG +
// records the manifest; hydrateDraft rebuilds a FRESH editor from it. happy-dom has no 2D context
// (redraw no-ops) and no real canvas encoder, so we stub canvas.toBlob + Image/URL (as the AI spec
// does) and assert the reactive STATE round-trips — the canvas re-render itself is verified in-browser.
describe('useImageEditor — draft serialize / hydrate', () => {
  let origToBlob: HTMLCanvasElement['toBlob'];

  beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('URL', { createObjectURL: () => 'blob:fake', revokeObjectURL: () => {} });
    vi.stubGlobal(
      'Image',
      class {
        onload: null | (() => void) = null;
        onerror: null | (() => void) = null;
        naturalWidth = 4;
        naturalHeight = 4;
        set src(_v: string) {
          queueMicrotask(() => this.onload?.());
        }
      },
    );
    // A real canvas has no working encoder under happy-dom — stub the prototype so serializeDraft
    // gets a deterministic PNG blob for each base (the plain-object fakeCanvas keeps its own toBlob).
    origToBlob = HTMLCanvasElement.prototype.toBlob;
    HTMLCanvasElement.prototype.toBlob = function (cb: BlobCallback) {
      cb(new Blob(['png'], { type: 'image/png' }));
    };
  });
  afterEach(() => {
    vi.unstubAllGlobals();
    HTMLCanvasElement.prototype.toBlob = origToBlob;
  });

  function fakeCanvas(w = 4, h = 4): HTMLCanvasElement {
    return {
      width: w,
      height: h,
      getContext: () => null,
      toBlob: (cb: (b: Blob | null) => void) => cb(new Blob(['x'], { type: 'image/png' })),
    } as unknown as HTMLCanvasElement;
  }

  it('serializes the full history + bases and hydrates a fresh editor to the same state', async () => {
    vi.mocked(api.get).mockResolvedValue(new Blob(['img'], { type: 'image/png' }));

    const e1 = useImageEditor(ref(file()));
    e1.canvasRef.value = fakeCanvas();
    await e1.load(); // base 0 = the loaded original (a real canvas)

    e1.resize(2); // a destructive commit → new base 1
    e1.rotate(); // a cheap step on base 1 (quarterTurns = 1)
    e1.setBrightness(30);
    e1.commitAdjust(); // a committed adjust step
    e1.undo(); // step back onto the rotate step

    // Pre-serialize state: two bases, an undo left us mid-history.
    expect(e1.historyIndex.value).toBe(2);
    expect(e1.savedIndex.value).toBe(0);
    expect(e1.historyLen.value).toBe(4);
    expect(e1.quarterTurns.value).toBe(1);
    expect(e1.dirty.value).toBe(true);

    const { manifest, bases } = await e1.serializeDraft();
    expect(manifest.kind).toBe('image');
    expect(manifest.baseIds).toEqual([0, 1]); // unique baseIds across history
    expect([...bases.map((b) => b.id)].sort()).toEqual([0, 1]); // a PNG per referenced base
    expect(manifest.historyIndex).toBe(2);
    expect(manifest.savedIndex).toBe(0);
    expect(manifest.history).toHaveLength(4);

    // Rebuild a FRESH editor from the manifest, fetching bases from the serialized blobs.
    const byId = new Map(bases.map((b) => [b.id, b.blob]));
    const e2 = useImageEditor(ref(file()));
    await e2.hydrateDraft(manifest, (id) => Promise.resolve(byId.get(id) as Blob));

    expect(e2.historyIndex.value).toBe(e1.historyIndex.value);
    expect(e2.savedIndex.value).toBe(e1.savedIndex.value);
    expect(e2.historyLen.value).toBe(e1.historyLen.value);
    expect(e2.quarterTurns.value).toBe(1); // the step's transform was restored
    expect(e2.brightness.value).toBe(0); // the rotate step had no adjust
    expect(e2.dirty.value).toBe(true); // historyIndex != savedIndex survives
    expect(e2.canUndo.value).toBe(true);
    expect(e2.canRedo.value).toBe(true); // the redo tail (the adjust step) survives
  });

  it('rejects when a referenced base blob is missing, so the caller can fall back to the file', async () => {
    vi.mocked(api.get).mockResolvedValue(new Blob(['img'], { type: 'image/png' }));
    const e1 = useImageEditor(ref(file()));
    e1.canvasRef.value = fakeCanvas();
    await e1.load();
    const { manifest } = await e1.serializeDraft();

    const e2 = useImageEditor(ref(file()));
    await expect(e2.hydrateDraft(manifest, () => Promise.reject(new Error('gone')))).rejects.toThrow();
  });
});
