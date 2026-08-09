<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch;
use App\Modules\Knowledge\DTOs\ChunkSimilarityQuery;
use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\SimilarityThreshold;
use Illuminate\Support\Facades\DB;

/**
 * Turns "these two passages are close" into a STORED edge of the knowledge graph.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY MATERIALISE AT ALL
 *
 * The obvious alternative is to compute related entries on read. It does not survive contact with the
 * product: the reader's "related" panel, the graph screen and the base overview all want this answer,
 * and computing it on read means an ANN query per view — or, worse, an embedding call per view for
 * anything that starts from a query rather than a stored vector. Storing it moves the whole cost onto
 * the re-index that was going to happen anyway, and makes a suggestion something a human can act on:
 * an edge is a row, so it can be dismissed, and a dismissal can be remembered.
 *
 * ------------------------------------------------------------------------------------------------
 * THE PASS, and what each rule is protecting
 *
 *   SOURCES are passages of at least `similarity.min_chunk_chars`, and so are candidates. A short
 *   passage is close to every other short passage for reasons of form rather than meaning ("## Cennik"
 *   plus one sentence), and one such row is enough to wire a base into a hairball. Applying the floor
 *   to both ends is what makes it mean something: the graph unions directions, so filtering only the
 *   source would still draw the edge from the other side.
 *
 *   The floor EXEMPTS a whole-entry passage — an entry that is a single chunk. The anti-boilerplate
 *   argument is about fragments, not about short documents, and applying it to both made a base of
 *   short notes draw no edges whatsoever. Exempted on both ends for the same symmetry reason the floor
 *   itself is.
 *
 *   THRESHOLD is high (0.86) because the costs are asymmetric. A suggestion that is wrong does not
 *   merely waste a click — it teaches the reader that this panel is noise, after which the right
 *   suggestions are ignored too. A missed suggestion costs nothing anyone can see.
 *
 *   ...but it is LENGTH-AWARE ({@see thresholdFor()}). That number was calibrated on long prose, and
 *   cosine does not mean the same thing for a one-sentence note: measured on dev, two obviously
 *   related short entries scored 0.6498. A pair with a short end is therefore judged against
 *   `similarity.threshold_short` instead. A second bar rather than a lower single one — dropping 0.86
 *   globally would buy short-text recall by wrecking precision on exactly the passages it was set for.
 *
 *   BEST PASSAGE PER TARGET. Two documents that overlap in six places are ONE relation, not six, so
 *   passage matches are grouped by target entry and the strongest one becomes the edge's `score` and
 *   its `evidence`. The evidence is the pair of ordinals — the stable citation address — so "why is
 *   this related?" is answerable by showing the two passages rather than by asking for trust.
 *
 *   CAP at `similarity.max_links`. An entry related to twenty others is an entry that should be split;
 *   drawing all twenty makes the graph unreadable and hides the three that matter.
 *
 * ------------------------------------------------------------------------------------------------
 * A DISMISSAL IS PERMANENT UNTIL IT IS UNDONE.
 *
 * This pass REPLACES the entry's machine-proposed edges (delete + insert rather than diff — the same
 * reasoning as the wikilink sync: a diff over five rows buys a class of drift bugs and saves nothing).
 * The one thing it must never touch is an edge a human dismissed. So dismissed rows are neither
 * deleted nor overwritten, and their targets are SKIPPED on insert — which the unique key
 * `[from_entry_id, target_slug, source]` then enforces for us: a bug that tried to re-propose a
 * dismissed edge would collide rather than quietly resurrect it. Without this, "no, these are not
 * related" would last exactly until the next time somebody edited a paragraph.
 *
 * ------------------------------------------------------------------------------------------------
 * COST. Nothing here spends: the source vectors are already stored, and
 * {@see KnowledgeSimilaritySearch::topNeighbours()} compares them inside Postgres. It is one indexed
 * lookup per qualifying passage on a background job, which is why the pass can afford to be exact
 * rather than sampled.
 */
