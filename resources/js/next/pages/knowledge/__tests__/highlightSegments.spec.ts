// highlightSegments.spec — turning the search API's OFFSET ranges into renderable segments.
//
// This is the seam that lets the frontend refuse server HTML. The backend returns a verbatim
// snippet plus `[start, length]` pairs; if this function is wrong, the alternative is `v-html` from
// the server, which is exactly the sanitisation seam the contract was designed to avoid.
//
// The unicode cases are not decoration: the offsets come from PHP's `mb_*` family, which counts
// CODE POINTS, while JavaScript string indexing counts UTF-16 units. One emoji in an entry and
// every later highlight slides — silently, and only for the users who write emoji.
import { describe, it, expect } from 'vitest';
import {
  headingPathParts,
  highlightSegments,
  truncationMarkers,
  scorePercent,
  type HighlightRange,
} from '../search/highlightSegments';

/** Re-join the segments; a correct split is always lossless. */
function rejoin(segments: { text: string }[]): string {
  return segments.map((s) => s.text).join('');
}

describe('highlightSegments', () => {
  it('splits a snippet around one range', () => {
    const segments = highlightSegments('the brand voice', [[4, 5]]);
    expect(segments).toEqual([
      { text: 'the ', match: false },
      { text: 'brand', match: true },
      { text: ' voice', match: false },
    ]);
  });

  it('is lossless — the segments always rebuild the snippet', () => {
    const snippet = 'alpha beta gamma delta';
    const segments = highlightSegments(snippet, [
      [0, 5],
      [11, 5],
    ]);
    expect(rejoin(segments)).toBe(snippet);
  });

  it('handles a match at the very start and at the very end', () => {
    expect(highlightSegments('abc', [[0, 3]])).toEqual([{ text: 'abc', match: true }]);
    expect(highlightSegments('abcdef', [[3, 3]])).toEqual([
      { text: 'abc', match: false },
      { text: 'def', match: true },
    ]);
  });

  it('renders the whole snippet unmatched when there are no highlights', () => {
    expect(highlightSegments('plain text', [])).toEqual([{ text: 'plain text', match: false }]);
    expect(highlightSegments('plain text', null)).toEqual([{ text: 'plain text', match: false }]);
    expect(highlightSegments('plain text', undefined)).toEqual([
      { text: 'plain text', match: false },
    ]);
  });

  it('returns nothing for an empty snippet', () => {
    expect(highlightSegments('', [[0, 3]])).toEqual([]);
  });

  // The backend is not required to send ranges sorted or disjoint — two query terms can overlap.
  it('sorts UNSORTED ranges before slicing', () => {
    const segments = highlightSegments('alpha beta gamma', [
      [11, 5],
      [0, 5],
    ]);
    expect(segments).toEqual([
      { text: 'alpha', match: true },
      { text: ' beta ', match: false },
      { text: 'gamma', match: true },
    ]);
  });

  it('merges OVERLAPPING ranges instead of duplicating text', () => {
    const segments = highlightSegments('abcdefgh', [
      [1, 4],
      [3, 4],
    ]);
    expect(rejoin(segments)).toBe('abcdefgh');
    expect(segments.filter((s) => s.match).map((s) => s.text)).toEqual(['bcdefg']);
  });

  it('merges TOUCHING ranges into one run', () => {
    const segments = highlightSegments('abcdef', [
      [0, 3],
      [3, 3],
    ]);
    expect(segments).toEqual([{ text: 'abcdef', match: true }]);
  });

  it('clamps a range that runs past the end of the snippet', () => {
    const segments = highlightSegments('abc', [[1, 99]]);
    expect(rejoin(segments)).toBe('abc');
    expect(segments).toEqual([
      { text: 'a', match: false },
      { text: 'bc', match: true },
    ]);
  });

  it('drops ranges that are out of bounds, zero-length or malformed', () => {
    const ranges = [
      [99, 3],
      [0, 0],
      [-5, 2],
      [1],
      null,
      [Number.NaN, 2],
    ] as unknown as HighlightRange[];
    expect(highlightSegments('abcdef', ranges)).toEqual([{ text: 'abcdef', match: false }]);
  });

  it('counts CODE POINTS, matching the server mb_* offsets, not UTF-16 units', () => {
    // "😀" is one character to PHP but two UTF-16 units to JS. Naive slicing would cut it in half
    // and shift the highlight by one.
    const snippet = '😀 brand';
    const segments = highlightSegments(snippet, [[2, 5]]);
    expect(segments).toEqual([
      { text: '😀 ', match: false },
      { text: 'brand', match: true },
    ]);
    expect(rejoin(segments)).toBe(snippet);
  });

  it('handles accented characters without shifting', () => {
    const snippet = 'zażółć gęślą';
    const segments = highlightSegments(snippet, [[7, 5]]);
    expect(segments.find((s) => s.match)?.text).toBe('gęślą');
    expect(rejoin(segments)).toBe(snippet);
  });
});

