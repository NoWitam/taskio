<?php

namespace App\Modules\Knowledge\Support;

/**
 * The readable FRAGMENT a search result shows, expressed as OFFSETS into the entry's own content.
 *
 * Offsets, never markup. The alternative — returning `…the <mark>cennik</mark> applies…` — would make
 * this class part of the XSS surface of every consumer that renders a result: the entry body is
 * user-authored, so any HTML the server injects into it has to be sanitized downstream by code that
 * now cannot tell the server's markup from the author's. Handing back `[start, length]` pairs keeps
 * the text a string and leaves highlighting to whoever is drawing it. It is also the only form that
 * survives a client which is not HTML at all (a bot assembling a prompt, a CSV export).
 *
 * Every offset is CHARACTER-based and absolute against the ENTRY's content, so:
 *
 *   mb_substr($entry->content, $snippet->charStart, $snippet->charLength) === $snippet->text
 *
 * holds exactly, and each highlight is a sub-range of that window. Characters rather than bytes
 * because this is a Polish-language product and a byte offset lands inside a multi-byte letter; the
 * chunker's spans are character-based for the same reason, which is what lets a chunk span be sliced
 * further here without any conversion.
 *
 * The window is centred on the first place a query term actually occurs, and falls back to the head of
 * the passage when the query matches nothing literally — which is the NORMAL case for a semantic hit,
 * where the passage was found for what it means rather than for the words it uses.
 */
final class KnowledgeSnippet
{
    /** Roughly two lines of prose: enough to judge a hit, far from enough to read the entry here. */
    public const DEFAULT_CHARS = 240;

    /** Most highlight ranges one snippet reports. A snippet lit up end to end highlights nothing. */
    private const MAX_HIGHLIGHTS = 20;

    /** Shortest word that is worth highlighting on its own. */
    private const MIN_TERM_CHARS = 3;

    /** How far the window may be nudged to avoid cutting a word in half. */
    private const BOUNDARY_SLACK = 32;

    /**
     * @param  array<int, array{0: int, 1: int}>  $highlights  absolute [start, length] pairs
     */
    private function __construct(
        public readonly string $text,
        public readonly int $charStart,
        public readonly int $charLength,
        public readonly array $highlights,
        public readonly bool $truncatedBefore,
        public readonly bool $truncatedAfter,
    ) {}

    /**
     * Cut a snippet out of the span `[$spanStart, $spanStart + $spanLength)` of `$content`.
     *
     * The span is the PASSAGE a match belongs to (a chunk's own range), so the snippet can never
     * wander into a neighbouring section — a result that quoted text the match did not come from
     * would be a citation that does not hold up.
     */
    public static function locate(
        string $content,
        int $spanStart,
        int $spanLength,
        ?string $query,
        int $maxChars = self::DEFAULT_CHARS,
    ): self {
        $total = mb_strlen($content);

        $spanStart = max(0, min($spanStart, $total));
        $spanEnd = max($spanStart, min($spanStart + $spanLength, $total));
        $span = mb_substr($content, $spanStart, $spanEnd - $spanStart);
        $spanChars = mb_strlen($span);

        $terms = self::terms($query);
        $anchor = self::firstOccurrence($span, $terms);

        // Show a little of what came BEFORE the match: a fragment that starts exactly on the query
        // term reads like an answer with its question cut off.
        $start = $anchor === null ? 0 : max(0, $anchor - (int) floor($maxChars / 3));
        $start = min($start, max(0, $spanChars - $maxChars));
        $end = min($spanChars, $start + $maxChars);

        [$start, $end] = self::snapToWords($span, $start, $end, $spanChars);

        $text = mb_substr($span, $start, $end - $start);

        return new self(
            text: $text,
            charStart: $spanStart + $start,
            charLength: mb_strlen($text),
            highlights: self::highlights($text, $terms, $spanStart + $start),
            truncatedBefore: $start > 0,
            truncatedAfter: $end < $spanChars,
        );
    }

    /**
     * What counts as a term: the WHOLE query first (a multi-word phrase that occurs verbatim is the
     * strongest possible signal and should be lit as one range), then its individual words. Very short
     * words are dropped — highlighting every "i" and "w" in a Polish sentence is visual noise that
     * makes the real match harder to find, not easier.
     *
     * @return array<int, string>
     */
    private static function terms(?string $query): array
    {
        $query = trim((string) $query);

        if ($query === '') {
            return [];
        }

        $terms = mb_strlen($query) >= 2 ? [$query] : [];

        foreach (preg_split('/\s+/u', $query) ?: [] as $word) {
            if (mb_strlen($word) >= self::MIN_TERM_CHARS && !in_array($word, $terms, true)) {
                $terms[] = $word;
            }
        }

        return $terms;
    }

    /** @param  array<int, string>  $terms */
    private static function firstOccurrence(string $haystack, array $terms): ?int
    {
        $first = null;

        foreach ($terms as $term) {
            $position = mb_stripos($haystack, $term);

            if ($position !== false && ($first === null || $position < $first)) {
                $first = $position;
            }
        }

        return $first;
    }

    /**
     * Nudge both edges onto whitespace so the snippet does not begin or end mid-word. Bounded by
     * {@see BOUNDARY_SLACK}: in text with no spaces at all (a pasted table, minified data) the window
     * stays where it was rather than collapsing to nothing.
     *
     * @return array{0: int, 1: int}
     */
    private static function snapToWords(string $span, int $start, int $end, int $spanChars): array
    {
        if ($start > 0) {
            for ($offset = 0; $offset < self::BOUNDARY_SLACK && $start + $offset < $end; $offset++) {
                if (preg_match('/\s/u', mb_substr($span, $start + $offset, 1)) === 1) {
                    $start = $start + $offset + 1;
                    break;
                }
            }
        }

        if ($end < $spanChars) {
            for ($offset = 0; $offset < self::BOUNDARY_SLACK && $end - $offset > $start; $offset++) {
                if (preg_match('/\s/u', mb_substr($span, $end - $offset - 1, 1)) === 1) {
                    $end = $end - $offset;
                    break;
                }
            }
        }

        return [$start, $end];
    }

    /**
     * Every occurrence of every term inside the snippet, as ABSOLUTE ranges, merged where they
     * overlap (the whole query and one of its words will normally cover the same characters) and
     * sorted — a consumer applying them in order must never have to reconcile two ranges that claim
     * the same character.
     *
     * @param  array<int, string>  $terms
     * @return array<int, array{0: int, 1: int}>
     */
    private static function highlights(string $text, array $terms, int $offset): array
    {
        $ranges = [];

        foreach ($terms as $term) {
            $length = mb_strlen($term);
            $from = 0;

            while (($position = mb_stripos($text, $term, $from)) !== false) {
                $ranges[] = [$position, $length];
                $from = $position + max(1, $length);

                if (count($ranges) >= self::MAX_HIGHLIGHTS * 4) {
                    break 2; // pathological input; the merge below still trims to the real cap
                }
            }
        }

        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);

        $merged = [];

        foreach ($ranges as [$start, $length]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][0] + $merged[$last][1]) {
                $end = max($merged[$last][0] + $merged[$last][1], $start + $length);
                $merged[$last][1] = $end - $merged[$last][0];

                continue;
            }

            $merged[] = [$start, $length];
        }

        return array_map(
            static fn (array $range): array => [$offset + $range[0], $range[1]],
            array_slice($merged, 0, self::MAX_HIGHLIGHTS),
        );
    }
}
