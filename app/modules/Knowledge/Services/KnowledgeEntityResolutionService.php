<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch;
use App\Modules\Knowledge\DTOs\AmbiguousMention;
use App\Modules\Knowledge\DTOs\ChunkMatch;
use App\Modules\Knowledge\DTOs\ChunkSimilarityQuery;
use App\Modules\Knowledge\DTOs\ExtractedMention;
use App\Modules\Knowledge\DTOs\ResolutionSet;
use App\Modules\Knowledge\DTOs\ResolvedEntity;
use App\Modules\Knowledge\DTOs\ResolvedRelation;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\EntryAliases;
use App\Modules\Knowledge\Support\MentionScanner;
use App\Modules\Knowledge\Support\SimilarityThreshold;
use App\Modules\Knowledge\Support\WikilinkParser;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Variables\Support\MeterContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WHO AND WHAT the pasted material is about, matched against the entries that already exist.
 *
 * The problem, in the owner's own words: a note says "Łukasz", the base holds "Łukasz Barszcz", and
 * nothing in the product connects the two. {@see MentionScanner} cannot, because its rule is that every
 * word of a name appears consecutively — and aliases, the intended escape hatch, are empty on every
 * entry written before they existed.
 *
 * ------------------------------------------------------------------------------------------------
 * CHEAP BEFORE EXPENSIVE, AND THE ORDER IS THE DESIGN
 *
 * Each pass only sees what the ones before it could not answer:
 *
 *   (a) EXACT — slug, title, or a declared alias. Free, and it is most of the traffic in a base whose
 *       author writes consistently.
 *   (b) LEXICAL — the shared scanner, run in BOTH directions. Forward finds an entry named inside a
 *       longer mention ("spotkanie z Łukaszem Barszczem"); INVERSE finds a shorter mention naming a
 *       longer entry by scanning the ENTRY'S OWN NAME for the mention's words — which is precisely the
 *       "Łukasz" → "Łukasz Barszcz" case, and it is deterministic and free. Both directions are the
 *       same scanner with its arguments swapped, so neither can drift into a second heuristic.
 *   (c) EDIT DISTANCE — a typo or a dropped diacritic, bounded at `resolution.edit_distance`.
 *   (d) VECTORS — ONE batched embedding call for every name still unresolved, then a nearest-neighbour
 *       lookup per name against the stored index. One provider round-trip for the whole session, not
 *       one per mention: the embedder takes a batch, and paying forty times for what one call answers
 *       would make this layer cost more than the composition it exists to improve.
 *
 * ------------------------------------------------------------------------------------------------
 * THREE OUTCOMES, AND AMBIGUITY IS ONE OF THEM
 *
 * One candidate resolves. TWO OR MORE do not — they land in `ambiguous` WITH the candidates, because
 * writing a fact into the wrong Łukasz's entry is silent, plausible and unrecoverable, while asking is
 * one click. None at all lands in `unresolved`, and creates nothing: an entity minted from a mention
 * nobody reviewed is how a base fills with half-facts about proper nouns.
 *
 * ------------------------------------------------------------------------------------------------
 * FAIL-SOFT, ALWAYS
 *
 * Every way this can be unavailable — the kill switch, the budget, no vector support, a provider error,
 * a base bigger than the scan limit — degrades to a NARROWER answer with the reason recorded, never to
 * an exception. The composer worked before this layer existed and must keep working when it is absent.
 * The recorded reason is what stops "unresolved" from quietly meaning two different things: "the base
 * does not know this name" and "I did not look properly" are different answers, and reporting them
 * identically is how a user learns to distrust the feature.
 */
class KnowledgeEntityResolutionService
{
    /** Resolution embeds a query, and embedding is embedding — the indexer's own channel. */
    public const CHANNEL = KnowledgeIndexService::CHANNEL;

    public function __construct(
        private KnowledgeMentionExtractor $extractor,
        private KnowledgeEmbedder $embedder,
        private KnowledgeSimilaritySearch $vectors,
        private MeteredAiCall $meter,
        private MeterContext $meterContext,
        private MentionScanner $scanner,
    ) {}

