<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch;
use App\Modules\Knowledge\DTOs\ChunkMatch;
use App\Modules\Knowledge\DTOs\ChunkSimilarityQuery;
use App\Modules\Knowledge\DTOs\CompiledKnowledge;
use App\Modules\Knowledge\Enums\KnowledgeBindingMode;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeBinding;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\KnowledgeFence;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RETRIEVAL: the passages of a base that bear on the question at hand, as one fenced DATA block — and the
 * single entry point ({@see forBinding()}) every consumer uses, whatever mode its binding names.
 *
 * ------------------------------------------------------------------------------------------------
 * IT CANNOT FAIL. THAT IS THE FEATURE.
 *
 * Every way this path can go wrong — the kill switch is off, the connection has no vector support, the
 * workspace is over its AI cap, the provider errors, the base was never indexed, nothing matched — ends in
 * the SAME place: {@see KnowledgeCompiler::compileForBinding()}, which spends nothing and always has an
 * answer. A consumer therefore never sees an exception, never has to decide what to do about one, and
 * never runs with silently no knowledge because of an operational event it did not cause. What degrades is
 * PRECISION (the whole base, budget-capped, instead of the relevant slice), never availability.
 *
 * The inverse mistake is worth naming because it is the tempting one: propagating the budget exception
 * would let a consumer "handle" it, and every consumer would handle it slightly differently — which is how
 * one bot answers from an empty context, another fails its run, and a third silently retries the spend.
 *
 * ------------------------------------------------------------------------------------------------
 * ONE EMBEDDING PER READ, GATED BEFORE IT
 *
 * Exactly one metered call on the `ai_embedding` channel — the query's own vector — with the budget
 * asserted BEFORE it so an over-cap workspace never reaches the provider at all. Everything after that is
 * free: ranking happens inside Postgres against the HNSW index, and the one-hop expansion reads
 * MATERIALIZED link rows. That is what makes expansion worth doing at all — it widens the answer for the
 * price of a join, so a passage that is relevant BECAUSE of what it links to is reachable without a second
 * round of embeddings.
 *
 * ------------------------------------------------------------------------------------------------
 * ONLY `approved`, HERE TOO
 *
 * Deliberately stricter than the human search, for the reason given at length in {@see KnowledgeCompiler}:
 * this text is quoted to a model as fact. The status filter is applied to the vector query AND re-applied
 * when the entries are hydrated, because a chunk row survives an entry's status change until the entry is
 * re-indexed — so the vector leg alone can name a passage of an entry that was demoted to `draft` an hour
 * ago.
 */
class KnowledgeRetrievalService
{
    /** The same meter channel the indexer and the human search spend on: embedding a query is embedding. */
    public const CHANNEL = KnowledgeIndexService::CHANNEL;

    /** Upper bound on the edge rows one expansion reads — see {@see expand()}. */
    private const EXPANSION_SCAN = 200;

    public function __construct(
        private KnowledgeCompiler $compiler,
        private KnowledgeEmbedder $embedder,
        private KnowledgeSimilaritySearch $vectors,
        private MeteredAiCall $meter,
    ) {}

    /**
     * The knowledge a bound consumer should read, chosen by the binding's MODE. The one method a consumer
     * calls — the mode policy lives here rather than being re-implemented by each of them.
     *
     * $query is what the consumer is trying to do, in its own words (a task title plus a digest of the
     * conversation, for a bot). It is only used by retrieval; inline ignores it.
     */
    public function forBinding(KnowledgeBinding $binding, string $query, ?int $maxChars = null): ?CompiledKnowledge
    {
        $maxChars = $maxChars ?? (int) config('knowledge.inline_max_chars');

        return match ($binding->mode) {
            KnowledgeBindingMode::INLINE => $this->compiler->compileForBinding($binding, $maxChars),
            KnowledgeBindingMode::RAG => $this->retrieveForBinding($binding, $query, $maxChars),
            KnowledgeBindingMode::AUTO => $this->auto($binding, $query, $maxChars),
        };
    }

