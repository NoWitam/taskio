<?php

namespace App\Modules\Knowledge\Support;

/**
 * Finds where a knowledge entry's TEXT names another entry's TITLE, without the writer having to mark
 * it up.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY THIS EXISTS
 *
 * `[[wikilinks]]` only connect a base if somebody remembers to type them, and in practice nobody does:
 * a person writing about the Eiffel Tower writes "wieży Eiffla", not "[[wieza-eiffla]]". A graph that
 * depends on that discipline stays empty and then gets blamed for being useless. So the connection has
 * to be found in prose the way a reader finds it — by recognising the name.
 *
 * ------------------------------------------------------------------------------------------------
 * THE HEURISTIC, stated exactly
 *
 * Both sides are word-tokenized and each token is normalized through the SAME transliteration the
 * module's slugs use ({@see WikilinkParser::normalize}), so "Wieży" and "wiezy" are one token and a
 * base written with Polish diacritics behaves like one written without them.
 *
 * A title matches when ALL of its words appear in the content CONSECUTIVELY — an adjacent window, in
 * title order. Requiring adjacency is what stops "Wieża" and "Eiffla", sitting in different sentences,
 * from being read as a mention of "Wieża Eiffla".
 *
 * DATE TOKENS ARE STRIPPED FROM THE TITLE FIRST ({@see titlePattern()}) — bare numbers and Polish
 * month names. Real titles are dated ("Zwiedzanie wieży Eiffla -13 lipiec 2026") and prose never
 * repeats a date stamp, so under an all-words rule one stray "2026" made such a title permanently
 * unmatchable. What survives must still be a name: a title reducing to nothing or to one short word is
 * not scanned for at all.
 *
 * A content word matches a title word by STEM, which is the concession Polish inflection demands
 * ("wieży" is the same word as "wieża"; a base full of nominative titles would otherwise match almost
 * nothing):
 *
 *   - a title word of 4+ characters yields a stem of its first `max(4, len - 3)` characters, and a
 *     content word matches when it STARTS WITH that stem;
 *   - a title word shorter than 4 characters must match EXACTLY. Below four characters a prefix is not
 *     a stem, it is a coincidence — "rok" would otherwise match "rokowania".
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT IT WILL GET WRONG, and why that is the right trade
 *
 * Stemming by truncation over-matches: a title "Paryż" (stem `pary`) also matches "parytet", and
 * "Bank" matches "bankructwo". It has no idea what a word means, so a title that is an ordinary noun
 * will be found wherever that noun is used — a base with an entry called "Cennik" will see mentions in
 * every note that says "cennik".
 *
 * That is acceptable HERE and would not be elsewhere, because of what a mention edge IS: a
 * machine-proposed, dismissible suggestion, drawn alongside the authored ones and distinguishable from
 * them by `source`. A false mention costs a reader one dismissal; a missed one costs an empty graph,
 * which is the failure that was actually observed. The rule is deliberately dumb, deterministic and
 * free — no AI call, no configuration, no per-language dictionary — so it can run on every index pass
 * of every entry forever.
 *
 * A real stemmer (or a language-aware analyzer) is the upgrade path if the noise ever justifies it.
 * Nothing about the stored edge changes if that happens: only this class does.
 */
class MentionScanner
{
    /** Shortest stem a prefix match may use. Below this a prefix is a coincidence, not a root. */
    public const MIN_STEM = 4;

    /** How many trailing characters an inflected ending may add. */
    public const MAX_INFLECTION = 3;

    /**
     * Polish month names, nominative AND genitive, in the normalized (transliterated, lowercase) form
     * the tokenizer produces. Genitive is listed because that is the form a date actually takes in
     * prose and in titles ("13 lipca", "lipiec 2026").
     */
    private const MONTHS = [
        'styczen', 'stycznia', 'luty', 'lutego', 'marzec', 'marca',
        'kwiecien', 'kwietnia', 'maj', 'maja', 'czerwiec', 'czerwca',
        'lipiec', 'lipca', 'sierpien', 'sierpnia', 'wrzesien', 'wrzesnia',
        'pazdziernik', 'pazdziernika', 'listopad', 'listopada', 'grudzien', 'grudnia',
    ];

    /**
     * The month NUMBER a normalized Polish month word names, or null.
     *
     * Published so {@see SourceDateScanner} can read dates out of prose without keeping a second copy
     * of this table. The entries above are nominative-then-genitive in calendar order, so the number
     * falls out of the position — which is why the table is a flat list rather than a map, and why
     * this reads it instead of restating it.
     */
    public static function monthNumber(string $normalizedWord): ?int
    {
        $index = array_search($normalizedWord, self::MONTHS, true);

        return $index === false ? null : intdiv((int) $index, 2) + 1;
    }

    /**
     * A title that survives date-stripping as a SINGLE word must be at least this long to be scanned
     * for. One short word is not a name, it is a common noun — and matching every note that happens
     * to use it would fill the graph with edges nobody would recognise.
     */
    private const MIN_LONE_WORD = 5;

