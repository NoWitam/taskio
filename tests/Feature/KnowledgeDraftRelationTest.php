<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Services\KnowledgeDraftRelationService;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * B12 — the RELATION PREVIEW: what a set of proposed drafts would do to the base.
 *
 * Three properties carry the design. It must WRITE NOTHING (every edge is a preview of a relation that
 * does not exist yet, and materialising it would put unaccepted work into the base's own graph). It
 * must be CHEAP TO RE-OPEN (one embedding batch, cached by the drafts' own text, so a panel the user
 * flips back to costs nothing). And it must STILL RENDER without vectors, because a budget event
 * should degrade the picture rather than remove it.
 */
class KnowledgeDraftRelationTest extends TestCase
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

        $this->base = KnowledgeBase::factory()->create(['workspace_id' => $this->workspace->id, 'language' => 'pl']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- fixtures -------------------------------------------------------------------

    private function fakeComposer(array $entries): void
    {
        KnowledgeDraftAgent::fake(fn (): string => json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE));
    }

    private function start(string $source = 'Material zrodlowy do przetworzenia.'): KnowledgeDraftSession
    {
        $id = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", ['source_text' => $source])
            ->assertCreated()->json('data.id');

        return KnowledgeDraftSession::query()->findOrFail($id);
    }

    /** A real indexed entry whose passage is an exact vector match for $matches. */
    private function existingEntry(string $title, string $content, string $matches): KnowledgeEntry
    {
        $id = (string) $this->makeEntry(base: $this->base, title: $title, content: $content)->id;

        $entry = KnowledgeEntry::query()->findOrFail($id);

        $chunk = KnowledgeEntryChunk::query()
            ->withoutEmbedding()
            ->where('knowledge_entry_id', $entry->id)
            ->orderBy('ordinal')
            ->firstOrFail();

        ChunkVector::write(
            (string) $chunk->id,
            FakeKnowledgeEmbedder::vectorFor($matches, (int) config('knowledge.embedding.dimensions')),
            (string) config('knowledge.embedding.model'),
            now(),
        );

        return $entry->refresh();
    }

    private function relations(KnowledgeDraftSession $session): array
    {
        return $this->getJson("/api/knowledge/draft-sessions/{$session->id}/relations")
            ->assertOk()
            ->json('data');
    }

    // ---- shape -------------------------------------------------------------------------

    /** The SAME shape the base graph returns, so the client reuses one canvas. */
    public function test_the_payload_matches_the_graph_resource_shape(): void
    {
        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'a', 'title' => 'Alfa', 'content' => 'Tresc alfa.', 'metadata' => []],
        ]);

        $data = $this->relations($this->start());

        $this->assertSame(
            [
                'center', 'nodes', 'edges', 'ghosts', 'truncated', 'duplicates', 'vector_skipped',
                // G4: the run's PROPOSED typed relations, and the entities it wants to create, in the
                // same panel a reviewer is already looking at. ALWAYS present — empty when the graph
                // layer is off or proposed nothing — so the payload shape does not move with a flag.
                'proposed_relations', 'proposed_entities',
            ],
            array_keys($data),
        );
        $this->assertSame(['hidden_nodes', 'hidden_edges'], array_keys($data['truncated']));
        $this->assertSame(
            ['id', 'slug', 'title', 'status', 'is_stale', 'degree', 'distance', 'is_draft', 'amended_by'],
            array_keys($data['nodes'][0]),
        );
        $this->assertTrue($data['nodes'][0]['is_draft']);
        $this->assertSame([], $data['nodes'][0]['amended_by']);
    }

    /** Every edge carries the same keys as a base-graph edge, `can_be_dismissed` included. */
    public function test_preview_edges_carry_the_graph_edge_keys_and_are_never_dismissable(): void
    {
        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'alfa', 'title' => 'Alfa', 'content' => 'Zobacz [[beta]].', 'metadata' => []],
            ['action' => 'create', 'slug' => 'beta', 'title' => 'Beta', 'content' => 'Tresc beta.', 'metadata' => []],
        ]);

        $data = $this->relations($this->start());
        $edge = collect($data['edges'])->firstWhere('source', 'wikilink');

        $this->assertNotNull($edge);
        $this->assertSame(
            ['id', 'from', 'to', 'source', 'score', 'evidence', 'dismissed', 'can_be_dismissed'],
            array_keys($edge),
        );
        $this->assertNull($edge['id'], 'a preview edge has no row');
        $this->assertFalse($edge['can_be_dismissed'], 'nothing unmaterialised can be refused');
    }

    /** THE rule: a preview writes nothing. */
    public function test_computing_relations_writes_no_links(): void
    {
        $this->existingEntry('Istniejacy', str_repeat('Tresc istniejaca. ', 30), 'Material zrodlowy do przetworzenia.');

        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'alfa', 'title' => 'Alfa', 'content' => 'Zobacz [[istniejacy]].', 'metadata' => []],
        ]);

        $before = KnowledgeLink::query()->count();
        $this->relations($this->start());

        $this->assertSame($before, KnowledgeLink::query()->count());
    }

    // ---- the cache ----------------------------------------------------------------------

    /** Re-opening an unchanged panel must cost NOTHING. */
    public function test_an_unchanged_reopen_makes_no_embedding_call(): void
    {
        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'a', 'title' => 'Alfa', 'content' => 'Tresc.', 'metadata' => []],
        ]);

        $session = $this->start();

        $this->relations($session);
        $this->embedder->reset();

        $this->relations($session);

        $this->assertSame(0, $this->embedder->calls);
    }

    public function test_editing_a_draft_invalidates_the_cache(): void
    {
        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'a', 'title' => 'Alfa', 'content' => 'Tresc.', 'metadata' => []],
        ]);

        $session = $this->start();
        $this->relations($session);

        $draft = $session->drafts()->firstOrFail();
        $draft->forceFill(['content' => 'Zupelnie inna tresc.'])->save();

        $this->embedder->reset();
        $this->relations($session);

        $this->assertSame(1, $this->embedder->calls, 'a changed draft must recompute');
    }

    /** A pipeline bump invalidates too — the same self-healing rule the entry digest uses. */
    public function test_bumping_the_links_version_invalidates_the_cache(): void
    {
        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'a', 'title' => 'Alfa', 'content' => 'Tresc.', 'metadata' => []],
        ]);

        $session = $this->start();
        $this->relations($session);

        config()->set('knowledge.links.version', 99);
        $this->embedder->reset();
        $this->relations($session);

        $this->assertSame(1, $this->embedder->calls);
    }

    // ---- fail-soft ------------------------------------------------------------------------

    /** No vectors, but still a panel: the deterministic edges are the ones a human can verify anyway. */
    public function test_without_vectors_the_panel_still_returns_deterministic_edges(): void
    {
        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'alfa', 'title' => 'Alfa', 'content' => 'Zobacz [[beta]].', 'metadata' => []],
            ['action' => 'create', 'slug' => 'beta', 'title' => 'Beta', 'content' => 'Tresc beta.', 'metadata' => []],
        ]);

        $session = $this->start();

        $this->app->instance(MeteredAiCall::class, new RefusingRelationMeter);

        $data = $this->relations($session);

        $this->assertSame(KnowledgeDraftRelationService::SKIPPED_BUDGET, $data['vector_skipped']);
        $this->assertNotEmpty($data['edges']);
        $this->assertSame(['wikilink'], array_values(array_unique(array_column($data['edges'], 'source'))));
    }

    /**
     * A budget-degraded picture is NOT remembered.
     *
     * The cache is keyed on the drafts' text, so caching a result that lost its similarity edges to a
     * momentary condition would make the degradation stick for the rest of the session's life — long
     * after the cap reset or the provider recovered, with no way for the user to ask again short of
     * editing a draft. Transient causes recompute; permanent ones (kill switch, no vector support)
     * are cached, because neither un-flips between two page views.
     */
    public function test_a_budget_degraded_result_is_not_cached(): void
    {
        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'alfa', 'title' => 'Alfa', 'content' => 'Zobacz [[beta]].', 'metadata' => []],
            ['action' => 'create', 'slug' => 'beta', 'title' => 'Beta', 'content' => 'Tresc beta.', 'metadata' => []],
        ]);

        $session = $this->start();

        $refusing = new RefusingRelationMeter;
        $this->app->instance(MeteredAiCall::class, $refusing);

        $degraded = $this->relations($session);
        $this->assertSame(KnowledgeDraftRelationService::SKIPPED_BUDGET, $degraded['vector_skipped']);

        // Nothing was written, so a later open cannot be served the degraded answer.
        $this->assertNull($session->refresh()->relations_cache);

        // The budget comes back — the very next open recomputes and gets the full picture.
        $this->app->forgetInstance(MeteredAiCall::class);
        $this->embedder->reset();

        $full = $this->relations($session);

        $this->assertNull($full['vector_skipped']);
        $this->assertSame(1, $this->embedder->calls, 'the recovered open must recompute the vectors');
        $this->assertNotNull($session->refresh()->relations_cache, 'and THAT result is worth remembering');
    }

    /** A permanently degraded picture IS cached: the kill switch does not un-flip between page views. */
    public function test_a_kill_switch_degraded_result_is_cached(): void
    {
        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'alfa', 'title' => 'Alfa', 'content' => 'Zobacz [[beta]].', 'metadata' => []],
            ['action' => 'create', 'slug' => 'beta', 'title' => 'Beta', 'content' => 'Tresc beta.', 'metadata' => []],
        ]);

        $session = $this->start();

        config()->set('knowledge.index.enabled', false);

        $data = $this->relations($session);

        $this->assertSame(KnowledgeDraftRelationService::SKIPPED_DISABLED, $data['vector_skipped']);
        $this->assertNotNull($session->refresh()->relations_cache);
    }

    // ---- shadows --------------------------------------------------------------------------

    /**
     * A shadow is NOT a circle of its own, and NOT an edge either.
     *
     * An edge from a shadow to its target would have an endpoint that is not in `nodes[]`, and the
     * canvas relies on every endpoint being a node to lay itself out. So the pending amendment is an
     * ANNOTATION on the target — which is how it was always going to be drawn anyway (a badge on the
     * entry, not a line to nowhere).
     */
    public function test_a_shadow_annotates_its_target_rather_than_becoming_a_node_or_an_edge(): void
    {
        $source = 'Zwroty przyjmujemy teraz w 30 dni.';
        $target = $this->existingEntry('Zwroty', str_repeat('Polityka zwrotow. ', 30), $source);

        $this->fakeComposer([
            ['action' => 'update', 'targets_slug' => $target->slug, 'title' => 'Zwroty', 'content' => 'Nowa tresc.', 'metadata' => []],
        ]);

        $session = $this->start($source);
        $shadow = $session->drafts()->firstOrFail();

        $data = $this->relations($session);

        $this->assertNotContains((string) $shadow->id, array_column($data['nodes'], 'id'));
        $this->assertContains((string) $target->id, array_column($data['nodes'], 'id'));

        // No `amends` edge exists at all — the annotation replaced it.
        $this->assertNotContains('amends', array_column($data['edges'], 'source'));

        $targetNode = collect($data['nodes'])->firstWhere('id', (string) $target->id);
        $this->assertSame([['draft_id' => (string) $shadow->id]], $targetNode['amended_by']);
    }

    /**
     * THE invariant the resolution protects: every endpoint of every edge is a node in the same
     * response. The canvas lays out from `nodes[]`, so an id it has never seen is a crash, not a
     * cosmetic gap.
     */
    public function test_every_edge_endpoint_is_a_node(): void
    {
        $source = 'Zwroty przyjmujemy teraz w 30 dni.';
        $target = $this->existingEntry('Zwroty', str_repeat('Polityka zwrotow. ', 30), $source);

        $this->fakeComposer([
            ['action' => 'update', 'targets_slug' => $target->slug, 'title' => 'Zwroty', 'content' => 'Nowa tresc.', 'metadata' => []],
            ['action' => 'create', 'slug' => 'alfa', 'title' => 'Alfa', 'content' => 'Zobacz [[beta]].', 'metadata' => []],
            ['action' => 'create', 'slug' => 'beta', 'title' => 'Beta', 'content' => 'Tresc beta o zwrotach.', 'metadata' => []],
        ]);

        $data = $this->relations($this->start($source));
        $ids = array_column($data['nodes'], 'id');

        $this->assertNotEmpty($data['edges']);

        foreach ($data['edges'] as $edge) {
            $this->assertContains($edge['from'], $ids, 'an edge starts at a node that is not in the response');
            $this->assertContains($edge['to'], $ids, 'an edge ends at a node that is not in the response');
        }
    }

    // ---- duplicates -------------------------------------------------------------------------

    public function test_a_near_identical_draft_is_flagged_as_a_duplicate(): void
    {
        $source = 'Zwroty przyjmujemy w 14 dni od zakupu towaru.';
        $existing = $this->existingEntry('Zwroty', str_repeat('Zwroty w 14 dni. ', 30), $source);

        // The draft's embedded text is title + content; align the existing passage with exactly that so
        // the pair scores 1.0 — comfortably over the 0.88 warning bar.
        $draftText = "Zwroty\nZwroty przyjmujemy w 14 dni.";
        $chunk = KnowledgeEntryChunk::query()->where('knowledge_entry_id', $existing->id)->orderBy('ordinal')->firstOrFail();
        ChunkVector::write(
            (string) $chunk->id,
            FakeKnowledgeEmbedder::vectorFor($draftText, (int) config('knowledge.embedding.dimensions')),
            (string) config('knowledge.embedding.model'),
            now(),
        );

        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'zwroty-nowe', 'title' => 'Zwroty', 'content' => 'Zwroty przyjmujemy w 14 dni.', 'metadata' => []],
        ]);

        $session = $this->start($source);
        $draft = $session->drafts()->firstOrFail();

        $data = $this->relations($session);

        $this->assertArrayHasKey((string) $draft->id, $data['duplicates']);
        $this->assertSame((string) $existing->slug, $data['duplicates'][(string) $draft->id]['slug']);
        $this->assertGreaterThanOrEqual(
            (float) config('knowledge.drafting.duplicate_warn_threshold'),
            $data['duplicates'][(string) $draft->id]['score'],
        );

        // ...and the session payload serves it from cache, so the card can show it with no extra call.
        $this->embedder->reset();
        $duplicates = $this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->json('data.duplicates');

        $this->assertArrayHasKey((string) $draft->id, $duplicates);
        $this->assertSame(0, $this->embedder->calls, 'the session payload must never compute');
    }

    public function test_an_unrelated_draft_is_not_flagged(): void
    {
        $this->existingEntry('Zwroty', str_repeat('Polityka zwrotow. ', 30), 'cos zupelnie innego');

        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'koty', 'title' => 'Koty', 'content' => 'O kotach i psach.', 'metadata' => []],
        ]);

        $this->assertSame([], $this->relations($this->start())['duplicates']);
    }

    // ---- access ------------------------------------------------------------------------------

    public function test_another_workspaces_session_is_not_found(): void
    {
        $otherOwner = User::factory()->create();
        $other = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $foreignBase = KnowledgeBase::factory()->create(['workspace_id' => $other->id]);
        $foreign = KnowledgeDraftSession::factory()->create([
            'workspace_id' => $other->id,
            'knowledge_base_id' => $foreignBase->id,
        ]);

        $this->getJson("/api/knowledge/draft-sessions/{$foreign->id}/relations")->assertNotFound();
    }

    // ---- the B14 gap ---------------------------------------------------------------------------

    /** The base graph's edges gained the same flag, so no client has to re-derive the rule. */
    public function test_the_base_graph_edges_now_carry_can_be_dismissed(): void
    {
        $content = str_repeat('Akapit o cenniku hurtowym wypelniajacy ten fragment dokumentu. ', 30);
        // Two near-identical entries, so the similarity pass draws the machine-guessed edge this test
        // is about.
        $first = $this->makeEntry($this->base, 'Cennik', $content);
        $this->makeEntry($this->base, 'Cennik', $content);

        $edges = $this->getJson("/api/knowledge/bases/{$this->base->id}/graph?entry={$first->id}")->assertOk()->json('data.edges');

        $this->assertNotEmpty($edges);

        foreach ($edges as $edge) {
            $this->assertArrayHasKey('can_be_dismissed', $edge);
            $this->assertSame(in_array($edge['source'], ['similarity', 'mention'], true), $edge['can_be_dismissed']);
        }
    }
}

/** A workspace over its cap: the relation panel degrades to deterministic edges. */
class RefusingRelationMeter implements MeteredAiCall
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