    /**
     * AUTO: compile first, and keep that answer when the base FITS.
     *
     * Compiling costs nothing, so trying it is free — and when the whole base fits the budget, inline is
     * not a compromise but the better answer: no embedding call, no chance of a relevant fact ranking
     * 13th, no dependency on the base having been indexed. Only a base that has outgrown the budget (i.e.
     * one that reports omissions) is worth paying to search.
     */
    private function auto(KnowledgeBinding $binding, string $query, int $maxChars): ?CompiledKnowledge
    {
        $inline = $this->compiler->compileForBinding($binding, $maxChars);

        if ($inline === null || $inline->omittedTitles === []) {
            return $inline;
        }

        return $this->retrieveForBinding($binding, $query, $maxChars);
    }

    /**
     * The passages closest to $query, one hop of links around them, packed under $maxChars — or the INLINE
     * compilation when retrieval cannot run or finds nothing.
     *
     * NEVER THROWS, and the whole body after the embedding is inside the guard rather than just the
     * provider call. That distinction is the difference between a claim and a fact: the lookup itself
     * fails in ways nothing above it can anticipate — the clearest being a `vector(1536)` column queried
     * with a 3072-wide vector after someone changes `knowledge.embedding.model`, which Postgres rejects
     * with "different vector dimensions" long after {@see ChunkVector::supported()} has said yes (it only
     * knows the DRIVER). Left unguarded that QueryException escapes into the consumer's own run — a whole
     * bot execution dying because a knowledge base was misconfigured, AFTER the embedding was paid for.
     */
    public function retrieveForBinding(KnowledgeBinding $binding, string $query, int $maxChars): ?CompiledKnowledge
    {
        $base = $binding->base()->first();

        if ($base === null) {
            return null;
        }

        $query = trim($query);

        // Nothing approved to retrieve: the fallback would return null anyway, and this way it does so
        // without buying a query vector first.
        if ($query === '' || $maxChars < 1 || !$this->compiler->hasApprovedEntries($base)) {
            return $this->compiler->compileForBinding($binding, $maxChars);
        }

        $vector = $this->embed($binding, $base, $query);
        $compiled = null;

        if ($vector !== null) {
            try {
                $compiled = $this->lookup($base, $vector, $maxChars);
            } catch (Throwable $e) {
                // The CLASS and the SQLSTATE, never the message. A QueryException interpolates its
                // BINDINGS into its message, and the bindings here are the base's own passages — so
                // logging it would copy knowledge content into the application log on every failure.
                // `report()` is deliberately not called for the same reason.
                Log::error('Knowledge retrieval failed after embedding; falling back to the inline compilation.', [
                    'knowledge_base_id' => $base->getKey(),
                    'knowledge_binding_id' => $binding->getKey(),
                    'exception' => $e::class,
                    'code' => $e->getCode(),
                ]);
            }
        }

        return $compiled ?? $this->compiler->compileForBinding($binding, $maxChars);
    }

    /**
     * The retrieval proper: rank, expand, hydrate, pack. Split out so the guard above wraps ALL of it —
     * a `return` from four different places inside one try block is how a path ends up outside it.
     *
     * Returns null for every "nothing to say" outcome, which the caller turns into the inline fallback:
     * an un-indexed base has no passages, and handing back an empty block would tell the model the
     * workspace knows nothing.
     *
     * @param  array<int, float>  $vector
     */
    private function lookup(KnowledgeBase $base, array $vector, int $maxChars): ?CompiledKnowledge
    {
        $matches = $this->vectors->topChunks($vector, new ChunkSimilarityQuery(
            baseId: (string) $base->getKey(),
            limit: max(1, (int) config('knowledge.retrieval.chunk_top_k')),
            statuses: [KnowledgeEntryStatus::APPROVED->value],
        ));

        if ($matches === []) {
            return null;
        }

        $passages = array_merge($matches, $this->expand($base, $matches));
        $entries = $this->entries($base, $passages);

        if ($entries->isEmpty()) {
            return null;
        }

        return $this->pack($base, $passages, $entries, $maxChars);
    }

    // ---- the one metered call -------------------------------------------------

