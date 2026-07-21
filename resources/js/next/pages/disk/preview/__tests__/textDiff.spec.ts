// Pure line/word diff unit tests — the LCS math behind the text editor's "Review changes" mode.
// No DOM needed; this mirrors the imageOps.spec.ts convention (a pure module + a colocated spec).
import { describe, it, expect } from 'vitest';
import { diffText, type DiffRow } from '../textDiff';

/** Concatenate a row's textual content (raw for equal, joined segments for del/add). */
function rowText(row: DiffRow): string {
  return row.type === 'equal' ? row.text : row.segments.map((s) => s.text).join('');
}

describe('diffText — line diff', () => {
  it('identical input is entirely equal (no del/add rows)', () => {
    const rows = diffText('alpha\nbeta\ngamma', 'alpha\nbeta\ngamma');
    expect(rows.every((r) => r.type === 'equal')).toBe(true);
    expect(rows.map(rowText)).toEqual(['alpha', 'beta', 'gamma']);
  });

  it('a pure added line yields a single add row (and no del)', () => {
    const rows = diffText('a\nb', 'a\nb\nc');
    expect(rows.filter((r) => r.type === 'del')).toHaveLength(0);
    const adds = rows.filter((r) => r.type === 'add');
    expect(adds).toHaveLength(1);
    expect(rowText(adds[0])).toBe('c');
    // Context lines are preserved as equal, in order.
    expect(rows.map((r) => r.type)).toEqual(['equal', 'equal', 'add']);
  });

  it('a pure removed line yields a single del row (and no add)', () => {
    const rows = diffText('a\nb\nc', 'a\nc');
    expect(rows.filter((r) => r.type === 'add')).toHaveLength(0);
    const dels = rows.filter((r) => r.type === 'del');
    expect(dels).toHaveLength(1);
    expect(rowText(dels[0])).toBe('b');
    expect(rows.map((r) => r.type)).toEqual(['equal', 'del', 'equal']);
  });

  it('preserves the order of multiple change hunks', () => {
    const rows = diffText('a\nb\nc\nd', 'a\nB\nc\nD');
    expect(rows.map((r) => r.type)).toEqual(['equal', 'del', 'add', 'equal', 'del', 'add']);
    expect(rows.map(rowText)).toEqual(['a', 'b', 'B', 'c', 'd', 'D']);
  });
});

describe('diffText — intra-line word highlight', () => {
  it('isolates a single changed word with equal segments around it', () => {
    const rows = diffText('the quick brown fox', 'the quick red fox');
    expect(rows).toHaveLength(2);
    const [del, add] = rows;
    expect(del.type).toBe('del');
    expect(add.type).toBe('add');
    // The removed line keeps the shared context plain and highlights only "brown".
    expect(del.type === 'del' && del.segments).toEqual([
      { type: 'equal', text: 'the quick ' },
      { type: 'del', text: 'brown' },
      { type: 'equal', text: ' fox' },
    ]);
    // The added line highlights only "red", with the same surrounding context.
    expect(add.type === 'add' && add.segments).toEqual([
      { type: 'equal', text: 'the quick ' },
      { type: 'add', text: 'red' },
      { type: 'equal', text: ' fox' },
    ]);
  });

  it('pairs replace-hunk lines by SIMILARITY, not index, when a blank drifts into the add run', () => {
    // Regression: the line diff here emits del("…brown fox"), add(""), add("…red brown fox"). Blind
    // index pairing matched the sentence to the blank "" → the whole sentence went red AND green. By
    // similarity, the two sentences pair → only the inserted "red" is highlighted.
    const rows = diffText('keep\nThe quick brown fox\nend', 'keep\n\nThe quick red brown fox\nend');

    const addSentence = rows.find((r) => r.type === 'add' && rowText(r).includes('brown fox'));
    expect(addSentence).toBeTruthy();
    const segs = addSentence!.type === 'add' ? addSentence!.segments : [];
    // NOT one whole-line add segment: the inserted word is isolated with equal context around it.
    expect(segs.length).toBeGreaterThan(1);
    expect(segs.some((s) => s.type === 'add' && s.text.includes('red'))).toBe(true);
    expect(segs.some((s) => s.type === 'equal' && s.text.includes('quick'))).toBe(true);

    // The paired OLD sentence carries no red highlight at all (a pure insertion removed nothing).
    const delSentence = rows.find((r) => r.type === 'del' && rowText(r).includes('brown fox'));
    expect(delSentence!.type === 'del' && delSentence!.segments.every((s) => s.type === 'equal')).toBe(true);
  });

  it('whole-lines the tail when a replace hunk has unequal run lengths', () => {
    // Two old lines replaced by one new line → the first pairs for word diff, the second is a
    // plain whole-line del.
    const rows = diffText('alpha one\nbeta two', 'alpha ONE');
    expect(rows.map((r) => r.type)).toEqual(['del', 'del', 'add']);
    const [pairedDel, tailDel] = rows;
    // First del pairs with the add → "one" is isolated.
    expect(pairedDel.type === 'del' && pairedDel.segments).toEqual([
      { type: 'equal', text: 'alpha ' },
      { type: 'del', text: 'one' },
    ]);
    // The unpaired second del is a single whole-line segment.
    expect(tailDel.type === 'del' && tailDel.segments).toEqual([{ type: 'del', text: 'beta two' }]);
  });
});

describe('diffText — edge cases', () => {
  it('does not crash on empty input and treats it as no change', () => {
    expect(() => diffText('', '')).not.toThrow();
    expect(diffText('', '')).toEqual([{ type: 'equal', text: '' }]);
  });

  it('handles a pure addition from empty content', () => {
    const rows = diffText('', 'hello');
    expect(rows.some((r) => r.type === 'add' && rowText(r) === 'hello')).toBe(true);
  });

  it('handles trailing newlines without crashing', () => {
    expect(() => diffText('a\n', 'a\n')).not.toThrow();
    expect(diffText('a\n', 'a\n').every((r) => r.type === 'equal')).toBe(true);
    const rows = diffText('a\n', 'a\nb\n');
    expect(rows.map((r) => r.type)).toEqual(['equal', 'add', 'equal']);
    expect(rowText(rows[1])).toBe('b');
  });
});

describe('diffText — large-input guard', () => {
  it('falls back to a coarse whole-block replace above ~3000 lines', () => {
    const oldText = Array.from({ length: 3200 }, (_, i) => `old ${i}`).join('\n');
    const newText = Array.from({ length: 3200 }, (_, i) => `new ${i}`).join('\n');
    const rows = diffText(oldText, newText);
    // No per-line DP: exactly one whole-block del + one whole-block add.
    expect(rows).toHaveLength(2);
    expect(rows[0].type).toBe('del');
    expect(rows[1].type).toBe('add');
    expect(rowText(rows[0])).toBe(oldText);
    expect(rowText(rows[1])).toBe(newText);
  });

  it('still reads identical huge input as all-equal', () => {
    const big = Array.from({ length: 3500 }, (_, i) => `line ${i}`).join('\n');
    const rows = diffText(big, big);
    expect(rows).toHaveLength(3500);
    expect(rows.every((r) => r.type === 'equal')).toBe(true);
  });
});
