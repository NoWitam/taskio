<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch;
use App\Modules\Knowledge\DTOs\ChunkMatch;
use App\Modules\Knowledge\DTOs\ChunkSimilarityQuery;
use App\Modules\Knowledge\DTOs\KnowledgeSearchHit;
use App\Modules\Knowledge\DTOs\KnowledgeSearchResults;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\KnowledgeSnippet;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HYBRID SEARCH: one keyword leg, one meaning leg, fused by reciprocal rank.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY TWO LEGS
 *
 * Each leg fails in a way the other does not. A vector search cannot find a document it has not
 * embedded yet — so the entry someone saved thirty seconds ago, or the one the budget refused to
 * index, is invisible to it — and it cannot reliably find an exact token (a product code, a surname,
 * a date), because those carry almost no semantic weight. A keyword search cannot find "ile mamy na
 * zwroty" in a document that says "polityka reklamacji", which is most of what a person actually
 * asks. Running both and fusing is not hedging: it is the only way the answer to "do we have anything
 * about X" is trustworthy for both kinds of X.
 *
 * The lexical leg is also the DEGRADED MODE. Every reason the vector leg can be unavailable — the AI
 * cap, the kill switch, a provider outage, a non-pgsql connection — leaves the keyword leg working,
 * so search NEVER returns an error for those. It returns fewer results and says so
 * ({@see KnowledgeSearchResults::$vectorSkipped}). A search box that 500s because a monthly cap was
 * reached would make an ordinary budget event look like an outage.
 *
 * ------------------------------------------------------------------------------------------------
 * RECIPROCAL RANK FUSION, and why not score addition
 *
 * The two legs produce numbers that are not comparable: cosine similarity lives in [0,1] with real
 * hits clustered around 0.8, while the lexical leg has no score at all — only an order. Normalising
 * them onto a common scale means inventing a conversion, and every such invention is a tuning
 * parameter that silently decides which leg wins. RRF uses only the RANKS: an entry contributes
 * `1 / (K + rank)` from each list it appears in. An entry that both legs rank highly beats one that
 * either leg loves, which is exactly the behaviour wanted, and it needs no per-leg calibration to
 * stay true when the embedding model changes.
 *
 * K = 60 is the constant from the original TREC formulation and the de-facto default in every hybrid
 * implementation; it flattens the difference between ranks 1 and 2 enough that a single leg cannot
 * dictate the head of the list on its own.
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT A SEARCH SEES
 *
 * Every status EXCEPT `archived`, unless the caller names statuses explicitly.
 *
 * That is a deliberate middle position between the two obvious ones. Restricting to `approved` would
 * be wrong for the person doing the searching: the drafts are theirs, they are usually looking for
 * something they half-wrote, and a search that hides work in progress teaches people not to use it.
 * Including `archived` by default would be wrong for a different reason — the status means
 * "deliberately no longer current", so returning it next to the entry that REPLACED it puts a retired
 * fact and a live one side by side with nothing to separate them, which is the precise failure a
 * knowledge base exists to prevent. Archived stays reachable by asking for it (`status[]=archived`),
 * because "what did we used to say" is a real question — just not the default one.
 *
 * This is the HUMAN search. A consumer assembling AI context makes its own, stricter choice; that
 * decision belongs to the consumer and is deliberately not baked in here.
 *
 * Trashed entries are excluded everywhere and unconditionally — by the soft-delete scope on the
 * lexical leg and by {@see \App\Modules\Knowledge\Support\ChunkCandidates} on the vector leg, which
 * has to say so explicitly because chunks SURVIVE a soft delete.
 *
 * ------------------------------------------------------------------------------------------------
 * SPEND. One search embeds the query exactly once: one metered call on the `ai_embedding` channel,
 * gated before it. The gate is checked up front so an over-cap workspace never reaches the provider,
 * and the whole leg sits behind `knowledge.index.enabled` — the module's kill switch has to stop
 * READS as well as writes, or "no provider call can come from this module" would not be true.
 */
class KnowledgeSearchService
{
    /** The same meter channel the indexer spends on: embedding a query is embedding. */
    public const CHANNEL = KnowledgeIndexService::CHANNEL;

    /** The RRF damping constant — see the class docblock. */
    private const RRF_K = 60;

