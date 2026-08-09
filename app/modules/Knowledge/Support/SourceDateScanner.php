<?php

namespace App\Modules\Knowledge\Support;

use Illuminate\Support\Str;

/**
 * THE DATES A MATERIAL CONTAINS, and the dates a set of drafts wrote down — so the server can compare
 * them instead of asking the model whether it behaved.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY THIS EXISTS
 *
 * The composer is instructed to correct a date the context makes impossible AND to declare the
 * correction in `unresolved`. On the owner's material it did the first and skipped the second: sushi
 * dated 13 July in the source became 13 September in the entry — the right answer — with nothing
 * anywhere saying a date had moved. A reviewer comparing the entry against the source sees a date that
 * is simply different.
 *
 * Three rounds of asking more firmly produced less each time. The reason is structural: EVERY
 * reporting channel in this pipeline is filled in by the same model whose work is being reported on,
 * so a model that will not admit a correction will also not admit failing to report it. A check that
 * needs the cooperation of the thing being checked is a different class of thing from one that does
 * not.
 *
 * This needs none. It reads the source text, reads the drafts, and compares two sets of dates.
 *
 * ------------------------------------------------------------------------------------------------
 * (DAY, MONTH) ONLY — AND WHAT THAT COSTS
 *
 * Polish prose writes "13 lipca" with no year; the composed entry writes `2026-09-13`. There is no
 * year to compare, so the key is the day and the month.
 *
 * The cost is real and worth stating: TWO DIFFERENT YEARS SHARING A DAY ARE INDISTINGUISHABLE. A
 * chronicle spanning several years, where "13 lipca 2024" and "13 lipca 2026" both appear, will see
 * the second as already covered by the first. For a single-episode material this cannot happen; for a
 * long-running base it is a real blind spot, and the honest fix then is to compare full dates and give
 * up on year-less prose entirely.
 */
final class SourceDateScanner
{
    /** `- 2026-09-13: …` — the dated line the composer is told to write under "Kalendarium". */
    private const ENTRY_LINE = '/^\s*[-*]\s*(\d{4})-(\d{2})-(\d{2})\s*:/mu';

    /**
     * A dated line that is NOT a date: `- 2023-08-??:`, `- 2023-08-XX:`, `- 2023-??-??:`.
     *
     * A measured failure, and the reason this pattern exists. The composer wrote
     * `- 2023-08-??: Incydent z alkoholem w Tajlandii.` — a real fact whose day it did not know — and
     * the placeholder made the line invisible to every control in the module: not an ISO date, so the
     * entry scanner skipped it, so nothing compared it against anything, so nobody was told the base
     * had acquired a line that no question about time will ever match.
     *
     * Matched only where a date was clearly ATTEMPTED (four digits and a dash), so an ordinary undated
     * bullet — "- Łukasz wygrał konkurs." — is not dragged in. A bullet with no date is not a broken
     * date; it is a sentence.
     */
    private const ENTRY_LINE_INCOMPLETE = '/^\s*[-*]\s*(\d{4})-([\d?xX]{2})-([\d?xX]{2})\s*:/mu';

    /**
     * How a MISSING NUMBER is spelled once a date has been attempted — `??`, `XX`, `xx`.
     *
     * Originally the literal `?` alone, which is the spelling the composer happened to use on the run
     * that produced this control (ADR-0050 recorded the narrowness as a known limitation, since a
     * placeholder written any other way passed through exactly as invisibly as the `?` form did before
     * the control existed). `X` is the other spelling a writer reaches for, so both are read.
     *
     * `-` is deliberately NOT a placeholder here: inside an ISO date the dash is the SEPARATOR, and
     * accepting it would make `2026-08---` and `2026-08-15` differ by parse luck rather than by
     * meaning. A word ("nieznany", "unknown") is not read either — that is prose, and prose in the
     * day slot no longer looks like an attempted date at all, which is the anchor this whole family
     * of patterns rests on.
     */
    private const PLACEHOLDER = '/[?xX]/u';

    /**
     * A dated line that STOPS AT THE MONTH — `- 2026-08: …`, `- 2026-XX: …`.
     *
     * The same invisibility with a different shape: nothing was written WRONG, something was simply
     * left off, and the result is again a chronicle line that {@see inEntry()} does not see and no
     * control compares.
     *
     * The month is range-checked (`01`–`12`) where the placeholder form is not, and that is the whole
     * defence against a false alarm: a bullet like `- 2026-27: sezon` is a span of years in ordinary
     * prose, not a truncated date, and `27` is not a month. A BARE YEAR (`- 2026: …`) is left alone
     * for the same reason one step further — a four-digit number before a colon is as likely to be a
     * count or a label as a date, and this control's worth is measured in how much it is trusted.
     */
    private const ENTRY_LINE_MONTH_ONLY = '/^\s*[-*]\s*((?:19|20)\d{2}-(?:0[1-9]|1[0-2]|[?xX]{2}))\s*:/mu';

