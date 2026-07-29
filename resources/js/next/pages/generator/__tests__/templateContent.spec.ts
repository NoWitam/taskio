// Unit tests for the DATA-DRIVEN content map — the per-kind defaults, the seed/activate/deactivate of
// optional parts, and the write-content builder (required parts always present, optional only when active).
import { describe, expect, it } from 'vitest';
import {
  activatePart,
  buildWriteContent,
  deactivatePart,
  defaultPartContent,
  fileSlotNames,
  isContentComplete,
  isPartActive,
  seedContent,
} from '../templateContent';
import type { ContentTypeDefinition, ContentTypePart } from '../types';

const bodyPart: ContentTypePart = { key: 'body', kind: 'text_body', label: 'Post body', required: true, config: {} };
const imagePart: ContentTypePart = { key: 'image', kind: 'image_plan', label: 'Image', required: false, config: {} };
const postWithImage: ContentTypeDefinition = { id: 'post_with_image', label: 'Post with image', parts: [bodyPart, imagePart] };

describe('defaultPartContent', () => {
  it('defaults by kind', () => {
    expect(defaultPartContent('text_body')).toEqual({ markdown: '' });
    expect(defaultPartContent('script')).toEqual({ markdown: '' });
    expect(defaultPartContent('image_plan')).toEqual({ base: null, filters: [] });
    expect(defaultPartContent('scene_plan')).toEqual({ scenes: [] });
  });
});

describe('seedContent', () => {
  it('seeds required parts and keeps existing optional parts, dropping unknown keys', () => {
    const seeded = seedContent(postWithImage, { image: { base: { kind: 'disk_file', file: 'f1' }, filters: [] }, gone: 1 });
    expect(seeded.body).toEqual({ markdown: '' });
    expect(seeded.image).toEqual({ base: { kind: 'disk_file', file: 'f1' }, filters: [] });
    expect('gone' in seeded).toBe(false);
  });

  it('leaves an absent optional part absent', () => {
    const seeded = seedContent(postWithImage, {});
    expect(seeded.body).toEqual({ markdown: '' });
    expect('image' in seeded).toBe(false);
  });
});

describe('activate / deactivate optional parts', () => {
  it('activatePart seeds the default; deactivatePart drops the key', () => {
    const activated = activatePart({ body: { markdown: '' } }, imagePart);
    expect(isPartActive(activated, imagePart)).toBe(true);
    expect(activated.image).toEqual({ base: null, filters: [] });

    const deactivated = deactivatePart(activated, imagePart);
    expect(isPartActive(deactivated, imagePart)).toBe(false);
  });
});

describe('buildWriteContent', () => {
  it('emits required parts always and optional parts only when active', () => {
    const withImage = buildWriteContent(postWithImage, {
      body: { markdown: 'Hello' },
      image: { base: { kind: 'from_slot', slot: 'hero' }, filters: [] },
    });
    expect(withImage).toEqual({
      body: { markdown: 'Hello' },
      image: { base: { kind: 'from_slot', slot: 'hero' }, filters: [] },
    });

    const withoutImage = buildWriteContent(postWithImage, { body: { markdown: 'Hi' } });
    expect(withoutImage).toEqual({ body: { markdown: 'Hi' } });
    expect('image' in withoutImage).toBe(false);
  });
});

describe('isContentComplete', () => {
  it('requires a complete base for an active image plan', () => {
    expect(
      isContentComplete(postWithImage, { body: { markdown: '' }, image: { base: null, filters: [] } }, []),
    ).toBe(false);
    expect(
      isContentComplete(postWithImage, { body: { markdown: '' }, image: { base: { kind: 'disk_file', file: 'f1' }, filters: [] } }, []),
    ).toBe(true);
  });

  it('is complete when an optional image is simply absent', () => {
    expect(isContentComplete(postWithImage, { body: { markdown: '' } }, [])).toBe(true);
  });
});

describe('fileSlotNames', () => {
  it('lists only the file-typed slots', () => {
    expect(
      fileSlotNames([
        { name: 'topic', descriptor: { base: 'text' } },
        { name: 'hero', descriptor: { base: 'file' } },
      ]),
    ).toEqual(['hero']);
  });
});
