<?php

namespace App\Modules\Knowledge\Support;

/**
 * Pulls the FIRST complete top-level JSON object out of a raw model reply: strips an optional
 * ```json … ``` (or bare ``` … ```) fence, then balance-scans the braces STRING- and ESCAPE-aware, so a
 * `}` inside a string value never closes the object and a chatty preamble or epilogue is tolerated.
 *
 * A DELIBERATE COPY of the Generator module's `JsonObjectExtractor`, byte-for-byte in behaviour, and
 * the duplication is the lesser evil. That module is an UPPER layer this one may not name — the
 * boundary scan is LITERAL over file bytes, so even the fully-qualified name in this sentence would
 * fail the build, which is the rule doing exactly its job. Reusing it would mean either inverting a
 * dependency that should not exist, or promoting a thirty-line brace walk into App\Support to serve two
 * callers that never talk to each other. Promoting it is the better move the day a THIRD module needs
 * it — at which point this file and that one are deleted together, which is the refactor a shared
 * helper should be born from rather than guessed at.
 *
 * Pure + static: no state, no config, no logging (the raw reply is DATA and is never logged anywhere).
 */
class JsonObject
{
    /** The first complete top-level `{ … }` in a (possibly fenced, possibly prose-wrapped) reply, or null. */
    public static function extract(string $raw): ?string
    {
        $raw = trim($raw);
        $raw = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);

        $start = strpos($raw, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($raw);

        for ($i = $start; $i < $length; $i++) {
            $char = $raw[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * The decoded object, or null when the reply carries none / is not an object. The one entry point
     * a caller should need — extracting and decoding are never useful apart.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $raw): ?array
    {
        $json = self::extract($raw);

        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }
}
