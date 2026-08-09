<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\DTOs\ChunkDraft;
use App\Modules\Knowledge\Exceptions\KnowledgeChunkOverflowException;

/**
 * Splits an entry into the passages that actually get embedded. A PURE FUNCTION of
 * (title, content, config): no AI, no database, no clock, no randomness.
 *
 * That purity is a requirement, not a style preference. The output feeds a per-chunk `digest`, and the
 * digest is what decides whether a passage gets re-embedded or reused. A splitter whose output could
 * drift between two runs over identical text would re-buy every vector in the base each time it ran —
 * so "same input, same chunks" is a COST guarantee, and it is pinned by a determinism test.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY SPLIT AT ALL
 *
 * One embedding over 40 000 characters averages away exactly the specifics a query is looking for.
 * The unit of retrieval has to be a passage. But an arbitrary passage is nearly as bad: a fragment
 * that begins mid-argument and carries no indication of what section it came from embeds to something
 * no query resembles. So the split follows the document's OWN structure first, and only falls back to
 * blunter instruments when the structure does not bound the size.
 *
 * THE CASCADE, most structural first:
 *
 *   1. HEADINGS (`#`..`######`). Each section becomes a candidate passage carrying its heading trail,
 *      rooted at the entry TITLE — "Cennik > Rabaty > Wolumen". The heading line itself is not part of
 *      the body; it is carried in `heading_path` and prepended to the embedded text by the indexer, so
 *      every passage says where it came from.
 *   2. PARAGRAPHS (blank line) inside an oversize section.
 *   3. SENTENCES inside an oversize paragraph — terminator + whitespace, or a line end. Polish
 *      abbreviations ("np.", "m.in.", "itd.") are NOT sentence ends; without that guard a list of
 *      examples shatters into fragments, which is both worse retrieval and more chunks to pay for.
 *   4. HARD CUT as the last resort, for a single sentence longer than the ceiling (minified text, a
 *      pasted table, a language this splitter does not model).
 *
 * MERGING runs before splitting: adjacent sections that are too small to embed meaningfully are fused,
 * and the fused passage takes the two paths' COMMON ANCESTOR ("Cennik", not "Cennik > Rabaty"), since
 * that is the only claim still true of all of its text. A 30-character chunk embeds to noise and costs
 * a full call, so merging is a quality and a cost measure at once.
 *
 * OVERLAP is applied ONLY at a hard cut, and never across a heading boundary. The reasoning is that
 * overlap exists to repair a cut that severed a thought — which is precisely what a hard cut does and
 * what a paragraph or heading boundary, by construction, does not. Overlapping across a heading would
 * be actively harmful: it would file one section's text under another section's path, and the path is
 * part of what gets embedded.
 *
 * OFFSETS. Every draft is a CONTIGUOUS slice of the original content, so
 * `mb_substr($content, $charStart, $charLength)` returns the draft's text exactly. Nothing is
 * re-joined or rewritten — a merged span simply covers the intervening heading lines, which is why a
 * merged passage keeps its sub-headings inline. Overlapping hard-cut windows therefore produce
 * OVERLAPPING ranges, which is expected: the spans are addresses into the source, not a partition of
 * it. Offsets are in CHARACTERS (not bytes) so a citation highlight lands correctly in Polish text.
 */
class KnowledgeChunker
{
    /** ATX headings only: one to six hashes, at least one space, non-empty text. */
    private const HEADING = '/^(\#{1,6})[ \t]+(\S.*)$/u';

    private const PATH_SEPARATOR = ' > ';

    /** Matches the `heading_path` column width; truncated BEFORE hashing so the digest stays stable. */
    private const PATH_MAX = 500;

    /**
     * Tokens that end in a period WITHOUT ending a sentence. Lowercased; the trailing period is part
     * of the token. Polish-first because this is a Polish-language product, plus the handful of Latin
     * abbreviations that show up in technical prose.
     *
     * @var array<int, string>
     */
    private const ABBREVIATIONS = [
        'np.', 'tzn.', 'm.in.', 'itd.', 'itp.', 'tj.', 'tzw.', 'ok.', 'ww.', 'ds.',
        'nr.', 'r.', 'w.', 'ul.', 'al.', 'godz.', 'min.', 'sek.', 'godzin.',
        'por.', 'zob.', 'str.', 'ss.', 'pkt.', 'art.', 'ust.', 'par.', 'rozdz.', 'cz.',
        'prof.', 'dr.', 'hab.', 'inż.', 'mgr.', 'im.', 'św.', 'ks.',
        'p.n.e.', 'n.e.', 'jw.', 'ew.', 'ident.', 'sp.', 'z.o.o.',
        'etc.', 'e.g.', 'i.e.', 'vs.', 'cf.', 'no.',
    ];