    /**
     * Read the material, match what it names against the base, and freeze the answer.
     *
     * @param  int  $fullContentSlots  how many entities are shown their WHOLE text (the ones a run may
     *                                 amend). See {@see ResolvedEntity} for why that matters.
     */
    public function resolve(
        KnowledgeBase $base,
        string $sourceText,
        ?string $actorType,
        ?string $actorId,
        int $fullContentSlots,
    ): ResolutionSet {
        if (!config('knowledge.graph_extraction.enabled') || !config('knowledge.index.enabled')) {
            return ResolutionSet::empty(ResolutionSet::DEGRADED_DISABLED);
        }

        $extracted = $this->extract($base, $sourceText, $actorType, $actorId);
        $mentions = $extracted['mentions'];
        // THE FACT LIST rides along on the same call and is carried, untouched, into the frozen set.
        // Nothing here matches it against anything: it is evidence for a human, not a lookup key.
        $facts = $extracted['facts'];
        // WHO THE MATERIAL IS ABOUT, including a subject it never names — carried the same way, and
        // deliberately NOT resolved against the base. A protagonist is a subject that must GET an
        // entry; it is not a name looking for one.
        $protagonists = $extracted['protagonists'];

        [$entries, $scanTruncated] = $this->candidateEntries($base);

        $degraded = $this->extractionDegradation;

        if ($scanTruncated) {
            $degraded[] = ResolutionSet::DEGRADED_SCAN_LIMIT;
        }

        // (a)-(c): deterministic, free, and enough for most of a well-written base.
        $matches = [];
        $remaining = [];

        foreach ($mentions as $mention) {
            $found = $this->matchDeterministically($mention, $entries);

            if ($found === null) {
                $remaining[] = $mention;

                continue;
            }

            $matches[] = $found;
        }

        // (d) plus the TOPICAL leg, in ONE embedding call.
        //
        // The two legs answer different questions — resolution asks "which entries are NAMED here",
        // topical retrieval asks "which entries are ABOUT this" — and a base needs both: a note saying
        // "the refund policy changed" without a single proper noun names nothing and is squarely about
        // an entry that exists. Merging them is what stops the composer from writing a duplicate of it.
        //
        // They are one call because the embedder takes a batch, and paying twice for one round trip is
        // the whole reason these two passes were consolidated.
        [$vectorMatches, $remaining, $vectorDegradation, $topical] = $this->vectorPass($base, $remaining, $entries, $sourceText);

        $matches = array_merge($matches, $vectorMatches);
        $degraded = array_merge($degraded, $vectorDegradation);

        if ($matches === [] && $topical === [] && $mentions === []) {
            // Nothing named and nothing on topic. Either the material really is about nothing the base
            // holds, or the extraction call failed — {@see extract()} has already recorded which. The
            // FACTS still travel: a material can state plenty while naming nothing the base knows, and
            // the checklist is worth as much then as at any other time.
            return ResolutionSet::empty(...array_values(array_unique($degraded)))->withReading($facts, $protagonists);
        }

        return $this->freeze($base, $matches, $remaining, $topical, $fullContentSlots, array_values(array_unique($degraded)))
            ->withReading($facts, $protagonists);
    }

    // ---- phase one --------------------------------------------------------------

    /** @var array<int, string> */
    private array $extractionDegradation = [];