describe('truncationMarkers', () => {
  // The ellipsis comes from the FLAGS, never from inspecting the string: the snippet is a verbatim
  // slice, so a passage that genuinely starts with "…" is not truncated, and a truncated one that
  // starts on a word boundary is.
  it('renders an ellipsis only where the server says there is more text', () => {
    expect(truncationMarkers({ truncated_before: true, truncated_after: true })).toEqual({
      before: '…',
      after: '…',
    });
    expect(truncationMarkers({ truncated_before: false, truncated_after: true })).toEqual({
      before: '',
      after: '…',
    });
    expect(truncationMarkers({ truncated_before: false, truncated_after: false })).toEqual({
      before: '',
      after: '',
    });
  });

  it('renders no marker for a missing chunk', () => {
    expect(truncationMarkers(null)).toEqual({ before: '', after: '' });
    expect(truncationMarkers(undefined)).toEqual({ before: '', after: '' });
  });
});

describe('scorePercent', () => {
  it('turns a cosine similarity into a whole percent', () => {
    expect(scorePercent(0.87)).toBe(87);
    expect(scorePercent(0.8749)).toBe(87);
    expect(scorePercent(1)).toBe(100);
    expect(scorePercent(0)).toBe(0);
  });

  it('returns null when there is no score (a keyword-only hit)', () => {
    expect(scorePercent(null)).toBeNull();
    expect(scorePercent(undefined)).toBeNull();
    expect(scorePercent(Number.NaN)).toBeNull();
  });

  it('clamps out-of-range values rather than rendering 137%', () => {
    expect(scorePercent(1.4)).toBe(100);
    expect(scorePercent(-0.2)).toBe(0);
  });
});

// The heading trail arrives as ONE ALREADY-JOINED STRING (KnowledgeChunker::formatPath). Handing
// that string to a `v-for` iterates it CHARACTER BY CHARACTER — "Cennik > Zwroty" renders as
// `C › e › n › n › i › k › …`, and a `.length` guard passes because a string has a length. These
// cases pin the split that stops it.
describe('headingPathParts', () => {
  it('splits a joined trail into its SEGMENTS, not its characters', () => {
    const parts = headingPathParts('Cennik > Zwroty');

    expect(parts).toEqual(['Cennik', 'Zwroty']);
    expect(parts).toHaveLength(2); // not 15
  });

  it('handles a single-level trail', () => {
    expect(headingPathParts('Cennik')).toEqual(['Cennik']);
  });

  it('handles a deep trail', () => {
    expect(headingPathParts('Cennik > Zwroty > Wyjątki')).toEqual(['Cennik', 'Zwroty', 'Wyjątki']);
  });

  it('returns nothing for a keyword-only hit, which carries no trail', () => {
    expect(headingPathParts(null)).toEqual([]);
    expect(headingPathParts(undefined)).toEqual([]);
    expect(headingPathParts('')).toEqual([]);
  });

  it('drops the empty tail a 500-character truncation can leave behind', () => {
    // `PATH_MAX` cuts the JOINED string, so it can land inside or just after a separator.
    expect(headingPathParts('Cennik > Zwroty > ')).toEqual(['Cennik', 'Zwroty']);
    expect(headingPathParts('Cennik >')).toEqual(['Cennik']);
  });

  it('is not fooled by a non-string, however it got there', () => {
    // Defensive: a wire regression back to an array must render nothing, not character soup.
    expect(headingPathParts(['Cennik', 'Zwroty'] as unknown as string)).toEqual([]);
  });
});
