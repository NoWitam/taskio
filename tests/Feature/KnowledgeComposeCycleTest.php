<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeDraftSessionStatus;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Enums\KnowledgeIndexStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Models\KnowledgeLink;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * ONE COMPOSITION, END TO END (B16) — the sequence a person actually performs, in order, as a single
 * scenario.
 *
 * Every step below is covered somewhere in isolation. What no isolated test can show is that the steps
 * COMPOSE: that the seed survives a refinement, that a refinement rewrites rather than duplicates, that
 * acceptance is what starts the indexer (and that nothing before it did), and that the entry a person
 * accepted is the entry the search box then finds. Those are the joins between features, and joins are
 * where a working set of parts stops being a working product.
 *
 * ONE ORDERING CLAIM IS LOAD-BEARING: NOTHING IS INDEXED BEFORE IT IS ACCEPTED. Indexing spends money
 * on the provider and puts passages into the retrieval layer a bot quotes from. A draft that got
 * indexed "early, to be ready" would be both a bill for text nobody kept and a way for unreviewed
 * machine output to reach a customer through the vector leg. The test asserts the zero as carefully as
 * it asserts the eventual chunks.
 *
 * NO PROVIDER IS REACHED. The composer's agent is served by its own fake gateway
 * ({@see KnowledgeDraftAgent::fake()}, the seam every other Knowledge test uses), and embeddings come
 * from {@see FakeKnowledgeEmbedder}, whose call counter is what makes "this step spent nothing" an
 * assertion rather than a hope.
 */
