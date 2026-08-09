<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * The WHOLE answer to one search: the ranked hits plus the honest account of how they were produced.
 *
 * The metadata is not decoration. A hybrid search can lose one of its two legs for reasons that are
 * entirely legitimate — the workspace hit its AI cap, an operator threw the module's kill switch, the
 * provider had a bad minute — and in every one of those cases the RIGHT answer is still a page of
 * keyword results, not an error. But a client that cannot tell a full search from a degraded one will
 * present "no results" as "you have nothing written about this", which is the single most damaging
 * lie a knowledge base can tell. Hence {@see $vectorSkipped} and its reason: the search succeeds, and
 * says what it could not do.
 *
 * {@see $hasMore} exists for the same reason in the opposite direction — there is no pagination here
 * (see `config/knowledge.php`), so a client has to be told when the list was cut rather than
 * exhausted.
 */
final class KnowledgeSearchResults
{
    /** The workspace is at its AI cost cap; the query could not be embedded. */
    public const SKIPPED_BUDGET = 'budget';

    /** `knowledge.index.enabled` is off — the module's kill switch covers reads too, not just writes. */
    public const SKIPPED_DISABLED = 'disabled';

    /** The connection has no vector column (a non-pgsql environment). */
    public const SKIPPED_UNSUPPORTED = 'unsupported';

    /** The embedding provider failed. Logged and reported; the search still answered. */
    public const SKIPPED_ERROR = 'error';

    /**
     * @param  array<int, KnowledgeSearchHit>  $hits
     */
    public function __construct(
        public readonly array $hits,
        public readonly string $query,
        public readonly int $limit,
        public readonly bool $hasMore,
        public readonly bool $vectorSkipped,
        /** One of the SKIPPED_* constants, or null when both legs ran. */
        public readonly ?string $vectorReason = null,
    ) {}
}
