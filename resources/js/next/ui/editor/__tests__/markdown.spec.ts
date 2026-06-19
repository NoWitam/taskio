import { describe, it, expect, beforeEach } from 'vitest';
import {
  markdownToDoc,
  docToMarkdown,
  __resetParserState,
  type MarkdownDoc,
} from '../markdown';

beforeEach(() => __resetParserState());

/** Parse then serialize — the canonical normalized form. */
function roundTrip(md: string): string {
  return docToMarkdown(markdownToDoc(md));
}

describe('markdownToDoc', () => {
  it('returns a single empty paragraph for blank input', () => {
    expect(markdownToDoc('')).toEqual<MarkdownDoc>({
      type: 'doc',
      content: [{ type: 'paragraph' }],
    });
  });

  it('parses headings (capped at H3)', () => {
    const doc = markdownToDoc('# A\n\n## B\n\n#### Deep');
    expect(doc.content[0]).toMatchObject({ type: 'heading', attrs: { level: 1 } });
    expect(doc.content[1]).toMatchObject({ type: 'heading', attrs: { level: 2 } });
    // H4 clamps to H3.
    expect(doc.content[2]).toMatchObject({ type: 'heading', attrs: { level: 3 } });
  });

  it('parses inline marks including underline (<u>)', () => {
    const doc = markdownToDoc('**b** *i* ~~s~~ <u>u</u> `c`');
    const inline = doc.content[0].content ?? [];
    const types = inline.flatMap((n) => (n.marks ?? []).map((m) => m.type));
    expect(types).toContain('bold');
    expect(types).toContain('italic');
    expect(types).toContain('strike');
    expect(types).toContain('underline');
    expect(types).toContain('code');
  });

  it('parses a link into a link mark with href', () => {
    const doc = markdownToDoc('[Taskio](https://taskio.test)');
    const text = doc.content[0].content?.[0];
    expect(text?.marks?.[0]).toMatchObject({
      type: 'link',
      attrs: { href: 'https://taskio.test' },
    });
  });
});

describe('round-trip fidelity', () => {
  it('headings + paragraph', () => {
    const md = '# Title\n\nA paragraph of text.';
    expect(roundTrip(md)).toBe(md);
  });

  it('nested bullet lists', () => {
    const md = '- one\n- two\n  - nested a\n  - nested b\n- three';
    expect(roundTrip(md)).toBe(md);
  });

  it('ordered list preserves a non-default start', () => {
    const md = '3. three\n4. four\n5. five';
    expect(roundTrip(md)).toBe(md);
  });

  it('code fence with a language', () => {
    const md = '```ts\nconst x: number = 1;\n```';
    expect(roundTrip(md)).toBe(md);
  });

  it('table with column alignment', () => {
    const md =
      '| Name | Qty | Price |\n| :--- | :---: | ---: |\n| Apple | 3 | 1.50 |';
    expect(roundTrip(md)).toBe(md);
  });

  it('blockquote', () => {
    const md = '> a quote line';
    expect(roundTrip(md)).toBe(md);
  });

  it('horizontal rule between paragraphs', () => {
    const md = 'before\n\n---\n\nafter';
    expect(roundTrip(md)).toBe(md);
  });

  it('combined emphasis nests deterministically', () => {
    const md = '**bold and *italic* inside**';
    // bold wraps; italic stays inside. Idempotent after the first pass.
    const once = roundTrip(md);
    expect(roundTrip(once)).toBe(once);
    expect(once).toContain('**');
    expect(once).toContain('*italic*');
  });

  it('is idempotent for a mixed document', () => {
    const md = [
      '# Heading',
      '',
      'Intro with **bold**, *italic*, ~~strike~~, <u>underline</u> and `code`.',
      '',
      '- bullet one',
      '- bullet two',
      '',
      '> quoted',
      '',
      '```js',
      'console.log("hi");',
      '```',
      '',
      '| A | B |',
      '| --- | --- |',
      '| 1 | 2 |',
      '',
      '[link](https://example.com)',
    ].join('\n');
    const once = roundTrip(md);
    expect(roundTrip(once)).toBe(once);
  });
});

describe('task list degradation', () => {
  it('keeps the literal checkbox text as a bullet item', () => {
    const out = roundTrip('- [x] done\n- [ ] todo');
    expect(out).toContain('done');
    expect(out).toContain('todo');
    // No task node: serialized back as bullets.
    expect(out.startsWith('-')).toBe(true);
  });
});

describe('safety / escaping', () => {
  it('escapes inline markup characters in plain text', () => {
    const doc = markdownToDoc('a literal *star* and _underscore_');
    const out = docToMarkdown(doc);
    // Round-trips to the same logical content.
    expect(roundTrip(out)).toBe(out);
  });
});
