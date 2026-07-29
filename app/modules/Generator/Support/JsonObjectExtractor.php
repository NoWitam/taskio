<?php

namespace App\Modules\Generator\Support;

/**
 * The SHARED defensive scanner every prompt-and-parse seam in the Generator uses to pull the FIRST complete
 * top-level JSON object out of a raw model reply: strip an optional ```json … ``` (or bare ``` … ```) fence,
 * then balance-scan the braces STRING- and ESCAPE-aware, so a `}` inside a JSON string value never closes the
 * object and a chatty preamble/epilogue around the object is tolerated.
 *
 * EXTRACTED (not forked) from {@see \App\Modules\Generator\Services\ShotListRenderer}, whose behavior it
 * preserves byte-for-byte, so the creative-direction parse
 * ({@see \App\Modules\Generator\Services\CreativeDirectionService}) rides the SAME proven walk instead of a
 * second, subtly-different copy. Pure + static: no state, no config, no logging (the raw reply is DATA and is
 * never logged anywhere).
 */
class JsonObjectExtractor
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
}
