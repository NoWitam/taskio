<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Contracts\KnowledgeSimilaritySearch;
use App\Modules\Knowledge\DTOs\ChunkSimilarityQuery;
use App\Modules\Knowledge\DTOs\CompiledKnowledge;
use App\Modules\Knowledge\Enums\KnowledgeBindingMode;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeBinding;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Services\KnowledgeRetrievalService;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RAG retrieval + the `auto` mode switch (B6).
 *
 * The vector leg is made OBSERVABLE the same way the human search's test does it: a chunk is stored with
 * the exact vector the fake embedder produces for a given query, so it scores 1.0 while everything else in
 * the base scores near zero. That turns "retrieval works" into an assertion instead of a hope.
 *
 * The other half of this file is the FAIL-SOFT contract, which matters more than the ranking: every way
 * retrieval can be unavailable must land on the free inline compilation, never on an exception. A consumer
 * that had to handle a budget error would handle it differently in every module.
 */
class KnowledgeRetrievalTest extends TestCase
{
    use RefreshDatabase;

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

        $this->base = KnowledgeBase::factory()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Baza obslugi',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures ------------------------------------------------------------------

    /**
     * An entry whose passages this test writes BY HAND.
     *
     * Saving an entry queues the real indexing job (sync queue in tests), which would chunk the body and
     * take ordinal 0 for itself. These tests need to control exactly which passage carries which vector,
     * so the auto-indexer is switched off for the write and back on straight after — the retrieval path
     * under test still sees the kill switch in its normal, ENABLED state.
     */
    private function entry(
        string $title,
        string $content,
        KnowledgeEntryStatus $status = KnowledgeEntryStatus::APPROVED,
        int $position = 0,
    ): KnowledgeEntry {
        $enabled = config('knowledge.index.enabled');
        config()->set('knowledge.index.enabled', false);

        try {
            return KnowledgeEntry::factory()->create([
                'workspace_id' => $this->workspace->id,
                'knowledge_base_id' => $this->base->id,
                'title' => $title,
                'content' => $content,
                'status' => $status,
                'position' => $position,
            ]);
        } finally {
            config()->set('knowledge.index.enabled', $enabled);
        }
    }

