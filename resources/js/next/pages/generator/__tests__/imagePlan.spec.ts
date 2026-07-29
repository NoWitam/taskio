// Unit tests for the PURE image-plan model — the base kinds, the pixel-op catalog + default params
// (mirroring ImagePlanValidator's wire), chain reordering, the client completeness gate, and the optional
// dry-run that REUSES the Disk editor's imageOps functions.
import { describe, expect, it } from 'vitest';
import {
  IMAGE_BASE_KINDS,
  PIXEL_OPS,
  defaultPixelParams,
  dryRunPixelChain,
  isBaseComplete,
  isImagePlanComplete,
  makeAiEditFilter,
  makeBase,
  makePixelFilter,
  moveStep,
  pixelParamShape,
} from '../imagePlan';
import type { ImageFilterStep } from '../types';

describe('base kinds', () => {
  it('offers disk_file, from_slot and ai_generate in order', () => {
    expect(IMAGE_BASE_KINDS).toEqual(['disk_file', 'from_slot', 'ai_generate']);
  });

  it('makeBase seeds the kind-specific member empty', () => {
    expect(makeBase('disk_file')).toEqual({ kind: 'disk_file', file: null });
    expect(makeBase('from_slot')).toEqual({ kind: 'from_slot', slot: null });
    expect(makeBase('ai_generate')).toEqual({ kind: 'ai_generate', prompt: '' });
  });
});

describe('pixel op catalog (mirrors the backend wire)', () => {
  it('carries the exact 11 ops', () => {
    expect(PIXEL_OPS.map((d) => d.op)).toEqual([
      'grayscale', 'sepia', 'invert', 'warm', 'cool',
      'brightness', 'contrast', 'saturation',
      'crop', 'rotate', 'flip',
    ]);
  });

  it('maps each op to its param shape', () => {
    expect(pixelParamShape('grayscale')).toBe('none');
    expect(pixelParamShape('brightness')).toBe('amount');
    expect(pixelParamShape('crop')).toBe('crop');
    expect(pixelParamShape('rotate')).toBe('rotate');
    expect(pixelParamShape('flip')).toBe('flip');
  });

  it('defaults params valid for the op (and none for color casts)', () => {
    expect(defaultPixelParams('grayscale')).toBeUndefined();
    expect(defaultPixelParams('brightness')).toEqual({ amount: 0 });
    expect(defaultPixelParams('crop')).toEqual({ rect: { x: 0, y: 0, w: 1, h: 1 } });
    expect(defaultPixelParams('rotate')).toEqual({ quarterTurns: 1 });
    expect(defaultPixelParams('flip')).toEqual({ axis: 'horizontal' });
  });

  it('makePixelFilter emits the exact wire (no params key for a color cast)', () => {
    expect(makePixelFilter('grayscale')).toEqual({ kind: 'pixel', op: 'grayscale' });
    expect(makePixelFilter('brightness')).toEqual({ kind: 'pixel', op: 'brightness', params: { amount: 0 } });
  });

  it('makeAiEditFilter emits an empty-prompt ai_edit step', () => {
    expect(makeAiEditFilter()).toEqual({ kind: 'ai_edit', prompt: '' });
  });
});

describe('moveStep', () => {
  const chain: ImageFilterStep[] = [
    { kind: 'pixel', op: 'grayscale' },
    { kind: 'ai_edit', prompt: 'x' },
    { kind: 'pixel', op: 'invert' },
  ];

  it('reorders up and down', () => {
    expect(moveStep(chain, 2, -1).map((s) => (s.kind === 'pixel' ? s.op : 'ai'))).toEqual(['grayscale', 'invert', 'ai']);
    expect(moveStep(chain, 0, 1).map((s) => (s.kind === 'pixel' ? s.op : 'ai'))).toEqual(['ai', 'grayscale', 'invert']);
  });

  it('is a no-op at the boundaries', () => {
    expect(moveStep(chain, 0, -1)).toEqual(chain);
    expect(moveStep(chain, 2, 1)).toEqual(chain);
  });
});

describe('completeness (client gate)', () => {
  it('a disk_file base needs a file id; a from_slot base needs a declared file slot', () => {
    expect(isBaseComplete({ kind: 'disk_file', file: 'f1' }, [])).toBe(true);
    expect(isBaseComplete({ kind: 'disk_file', file: null }, [])).toBe(false);
    expect(isBaseComplete({ kind: 'from_slot', slot: 'hero' }, ['hero'])).toBe(true);
    expect(isBaseComplete({ kind: 'from_slot', slot: 'hero' }, [])).toBe(false);
  });

  it('ai_generate (sub-stage 6, LIVE) is savable once its prompt is non-empty', () => {
    expect(isBaseComplete({ kind: 'ai_generate', prompt: 'a cat' }, [])).toBe(true);
    expect(isBaseComplete({ kind: 'ai_generate', prompt: '' }, [])).toBe(false);
    expect(isBaseComplete({ kind: 'ai_generate', prompt: '   ' }, [])).toBe(false);
    expect(isBaseComplete({ kind: 'ai_generate' }, [])).toBe(false);
  });

  it('an image plan is complete when its base is complete (a chain is optional)', () => {
    expect(isImagePlanComplete({ base: { kind: 'disk_file', file: 'f1' }, filters: [] }, [])).toBe(true);
    expect(isImagePlanComplete({ base: { kind: 'ai_generate', prompt: 'a cat' }, filters: [] }, [])).toBe(true);
    expect(isImagePlanComplete({ base: null, filters: [] }, [])).toBe(false);
  });
});

describe('dryRunPixelChain (reuses imageOps)', () => {
  it('applies the pixel steps in order, skipping ai_edit + geometry', () => {
    // A single opaque grey pixel; invert flips it, brightness lifts it.
    const data = new Uint8ClampedArray([100, 100, 100, 255]);
    dryRunPixelChain(data, [
      { kind: 'pixel', op: 'invert' },
      { kind: 'ai_edit', prompt: 'ignored' },
      { kind: 'pixel', op: 'crop', params: { rect: { x: 0, y: 0, w: 0.5, h: 0.5 } } },
    ]);
    // invert: 255-100 = 155 (crop is geometry — skipped, alpha untouched).
    expect(Array.from(data)).toEqual([155, 155, 155, 255]);
  });
});
