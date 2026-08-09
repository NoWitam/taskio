<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch;
use App\Modules\Knowledge\DTOs\ChunkSimilarityQuery;
use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\MentionScanner;
use App\Modules\Knowledge\Support\SimilarityThreshold;
use App\Modules\Knowledge\Support\WikilinkParser;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Variables\Support\MeterContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WHAT THE PROPOSED DRAFTS WOULD DO TO THE BASE — computed on demand, stored nowhere.
 *
 * A reviewer looking at eight machine-written drafts cannot tell from the text alone whether they
 * duplicate what is already there, whether they connect to it, or whether they connect to each other.
 * This is the picture that answers that, in the SAME shape the base's own graph endpoint returns, so
 * the front end reuses one canvas instead of growing a second.
 *
 * ------------------------------------------------------------------------------------------------
 * IT WRITES NOTHING TO `knowledge_links`
 *
 * Every edge here is a PREVIEW of a relation that does not exist yet. Materialising it would put rows
 * in the graph for entries nobody has accepted — visible to the base's own graph, counted in its red
 * links, and needing a cleanup path for every way a session can end. The edges are computed and
 * returned; if the drafts are accepted, the ordinary indexing and link passes draw the real ones.
 *
 * ------------------------------------------------------------------------------------------------
 * COST, AND THE CACHE THAT MAKES IT BEARABLE
 *
 * ONE embedding batch per computation — every draft in one call, on `ai_embedding` — and then only
 * database work: ranking happens inside Postgres, draft-to-draft is a pairwise cosine over at most 28
 * pairs in PHP, and the deterministic edges are string matching.
 *
 * The result is cached on the session, keyed by a digest of every draft's TEXT plus the pipeline
 * versions. So re-opening the panel without touching anything costs ZERO embeddings, editing one draft
 * recomputes, and bumping `links.version` or `chunking.version` invalidates — the same self-healing
 * rule the entry digest uses, for the same reason: a heuristic change that only reached new sessions
 * would make the panel quietly disagree with the graph.
 *
 * FAIL-SOFT. Budget exhausted, kill switch off, no vector support, provider error: the panel still
 * renders, with the DETERMINISTIC edges (wikilinks, mentions, amendments) and `vector_skipped` saying
 * why the similarity ones are missing. Those are the edges a reviewer can verify by eye anyway; the
 * vector ones are the enrichment.
 */
class KnowledgeDraftRelationService
{
    public const CHANNEL = KnowledgeIndexService::CHANNEL;

    public const SKIPPED_BUDGET = 'budget';

    public const SKIPPED_DISABLED = 'disabled';

    public const SKIPPED_UNSUPPORTED = 'unsupported';

    public const SKIPPED_ERROR = 'error';

    /** Passages pulled per draft before they are grouped into entries. */
    private const CHUNK_TOP_K = 12;

    public function __construct(
        private KnowledgeEmbedder $embedder,
        private KnowledgeSimilaritySearch $vectors,
        private MeteredAiCall $meter,
        private MeterContext $meterContext,
        private MentionScanner $mentions,
        private WikilinkParser $wikilinks,
    ) {}

    /**
     * The relation picture for one session, from cache when nothing has moved.
     *
     * @return array<string, mixed>
     */
    public function relations(KnowledgeDraftSession $session): array
    {
        $drafts = $session->drafts()->with('targetsEntry')->get();
        $fingerprint = $this->fingerprint($drafts);

        $cached = is_array($session->relations_cache) ? $session->relations_cache : [];

        if (($cached['fingerprint'] ?? null) === $fingerprint && isset($cached['result'])) {
            // The PROPOSED typed relations are merged in AFTER the cache and never into it. They come
            // from `graph_ops`, which the composer rewrites on every run, while this cache is keyed on
            // the drafts' TEXT — a run that changed only the graph half would otherwise serve a stale
            // proposal from a fingerprint that legitimately had not moved.
            return $this->withProposedRelations($session, $cached['result']);
        }

        $base = $session->base()->firstOrFail();
        $result = $this->compute($session, $base, $drafts);

        if ($this->isCacheable($result)) {
            $session->forceFill(['relations_cache' => [
                'fingerprint' => $fingerprint,
                'result' => $result,
                // Namespaced beside the result rather than in a column of its own: it is derived from
                // the same embedding batch and invalidated by the same fingerprint, so splitting them
                // would mean two things to keep in step for no gain.
                'duplicates' => $result['duplicates'],
            ]])->save();
        }

        return $this->withProposedRelations($session, $result);
    }

