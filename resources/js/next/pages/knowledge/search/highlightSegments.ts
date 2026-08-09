// highlightSegments — turn a search snippet + its OFFSET ranges into renderable segments.
//
// Pure, no Vue, no DOM. It exists because the backend deliberately returns NO markup: the search
// resource hands over a verbatim `snippet` plus `highlights: [start, length][]` relative to that
// snippet, and states the invariant
//
//     mb_substr(entry.content, char_start, char_length) === snippet
//
// Rendering `v-html` from a server string would put server markup inside user-authored prose and
// force the client to sanitize text it cannot tell apart from markup. So we slice and let Vue
// escape every piece.
//
// ─────────────────────────────────────────────────────────────────────────────
// CODE POINTS, not UTF-16 units.
// ─────────────────────────────────────────────────────────────────────────────
// The offsets come from PHP's `mb_*` family, which counts CHARACTERS (code points). JavaScript
// string indexing counts UTF-16 code units, and the two disagree the moment an entry contains an
// emoji or any astral-plane character — every highlight after it would slide by one per character.
// `Array.from` iterates code points, so slicing over that array matches the server's arithmetic.
// The cost is one array per snippet, which is nothing next to silently mis-highlighting.

/** One run of snippet text, flagged as matched or not. */
export interface HighlightSegment {
  text: string;
  match: boolean;
}

/** A `[start, length]` pair as the backend emits it. */
export type HighlightRange = [number, number];

/**
 * Merge, clamp and sort ranges so the output is always a clean left-to-right partition.
 *
 * The backend is not required to send ranges sorted or disjoint (two query terms can match
 * overlapping spans), and a naive slicer fed an unsorted or overlapping list produces duplicated
 * or negative-length text. Normalizing here means the renderer stays a plain loop.
 */
function normalizeRanges(ranges: readonly HighlightRange[], total: number): HighlightRange[] {
  const clean: HighlightRange[] = [];

  for (const range of ranges ?? []) {
    if (!Array.isArray(range) || range.length < 2) continue;
    const [rawStart, rawLength] = range;
    if (!Number.isFinite(rawStart) || !Number.isFinite(rawLength)) continue;

    const start = Math.trunc(rawStart);
    const length = Math.trunc(rawLength);
    // A NEGATIVE start is dropped rather than clamped to 0: highlights are defined as offsets
    // WITHIN the snippet, so a negative one is malformed, and clamping would silently emphasise
    // text the server never pointed at. Refusing to highlight is the honest failure.
    if (start < 0 || length <= 0 || start >= total) continue;

    const end = Math.min(total, start + length);
    if (end > start) clean.push([start, end - start]);
  }

  clean.sort((a, b) => a[0] - b[0] || a[1] - b[1]);

  // Coalesce overlapping / touching ranges into one.
  const merged: HighlightRange[] = [];
  for (const [start, length] of clean) {
    const last = merged[merged.length - 1];
    if (last && start <= last[0] + last[1]) {
      const end = Math.max(last[0] + last[1], start + length);
      last[1] = end - last[0];
    } else {
      merged.push([start, length]);
    }
  }
  return merged;
}

/**
 * Split `snippet` into alternating plain / matched segments.
 *
 * Empty or unusable `highlights` yields exactly one non-matching segment — the snippet still
 * renders, just without emphasis, which is the honest outcome for a keyword-only hit.
 */
export function highlightSegments(
  snippet: string,
  highlights: readonly HighlightRange[] | null | undefined,
): HighlightSegment[] {
  const text = typeof snippet === 'string' ? snippet : '';
  if (text === '') return [];

  const chars = Array.from(text);
  const ranges = normalizeRanges(highlights ?? [], chars.length);
  if (ranges.length === 0) return [{ text, match: false }];

  const segments: HighlightSegment[] = [];
  let cursor = 0;

  for (const [start, length] of ranges) {
    if (start > cursor) {
      segments.push({ text: chars.slice(cursor, start).join(''), match: false });
    }
    segments.push({ text: chars.slice(start, start + length).join(''), match: true });
    cursor = start + length;
  }

  if (cursor < chars.length) {
    segments.push({ text: chars.slice(cursor).join(''), match: false });
  }
  return segments;
}

/**
 * The ellipsis markers around a snippet.
 *
 * Read from the server's `truncated_before` / `truncated_after` FLAGS, never by inspecting the
 * string: the snippet is a verbatim slice, so an entry that genuinely begins with "…" would
 * otherwise be reported as truncated, and a truncated window that happens to start on a word
 * boundary would be reported as complete. The flags are the only source that knows.
 */
export function truncationMarkers(chunk: {
  truncated_before?: boolean;
  truncated_after?: boolean;
} | null | undefined): { before: string; after: string } {
  return {
    before: chunk?.truncated_before ? '…' : '',
    after: chunk?.truncated_after ? '…' : '',
  };
}

/**
 * A cosine similarity in [0,1] as a whole-percent number, or null when there is none.
 *
 * ONLY `matched_chunk.score` may be passed here. `rrf_score` is a fused RANK score comparable
 * within a single response and is explicitly not a percentage — the backend says so, and rendering
 * it as one would invent a precision the number does not have.
 */
export function scorePercent(score: number | null | undefined): number | null {
  if (score == null || !Number.isFinite(score)) return null;
  return Math.max(0, Math.min(100, Math.round(score * 100)));
}

/**
 * CONTRACT MIRROR of `KnowledgeChunker::PATH_SEPARATOR`.
 *
 * The heading trail is flattened server-side BEFORE it is stored, so `heading_path` reaches the
 * client as one string (`"Cennik > Zwroty"`), not as an array. If that constant ever changes in
 * `app/modules/Knowledge/Services/KnowledgeChunker.php`, change it here too.
 */
export const KNOWLEDGE_HEADING_SEPARATOR = ' > ';

/**
 * The heading trail as renderable SEGMENTS.
 *
 * Exists because the alternative — handing the raw string to a `v-for` — iterates it CHARACTER BY
 * CHARACTER and draws `C › e › n › n › i › k`, while a `.length` guard passes happily (a string
 * has one). Splitting is a real reconstruction, so two caveats are worth knowing rather than
 * hiding:
 *   • a heading that itself contains " > " is indistinguishable from a level boundary — the server
 *     flattened it, and no client can undo that;
 *   • the server truncates the JOINED string at 500 characters (`PATH_MAX`), so the last segment
 *     may be cut mid-word, and a cut that lands inside the separator can leave an empty tail.
 * Empty and whitespace-only segments are therefore dropped: they would render as a stray `›`.
 */
export function headingPathParts(path: string | null | undefined): string[] {
  if (typeof path !== 'string') return [];

  // The 500-character cut can land INSIDE the separator, leaving a half of it (`"Cennik >"`) that
  // no split on the full `" > "` can see. Trimmed off the tail only, so a `>` in the middle of a
  // real heading survives.
  const trimmed = path.replace(/\s*>\s*$/, '');

  return trimmed
    .split(KNOWLEDGE_HEADING_SEPARATOR)
    .map((segment) => segment.trim())
    .filter((segment) => segment !== '');
}
