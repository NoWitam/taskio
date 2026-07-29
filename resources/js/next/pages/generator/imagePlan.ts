// imagePlan — the PURE, testable core of an IMAGE PLAN (the config of an `image_plan` part and of a
// scene's optional image). An image plan mirrors the variable pipeline: a BASE (where the image starts)
// + an ORDERED FILTER CHAIN (how it is transformed). This is a NEW ordered pipeline model — the Disk
// editor is single-filter, so we REUSE its pure `imageOps.ts` functions (for an optional client dry-run)
// but never fork the editor.
//
// The FE model is EXACTLY the wire shape (base + filters), so `slotDraftToWire`-style stripping is not
// needed — the object a part v-models IS what is POSTed. Owner-locked ops (D-table) + the base kinds
// (disk_file / from_slot / ai_generate, D5+D6) live here so the builder + validator + preview agree.
import {
  brightness,
  contrast,
  cool,
  grayscale,
  invert,
  saturation,
  sepia,
  warm,
} from '../disk/imageOps';
import type {
  AiEditFilter,
  ImageBase,
  ImageBaseKind,
  ImageFilterStep,
  ImagePlanContent,
  PixelFilter,
  PixelOp,
} from './types';

/** The base kinds an image may START from, in the order the picker offers them (D5 + D6). */
export const IMAGE_BASE_KINDS: ImageBaseKind[] = ['disk_file', 'from_slot', 'ai_generate'];

/** How a pixel op is parametrized — drives the builder's per-op param control + the client validator. */
export type PixelParamShape = 'none' | 'amount' | 'crop' | 'rotate' | 'flip';

/** One pixel op's authoring metadata (the imageOps.ts vocabulary, in the builder's offer order). */
export interface PixelOpDef {
  op: PixelOp;
  shape: PixelParamShape;
}

/** The ordered pixel-op catalog — mirrors ImagePlanValidator::PIXEL_OPS 1:1 (the load-bearing wire set). */
export const PIXEL_OPS: PixelOpDef[] = [
  { op: 'grayscale', shape: 'none' },
  { op: 'sepia', shape: 'none' },
  { op: 'invert', shape: 'none' },
  { op: 'warm', shape: 'none' },
  { op: 'cool', shape: 'none' },
  { op: 'brightness', shape: 'amount' },
  { op: 'contrast', shape: 'amount' },
  { op: 'saturation', shape: 'amount' },
  { op: 'crop', shape: 'crop' },
  { op: 'rotate', shape: 'rotate' },
  { op: 'flip', shape: 'flip' },
];

/** The param shape for a pixel op (defaults to `none` for an unknown op). */
export function pixelParamShape(op: PixelOp): PixelParamShape {
  return PIXEL_OPS.find((def) => def.op === op)?.shape ?? 'none';
}

// --- Factories (default, valid-by-construction) ----------------------------

/** An empty image plan — no base chosen yet, no filters. */
export function emptyImagePlan(): ImagePlanContent {
  return { base: null, filters: [] };
}

/** A fresh base of the given kind, with its member seeded empty (the user fills it). */
export function makeBase(kind: ImageBaseKind): ImageBase {
  switch (kind) {
    case 'disk_file':
      return { kind, file: null };
    case 'from_slot':
      return { kind, slot: null };
    case 'ai_generate':
      return { kind, prompt: '' };
  }
}

/** The default params for a pixel op (valid for its shape); undefined for the no-param color casts. */
export function defaultPixelParams(op: PixelOp): Record<string, unknown> | undefined {
  switch (pixelParamShape(op)) {
    case 'amount':
      return { amount: 0 };
    case 'crop':
      return { rect: { x: 0, y: 0, w: 1, h: 1 } };
    case 'rotate':
      return { quarterTurns: 1 };
    case 'flip':
      return { axis: 'horizontal' };
    default:
      return undefined;
  }
}

/** A fresh pixel filter step (params seeded per its op). */
export function makePixelFilter(op: PixelOp): PixelFilter {
  const params = defaultPixelParams(op);
  return params === undefined ? { kind: 'pixel', op } : { kind: 'pixel', op, params };
}

/** A fresh AI-edit filter step (empty prompt). */
export function makeAiEditFilter(): AiEditFilter {
  return { kind: 'ai_edit', prompt: '' };
}

// --- Ordering ---------------------------------------------------------------

/** Move a chain step up (dir -1) or down (dir 1); returns a NEW array (out-of-range is a no-op copy). */
export function moveStep(filters: ImageFilterStep[], index: number, dir: -1 | 1): ImageFilterStep[] {
  const target = index + dir;
  if (index < 0 || index >= filters.length || target < 0 || target >= filters.length) {
    return [...filters];
  }
  const next = [...filters];
  [next[index], next[target]] = [next[target], next[index]];
  return next;
}

// --- Client validation (mirrors ImagePlanValidator; the server stays authoritative) -------------

/** Whether a base is structurally complete enough to save (its required member is present). */
export function isBaseComplete(base: ImageBase | null, fileSlotNames: string[]): boolean {
  if (base === null) return false;
  switch (base.kind) {
    case 'disk_file':
      return typeof base.file === 'string' && base.file !== '';
    case 'from_slot':
      return typeof base.slot === 'string' && base.slot !== '' && fileSlotNames.includes(base.slot);
    case 'ai_generate':
      // sub-stage 6 (LIVE): a text→image base needs a prompt. The server (validateBody) tolerates an
      // empty prompt, but — mirroring the "required member present" gate of the other two bases — the
      // client requires a non-empty one so a base with nothing to generate can't be saved.
      return typeof base.prompt === 'string' && base.prompt.trim() !== '';
    default:
      return false;
  }
}

/** Whether an image plan is savable: a complete base (a chain is optional; an ai_edit needs no prompt). */
export function isImagePlanComplete(plan: ImagePlanContent | null, fileSlotNames: string[]): boolean {
  if (plan === null) return false;
  return isBaseComplete(plan.base, fileSlotNames);
}

// --- Optional client dry-run (default OFF) ----------------------------------

/**
 * Apply the pixel steps of a chain to raw RGBA bytes IN PLACE, REUSING the Disk editor's pure
 * `imageOps.ts` functions — a lightweight optional client PREVIEW of the deterministic ops (the ai_edit
 * steps + crop/rotate/flip geometry are server-side / sub-stage 2, so they are skipped here). NEVER runs
 * by default; the builder gates it behind an off-by-default toggle. Pure + unit-testable (no canvas).
 */
export function dryRunPixelChain(data: Uint8ClampedArray, filters: ImageFilterStep[]): Uint8ClampedArray {
  for (const step of filters) {
    if (step.kind !== 'pixel') continue;
    const amount = amountOf(step);
    switch (step.op) {
      case 'grayscale':
        grayscale(data);
        break;
      case 'sepia':
        sepia(data);
        break;
      case 'invert':
        invert(data);
        break;
      case 'warm':
        warm(data);
        break;
      case 'cool':
        cool(data);
        break;
      case 'brightness':
        brightness(data, Math.round(amount * 2.55));
        break;
      case 'contrast':
        contrast(data, amount);
        break;
      case 'saturation':
        saturation(data, amount);
        break;
      // crop / rotate / flip are canvas geometry (not raw-byte math) — deferred to execution.
      default:
        break;
    }
  }
  return data;
}

/** The `-100..100` amount of a tonal pixel step (0 when absent / malformed). */
function amountOf(step: PixelFilter): number {
  const amount = step.params?.amount;
  return typeof amount === 'number' ? amount : 0;
}
