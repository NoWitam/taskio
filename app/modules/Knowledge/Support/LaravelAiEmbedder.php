<?php

namespace App\Modules\Knowledge\Support;

use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\DTOs\EmbeddingBatchResult;
use Laravel\Ai\Embeddings;
use RuntimeException;

/**
 * The real {@see KnowledgeEmbedder}: one laravel/ai embeddings call per invocation.
 *
 * PROVIDER AND MODEL ARE PASSED EXPLICITLY, never left to the package's `default_for_embeddings`.
 * That default is a package-level setting this app does not configure, so an implicit call would
 * resolve to whichever provider the package ships as its default and fail on a missing key — the same
 * trap `ai.image_generate_provider` documents for image generation. Both come from
 * `config('knowledge.embedding')`, which also feeds the entry digest, so a model swap re-indexes
 * everything instead of leaving a base half-embedded by two different models.
 *
 * DIMENSIONS are likewise explicit and load-bearing: they must match the stored `vector(N)` column, so
 * they are requested rather than inherited from the provider's default width.
 *
 * NO CACHING. `->cache()` is deliberately not used: the package's embedding cache keys on the joined
 * inputs, and this module already has a far better cache — the per-chunk `digest`, which skips the
 * call entirely rather than skipping the network. Layering the package cache on top would only add a
 * second, coarser copy of the same text with its own TTL.
 *
 * Metering is applied by the CALLER (see {@see KnowledgeEmbedder}); this class holds no budget logic.
 */
class LaravelAiEmbedder implements KnowledgeEmbedder
{
    public function embed(array $texts): EmbeddingBatchResult
    {
        $texts = array_values($texts);

        $response = Embeddings::for($texts)
            ->dimensions((int) config('knowledge.embedding.dimensions'))
            ->generate(
                (string) config('knowledge.embedding.provider'),
                (string) config('knowledge.embedding.model'),
            );

        $vectors = array_values($response->embeddings);

        // A provider that returns a different number of vectors than we sent would silently
        // MIS-ALIGN every chunk with someone else's vector — the worst possible failure for a
        // retrieval index, because nothing downstream can detect it. Fail loudly instead; the job
        // marks the entry `failed` and the sweep retries.
        if (count($vectors) !== count($texts)) {
            throw new RuntimeException(
                'Embedding provider returned ' . count($vectors) . ' vectors for ' . count($texts) . ' inputs.'
            );
        }

        return new EmbeddingBatchResult($vectors, max(0, (int) $response->tokens));
    }
}