    /**
     * A month NAMED beside a year, with no day — `- sierpień 2026: …`.
     *
     * The prose spelling of the same omission, and the form a model writing Polish falls back on when
     * it knows the month and not the day. The word is CHECKED against the module's month table rather
     * than accepted for being a word: `- Tajlandia 2026: wyjazd` is a heading somebody wrote, not a
     * broken date, and matching it would put this control's false-alarm rate above its usefulness.
     */
    private const ENTRY_LINE_MONTH_WORD = '/^\s*[-*]\s*((\p{L}+)\s+(?:19|20)\d{2})\s*:/mu';

    /** A bare ISO date anywhere in the source, so a material that already writes them is read too. */
    private const SOURCE_ISO = '/\b(\d{4})-(\d{2})-(\d{2})\b/u';

    /** Any four-digit year, which is how "the material dates nothing" gets decided. */
    private const SOURCE_YEAR = '/\b(?:19|20)\d{2}\b/u';

    /** "13 lipca", "1 marca" — a day followed by a Polish month name in either of its listed forms. */
    private const SOURCE_POLISH = '/\b(\d{1,2})\s+([\p{L}]+)/u';

    /**
     * A DATE RANGE sharing one month name: "od 12 do 15 lipca", "13-15 lipca", "między 12 a 15 lipca".
     *
     * Scanned separately because the single-day pattern requires the day to sit next to the month, so
     * the FIRST day of a range — the one with "do", "a" or a dash between it and the month — was
     * invisible. That cost a false `date_not_in_source` for 12 July on the owner's own material: the
     * entry had recorded the date correctly and the scanner reported the source never mentioned it.
     */
    private const SOURCE_POLISH_RANGE = '/\b(\d{1,2})\s*(?:[-–—]|do|a)\s*(\d{1,2})\s+([\p{L}]+)/u';

    /**
     * Every (day, month) the MATERIAL names, as `d-m` keys.
     *
     * @return array<int, string>
     */
    public static function inSource(string $text): array
    {
        $keys = [];

        if (preg_match_all(self::SOURCE_ISO, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $keys[] = self::key((int) $match[3], (int) $match[2]);
            }
        }

        // RANGES FIRST. Both ends share the trailing month name, and the second end is also matched by
        // the single-day pattern below — which is harmless, since the keys are deduped at the end.
        if (preg_match_all(self::SOURCE_POLISH_RANGE, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $month = self::monthOf($match[3]);

                if ($month === null) {
                    continue;
                }

                foreach ([(int) $match[1], (int) $match[2]] as $day) {
                    if ($day >= 1 && $day <= 31) {
                        $keys[] = self::key($day, $month);
                    }
                }
            }
        }

        if (preg_match_all(self::SOURCE_POLISH, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $day = (int) $match[1];
                $month = self::monthOf($match[2]);

                if ($month !== null && $day >= 1 && $day <= 31) {
                    $keys[] = self::key($day, $month);
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The month number a word names, or null.
     *
     * Normalized through the same transliteration the mention scanner's table is written in, so
     * "września" and "wrzesnia" are one word here as they are there.
     */
    private static function monthOf(string $word): ?int
    {
        return MentionScanner::monthNumber(Str::slug($word, ''));
    }

    /** Whether the material states a year anywhere. When it does, nothing here supplies one. */
    public static function hasYear(string $text): bool
    {
        return preg_match(self::SOURCE_YEAR, $text) === 1;
    }

    /**
     * Dated lines whose date is not one: a PLACEHOLDER where a number belongs (`2023-08-??`,
     * `2023-08-XX`), or a date that simply STOPS EARLY (`2026-08`, `sierpień 2026`).
     *
     * Three shapes, one defect — a chronicle line that {@see inEntry()} cannot read, so no control
     * compares it and the base quietly gains a fact no question about time will return.
     *
     * @return array<int, string> the malformed dates, as written
     */
    public static function incompleteInEntry(string $content): array
    {
        $found = [];

        if (preg_match_all(self::ENTRY_LINE_INCOMPLETE, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $written = $match[1] . '-' . $match[2] . '-' . $match[3];

                // A complete date is handled by inEntry(); only the placeholders are this loop's business.
                if (preg_match(self::PLACEHOLDER, $written) === 1) {
                    $found[] = $written;
                }
            }
        }

        if (preg_match_all(self::ENTRY_LINE_MONTH_ONLY, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $found[] = $match[1];
            }
        }

        if (preg_match_all(self::ENTRY_LINE_MONTH_WORD, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // A word beside a year is only a date when it NAMES A MONTH; anything else is prose.
                if (self::monthOf($match[2]) !== null) {
                    $found[] = $match[1];
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Every dated line in an entry's body: the ISO date it wrote, and its (day, month) key.
     *
     * @return array<int, array{iso: string, key: string}>
     */
    public static function inEntry(string $content): array
    {
        if (!preg_match_all(self::ENTRY_LINE, $content, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $dates = [];
        $seen = [];

        foreach ($matches as $match) {
            $iso = $match[1] . '-' . $match[2] . '-' . $match[3];

            if (isset($seen[$iso])) {
                continue;
            }

            $seen[$iso] = true;
            $dates[] = ['iso' => $iso, 'key' => self::key((int) $match[3], (int) $match[2])];
        }

        return $dates;
    }

    private static function key(int $day, int $month): string
    {
        return $day . '-' . $month;
    }
}