    /** One stored passage of an entry. $alignWith stores the vector the fake produces for that query. */
    private function chunk(KnowledgeEntry $entry, string $content, int $ordinal = 0, ?string $alignWith = null, ?string $heading = null): KnowledgeEntryChunk
    {
        $chunk = KnowledgeEntryChunk::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_entry_id' => $entry->id,
            'knowledge_base_id' => $this->base->id,
            'ordinal' => $ordinal,
            'heading_path' => $heading,
            'content' => $content,
            'char_start' => 0,
            'char_length' => mb_strlen($content),
        ]);

        ChunkVector::write(
            (string) $chunk->id,
            FakeKnowledgeEmbedder::vectorFor($alignWith ?? ('nic wspolnego ' . $chunk->id), (int) config('knowledge.embedding.dimensions')),
            (string) config('knowledge.embedding.model'),
            now(),
        );

        return $chunk;
    }

    private function binding(KnowledgeBindingMode $mode = KnowledgeBindingMode::RAG): KnowledgeBinding
    {
        return KnowledgeBinding::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'mode' => $mode,
        ]);
    }

    private function retrieve(string $query, KnowledgeBindingMode $mode = KnowledgeBindingMode::RAG, int $maxChars = 8000): ?CompiledKnowledge
    {
        return app(KnowledgeRetrievalService::class)->forBinding($this->binding($mode), $query, $maxChars);
    }

    // ---- what retrieval returns ------------------------------------------------------

    /**
     * The point of retrieval: a passage that shares NO words with the query is still found, and it comes
     * back with its citation address so an answer can be traced to the passage rather than the document.
     */
    public function test_it_returns_the_matching_passage_with_its_citation(): void
    {
        $entry = $this->entry('Reklamacje towaru', 'Pelna tresc wpisu o reklamacjach.');
        $chunk = $this->chunk($entry, 'Reklamacje rozpatrujemy w 30 dni.', alignWith: 'polityka zwrotow', heading: 'Terminy');

        $compiled = $this->retrieve('polityka zwrotow');

        $this->assertNotNull($compiled);
        $this->assertSame(KnowledgeBindingMode::RAG, $compiled->mode);
        $this->assertStringContainsString('Reklamacje rozpatrujemy w 30 dni.', $compiled->text);
        $this->assertStringContainsString('[' . $entry->id . '#' . $chunk->ordinal . ']', $compiled->text);
        $this->assertStringContainsString('Reklamacje towaru > Terminy', $compiled->text);
        $this->assertSame([(string) $entry->id], $compiled->entryIds);
    }

    /**
     * Stricter than the human search, and deliberately so: this text is quoted to a model as fact. The
     * filter is re-applied when entries are hydrated, because a chunk row OUTLIVES its entry's status
     * change until the entry is re-indexed — so the vector leg alone can still name a demoted entry.
     */
    public function test_only_approved_entries_are_retrieved(): void
    {
        $draft = $this->entry('Szkic o zwrotach', 'Polowa mysli.', status: KnowledgeEntryStatus::DRAFT);
        $this->chunk($draft, 'Szkicowa tresc o zwrotach.', alignWith: 'polityka zwrotow');

        $approved = $this->entry('Zwroty', 'Zatwierdzona tresc.', position: 1);
        $this->chunk($approved, 'Zwroty przyjmujemy w 14 dni.');

        $compiled = $this->retrieve('polityka zwrotow');

        $this->assertNotNull($compiled);
        $this->assertStringNotContainsString('Szkicowa tresc', $compiled->text);
        $this->assertNotContains((string) $draft->id, $compiled->entryIds);
    }

    /**
     * The FREE one-hop expansion: an entry reached only through a materialized link contributes its
     * opening passage. It costs no embedding — the edges are already stored — which is the only reason
     * widening the answer this way is affordable at all.
     */
    public function test_a_linked_neighbour_is_pulled_in_for_free(): void
    {
        // K = 1 so the vector leg returns ONLY the aligned passage. Without this the tiny fixture base
        // would come back whole and the assertion would pass whether or not expansion ran at all.
        config()->set('knowledge.retrieval.chunk_top_k', 1);

        $hit = $this->entry('Zwroty', 'Tresc o zwrotach.');
        $this->chunk($hit, 'Zwroty w 14 dni.', alignWith: 'jak oddac produkt');

        $neighbour = $this->entry('Koszty wysylki', 'Tresc o wysylce.', position: 1);
        $this->chunk($neighbour, 'Wysylke zwrotna oplaca klient.', ordinal: 0);

        KnowledgeLink::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'from_entry_id' => $hit->id,
            'to_entry_id' => $neighbour->id,
            'target_slug' => $neighbour->slug,
            'source' => KnowledgeLinkSource::SIMILARITY,
            'score' => 0.9,
        ]);

        $this->embedder->reset();
        $compiled = $this->retrieve('jak oddac produkt');

        $this->assertNotNull($compiled);
        $this->assertStringContainsString('Wysylke zwrotna oplaca klient.', $compiled->text);
        $this->assertContains((string) $neighbour->id, $compiled->entryIds);
        // Still exactly one provider round-trip: the hop reads stored rows, not the provider.
        $this->assertSame(1, $this->embedder->calls);
    }

    public function test_a_dismissed_link_is_not_expanded(): void
    {
        config()->set('knowledge.retrieval.chunk_top_k', 1);

        $hit = $this->entry('Zwroty', 'Tresc.');
        $this->chunk($hit, 'Zwroty w 14 dni.', alignWith: 'jak oddac produkt');

        $neighbour = $this->entry('Nie na temat', 'Tresc.', position: 1);
        $this->chunk($neighbour, 'Zupelnie inny temat.', ordinal: 0);

        KnowledgeLink::factory()->create([
            'workspace_id' => $this->workspace->id,
            'knowledge_base_id' => $this->base->id,
            'from_entry_id' => $hit->id,
            'to_entry_id' => $neighbour->id,
            'target_slug' => $neighbour->slug,
            'source' => KnowledgeLinkSource::SIMILARITY,
            'score' => 0.9,
            'dismissed_at' => now(),
        ]);

        $compiled = $this->retrieve('jak oddac produkt');

        $this->assertNotNull($compiled);
        $this->assertStringNotContainsString('Zupelnie inny temat.', $compiled->text);
    }

    // ---- spend ------------------------------------------------------------------------

    /** ONE embedding per retrieval, on the shared channel. Ranking and expansion are free. */
    public function test_exactly_one_embedding_call_is_made_per_retrieval(): void
    {
        $entry = $this->entry('Zwroty', 'Tresc.');
        $this->chunk($entry, 'Zwroty w 14 dni.', alignWith: 'zwroty');

        $meter = new RecordingMeteredAiCall;
        $this->app->instance(MeteredAiCall::class, $meter);
        $this->embedder->reset();

        $this->retrieve('zwroty');

        $this->assertSame(1, $this->embedder->calls);
        $this->assertSame([KnowledgeRetrievalService::CHANNEL], $meter->metered);
        // Gated BEFORE the spend, so an over-cap workspace never reaches the provider.
        $this->assertSame([KnowledgeRetrievalService::CHANNEL], $meter->asserted);
    }

    // ---- fail-soft --------------------------------------------------------------------

    /**
     * An exhausted AI budget must NOT make a bot forget everything it knows. It degrades to the inline
     * compilation, which spends nothing — and it does so without an exception reaching the consumer.
     */
    public function test_an_exhausted_budget_degrades_to_inline_without_throwing(): void
    {
        $entry = $this->entry('Zwroty', 'Zwroty przyjmujemy w 14 dni.');
        $this->chunk($entry, 'Zwroty w 14 dni.', alignWith: 'zwroty');

        $this->app->instance(MeteredAiCall::class, new RefusingMeteredAiCall);
        $this->embedder->reset();

        $compiled = $this->retrieve('zwroty');

        $this->assertNotNull($compiled);
        $this->assertSame(KnowledgeBindingMode::INLINE, $compiled->mode);
        $this->assertStringContainsString('Zwroty przyjmujemy w 14 dni.', $compiled->text);
        $this->assertSame(0, $this->embedder->calls);
    }

    /** The module KILL SWITCH has to stop reads as well as writes. */
    public function test_the_kill_switch_degrades_to_inline(): void
    {
        $entry = $this->entry('Zwroty', 'Zwroty przyjmujemy w 14 dni.');
        $this->chunk($entry, 'Zwroty w 14 dni.', alignWith: 'zwroty');

        config()->set('knowledge.index.enabled', false);
        $this->embedder->reset();

        $compiled = $this->retrieve('zwroty');

        $this->assertNotNull($compiled);
        $this->assertSame(KnowledgeBindingMode::INLINE, $compiled->mode);
        $this->assertSame(0, $this->embedder->calls);
    }

    /** A provider outage is an operational event, not a knowledge outage. */
    public function test_a_provider_failure_degrades_to_inline(): void
    {
        $entry = $this->entry('Zwroty', 'Zwroty przyjmujemy w 14 dni.');
        $this->chunk($entry, 'Zwroty w 14 dni.', alignWith: 'zwroty');

        $this->embedder->reset();
        $this->embedder->failure = new \RuntimeException('provider down');

        $compiled = $this->retrieve('zwroty');

        $this->assertNotNull($compiled);
        $this->assertSame(KnowledgeBindingMode::INLINE, $compiled->mode);
        $this->assertStringContainsString('Zwroty przyjmujemy w 14 dni.', $compiled->text);
    }

    /**
     * THE fail-soft case the "never throws" claim actually rests on, and the one an earlier version got
     * wrong: only the EMBEDDING was guarded, so the lookup itself could throw straight into the
     * consumer's run.
     *
     * The realistic trigger is a `vector(1536)` column queried with a 3072-wide vector after someone
     * changes `knowledge.embedding.model` — Postgres refuses it, `ChunkVector::supported()` cannot see
     * it coming (it only knows the driver), and the QueryException used to kill a whole bot execution
     * AFTER the embedding had been paid for. Any Throwable from the search stands in for it here.
     */
    public function test_a_throwing_similarity_search_degrades_to_inline_without_throwing(): void
    {
        $entry = $this->entry('Zwroty', 'Zwroty przyjmujemy w 14 dni.');
        $this->chunk($entry, 'Zwroty w 14 dni.', alignWith: 'zwroty');

        $this->app->instance(KnowledgeSimilaritySearch::class, new ExplodingSimilaritySearch);

        $compiled = $this->retrieve('zwroty');

        $this->assertNotNull($compiled);
        $this->assertSame(KnowledgeBindingMode::INLINE, $compiled->mode);
        $this->assertStringContainsString('Zwroty przyjmujemy w 14 dni.', $compiled->text);
    }

    /** The same guard through the `auto` path, which reaches the lookup by a different route. */
    public function test_auto_survives_a_throwing_similarity_search(): void
    {
        $big = $this->entry('Duzy wpis', str_repeat('T', 3000), position: 0);
        $this->chunk($big, 'Fragment o zwrotach.', alignWith: 'zwroty');
        $this->entry('Drugi duzy wpis', str_repeat('U', 3000), position: 1);

        $this->app->instance(KnowledgeSimilaritySearch::class, new ExplodingSimilaritySearch);

        $compiled = $this->retrieve('zwroty', KnowledgeBindingMode::AUTO, maxChars: 2000);

        $this->assertNotNull($compiled);
        $this->assertSame(KnowledgeBindingMode::INLINE, $compiled->mode);
    }

    /**
     * A base nobody has indexed yet has no passages. Handing back an empty block would tell the model the
     * workspace knows nothing; the compiler knows better and costs nothing.
     */
    public function test_a_base_with_no_indexed_passages_degrades_to_inline(): void
    {
        $this->entry('Zwroty', 'Zwroty przyjmujemy w 14 dni.');

        $compiled = $this->retrieve('zwroty');

        $this->assertNotNull($compiled);
        $this->assertSame(KnowledgeBindingMode::INLINE, $compiled->mode);
    }

    /** Nothing approved: no block, and NO embedding bought to discover that. */
    public function test_a_base_with_nothing_approved_returns_null_without_spending(): void
    {
        $draft = $this->entry('Szkic', 'Nic gotowego.', status: KnowledgeEntryStatus::DRAFT);
        $this->chunk($draft, 'Szkic.', alignWith: 'zwroty');

        $this->embedder->reset();

        $this->assertNull($this->retrieve('zwroty'));
        $this->assertSame(0, $this->embedder->calls);
    }

    // ---- auto ---------------------------------------------------------------------------

    /** A base that FITS is served inline: strictly better, and free. */
    public function test_auto_uses_inline_while_the_base_fits(): void
    {
        $entry = $this->entry('Zwroty', 'Zwroty przyjmujemy w 14 dni.');
        $this->chunk($entry, 'Zwroty w 14 dni.', alignWith: 'zwroty');

        $this->embedder->reset();
        $compiled = $this->retrieve('zwroty', KnowledgeBindingMode::AUTO);

        $this->assertNotNull($compiled);
        $this->assertSame(KnowledgeBindingMode::INLINE, $compiled->mode);
        $this->assertSame(0, $this->embedder->calls);
    }

    /** Once it stops fitting, retrieval is the only honest option — and it costs exactly one call. */
    public function test_auto_switches_to_retrieval_once_the_base_outgrows_the_budget(): void
    {
        $big = $this->entry('Duzy wpis', str_repeat('T', 3000), position: 0);
        $this->chunk($big, 'Fragment o zwrotach w 14 dni.', alignWith: 'zwroty');
        $this->entry('Drugi duzy wpis', str_repeat('U', 3000), position: 1);

        $this->embedder->reset();
        $compiled = $this->retrieve('zwroty', KnowledgeBindingMode::AUTO, maxChars: 2000);

        $this->assertNotNull($compiled);
        $this->assertSame(KnowledgeBindingMode::RAG, $compiled->mode);
        $this->assertStringContainsString('Fragment o zwrotach w 14 dni.', $compiled->text);
        $this->assertSame(1, $this->embedder->calls);
    }

    /** An explicit `inline` binding never buys a vector, whatever the query says. */
    public function test_inline_mode_never_embeds(): void
    {
        $entry = $this->entry('Zwroty', 'Zwroty przyjmujemy w 14 dni.');
        $this->chunk($entry, 'Zwroty w 14 dni.', alignWith: 'zwroty');

        $this->embedder->reset();
        $compiled = $this->retrieve('zwroty', KnowledgeBindingMode::INLINE);

        $this->assertNotNull($compiled);
        $this->assertSame(KnowledgeBindingMode::INLINE, $compiled->mode);
        $this->assertSame(0, $this->embedder->calls);
    }
}