    /**
     * Cut $content into passages.
     *
     * @return array<int, ChunkDraft> ordinals are 0-based and gapless
     *
     * @throws KnowledgeChunkOverflowException when the fan-out cap would be exceeded
     */
    public function chunk(string $title, string $content): array
    {
        $config = $this->config();

        $chars = $content === '' ? [] : mb_str_split($content);
        $total = count($chars);

        if ($total === 0) {
            return []; // an empty body has nothing to retrieve; a title alone is not a passage
        }

        $sections = $this->mergeSmallSections($this->sections($title, $chars, $total), $config);

        $drafts = [];

        foreach ($sections as $section) {
            foreach ($this->splitSection($chars, $section, $config) as [$start, $end]) {
                [$start, $end] = $this->trimSpan($chars, $start, $end);

                if ($end <= $start) {
                    continue;
                }

                $path = $this->formatPath($section['path']);
                $text = $this->slice($chars, $start, $end);

                $drafts[] = new ChunkDraft(
                    ordinal: count($drafts),
                    headingPath: $path,
                    content: $text,
                    charStart: $start,
                    charLength: $end - $start,
                    digest: hash('sha256', $path . "\0" . $text),
                );
            }
        }

        if (count($drafts) > $config['max_chunks_per_entry']) {
            throw new KnowledgeChunkOverflowException(count($drafts), $config['max_chunks_per_entry']);
        }

        return $drafts;
    }

    /**
     * How many passages this text WOULD produce, without keeping them.
     *
     * Used by the COMPOSER — {@see \App\Modules\Knowledge\Services\KnowledgeDraftService} — to drop a
     * proposal whose text could never be indexed, and to tell the reviewer why BEFORE they are asked
     * to accept it.
     *
     * It used to serve the entry FormRequest, answering 422 to the person typing. That request went
     * with hand-authorship, and for a while this method had no caller at all: an over-cap entry was
     * written and its indexing then failed in the background hours later — the invisible failure this
     * guard exists to prevent. Same question, asked one layer earlier, of the machine that now writes.
     */
    public function countFor(string $title, string $content): int
    {
        try {
            return count($this->chunk($title, $content));
        } catch (KnowledgeChunkOverflowException $e) {
            // The dry run must REPORT an overflow, not raise it — the caller's whole job is to turn
            // this number into a validation message.
            return $e->count;
        }
    }

    // ---- configuration --------------------------------------------------------

    /**
     * Read at call time (never cached on the instance) so a test — and the version bump that makes
     * every entry stale — takes effect without re-resolving the service.
     *
     * @return array{version: int, target_chars: int, max_chars: int, min_chars: int, overlap_chars: int, max_chunks_per_entry: int}
     */
    private function config(): array
    {
        $max = max(1, (int) config('knowledge.chunking.max_chars'));

        return [
            'version' => (int) config('knowledge.chunking.version'),
            // The target can never exceed the ceiling, and the overlap can never reach the ceiling —
            // a step of zero in the hard cut would not terminate. Clamped here rather than trusted,
            // because these are operator-editable numbers.
            'target_chars' => min($max, max(1, (int) config('knowledge.chunking.target_chars'))),
            'max_chars' => $max,
            'min_chars' => max(0, (int) config('knowledge.chunking.min_chars')),
            'overlap_chars' => min(intdiv($max, 2), max(0, (int) config('knowledge.chunking.overlap_chars'))),
            'max_chunks_per_entry' => max(1, (int) config('knowledge.chunking.max_chunks_per_entry')),
        ];
    }

    // ---- 1. structural split by headings --------------------------------------

