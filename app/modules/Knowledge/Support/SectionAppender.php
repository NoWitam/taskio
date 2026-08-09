<?php

namespace App\Modules\Knowledge\Support;

/**
 * WHERE AN APPEND GOES, computed from the LIVE text at the moment of the write.
 *
 * ------------------------------------------------------------------------------------------------
 * THIS IS WHY AN APPEND NEVER CONFLICTS
 *
 * A rewrite replaces a document, so it must be judged against the version its author read — that is
 * what the optimistic lock is for, and a rewrite of text somebody else has since changed genuinely IS
 * a conflict a human has to settle.
 *
 * An append is not that. "Add this dated line to the Kalendarium" is a COMMUTATIVE instruction: it
 * means the same thing before and after somebody else's edit, and applying it to the newer text loses
 * nothing from either side. So it is composed here against the text as it stands NOW rather than
 * against the frozen copy the composer saw — which is precisely what stops a reviewer from being told
 * "409, rebase and try again" for an operation that could not have collided with anything.
 *
 * The distinction is worth stating because it is easy to get backwards: the safety of an append comes
 * from being re-derived late, and the safety of a rewrite comes from being checked against something
 * early. Treating them the same way breaks one of the two.
 *
 * ------------------------------------------------------------------------------------------------
 * SECTIONS ARE MARKDOWN HEADINGS, MATCHED LOOSELY AND CREATED WHEN ABSENT
 *
 * A named section is found by its heading text, at any level, ignoring case and surrounding
 * whitespace — a composer that writes "Kalendarium" must not miss "## Kalendarium ". The line is
 * inserted at the END of that section, before the next heading of the same or higher level, because a
 * chronology grows downwards and jamming it under the heading would reverse the order.
 *
 * When the section does not exist it is CREATED at the end of the document rather than the content
 * being dropped or silently merged into whatever happens to be last. A section a reviewer asked for
 * and cannot find is the same failure as content that vanished.
 */
final class SectionAppender
{
    /**
     * The document with $addition placed in $section — or at the end when no section was named.
     *
     * Idempotent in the only sense that matters here: appending text that is already present verbatim
     * at the insertion point is a no-op, so a job re-delivered after a partial failure cannot double a
     * line. That is a cheap backstop UNDER the real one (`applied_ops`), not a replacement for it —
     * this catches a repeat of the identical string, while the ledger catches a repeat of the
     * OPERATION whatever its text.
     */
    public static function apply(string $content, ?string $section, string $addition): string
    {
        $addition = trim($addition);

        if ($addition === '') {
            return $content;
        }

        $section = $section === null ? null : trim($section);

        if ($section === null || $section === '') {
            return self::appendAtEnd($content, $addition);
        }

        $lines = preg_split('/\R/u', $content) ?: [];
        $headingIndex = self::findHeading($lines, $section);

        if ($headingIndex === null) {
            return self::appendAtEnd(self::appendAtEnd($content, '## ' . $section), $addition);
        }

        $insertAt = self::endOfSection($lines, $headingIndex);

        if (self::alreadyPresent(array_slice($lines, $headingIndex, $insertAt - $headingIndex), $addition)) {
            return $content;
        }

        array_splice($lines, $insertAt, 0, [$addition]);

        return implode("\n", $lines);
    }

    /** Append to the end of the document, separated by a blank line. */
    private static function appendAtEnd(string $content, string $addition): string
    {
        $trimmed = rtrim($content);

        if ($trimmed === '') {
            return $addition;
        }

        if (self::alreadyPresent(preg_split('/\R/u', $trimmed) ?: [], $addition)) {
            return $content;
        }

        return $trimmed . "\n\n" . $addition;
    }

    /**
     * The index of the heading line naming $section, or null.
     *
     * @param  array<int, string>  $lines
     */
    private static function findHeading(array $lines, string $section): ?int
    {
        $needle = mb_strtolower($section);

        foreach ($lines as $index => $line) {
            if (preg_match('/^(#{1,6})\s+(.*?)\s*$/u', $line, $match) !== 1) {
                continue;
            }

            if (mb_strtolower(trim($match[2])) === $needle) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Where the section that starts at $headingIndex ENDS — the line before the next heading of the
     * same or a higher level, trailing blank lines excluded so the insertion does not leave a gap.
     *
     * @param  array<int, string>  $lines
     */
    private static function endOfSection(array $lines, int $headingIndex): int
    {
        preg_match('/^(#{1,6})/u', $lines[$headingIndex], $match);
        $level = strlen($match[1] ?? '#');

        $end = count($lines);

        for ($index = $headingIndex + 1; $index < count($lines); $index++) {
            if (preg_match('/^(#{1,6})\s/u', $lines[$index], $next) === 1 && strlen($next[1]) <= $level) {
                $end = $index;

                break;
            }
        }

        while ($end > $headingIndex + 1 && trim($lines[$end - 1]) === '') {
            $end--;
        }

        return $end;
    }

    /** @param  array<int, string>  $lines */
    private static function alreadyPresent(array $lines, string $addition): bool
    {
        $needle = trim($addition);

        foreach ($lines as $line) {
            if (trim($line) === $needle) {
                return true;
            }
        }

        return false;
    }
}
