<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeIndexStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * B7 — THE WHOLE MODULE, ONCE, ON AN OWN-DATABASE WORKSPACE. Guarded behind TENANT_DB_TESTS=1 (it
 * issues CREATE DATABASE / DROP DATABASE), mirroring {@see KnowledgeTenantSchemaTest} and
 * {@see KnowledgeTenantSubjectPurgeTest}, which it deliberately does not extend so each can be run
 * alone.
 *
 * The sibling tenant tests cover the two ends: that the SCHEMA provisions, and that the erasure
 * COMMAND reaches the right connection. Neither covers the path a user actually walks, and that path
 * crosses the tenancy seam four times in a way the shared-database suite cannot exercise even once:
 *
 *   WRITE      the entry is created through HTTP, so the connection is chosen by middleware from a
 *              header rather than by a test calling `configure()` — the way it is chosen in production.
 *   INDEX      the chunks are written by a QUEUED JOB that re-establishes tenancy from a workspace id,
 *              and the vector goes in through {@see ChunkVector}'s raw SQL, which reads its connection
 *              off the model. A hardcoded connection here would write every tenant's vectors into the
 *              central database — where the column does not exist, and where the failure would surface
 *              as a confusing SQL error on somebody else's box.
 *   SEARCH     the ranking runs against the vector index in the tenant database, through
 *              {@see \App\Modules\Knowledge\Support\ChunkCandidates}, whose tenancy filter must OMIT
 *              `workspace_id` here (the column does not exist on a tenant table) while adding it in
 *              shared mode. Getting that backwards is either a crash or a leak, and only one of the
 *              two modes can be tested at a time.
 *   GRAPH      the link walk, over rows that likewise carry no workspace column.
 *
 * The strongest assertion in the file is the NEGATIVE one: the central database must hold none of it.
 * A tenancy bug that wrote to the default connection would leave every other assertion here passing —
 * the data would be found, because it would be found in the wrong place.
 */
class KnowledgeTenantIndexingTest extends TestCase
{
    use CreatesKnowledgeFixtures;

    private ?Workspace $workspace = null;

    private ?User $user = null;

    private ?string $tenantDatabase = null;