/** Records which channels were gated and metered, then runs the call for real. */
class RecordingMeteredAiCall implements MeteredAiCall
{
    /** @var array<int, string> */
    public array $metered = [];

    /** @var array<int, string> */
    public array $asserted = [];

    public function meter(string $channel, callable $call): mixed
    {
        $this->metered[] = $channel;

        return $call();
    }

    public function assertWithinBudget(string $channel, float $projectedCost = 0.0): void
    {
        $this->asserted[] = $channel;
    }
}

/**
 * A similarity backend that fails the way the real one does when the embedding width stops matching the
 * stored column. The message deliberately carries the shape of a QueryException's — the point is that
 * NOTHING of it reaches the consumer, and nothing of it is logged either.
 */
class ExplodingSimilaritySearch implements KnowledgeSimilaritySearch
{
    public function topChunks(array $vector, ChunkSimilarityQuery $query): array
    {
        throw new QueryException(
            'pgsql',
            'select * from knowledge_entry_chunks order by embedding <=> ?',
            ['[0.1,0.2]'],
            new \RuntimeException('SQLSTATE[22000]: different vector dimensions 1536 and 3072'),
        );
    }

    public function topNeighbours(string $chunkId, ChunkSimilarityQuery $query): array
    {
        return [];
    }
}

/** A workspace that is over its cap: the gate refuses before anything is spent. */
class RefusingMeteredAiCall implements MeteredAiCall
{
    public function meter(string $channel, callable $call): mixed
    {
        throw new AiBudgetExceededException($channel, 12.5, 10.0);
    }

    public function assertWithinBudget(string $channel, float $projectedCost = 0.0): void
    {
        throw new AiBudgetExceededException($channel, 12.5, 10.0);
    }
}