class KnowledgeSimilarityLinker
{
    /**
     * Neighbours fetched per source passage, as a multiple of the link cap. Passages of ONE document
     * cluster, so a lookup limited to the cap could come back as five passages of the same entry and
     * propose a single edge; the multiple is what keeps distinct targets in view.
     */
    private const CANDIDATE_FACTOR = 3;

    public function __construct(
        private KnowledgeSimilaritySearch $vectors,
    ) {}

    /**
     * Recompute the `similarity` edges this entry DRAWS. Returns how many edges it now has.
     *
     * Safe to call on an entry that has vanished, has no vectors, or sits on a connection without
     * pgvector — all of which return 0 without writing anything.
     */
    public function link(string $entryId): int
    {
        $entry = KnowledgeEntry::query()->find($entryId);

        if ($entry === null || !ChunkVector::supported()) {
            return 0;
        }

        $best = $this->neighbours($entry);
        $targets = $this->resolve($entry, $best);

        return $this->store($entry, $targets);
    }

    /**
     * The strongest passage match against every OTHER entry of the base, keyed by target entry.
     *
     * @return array<string, array{score: float, from: int, to: int}>
     */
    private function neighbours(KnowledgeEntry $entry): array
    {
        $sources = $this->sourceChunks($entry);

        if ($sources === []) {
            return [];
        }

        $maxLinks = max(1, (int) config('knowledge.similarity.max_links'));
        $floor = (int) config('knowledge.similarity.min_chunk_chars');

        $query = new ChunkSimilarityQuery(
            baseId: (string) $entry->knowledge_base_id,
            limit: $maxLinks * self::CANDIDATE_FACTOR,
            excludeEntryId: (string) $entry->getKey(),
            minChunkChars: $floor,
        );

        $best = [];

        foreach ($sources as $source) {
            foreach ($this->vectors->topNeighbours((string) $source->getKey(), $query) as $match) {
                if ($match->similarity < $this->thresholdFor((int) $source->char_length, $match->charLength)) {
                    continue; // the list is ordered, but an explicit test beats an assumed order
                }

                if (($best[$match->entryId]['score'] ?? -1.0) >= $match->similarity) {
                    continue;
                }

                $best[$match->entryId] = [
                    'score' => $match->similarity,
                    'from' => (int) $source->ordinal,
                    'to' => $match->ordinal,
                ];
            }
        }

        uasort($best, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($best, 0, $maxLinks, preserve_keys: true);
    }

    /**
     * The bar THIS PAIR has to clear — the standard one, or the short-text one when either end is
     * short.
     *
     * Cosine is not comparable across lengths, and treating it as if it were is what made the graph
     * empty. A long passage averages many sentences into its vector, so two documents about one
     * subject land high; a one-sentence note has almost nothing to average, so the SAME relation lands
     * far lower. Measured on dev: two entries any reader calls related scored 0.6498 — nowhere near
     * the 0.86 that was calibrated on long prose, and nowhere near the unrelated band either.
     *
     * EITHER end being short is enough. A short note paired with a long article suffers the same
     * compression on one side, and requiring both ends to be short would leave exactly that pairing —
     * a note about a topic and the article about it — permanently unlinkable.
     *
     * Only the BAR moves. Which passages may be compared at all is a separate rule
     * ({@see sourceChunks()}), and the short bar is still far above what unrelated text scores.
     */
    private function thresholdFor(int $sourceLength, int $candidateLength): float
    {
        // Delegated to {@see SimilarityThreshold} since B11b: the drafting composer's context retrieval
        // judges the same kind of comparison, and two copies of this rule would drift apart the next
        // time the numbers are tuned.
        return SimilarityThreshold::for($sourceLength, $candidateLength);
    }

    /**
     * The passages of this entry that may PROPOSE an edge: carrying a vector from the model currently
     * in use (a vector from a previous model would score against today's index as noise — see
     * {@see \App\Modules\Knowledge\Support\ChunkCandidates}), and long enough to mean something.
     *
     * "Long enough" has an EXCEPTION, and it is the whole point of the rule: a WHOLE-ENTRY passage is
     * never too short. The floor is aimed at a two-line fragment of a long document, which resembles
     * every other two-line fragment for reasons of form; an entry that is two lines is a complete
     * thought, and the text that gets embedded carries its title besides. Without the exception a base
     * of short notes draws no edges at all — which is precisely how it was found.
     *
     * The count is read from the CHUNK TABLE rather than from `chunks_count`, because this runs
     * immediately after the indexer wrote those rows and the denormalized column is only guaranteed
     * correct once the run has settled.
     *
     * @return array<int, KnowledgeEntryChunk>
     */
    private function sourceChunks(KnowledgeEntry $entry): array
    {
        $chunks = KnowledgeEntryChunk::query()
            ->withoutEmbedding()
            ->where('knowledge_entry_id', $entry->getKey())
            ->where('embedding_model', (string) config('knowledge.embedding.model'))
            ->whereNotNull('embedding')
            ->orderBy('ordinal')
            ->get();

        if (KnowledgeEntryChunk::query()->where('knowledge_entry_id', $entry->getKey())->count() <= 1) {
            return $chunks->all();
        }

        $floor = (int) config('knowledge.similarity.min_chunk_chars');

        return $chunks
            ->filter(fn (KnowledgeEntryChunk $chunk): bool => (int) $chunk->char_length >= $floor)
            ->values()
            ->all();
    }

    /**
     * Attach each target's SLUG — the address an edge is stored against, which is also how a dismissal
     * keys. One query for the whole batch.
     *
     * @param  array<string, array{score: float, from: int, to: int}>  $best
     * @return array<int, array{id: string, slug: string, score: float, from: int, to: int}>
     */
    private function resolve(KnowledgeEntry $entry, array $best): array
    {
        if ($best === []) {
            return [];
        }

        $slugs = KnowledgeEntry::query()
            ->whereKey(array_keys($best))
            ->where('knowledge_base_id', $entry->knowledge_base_id)
            ->pluck('slug', 'id');

        $targets = [];

        foreach ($best as $id => $match) {
            $slug = $slugs->get($id);

            if (!is_string($slug)) {
                continue; // trashed or moved between the lookup and now
            }

            $targets[] = ['id' => (string) $id, 'slug' => $slug] + $match;
        }

        return $targets;
    }

    /**
     * Replace the entry's live similarity edges, leaving dismissed ones exactly as they are.
     *
     * @param  array<int, array{id: string, slug: string, score: float, from: int, to: int}>  $targets
     */
    private function store(KnowledgeEntry $entry, array $targets): int
    {
        return DB::transaction(function () use ($entry, $targets): int {
            $dismissed = KnowledgeLink::query()
                ->where('from_entry_id', $entry->getKey())
                ->ofSource(KnowledgeLinkSource::SIMILARITY)
                ->whereNotNull('dismissed_at')
                ->pluck('target_slug')
                ->all();

            KnowledgeLink::query()
                ->where('from_entry_id', $entry->getKey())
                ->ofSource(KnowledgeLinkSource::SIMILARITY)
                ->whereNull('dismissed_at')
                ->delete();

            $written = 0;

            foreach ($targets as $target) {
                if (in_array($target['slug'], $dismissed, true)) {
                    continue; // a human already said no to this pair
                }

                KnowledgeLink::create([
                    'knowledge_base_id' => $entry->knowledge_base_id,
                    'from_entry_id' => $entry->getKey(),
                    'to_entry_id' => $target['id'],
                    'target_slug' => $target['slug'],
                    'source' => KnowledgeLinkSource::SIMILARITY->value,
                    'score' => round($target['score'], 4),
                    'evidence' => [
                        'from_chunk_ordinal' => $target['from'],
                        'to_chunk_ordinal' => $target['to'],
                    ],
                ]);

                $written++;
            }

            return $written;
        });
    }
}
