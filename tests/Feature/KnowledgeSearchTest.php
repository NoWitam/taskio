<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Variables\Models\AiUsageEvent;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * HYBRID SEARCH end to end (B2b): two legs, one fused ranking, and an answer even when half of it is
 * unavailable.
 *
 * The vector leg is made OBSERVABLE by aligning a specific chunk's stored vector with the vector the
 * fake embedder produces for a given query string ({@see alignWithQuery()}). That is what turns "the
 * semantic leg works" from an untestable claim into an assertion: the aligned passage scores 1.0 while
 * every other passage in the base scores near zero, so an entry that shares NO words with the query
 * must still come back — and if the vector leg were quietly dropped, it would not.
 */
class KnowledgeSearchTest extends TestCase
{
    use CreatesKnowledgeFixtures, RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private KnowledgeBase $base;

    private FakeKnowledgeEmbedder $embedder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);

        $this->embedder = new FakeKnowledgeEmbedder;
        $this->app->instance(KnowledgeEmbedder::class, $this->embedder);

        $this->base = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- helpers -------------------------------------------------------------------

    private function paragraph(string $subject, int $index): string
    {
        $sentences = [];

        for ($i = 1; $i <= 12; $i++) {
            $sentences[] = "Akapit {$index} zdanie {$i} o temacie {$subject} wypelniajacy ten fragment dokumentu.";
        }

        return implode(' ', $sentences);
    }

    private function document(string $subject, int $paragraphs = 4): string
    {
        $parts = [];

        for ($i = 1; $i <= $paragraphs; $i++) {
            $parts[] = $this->paragraph($subject, $i);
        }

        return implode("\n\n", $parts);
    }

    private function createEntry(
        string $title,
        string $content,
        ?KnowledgeBase $base = null,
        ?KnowledgeEntryStatus $status = null,
    ): KnowledgeEntry {
        return $this->makeEntry(
            base: $base ?? $this->base,
            title: $title,
            content: $content,
            status: $status ?? KnowledgeEntryStatus::APPROVED,
        );
    }

    /**
     * Make one of an entry's passages an EXACT vector match for `$query`, by storing the very vector
     * the fake embedder will produce when the search embeds that query.
     */
    private function alignWithQuery(KnowledgeEntry $entry, string $query): KnowledgeEntryChunk
    {
        $chunk = KnowledgeEntryChunk::query()
            ->withoutEmbedding()
            ->where('knowledge_entry_id', $entry->getKey())
            ->orderBy('ordinal')
            ->firstOrFail();

        ChunkVector::write(
            (string) $chunk->id,
            FakeKnowledgeEmbedder::vectorFor($query, (int) config('knowledge.embedding.dimensions')),
            (string) config('knowledge.embedding.model'),
            now(),
        );

        return $chunk;
    }

    private function search(string $query, array $params = [], ?KnowledgeBase $base = null)
    {
        $base ??= $this->base;

        return $this->getJson("/api/knowledge/bases/{$base->id}/search?" . http_build_query(['q' => $query] + $params));
    }

    /** @return array<int, string> */
    private function ids($response): array
    {
        return array_map(static fn (array $row): string => $row['id'], $response->json('data'));
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    // ---- the two legs --------------------------------------------------------------

    /**
     * The point of the meaning leg: an entry that shares no words with the query is still found.
     *
     * If the vector leg were silently dropped — an unbound contract, a swallowed exception, a filter
     * that excluded everything — every other search assertion would still pass, because the keyword
     * leg alone answers most of them. This one would not.
     */
    public function test_the_vector_leg_finds_an_entry_that_shares_no_words_with_the_query(): void
    {
        $semantic = $this->createEntry('Reklamacje towaru', $this->document('reklamacje'));
        $this->alignWithQuery($semantic, 'polityka zwrotow');

        $response = $this->search('polityka zwrotow')->assertOk();

        $this->assertContains((string) $semantic->id, $this->ids($response));
        $this->assertFalse($response->json('meta.vector_search_skipped'));
        $this->assertNull($response->json('meta.vector_search_reason'));
    }

    /** And the point of the keyword leg: a literal match is found whatever the vectors say. */
    public function test_the_lexical_leg_finds_a_literal_title_match(): void
    {
        $entry = $this->createEntry('Polityka zwrotow', $this->document('zwroty'));

        $response = $this->search('Polityka zwrotow')->assertOk();

        $this->assertContains((string) $entry->id, $this->ids($response));
    }

    /**
     * THE claim that justifies running two legs at all: an entry nothing has embedded yet is
     * findable. A search that only worked once a background job had caught up would be a search
     * people learn not to trust.
     */
    public function test_an_entry_that_was_never_indexed_is_still_findable(): void
    {
        config()->set('knowledge.index.enabled', false);
        $entry = $this->createEntry('Cennik hurtowy', $this->document('cennik'));
        config()->set('knowledge.index.enabled', true);

        $this->assertSame(0, KnowledgeEntryChunk::query()->where('knowledge_entry_id', $entry->id)->count());

        $response = $this->search('Cennik hurtowy')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', (string) $entry->id);

        $this->assertNotNull($row, 'an unindexed entry must be reachable through the keyword leg');
        // No passage to cite — but still a readable window on the body, in the same shape.
        $this->assertNull($row['matched_chunk']['ordinal']);
        $this->assertNull($row['matched_chunk']['score']);
        $this->assertSame(0, $row['matched_chunks_count']);
        $this->assertNotSame('', $row['matched_chunk']['snippet']);
    }

    // ---- fusion --------------------------------------------------------------------

    /**
     * An entry both legs like beats an entry only one of them likes. That is the entire value of
     * fusing, and it is the behaviour a naive "vector results, then lexical results" concatenation
     * would silently fail to produce.
     *
     * THE FIXTURE PINS EACH LEG'S RANK RATHER THAN HOPING FOR IT. `both` is titled with the query
     * VERBATIM, so the lexical leg's exact-title bucket puts it at rank 1 by rule instead of by
     * timestamp. That matters because RRF scores at adjacent ranks differ in the fourth decimal:
     * with `both` at lexical rank 2 the margin over a single-leg entry at ranks 2 and 3 collapses to
     * ~1e-5, and the test then measures the arithmetic of `1/(60+r)` rather than the claim in its
     * name. (It also used to make this test intermittently RED — see
     * {@see test_two_passages_with_identical_vectors_do_not_reshuffle_between_searches}.)
     */
    public function test_an_entry_matched_by_both_legs_outranks_one_matched_by_a_single_leg(): void
    {
        $query = 'gwarancja';

        $both = $this->createEntry('Gwarancja', $this->document('gwarancja'));
        $lexicalOnly = $this->createEntry('Gwarancja dodatkowa', $this->document('gwarancja'));
        $vectorOnly = $this->createEntry('Serwis pogwarancyjny', $this->document('serwis'));

        $this->alignWithQuery($both, $query);
        $this->alignWithQuery($vectorOnly, $query);

        $ids = $this->ids($this->search($query)->assertOk());

        $this->assertSame((string) $both->id, $ids[0], 'the entry both legs ranked first must lead');
        $this->assertContains((string) $lexicalOnly->id, $ids);
        $this->assertContains((string) $vectorOnly->id, $ids);
    }

    /** The same query twice must produce the same order, or a user can never go back to a result. */
    public function test_the_ranking_is_deterministic_across_identical_searches(): void
    {
        foreach (['Zwroty', 'Zwroty konsumenckie', 'Reklamacje', 'Wysylka'] as $index => $title) {
            $entry = $this->createEntry($title, $this->document('temat ' . $index));

            if ($index % 2 === 0) {
                $this->alignWithQuery($entry, 'zwroty');
            }
        }

        $first = $this->ids($this->search('zwroty')->assertOk());
        $second = $this->ids($this->search('zwroty')->assertOk());

        $this->assertSame($first, $second);
        $this->assertGreaterThan(1, count($first));
    }

    /**
     * THE TIE THE ORDER-BY DOES NOT BREAK (B16 regression).
     *
     * An embedding is a function of text, so two passages with the same text have byte-identical
     * vectors and exactly equal distance to every query — a duplicated document, a shared boilerplate
     * section, an entry copied to be edited. `ORDER BY embedding <=> ?` alone leaves their relative
     * order to the plan, and hybrid search consumes RANKS: a swap between two tied passages moves both
     * entries by one RRF rank and can change which result leads.
     *
     * The fixture is the degenerate case on purpose (two entries whose aligned passages hold the SAME
     * vector), because that is the only way to observe the tie-break at all. What is asserted is the
     * property, not a particular winner: repeated identical searches agree, and the winner is the one
     * the documented comparator names — entry id, ascending — so the Postgres path and the `php`
     * escape hatch cannot answer differently.
     */
    public function test_two_passages_with_identical_vectors_do_not_reshuffle_between_searches(): void
    {
        $query = 'identyczne pasaze';

        $first = $this->createEntry('Pierwszy', $this->document('kopia'));
        $second = $this->createEntry('Drugi', $this->document('kopia'));

        // ALIGNED IN REVERSE, and that is the whole fixture. Ids here are time-ordered, so without
        // this the physical row order and the id order would agree and an unbroken tie would look
        // stable — the test would pass against the very bug it exists to catch. Writing the SECOND
        // entry's vector first moves its row later in the heap than the first's, so an unordered scan
        // hands the two tied passages back in id-DESCENDING order.
        $this->alignWithQuery($second, $query);
        $this->alignWithQuery($first, $query);

        $expected = [(string) $first->id, (string) $second->id];
        sort($expected); // the comparator's own rule, restated rather than assumed from creation order

        foreach (['pgvector' => 'pgvector', 'php fallback' => 'php'] as $label => $store) {
            config()->set('knowledge.vector_store', $store);

            $runs = [
                $this->ids($this->search($query)->assertOk()),
                $this->ids($this->search($query)->assertOk()),
                $this->ids($this->search($query)->assertOk()),
            ];

            $this->assertSame($runs[0], $runs[1], "{$label}: the same query twice must agree");
            $this->assertSame($runs[1], $runs[2], "{$label}: and a third time");
            $this->assertSame($expected, array_slice($runs[0], 0, 2), "{$label}: ties break on the entry id");
        }
    }

    // ---- degradation ---------------------------------------------------------------

    /**
     * An exhausted AI budget must NOT break search. The keyword leg still answers, the response says
     * the meaning leg was skipped and why, and no provider call is made.
     */
    public function test_an_exhausted_budget_degrades_to_the_lexical_leg_instead_of_failing(): void
    {
        $entry = $this->createEntry('Polityka zwrotow', $this->document('zwroty'));

        $this->workspace->update(['ai_monthly_cost_cap' => 1.00]);
        app(TenantContext::class)->set($this->workspace->fresh());
        AiUsageEvent::create([
            'channel' => 'ai_text',
            'prompt_tokens' => 1000,
            'completion_tokens' => 0,
            'total_tokens' => 1000,
            'estimated_cost' => 5.00,
        ]);

        $this->embedder->reset();

        $response = $this->search('Polityka zwrotow')->assertOk();

        $this->assertTrue($response->json('meta.vector_search_skipped'));
        $this->assertSame('budget', $response->json('meta.vector_search_reason'));
        $this->assertSame(0, $this->embedder->calls, 'an over-cap workspace must never reach the provider');
        $this->assertContains((string) $entry->id, $this->ids($response));
    }

    /** The module's kill switch covers READS too: no query is embedded while it is off. */
    public function test_the_kill_switch_skips_the_vector_leg(): void
    {
        $entry = $this->createEntry('Polityka zwrotow', $this->document('zwroty'));

        config()->set('knowledge.index.enabled', false);
        $this->embedder->reset();

        $response = $this->search('Polityka zwrotow')->assertOk();

        $this->assertTrue($response->json('meta.vector_search_skipped'));
        $this->assertSame('disabled', $response->json('meta.vector_search_reason'));
        $this->assertSame(0, $this->embedder->calls);
        $this->assertContains((string) $entry->id, $this->ids($response));
    }

    /** A provider outage is not the user's problem either: keyword results, and an honest reason. */
    public function test_a_provider_failure_degrades_instead_of_erroring(): void
    {
        $entry = $this->createEntry('Polityka zwrotow', $this->document('zwroty'));

        $this->embedder->failure = new \RuntimeException('provider exploded');

        $response = $this->search('Polityka zwrotow')->assertOk();

        $this->assertTrue($response->json('meta.vector_search_skipped'));
        $this->assertSame('error', $response->json('meta.vector_search_reason'));
        $this->assertContains((string) $entry->id, $this->ids($response));
    }

    /** One search embeds the query ONCE, and it is billed on the embedding channel. */
    public function test_a_search_costs_exactly_one_metered_embedding_call(): void
    {
        $this->createEntry('Polityka zwrotow', $this->document('zwroty'));
        $this->embedder->reset();
        AiUsageEvent::query()->delete();

        $this->search('czego dotyczy polityka zwrotow')->assertOk();

        $this->assertSame(1, $this->embedder->calls);
        $this->assertSame(1, AiUsageEvent::query()->where('channel', 'ai_embedding')->count());
    }

    // ---- what a search may see ------------------------------------------------------

    /** Archived is "deliberately no longer current": out by default, in when asked for by name. */
    public function test_archived_entries_are_hidden_by_default_and_reachable_on_request(): void
    {
        $archived = $this->createEntry('Zwroty stara wersja', $this->document('zwroty'), null, KnowledgeEntryStatus::ARCHIVED);
        $live = $this->createEntry('Zwroty', $this->document('zwroty'));

        $default = $this->ids($this->search('Zwroty')->assertOk());
        $this->assertContains((string) $live->id, $default);
        $this->assertNotContains((string) $archived->id, $default);

        $explicit = $this->ids($this->search('Zwroty', ['status' => ['archived']])->assertOk());
        $this->assertContains((string) $archived->id, $explicit);
        $this->assertNotContains((string) $live->id, $explicit);
    }

    /** Drafts ARE searchable — most of what a person looks for is something they half-wrote. */
    public function test_draft_entries_are_searchable(): void
    {
        $draft = $this->createEntry('Notatka o zwrotach', $this->document('zwroty'), null, KnowledgeEntryStatus::DRAFT);

        $this->assertContains((string) $draft->id, $this->ids($this->search('Notatka o zwrotach')->assertOk()));
    }

    /**
     * A trashed entry must vanish from BOTH legs. Its chunks deliberately survive a soft delete (so a
     * restore costs nothing), which is exactly why the vector leg has to exclude it explicitly — this
     * pins the filter that does it.
     */
    public function test_a_trashed_entry_disappears_from_both_legs(): void
    {
        $entry = $this->createEntry('Polityka zwrotow', $this->document('zwroty'));
        $this->alignWithQuery($entry, 'polityka zwrotow');

        // Trashed through the service — the base cascade and the erasure command are what trash an
        // entry now, and both go through exactly this call.
        $this->trashEntry($entry);

        $this->assertGreaterThan(
            0,
            KnowledgeEntryChunk::query()->where('knowledge_entry_id', $entry->id)->whereRaw('embedding is not null')->count(),
            'the fixture must keep a live vector, or this test proves nothing',
        );

        $this->assertNotContains((string) $entry->id, $this->ids($this->search('polityka zwrotow')->assertOk()));
    }

    // ---- the citation ---------------------------------------------------------------

    /**
     * The offset contract, which every highlight in the UI depends on:
     * `mb_substr(content, char_start, char_length) === snippet`, and every highlight range is a real
     * occurrence of the query inside it. A snippet that carried its own ellipsis, or offsets measured
     * in bytes, would break here — and would land a highlight inside a Polish letter in production.
     */
    public function test_the_snippet_and_its_highlights_are_exact_offsets_into_the_entry_content(): void
    {
        $content = $this->document('zwroty') . "\n\nSzczegolne zasady dotycza reklamacji zagranicznych oraz przesylek kurierskich.";
        $entry = $this->createEntry('Zasady', $content);

        $response = $this->search('reklamacji')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', (string) $entry->id);

        $this->assertNotNull($row);

        $matched = $row['matched_chunk'];
        $this->assertSame(
            $matched['snippet'],
            mb_substr((string) $entry->content, $matched['char_start'], $matched['char_length']),
            'the snippet must be a verbatim slice of the entry content at the reported offsets',
        );

        $this->assertNotEmpty($matched['highlights']);

        foreach ($matched['highlights'] as [$start, $length]) {
            $this->assertGreaterThanOrEqual($matched['char_start'], $start);
            $this->assertLessThanOrEqual($matched['char_start'] + $matched['char_length'], $start + $length);
            $this->assertSame(
                'reklamacji',
                mb_strtolower(mb_substr((string) $entry->content, $start, $length)),
            );
        }
    }

    /** A cited passage names its ordinal and heading trail — the stable citation address. */
    public function test_a_vector_hit_cites_the_passage_it_matched(): void
    {
        $entry = $this->createEntry('Instrukcja', "# Montaz\n\n" . $this->document('montaz'));
        $chunk = $this->alignWithQuery($entry, 'jak zlozyc regal');

        $response = $this->search('jak zlozyc regal')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', (string) $entry->id);

        $this->assertNotNull($row);
        $this->assertSame((int) $chunk->ordinal, $row['matched_chunk']['ordinal']);
        $this->assertSame($chunk->heading_path, $row['matched_chunk']['heading_path']);
        $this->assertEqualsWithDelta(1.0, $row['matched_chunk']['score'], 1e-4);
        $this->assertGreaterThanOrEqual(1, $row['matched_chunks_count']);
    }

    // ---- scoping ---------------------------------------------------------------------

    /** The global endpoint spans bases and every result says which one it came from. */
    public function test_the_global_search_spans_bases_and_names_each_result_s_base(): void
    {
        $other = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id]);

        $here = $this->createEntry('Zwroty krajowe', $this->document('zwroty'));
        $there = $this->createEntry('Zwroty zagraniczne', $this->document('zwroty'), $other);

        $response = $this->getJson('/api/knowledge/search?' . http_build_query(['q' => 'Zwroty']))->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($rows->has((string) $here->id));
        $this->assertTrue($rows->has((string) $there->id));
        $this->assertSame((string) $this->base->id, $rows[(string) $here->id]['base']['id']);
        $this->assertSame((string) $other->id, $rows[(string) $there->id]['base']['id']);
        $this->assertSame($other->name, $rows[(string) $there->id]['base']['name']);
    }

    /** The base-scoped endpoint is the base filter: nothing from a sibling base leaks in. */
    public function test_the_base_scoped_search_returns_only_that_base(): void
    {
        $other = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id]);

        $here = $this->createEntry('Zwroty krajowe', $this->document('zwroty'));
        $there = $this->createEntry('Zwroty zagraniczne', $this->document('zwroty'), $other);

        $ids = $this->ids($this->search('Zwroty')->assertOk());

        $this->assertContains((string) $here->id, $ids);
        $this->assertNotContains((string) $there->id, $ids);
    }

    /** Another workspace's base is not reachable, and its entries are not in the global search. */
    public function test_search_cannot_reach_another_workspace(): void
    {
        $foreign = Workspace::factory()->create(['owner_id' => $this->user->id]);
        app(TenantContext::class)->set($foreign);
        $foreignBase = KnowledgeBase::factory()->create(['workspace_id' => $foreign->id]);
        $foreignEntry = KnowledgeEntry::factory()->create([
            'workspace_id' => $foreign->id,
            'knowledge_base_id' => $foreignBase->id,
            'title' => 'Zwroty obcego workspace',
        ]);
        app(TenantContext::class)->set($this->workspace);

        $this->search('Zwroty', [], $foreignBase)->assertNotFound();

        $ids = $this->ids($this->getJson('/api/knowledge/search?q=Zwroty')->assertOk());
        $this->assertNotContains((string) $foreignEntry->id, $ids);
    }

    // ---- the escape hatch -------------------------------------------------------------

    /**
     * The `php` vector store must return the same answer as Postgres. It exists so the module is not
     * wedged when the index is unavailable, and an escape hatch nobody has ever run is not one.
     */
    public function test_the_php_similarity_fallback_produces_the_same_hit(): void
    {
        $entry = $this->createEntry('Reklamacje towaru', $this->document('reklamacje'));
        $this->alignWithQuery($entry, 'polityka zwrotow');

        config()->set('knowledge.vector_store', 'php');

        $response = $this->search('polityka zwrotow')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', (string) $entry->id);

        $this->assertNotNull($row, 'the PHP fallback must find what pgvector finds');
        $this->assertEqualsWithDelta(1.0, $row['matched_chunk']['score'], 1e-4);
    }

    /**
     * B7 — THE FALLBACK'S REFUSAL TO MELT, exercised at the size where it applies.
     *
     * {@see \App\Modules\Knowledge\Support\PhpCosineSimilaritySearch::CANDIDATE_CAP} bounds how many
     * rows one lookup reads, because the fallback scores IN PROCESS: every candidate's 1536 floats are
     * pulled into PHP memory. Uncapped, the escape hatch you reach for when pgvector is unavailable
     * would take the worker down on exactly the base that needed it most — the failure mode where a
     * degraded mode is worse than no mode.
     *
     * The cap is a private constant with no config seam (deliberately — it is a safety floor, not a
     * tunable), so this seeds past it for real rather than lowering it. The fixture is built to be
     * CHEAP at that size: one bulk `insert … select generate_series`, and a sparse vector literal, so
     * the cost is in Postgres and not in 5 000 round trips.
     *
     * GUARDED behind KNOWLEDGE_HEAVY_TESTS=1, mirroring the TENANT_DB_TESTS gate on the tenant specs.
     * Not because it is slow (it is ~8s) but because of MEMORY: scoring 5 000 candidates in process is
     * the very thing the cap exists to bound, and it needs ~50 MB of headroom that simply is not there
     * late in a `--filter=Knowledge` run against this project's 128 MB `memory_limit`. Run alone it
     * passes comfortably; left ungated it would make the scoped suite die of an OOM that says nothing
     * about the code. Gating it keeps the default command honest AND keeps the check runnable:
     *
     *     KNOWLEDGE_HEAVY_TESTS=1 php artisan test --filter=candidate_cap
     *
     * Three claims, and the third is the one that matters:
     *   the lookup still ANSWERS (no exhausted worker, no timeout);
     *   it says so in the log, so a partial ranking is diagnosable rather than merely disappointing;
     *   and the answer is CORRECT within the candidate set — the aligned passage is ranked first,
     *     which is what distinguishes "bounded" from "arbitrary".
     */
    public function test_the_php_fallback_bounded_by_its_candidate_cap_still_answers_and_says_so(): void
    {
        if (env('KNOWLEDGE_HEAVY_TESTS') !== '1') {
            $this->markTestSkipped('Set KNOWLEDGE_HEAVY_TESTS=1 to seed 5 000+ chunks (needs headroom above the 128M memory_limit).');
        }

        $entry = $this->createEntry('Reklamacje towaru', $this->document('reklamacje'));
        $aligned = $this->alignWithQuery($entry, 'polityka zwrotow');

        // Filler passages, seeded past the cap in ONE statement. Their vector is deliberately
        // orthogonal-ish to anything the fake embedder produces, so they cannot outrank the aligned
        // row; the point is their NUMBER, not their content.
        $this->seedFillerChunks($entry, 5200);

        config()->set('knowledge.vector_store', 'php');

        Log::spy();

        $response = $this->search('polityka zwrotow')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', (string) $entry->id);
        $this->assertNotNull($row, 'a base past the cap must still return an answer, not an error');
        $this->assertSame(
            (int) $aligned->ordinal,
            (int) $row['matched_chunk']['ordinal'],
            'the bounded candidate set is taken in a DETERMINISTIC order and the real match is in it',
        );
        $this->assertEqualsWithDelta(1.0, $row['matched_chunk']['score'], 1e-4);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, 'candidate cap')
                && ($context['knowledge_base_id'] ?? null) === (string) $this->base->id)
            ->atLeast()->once();
    }

    /**
     * Bulk-seed $count chunk rows on $entry, cheaply.
     *
     * Written as raw SQL on the model's own connection — the module's documented narrow exception for
     * vector work ({@see ChunkVector}) — because 5 000 Eloquent creates plus 5 000 vector updates would
     * make this test slower than the whole rest of the file put together.
     */
    private function seedFillerChunks(KnowledgeEntry $entry, int $count): void
    {
        $dimensions = (int) config('knowledge.embedding.dimensions');
        // A unit vector along one axis: 3 KB of literal instead of ~17 KB of formatted floats, and it
        // scores near zero against anything sha256-derived.
        $literal = '[' . implode(',', array_fill(0, $dimensions - 1, '0')) . ',1]';

        $connection = (new KnowledgeEntryChunk)->getConnection();
        $highest = (int) KnowledgeEntryChunk::query()
            ->where('knowledge_entry_id', $entry->getKey())
            ->max('ordinal');

        $connection->statement(
            'insert into knowledge_entry_chunks
                (id, workspace_id, knowledge_base_id, knowledge_entry_id, ordinal, heading_path,
                 content, char_start, char_length, digest, embedding, embedding_model, indexed_at,
                 created_at, updated_at)
             select gen_random_uuid(), ?, ?, ?, ? + g, null,
                    \'Fragment wypelniajacy numer \' || g, 0, 60, md5(\'filler\' || g), ?::vector, ?, now(),
                    now(), now()
             from generate_series(1, ?) g',
            [
                $this->workspace->id,
                $entry->knowledge_base_id,
                $entry->getKey(),
                $highest,
                $literal,
                (string) config('knowledge.embedding.model'),
                $count,
            ],
        );
    }

    // ---- the envelope ------------------------------------------------------------------

    /** The limit is part of the contract: there is no pagination, so the cut has to be visible. */
    public function test_the_response_reports_its_limit_and_whether_it_was_cut(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->createEntry("Zwroty wariant {$i}", $this->document('zwroty'));
        }

        $response = $this->search('Zwroty', ['limit' => 2])->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.limit'));
        $this->assertSame(2, $response->json('meta.count'));
        $this->assertTrue($response->json('meta.has_more'));
    }

    /**
     * A search must cost the same whether it returns two results or twenty. The shape of the bug this
     * catches is a resource that lazy-loads a creator or a base per row — invisible on a small
     * fixture, and the reason a search box gets blamed for "the app being slow".
     */
    public function test_the_query_count_does_not_grow_with_the_number_of_results(): void
    {
        $other = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id]);

        for ($i = 1; $i <= 2; $i++) {
            $this->createEntry("Zwroty wariant {$i}", $this->document('zwroty'), $i % 2 === 0 ? $other : null);
        }

        $few = $this->countQueries(fn () => $this->getJson('/api/knowledge/search?q=Zwroty')->assertOk());

        for ($i = 3; $i <= 12; $i++) {
            $this->createEntry("Zwroty wariant {$i}", $this->document('zwroty'), $i % 2 === 0 ? $other : null);
        }

        $many = $this->countQueries(fn () => $this->getJson('/api/knowledge/search?q=Zwroty')->assertOk());

        $this->assertSame($few, $many, 'a search must not issue work per result');
        $this->assertLessThan(15, $many);
    }

    public function test_a_search_without_a_query_is_refused(): void
    {
        $this->getJson("/api/knowledge/bases/{$this->base->id}/search")
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    public function test_a_limit_above_the_configured_maximum_is_refused(): void
    {
        $this->search('Zwroty', ['limit' => (int) config('knowledge.search.max_results') + 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');
    }
}