    /**
     * The document's sections, each with the heading trail it sits under.
     *
     * A section's span covers the text BETWEEN two heading lines, with the heading itself excluded —
     * it lives in the path. Sections that are empty (a heading with nothing under it, or leading
     * whitespace) are dropped: there is no text to embed, and the heading survives anyway as an
     * ancestor in its children's paths.
     *
     * @param  array<int, string>  $chars
     * @return array<int, array{path: array<int, string>, start: int, end: int}>
     */
    private function sections(string $title, array $chars, int $total): array
    {
        $sections = [];
        $stack = [];       // heading level (1..6) => heading text
        $bodyStart = 0;
        $lineStart = 0;

        for ($i = 0; $i <= $total; $i++) {
            if ($i !== $total && $chars[$i] !== "\n") {
                continue;
            }

            $line = rtrim($this->slice($chars, $lineStart, $i), "\r");

            if (preg_match(self::HEADING, $line, $matches) === 1) {
                $sections[] = ['path' => $this->pathFor($title, $stack), 'start' => $bodyStart, 'end' => $lineStart];

                $level = mb_strlen($matches[1]);

                // A heading closes every deeper level: "## B" after "### A" makes A no longer an
                // ancestor of anything that follows.
                for ($deeper = $level; $deeper <= 6; $deeper++) {
                    unset($stack[$deeper]);
                }

                $stack[$level] = trim($matches[2]);
                ksort($stack);

                $bodyStart = min($i + 1, $total);
            }

            $lineStart = $i + 1;
        }

        $sections[] = ['path' => $this->pathFor($title, $stack), 'start' => $bodyStart, 'end' => $total];

        $trimmed = [];

        foreach ($sections as $section) {
            [$start, $end] = $this->trimSpan($chars, $section['start'], $section['end']);

            if ($end > $start) {
                $trimmed[] = ['path' => $section['path'], 'start' => $start, 'end' => $end];
            }
        }

        return $trimmed;
    }

    /**
     * The heading trail for the current stack, rooted at the entry title. The title is the root of
     * every path because a passage retrieved out of context must still say what document it belongs
     * to — a chunk labelled only "Rabaty" is unattributable.
     *
     * @param  array<int, string>  $stack
     * @return array<int, string>
     */
    private function pathFor(string $title, array $stack): array
    {
        return array_values(array_merge([trim($title)], array_values($stack)));
    }

    // ---- 2. merge undersized sections -----------------------------------------

    /**
     * Fuse adjacent sections when either is below `min_chars` and the result still fits the ceiling.
     *
     * A single left-to-right pass, which is what makes it deterministic and terminating: each section
     * is considered exactly once against the accumulator, so a run of tiny sections collapses into one
     * passage without any ordering ambiguity. The ceiling guard is what stops a stub heading from
     * swallowing a full-size section it happens to precede.
     *
     * The fused span runs from the first section's start to the last one's end, so it INCLUDES the
     * heading lines in between — the sub-headings stay visible inline, which is the right outcome for
     * a passage whose path had to be generalized to their common ancestor.
     *
     * @param  array<int, array{path: array<int, string>, start: int, end: int}>  $sections
     * @param  array{min_chars: int, max_chars: int, ...}  $config
     * @return array<int, array{path: array<int, string>, start: int, end: int}>
     */
    private function mergeSmallSections(array $sections, array $config): array
    {
        $merged = [];

        foreach ($sections as $section) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];

            if ($last !== null) {
                $lastLength = $last['end'] - $last['start'];
                $length = $section['end'] - $section['start'];
                $combined = $section['end'] - $last['start'];

                $eitherIsTiny = $lastLength < $config['min_chars'] || $length < $config['min_chars'];

                if ($eitherIsTiny && $combined <= $config['max_chars']) {
                    $merged[count($merged) - 1] = [
                        'path' => $this->commonPath($last['path'], $section['path']),
                        'start' => $last['start'],
                        'end' => $section['end'],
                    ];

                    continue;
                }
            }

