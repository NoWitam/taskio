// wikilinkAnchors.spec — slug normalization and the content-offset → heading-anchor mapping.
//
// `normalizeSlug` MUST agree with the backend's WikilinkParser::normalize, because the slug it
// produces is written into the markdown as `[[slug]]` and later resolved by the server into a graph
// edge. A client that slugified differently would author red links pointing at entries that exist.
//
// `anchorForOffset` is what makes "jump to passage" work: search returns a character offset into
// the entry's own content, and the rendered DOM carries no offsets at all. Mapping source offsets
// onto rendered nodes is not solvable in general, so we resolve to the heading that OWNS the
// offset — a stable answer instead of a guessed one.
import { describe, it, expect } from 'vitest';
import { normalizeSlug, headingAnchorId, anchorForOffset } from '../reader/wikilinkAnchors';

describe('normalizeSlug', () => {
  it('lowercases and hyphenates', () => {
    expect(normalizeSlug('Brand Voice')).toBe('brand-voice');
    expect(normalizeSlug('Pricing')).toBe('pricing');
  });

  it('strips Polish diacritics so both spellings reach one slug', () => {
    expect(normalizeSlug('Zażółć gęślą')).toBe('zazolc-gesla');
    expect(normalizeSlug('Ćwiczenia')).toBe('cwiczenia');
  });

  it('collapses runs of punctuation into a single hyphen and trims the ends', () => {
    expect(normalizeSlug('  --Hello,   World!!  ')).toBe('hello-world');
    expect(normalizeSlug('a///b')).toBe('a-b');
  });

  it('returns an empty string when nothing survives', () => {
    expect(normalizeSlug('!!!')).toBe('');
    expect(normalizeSlug('')).toBe('');
  });

  it('is idempotent — slugifying a slug changes nothing', () => {
    const once = normalizeSlug('Brand Voice & Tone');
    expect(normalizeSlug(once)).toBe(once);
  });
});

describe('headingAnchorId', () => {
  it('namespaces the id so it cannot collide with anything else on the page', () => {
    expect(headingAnchorId('Getting started')).toBe('h-getting-started');
  });

  it('falls back to a stable name for a heading with no usable characters', () => {
    expect(headingAnchorId('###')).toBe('h-section');
  });
});

describe('anchorForOffset', () => {
  const DOC = [
    'Intro paragraph.', // 0
    '',
    '## Pricing', //
    '',
    'Pricing body text.',
    '',
    '## Refunds',
    '',
    'Refund body text.',
  ].join('\n');

  it('returns the heading that owns the offset', () => {
    const refundsAt = DOC.indexOf('Refund body text.');
    expect(anchorForOffset(DOC, refundsAt)).toBe('h-refunds');

    const pricingAt = DOC.indexOf('Pricing body text.');
    expect(anchorForOffset(DOC, pricingAt)).toBe('h-pricing');
  });

  it('returns null for an offset before the first heading', () => {
    expect(anchorForOffset(DOC, 3)).toBeNull();
  });

  it('ignores a `#` inside a fenced code block', () => {
    const doc = ['## Real', '', '```bash', '# not a heading', '```', '', 'text'].join('\n');
    expect(anchorForOffset(doc, doc.length - 1)).toBe('h-real');
  });

  it('numbers DUPLICATE headings the same way the DOM pass does', () => {
    // Two sections legitimately called "Zasady"; a deep link must be able to reach the second.
    const doc = ['## Zasady', '', 'first', '', '## Zasady', '', 'second'].join('\n');
    expect(anchorForOffset(doc, doc.indexOf('first'))).toBe('h-zasady');
    expect(anchorForOffset(doc, doc.indexOf('second'))).toBe('h-zasady-2');
  });

  it('handles bad input without throwing', () => {
    expect(anchorForOffset('', 5)).toBeNull();
    expect(anchorForOffset(DOC, Number.NaN)).toBeNull();
    expect(anchorForOffset(null as unknown as string, 0)).toBeNull();
  });

  it('counts CODE POINTS, so an emoji earlier in the doc does not shift the answer', () => {
    const doc = ['# A 😀 heading', '', 'body', '', '## Second', '', 'target'].join('\n');
    const codePointOffset = Array.from(doc).indexOf('t', Array.from(doc).length - 8);
    expect(anchorForOffset(doc, codePointOffset)).toBe('h-second');
  });
});
