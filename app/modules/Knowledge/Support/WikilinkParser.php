<?php

namespace App\Modules\Knowledge\Support;

use Illuminate\Support\Str;

/**
 * Parses `[[wikilinks]]` out of a knowledge entry's content.
 *
 * Two forms, and nothing else: `[[slug]]` and `[[slug|human label]]`. The label is display-only — it
 * is not stored, because the link's meaning lives in the target and a label copied into the edge
 * would go stale the moment the target is renamed.
 *
 * NORMALIZATION is the load-bearing part. The raw target is put through the SAME `Str::slug` that
 * mints an entry's slug, so `[[Moja Notatka]]`, `[[moja notatka]]` and `[[moja-notatka]]` all resolve
 * to the one entry. Without that, a writer would have to know the slugging rules to write a link, and
 * the overwhelmingly common failure — writing the TITLE instead of the slug — would silently produce
 * a ghost that never attaches.
 *
 * The parser sees content that has ALREADY passed the directive guard, so it can never encounter the
 * template engine's `[[IF]]` / `[[ELSE_IF]]` / `[[ELSE]]` branch markers (they are rejected at
 * validation). That ordering is deliberate: the two syntaxes overlap on `[[`, and the guard is what
 * keeps this parser from having to know anything about the template engine.
 */
class WikilinkParser
{
    /**
     * `[[target]]` or `[[target|label]]`. Neither part may contain a bracket or a newline, so an
     * unclosed link cannot swallow the rest of the document, and the lengths are bounded so a
     * pathological input cannot produce an enormous target string.
     */
    private const PATTERN = '/\[\[([^\[\]|\r\n]{1,200})(?:\|([^\[\]\r\n]{0,200}))?\]\]/u';

    /**
     * How many DISTINCT targets one entry may link to. A generous ceiling whose only job is to bound
     * the delete+insert a save performs; a real entry links to a handful.
     */
    public const MAX_TARGETS = 500;

    /**
     * The distinct, normalized target slugs $content links to, in first-appearance order. Empty
     * targets (`[[]]`, `[[ | x ]]`) and anything that slugs to nothing are dropped rather than stored
     * as an unmatchable edge.
     *
     * @return array<int, string>
     */
    public function targets(?string $content): array
    {
        if (!is_string($content) || !str_contains($content, '[[')) {
            return [];
        }

        if (preg_match_all(self::PATTERN, $content, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $targets = [];

        foreach ($matches as $match) {
            $slug = self::normalize($match[1] ?? '');

            if ($slug === '' || in_array($slug, $targets, true)) {
                continue;
            }

            $targets[] = $slug;

            if (count($targets) >= self::MAX_TARGETS) {
                break;
            }
        }

        return $targets;
    }

    /**
     * The canonical slug form of a raw link target / title. THE single normalization used by both the
     * parser and the entry slug minting, so a link and the entry it points at can never disagree
     * about what the slug is.
     */
    public static function normalize(string $raw): string
    {
        return Str::slug(trim($raw));
    }
}
