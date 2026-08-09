<?php

namespace App\Modules\Knowledge\Support;

/**
 * The ONE normalizer for an entry's alias list — used by the write path, by the AI composer's
 * laundering, and by anything reading the column back.
 *
 * There is one authority because the list arrives from three places with very different trust: a human
 * typing into the editor, a model returning JSON, and a jsonb column that may hold whatever an older
 * version wrote. Normalising per caller would mean three slightly different ideas of what an alias is,
 * and the mention layer would then behave differently depending on who created the entry.
 *
 * The rules, and what each is protecting:
 *   STRINGS ONLY, TRIMMED, NON-EMPTY — an alias is a surface form; a blank one would compile to an
 *     empty pattern that matches nothing (or, worse, everything).
 *   LENGTH-CAPPED — an alias is a name, not a sentence. The cap also bounds the prompt the composer
 *     is shown and the scan work per entry.
 *   COUNT-CAPPED — the mention scan builds one pattern per alias, so this is the per-entry bound on
 *     that work. Extra aliases are DROPPED rather than refused: a model returning fifteen is being
 *     enthusiastic, not malicious, and failing the whole draft over it would throw away good writing.
 *   DEDUPED CASE-INSENSITIVELY — "Wieży Eiffla" and "wieży eiffla" are one pattern; keeping both would
 *     double the scan cost for nothing and could double an edge.
 */
class EntryAliases
{
    /** Most aliases one entry may carry — the per-entry bound on mention-scan patterns. */
    public const MAX = 10;

    /** Longest one alias may be. A name, not a sentence. */
    public const MAX_CHARS = 120;

    /**
     * @return array<int, string>
     */
    public static function normalize(mixed $aliases): array
    {
        if (!is_array($aliases)) {
            return [];
        }

        $clean = [];
        $seen = [];

        foreach ($aliases as $alias) {
            if (count($clean) >= self::MAX) {
                break;
            }

            if (!is_string($alias)) {
                continue;
            }

            // Control characters stripped for the same reason every other stored text field strips
            // them: an alias is composed into a prompt, and an invisible character in a pattern is a
            // pattern nobody can debug.
            $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $alias));

            if ($value === '') {
                continue;
            }

            $value = mb_substr($value, 0, self::MAX_CHARS);
            $key = mb_strtolower($value);

            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $clean[] = $value;
        }

        return $clean;
    }
}
