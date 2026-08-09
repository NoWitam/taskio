<?php

namespace App\Modules\Knowledge\Support;

/**
 * WHICH `[[wikilinks]]` A REWRITE WOULD DESTROY.
 *
 * A rewrite replaces a document's whole body, and a model rewriting for tone or length will drop links
 * without noticing — they read as punctuation. Each one it drops is an edge that disappears from the
 * graph, and the loss is invisible in the diff: the sentence is still there, worded slightly
 * differently, and the reader's eye goes to the prose.
 *
 * ------------------------------------------------------------------------------------------------
 * IT WARNS, IT DOES NOT REFUSE
 *
 * The owner's decision is that everything is reviewed by a human, so the honest job here is to make
 * the loss LOUD rather than to block the operation. Blocking would be wrong on the merits too: a
 * rewrite that removes a paragraph is supposed to remove that paragraph's links, and a guard that
 * cannot tell an intentional removal from an accidental one would refuse the good rewrites at exactly
 * the same rate as the bad ones.
 *
 * What it can do is state the fact precisely — "this rewrite drops [[cennik]] and [[zwroty]]" — which
 * is a sentence a reviewer can act on in a second, and which no diff of prose surfaces on its own.
 *
 * Comparison is on the NORMALIZED target, the same function the parser and the slug minter use, so
 * `[[Cennik]]` rewritten as `[[cennik]]` is correctly seen as kept rather than reported as lost.
 */
final class LinkPreservationGuard
{
    /**
     * The link targets present in $before and absent from $after, in the order they were written.
     *
     * @return array<int, string>
     */
    public static function lostLinks(string $before, string $after): array
    {
        $had = self::targets($before);

        if ($had === []) {
            return [];
        }

        $kept = self::targets($after);

        return array_values(array_diff($had, $kept));
    }

    /**
     * Every `[[target]]` in a body, normalized and de-duplicated.
     *
     * The pattern is the wikilink parser's own, including the optional `|display form` — a link whose
     * display text changed has not been lost, and treating it as lost would cry wolf on every
     * inflection the composer fixes.
     *
     * @return array<int, string>
     */
    private static function targets(string $content): array
    {
        if (!str_contains($content, '[[')) {
            return [];
        }

        preg_match_all('/\[\[([^\[\]|\r\n]{1,200})(?:\|[^\[\]\r\n]{0,200})?\]\]/u', $content, $matches);

        $targets = [];

        foreach ($matches[1] ?? [] as $target) {
            $normalized = WikilinkParser::normalize($target);

            if ($normalized !== '') {
                $targets[$normalized] = true;
            }
        }

        return array_keys($targets);
    }
}
