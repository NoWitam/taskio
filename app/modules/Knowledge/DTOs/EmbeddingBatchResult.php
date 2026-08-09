<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * The result of ONE embedding batch: the vectors, positionally aligned with the texts that were
 * handed in, plus the REAL token count the provider reported.
 *
 * `$tokens` is not decoration. This object is what the {@see \App\Modules\Variables\Contracts\MeteredAiCall}
 * meter sees when it brackets an embedding call, and the meter reads a flat `->tokens` int off a
 * result that has no `->usage` — which is exactly the shape laravel/ai's own
 * {@see \Laravel\Ai\Responses\EmbeddingsResponse} has. Keeping the field name identical is what lets
 * the meter record what an embedding ACTUALLY cost instead of a flat per-call stand-in, for the real
 * embedder and the fake alike. Renaming it silently downgrades every embedding row in the ledger to a
 * guess.
 */
final class EmbeddingBatchResult
{
    /**
     * @param  array<int, array<int, float>>  $vectors  one vector per input text, in input order
     * @param  int  $tokens  real provider tokens billed for this batch
     */
    public function __construct(
        public readonly array $vectors,
        public readonly int $tokens,
    ) {}
}
