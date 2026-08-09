<?php

namespace App\Modules\Knowledge\Support;

/**
 * The `index_digest` of a knowledge entry: a sha256 over everything that would change what gets
 * embedded, or how.
 *
 * That is BOTH the authored text (title + content + metadata) AND the parameters the indexing run
 * would use: the chunker version, the embedding model and its dimensions, and the LINK DERIVATION
 * version. Folding the parameters in is what makes an infrastructure change self-healing: bump the
 * chunker or swap the model, and every entry's digest moves, so `index_digest != indexed_digest` marks
 * the whole base for re-indexing without anyone having to remember to run a backfill. Leaving them out
 * is the classic version of this bug — new entries get the new pipeline, old ones keep the old one
 * forever, and retrieval quality quietly depends on when a document happened to be written.
 *
 * `links.version` is here for the same reason and is CHEAP to bump, which is the point: the run it
 * triggers re-chunks the entry to the identical chunk digests, so every stored embedding is reused and
 * nothing is bought. What actually gets recomputed is the graph. That makes improving a link heuristic
 * a one-line config change plus a sweep, instead of a migration that re-embeds a workspace.
 *
 * The metadata is canonicalized (keys sorted, recursively) before hashing, because it round-trips
 * through JSON and PHP arrays: identical data can arrive with different key order, and an entry that
 * re-indexes itself because a map was rebuilt is paying real money for nothing.
 *
 * Separator bytes are NUL, which cannot appear in any input (the directive guard refuses them), so
 * no combination of field values can be made to collide with another by shifting text across a
 * boundary.
 */
class KnowledgeDigest
{
    private const SEPARATOR = "\0";

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function for(string $title, string $content, ?array $metadata): string
    {
        $parts = [
            $title,
            $content,
            self::canonicalJson($metadata ?? []),
            (string) config('knowledge.chunking.version'),
            (string) config('knowledge.embedding.model'),
            (string) config('knowledge.embedding.dimensions'),
            (string) config('knowledge.links.version'),
        ];

        return hash('sha256', implode(self::SEPARATOR, $parts));
    }

    /**
     * A short fingerprint of the PIPELINE PARAMETERS alone — the chunker version, the embedding model
     * and its width — with no entry content in it.
     *
     * {@see for()} already folds these in, which is what makes an entry's digest move when the
     * pipeline changes. But that digest is only ever RECOMPUTED when someone saves the entry, so on
     * its own it delivers exactly nothing: bump the chunker and every existing entry keeps its old
     * digest, still equal to its `indexed_digest`, and the base is quietly left on the old pipeline
     * forever — the precise failure the digest was designed to prevent.
     *
     * Storing this fingerprint next to the digest is what closes that. It is a SQL-comparable value,
     * identical for every entry, so the sweep can find every out-of-pipeline entry with one indexed
     * predicate instead of rehashing 40 000 characters per row to discover the same thing. Short (16
     * hex characters) because it is an equality marker, never a security boundary.
     */
    public static function parameters(): string
    {
        return substr(hash('sha256', implode(self::SEPARATOR, [
            (string) config('knowledge.chunking.version'),
            (string) config('knowledge.embedding.model'),
            (string) config('knowledge.embedding.dimensions'),
            (string) config('knowledge.links.version'),
        ])), 0, 16);
    }

    /** JSON with every object's keys in a deterministic order (recursively). */
    private static function canonicalJson(mixed $value): string
    {
        return (string) json_encode(self::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        // A LIST keeps its order (order is data there); a MAP is sorted by key (order is incidental).
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item) => self::canonicalize($item), $value);
        }

        ksort($value);

        return array_map(static fn (mixed $item) => self::canonicalize($item), $value);
    }
}