    private FakeKnowledgeEmbedder $embedder;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('TENANT_DB_TESTS') !== '1') {
            $this->markTestSkipped('Set TENANT_DB_TESTS=1 to run real tenant-database provisioning (issues DDL).');
        }

        if (config('database.connections.' . config('database.default') . '.driver') !== 'pgsql') {
            $this->markTestSkipped('Tenant-database provisioning requires the pgsql driver.');
        }

        // This file asserts the INDEXED end state, so it must name the branch it needs rather than
        // inherit it: there is no `.env.testing`, `knowledge.index.enabled` is env-driven
        // (KNOWLEDGE_INDEX_ENABLED), and the test passes today only because the config default
        // happens to be true and nobody has switched it off by hand. Its siblings already pin their
        // flags — KnowledgeTenantSubjectPurgeTest this one, KnowledgeTenantComposerTest the graph one.
        config(['knowledge.index.enabled' => true]);

        $this->embedder = new FakeKnowledgeEmbedder;
        $this->app->instance(KnowledgeEmbedder::class, $this->embedder);
    }

    public function test_an_own_database_workspace_indexes_searches_and_graphs_its_own_knowledge(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);

        $this->useTenant();
        $base = KnowledgeBase::factory()->create(['creator_id' => $this->user->id]);

        // ---- WRITE + INDEX, through the real endpoint -------------------------------------

        $entryId = (string) $this->makeEntry(base: $base, title: 'Polityka zwrotow', content: $this->document('zwrotow i reklamacji'))->id;

        $this->useTenant();

        $entry = KnowledgeEntry::query()->findOrFail($entryId);

        $this->assertSame(KnowledgeIndexStatus::INDEXED, $entry->index_status);
        $this->assertGreaterThan(0, $this->embedder->calls, 'the tenant write must have reached the indexer');

        $chunks = KnowledgeEntryChunk::query()
            ->withoutEmbedding()
            ->where('knowledge_entry_id', $entry->getKey())
            ->orderBy('ordinal')
            ->get();

        $this->assertGreaterThan(1, $chunks->count(), 'the fixture must really split, or the run proves little');
        $this->assertSame($chunks->count(), (int) $entry->chunks_count);

        // The vector itself, read back through the raw-SQL seam — on the TENANT connection.
        $vector = ChunkVector::read((string) $chunks->first()->id);
        $this->assertNotNull($vector, 'the vector must be stored in the tenant database');
        $this->assertCount((int) config('knowledge.embedding.dimensions'), $vector);

        // ---- THE NEGATIVE: none of it landed centrally -------------------------------------

        $central = DB::connection(config('database.default'));

        $this->assertSame(
            0,
            (int) $central->table('knowledge_entries')->where('id', $entryId)->count(),
            'an own-database workspace must not leave its entries in the central database',
        );
        $this->assertSame(
            0,
            (int) $central->table('knowledge_entry_chunks')->where('knowledge_entry_id', $entryId)->count(),
            'nor its vectors — this is the assertion a hardcoded connection would fail',
        );

        // ---- SEARCH: the vector leg, ranked inside the tenant database ---------------------

        // Make one passage an exact match for the query the search will embed, so the semantic leg is
        // observable rather than merely believed.
        ChunkVector::write(
            (string) $chunks->first()->id,
            FakeKnowledgeEmbedder::vectorFor('procedura oddawania towaru', (int) config('knowledge.embedding.dimensions')),
            (string) config('knowledge.embedding.model'),
            now(),
        );

        $search = $this->getJson("/api/knowledge/bases/{$base->id}/search?" . http_build_query([
            'q' => 'procedura oddawania towaru',
        ]))->assertOk();

        $hit = collect($search->json('data'))->firstWhere('id', (string) $entryId);

        $this->assertNotNull($hit, 'the tenant base must be searchable');
        $this->assertEqualsWithDelta(
            1.0,
            $hit['matched_chunk']['score'],
            1e-4,
            'the ranking ran against the tenant vector index, not an empty central one',
        );

        // ---- GRAPH: the link walk over tenant rows ------------------------------------------

        $this->useTenant();
        $slug = (string) KnowledgeEntry::query()->whereKey($entryId)->value('slug');

        $sourceId = (string) $this->makeEntry(base: $base, title: 'Obsluga klienta', content: "Szczegoly opisuje [[{$slug}]] w osobnym dokumencie firmowym.")->id;

        $graph = $this->getJson("/api/knowledge/bases/{$base->id}/graph?" . http_build_query([
            'entry' => $sourceId,
            'depth' => 1,
            'sources' => 'wikilink',
        ]))->assertOk();

        $nodeIds = array_map(static fn (array $node): string => $node['id'], $graph->json('data.nodes'));

        $this->assertContains((string) $sourceId, $nodeIds);
        $this->assertContains((string) $entryId, $nodeIds, 'the wikilink resolved against tenant rows');
        $this->assertCount(1, $graph->json('data.edges'));
        $this->assertSame(0, $graph->json('data.truncated.hidden_nodes'));
    }

    // ---- fixtures --------------------------------------------------------------

    /** Prose long enough to split into several passages. */
    private function document(string $subject): string
    {
        $parts = [];

        for ($paragraph = 1; $paragraph <= 6; $paragraph++) {
            $sentences = [];

            for ($i = 1; $i <= 12; $i++) {
                $sentences[] = "Akapit {$paragraph} zdanie {$i} o temacie {$subject} wypelniajacy ten fragment dokumentu.";
            }

            $parts[] = implode(' ', $sentences);
        }

        return implode("\n\n", $parts);
    }

    private function provisionOwnDatabaseWorkspace(): void
    {
        $this->user = User::factory()->create();

        $this->workspace = Workspace::factory()->create([
            'owner_id' => $this->user->id,
            'db_mode' => 'own',
            'status' => 'provisioning',
        ]);
        $this->workspace->users()->attach($this->user->id);

        $provisioner = app(WorkspaceProvisioner::class);
        $this->tenantDatabase = $provisioner->databaseName($this->workspace);
        $provisioner->provision($this->workspace);

        $this->workspace->forceFill(['status' => WorkspaceStatus::Ready])->save();
    }

    private function useTenant(): void
    {
        app(TenantContext::class)->set($this->workspace);
        app(TenantManager::class)->configure($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        // Drop ONLY the generated tenant database — never the central app DB.
        if ($this->tenantDatabase !== null) {
            app(TenantManager::class)->forget();

            DB::connection(config('database.default'))
                ->statement('drop database if exists "' . $this->tenantDatabase . '"');
        }

        // No RefreshDatabase here (the provisioning DDL cannot run inside the suite transaction), so
        // the central rows this test made are removed by hand.
        if ($this->workspace !== null) {
            DB::connection(config('database.default'))
                ->table('workspace_user')
                ->where('workspace_id', $this->workspace->id)
                ->delete();
        }

        $this->workspace?->forceDelete();
        $this->user?->forceDelete();

        parent::tearDown();
    }
}
