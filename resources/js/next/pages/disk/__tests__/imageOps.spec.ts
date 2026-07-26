// Pure pixel-op unit tests — the filter math and the geometric-size helpers. No canvas needed.
import { describe, it, expect } from 'vitest';
import {
  grayscale,
  sepia,
  invert,
  warm,
  cool,
  brightness,
  contrast,
  saturation,
  applyAdjustments,
  applyFilter,
  rotatedSize,
  fitScale,
  normalizeCrop,
  constrainRatio,
  cropToPixels,
  maskPointToCanvas,
} from '../imageOps';

/** One opaque RGBA pixel as a fresh clamped array. */
function px(r: number, g: number, b: number, a = 255): Uint8ClampedArray {
  return new Uint8ClampedArray([r, g, b, a]);
}

describe('imageOps filters', () => {
  it('grayscale collapses to Rec.601 luma and leaves alpha', () => {
    const d = grayscale(px(255, 0, 0, 128));
    // luma of pure red = 0.299 × 255 ≈ 76; all three channels collapse to it.
    expect(d[0]).toBe(d[1]);
    expect(d[1]).toBe(d[2]);
    expect(d[0]).toBeGreaterThan(70);
    expect(d[0]).toBeLessThan(82);
    expect(d[3]).toBe(128); // alpha untouched
  });

  it('invert flips each channel but not alpha', () => {
    const d = invert(px(10, 20, 30, 200));
    expect([d[0], d[1], d[2], d[3]]).toEqual([245, 235, 225, 200]);
  });

  it('sepia warms toward brown and clamps to a byte', () => {
    const d = sepia(px(255, 255, 255));
    // A white pixel over-saturates the red/green channels → clamped at 255, never above.
    expect(d[0]).toBe(255);
    expect(d[1]).toBeLessThanOrEqual(255);
    expect(d[2]).toBeLessThanOrEqual(255);
    expect(d[2]).toBeLessThan(d[0]); // blue is dampened → warmer
  });

  it('brightness shifts and clamps; 0 is a no-op', () => {
    expect(Array.from(brightness(px(100, 100, 100), 50)).slice(0, 3)).toEqual([150, 150, 150]);
    expect(Array.from(brightness(px(240, 240, 240), 50)).slice(0, 3)).toEqual([255, 255, 255]); // clamp
    expect(Array.from(brightness(px(10, 20, 30), 0)).slice(0, 3)).toEqual([10, 20, 30]); // no-op
  });

  it('contrast 0 is a no-op; positive pushes away from mid-grey', () => {
    expect(Array.from(contrast(px(50, 128, 200), 0)).slice(0, 3)).toEqual([50, 128, 200]);
    const d = contrast(px(50, 128, 200), 60);
    expect(d[1]).toBe(128); // the midpoint is fixed
    expect(d[0]).toBeLessThan(50); // darks get darker
    expect(d[2]).toBeGreaterThan(200); // brights get brighter
  });

  it('saturation 0 is a no-op; -100 collapses to grey; +100 pushes away from luma', () => {
    // No-op at 0.
    expect(Array.from(saturation(px(200, 100, 50), 0)).slice(0, 3)).toEqual([200, 100, 50]);
    // -100 → every channel equals the pixel's luma (fully desaturated).
    const grey = saturation(px(200, 100, 50), -100);
    expect(grey[0]).toBe(grey[1]);
    expect(grey[1]).toBe(grey[2]);
    // +100 doubles the spread around luma: the dominant channel gets stronger.
    const boosted = saturation(px(200, 100, 50), 100);
    expect(boosted[0]).toBeGreaterThan(200);
    expect(boosted[2]).toBeLessThan(50);
  });

  it('applyAdjustments no-ops on a neutral bundle and chains brightness→contrast→saturation', () => {
    expect(Array.from(applyAdjustments(px(10, 20, 30), { brightness: 0, contrast: 0, saturation: 0 })).slice(0, 3)).toEqual([10, 20, 30]);
    // Brightness maps the -100..100 UI scale onto ±255: +100 → +255 → white clamp.
    expect(Array.from(applyAdjustments(px(100, 100, 100), { brightness: 100, contrast: 0, saturation: 0 })).slice(0, 3)).toEqual([255, 255, 255]);
  });

  it("applyFilter('none') leaves the data untouched", () => {
    const before = px(1, 2, 3);
    const after = applyFilter(px(1, 2, 3), 'none');
    expect(Array.from(after)).toEqual(Array.from(before));
  });

  it('applyFilter dispatches to the named filter', () => {
    expect(Array.from(applyFilter(px(10, 20, 30), 'invert')).slice(0, 3)).toEqual([245, 235, 225]);
  });

  it('warm lifts red + drops blue; cool does the opposite; green stays', () => {
    const w = warm(px(100, 100, 100));
    expect([w[0], w[1], w[2]]).toEqual([118, 100, 82]);
    const c = cool(px(100, 100, 100));
    expect([c[0], c[1], c[2]]).toEqual([82, 100, 118]);
  });

  it('applyFilter dispatches warm / cool / highContrast', () => {
    expect(Array.from(applyFilter(px(100, 100, 100), 'warm')).slice(0, 3)).toEqual([118, 100, 82]);
    expect(Array.from(applyFilter(px(100, 100, 100), 'cool')).slice(0, 3)).toEqual([82, 100, 118]);
    // highContrast pushes away from the 128 midpoint.
    const hc = applyFilter(px(40, 128, 220), 'highContrast');
    expect(hc[0]).toBeLessThan(40);
    expect(hc[1]).toBe(128);
    expect(hc[2]).toBeGreaterThan(220);
  });
});

