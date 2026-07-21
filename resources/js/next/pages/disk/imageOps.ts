// Pure pixel operations for the image editor (P8).
//
// Filters run on the raw RGBA byte array (a canvas's ImageData.data), NOT via CanvasRenderingContext2D.filter —
// Safari's ctx.filter support is unreliable, and pixel math is deterministic and unit-testable
// without a real canvas. Each filter mutates the array IN PLACE (the canvas owns one buffer per
// edit) and returns it for chaining. Geometric ops (rotate/flip) are canvas transforms and live in
// the component; only the math that can be tested in isolation lives here.

/** The classic, non-AI filters offered in the editor. */
export type ImageFilter = 'none' | 'grayscale' | 'sepia' | 'invert' | 'warm' | 'cool' | 'highContrast';

export const IMAGE_FILTERS: ImageFilter[] = ['none', 'grayscale', 'sepia', 'invert', 'warm', 'cool', 'highContrast'];

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

/** Warm — lift the red channel and drop the blue by a fixed amount (a warm color cast). */
export function warm(data: Uint8ClampedArray): Uint8ClampedArray {
  for (let i = 0; i < data.length; i += 4) {
    data[i] = clamp255(data[i] + 18);
    data[i + 2] = clamp255(data[i + 2] - 18);
  }
  return data;
}

/** Cool — lift the blue channel and drop the red (a cool color cast). */
export function cool(data: Uint8ClampedArray): Uint8ClampedArray {
  for (let i = 0; i < data.length; i += 4) {
    data[i] = clamp255(data[i] - 18);
    data[i + 2] = clamp255(data[i + 2] + 18);
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

/** Saturation: `amount` is -100..100; -100 → fully grey, 0 → identity, +100 → doubled saturation.
 *  Each channel is pushed away from (or toward) the pixel's Rec.601 luma by the factor. */
export function saturation(data: Uint8ClampedArray, amount: number): Uint8ClampedArray {
  if (amount === 0) return data;
  const factor = 1 + amount / 100;
  for (let i = 0; i < data.length; i += 4) {
    const gray = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
    data[i] = clamp255(gray + (data[i] - gray) * factor);
    data[i + 1] = clamp255(gray + (data[i + 1] - gray) * factor);
    data[i + 2] = clamp255(gray + (data[i + 2] - gray) * factor);
  }
  return data;
}

/** The live, non-destructive tonal adjustments — each on a -100..100 UI scale, 0 = neutral. */
export interface AdjustValues {
  brightness: number;
  contrast: number;
  saturation: number;
}

export const NEUTRAL_ADJUST: AdjustValues = { brightness: 0, contrast: 0, saturation: 0 };

/**
 * Apply brightness → contrast → saturation IN PLACE, in that order. Brightness maps the -100..100
 * UI scale onto the ±255 channel shift; contrast and saturation take the UI value directly. Each
 * step no-ops at 0, so a neutral bundle leaves the bytes untouched.
 */
export function applyAdjustments(data: Uint8ClampedArray, adj: AdjustValues): Uint8ClampedArray {
  if (adj.brightness !== 0) brightness(data, Math.round(adj.brightness * 2.55));
  if (adj.contrast !== 0) contrast(data, adj.contrast);
  if (adj.saturation !== 0) saturation(data, adj.saturation);
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
    case 'warm':
      return warm(data);
    case 'cool':
      return cool(data);
    case 'highContrast':
      return contrast(data, 55);
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

/**
 * Constrain a drag rectangle so the SELECTED PIXELS keep a target aspect ratio (w/h). The anchor
 * corner (x0,y0) and the dragged x1 are kept; y1 is recomputed from the pixel width so
 * (|Δx|·canvasW)/(|Δy|·canvasH) === ratio, preserving the drag's vertical direction and clamped to
 * the canvas. Pure (fractions in, fractions out) so the crop math is testable without a canvas.
 */
export function constrainRatio(
  raw: { x0: number; y0: number; x1: number; y1: number },
  ratio: number,
  canvasW: number,
  canvasH: number,
): { x0: number; y0: number; x1: number; y1: number } {
  if (ratio <= 0 || canvasW <= 0 || canvasH <= 0) return raw;
  const widthPx = Math.abs(raw.x1 - raw.x0) * canvasW;
  const heightFrac = widthPx / ratio / canvasH;
  const dir = raw.y1 >= raw.y0 ? 1 : -1;
  const y1 = Math.min(1, Math.max(0, raw.y0 + dir * heightFrac));
  return { x0: raw.x0, y0: raw.y0, x1: raw.x1, y1 };
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

/**
 * Map a pointer's client coordinates over the mask overlay onto CANVAS-PIXEL coordinates. The
 * overlay is DISPLAYED at `rect` (already post-CSS-`zoom`, straight from getBoundingClientRect)
 * while its backing buffer is `canvasW × canvasH`; scaling by the display→buffer ratio keeps a
 * brush dab aligned with the underlying image regardless of the display scale or zoom. Pure so the
 * mask coordinate math is unit-testable without a real canvas (happy-dom has no 2D context).
 */
export function maskPointToCanvas(
  clientX: number,
  clientY: number,
  rect: { left: number; top: number; width: number; height: number },
  canvasW: number,
  canvasH: number,
): { x: number; y: number } {
  if (rect.width <= 0 || rect.height <= 0) return { x: 0, y: 0 };
  return {
    x: ((clientX - rect.left) / rect.width) * canvasW,
    y: ((clientY - rect.top) / rect.height) * canvasH,
  };
}
