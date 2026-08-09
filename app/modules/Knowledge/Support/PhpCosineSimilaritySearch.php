<?php

namespace App\Modules\Knowledge\Support;

use App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch;
use App\Modules\Knowledge\DTOs\ChunkMatch;
use App\Modules\Knowledge\DTOs\ChunkSimilarityQuery;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use Illuminate\Support\Facades\Log;

/**
 * The `knowledge.vector_store=php` ESCAPE HATCH: scoring in process instead of in the index.
 *
 * It exists so the module is not wedged if the vector index ever has to be dropped (a pgvector
 * upgrade, a restore onto a database where the extension is missing, a bug in the ANN parameters).
 * It is NOT a second supported backend, and the difference is worth being explicit about: this reads
 * every candidate row's vector into memory, which is O(base) work and O(base × 1536 floats) of
 * memory per query. On a base of a few hundred passages it is invisible; on a real one it is the
 * wrong shape of solution, which is why {@see CANDIDATE_CAP} bounds it and says so in the log.
 *
 * COSINE = DOT PRODUCT here, and that is not a shortcut: both embedders in this module return
 * unit-length vectors ({@see FakeKnowledgeEmbedder} normalizes explicitly, and the provider's
 * text-embedding-3 family is normalized by construction), so the magnitudes are 1 and the division
 * cancels. Computing the norms anyway would cost two extra passes over 1536 floats per row to divide
 * by 1 — and, worse, would hide a genuine bug: a vector that is NOT unit length means something has
 * gone wrong upstream, and it should show up as a wrong score rather than be quietly normalized away.
 *
 * The scope, the tenancy filter and the trash filter are the SAME ones the Postgres path applies —
 * see {@see ChunkCandidates}, which is shared precisely so an escape hatch can never be the leakier
 * of the two.
 */
class PhpCosineSimilaritySearch implements KnowledgeSimilaritySearch
{
    /**
     * Most rows one lookup will read. A refusal to melt: past this the fallback stops being slow and
     * starts being an outage, and returning the best of a bounded, DETERMINISTIC candidate set (rows
     * are taken in primary-key order, so the same base always yields the same candidates) is a better
     * failure than an exhausted worker.
     */
    private const CANDIDATE_CAP = 5000;

    public function topChunks(array $vector, ChunkSimilarityQuery $query): array
    {
        if ($vector === [] || $query->limit < 1 || !ChunkVector::supported()) {
            return [];
        }

        $table = (new KnowledgeEntryChunk)->getTable();

        $rows = ChunkCandidates::query($query)
            ->selectRaw($table . '.embedding::text as embedding_text')
            ->orderBy($table . '.id')
            ->limit(self::CANDIDATE_CAP)
            ->get();

        if ($rows->count() >= self::CANDIDATE_CAP) {
            Log::warning('Knowledge PHP similarity fallback hit its candidate cap; results are partial.', [
                'knowledge_base_id' => $query->baseId,
                'cap' => self::CANDIDATE_CAP,
            ]);
        }

        $matches = [];

        foreach ($rows as $chunk) {
            $stored = ChunkVector::fromLiteral($chunk->getAttribute('embedding_text'));

            if ($stored === null) {
                continue;
            }

            $matches[] = new ChunkMatch(
                chunkId: (string) $chunk->getKey(),
                entryId: (string) $chunk->knowledge_entry_id,
                ordinal: (int) $chunk->ordinal,
                headingPath: $chunk->heading_path,
                content: (string) $chunk->content,
                charStart: (int) $chunk->char_start,
                charLength: (int) $chunk->char_length,
                similarity: self::dot($vector, $stored),
            );
        }

        // Ties break on the citation address, so the same base and the same query always produce the
        // same order — a ranking that reshuffles equal scores makes every downstream assertion (and
        // every user's mental map of their own base) unreproducible.
        usort($matches, static fn (ChunkMatch $a, ChunkMatch $b): int => $b->similarity <=> $a->similarity
            ?: [$a->entryId, $a->ordinal] <=> [$b->entryId, $b->ordinal]);

        return array_slice($matches, 0, $query->limit);
    }

    public function topNeighbours(string $chunkId, ChunkSimilarityQuery $query): array
    {
        $source = KnowledgeEntryChunk::query()->withoutEmbedding()->find($chunkId);
        $vector = ChunkVector::read($chunkId);

        if ($source === null || $vector === null) {
            return [];
        }

        // The interface promises self-exclusion whatever the caller asked for.
        return $this->topChunks($vector, new ChunkSimilarityQuery(
            baseId: $query->baseId,
            limit: $query->limit,
            statuses: $query->statuses,
            excludeEntryId: (string) $source->knowledge_entry_id,
            minChunkChars: $query->minChunkChars,
        ));
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $width = min(count($a), count($b));

        for ($i = 0; $i < $width; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }
}
