// Pure pixel-op unit tests — the filter math and the geometric-size helpers. No canvas needed.
import { describe, it, expect } from 'vitest';
import {
  grayscale,
  sepia,
  invert,
  brightness,
  contrast,
  applyFilter,
  rotatedSize,
  fitScale,
  normalizeCrop,
  cropToPixels,
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

  it("applyFilter('none') leaves the data untouched", () => {
    const before = px(1, 2, 3);
    const after = applyFilter(px(1, 2, 3), 'none');
    expect(Array.from(after)).toEqual(Array.from(before));
  });

  it('applyFilter dispatches to the named filter', () => {
    expect(Array.from(applyFilter(px(10, 20, 30), 'invert')).slice(0, 3)).toEqual([245, 235, 225]);
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

  it('cropToPixels maps fractions onto integer source pixels with a 1px floor', () => {
    expect(cropToPixels({ x: 0.25, y: 0.5, w: 0.5, h: 0.25 }, 800, 600)).toEqual({ sx: 200, sy: 300, sw: 400, sh: 150 });
    // A sub-pixel selection never yields a 0-size drawImage source.
    expect(cropToPixels({ x: 0, y: 0, w: 0.0001, h: 0.0001 }, 100, 100)).toEqual({ sx: 0, sy: 0, sw: 1, sh: 1 });
  });
});