    /**
     * Add the run's PROPOSED typed relations to the picture, as edges and nodes that do not exist yet.
     *
     * A reviewer looking at this panel is deciding whether to accept a proposal, and "Anna will be a
     * member of Acme" is exactly as much a part of that proposal as the prose is. Leaving it out meant
     * the graph half of an answer could only be read as raw JSON.
     *
     * ------------------------------------------------------------------------------------------------
     * THE DEPENDENCY FLAG IS THE POINT
     *
     * A relation may name an entity this same run is proposing to CREATE (`N1`). That relation cannot
     * be applied until that draft is accepted — and a reviewer who accepts the relation alone would get
     * a 422 that explains nothing about which of the two things they were missing. `depends_on_draft`
     * says so BEFORE the click: the client can grey the row, or accept the pair together.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withProposedRelations(KnowledgeDraftSession $session, array $result): array
    {
        $ops = $session->graphOps();

        if ($ops['graph_updates'] === [] && $ops['entities'] === []) {
            return $result + ['proposed_relations' => [], 'proposed_entities' => []];
        }

        $entities = $session->resolutionSet()['entities'];
        $titles = [];

        foreach ($entities as $entity) {
            if (is_string($entity['handle'] ?? null)) {
                $titles[$entity['handle']] = (string) ($entity['title'] ?? '');
            }
        }

        // The entities this run wants to CREATE. Nodes with no id, flagged as drafts, so the canvas can
        // draw them in the same pass as the existing ones.
        $proposedEntities = [];

        foreach ($ops['entities'] as $entity) {
            $ref = is_string($entity['ref'] ?? null) ? $entity['ref'] : null;

            if ($ref === null) {
                continue;
            }

            $titles[$ref] = (string) ($entity['title'] ?? '');

            $proposedEntities[] = [
                'id' => null,
                'handle' => $ref,
                'title' => $titles[$ref],
                'slug' => $entity['slug'] ?? null,
                'entry_type' => $entity['entry_type'] ?? null,
                'is_draft' => true,
            ];
        }

        $newRefs = array_column($ops['entities'], 'ref');
        $pairs = $session->graphOpPairs();
        $proposed = [];

        foreach ($ops['graph_updates'] as $index => $op) {
            $from = is_string($op['from'] ?? null) ? $op['from'] : null;
            $to = is_string($op['to'] ?? null) ? $op['to'] : null;

            $proposed[] = [
                // THE OPERATION KEY — server-owned, and the same string `accept` takes back and
                // `applied_ops` records. It is the ordinal position in the frozen `graph_ops`, which is
                // stable for exactly as long as it must be: the column is rewritten WHOLESALE by a run
                // and never mutated in place, so keys cannot shift while a reviewer is reading them —
                // and a refinement that renumbers everything invalidates the preview the client holds,
                // which `accept` then refuses with a 422 instead of applying the wrong operation. A
                // client computing its own key has no way to notice that has happened.
                'key' => 'graph:' . $index,
                // THE OTHER HALF of a replacement, or null. BOTH operations carry it, pointing at each
                // other, so a client groups them by reading one field instead of matching `replaces`
                // against every `end` itself. `accept` refuses a selection that splits a pair, so the UI
                // should offer them as ONE control rather than as two independent checkboxes.
                'pair_with' => $pairs['graph:' . $index] ?? null,
                'replaces' => $op['replaces'] ?? null,
                // Same shape the base graph uses for a relation edge, with a null id because nothing is
                // materialised — the client keys on the handles.
                'kind' => 'relation',
                'id' => null,
                'op' => $op['op'] ?? null,
                'from' => $from,
                'to' => $to,
                'relation' => $op['relation'] ?? null,
                'from_title' => $from === null ? null : ($titles[$from] ?? null),
                'to_title' => $to === null ? null : ($titles[$to] ?? null),
                'relation_type' => $op['type'] ?? null,
                'description' => $op['description'] ?? null,
                'properties' => $op['properties'] ?? [],
                'valid_from' => $op['valid_from'] ?? null,
                'valid_to' => $op['valid_to'] ?? null,
                // Which end (if any) is a draft that has to be accepted first. A LIST rather than a
                // boolean because both ends can be new, and a reviewer needs to know which drafts.
                'depends_on_draft' => array_values(array_intersect(
                    array_filter([$from, $to]),
                    $newRefs,
                )),
            ];
        }

        return $result + ['proposed_relations' => $proposed, 'proposed_entities' => $proposedEntities];
    }

    /**
     * Whether a computed picture is worth REMEMBERING — which turns on whether the thing that degraded
     * it can come back on its own.
     *
     * A result missing its similarity edges is still a useful answer, so it is returned either way. But
     * caching it makes the degradation STICKY: the cache is keyed on the drafts' text, so a panel
     * degraded by a momentary condition would stay degraded for the rest of the session's life, long
     * after the condition cleared, and the user would have no way to ask again short of editing a draft.
     *
     *   budget / error   TRANSIENT. A cap resets at the start of the month, an operator can raise it,
     *                    and a provider hiccup is over by the next click. Not cached — the next open
     *                    recomputes and gets the full picture.
     *   disabled / unsupported  NOT transient. The kill switch is off, or the connection has no vector
     *                    support; neither un-flips between two page views, and recomputing on every
     *                    open would buy nothing but a query per click.
     *
     * @param  array<string, mixed>  $result
     */
    private function isCacheable(array $result): bool
    {
        return !in_array($result['vector_skipped'] ?? null, [self::SKIPPED_BUDGET, self::SKIPPED_ERROR], true);
    }