    /**
     * How many lexical candidates are fused, relative to the result count. Wider than the result list
     * so an entry the keyword leg ranks 30th can still reach the top when the vector leg agrees with
     * it — the whole value of fusing is in exactly those entries.
     */
    private const LEXICAL_CANDIDATE_FACTOR = 2;

    public function __construct(
        private KnowledgeEmbedder $embedder,
        private KnowledgeSimilaritySearch $vectors,
        private MeteredAiCall $meter,
    ) {}

    /**
     * @param  array<int, string>|null  $statuses  null = every status but `archived`
     */
    public function search(
        ?KnowledgeBase $base,
        string $query,
        ?array $statuses = null,
        ?int $limit = null,
    ): KnowledgeSearchResults {
        $query = trim($query);
        $limit = $limit ?? (int) config('knowledge.search.max_results');
        $statuses = $statuses ?: self::defaultStatuses();

        if ($query === '' || $limit < 1) {
            return new KnowledgeSearchResults([], $query, max($limit, 0), false, false);
        }

        $lexical = $this->lexicalLeg($base, $query, $statuses, $limit * self::LEXICAL_CANDIDATE_FACTOR);

        [$chunks, $reason] = $this->vectorLeg($base, $query, $statuses);

        $passages = $this->groupByEntry($chunks);

        $ranked = $this->fuse($lexical->keys()->all(), array_keys($passages));

        $entries = $this->hydrate($lexical, array_keys($ranked), $base, $statuses);

        $ordered = $this->order($ranked, $entries);

        $hits = $this->hits(array_slice($ordered, 0, $limit), $entries, $ranked, $passages, $base, $query);

        return new KnowledgeSearchResults(
            hits: $hits,
            query: $query,
            limit: $limit,
            hasMore: count($ordered) > $limit,
            vectorSkipped: $reason !== null,
            vectorReason: $reason,
        );
    }

    /** Every status a search shows unless the caller says otherwise — see the class docblock. */
    public static function defaultStatuses(): array
    {
        return array_values(array_filter(
            KnowledgeEntryStatus::ids(),
            static fn (string $status): bool => $status !== KnowledgeEntryStatus::ARCHIVED->value,
        ));
    }

    // ---- the keyword leg ------------------------------------------------------

    /**
     * Entries whose title or body contains the term, in a DETERMINISTIC relevance order: an exact
     * title, then a title that starts with it, then a title that contains it, then a body match —
     * ties broken by recency and finally by id.
     *
     * Ordered rather than merely filtered because RRF consumes RANKS: handing it a set in primary-key
     * order would make one whole leg contribute noise. The four buckets encode the only thing a
     * keyword match can honestly tell us — WHERE it matched — and the shared {@see \App\Models\Concerns\Searchable}
     * scope still does the matching itself, so the filter and the ranking cannot disagree about what
     * "contains" means.
     *
     * @param  array<int, string>  $statuses
     * @return Collection<string, KnowledgeEntry>
     */
    private function lexicalLeg(?KnowledgeBase $base, string $query, array $statuses, int $limit): Collection
    {
        return KnowledgeEntry::query()
            ->when($base !== null, fn ($builder) => $builder->where('knowledge_base_id', $base->getKey()))
            ->whereIn('status', $statuses)
            ->search(['title', 'content'], $query)
            ->with('creator')
            // A RESULT ROW IS A LIST ROW — KnowledgeSearchResultResource delegates the entry half to
            // KnowledgeEntryListResource, so the same component renders both. Without this scope the
            // list showed "7 of 8 indexed" and the search showed nothing for the same entry, because
            // `indexed_chunks_count` reports null when it was not counted, and null there means
            // "cannot be counted on this connection". One correlated sub-select for the page, which is
            // what the scope exists for; a no-op where vectors are unsupported.
            ->withIndexedChunks()
            ->orderByRaw(
                'case when lower(title) = lower(?) then 0'
                . ' when lower(title) like lower(?) then 1'
                . ' when lower(title) like lower(?) then 2'
                . ' else 3 end',
                [$query, $query . '%', '%' . $query . '%'],
            )
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->keyBy('id');
    }

    // ---- the meaning leg ------------------------------------------------------