    /**
     * The query's vector, or NULL with the reason logged. Mirrors the human search's vector leg — same
     * channel, same gate-before-spend, same refusal paths — because they are the same spend made for two
     * different readers, and letting them drift would mean one of them eventually stops honouring the
     * kill switch.
     *
     * @return array<int, float>|null
     */
    private function embed(KnowledgeBinding $binding, KnowledgeBase $base, string $query): ?array
    {
        if (!config('knowledge.index.enabled') || !ChunkVector::supported()) {
            return null;
        }

        try {
            $this->meter->assertWithinBudget(self::CHANNEL);

            $result = $this->meter->meter(self::CHANNEL, fn () => $this->embedder->embed([$query]));
        } catch (AiBudgetExceededException) {
            // AUDIBLE degradation. The consumer silently gets the inline compilation instead of the
            // relevant slice, and every symptom of that is a quality one — a bot answering vaguely,
            // an entry that "should have been found". Without a line here the only visible trace of a
            // reached cap is a bot that got worse, which nobody diagnoses as a budget event. Ids and a
            // reason only: no query text (it carries the consumer's task) and no amounts (the ledger
            // owns those).
            Log::warning('Knowledge retrieval fell back to inline: the AI budget is exhausted.', [
                'knowledge_base_id' => $base->getKey(),
                'knowledge_binding_id' => $binding->getKey(),
                'channel' => self::CHANNEL,
            ]);

            return null;
        } catch (Throwable $e) {
            // The provider's failure, never the query: a retrieval query carries task text and is not logged.
            Log::error('Knowledge retrieval could not embed the query.', [
                'knowledge_base_id' => $base->getKey(),
                'exception' => $e::class,
            ]);
            report($e);

            return null;
        }

        $vector = $result->vectors[0] ?? null;

        return is_array($vector) && $vector !== [] ? $vector : null;
    }

    // ---- one free hop ---------------------------------------------------------

    /**
     * The OPENING passage of entries one materialized link away from what matched.
     *
     * Direction is UNIONED. A similarity edge is written only on the side that was re-indexed most
     * recently, so A→B may exist while B→A does not; respecting direction would make "related" mean
     * something different depending on which of two entries the query happened to hit — the same relation,
     * present or absent by accident of indexing order. The graph view unions for exactly this reason, and
     * disagreeing with it here would make the neighbourhood a reader SEES differ from the one the bot READ.
     *
     * Only the first passage of each neighbour is pulled: the expansion is a POINTER ("there is also a
     * document about this"), not a second retrieval, and an entry's opening passage is the one that says
     * what it is about. Dismissed edges are excluded — a human said that relation is not one.
     *
     * @param  array<int, ChunkMatch>  $matches
     * @return array<int, ChunkMatch>
     */
    private function expand(KnowledgeBase $base, array $matches): array
    {
        $limit = max(0, (int) config('knowledge.retrieval.expansion_limit'));

        if ($limit === 0) {
            return [];
        }

        $seen = [];

        foreach ($matches as $match) {
            $seen[$match->entryId] = true;
        }

        $anchors = array_keys($seen);

        $neighbours = KnowledgeLink::query()
            ->where('knowledge_base_id', $base->getKey())
            ->whereNull('dismissed_at')
            ->whereNotNull('to_entry_id')
            ->where(fn ($query) => $query
                ->whereIn('from_entry_id', $anchors)
                ->orWhereIn('to_entry_id', $anchors))
            // Human-drawn edges first (a `wikilink` or `manual` link carries no score, and somebody MEANT
            // it), then the strongest machine suggestions. Stated explicitly rather than left to the
            // driver's NULL-ordering default, which happens to agree today and is not a contract.
            ->orderByRaw('score desc nulls first')
            ->orderBy('id')
            // A hop is a HINT, not a traversal: only `expansion_limit` neighbours are ever used, so
            // scanning a densely-linked base's whole edge table to pick five of them is waste. The order
            // above is applied by the database before this cut, so the best edges still win.
            ->limit(self::EXPANSION_SCAN)
            ->get(['from_entry_id', 'to_entry_id'])
            ->flatMap(fn (KnowledgeLink $link): array => [
                (string) $link->from_entry_id,
                (string) $link->to_entry_id,
            ])
            ->reject(fn (string $id): bool => isset($seen[$id]))
            ->unique()
            ->take($limit)
            ->values()
            ->all();

        if ($neighbours === []) {
            return [];
        }

        return KnowledgeEntryChunk::query()
            ->withoutEmbedding()
            ->whereIn('knowledge_entry_id', $neighbours)
            ->where('ordinal', 0)
            ->get()
            ->map(fn (KnowledgeEntryChunk $chunk): ChunkMatch => new ChunkMatch(
                chunkId: (string) $chunk->getKey(),
                entryId: (string) $chunk->knowledge_entry_id,
                ordinal: (int) $chunk->ordinal,
                headingPath: $chunk->heading_path,
                content: (string) $chunk->content,
                charStart: (int) $chunk->char_start,
                charLength: (int) $chunk->char_length,
                // Not a similarity at all: these were reached by a link, not by distance. Zero states that
                // plainly rather than inventing a score that would then be compared against real ones.
                similarity: 0.0,
            ))
            ->all();
    }

