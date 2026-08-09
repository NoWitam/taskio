<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch;
use App\Modules\Knowledge\DTOs\ChunkMatch;
use App\Modules\Knowledge\DTOs\ChunkSimilarityQuery;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\SimilarityThreshold;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Variables\Support\MeterContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WHAT THE BASE ALREADY SAYS about the material somebody just pasted.
 *
 * Without this the composer can only write NEW entries, which is the failure mode that quietly ruins a
 * knowledge base: pasted meeting notes about the refund policy become a second entry about the refund
 * policy, and now the base has two answers to one question. Showing the composer the entries its
 * material touches is what lets it say "this belongs IN that one" — the shadow drafts of B11b.
 *
 * ------------------------------------------------------------------------------------------------
 * COST: ONE embedding, once per session
 *
 * The source text is embedded a single time on the `ai_embedding` channel; ranking happens inside
 * Postgres against the stored index, so the retrieval itself is free. The result is FROZEN onto the
 * session and never recomputed by a refinement — partly to avoid spending per instruction, but mostly
 * because the composer must keep judging its proposals against the evidence it first saw. A shadow
 * draft records the target revision it was written against; re-retrieving mid-session would silently
 * move that ground.
 *
 * FAIL-SOFT, always. Every way this can be unavailable — the budget, the kill switch, no vector
 * support, an unindexed base, a provider error — returns an EMPTY set, and the session proceeds exactly
 * as it did in B11a: create-only, no amendments. Retrieval makes the composer better; it must never be
 * the reason a user cannot use it at all.
 */
class KnowledgeDraftRetrievalService
{
    /** Embedding a query is embedding — the same channel the indexer and the searches spend on. */
    public const CHANNEL = KnowledgeIndexService::CHANNEL;

    public function __construct(
        private KnowledgeEmbedder $embedder,
        private KnowledgeSimilaritySearch $vectors,
        private MeteredAiCall $meter,
        private MeterContext $meterContext,
    ) {}