    /**
     * Embed the query and pull the closest passages, or explain why not.
     *
     * Every refusal path returns a REASON and an empty list rather than throwing: the caller's job is
     * to answer the search, and the keyword leg has already done that.
     *
     * @param  array<int, string>  $statuses
     * @return array{0: array<int, ChunkMatch>, 1: string|null}
     */
    private function vectorLeg(?KnowledgeBase $base, string $query, array $statuses): array
    {
        if (!config('knowledge.index.enabled')) {
            return [[], KnowledgeSearchResults::SKIPPED_DISABLED];
        }

        if (!ChunkVector::supported()) {
            return [[], KnowledgeSearchResults::SKIPPED_UNSUPPORTED];
        }

        try {
            // GATE BEFORE SPEND. An over-cap workspace must not reach the provider at all — and a
            // search is the one place a user can trigger an embedding call by typing, so the gate is
            // doing real work here rather than guarding a background job.
            $this->meter->assertWithinBudget(self::CHANNEL);

            $result = $this->meter->meter(self::CHANNEL, fn () => $this->embedder->embed([$query]));
        } catch (AiBudgetExceededException) {
            return [[], KnowledgeSearchResults::SKIPPED_BUDGET];
        } catch (Throwable $e) {
            // The provider's failure, never the user's query: nothing of what was typed is logged.
            Log::error('Knowledge search could not embed the query.', [
                'knowledge_base_id' => $base?->getKey(),
                'exception' => $e::class,
            ]);
            report($e);

            return [[], KnowledgeSearchResults::SKIPPED_ERROR];
        }

        $vector = $result->vectors[0] ?? null;

        if (!is_array($vector) || $vector === []) {
            return [[], KnowledgeSearchResults::SKIPPED_ERROR];
        }

        return [
            $this->vectors->topChunks($vector, new ChunkSimilarityQuery(
                baseId: $base?->getKey(),
                limit: max(1, (int) config('knowledge.search.chunk_top_k')),
                statuses: $statuses,
            )),
            null,
        ];
    }

    /**
     * Collapse passages into the documents they belong to: best score wins, matches are counted.
     *
     * MAX rather than a sum or a mean, and the choice matters. Summing would rank a long document
     * above a short one that answers the question outright, purely for having more passages; averaging
     * would punish a long document for the sections that are about something else — which is what
     * sections are for. The best passage is the actual evidence, and its position is what the result
     * cites.
     *
     * @param  array<int, ChunkMatch>  $chunks
     * @return array<string, array{best: ChunkMatch, count: int}> insertion order = descending score
     */
    private function groupByEntry(array $chunks): array
    {
        $grouped = [];

        foreach ($chunks as $chunk) {
            if (!isset($grouped[$chunk->entryId])) {
                $grouped[$chunk->entryId] = ['best' => $chunk, 'count' => 0];
            } elseif ($chunk->similarity > $grouped[$chunk->entryId]['best']->similarity) {
                $grouped[$chunk->entryId]['best'] = $chunk;
            }

            $grouped[$chunk->entryId]['count']++;
        }

        // The matches arrive best-first, so first appearance already orders the entries by their best
        // passage — no re-sort, and no chance of a re-sort disagreeing with the scores it was given.
        return $grouped;
    }

    // ---- fusion ---------------------------------------------------------------

    /**
     * @param  array<int, string>  $lexical  entry ids, best first
     * @param  array<int, string>  $vector  entry ids, best first
     * @return array<string, float> entry id => fused score
     */
    private function fuse(array $lexical, array $vector): array
    {
        $scores = [];

        foreach ([$lexical, $vector] as $list) {
            foreach (array_values($list) as $index => $entryId) {
                $scores[$entryId] = ($scores[$entryId] ?? 0.0) + 1 / (self::RRF_K + $index + 1);
            }
        }

        return $scores;
    }