    // ---- assembly -------------------------------------------------------------

    /**
     * The entries the passages belong to — approved ones only, re-checked here because a chunk row
     * outlives its entry's status change (see the class docblock).
     *
     * @param  array<int, ChunkMatch>  $passages
     * @return Collection<string, KnowledgeEntry>
     */
    private function entries(KnowledgeBase $base, array $passages): Collection
    {
        $ids = array_values(array_unique(array_map(
            fn (ChunkMatch $match): string => $match->entryId,
            $passages,
        )));

        return KnowledgeEntry::query()
            ->whereKey($ids)
            ->where('knowledge_base_id', $base->getKey())
            ->where('status', KnowledgeEntryStatus::APPROVED->value)
            ->get(['id', 'title', 'current_revision_id'])
            ->keyBy('id');
    }

    /**
     * Passages best-first under the budget, each carrying its citation. A passage that does not fit is
     * dropped WHOLE, for the same reason an inline entry is (see {@see KnowledgeCompiler}) — and packing
     * continues, so one long passage in the middle of the ranking cannot end the block.
     *
     * @param  array<int, ChunkMatch>  $passages
     * @param  Collection<string, KnowledgeEntry>  $entries
     */
    private function pack(KnowledgeBase $base, array $passages, Collection $entries, int $maxChars): ?CompiledKnowledge
    {
        // The SAME header the inline block carries — a base's identity must not depend on which mode
        // produced the text, so the compiler owns it and this renders it. It is dropped when it alone
        // would blow the budget (a base may carry a long charter), exactly as the inline packer drops it:
        // the cap has to hold whatever the base's own prose happens to be.
        $header = $this->compiler->header($base);
        $headerFits = mb_strlen($header) <= $maxChars;
        $used = $headerFits ? mb_strlen($header) : 0;
        $parts = $headerFits ? [$header] : [];
        $usedEntries = [];
        $seenChunks = [];

        foreach ($passages as $passage) {
            $entry = $entries->get($passage->entryId);

            if ($entry === null || isset($seenChunks[$passage->chunkId])) {
                continue;
            }

            $block = $this->passageBlock($passage, $entry);
            $cost = mb_strlen($block) + ($used > 0 ? 2 : 0); // the "\n\n" that joins it to what precedes

            if ($used + $cost > $maxChars) {
                continue;
            }

            $used += $cost;
            $parts[] = $block;
            $seenChunks[$passage->chunkId] = true;
            $usedEntries[$passage->entryId] = $entry;
        }

        if ($usedEntries === []) {
            return null;
        }

        return new CompiledKnowledge(
            text: KnowledgeFence::block()->render(KnowledgeFence::LABEL, implode("\n\n", $parts)),
            baseId: (string) $base->getKey(),
            mode: KnowledgeBindingMode::RAG,
            entryIds: array_keys($usedEntries),
            revisionIds: array_values(array_filter(array_map(
                fn (KnowledgeEntry $entry): ?string => $entry->current_revision_id,
                array_values($usedEntries),
            ))),
        );
    }

    /**
     * One passage: a CITATION line then the text. The citation is the `(entryId, ordinal)` pair — the same
     * stable address a search result and a similarity edge use — so an answer that quotes a passage can be
     * traced back to the exact passage rather than to the top of a long document. The human-readable title
     * rides along because a uuid tells a model nothing about what it is reading.
     */
    private function passageBlock(ChunkMatch $passage, KnowledgeEntry $entry): string
    {
        $heading = filled($passage->headingPath)
            ? ' > ' . KnowledgeFence::sanitize((string) $passage->headingPath)
            : '';

        return '[' . $passage->entryId . '#' . $passage->ordinal . '] '
            . KnowledgeFence::sanitize((string) $entry->title) . $heading . "\n"
            . KnowledgeFence::sanitize($passage->content);
    }
}
