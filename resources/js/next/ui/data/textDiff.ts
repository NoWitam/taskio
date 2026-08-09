// Pure, dependency-free text diff for the TEXT file editor's "Review changes" mode.
//
// `diffText(old, new)` returns an ordered list of rows describing how the SAVED file (`old`)
// becomes the CURRENT buffer (`new`), GitHub-style:
//   1. A classic LCS over LINES yields `equal` / `del` (only in old) / `add` (only in new) rows,
//      preserving order — context lines between changes stay `equal`.
//   2. Where a run of `del` rows is immediately followed by a run of `add` rows (a "replace"
//      hunk), the lines are paired by index and an LCS over TOKENS (words + whitespace runs)
//      splits each into `equal` / `del` / `add` SEGMENTS for intra-line word highlighting.
// The math lives here (a pure module, mirrored by `__tests__/textDiff.spec.ts`) so it is
// unit-testable in isolation, exactly like `pages/disk/imageOps.ts`.

/** A word-level span within a changed line. */
export type DiffSegment = { type: 'equal' | 'del' | 'add'; text: string };

/** One rendered row of the diff. `equal` carries the raw line; `del`/`add` carry word segments. */
export type DiffRow =
  | { type: 'equal'; text: string }
  | { type: 'del'; segments: DiffSegment[] }
  | { type: 'add'; segments: DiffSegment[] };

/**
 * Above this line count on either side we skip the O(n·m) DP so a huge file can't hang the UI.
 *
 * EXPORTED because the view has to be able to say "this comparison is coarse" — and the only
 * reliable way to know is the same condition this module applies. Inferring it from the OUTPUT
 * shape does not work: a one-line total replacement produces the identical two-row result, and a
 * renderer that guessed would tell a user their two-word document was "very long".
 */
export const TEXT_DIFF_MAX_LINES = 3000;
const MAX_LINES = TEXT_DIFF_MAX_LINES;
/** A single monstrous line (token count) also falls back to a whole-line segment. */
const MAX_TOKENS = 2000;
/**
 * Above this many del×add PAIRS in one replace hunk, lines are paired by INDEX instead of by
 * similarity.
 *
 * The third guard, and the one the other two do not cover. `MAX_LINES` bounds the line DP, but the
 * similarity pairing below is a second quadratic — one token-level LCS per (del, add) pair — and a
 * hunk can be huge while the document stays well inside `MAX_LINES`. Measured on the knowledge
 * module's 40 000-character entry cap: two substantially rewritten versions (477 lines) took 3.3 s,
 * and 2 900 changed lines took 13.8 s, both of which freeze the tab.
 *
 * 40 000 cells keeps content-pairing for every hunk a human reads closely (a 200×200 rewrite still
 * gets it) and costs ~50 ms; past it, index pairing still produces word-level highlighting, just
 * aligned by position — which is what this module did before content pairing was added, and is
 * indistinguishable to a reader scrolling a thousand-line rewrite.
 */
const MAX_PAIR_CELLS = 40000;

type Op = { type: 'equal' | 'del' | 'add'; text: string };

/**
 * Classic LCS diff over two string sequences → an ordered op list. `equal` items are the common
 * subsequence; `del` items exist only in `a`, `add` items only in `b`. On a mismatch the backtrack
 * prefers `del` (tie `>=`), which clusters removals BEFORE additions in a replace region so the
 * caller can pair them line-by-line.
 */
