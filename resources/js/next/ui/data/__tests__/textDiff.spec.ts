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

// A TIME BUDGET, because this runs synchronously on the main thread: every millisecond here is a
// frozen tab. The sizes are the knowledge module's real ceiling — `KNOWLEDGE_ENTRY_MAX_CHARS` is
// 40 000, so "two full-size entries, rewritten" is the worst thing a version drawer can be asked
// to draw.
//
// The thresholds are deliberately loose (30-50× the measured time on a dev machine) so a slow or
// contended CI box cannot flake them. They are still tight enough to catch the regression they
// were written for: before the `MAX_PAIR_CELLS` guard, the first case took 3.3 s and the second
// 13.8 s, because the replace-hunk pairing ran a token-level LCS for every del×add pair.
describe('diffText — time budget at the knowledge entry cap (40 000 chars)', () => {
  /** ~`chars` of realistic prose: repeated vocabulary, ~85-character lines. */
  function prose(chars: number, salt: string): string {
    const words = ['polityka', 'zwrotow', 'klient', 'termin', 'dni', 'przesylka', 'koszt', 'wyjatek', salt];
    const lines: string[] = [];
    let total = 0;
    for (let i = 0; total < chars; i++) {
      const line = Array.from({ length: 12 }, (_, k) => words[(i + k) % words.length]).join(' ');
      lines.push(line);
      total += line.length + 1;
    }
    return lines.join('\n');
  }

  function msToDiff(a: string, b: string): number {
    const started = performance.now();
    diffText(a, b);
    return performance.now() - started;
  }

  it('diffs two 40 000-character documents that share nothing, well inside budget', () => {
    const oldText = prose(40000, 'alfa');
    const newText = prose(40000, 'beta')
      .split('\n')
      .map((line) => `${line} inne`)
      .join('\n');

    expect(oldText.length).toBeGreaterThan(39000);
    expect(newText.length).toBeGreaterThan(39000);
    expect(msToDiff(oldText, newText)).toBeLessThan(1500);
  });

  it('diffs a 40 000-character document against a lightly edited copy', () => {
    const oldText = prose(40000, 'alfa');
    const lines = oldText.split('\n');
    for (const index of [5, 50, 120, 300]) if (lines[index]) lines[index] += ' ZMIANA';

    expect(msToDiff(oldText, lines.join('\n'))).toBeLessThan(1000);
  });

  it('stays bounded just under the line guard, where the pairing cost used to explode', () => {
    // 2 900 changed lines a side: inside MAX_LINES, so the line DP runs — and the hunk is one
    // 2 900 × 2 900 replace, which is what MAX_PAIR_CELLS exists to bound.
    const oldText = Array.from({ length: 2900 }, (_, i) => `stara linia numer ${i} z jakas trescia`).join('\n');
    const newText = Array.from({ length: 2900 }, (_, i) => `nowa linia numer ${i} z inna trescia`).join('\n');

    expect(msToDiff(oldText, newText)).toBeLessThan(2500);
  });

  it('keeps word-level highlighting when it falls back to index pairing', () => {
    // The guard trades pairing PRECISION for time, never the word diff itself: aligned lines still
    // highlight only what changed.
    const oldText = Array.from({ length: 250 }, (_, i) => `linia numer ${i} ze starym slowem`).join('\n');
    const newText = Array.from({ length: 250 }, (_, i) => `linia numer ${i} ze nowym slowem`).join('\n');

    const rows = diffText(oldText, newText);
    const add = rows.find((r) => r.type === 'add');
    expect(add?.type === 'add' && add.segments.length).toBeGreaterThan(1);
    expect(add?.type === 'add' && add.segments.some((s) => s.type === 'equal')).toBe(true);
  });
});