    /**
     * The first mention of each given target inside $content.
     *
     * A target may be named by its title ALONE (a plain string) or by ANY OF SEVERAL names — its title
     * plus its aliases (a list). Aliases are how the same entry is spelled in a real sentence
     * ("wieży Eiffla", "Eiffel Tower"), and matching them is the cheap substitute for the Polish
     * lemmatiser this stack does not have.
     *
     * Each name is compiled into its own pattern with exactly the same mechanics — date-stripping,
     * stems, and the refusal to scan for a title that reduces to nothing or one short word — so an
     * alias cannot smuggle in a looser rule than a title gets.
     *
     * ONE MENTION PER TARGET, whichever name found it and however many did: the EARLIEST occurrence in
     * the content wins. Two names hitting the same entry is one relation, not two, and returning both
     * would double an edge for the accident of having written a good alias list.
     *
     * @param  array<string, string|array<int, string>>  $targets  keyed by whatever the caller wants
     *                                                             back (entry id); value = name or names
     * @return array<string, array{char_start: int, char_length: int}> keyed the same, mentions only
     */
    public function scan(?string $content, array $targets): array
    {
        $content = (string) $content;

        if ($content === '' || $targets === []) {
            return [];
        }

        $tokens = $this->tokenize($content);

        if ($tokens === []) {
            return [];
        }

        // Content tokens grouped by their leading key, so a name is looked up rather than scanned for.
        // Without this the pass is names × tokens; with it, it is ~tokens + matches. That index is what
        // keeps aliases affordable: more names cost more LOOKUPS, not more passes over the text.
        //
        // FUTURE, not now: at a base large enough for the lookups themselves to matter, the standard
        // answer is one Aho-Corasick automaton over every pattern in the base rather than a pattern at
        // a time. Deliberately not built — it is a different data structure to test and maintain, and
        // nothing here is close to needing it.
        $index = [];

        foreach ($tokens as $position => $token) {
            $index[$this->key($token['word'])][] = $position;
        }

        $found = [];

        foreach ($targets as $id => $names) {
            $best = null;

            foreach ((array) $names as $name) {
                $words = $this->titlePattern((string) $name);

                if ($words === null) {
                    continue;
                }

                $mention = $this->locate($tokens, $index, $words);

                if ($mention !== null && ($best === null || $mention['char_start'] < $best['char_start'])) {
                    $best = $mention;
                }
            }

            if ($best !== null) {
                $found[$id] = $best;
            }
        }

        return $found;
    }

    /**
     * The words a title is actually SEARCHED FOR, or null when it has none worth searching.
     *
     * DATES ARE DROPPED. Real titles carry them — "Zwiedzanie wieży Eiffla -13 lipiec 2026" — and
     * prose never repeats them: nobody writes the entry's own date stamp inside another note. Since a
     * match requires ALL the title's words adjacent, one stray "2026" made such a title unmatchable
     * forever, which is how the layer produced nothing on the first real data it met. Dropped tokens
     * are bare numbers (years, days) and Polish month names in either case form. Punctuation and
     * dashes never reach here at all — {@see tokenize()} only emits letter-initial words.
     *
     * WHAT IS LEFT MUST STILL BE A NAME. A title that reduces to nothing ("Lipiec 2026") or to one
     * short word is refused rather than scanned for: "2026" is not a subject, and a lone common noun
     * would match every note that used it. A single word of {@see MIN_LONE_WORD}+ characters is kept —
     * "Eiffla" is a perfectly good thing to look for.
     *
     * Deliberately NOT a subset match. Scanning for "any significant word of the title" would connect
     * "Podróż paryż - lipiec 2026" to every note containing "podróż", which is the noise that makes a
     * graph worthless — the opposite failure from the one being fixed, and harder to see.
     *
     * @return array<int, string>|null
     */
    private function titlePattern(string $title): ?array
    {
        $words = [];

        foreach ($this->tokenize($title) as $token) {
            $word = $token['word'];

            if (ctype_digit($word) || in_array($word, self::MONTHS, true)) {
                continue;
            }

            $words[] = $word;
        }

        if ($words === [] || (count($words) === 1 && mb_strlen($words[0]) < self::MIN_LONE_WORD)) {
            return null;
        }

        return $words;
    }