    /**
     * The duplicate warnings alone, keyed by draft id — what the entry resource shows on a draft card.
     *
     * Read from the CACHE only: this must never trigger a computation, because it is called while
     * serializing a session and an embedding batch inside a resource would be a spend nobody asked for.
     * Absent cache simply means no warning yet.
     *
     * @return array<string, array{slug: string, title: string, score: float}>
     */
    public static function cachedDuplicates(KnowledgeDraftSession $session): array
    {
        $cached = is_array($session->relations_cache) ? $session->relations_cache : [];
        $duplicates = $cached['duplicates'] ?? [];

        return is_array($duplicates) ? $duplicates : [];
    }

    /**
     * What the cache is keyed on: every draft's identity and text, plus the pipeline versions.
     *
     * @param  \Illuminate\Support\Collection<int, KnowledgeEntry>  $drafts
     */
    private function fingerprint($drafts): string
    {
        $parts = $drafts
            ->map(fn (KnowledgeEntry $draft): string => $draft->id . ':' . hash('sha256', (string) $draft->title . "\0" . (string) $draft->content))
            ->sort()
            ->values()
            ->all();

        $parts[] = 'links=' . config('knowledge.links.version');
        $parts[] = 'chunking=' . config('knowledge.chunking.version');

        return hash('sha256', implode('|', $parts));
    }

    // ---- the computation -------------------------------------------------------

