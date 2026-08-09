<?php

namespace App\Modules\Knowledge\Support;

use App\Modules\Knowledge\DTOs\ChunkSimilarityQuery;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ONE place a similarity lookup's SCOPE is expressed — shared by both implementations of
 * {@see \App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch} so the Postgres path and the PHP
 * fallback can never disagree about what a lookup is allowed to see.
 *
 * That agreement is a security property, not tidiness. Three of the filters below are the difference
 * between a retrieval layer and a leak:
 *
 *   TENANCY   The query is built on the Eloquent model, so {@see \App\Models\Scopes\WorkspaceScope}
 *             adds the workspace predicate in shared mode and correctly omits it in own-database
 *             mode. This is why the vector search is NOT raw SQL: a hand-written statement would have
 *             to know which tenancy mode it is in, and a wrong guess reads another workspace's base.
 *   TRASH     Chunks SURVIVE a soft delete on purpose (restoring an entry must not cost a re-index),
 *             so nothing stops a trashed entry's passages from winning a vector ranking. The join and
 *             its `deleted_at is null` are what keep a deleted document out of search results and out
 *             of the graph.
 *   MODEL     Only vectors produced by the CURRENTLY configured embedding model are comparable.
 *             Two models share no coordinate system, so a leftover vector from a previous model would
 *             score against the query as noise that looks exactly like a real result — undetectable
 *             downstream, which is why it is excluded here rather than filtered later.
 *
 * The join is a plain SQL join rather than a relation constraint because the ranking has to happen in
 * ONE statement against the vector index; `whereHas` would nest a sub-select the planner cannot push
 * into the ANN scan.
 */
final class ChunkCandidates
{
    /** The columns a match projection needs. Never `embedding` — see the model's docblock. */
    public static function query(ChunkSimilarityQuery $query): Builder
    {
        $chunks = (new KnowledgeEntryChunk)->getTable();
        $entries = (new KnowledgeEntry)->getTable();

        $builder = KnowledgeEntryChunk::query()
            ->select([
                $chunks . '.id',
                $chunks . '.knowledge_entry_id',
                $chunks . '.ordinal',
                $chunks . '.heading_path',
                $chunks . '.content',
                $chunks . '.char_start',
                $chunks . '.char_length',
            ])
            ->join($entries, $entries . '.id', '=', $chunks . '.knowledge_entry_id')
            ->whereNull($entries . '.deleted_at')
            // DRAFTS, for the same reason as the trash above: this is a raw JOIN, so it inherits
            // neither the soft-delete filter nor {@see \App\Modules\Knowledge\Models\Scopes\WithoutDraftsScope}.
            // Today an unaccepted draft owns no chunks (it is never indexed) and so cannot reach a
            // ranking anyway — but the scope's whole promise is that the barrier is in ONE place and
            // cannot be forgotten, and a hand-written join is exactly where that promise lapses. One
            // predicate is cheaper than depending on two invariants elsewhere staying true.
            ->whereNull($entries . '.draft_session_id')
            ->whereNotNull($chunks . '.embedding')
            ->where($chunks . '.embedding_model', (string) config('knowledge.embedding.model'));

        if ($query->baseId !== null) {
            $builder->where($chunks . '.knowledge_base_id', $query->baseId);
        }

        if ($query->statuses !== null) {
            $builder->whereIn($entries . '.status', $query->statuses);
        }

        if ($query->excludeEntryId !== null) {
            $builder->where($chunks . '.knowledge_entry_id', '!=', $query->excludeEntryId);
        }

        if ($query->minChunkChars > 0) {
            // The floor bites SHORT FRAGMENTS OF LONG DOCUMENTS, never short documents.
            //
            // It exists because a two-line passage of a long entry ("## Cennik" plus a sentence) is
            // close to every other two-line passage for reasons of FORM rather than meaning. That
            // argument does not transfer to an entry that IS two lines: a dictionary-style note is a
            // complete unit of meaning, and its embedding carries the entry's title, so it is exactly
            // as comparable as a long one. Excluding those was making short entries invisible to the
            // graph — no edges at all, with nothing on screen to explain why.
            //
            // `chunks_count` lives on the already-joined entries row, so this costs no extra join.
            $builder->where(fn (Builder $inner) => $inner
                ->where($chunks . '.char_length', '>=', $query->minChunkChars)
                ->orWhere($entries . '.chunks_count', '<=', 1));
        }

        return $builder;
    }
}