    /**
     * The first adjacent window of $tokens matching every word of $words, in order.
     *
     * @param  array<int, array{word: string, start: int, length: int}>  $tokens
     * @param  array<string, array<int, int>>  $index
     * @param  array<int, string>  $words
     * @return array{char_start: int, char_length: int}|null
     */
    private function locate(array $tokens, array $index, array $words): ?array
    {
        foreach ($index[$this->key($words[0])] ?? [] as $start) {
            $matched = true;

            foreach ($words as $offset => $word) {
                $token = $tokens[$start + $offset] ?? null;

                if ($token === null || !$this->wordMatches($token['word'], $word)) {
                    $matched = false;

                    break;
                }
            }

            if ($matched) {
                $last = $tokens[$start + count($words) - 1];

                // Offsets are CHARACTER offsets into the entry's content — the same convention chunks
                // use, so `mb_substr($content, $char_start, $char_length)` is the mention itself.
                return [
                    'char_start' => $tokens[$start]['start'],
                    'char_length' => $last['start'] + $last['length'] - $tokens[$start]['start'],
                ];
            }
        }

        return null;
    }

    /** Whether a content word is an inflected form of a title word. See the class docblock. */
    private function wordMatches(string $contentWord, string $titleWord): bool
    {
        $length = mb_strlen($titleWord);

        if ($length < self::MIN_STEM) {
            return $contentWord === $titleWord;
        }

        $stem = mb_substr($titleWord, 0, max(self::MIN_STEM, $length - self::MAX_INFLECTION));

        return str_starts_with($contentWord, $stem);
    }

    /**
     * The bucket a word is indexed and looked up by.
     *
     * Its correctness is what makes the index safe to use: a content word that matches a title word
     * must land in the SAME bucket. For a 4+ character title word the stem's first four characters are
     * the title word's first four, and any content word starting with that stem starts with them too;
     * for a shorter one the match is exact, so the whole word is the bucket.
     */
    private function key(string $word): string
    {
        return mb_strlen($word) >= self::MIN_STEM ? mb_substr($word, 0, self::MIN_STEM) : $word;
    }

    /**
     * Words with their CHARACTER offsets, normalized.
     *
     * `preg_match_all` reports BYTE offsets, and every offset in this module is a character offset (an
     * entry may be full of diacritics, so the two differ). The conversion walks the string once,
     * carrying the last known pair, rather than measuring each match from the start — which would be
     * quadratic on a 40 000-character entry.
     *
     * @return array<int, array{word: string, start: int, length: int}>
     */
    private function tokenize(string $text): array
    {
        if (preg_match_all('/\p{L}[\p{L}\p{N}]*/u', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $tokens = [];
        $byte = 0;
        $char = 0;

        foreach ($matches[0] as [$raw, $offset]) {
            $char += mb_strlen(substr($text, $byte, $offset - $byte));
            $byte = $offset;

            $word = WikilinkParser::normalize($raw);

            if ($word !== '') {
                $tokens[] = ['word' => $word, 'start' => $char, 'length' => mb_strlen($raw)];
            }
        }

        return $tokens;
    }

    /**
     * The longest word of a title, normalized — the cheapest selective handle for finding the entries
     * that might mention it. Used to pre-filter with one SQL `like` before this scanner verifies the
     * hits properly; the longest word is chosen because it is the least likely to appear by accident.
     */
    /**
     * The pre-filter handle for EACH of a target's names — one `like` stem per name, deduped.
     *
     * The reverse pass looks for entries that already mention a given entry, and it narrows the base
     * with an indexed `like` before verifying properly. With aliases there is no single handle that
     * covers every way the entry might be written, so the caller gets one per name and ORs them: a
     * single stem would silently make every alias unreachable from that direction.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    public function longestStems(array $names): array
    {
        $stems = [];

        foreach ($names as $name) {
            $stem = $this->longestStem((string) $name);

            if ($stem !== null && !in_array($stem, $stems, true)) {
                $stems[] = $stem;
            }
        }

        return $stems;
    }

    public function longestStem(string $title): ?string
    {
        // The SAME pattern the scan uses, so the pre-filter and the verification cannot disagree about
        // what the title is. Reading the raw tokens here would have the reverse pass hunting the base
        // for "lipiec" — every dated note in the workspace — and then discarding almost all of them.
        $words = $this->titlePattern($title);

        if ($words === null) {
            return null;
        }

        $stem = null;
        $stemLength = 0;
        $wordLength = 0;

        foreach ($words as $word) {
            $length = mb_strlen($word);

            if ($length < self::MIN_STEM) {
                continue;
            }

            $candidate = mb_substr($word, 0, max(self::MIN_STEM, $length - self::MAX_INFLECTION));
            $candidateLength = mb_strlen($candidate);

            // Longest stem wins; on a tie the longest WORD wins. Most short titles stem to exactly
            // MIN_STEM characters, so without the tie-break "Wieża Eiffla" would pre-filter on `wiez`
            // — a fragment of half the Polish words for a tower — instead of on `eiff`, which is
            // essentially a proper noun. The `like` is only a pre-filter, so this costs nothing but
            // decides how many rows the PHP verification has to read.
            if ($candidateLength > $stemLength || ($candidateLength === $stemLength && $length > $wordLength)) {
                $stem = $candidate;
                $stemLength = $candidateLength;
                $wordLength = $length;
            }
        }

        return $stem;
    }
}