    /**
     * The names AND facts in the material, or empty lists with the reason recorded.
     *
     * @return array{mentions: array<int, ExtractedMention>, facts: array<int, ExtractedFact>}
     */
    private function extract(KnowledgeBase $base, string $sourceText, ?string $actorType, ?string $actorId): array
    {
        $this->extractionDegradation = [];

        try {
            // Gated explicitly rather than relying on the meter's own gate inside the call: a refusal
            // there is swallowed by the text seam and would arrive as an empty answer, indistinguishable
            // from "this document names nothing".
            $this->meter->assertWithinBudget(KnowledgeMentionExtractor::CHANNEL);
        } catch (AiBudgetExceededException) {
            Log::warning('Knowledge resolution skipped: the AI budget is exhausted.', [
                'knowledge_base_id' => $base->getKey(),
                'channel' => KnowledgeMentionExtractor::CHANNEL,
            ]);

            $this->extractionDegradation[] = ResolutionSet::DEGRADED_BUDGET;

            return KnowledgeMentionExtractor::nothing();
        }

        $extracted = $this->extractor->extract($sourceText, $actorType, $actorId);

        if ($extracted['mentions'] === []) {
            // Indistinguishable from the outside, and the honest thing is to say the pass did not
            // produce anything rather than to assert the document is nameless.
            $this->extractionDegradation[] = ResolutionSet::DEGRADED_EXTRACTION_FAILED;
        }

        return $extracted;
    }

    // ---- the deterministic ladder ------------------------------------------------

