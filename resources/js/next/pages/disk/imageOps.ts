// Pure pixel operations for the image editor (P8).
//
// Filters run on the raw RGBA byte array (a canvas's ImageData.data), NOT via CanvasRenderingContext2D.filter —
// Safari's ctx.filter support is unreliable, and pixel math is deterministic and unit-testable
// without a real canvas. Each filter mutates the array IN PLACE (the canvas owns one buffer per
// edit) and returns it for chaining. Geometric ops (rotate/flip) are canvas transforms and live in
// the component; only the math that can be tested in isolation lives here.

/** The classic, non-AI filters offered in the editor. */
export type ImageFilter = 'none' | 'grayscale' | 'sepia' | 'invert';

export const IMAGE_FILTERS: ImageFilter[] = ['none', 'grayscale', 'sepia', 'invert'];

/** Clamp to a byte (0..255); the source array is Uint8ClampedArray but adjustments overflow first. */
function clamp255(value: number): number {
  return value < 0 ? 0 : value > 255 ? 255 : value;
}

/** Rec. 601 luma — the perceptual grey the eye reads, not a flat average. */
export function grayscale(data: Uint8ClampedArray): Uint8ClampedArray {
  for (let i = 0; i < data.length; i += 4) {
    const y = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
    data[i] = data[i + 1] = data[i + 2] = y;
  }
  return data;
}

/** The classic warm sepia matrix. */
export function sepia(data: Uint8ClampedArray): Uint8ClampedArray {
  for (let i = 0; i < data.length; i += 4) {
    const r = data[i];
    const g = data[i + 1];
    const b = data[i + 2];
    data[i] = clamp255(0.393 * r + 0.769 * g + 0.189 * b);
    data[i + 1] = clamp255(0.349 * r + 0.686 * g + 0.168 * b);
    data[i + 2] = clamp255(0.272 * r + 0.534 * g + 0.131 * b);
  }
  return data;
}

/** Photographic negative — invert each channel, leave alpha. */
export function invert(data: Uint8ClampedArray): Uint8ClampedArray {
  for (let i = 0; i < data.length; i += 4) {
    data[i] = 255 - data[i];
    data[i + 1] = 255 - data[i + 1];
    data[i + 2] = 255 - data[i + 2];
  }
  return data;
}

/** Brightness: shift every channel by `amount` (-255..255). */
export function brightness(data: Uint8ClampedArray, amount: number): Uint8ClampedArray {
  if (amount === 0) return data;
  for (let i = 0; i < data.length; i += 4) {
    data[i] = clamp255(data[i] + amount);
    data[i + 1] = clamp255(data[i + 1] + amount);
    data[i + 2] = clamp255(data[i + 2] + amount);
  }
  return data;
}

/** Contrast: `amount` is -100..100; scales channels around the 128 midpoint. */
export function contrast(data: Uint8ClampedArray, amount: number): Uint8ClampedArray {
  if (amount === 0) return data;
  const factor = (259 * (amount + 255)) / (255 * (259 - amount));
  for (let i = 0; i < data.length; i += 4) {
    data[i] = clamp255(factor * (data[i] - 128) + 128);
    data[i + 1] = clamp255(factor * (data[i + 1] - 128) + 128);
    data[i + 2] = clamp255(factor * (data[i + 2] - 128) + 128);
  }
  return data;
}

/** Apply the named classic filter to the raw RGBA bytes in place. */
export function applyFilter(data: Uint8ClampedArray, filter: ImageFilter): Uint8ClampedArray {
  switch (filter) {
    case 'grayscale':
      return grayscale(data);
    case 'sepia':
      return sepia(data);
    case 'invert':
      return invert(data);
    default:
      return data;
  }
}

/**
 * The rendered dimensions after `quarterTurns` 90° rotations: an odd number of turns swaps width
 * and height. Kept a pure function so the canvas-sizing logic is testable.
 */
export function rotatedSize(
  width: number,
  height: number,
  quarterTurns: number,
): { width: number; height: number } {
  return quarterTurns % 2 === 0 ? { width, height } : { width: height, height: width };
}

/** The largest scale ≤ 1 that fits WxH inside the cap on its longest edge (never upscales). */
export function fitScale(width: number, height: number, cap: number): number {
  const longest = Math.max(width, height);
  return longest > cap ? cap / longest : 1;
}

/** A crop selection in 0..1 canvas fractions. */
export interface CropRect {
  x: number;
  y: number;
  w: number;
  h: number;
}

/** Order a drag's two corners into a {x,y,w,h} rect (start/end may be in any direction). */
export function normalizeCrop(raw: { x0: number; y0: number; x1: number; y1: number }): CropRect {
  return {
    x: Math.min(raw.x0, raw.x1),
    y: Math.min(raw.y0, raw.y1),
    w: Math.abs(raw.x1 - raw.x0),
    h: Math.abs(raw.y1 - raw.y0),
  };
}

/** Map a fractional crop rect onto integer source pixels for drawImage (each edge ≥ 1px). */
export function cropToPixels(
  rect: CropRect,
  width: number,
  height: number,
): { sx: number; sy: number; sw: number; sh: number } {
  return {
    sx: Math.round(rect.x * width),
    sy: Math.round(rect.y * height),
    sw: Math.max(1, Math.round(rect.w * width)),
    sh: Math.max(1, Math.round(rect.h * height)),
  };
}
