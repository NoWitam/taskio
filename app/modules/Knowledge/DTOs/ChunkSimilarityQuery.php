<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * WHAT a similarity lookup is allowed to return — the scope, expressed declaratively so both
 * implementations of {@see \App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch} can honour it
 * in their own idiom (a WHERE clause in Postgres, a candidate filter in PHP).
 *
 * Declarative rather than a closure over a query builder, even though both implementations happen to
 * be Eloquent-based today: a scope the caller could extend arbitrarily would let a caller widen the
 * lookup past what the implementations can safely express — and the two most important constraints
 * here are SAFETY constraints, not preferences. Trashed entries must never surface (their chunks
 * survive a soft delete on purpose, so a restore brings back a searchable entry without re-paying for
 * it), and neither must another workspace's — the latter is enforced by the tenancy scope on the
 * models, which is exactly why both implementations must build their queries through Eloquent rather
 * than as raw SQL.
 *
 * `$baseId` null means "every base in the ACTIVE workspace" — the global search. It is a widening,
 * never a leak: the workspace boundary is applied by the model's global scope underneath.
 *
 * `$minChunkChars` is applied to the CANDIDATES here, which is the other half of the rule the
 * similarity linker applies to its sources. Filtering only one end would make the floor decorative:
 * the graph unions both directions, so a long passage matching a two-line one would still draw the
 * edge the floor exists to prevent.
 */
final class ChunkSimilarityQuery
{
    /**
     * @param  string|null  $baseId  null = every base in the active workspace
     * @param  array<int, string>|null  $statuses  entry statuses to include; null = every status
     * @param  string|null  $excludeEntryId  never return this entry's own passages
     * @param  int  $minChunkChars  ignore candidate passages shorter than this
     */
    public function __construct(
        public readonly ?string $baseId,
        public readonly int $limit,
        public readonly ?array $statuses = null,
        public readonly ?string $excludeEntryId = null,
        public readonly int $minChunkChars = 0,
    ) {}
}
