<?php

namespace App\Modules\Knowledge\Support;

use App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch;
use App\Modules\Knowledge\DTOs\ChunkMatch;
use App\Modules\Knowledge\DTOs\ChunkSimilarityQuery;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use Illuminate\Database\Eloquent\Builder;

/**
 * The PRODUCTION vector lookup: ranking happens inside Postgres, against the HNSW index.
 *
 * Everything about this class follows from one rule — the vectors do not come to PHP. The ORDER BY is
 * the cosine-distance operator `<=>` applied to the indexed column, so the database walks the graph
 * index and returns the K best rows; the alternative (fetch candidates, score them here) would read
 * every row's 1536 floats to answer a question the index already answers, and would stop working at
 * exactly the base size where search starts to matter.
 *
 * `<=>` yields cosine DISTANCE. Every value that leaves this class is converted to SIMILARITY
 * (`1 - distance`) once, here, so no caller has to remember which direction the number runs — the one
 * mistake that would silently invert every threshold in the module.
 *
 * The query vector is bound as `?::vector`, never interpolated. pgvector's text form is a bare list of
 * floats, so an interpolated one is an injection surface the moment a vector can come from untrusted
 * input — which, for the search leg, it does: the vector is derived from a user's query string.
 *
 * {@see topNeighbours()} passes the stored vector as a SCALAR SUB-SELECT rather than reading it and
 * binding it back. Postgres evaluates such a sub-select once per statement (an InitPlan), so the ANN
 * scan still sees a constant — and a fifty-passage entry's edges are materialised without a single
 * vector crossing the connection.
 *
 * FILTERED ANN is post-filtered by the planner: with a very selective scope Postgres may return fewer
 * than `limit` rows from the index, or fall back to a sequential scan. Both are CORRECT (the shape of
 * the result never changes, only how it was reached), which is the property that matters — an
 * approximate index that silently dropped rows the caller filtered on would not be.
 */
class PgVectorSimilaritySearch implements KnowledgeSimilaritySearch
{
    public function topChunks(array $vector, ChunkSimilarityQuery $query): array
    {
        if ($vector === [] || $query->limit < 1 || !ChunkVector::supported()) {
            return [];
        }

        $literal = ChunkVector::toLiteral($vector);
        $distance = $this->column() . ' <=> ?::vector';

        return $this->matches(
            ChunkCandidates::query($query)
                ->selectRaw('1 - (' . $distance . ') as similarity', [$literal])
                ->orderByRaw($distance, [$literal])
                ->limit($query->limit),
        );
    }

    public function topNeighbours(string $chunkId, ChunkSimilarityQuery $query): array
    {
        if ($query->limit < 1 || !ChunkVector::supported()) {
            return [];
        }

        $table = (new KnowledgeEntryChunk)->getTable();
        $distance = $this->column() . ' <=> (select source_chunk.embedding from ' . $table
            . ' as source_chunk where source_chunk.id = ?)';

        return $this->matches(
            ChunkCandidates::query($query)
                ->selectRaw('1 - (' . $distance . ') as similarity', [$chunkId])
                // A passage is trivially its own nearest neighbour, and an entry is not related to
                // itself. Enforced here rather than left to the caller because it is true of EVERY
                // call, and resolved in SQL so learning the owner costs no extra round trip.
                ->whereRaw(
                    $table . '.knowledge_entry_id <> (select source_owner.knowledge_entry_id from '
                    . $table . ' as source_owner where source_owner.id = ?)',
                    [$chunkId],
                )
                ->orderByRaw($distance, [$chunkId])
                ->limit($query->limit),
        );
    }

    /**
     * @return array<int, ChunkMatch> best first, TIES BROKEN ON THE CITATION ADDRESS
     */
    private function matches(Builder $builder): array
    {
        $matches = $builder->get()
            ->map(fn (KnowledgeEntryChunk $chunk): ChunkMatch => new ChunkMatch(
                chunkId: (string) $chunk->getKey(),
                entryId: (string) $chunk->knowledge_entry_id,
                ordinal: (int) $chunk->ordinal,
                headingPath: $chunk->heading_path,
                content: (string) $chunk->content,
                charStart: (int) $chunk->char_start,
                charLength: (int) $chunk->char_length,
                similarity: (float) $chunk->getAttribute('similarity'),
            ))
            ->all();

        // EXACTLY TIED DISTANCES ARE NOT HYPOTHETICAL. An embedding is a function of text, so two
        // passages with the same text — a document duplicated into two entries, a shared boilerplate
        // section, an entry copied to be edited — have BYTE-IDENTICAL vectors and therefore identical
        // distance to every query. `ORDER BY embedding <=> ?` alone leaves their relative order up to
        // the plan, and the callers consume RANKS: hybrid search fuses them by reciprocal rank (where
        // an arbitrary swap between two tied passages can flip the head of the result list), retrieval
        // packs passages into a budget in the order they arrive, and the relation preview draws edges
        // in it. A search that returned a different first result for the same query would break the
        // one thing a result list has to offer: the ability to go back and find what you just saw.
        //
        // Broken HERE rather than in the ORDER BY on purpose. A secondary sort key in SQL would force
        // the planner to sort on top of the ANN scan, and the whole design of this class is that the
        // index does the ranking; re-ordering at most `limit` already-fetched rows costs nothing and
        // cannot change which rows the index chose.
        //
        // The comparator is the one {@see PhpCosineSimilaritySearch} already used — entry, then
        // ordinal — so the escape hatch and the production path still answer identically, which is the
        // property {@see ChunkCandidates} exists to protect.
        usort($matches, static fn (ChunkMatch $a, ChunkMatch $b): int => $b->similarity <=> $a->similarity
            ?: [$a->entryId, $a->ordinal] <=> [$b->entryId, $b->ordinal]);

        return $matches;
    }

    private function column(): string
    {
        return (new KnowledgeEntryChunk)->getTable() . '.embedding';
    }
}