function lcsDiff(a: string[], b: string[]): Op[] {
  const n = a.length;
  const m = b.length;
  if (n === 0 && m === 0) return [];
  if (n === 0) return b.map((text) => ({ type: 'add', text }));
  if (m === 0) return a.map((text) => ({ type: 'del', text }));

  // dp[i][j] = length of the LCS of a[i..] and b[j..]; filled from the bottom-right corner.
  const dp: Int32Array[] = new Array(n + 1);
  for (let i = 0; i <= n; i++) dp[i] = new Int32Array(m + 1);
  for (let i = n - 1; i >= 0; i--) {
    const ai = a[i];
    const row = dp[i];
    const next = dp[i + 1];
    for (let j = m - 1; j >= 0; j--) {
      row[j] = ai === b[j] ? next[j + 1] + 1 : Math.max(next[j], row[j + 1]);
    }
  }

  const ops: Op[] = [];
  let i = 0;
  let j = 0;
  while (i < n && j < m) {
    if (a[i] === b[j]) {
      ops.push({ type: 'equal', text: a[i] });
      i++;
      j++;
    } else if (dp[i + 1][j] >= dp[i][j + 1]) {
      ops.push({ type: 'del', text: a[i] });
      i++;
    } else {
      ops.push({ type: 'add', text: b[j] });
      j++;
    }
  }
  while (i < n) ops.push({ type: 'del', text: a[i++] });
  while (j < m) ops.push({ type: 'add', text: b[j++] });
  return ops;
}

/** Split a line into words + whitespace runs, keeping separators; drop the empty edge tokens. */
function tokenize(line: string): string[] {
  return line.split(/(\s+)/).filter((t) => t !== '');
}

/** Merge adjacent same-type ops (projected onto one side) into compact segments. */
function project(ops: Op[], keep: 'del' | 'add'): DiffSegment[] {
  const out: DiffSegment[] = [];
  for (const op of ops) {
    // The del side keeps equal + del tokens (→ the old line); the add side keeps equal + add.
    if (op.type !== 'equal' && op.type !== keep) continue;
    const last = out[out.length - 1];
    if (last && last.type === op.type) last.text += op.text;
    else out.push({ type: op.type, text: op.text });
  }
  return out;
}

/** Intra-line word diff of a paired del/add line → segment lists for each side. */
function wordDiff(oldLine: string, newLine: string): { del: DiffSegment[]; add: DiffSegment[] } {
  const a = tokenize(oldLine);
  const b = tokenize(newLine);
  if (a.length > MAX_TOKENS || b.length > MAX_TOKENS) {
    return { del: [{ type: 'del', text: oldLine }], add: [{ type: 'add', text: newLine }] };
  }
  const ops = lcsDiff(a, b);
  return { del: project(ops, 'del'), add: project(ops, 'add') };
}

/** A del/add row always carries at least one segment; empty lines fall back to a whole-line span. */
function segmentsOr(segments: DiffSegment[], type: 'del' | 'add', line: string): DiffSegment[] {
  return segments.length > 0 ? segments : [{ type, text: line }];
}

/** Word-overlap similarity of two lines, 0..1 (Dice coefficient over tokens). Used to pair the lines
 *  of a replace hunk by CONTENT, so a changed line matches its real counterpart rather than a blank
 *  or unrelated line that merely shares its slot. Identical → 1; either side empty → 0. */
function similarity(oldLine: string, newLine: string): number {
  if (oldLine === newLine) return 1;
  const a = tokenize(oldLine);
  const b = tokenize(newLine);
  if (a.length === 0 && b.length === 0) return 1;
  if (a.length === 0 || b.length === 0) return 0;
  if (a.length > MAX_TOKENS || b.length > MAX_TOKENS) return 0;
  let common = 0;
  for (const op of lcsDiff(a, b)) if (op.type === 'equal') common++;
  return (2 * common) / (a.length + b.length);
}

/** Lines must share at least this fraction of words to be paired for intra-line word highlighting;
 *  below it they read as an unrelated remove+add (whole-line), not a word-level edit. */
const PAIR_THRESHOLD = 0.4;

/**
 * Emit a replace hunk (a del run immediately followed by an add run). Each del line is matched to the
 * MOST SIMILAR add line (greedy, one-to-one) before word-diffing — pairing by content, not by index,
 * so a mid-line insertion highlights only the inserted words even when a blank or unrelated line sits
 * in the run (blind index pairing would match the changed line to that blank and red/green the whole
 * line). Paired lines get a word diff; unmatched del/add lines render whole-line.
 */
