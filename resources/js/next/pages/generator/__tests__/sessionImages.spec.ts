// sessionImages.spec — the pure helpers that decide WHAT can be saved to Disk and its default name.
// Only produced images are savable (image parts + scene images); text parts never appear.
import { describe, it, expect } from 'vitest';
import { collectSavableImages, pngFileName } from '../session/sessionImages';
import type { ContentTypePart } from '../types';
import type { SessionResults } from '../sessionTypes';

const label = (part: ContentTypePart): string => part.label;

function part(overrides: Partial<ContentTypePart>): ContentTypePart {
  return { key: 'k', kind: 'text_body', label: 'L', required: false, config: {}, ...overrides };
}

describe('pngFileName', () => {
  it('appends .png when missing and preserves an existing one', () => {
    expect(pngFileName('Spring promo')).toBe('Spring promo.png');
    expect(pngFileName('already.png')).toBe('already.png');
    expect(pngFileName('already.PNG')).toBe('already.PNG');
  });
  it('falls back to a name for a blank base', () => {
    expect(pngFileName('   ')).toBe('image.png');
  });
});

describe('collectSavableImages', () => {
  const parts = [
    part({ key: 'body', kind: 'text_body', label: 'Body' }),
    part({ key: 'image', kind: 'image_plan', label: 'Image' }),
    part({ key: 'scenes', kind: 'scene_plan', label: 'Scenes' }),
  ];

  it('returns [] when there are no results', () => {
    expect(collectSavableImages(parts, null, label)).toEqual([]);
  });

  it('collects an ok image part + ok scene images, skipping text / failed / none', () => {
    const results: SessionResults = {
      body: { kind: 'text_body', status: 'ok', text: 'hi' },
      image: { kind: 'image_plan', status: 'ok', image: { mime: 'image/png', width: 1, height: 1 } },
      scenes: {
        kind: 'scene_plan',
        status: 'ok',
        scenes: [
          { narration: 'a', image_status: 'ok', part_key: 'scene_plan.0', image: { mime: 'image/png', width: 1, height: 1 } },
          { narration: 'b', image_status: 'failed', image_error: 'x' },
          { narration: 'c', image_status: 'none' },
          { narration: 'd', image_status: 'ok', part_key: 'scene_plan.3', image: { mime: 'image/png', width: 1, height: 1 } },
        ],
      },
    };

    expect(collectSavableImages(parts, results, label)).toEqual([
      { partKey: 'image', label: 'Image' },
      { partKey: 'scene_plan.0', label: 'Scenes · 1' },
      { partKey: 'scene_plan.3', label: 'Scenes · 4' },
    ]);
  });

  it('excludes a failed image part (no bytes to save)', () => {
    const results: SessionResults = {
      image: { kind: 'image_plan', status: 'failed', error: 'boom' },
    };
    expect(collectSavableImages(parts, results, label)).toEqual([]);
  });
});