class KnowledgeComposeCycleTest extends TestCase
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

        $this->base = KnowledgeBase::factory()->create([
            'workspace_id' => $this->workspace->id,
            'language' => 'pl',
        ]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function fakeComposer(array $entries): void
    {
        KnowledgeDraftAgent::fake(fn (): string => json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE));
    }

    /**
     * THE WHOLE LOOP: a red link, a session seeded from it, a revision, a partial acceptance, the
     * indexer starting only then, and the result being findable.
     */
    public function test_a_composition_runs_from_a_red_link_to_a_findable_entry(): void
    {
        // --- 0. THE RED LINK ------------------------------------------------------------------
        // Somebody wrote `[[cennik-hurtowy]]` in an entry that exists. Nothing answers it yet, which
        // is the situation the composer's seed exists for.
        $author = (string) $this->makeEntry(base: $this->base, title: 'Regulamin sprzedazy', content: 'Warunki wspolpracy opisuje [[cennik-hurtowy]] oraz zasady zwrotow.')->id;

        $ghost = KnowledgeLink::query()
            ->where('from_entry_id', $author)
            ->where('target_slug', 'cennik-hurtowy')
            ->firstOrFail();

        $this->assertNull($ghost->to_entry_id);
        $this->assertSame(1, $this->getJson("/api/knowledge/bases/{$this->base->id}")->assertOk()->json('data.ghost_links_count'));

        // --- 1. THE SESSION -------------------------------------------------------------------
        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'cennik', 'title' => 'Cennik hurtowy', 'content' => 'Rabat 10% od 100 sztuk.', 'metadata' => []],
            ['action' => 'create', 'slug' => 'dostawa', 'title' => 'Dostawa', 'content' => 'Wysylka w 24 godziny.', 'metadata' => []],
        ]);

        $created = $this->postJson("/api/knowledge/bases/{$this->base->id}/draft-sessions", [
            'source_text' => 'Rabat 10 procent od 100 sztuk. Wysylka w 24 godziny.',
            'seed_slug' => 'cennik-hurtowy',
            'seed_title' => 'Cennik hurtowy',
        ])->assertCreated();

        $session = KnowledgeDraftSession::query()->findOrFail($created->json('data.id'));
        $this->assertSame(KnowledgeDraftSessionStatus::READY, $session->status);

        $drafts = $session->drafts()->orderBy('position')->get();
        $this->assertCount(2, $drafts);

        // THE SEED PROMISE: the run was opened from a red link, so one draft must occupy THAT slug —
        // the composer proposed `cennik`, and the server re-pointed it.
        $seeded = $drafts->firstWhere('slug', 'cennik-hurtowy');
        $this->assertNotNull($seeded, 'a seeded session must produce the entry the red link names');
        $this->assertSame('Cennik hurtowy', $seeded->title);

        // Nothing has been indexed and nothing is visible: the invariant this whole feature rests on.
        $this->assertSame(0, KnowledgeEntryChunk::query()->whereIn('knowledge_entry_id', $drafts->pluck('id'))->count());
        $this->assertNull($ghost->fresh()->to_entry_id, 'a draft does not answer a red link');
        $this->assertSame(
            [$author],
            array_column($this->getJson("/api/knowledge/bases/{$this->base->id}/entries")->assertOk()->json('data'), 'id'),
            'the base still holds exactly the one entry a human wrote',
        );

        // --- 2. THE REFINEMENT ----------------------------------------------------------------
        // The reviewer asks for something shorter. Matching is BY SLUG, so this rewrites the same two
        // rows rather than minting two more — which is what keeps the diff panel's history meaningful.
        $this->embedder->reset();

        $this->fakeComposer([
            ['action' => 'create', 'slug' => 'cennik-hurtowy', 'title' => 'Cennik hurtowy', 'content' => 'Rabat 10% od 100 sztuk. Powyzej 500 sztuk rabat 15%.', 'metadata' => []],
            ['action' => 'create', 'slug' => 'dostawa', 'title' => 'Dostawa', 'content' => 'Wysylka w 24 godziny robocze.', 'metadata' => []],
        ]);

        // The POST answers with the session it CLAIMED, before the run settles — the client is told
        // "in flight" and waits on the broadcast rather than being handed a result synchronously.
        $this->postJson("/api/knowledge/draft-sessions/{$session->id}/refine", ['instruction' => 'dodaj drugi prog rabatowy'])
            ->assertOk()
            ->assertJsonPath('data.status', 'generating');

        // The poll (which is what the settle event triggers) then shows the finished set.
        $this->getJson("/api/knowledge/draft-sessions/{$session->id}")->assertOk()->assertJsonPath('data.status', 'ready');

        $refined = $session->fresh()->drafts()->get();
        $this->assertCount(2, $refined, 'a refinement revises the set, it does not append a second one');

        $seeded->refresh();
        $this->assertStringContainsString('15%', (string) $seeded->content);
        $this->assertGreaterThanOrEqual(2, $seeded->revisions()->count(), 'each run appends a revision');

        // Still nothing indexed, and the refinement itself embedded nothing.
        $this->assertSame(0, KnowledgeEntryChunk::query()->whereIn('knowledge_entry_id', $refined->pluck('id'))->count());
        $this->assertSame(0, $this->embedder->calls, 'a refinement is a text call — it must not embed');

        // --- 3. THE PARTIAL ACCEPTANCE ---------------------------------------------------------
        // One is good, the other is not ready. Accepting one must not drag the other along.
        $rejected = $refined->firstWhere('slug', 'dostawa');

        $accepted = $this->postJson("/api/knowledge/draft-sessions/{$session->id}/accept", [
            'entry_ids' => [(string) $seeded->id],
            'status' => 'approved',
        ])->assertOk();

        $this->assertCount(1, $accepted->json('accepted'));
        $this->assertSame([], $accepted->json('conflicts'));
        $this->assertSame((string) $seeded->id, $accepted->json('accepted.0.id'));

        $published = KnowledgeEntry::query()->findOrFail($seeded->id);
        $this->assertNull($published->draft_session_id, 'accept is one column write, not a copy');
        $this->assertSame((string) $seeded->id, (string) $published->id, 'and the id survives it');
        $this->assertSame(KnowledgeEntryStatus::APPROVED, $published->status);

        $this->assertNotNull($rejected->fresh()->draft_session_id, 'the other draft stays a draft');
        $this->assertNull(KnowledgeEntry::query()->find($rejected->id), 'and stays invisible');

        // --- 4. WHAT ACCEPTANCE STARTED --------------------------------------------------------
        // Indexing begins HERE and only here, and the accepted entry's own links resolve for the first
        // time — including the red link that opened the session.
        $chunks = KnowledgeEntryChunk::query()->where('knowledge_entry_id', $published->id)->count();
        $this->assertGreaterThan(0, $chunks, 'acceptance is what queues the entry for indexing');
        $this->assertSame(KnowledgeIndexStatus::INDEXED, $published->fresh()->index_status);
        $this->assertGreaterThan(0, $this->embedder->calls, 'and that is the first embedding this session paid for');

        $this->assertSame(0, KnowledgeEntryChunk::query()->where('knowledge_entry_id', $rejected->id)->count());

        $this->assertSame((string) $published->id, (string) $ghost->fresh()->to_entry_id, 'the red link is answered');
        $this->assertSame(0, $this->getJson("/api/knowledge/bases/{$this->base->id}")->assertOk()->json('data.ghost_links_count'));

        // --- 5. FINDABLE ------------------------------------------------------------------------
        $hits = $this->getJson("/api/knowledge/bases/{$this->base->id}/search?q=" . urlencode('Cennik hurtowy'))
            ->assertOk()
            ->json('data');

        $ids = array_column($hits, 'id');
        $this->assertContains((string) $published->id, $ids, 'what a person accepted is what search finds');
        $this->assertNotContains((string) $rejected->id, $ids, 'and what they did not accept is still invisible');

        // The base's own numbers moved by exactly one.
        $base = $this->getJson("/api/knowledge/bases/{$this->base->id}")->assertOk()->json('data');
        $this->assertSame(2, $base['entries_count']);
        $this->assertSame(2, $base['index_summary']['total']);

        // --- 6. THE LEFTOVER --------------------------------------------------------------------
        // Abandoning takes the unaccepted draft with it, and leaves the published entry alone.
        $this->deleteJson("/api/knowledge/draft-sessions/{$session->id}")->assertNoContent();

        $this->assertNull(KnowledgeDraftSession::query()->find($session->id));
        $this->assertNull(KnowledgeEntry::query()->withDrafts()->withTrashed()->find($rejected->id));
        $this->assertNotNull(KnowledgeEntry::query()->find($published->id), 'an accepted entry is nobody\'s draft any more');
        $this->assertGreaterThan(0, KnowledgeEntryChunk::query()->where('knowledge_entry_id', $published->id)->count());
    }
}
