import { describe, expect, it } from 'vitest';
import { entityDescriptionToText, entityDescriptionToMarkdown } from '../entityDescription';

describe('entityDescriptionToText', () => {
  it('returns "" for null/undefined/blank', () => {
    expect(entityDescriptionToText(null)).toBe('');
    expect(entityDescriptionToText(undefined)).toBe('');
    expect(entityDescriptionToText('   ')).toBe('');
  });

  it('passes a plain string through (trimmed)', () => {
    expect(entityDescriptionToText('  Please review  ')).toBe('Please review');
  });

  it('extracts text from a ProseMirror doc object (block boundaries → spaces)', () => {
    const doc = {
      type: 'doc',
      content: [
        { type: 'paragraph', content: [{ type: 'text', text: 'Hello' }] },
        { type: 'paragraph', content: [{ type: 'text', text: 'world' }] },
      ],
    };
    expect(entityDescriptionToText(doc as never)).toBe('Hello world');
  });

  it('returns "" for an empty doc (the real queue bug: a doc with empty text)', () => {
    const empty = { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: '' }] }] };
    expect(entityDescriptionToText(empty as never)).toBe('');
  });

  it('parses a JSON STRING of a doc', () => {
    const json = JSON.stringify({
      type: 'doc',
      content: [{ type: 'paragraph', content: [{ type: 'text', text: 'From JSON' }] }],
    });
    expect(entityDescriptionToText(json)).toBe('From JSON');
  });

  it('treats a non-doc JSON-looking string as plain text', () => {
    expect(entityDescriptionToText('{not really json')).toBe('{not really json');
  });
});

describe('entityDescriptionToMarkdown', () => {
  it('returns "" for null/undefined/blank', () => {
    expect(entityDescriptionToMarkdown(null)).toBe('');
    expect(entityDescriptionToMarkdown(undefined)).toBe('');
    expect(entityDescriptionToMarkdown('   ')).toBe('');
  });

  it('passes a plain markdown string through (trimmed)', () => {
    expect(entityDescriptionToMarkdown('  **bold**  ')).toBe('**bold**');
  });

  it('serializes a ProseMirror doc to markdown (heading keeps its #)', () => {
    const doc = {
      type: 'doc',
      content: [{ type: 'heading', attrs: { level: 1 }, content: [{ type: 'text', text: 'Title' }] }],
    };
    expect(entityDescriptionToMarkdown(doc as never)).toContain('# Title');
  });

  it('serializes a JSON STRING of a doc', () => {
    const json = JSON.stringify({
      type: 'doc',
      content: [{ type: 'paragraph', content: [{ type: 'text', text: 'From JSON' }] }],
    });
    expect(entityDescriptionToMarkdown(json)).toContain('From JSON');
  });
});