            $merged[] = $section;
        }

        return $merged;
    }

    /**
     * The longest common ancestor trail of two paths. Never empty in practice — both paths are rooted
     * at the same entry title.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     * @return array<int, string>
     */
    private function commonPath(array $a, array $b): array
    {
        $common = [];

        foreach ($a as $index => $segment) {
            if (($b[$index] ?? null) !== $segment) {
                break;
            }

            $common[] = $segment;
        }

        return $common;
    }

    // ---- 3. size the sections -------------------------------------------------

    /**
     * @param  array<int, string>  $chars
     * @param  array{path: array<int, string>, start: int, end: int}  $section
     * @return array<int, array{0: int, 1: int}>
     */
    private function splitSection(array $chars, array $section, array $config): array
    {
        if ($config['max_chars'] >= $section['end'] - $section['start']) {
            return [[$section['start'], $section['end']]];
        }

        return $this->pack(
            $this->paragraphSpans($chars, $section['start'], $section['end']),
            $config,
            fn (int $start, int $end): array => $this->splitParagraph($chars, $start, $end, $config),
        );
    }

    /**
     * @param  array<int, string>  $chars
     * @return array<int, array{0: int, 1: int}>
     */
    private function splitParagraph(array $chars, int $start, int $end, array $config): array
    {
        return $this->pack(
            $this->sentenceSpans($chars, $start, $end),
            $config,
            fn (int $hardStart, int $hardEnd): array => $this->hardCut($hardStart, $hardEnd, $config),
        );
    }

    /**
     * Greedily group consecutive units into passages.
     *
     * The rule reads directly off the three size knobs: keep adding while the result stays within
     * `target_chars`; allow the overshoot up to `max_chars` only while the passage is still below
     * `min_chars`, because a too-small passage is a worse outcome than a slightly-too-large one. A
     * unit that is itself over the ceiling cannot be packed at all and is handed to $oversize, the
     * next rung down the cascade.
     *
     * Grouped units keep their SPAN, so a passage covers the whitespace between the units it fused —
     * which is what preserves the "a draft is a contiguous slice of the original" invariant.
     *
     * @param  array<int, array{0: int, 1: int}>  $units
     * @param  callable(int, int): array<int, array{0: int, 1: int}>  $oversize
     * @return array<int, array{0: int, 1: int}>
     */
    private function pack(array $units, array $config, callable $oversize): array
    {
        $pieces = [];
        $start = null;
        $end = null;

        foreach ($units as [$unitStart, $unitEnd]) {
            if ($unitEnd - $unitStart > $config['max_chars']) {
                if ($start !== null) {
                    $pieces[] = [$start, $end];
                    $start = null;
                }

                foreach ($oversize($unitStart, $unitEnd) as $piece) {
                    $pieces[] = $piece;
                }

                continue;
            }

            if ($start === null) {
                $start = $unitStart;
                $end = $unitEnd;

                continue;
            }

            $grown = $unitEnd - $start;
            $current = $end - $start;

            if ($grown <= $config['target_chars'] || ($current < $config['min_chars'] && $grown <= $config['max_chars'])) {
                $end = $unitEnd;

                continue;
            }

            $pieces[] = [$start, $end];
            $start = $unitStart;
            $end = $unitEnd;
        }

        if ($start !== null) {
            $pieces[] = [$start, $end];
        }

        return $pieces;
    }

    /**
     * The last resort: fixed-width windows with an overlap tail.
     *
     * This is the ONLY place `overlap_chars` is applied. A hard cut lands in the middle of a sentence
     * by definition, so whatever straddles the boundary would otherwise be unretrievable from both
     * sides; repeating the previous window's tail makes it retrievable from the second. The windows
     * therefore OVERLAP in the source — which is exactly why chunk spans are addresses, not a
     * partition.
     *
     * The final window is pulled BACK to full width when the remainder would be below `min_chars`,
     * rather than emitted as a stub: a 12-character trailing chunk costs a full embedding call and
     * retrieves nothing.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function hardCut(int $start, int $end, array $config): array
    {
        $pieces = [];
        $cursor = $start;

        while (true) {
            $stop = min($end, $cursor + $config['max_chars']);

            if ($stop >= $end && $pieces !== [] && $end - $cursor < $config['min_chars']) {
                $cursor = max($start, $end - $config['max_chars']);
            }

            $pieces[] = [$cursor, $stop];

            if ($stop >= $end) {
                return $pieces;
            }

            // The step is max_chars - overlap_chars, which config() guarantees is positive.
            $cursor = $stop - $config['overlap_chars'];
        }
    }

    // ---- unit finders ---------------------------------------------------------

    /**
     * Paragraph spans inside [$start, $end): runs of non-blank lines, with blank lines dropped from
     * the spans (they reappear inside a packed passage as the gap between two units).
     *
     * @param  array<int, string>  $chars
     * @return array<int, array{0: int, 1: int}>
     */
    private function paragraphSpans(array $chars, int $start, int $end): array
    {
        $spans = [];
        $paragraphStart = null;
        $paragraphEnd = null;

        foreach ($this->lineSpans($chars, $start, $end) as [$lineStart, $lineEnd]) {
            if ($this->isBlank($chars, $lineStart, $lineEnd)) {
                if ($paragraphStart !== null) {
                    $spans[] = [$paragraphStart, $paragraphEnd];
                    $paragraphStart = null;
                }

                continue;
            }

            $paragraphStart ??= $lineStart;
            $paragraphEnd = $lineEnd;
        }

        if ($paragraphStart !== null) {
            $spans[] = [$paragraphStart, $paragraphEnd];
        }

        // A section with no blank line at all is one paragraph — hand the whole span down the cascade.
        return $spans === [] ? [[$start, $end]] : $spans;
    }

    /**
     * Sentence spans inside [$start, $end).
     *
     * A boundary is a sentence terminator (`.`, `!`, `?`, `…`, optionally followed by a closing quote
     * or bracket) followed by horizontal whitespace, OR a line end — a paragraph with no blank lines
     * can still be a list, and its items are the natural units. A period that closes an abbreviation
     * or a numbered list marker is NOT a boundary; see {@see endsSentence()}.
     *
     * @param  array<int, string>  $chars
     * @return array<int, array{0: int, 1: int}>
     */
    private function sentenceSpans(array $chars, int $start, int $end): array
    {
        $text = $this->slice($chars, $start, $end);

        if (preg_match_all('/[.!?…]["»”\'\)\]]*[ \t]+|\R/u', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [[$start, $end]];
        }

        $cuts = [];

        foreach ($matches[0] as [$match, $byteOffset]) {
            if (!$this->endsSentence($text, (int) $byteOffset)) {
                continue;
            }

            // preg reports BYTES; the spans are in CHARACTERS. Converting per boundary is O(n) each,
            // but this path only runs for a paragraph already over the ceiling, so there are few.
            $cuts[] = mb_strlen(substr($text, 0, (int) $byteOffset)) + mb_strlen($match);
        }

        $spans = [];
        $cursor = 0;

        foreach ($cuts as $cut) {
            if ($cut <= $cursor) {
                continue;
            }

            $spans[] = [$start + $cursor, $start + $cut];
            $cursor = $cut;
        }

        if ($cursor < $end - $start) {
            $spans[] = [$start + $cursor, $end];
        }

        return $spans === [] ? [[$start, $end]] : $spans;
    }

    /**
     * Whether the terminator at $byteOffset really ends a sentence.
     *
     * Only a PERIOD is ambiguous — `!`, `?`, `…` and a line end always close a unit. For a period, the
     * preceding token decides: a known abbreviation ("m.in.", "np.") or a bare numeral (a "1." list
     * marker) is not an ending. Getting this wrong is not cosmetic — it shatters a paragraph of
     * examples into fragments, each of which is a separate embedding call for a passage too small to
     * retrieve.
     */
    private function endsSentence(string $text, int $byteOffset): bool
    {
        if (substr($text, $byteOffset, 1) !== '.') {
            return true;
        }

        if (preg_match('/(\S+)$/u', substr($text, 0, $byteOffset + 1), $matches) !== 1) {
            return true;
        }

        $token = mb_strtolower($matches[1]);

        if (in_array($token, self::ABBREVIATIONS, true)) {
            return false;
        }

        // "1." / "12." — an ordered-list marker or a numbered clause, never a sentence end.
        return preg_match('/^\d+\.$/u', $token) !== 1;
    }

    // ---- primitives -----------------------------------------------------------

    /**
     * Line spans inside [$start, $end), each EXCLUDING its newline.
     *
     * @param  array<int, string>  $chars
     * @return array<int, array{0: int, 1: int}>
     */
    private function lineSpans(array $chars, int $start, int $end): array
    {
        $spans = [];
        $lineStart = $start;

        for ($i = $start; $i <= $end; $i++) {
            if ($i !== $end && $chars[$i] !== "\n") {
                continue;
            }

            $spans[] = [$lineStart, $i];
            $lineStart = $i + 1;
        }

        return $spans;
    }

    /** @param array<int, string> $chars */
    private function isBlank(array $chars, int $start, int $end): bool
    {
        for ($i = $start; $i < $end; $i++) {
            if (trim($chars[$i]) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Shrink a span past leading and trailing whitespace. Applied to every emitted draft so a passage
     * never begins with the blank line that separated it from its predecessor — and applied to the
     * SPAN, not the text, so the offsets keep pointing at the real source characters.
     *
     * @param  array<int, string>  $chars
     * @return array{0: int, 1: int}
     */
    private function trimSpan(array $chars, int $start, int $end): array
    {
        while ($start < $end && trim($chars[$start]) === '') {
            $start++;
        }

        while ($end > $start && trim($chars[$end - 1]) === '') {
            $end--;
        }

        return [$start, $end];
    }

    /** @param array<int, string> $chars */
    private function slice(array $chars, int $start, int $end): string
    {
        return $end <= $start ? '' : implode('', array_slice($chars, $start, $end - $start));
    }

    /**
     * Render a heading trail. Truncated to the storage width HERE, before the digest is taken, so a
     * path that had to be cut still hashes consistently across runs.
     *
     * @param  array<int, string>  $path
     */
    private function formatPath(array $path): string
    {
        $segments = array_values(array_filter($path, static fn (string $segment): bool => $segment !== ''));

        return mb_substr(implode(self::PATH_SEPARATOR, $segments), 0, self::PATH_MAX);
    }
}