function pushReplaceHunk(rows: DiffRow[], delRun: string[], addRun: string[]): void {
  const addUsed = new Array<boolean>(addRun.length).fill(false);
  const pairedAdd = new Array<number>(delRun.length).fill(-1); // del index → its paired add index, or -1

  // Past the cell budget, pair by INDEX: the content search below is quadratic in the hunk and is
  // what makes a full-document rewrite take seconds (see MAX_PAIR_CELLS).
  if (delRun.length * addRun.length > MAX_PAIR_CELLS) {
    for (let i = 0; i < delRun.length && i < addRun.length; i++) {
      pairedAdd[i] = i;
      addUsed[i] = true;
    }
  } else {
    for (let i = 0; i < delRun.length; i++) {
      let best = -1;
      let bestScore = PAIR_THRESHOLD; // must beat the threshold to count as a pair
      for (let j = 0; j < addRun.length; j++) {
        if (addUsed[j]) continue;
        const score = similarity(delRun[i], addRun[j]);
        if (score > bestScore) {
          bestScore = score;
          best = j;
        }
      }
      if (best >= 0) {
        pairedAdd[i] = best;
        addUsed[best] = true;
      }
    }
  }

  // Removed lines first (word-diffed where paired), then added lines — GitHub's block layout.
  for (let i = 0; i < delRun.length; i++) {
    const j = pairedAdd[i];
    const segments = j >= 0 ? wordDiff(delRun[i], addRun[j]).del : [{ type: 'del' as const, text: delRun[i] }];
    rows.push({ type: 'del', segments: segmentsOr(segments, 'del', delRun[i]) });
  }
  for (let j = 0; j < addRun.length; j++) {
    const i = pairedAdd.indexOf(j);
    const segments = i >= 0 ? wordDiff(delRun[i], addRun[j]).add : [{ type: 'add' as const, text: addRun[j] }];
    rows.push({ type: 'add', segments: segmentsOr(segments, 'add', addRun[j]) });
  }
}

/**
 * Diff the SAVED text against the CURRENT buffer as an ordered list of rows.
 * Identical input → all `equal`; huge input (> ~3000 lines a side) falls back to a coarse
 * whole-block replace so the DP can never hang the UI. Empty/trailing-newline input is safe.
 */
export function diffText(oldText: string, newText: string): DiffRow[] {
  const oldLines = oldText.split('\n');
  const newLines = newText.split('\n');

  // Guard: skip the O(n·m) DP on very large inputs.
  if (oldLines.length > MAX_LINES || newLines.length > MAX_LINES) {
    if (oldText === newText) return oldLines.map((text) => ({ type: 'equal', text }));
    const rows: DiffRow[] = [];
    if (oldText !== '') rows.push({ type: 'del', segments: [{ type: 'del', text: oldText }] });
    if (newText !== '') rows.push({ type: 'add', segments: [{ type: 'add', text: newText }] });
    return rows;
  }

  const raw = lcsDiff(oldLines, newLines);
  const rows: DiffRow[] = [];
  let idx = 0;
  while (idx < raw.length) {
    const op = raw[idx];
    if (op.type === 'equal') {
      rows.push({ type: 'equal', text: op.text });
      idx++;
      continue;
    }
    if (op.type === 'del') {
      const delRun: string[] = [];
      while (idx < raw.length && raw[idx].type === 'del') delRun.push(raw[idx++].text);
      // A del run immediately followed by an add run is a "replace" → pair them for word highlight.
      if (idx < raw.length && raw[idx].type === 'add') {
        const addRun: string[] = [];
        while (idx < raw.length && raw[idx].type === 'add') addRun.push(raw[idx++].text);
        pushReplaceHunk(rows, delRun, addRun);
      } else {
        for (const line of delRun) rows.push({ type: 'del', segments: [{ type: 'del', text: line }] });
      }
      continue;
    }
    // A pure add run (not preceded by a del run — that case is consumed above).
    const addRun: string[] = [];
    while (idx < raw.length && raw[idx].type === 'add') addRun.push(raw[idx++].text);
    for (const line of addRun) rows.push({ type: 'add', segments: [{ type: 'add', text: line }] });
  }
  return rows;
}