    /**
     * Load whatever the vector leg found that the keyword leg did not. One query, and the same scope
     * both legs used — an entry that changed status between the two queries must not slip through on
     * a stale chunk row.
     *
     * @param  Collection<string, KnowledgeEntry>  $lexical
     * @param  array<int, string>  $ids
     * @param  array<int, string>  $statuses
     * @return Collection<string, KnowledgeEntry>
     */
    private function hydrate(Collection $lexical, array $ids, ?KnowledgeBase $base, array $statuses): Collection
    {
        $missing = array_values(array_diff($ids, $lexical->keys()->all()));

        if ($missing === []) {
            return $lexical;
        }

        $loaded = KnowledgeEntry::query()
            ->whereKey($missing)
            ->when($base !== null, fn ($builder) => $builder->where('knowledge_base_id', $base->getKey()))
            ->whereIn('status', $statuses)
            ->with('creator')
            // Same reason as the lexical leg: an entry found only by the VECTOR leg has to arrive with
            // the same index figures as one found by both, or the row's meaning would depend on which
            // leg happened to surface it.
            ->withIndexedChunks()
            ->get();

        // Re-key AFTER merging, not before: Eloquent's Collection::merge() rebuilds the collection
        // through `array_values()`, so it silently discards string keys — merging two id-keyed
        // collections hands back a numerically indexed one, and every later `has($id)` lookup would
        // quietly answer false.
        return $lexical->merge($loaded)->keyBy('id');
    }

    /**
     * The final order: fused score, then recency, then id.
     *
     * The tie-breaks are not cosmetic. Two entries found by only one leg, at adjacent ranks, get
     * scores that differ in the sixth decimal — and two found at the SAME rank in different legs get
     * identical ones. Without a total order the list would reshuffle between two identical searches,
     * which destroys the one thing a search result has to have: the ability to go back and find the
     * thing you just saw.
     *
     * @param  array<string, float>  $ranked
     * @param  Collection<string, KnowledgeEntry>  $entries
     * @return array<int, string>
     */
    private function order(array $ranked, Collection $entries): array
    {
        $ids = array_values(array_filter(
            array_keys($ranked),
            static fn (string $id): bool => $entries->has($id),
        ));

        usort($ids, static function (string $a, string $b) use ($ranked, $entries): int {
            $byScore = $ranked[$b] <=> $ranked[$a];

            if ($byScore !== 0) {
                return $byScore;
            }

            $updated = ($entries->get($b)->updated_at?->getTimestamp() ?? 0)
                <=> ($entries->get($a)->updated_at?->getTimestamp() ?? 0);

            return $updated !== 0 ? $updated : strcmp($a, $b);
        });

        return $ids;
    }

    /**
     * @param  array<int, string>  $ids
     * @param  Collection<string, KnowledgeEntry>  $entries
     * @param  array<string, float>  $ranked
     * @param  array<string, array{best: ChunkMatch, count: int}>  $passages
     * @return array<int, KnowledgeSearchHit>
     */
    private function hits(array $ids, Collection $entries, array $ranked, array $passages, ?KnowledgeBase $base, string $query): array
    {
        $bases = $this->bases($ids, $entries, $base);
        $hits = [];

        foreach ($ids as $id) {
            /** @var KnowledgeEntry $entry */
            $entry = $entries->get($id);
            $chunk = $passages[$id]['best'] ?? null;
            $content = (string) $entry->content;

            $hits[] = new KnowledgeSearchHit(
                entry: $entry,
                base: $bases[$entry->knowledge_base_id] ?? null,
                chunk: $chunk,
                // With a passage, the snippet is cut from THAT passage so the quote and the citation
                // agree. Without one (a keyword-only hit), the whole body is the span.
                snippet: $content === '' ? null : KnowledgeSnippet::locate(
                    $content,
                    $chunk?->charStart ?? 0,
                    $chunk?->charLength ?? mb_strlen($content),
                    $query,
                ),
                matchedChunks: $passages[$id]['count'] ?? 0,
                rrfScore: $ranked[$id] ?? 0.0,
            );
        }

        return $hits;
    }

    /**
     * The bases the results belong to, in ONE query — the global search spans them, so without this
     * a page of 25 results from 25 bases would be 25 lookups.
     *
     * @param  array<int, string>  $ids
     * @param  Collection<string, KnowledgeEntry>  $entries
     * @return array<string, KnowledgeBase>
     */
    private function bases(array $ids, Collection $entries, ?KnowledgeBase $base): array
    {
        if ($base !== null) {
            return [(string) $base->getKey() => $base];
        }

        $baseIds = [];

        foreach ($ids as $id) {
            $baseIds[(string) $entries->get($id)->knowledge_base_id] = true;
        }

        if ($baseIds === []) {
            return [];
        }

        return KnowledgeBase::query()
            ->whereKey(array_keys($baseIds))
            ->get()
            ->keyBy('id')
            ->all();
    }
}