describe('imageOps geometry', () => {
  it('rotatedSize swaps width/height only on an odd number of quarter turns', () => {
    expect(rotatedSize(800, 600, 0)).toEqual({ width: 800, height: 600 });
    expect(rotatedSize(800, 600, 1)).toEqual({ width: 600, height: 800 });
    expect(rotatedSize(800, 600, 2)).toEqual({ width: 800, height: 600 });
    expect(rotatedSize(800, 600, 3)).toEqual({ width: 600, height: 800 });
  });

  it('fitScale caps the longest edge and never upscales', () => {
    expect(fitScale(8192, 4096, 4096)).toBe(0.5); // longest 8192 → half
    expect(fitScale(2000, 1000, 4096)).toBe(1); // already within the cap → no change
    expect(fitScale(4096, 100, 4096)).toBe(1); // exactly at the cap
  });

  it('normalizeCrop orders the drag corners regardless of direction', () => {
    // Dragged bottom-right → top-left (x1<x0, y1<y0): still yields a positive {x,y,w,h}.
    const a = normalizeCrop({ x0: 0.8, y0: 0.9, x1: 0.2, y1: 0.3 });
    expect(a.x).toBeCloseTo(0.2);
    expect(a.y).toBeCloseTo(0.3);
    expect(a.w).toBeCloseTo(0.6);
    expect(a.h).toBeCloseTo(0.6);
    const b = normalizeCrop({ x0: 0.1, y0: 0.1, x1: 0.5, y1: 0.4 });
    expect(b.x).toBeCloseTo(0.1);
    expect(b.y).toBeCloseTo(0.1);
    expect(b.w).toBeCloseTo(0.4);
    expect(b.h).toBeCloseTo(0.3);
  });

  it('constrainRatio locks the selection to a target pixel ratio, preserving drag direction', () => {
    // Canvas 200×100, target 1:1. A 0.5-wide selection is 100px → 100px tall → 1.0 frac (clamped).
    const a = constrainRatio({ x0: 0, y0: 0, x1: 0.5, y1: 0.2 }, 1, 200, 100);
    expect(a.x1).toBe(0.5);
    expect(a.y1).toBeCloseTo(1);
    // 16:9 on a 100×100 canvas: 0.8 wide (80px) → 45px tall → 0.45 frac.
    const b = constrainRatio({ x0: 0, y0: 0, x1: 0.8, y1: 0.9 }, 16 / 9, 100, 100);
    expect(b.y1).toBeCloseTo(0.45);
    // Upward drag (y1 < y0) stays upward.
    const c = constrainRatio({ x0: 0.5, y0: 0.9, x1: 0.9, y1: 0.1 }, 1, 100, 100);
    expect(c.y1).toBeLessThan(c.y0);
    expect(c.y1).toBeCloseTo(0.5);
  });

  it('cropToPixels maps fractions onto integer source pixels with a 1px floor', () => {
    expect(cropToPixels({ x: 0.25, y: 0.5, w: 0.5, h: 0.25 }, 800, 600)).toEqual({ sx: 200, sy: 300, sw: 400, sh: 150 });
    // A sub-pixel selection never yields a 0-size drawImage source.
    expect(cropToPixels({ x: 0, y: 0, w: 0.0001, h: 0.0001 }, 100, 100)).toEqual({ sx: 0, sy: 0, sw: 1, sh: 1 });
  });
});

describe('maskPointToCanvas (mask brush coordinate mapping)', () => {
  it('maps 1:1 when the overlay is displayed at its buffer size', () => {
    const rect = { left: 0, top: 0, width: 100, height: 100 };
    expect(maskPointToCanvas(40, 60, rect, 100, 100)).toEqual({ x: 40, y: 60 });
  });

  it('scales display coords up to the (larger) canvas buffer', () => {
    // Overlay shown at 100×50 but backed by a 400×200 buffer → coords ×4.
    const rect = { left: 0, top: 0, width: 100, height: 50 };
    expect(maskPointToCanvas(25, 10, rect, 400, 200)).toEqual({ x: 100, y: 40 });
  });

  it('subtracts the overlay origin (offset rect) before scaling', () => {
    // Rect offset by (20,30); a pointer at its center maps to the buffer center.
    const rect = { left: 20, top: 30, width: 200, height: 100 };
    expect(maskPointToCanvas(120, 80, rect, 200, 100)).toEqual({ x: 100, y: 50 });
  });

  it('accounts for zoom (a zoomed-in overlay has a larger display rect)', () => {
    // 2× zoom: the 100×100 buffer is displayed at 200×200, so a display point halves into the buffer.
    const rect = { left: 0, top: 0, width: 200, height: 200 };
    expect(maskPointToCanvas(100, 50, rect, 100, 100)).toEqual({ x: 50, y: 25 });
  });

  it('returns the origin for a degenerate (zero-size) rect instead of dividing by zero', () => {
    expect(maskPointToCanvas(10, 10, { left: 0, top: 0, width: 0, height: 0 }, 100, 100)).toEqual({ x: 0, y: 0 });
  });
});