    /**
     * The entries this material touches, frozen for the session.
     *
     * `items` is the set the composer reads; `omitted` names the entries that cleared the bar but did
     * not fit the character ceiling, so the prompt can SAY they exist. An empty omission list is the
     * normal case.
     *
     * @return array{items: array<int, array{slug: string, title: string, current_revision_id: ?string, excerpt: string, truncated: bool}>, omitted: array<int, string>}
     */
    public function retrieve(KnowledgeBase $base, string $sourceText, ?string $actorType, ?string $actorId): array
    {
        $sourceText = trim($sourceText);

        if ($sourceText === '' || !config('knowledge.index.enabled') || !ChunkVector::supported()) {
            return self::nothing();
        }

        $vector = $this->embed($base, $sourceText, $actorType, $actorId);

        if ($vector === null) {
            return self::nothing();
        }

        $k = max(1, (int) config('knowledge.drafting.retrieval_k'));

        try {
            $matches = $this->vectors->topChunks($vector, new ChunkSimilarityQuery(
                baseId: (string) $base->getKey(),
                // Several passages of one entry cluster, so the lookup asks for more than it keeps.
                limit: $k * 4,
            ));
        } catch (Throwable $e) {
            // A dimension mismatch after a model swap is the realistic one. Class + SQLSTATE only: a
            // query message carries its bindings, and those are the base's own passages.
            Log::error('Knowledge drafting retrieval failed; composing without context.', [
                'knowledge_base_id' => $base->getKey(),
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);

            return self::nothing();
        }

        return $this->freeze($base, $sourceText, $matches, $k);
    }

    /**
     * The empty frozen set, in the one shape every caller stores.
     *
     * @return array{items: array<int, mixed>, omitted: array<int, string>}
     */
    public static function nothing(): array
    {
        return ['items' => [], 'omitted' => []];
    }

    /** The source text's vector, or null with the reason handled. Mirrors the retrieval seam's gate. */
    private function embed(KnowledgeBase $base, string $sourceText, ?string $actorType, ?string $actorId): ?array
    {
        $this->meterContext->setActor($actorType, $actorId);

        try {
            $this->meter->assertWithinBudget(self::CHANNEL);

            $result = $this->meter->meter(self::CHANNEL, fn () => $this->embedder->embed([$sourceText]));
        } catch (AiBudgetExceededException) {
            // Audible, but not fatal: the session composes without context.
            Log::warning('Knowledge drafting composed without context: the AI budget is exhausted.', [
                'knowledge_base_id' => $base->getKey(),
                'channel' => self::CHANNEL,
            ]);

            return null;
        } catch (Throwable $e) {
            Log::error('Knowledge drafting could not embed the source material.', [
                'knowledge_base_id' => $base->getKey(),
                'exception' => $e::class,
            ]);

            return null;
        } finally {
            $this->meterContext->clearActor();
        }

        $vector = $result->vectors[0] ?? null;

        return is_array($vector) && $vector !== [] ? $vector : null;
    }

    /**
     * Group the matched passages into ENTRIES, keep the ones that clear the length-aware bar, and
     * freeze the text the composer will read.
     *
     * The bar is the SHARED one ({@see SimilarityThreshold}), so "related enough to amend" means the
     * same thing here as it does when the graph draws an edge — including its short-text allowance,
     * without which a base of brief notes would retrieve nothing and the composer would be create-only
     * exactly where amendments matter most.
     *
     * ------------------------------------------------------------------------------------------
     * HOW MUCH OF AN ENTRY IS SHOWN depends on whether it can be AMENDED
     *
     * The strongest `max_shadow_per_session` matches are the only entries the composer may propose a
     * change to, so they are the only ones whose text it must be able to trust. They are shown WHOLE,
     * up to `amend_full_chars`. Everything below that line is context — "the base already covers this,
     * do not write it again" — and an excerpt answers that question completely.
     *
     * `truncated` is the load-bearing field. It records that the composer did NOT see all of an entry,
     * which is what {@see \App\Modules\Knowledge\Services\KnowledgeDraftService} turns into "this one
     * may only be appended to". Deriving it here rather than at laundering time is deliberate: this is
     * the only place that knows what was actually shown, and a rewrite is safe exactly when the model
     * read the whole document.
     *
     * @param  array<int, ChunkMatch>  $matches
     * @return array{items: array<int, array{slug: string, title: string, current_revision_id: ?string, excerpt: string, truncated: bool}>, omitted: array<int, string>}
     */
    private function freeze(KnowledgeBase $base, string $sourceText, array $matches, int $k): array
    {
        $sourceLength = mb_strlen($sourceText);
        $best = [];

        foreach ($matches as $match) {
            if (($best[$match->entryId] ?? -1.0) < $match->similarity) {
                $best[$match->entryId] = $match->similarity;
            }
        }

        arsort($best);

        if ($best === []) {
            return self::nothing();
        }

        $entries = KnowledgeEntry::query()
            ->whereKey(array_keys($best))
            ->where('knowledge_base_id', $base->getKey())
            ->get(['id', 'slug', 'title', 'content', 'current_revision_id'])
            ->keyBy('id');

        $excerptCap = max(1, (int) config('knowledge.drafting.retrieval_excerpt_chars'));
        $fullCap = max($excerptCap, (int) config('knowledge.drafting.amend_full_chars'));
        $totalCap = max(1, (int) config('knowledge.drafting.retrieval_total_chars'));
        $candidates = max(0, (int) config('knowledge.drafting.max_shadow_per_session'));

        $set = [];
        $omitted = [];
        $used = 0;
        $rank = 0;

        foreach ($best as $entryId => $score) {
            $entry = $entries->get($entryId);

            if ($entry === null || count($set) >= $k) {
                continue;
            }

            $content = (string) $entry->content;

            if ($score < SimilarityThreshold::for($sourceLength, mb_strlen($content))) {
                continue;
            }

            // AMENDMENT CANDIDATE or mere context — decided by rank among the qualifying matches,
            // which is the same order the amendment cap is spent in. Ranked BEFORE the size checks so
            // a candidate that has to be omitted does not silently promote a weaker match into a slot
            // the composer was never going to be allowed to amend.
            $cap = $rank < $candidates ? $fullCap : $excerptCap;
            $rank++;

            $shown = mb_strlen($content) > $cap ? mb_substr($content, 0, $cap) : $content;

            // The TOTAL bound is what actually protects the prompt: k × cap is an upper estimate
            // nobody should have to multiply out to reason about the block's size. An entry that does
            // not fit is NAMED rather than dropped in silence — see the omission marker in the prompt.
            if ($used + mb_strlen($shown) > $totalCap) {
                $omitted[] = (string) $entry->title;

                continue;
            }

            $used += mb_strlen($shown);

            $set[] = [
                'slug' => (string) $entry->slug,
                'title' => (string) $entry->title,
                // The revision the composer is about to READ — replayed as the optimistic-lock token
                // when a proposal against it is accepted.
                'current_revision_id' => $entry->current_revision_id,
                'excerpt' => $shown,
                // Whether anything was withheld. A rewrite of this entry is refused downstream.
                'truncated' => mb_strlen($shown) < mb_strlen($content),
            ];
        }

        return ['items' => $set, 'omitted' => $omitted];
    }
}