    /**
     * The base's entries, bounded — and whether the bound bit.
     *
     * @return array{0: Collection<int, KnowledgeEntry>, 1: bool}
     */
    private function candidateEntries(KnowledgeBase $base): array
    {
        $limit = max(1, (int) config('knowledge.resolution.lexical_scan_limit'));

        $total = KnowledgeEntry::query()->where('knowledge_base_id', $base->getKey())->count();

        $entries = KnowledgeEntry::query()
            ->where('knowledge_base_id', $base->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->limit($limit)
            // Bodies are NOT loaded here: this pass compares NAMES, and pulling 2000 entries' text —
            // up to 40 000 characters each — to compare titles would be tens of megabytes for nothing.
            // The winners' content is fetched by id in the freeze.
            ->get(['id', 'slug', 'title', 'aliases', 'entry_type']);

        return [$entries, $total > $limit];
    }

    /**
     * Passes (a), (b) and (c) for one mention.
     *
     * @param  Collection<int, KnowledgeEntry>  $entries
     * @return array{mention: ExtractedMention, candidates: array<int, array{entry: KnowledgeEntry, score: ?float}>, matched_by: string}|null
     */
    private function matchDeterministically(ExtractedMention $mention, Collection $entries): ?array
    {
        $needle = WikilinkParser::normalize($mention->text);

        if ($needle === '') {
            return null;
        }

        // (a) EXACT — slug, title or a declared alias, all compared through the same normalization the
        // module mints slugs with, so "Wieża Eiffla" and "wieza-eiffla" are one key.
        $exact = $entries->filter(function (KnowledgeEntry $entry) use ($needle): bool {
            foreach ($entry->mentionNames() as $name) {
                if (WikilinkParser::normalize($name) === $needle) {
                    return true;
                }
            }

            return WikilinkParser::normalize((string) $entry->slug) === $needle;
        });

        if ($exact->isNotEmpty()) {
            return $this->candidateSet($mention, $exact, 'exact');
        }

        // (b) LEXICAL, both directions — see the class docblock for why the inverse is the one that
        // solves the case this layer was built for.
        $lexical = $entries->filter(fn (KnowledgeEntry $entry): bool => $this->namesEachOther($mention->text, $entry));

        if ($lexical->isNotEmpty()) {
            return $this->candidateSet($mention, $lexical, 'lexical');
        }

        // (c) EDIT DISTANCE — a typo, a dropped diacritic. Bounded, and deliberately last of the free
        // passes: it is the one that can reach a genuinely different name.
        $threshold = max(0, (int) config('knowledge.resolution.edit_distance'));
        $near = $entries->filter(fn (KnowledgeEntry $entry): bool => $this->withinEditDistance($needle, $entry, $threshold));

        if ($near->isNotEmpty()) {
            return $this->candidateSet($mention, $near, 'edit_distance');
        }

        return null;
    }

    /**
     * Whether the mention and the entry name each other, in either direction.
     *
     * ONE scanner, called twice with its arguments swapped, rather than two heuristics: forward asks
     * "does this mention contain the entry's name", inverse asks "does the entry's name contain the
     * mention". The inverse is what matches a first name against a full name, and it inherits the
     * scanner's own guards for free — a mention that reduces to nothing, or to one short word, is not
     * scanned for at all, which is what stops "rok" from matching every entry with a date in it.
     */
    private function namesEachOther(string $mentionText, KnowledgeEntry $entry): bool
    {
        $names = $entry->mentionNames();

        if ($this->scanner->scan($mentionText, ['candidate' => $names]) !== []) {
            return true;
        }

        foreach ($names as $name) {
            if ($this->scanner->scan($name, ['mention' => $mentionText]) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any of the entry's names is within $threshold single-character edits of the mention.
     *
     * Compared on the NORMALIZED (transliterated, lowercased) forms, so a missing diacritic costs
     * nothing rather than one edit per accented letter — otherwise "Lukasz" against "Łukasz" would eat
     * the whole budget on spelling the same name correctly.
     */
    private function withinEditDistance(string $needle, KnowledgeEntry $entry, int $threshold): bool
    {
        if ($threshold <= 0) {
            return false;
        }

        foreach ($entry->mentionNames() as $name) {
            $candidate = WikilinkParser::normalize($name);

            // levenshtein() is byte-based and refuses long inputs; the normalized forms are ASCII, and
            // a "name" past 255 characters is not a name.
            if ($candidate === '' || strlen($candidate) > 255 || strlen($needle) > 255) {
                continue;
            }

            // A short name is all budget and no signal: five edits turns "Ala" into anything.
            if (mb_strlen($candidate) <= $threshold || mb_strlen($needle) <= $threshold) {
                continue;
            }

            if (levenshtein($needle, $candidate) <= $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, KnowledgeEntry>  $candidates
     * @return array{mention: ExtractedMention, candidates: array<int, array{entry: KnowledgeEntry, score: ?float}>, matched_by: string}
     */
    private function candidateSet(ExtractedMention $mention, Collection $candidates, string $matchedBy): array
    {
        // The KIND HINT is a tie-breaker and nothing more: it only ever NARROWS a set that already
        // matched by name, and only when the narrowing leaves something. A hint that eliminates every
        // candidate is a wrong hint, and deferring to it would turn a good match into an unresolved
        // name on the strength of a guess.
        if ($mention->kind !== null && $candidates->count() > 1) {
            $narrowed = $candidates->filter(
                fn (KnowledgeEntry $entry): bool => $entry->entry_type === $mention->kind,
            );

            if ($narrowed->isNotEmpty()) {
                $candidates = $narrowed;
            }
        }

        return [
            'mention' => $mention,
            'candidates' => $candidates->map(
                static fn (KnowledgeEntry $entry): array => ['entry' => $entry, 'score' => null],
            )->values()->all(),
            'matched_by' => $matchedBy,
        ];
    }

    // ---- the vector pass ---------------------------------------------------------

    /**
     * Pass (d): ONE embedding call for every unresolved name, then a lookup per name.
     *
     * @param  array<int, ExtractedMention>  $mentions
     * @param  Collection<int, KnowledgeEntry>  $entries
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, ExtractedMention>, 2: array<int, string>}
     */
    private function vectorPass(KnowledgeBase $base, array $mentions, Collection $entries, string $sourceText): array
    {
        if (!ChunkVector::supported()) {
            return [[], $mentions, [ResolutionSet::DEGRADED_VECTORS_UNSUPPORTED], []];
        }

        // ONE batch: every open name AND the source text. The source rides last so its vector is
        // recoverable by index without a second call — that single round trip is what the whole
        // consolidation buys.
        $vectors = $this->embed($base, $mentions, $sourceText);

        if ($vectors === null) {
            return [[], $mentions, [ResolutionSet::DEGRADED_EMBEDDING_FAILED], []];
        }

        $byId = $entries->keyBy('id');
        $matched = [];
        $open = [];

        foreach ($mentions as $index => $mention) {
            $vector = $vectors[$index] ?? null;

            if (!is_array($vector) || $vector === []) {
                $open[] = $mention;

                continue;
            }

            $candidates = $this->nearestEntries($base, $mention, $vector, $byId);

            if ($candidates === []) {
                $open[] = $mention;

                continue;
            }

            $matched[] = [
                'mention' => $mention,
                'candidates' => $candidates,
                'matched_by' => 'vector',
            ];
        }

        $sourceVector = $vectors[count($mentions)] ?? null;

        return [$matched, $open, [], $this->topicalEntries($base, $sourceText, $sourceVector)];
    }

    /**
     * Entries the material is ABOUT, whether or not it names them.
     *
     * The other half of the merge, and the reason it exists: "the refund policy changed" names nothing
     * and is squarely about an entry that already exists. Without this leg a document written without
     * proper nouns would arrive at the composer with no context at all, and the composer would write a
     * second refund-policy entry — which is the exact failure the whole layer is here to prevent.
     *
     * Bounded by `retrieval.chunk_top_k` and by the same LENGTH-AWARE bar the graph draws edges at, so
     * "related enough to be shown" means one thing across the module.
     *
     * @param  array<int, float>|null  $vector
     * @return array<int, string> entry ids, best first
     */
    private function topicalEntries(KnowledgeBase $base, string $sourceText, ?array $vector): array
    {
        if (!is_array($vector) || $vector === []) {
            return [];
        }

        try {
            $matches = $this->vectors->topChunks($vector, new ChunkSimilarityQuery(
                baseId: (string) $base->getKey(),
                limit: max(1, (int) config('knowledge.retrieval.chunk_top_k')),
            ));
        } catch (Throwable $e) {
            Log::error('Knowledge topical lookup failed; composing with named entities only.', [
                'knowledge_base_id' => $base->getKey(),
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);

            return [];
        }

        $sourceLength = mb_strlen($sourceText);
        $best = [];

        foreach ($matches as $match) {
            if ($match->similarity < SimilarityThreshold::for($sourceLength, mb_strlen($match->content))) {
                continue;
            }

            if (($best[$match->entryId] ?? -1.0) < $match->similarity) {
                $best[$match->entryId] = $match->similarity;
            }
        }

        arsort($best);

        return array_map('strval', array_keys($best));
    }

    /**
     * Every unresolved name in ONE provider call — the whole point of doing this pass in a batch.
     *
     * @param  array<int, ExtractedMention>  $mentions
     * @return array<int, array<int, float>>|null vectors in input order, or null with the reason handled
     */
    private function embed(KnowledgeBase $base, array $mentions, string $sourceText): ?array
    {
        // The name PLUS its context: "Łukasz" alone is almost no signal for an embedding, while
        // "Łukasz — spotkanie z Łukaszem o cenniku" carries the subject matter that makes a
        // nearest-neighbour lookup mean something.
        $texts = array_map(
            static fn (ExtractedMention $mention): string => trim($mention->text . ' — ' . $mention->context),
            $mentions,
        );

        // The SOURCE goes in the same batch, last, so the topical leg costs no extra round trip.
        $texts[] = trim($sourceText);

        try {
            $this->meter->assertWithinBudget(self::CHANNEL);

            $result = $this->meter->meter(self::CHANNEL, fn () => $this->embedder->embed($texts));
        } catch (AiBudgetExceededException) {
            Log::warning('Knowledge resolution ran without vectors: the AI budget is exhausted.', [
                'knowledge_base_id' => $base->getKey(),
                'channel' => self::CHANNEL,
            ]);

            return null;
        } catch (Throwable $e) {
            // Class only: a provider message can carry the input, and the input is the user's material.
            Log::error('Knowledge resolution could not embed its mentions.', [
                'knowledge_base_id' => $base->getKey(),
                'exception' => $e::class,
            ]);

            return null;
        }

        return $result->vectors;
    }

    /**
     * The entries whose passages are nearest this name, above the length-aware bar.
     *
     * @param  Collection<string, KnowledgeEntry>  $byId
     * @return array<int, array{entry: KnowledgeEntry, score: float}>
     */
    private function nearestEntries(KnowledgeBase $base, ExtractedMention $mention, array $vector, Collection $byId): array
    {
        try {
            $matches = $this->vectors->topChunks($vector, new ChunkSimilarityQuery(
                baseId: (string) $base->getKey(),
                // Several passages of one entry cluster, so the lookup asks for more than it keeps.
                limit: 12,
            ));
        } catch (Throwable $e) {
            Log::error('Knowledge resolution lookup failed.', [
                'knowledge_base_id' => $base->getKey(),
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);

            return [];
        }

        $needle = mb_strlen($mention->text . $mention->context);
        $best = [];

        foreach ($matches as $match) {
            /** @var ChunkMatch $match */
            if ($match->similarity < SimilarityThreshold::for($needle, mb_strlen($match->content))) {
                continue;
            }

            if (($best[$match->entryId] ?? -1.0) < $match->similarity) {
                $best[$match->entryId] = $match->similarity;
            }
        }

        arsort($best);

        $candidates = [];

        foreach ($best as $entryId => $score) {
            // The scan set is the bounded one, so a hit outside it is re-read by id rather than
            // dropped — otherwise the vector pass would inherit the lexical pass's cap for no reason.
            $entry = $byId->get($entryId) ?? KnowledgeEntry::query()
                ->where('knowledge_base_id', $base->getKey())
                ->whereKey($entryId)
                ->first(['id', 'slug', 'title', 'aliases', 'entry_type']);

            if ($entry !== null) {
                $candidates[] = ['entry' => $entry, 'score' => (float) $score];
            }
        }

        return $candidates;
    }

    // ---- freezing ----------------------------------------------------------------

    /**
     * Turn the matches into the stored answer: handles minted, content and relations attached, the
     * character ceiling applied, everything that did not fit NAMED.
     *
     * THE MERGE HAPPENS HERE, and the ORDER of `$byEntry` is the cut priority: an entity that is
     * NAMED comes before one that is merely on topic, so when the character ceiling bites it is the
     * topical tail that is dropped and reported. Named entities are also the ones the amendment slots
     * are spent on — a proposal to change an entry the material actually names is the one worth being
     * able to make.
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @param  array<int, ExtractedMention>  $unresolved
     * @param  array<int, string>  $topical  entry ids the material is ABOUT, best first
     * @param  array<int, string>  $degraded
     */
    private function freeze(
        KnowledgeBase $base,
        array $matches,
        array $unresolved,
        array $topical,
        int $fullContentSlots,
        array $degraded,
    ): ResolutionSet {
        // Several mentions may land on ONE entry ("Łukasz" and "Ł. Barszcz"). That is one entity with
        // two pieces of evidence, not two entities — merging here is what keeps a handle meaning an
        // ENTRY rather than a lookup.
        $byEntry = [];
        $ambiguous = [];

        foreach ($matches as $match) {
            /** @var array<int, array{entry: KnowledgeEntry, score: ?float}> $candidates */
            $candidates = $match['candidates'];

            if (count($candidates) > 1) {
                $ambiguous[] = $match;

                continue;
            }

            $entry = $candidates[0]['entry'];
            $id = (string) $entry->getKey();

            $byEntry[$id]['entry'] = $entry;
            $byEntry[$id]['mentions'][] = $match['mention']->text;
            $byEntry[$id]['matched_by'] ??= (string) $match['matched_by'];
        }

        // TOPICAL entries are appended AFTER the named ones and de-duplicated against them by id: an
        // entry that is both named and on topic is one entity with the stronger provenance, not two.
        foreach ($topical as $id) {
            if (isset($byEntry[$id])) {
                continue;
            }

            $byEntry[$id] = ['entry' => null, 'mentions' => [], 'matched_by' => 'topical'];
        }

        $entities = $this->buildEntities($base, $byEntry, $fullContentSlots);

        return new ResolutionSet(
            entities: $entities['entities'],
            // Handles are minted from the ENTITY set, so an ambiguous candidate that is also a
            // confidently resolved entity elsewhere is offered under the handle it already has —
            // otherwise a reviewer choosing it would be choosing a different-looking thing.
            ambiguous: $this->buildAmbiguous($ambiguous, $entities['handles']),
            unresolved: array_values($unresolved),
            omitted: $entities['omitted'],
            degraded: $degraded,
        );
    }

    /**
     * @param  array<string, array{entry: KnowledgeEntry, mentions: array<int, string>, matched_by: string}>  $byEntry
     * @return array{entities: array<int, ResolvedEntity>, handles: array<string, string>, omitted: array<int, string>}
     */
    private function buildEntities(KnowledgeBase $base, array $byEntry, int $fullContentSlots): array
    {
        if ($byEntry === []) {
            return ['entities' => [], 'handles' => [], 'omitted' => []];
        }

        $ids = array_keys($byEntry);

        // ONE query for the bodies — the scan deliberately did not load them.
        $full = KnowledgeEntry::query()
            ->whereKey($ids)
            ->where('knowledge_base_id', $base->getKey())
            // `current_revision_id` rides along because this set REPLACES the retrieval set once
            // extraction is on, and it is the optimistic-lock token the amendment path replays.
            ->get(['id', 'slug', 'title', 'content', 'aliases', 'entry_type', 'current_revision_id'])
            ->keyBy('id');

        $relations = $this->relationsFor($ids);

        // Handles are assigned BEFORE the ceiling is applied, so an entity that gets omitted for space
        // does not renumber the ones after it.
        $handles = [];
        $position = 0;

        foreach ($ids as $id) {
            $handles[$id] = 'E' . (++$position);
        }

        $excerptCap = max(1, (int) config('knowledge.drafting.retrieval_excerpt_chars'));
        $fullCap = max($excerptCap, (int) config('knowledge.drafting.amend_full_chars'));
        $totalCap = max(1, (int) config('knowledge.resolution.total_chars'));

        $entities = [];
        $omitted = [];
        $used = 0;
        $rank = 0;

        // relation id => handle. SHARED across entities, because a relation whose two ends are both in
        // this set is listed under BOTH of them — the same row, read from each side. Minting per listing
        // gave it two handles, so "end R4" and "end R7" were the same edge and nothing downstream could
        // tell. One row, one handle, read twice.
        $relationHandles = [];

        foreach ($ids as $id) {
            $entry = $full->get($id);

            if ($entry === null) {
                continue; // trashed between the match and here
            }

            $content = (string) $entry->content;
            $cap = $rank < $fullContentSlots ? $fullCap : $excerptCap;
            $rank++;

            $shown = mb_strlen($content) > $cap ? mb_substr($content, 0, $cap) : $content;

            if ($used + mb_strlen($shown) > $totalCap) {
                $omitted[] = (string) $entry->title;

                continue;
            }

            $used += mb_strlen($shown);

            $entities[] = new ResolvedEntity(
                handle: $handles[$id],
                slug: (string) $entry->slug,
                title: (string) $entry->title,
                aliases: EntryAliases::normalize($entry->aliases),
                entryType: $entry->entry_type?->value,
                mentions: array_values(array_unique($byEntry[$id]['mentions'])),
                content: $shown,
                truncated: mb_strlen($shown) < mb_strlen($content),
                relations: $this->describeRelations($relations[$id] ?? [], $id, $handles, $relationHandles),
                matchedBy: $byEntry[$id]['matched_by'],
                currentRevisionId: $entry->current_revision_id,
            );
        }

        return ['entities' => $entities, 'handles' => $handles, 'omitted' => $omitted];
    }

    /**
     * Every ACTIVE relation touching the resolved entities, grouped by entity. One query.
     *
     * Active only: a composer told what is true now is being helped, while one told every membership a
     * person ever held is being asked to work out which still applies — a judgement the base has
     * already made and recorded in `state`.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array<int, KnowledgeRelation>>
     */
    private function relationsFor(array $ids): array
    {
        $cap = max(1, (int) config('knowledge.resolution.relations_per_entity'));

        $rows = KnowledgeRelation::query()
            ->current()
            ->where(fn ($query) => $query->whereIn('from_entry_id', $ids)->orWhereIn('to_entry_id', $ids))
            ->with(['fromEntry:id,title,slug', 'toEntry:id,title,slug'])
            ->orderBy('relation_type')
            ->orderBy('id')
            ->get();

        $grouped = [];

        foreach ($rows as $relation) {
            foreach ([(string) $relation->from_entry_id, (string) $relation->to_entry_id] as $end) {
                if (!in_array($end, $ids, true) || count($grouped[$end] ?? []) >= $cap) {
                    continue;
                }

                $grouped[$end][] = $relation;
            }
        }

        return $grouped;
    }

    /**
     * One entity's relations, each read FROM THAT ENTITY'S SIDE.
     *
     * The SAME ROW KEEPS THE SAME HANDLE when it is listed under both of its ends: `$minted` is carried
     * across entities so the second listing reuses the first's handle rather than minting a rival one.
     * The direction and label still differ per side — that is the point of reading it from each end —
     * but "R4" names one edge in the whole set, which is what lets a later stage say "end R4".
     *
     * @param  array<int, KnowledgeRelation>  $relations
     * @param  array<string, string>  $handles
     * @param  array<string, string>  $minted  relation id => handle, mutated across calls
     * @return array<int, ResolvedRelation>
     */
    private function describeRelations(array $relations, string $entityId, array $handles, array &$minted): array
    {
        $described = [];

        foreach ($relations as $relation) {
            $outgoing = (string) $relation->from_entry_id === $entityId;
            $other = $outgoing ? $relation->toEntry : $relation->fromEntry;
            $otherId = $outgoing ? (string) $relation->to_entry_id : (string) $relation->from_entry_id;
            $relationId = (string) $relation->getKey();

            $minted[$relationId] ??= 'R' . (count($minted) + 1);

            $described[] = new ResolvedRelation(
                handle: $minted[$relationId],
                id: $relationId,
                type: (string) ($relation->relation_type?->value ?? ''),
                // The verb ALREADY read in this direction. Making the consumer flip it is how a graph
                // ends up confidently backwards rather than obviously broken.
                label: (string) ($outgoing
                    ? $relation->relation_type?->label()
                    : $relation->relation_type?->inverseLabel()),
                direction: $outgoing ? ResolvedRelation::DIRECTION_OUT : ResolvedRelation::DIRECTION_IN,
                otherHandle: $handles[$otherId] ?? null,
                otherTitle: (string) ($other?->title ?? ''),
                description: $relation->description,
                properties: is_array($relation->properties) ? $relation->properties : [],
                validFrom: $relation->valid_from?->toDateString(),
                validTo: $relation->valid_to?->toDateString(),
                state: (string) ($relation->state?->value ?? ''),
            );
        }

        return $described;
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @param  array<string, string>  $handles
     * @return array<int, AmbiguousMention>
     */
    private function buildAmbiguous(array $matches, array $handles): array
    {
        $ambiguous = [];
        $next = count($handles);

        foreach ($matches as $match) {
            $candidates = [];

            foreach ($match['candidates'] as $candidate) {
                /** @var KnowledgeEntry $entry */
                $entry = $candidate['entry'];
                $id = (string) $entry->getKey();

                // A candidate that is not already an entity still needs an address the model can name,
                // so it gets one from the same sequence — no id ever leaves this service.
                $handles[$id] ??= 'E' . (++$next);

                $candidates[] = [
                    'handle' => $handles[$id],
                    'slug' => (string) $entry->slug,
                    'title' => (string) $entry->title,
                    'entry_type' => $entry->entry_type?->value,
                    'score' => $candidate['score'] === null ? null : round((float) $candidate['score'], 4),
                ];
            }

            $ambiguous[] = new AmbiguousMention($match['mention'], $candidates);
        }

        return $ambiguous;
    }
}
