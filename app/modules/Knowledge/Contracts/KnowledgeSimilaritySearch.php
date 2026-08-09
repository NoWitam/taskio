<?php

namespace App\Modules\Knowledge\Contracts;

use App\Modules\Knowledge\DTOs\ChunkMatch;
use App\Modules\Knowledge\DTOs\ChunkSimilarityQuery;

/**
 * The seam every VECTOR lookup in this module goes through — the read counterpart of
 * {@see KnowledgeEmbedder}, which owns the write.
 *
 * Two implementations, chosen by `knowledge.vector_store`:
 *   pgvector  {@see \App\Modules\Knowledge\Support\PgVectorSimilaritySearch} — ranks INSIDE Postgres
 *             against the HNSW index. The production path, and the only one that scales.
 *   php       {@see \App\Modules\Knowledge\Support\PhpCosineSimilaritySearch} — scores candidates in
 *             process. An escape hatch, bounded by a hard candidate cap.
 *
 * The contract exists so callers can never do the one thing that would sink this design: pull vectors
 * into PHP to compare them. Every method here returns {@see ChunkMatch} projections that carry no
 * embedding at all, and the pgvector implementation never materialises one — a 1536-float column
 * hydrated across a base's worth of rows is megabytes of memory to answer a question the database
 * already indexed. That is a correctness-of-scale property, not a micro-optimisation, so it belongs
 * in the interface rather than in a comment on one caller.
 *
 * NEITHER METHOD SPENDS. Producing the query vector is the caller's job, so the caller can bracket it
 * with the shared meter (embedding a query is an AI call and is billed like any other); a lookup by
 * an ALREADY STORED vector — {@see topNeighbours()} — costs nothing at all, which is precisely why
 * the similarity linker can afford to run on every re-index.
 */
interface KnowledgeSimilaritySearch
{
    /**
     * The passages closest to a vector the caller just produced (a search query's embedding).
     *
     * @param  array<int, float>  $vector  unit-length; both embedders in this module normalize
     * @return array<int, ChunkMatch> best first, at most `$query->limit`
     */
    public function topChunks(array $vector, ChunkSimilarityQuery $query): array;

    /**
     * The passages closest to an ALREADY STORED passage, named by id.
     *
     * Separate from {@see topChunks()} because the stored vector must not make a round trip through
     * PHP to be used as a query: on Postgres this compiles to a sub-select, so a chunk's neighbours
     * are found without its 1536 floats ever leaving the database. That is what makes materialising
     * similarity edges for a fifty-passage entry affordable on a background job.
     *
     * The chunk's own entry is always excluded, whatever the query says — an entry is trivially
     * similar to itself and an edge to oneself is not a relation.
     *
     * @return array<int, ChunkMatch> best first, at most `$query->limit`
     */
    public function topNeighbours(string $chunkId, ChunkSimilarityQuery $query): array;
}