    /**
     * @param  \Illuminate\Support\Collection<int, KnowledgeEntry>  $drafts
     * @return array<string, mixed>
     */
    private function compute(KnowledgeDraftSession $session, KnowledgeBase $base, $drafts): array
    {
        [$vectors, $skipped] = $this->embedDrafts($session, $drafts);

        $nodes = [];   // id => ['entry' => KnowledgeEntry, 'is_draft' => bool]
        $edges = [];
        $duplicates = [];
        $amendedBy = [];   // target entry id => [['draft_id' => …], …]

        foreach ($drafts as $draft) {
            // A SHADOW contributes no node of its own: it is a proposed change to an entry that
            // already exists, and drawing it as a second circle beside its target would read as a
            // duplicate entry — the opposite of what it is.
            //
            // Nor is it an EDGE. An edge from a shadow to its target would have an endpoint that is not
            // in `nodes[]`, and the graph's invariant — every endpoint is a node — is one the canvas
            // relies on to lay itself out. So the pending amendment is recorded as an ANNOTATION on the
            // target node instead: same information, no exception to the rule, and it matches how it is
            // meant to be drawn anyway (a badge on the entry, not a line to nowhere).
            if (!$draft->isShadow()) {
                $nodes[(string) $draft->id] = ['entry' => $draft, 'is_draft' => true];

                continue;
            }

            if ($draft->targetsEntry !== null) {
                $targetId = (string) $draft->targetsEntry->id;

                $nodes[$targetId] = ['entry' => $draft->targetsEntry, 'is_draft' => false];
                // A list, not a single value: two proposals against one entry are not expected, but a
                // shape that cannot express them would have to be changed the day one appears.
                $amendedBy[$targetId][] = ['draft_id' => (string) $draft->id];
            }
        }

        $threshold = (float) config('knowledge.drafting.duplicate_warn_threshold');

        // --- draft ↔ existing entries (vector) ---
        foreach ($vectors as $draftId => $vector) {
            $draft = $drafts->firstWhere('id', $draftId);

            if ($draft === null) {
                continue;
            }

            foreach ($this->neighbours($base, $vector, (string) $draft->content) as $entryId => $match) {
                $nodes[(string) $entryId] = ['entry' => $match['entry'], 'is_draft' => false];
                $edges[] = $this->edge($draftId, $entryId, KnowledgeLinkSource::SIMILARITY->value, $match['score']);

                // The DUPLICATE warning is the same measurement read against a higher bar: "related to"
                // and "is this again" are different claims, so it has its own threshold. Only for
                // CREATE drafts — an amendment is supposed to resemble the entry it amends.
                if (!$draft->isShadow() && $match['score'] >= $threshold) {
                    $current = $duplicates[(string) $draftId]['score'] ?? -1.0;

                    if ($match['score'] > $current) {
                        $duplicates[(string) $draftId] = [
                            'slug' => (string) $match['entry']->slug,
                            'title' => (string) $match['entry']->title,
                            'score' => round($match['score'], 4),
                        ];
                    }
                }
            }
        }

        // --- draft ↔ draft (vector, pairwise in PHP: at most 8 drafts = 28 pairs) ---
        $ids = array_keys($vectors);

        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $a = $drafts->firstWhere('id', $ids[$i]);
                $b = $drafts->firstWhere('id', $ids[$j]);

                if ($a === null || $b === null || $a->isShadow() || $b->isShadow()) {
                    continue;
                }

                $score = $this->cosine($vectors[$ids[$i]], $vectors[$ids[$j]]);

                if ($score >= SimilarityThreshold::for(mb_strlen((string) $a->content), mb_strlen((string) $b->content))) {
                    $edges[] = $this->edge($ids[$i], $ids[$j], KnowledgeLinkSource::SIMILARITY->value, $score);
                }
            }
        }

        // --- deterministic edges (no AI, always present) ---
        $edges = array_merge($edges, $this->wikilinkEdges($base, $drafts, $nodes));
        $edges = array_merge($edges, $this->mentionEdges($base, $drafts, $nodes));

        return $this->payload($session, $nodes, $edges, $duplicates, $amendedBy, $skipped);
    }

    /**
     * Every draft embedded in ONE call, or nothing plus a reason.
     *
     * @param  \Illuminate\Support\Collection<int, KnowledgeEntry>  $drafts
     * @return array{0: array<string, array<int, float>>, 1: ?string}
     */
    private function embedDrafts(KnowledgeDraftSession $session, $drafts): array
    {
        if ($drafts->isEmpty()) {
            return [[], null];
        }

        if (!config('knowledge.index.enabled')) {
            return [[], self::SKIPPED_DISABLED];
        }

        if (!ChunkVector::supported()) {
            return [[], self::SKIPPED_UNSUPPORTED];
        }

        $texts = $drafts->map(
            fn (KnowledgeEntry $draft): string => trim((string) $draft->title) . "\n" . (string) $draft->content,
        )->values()->all();

        $this->meterContext->setActor($session->creator_type, $session->creator_id);

        try {
            $this->meter->assertWithinBudget(self::CHANNEL);

            $result = $this->meter->meter(self::CHANNEL, fn () => $this->embedder->embed($texts));
        } catch (AiBudgetExceededException) {
            return [[], self::SKIPPED_BUDGET];
        } catch (Throwable $e) {
            Log::error('Knowledge draft relations could not embed the drafts.', [
                'session_id' => $session->getKey(),
                'exception' => $e::class,
            ]);

            return [[], self::SKIPPED_ERROR];
        } finally {
            $this->meterContext->clearActor();
        }

        $vectors = [];

        foreach ($drafts->values() as $index => $draft) {
            $vector = $result->vectors[$index] ?? null;

            if (is_array($vector) && $vector !== []) {
                $vectors[(string) $draft->id] = $vector;
            }
        }

        return [$vectors, null];
    }

    /**
     * The existing entries one draft resembles, best score per entry, above the LENGTH-AWARE bar.
     *
     * Drafts cannot appear among the candidates: they are never indexed, so they own no chunks.
     *
     * @param  array<int, float>  $vector
     * @return array<string, array{entry: KnowledgeEntry, score: float}>
     */
    private function neighbours(KnowledgeBase $base, array $vector, string $draftContent): array
    {
        try {
            $matches = $this->vectors->topChunks($vector, new ChunkSimilarityQuery(
                baseId: (string) $base->getKey(),
                limit: self::CHUNK_TOP_K,
            ));
        } catch (Throwable $e) {
            Log::error('Knowledge draft relations lookup failed.', [
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]);

            return [];
        }

        $best = [];

        foreach ($matches as $match) {
            if (($best[$match->entryId] ?? -1.0) < $match->similarity) {
                $best[$match->entryId] = $match->similarity;
            }
        }

        if ($best === []) {
            return [];
        }

        $entries = KnowledgeEntry::query()
            ->whereKey(array_keys($best))
            ->where('knowledge_base_id', $base->getKey())
            ->get()
            ->keyBy('id');

        $out = [];
        $draftLength = mb_strlen($draftContent);

        foreach ($best as $entryId => $score) {
            $entry = $entries->get($entryId);

            if ($entry !== null && $score >= SimilarityThreshold::for($draftLength, mb_strlen((string) $entry->content))) {
                $out[(string) $entryId] = ['entry' => $entry, 'score' => $score];
            }
        }

        return $out;
    }

    /**
     * `[[wikilinks]]` the drafts WRITE — between themselves and at existing entries.
     *
     * Resolved against the FINAL slugs (de-collision has already run), so the preview shows the links
     * that would exist after acceptance rather than the ones the model typed.
     *
     * @param  \Illuminate\Support\Collection<int, KnowledgeEntry>  $drafts
     * @param  array<string, array{entry: KnowledgeEntry, is_draft: bool}>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function wikilinkEdges(KnowledgeBase $base, $drafts, array &$nodes): array
    {
        $bySlug = [];

        foreach ($drafts as $draft) {
            if (!$draft->isShadow()) {
                $bySlug[(string) $draft->slug] = $draft;
            }
        }

        $edges = [];

        foreach ($drafts as $draft) {
            if ($draft->isShadow()) {
                continue; // a shadow's text belongs to its target, whose own links are already real
            }

            foreach ($this->wikilinks->targets((string) $draft->content) as $slug) {
                $target = $bySlug[$slug] ?? KnowledgeEntry::query()
                    ->where('knowledge_base_id', $base->getKey())
                    ->where('slug', $slug)
                    ->first();

                if ($target === null) {
                    continue; // a ghost: reported separately, never as an edge
                }

                $nodes[(string) $target->id] ??= ['entry' => $target, 'is_draft' => $target->isDraft()];
                $edges[] = $this->edge($draft->id, $target->id, KnowledgeLinkSource::WIKILINK->value);
            }
        }

        return $edges;
    }

    /**
     * Names the drafts MENTION — of each other and of existing entries — through the same scanner the
     * materialised mention layer uses, so the preview and the eventual edges agree.
     *
     * @param  \Illuminate\Support\Collection<int, KnowledgeEntry>  $drafts
     * @param  array<string, array{entry: KnowledgeEntry, is_draft: bool}>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function mentionEdges(KnowledgeBase $base, $drafts, array &$nodes): array
    {
        $candidates = [];
        $existing = [];

        foreach ($drafts as $draft) {
            if (!$draft->isShadow()) {
                $candidates[(string) $draft->id] = (string) $draft->title;
            }
        }

        foreach (KnowledgeEntry::query()->where('knowledge_base_id', $base->getKey())->limit(500)->get(['id', 'title', 'slug', 'status', 'stale_at', 'content']) as $entry) {
            $candidates[(string) $entry->id] = (string) $entry->title;
            $existing[(string) $entry->id] = $entry;
        }

        $edges = [];

        foreach ($drafts as $draft) {
            if ($draft->isShadow()) {
                continue;
            }

            $others = $candidates;
            unset($others[(string) $draft->id]);

            foreach ($this->mentions->scan((string) $draft->content, $others) as $targetId => $evidence) {
                $target = $drafts->firstWhere('id', $targetId) ?? ($existing[$targetId] ?? null);

                if ($target === null) {
                    continue;
                }

                $nodes[(string) $targetId] ??= ['entry' => $target, 'is_draft' => $target->isDraft()];
                $edges[] = $this->edge($draft->id, $targetId, KnowledgeLinkSource::MENTION->value, null, $evidence);
            }
        }

        return $edges;
    }

    // ---- shaping ---------------------------------------------------------------

    /**
     * @param  array<string, array{entry: KnowledgeEntry, is_draft: bool}>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     * @param  array<string, array{slug: string, title: string, score: float}>  $duplicates
     * @param  array<string, array<int, array{draft_id: string}>>  $amendedBy
     * @return array<string, mixed>
     */
    private function payload(KnowledgeDraftSession $session, array $nodes, array $edges, array $duplicates, array $amendedBy, ?string $skipped): array
    {
        $degrees = [];

        foreach ($edges as $edge) {
            $degrees[$edge['from']] = ($degrees[$edge['from']] ?? 0) + 1;
            $degrees[$edge['to']] = ($degrees[$edge['to']] ?? 0) + 1;
        }

        return [
            // No centre: this is an overview of a proposal, not a walk out from one entry.
            'center' => null,

            'nodes' => array_values(array_map(
                fn (array $node): array => [
                    'id' => (string) $node['entry']->id,
                    'slug' => $node['entry']->slug,
                    'title' => $node['entry']->title,
                    'status' => $node['entry']->status?->value,
                    'is_stale' => $node['entry']->isStale(),
                    'degree' => $degrees[(string) $node['entry']->id] ?? 0,
                    'distance' => null,
                    // The TWO additions to the base graph's node shape. `is_draft` says which circles
                    // are proposals; `amended_by` says this existing entry has a pending shadow draft
                    // against it — the badge that replaces what would otherwise have been an edge with
                    // a dangling endpoint. Always present (empty when none) so the node shape is
                    // uniform and a client never has to test for the key.
                    'is_draft' => $node['is_draft'],
                    'amended_by' => $amendedBy[(string) $node['entry']->id] ?? [],
                ],
                $nodes,
            )),

            'edges' => $edges,

            // A preview draws no ghosts: an unresolved `[[link]]` in a draft is not a red link in the
            // base until the draft is accepted, and showing it as one would report work as broken that
            // nobody has done yet.
            'ghosts' => [],

            'truncated' => ['hidden_nodes' => 0, 'hidden_edges' => 0],

            'duplicates' => $duplicates,

            // Null when the vector legs ran; a reason when the panel is showing deterministic edges only.
            'vector_skipped' => $skipped,
        ];
    }

    /** @param array{char_start: int, char_length: int}|null $evidence */
    private function edge(string $from, string $to, string $source, ?float $score = null, ?array $evidence = null): array
    {
        return [
            // A PREVIEW edge has no row, so no id — the client keys on the endpoint pair plus source.
            'id' => null,
            'from' => $from,
            'to' => $to,
            'source' => $source,
            'score' => $score === null ? null : round($score, 4),
            'evidence' => $evidence,
            'dismissed' => false,
            // Nothing here is materialised, so nothing here can be refused. Present so the shape
            // matches the base graph's edges exactly.
            'can_be_dismissed' => false,
        ];
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;

        foreach ($a as $index => $value) {
            $dot += $value * ($b[$index] ?? 0.0);
        }

        // Both embedders in this module normalize to unit length, so the dot product IS the cosine.
        return $dot;
    }
}
