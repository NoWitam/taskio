<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Agents\KnowledgeDraftAgent;
use App\Modules\Knowledge\Agents\KnowledgeMentionAgent;
use App\Modules\Knowledge\Contracts\KnowledgeEmbedder;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Jobs\GenerateKnowledgeDraftsJob;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Services\KnowledgeDraftService;
use App\Modules\Knowledge\Support\FakeKnowledgeEmbedder;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesKnowledgeFixtures;
use Tests\TestCase;

/**
 * G8 — THE COMPOSER ON AN OWN-DATABASE WORKSPACE. Guarded behind TENANT_DB_TESTS=1 (it issues
 * CREATE DATABASE / DROP DATABASE), mirroring {@see KnowledgeTenantIndexingTest}, which it
 * deliberately does not extend so each file can be run alone.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY THE COMPOSER NEEDS ITS OWN TENANT TEST
 *
 * ADR-0046 records this as an open gap, and the reason it is a gap rather than an oversight is that
 * the composer is the only part of the module whose write path is a QUEUED JOB CARRYING NOTHING BUT
 * TWO STRINGS. `GenerateKnowledgeDraftsJob` re-establishes tenancy from a workspace id, and every
 * table it then touches — sessions, drafts, relations, relation events — omits `workspace_id`
 * entirely in own-database mode. Nothing about that is exercised by the shared-database suite: there,
 * a wrong connection still finds its rows, because there is only one database and the scope filters
 * by a column that exists.
 *
 * The three seams crossed here, none of them reachable in shared mode:
 *
 *   START     the session row is written through HTTP, so the connection comes from a header.
 *   GENERATE  the job re-derives tenancy from an id, and the two AI agents, the resolution pass and
 *             the laundering all run against whatever connection it established. A miss writes the
 *             drafts into the central database, where they would be invisible to the tenant and
 *             visible to nobody else — a silent data loss that reads as "the run produced nothing".
 *   ACCEPT    entities, relations and the relation EVENT LOG are written in one transaction on that
 *             same connection, and the graph tables are the newest arrivals in the tenant schema.
 *
 * And the REAPER, which is the one path that starts with no tenant at all: a scheduled command walks
 * every own-database workspace in turn, so a bug there abandons the wrong workspace's sessions.
 *
 * As in the sibling file, the strongest assertion is the NEGATIVE one — the central database must
 * hold none of it. A tenancy bug would leave every positive assertion passing, because the rows would
 * be found; they would simply be found in the wrong place.
 *
 * ------------------------------------------------------------------------------------------------
 * THIS FILE ROTTED ONCE, AND THE REASON IS STRUCTURAL
 *
 * It was found scripting a composer contract that had moved on, after being wrong for some time with
 * no run ever going red — because it only executes when somebody sets the flag, while the shared-mode
 * tests it mirrors kept passing and said the module was fine.
 *
 * What the default suite DOES catch is narrower than it looks: `php artisan test --filter=Knowledge`
 * still LOADS this class and instantiates it (the skip happens in `setUp`), so a parse error, a moved
 * class or a deleted import fails the ordinary run. What it cannot catch is a method BODY scripting a
 * contract that no longer exists — the one failure that actually happened.
 *
 * So: after changing the composer's queued path, the agents' reply contract, or tenancy plumbing, run
 *
 *     TENANT_DB_TESTS=1 php artisan test --filter=Tenant
 *
 * by hand, and read `docs/backend/knowledge-api.md` → "A file behind a flag ROTS, and nothing tells
 * you" for the full list of triggers and for the nightly CI job this habit stands in for.
 */
class KnowledgeTenantComposerTest extends TestCase
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

        $this->embedder = new FakeKnowledgeEmbedder;
        $this->app->instance(KnowledgeEmbedder::class, $this->embedder);

        config()->set('knowledge.graph_extraction.enabled', true);
    }

    public function test_an_own_database_workspace_composes_and_accepts_its_own_graph(): void
    {
        $this->provisionOwnDatabaseWorkspace();
        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);

        $this->useTenant();
        $base = KnowledgeBase::factory()->create(['creator_id' => $this->user->id]);

        $lukaszId = (string) $this->makeEntry(base: $base, title: 'Łukasz Barszcz', content: 'Łukasz prowadzi projekt zwrotów.', type: KnowledgeEntryType::PERSON)->id;

        // ---- START + GENERATE, through the real endpoint and the real job ------------------

        KnowledgeMentionAgent::fake(fn (): string => json_encode([
            'mentions' => [['text' => 'Łukasz', 'kind' => 'person', 'context' => 'Łukasz przeszedł do Kwadratury']],
        ], JSON_UNESCAPED_UNICODE));

        // THE NEW SUBJECT IS AN ORDINARY DRAFT CARRYING A HANDLE. The model does not declare `entities`
        // any more — it writes the page and claims `N1` on it, and the server synthesises the
        // declaration from the laundered draft. This fixture still spoke the old shape, and because the
        // whole file only runs under TENANT_DB_TESTS nothing noticed: the handle resolved to nothing,
        // the graph operation was rejected as an unknown handle, and the run settled `failed: empty`.
        KnowledgeDraftAgent::fake(fn (): string => json_encode([
            'entries' => [[
                'action' => 'create',
                'ref' => 'N1',
                'slug' => 'kwadratura',
                'title' => 'Kwadratura',
                'type' => 'organization',
                'content' => 'Kwadratura to software house.',
                'metadata' => [],
            ]],
            'graph_updates' => [['op' => 'create', 'from' => 'E1', 'to' => 'N1', 'type' => 'member_of', 'description' => 'Przeszedł w lipcu.']],
        ], JSON_UNESCAPED_UNICODE));

        $sessionId = $this->postJson("/api/knowledge/bases/{$base->id}/draft-sessions", [
            'source_text' => 'Łukasz przeszedł do Kwadratury.',
        ])->assertCreated()->json('data.id');

        $this->useTenant();

        $session = KnowledgeDraftSession::query()->findOrFail($sessionId);

        $this->assertSame('ready', $session->status?->value, 'the run settled on the tenant connection: ' . (string) $session->failure_reason);
        $this->assertNotSame([], $session->resolutionSet()['entities'], 'phase 1 froze against the TENANT base');
        $this->assertCount(1, $session->graphOps()['graph_updates']);

        // ---- ACCEPT: entities, relations and the event log, all on the tenant connection -----
        //
        // The draft for Kwadratura is ticked as well as the relation: the subject is a review card like
        // any other page, and the relation can only bind to it once a person has approved it.

        $response = $this->postJson("/api/knowledge/draft-sessions/{$sessionId}/accept", [
            'entry_ids' => [(string) $session->drafts()->firstOrFail()->id],
            'graph_op_keys' => ['graph:0'],
            'status' => 'approved',
        ])->assertOk();

        $this->useTenant();

        $this->assertCount(1, $response->json('relations'));

        $kwadratura = KnowledgeEntry::query()->where('title', 'Kwadratura')->firstOrFail();
        $relation = KnowledgeRelation::query()->firstOrFail();

        $this->assertSame('Kwadratura to software house.', (string) $kwadratura->content);
        $this->assertSame((string) $lukaszId, (string) $relation->from_entry_id);
        $this->assertSame((string) $kwadratura->id, (string) $relation->to_entry_id);
        $this->assertSame(KnowledgeRelationType::MEMBER_OF, $relation->relation_type);

        // The audit line for that write lives in the tenant database too.
        $this->assertSame(
            1,
            (int) DB::connection($relation->getConnectionName())->table('knowledge_relation_events')->where('relation_id', $relation->id)->count(),
        );

        // ---- THE NEGATIVE: the central database holds none of it ------------------------------

        $central = DB::connection(config('database.default'));

        $this->assertSame(0, (int) $central->table('knowledge_draft_sessions')->where('id', $sessionId)->count());
        $this->assertSame(0, (int) $central->table('knowledge_entries')->where('id', $kwadratura->id)->count());
        $this->assertSame(0, (int) $central->table('knowledge_relations')->count(), 'a relation must never land centrally');
        $this->assertSame(0, (int) $central->table('knowledge_relation_events')->count());
    }

    /**
     * THE REAPER, which begins with NO tenant context at all.
     *
     * A scheduled command has no request and no header, so it walks every own-database workspace and
     * establishes tenancy itself, one at a time. That makes it the one place where a leaked connection
     * from the PREVIOUS workspace destroys rows belonging to somebody else — and where doing nothing
     * at all is equally invisible, because a session nobody reaps is a session nobody looks at.
     *
     * Both directions are asserted: the stale session goes, the fresh one stays.
     */
    public function test_the_reaper_reaches_an_own_database_workspaces_abandoned_sessions(): void
    {
        $this->provisionOwnDatabaseWorkspace();
        $this->useTenant();

        $base = KnowledgeBase::factory()->create(['creator_id' => $this->user->id]);

        $days = max(1, (int) config('knowledge.drafting.abandon_after_days'));

        $stale = KnowledgeDraftSession::factory()->create([
            'knowledge_base_id' => $base->id,
            'source_text' => 'Material, o ktorym wszyscy zapomnieli.',
        ]);
        $fresh = KnowledgeDraftSession::factory()->create([
            'knowledge_base_id' => $base->id,
            'source_text' => 'Material sprzed chwili.',
        ]);

        // A draft hanging off the stale session, so the cascade is observable rather than assumed.
        $draft = KnowledgeEntry::factory()->create([
            'knowledge_base_id' => $base->id,
            'draft_session_id' => $stale->id,
            'title' => 'Szkic do porzucenia',
            'slug' => 'szkic-do-porzucenia',
            'content' => 'Tresc szkicu.',
        ]);

        // `updated_at` is what the reaper reads as "last activity" — moved without touching anything
        // else, which is exactly the state an abandoned tab leaves behind.
        DB::connection($stale->getConnectionName())
            ->table('knowledge_draft_sessions')
            ->where('id', $stale->id)
            ->update(['updated_at' => now()->subDays($days + 1)]);

        // The command establishes its own tenancy; drop ours so it cannot ride on it.
        app(TenantContext::class)->clear();
        app(TenantManager::class)->forget();

        $this->assertSame(0, Artisan::call('knowledge:reap-draft-sessions'));

        $this->useTenant();

        $this->assertNull(KnowledgeDraftSession::query()->find($stale->id), 'the abandoned session was reaped');
        $this->assertNull(
            KnowledgeEntry::query()->withDrafts()->withTrashed()->find($draft->id),
            'abandoning takes its drafts with it, on the tenant connection',
        );
        $this->assertNotNull(KnowledgeDraftSession::query()->find($fresh->id), 'a live session is untouched');
    }

    /**
     * THE JOB ON ITS OWN, invoked the way the queue invokes it: two strings and nothing else.
     *
     * The test above reaches the job through HTTP, so the connection was already right when it
     * started. This one starts with tenancy CLEARED, which is the state a worker process is really in
     * — and it is the only way to prove the job re-establishes tenancy from its payload rather than
     * inheriting it from whatever ran before it on the same worker.
     */
    public function test_the_generation_job_establishes_tenancy_from_its_payload_alone(): void
    {
        $this->provisionOwnDatabaseWorkspace();
        $this->useTenant();

        $base = KnowledgeBase::factory()->create(['creator_id' => $this->user->id]);

        $session = KnowledgeDraftSession::factory()->create([
            'knowledge_base_id' => $base->id,
            'source_text' => 'Zwroty przyjmujemy w 30 dni.',
            'status' => 'generating',
        ]);

        KnowledgeMentionAgent::fake(fn (): string => json_encode(['mentions' => []], JSON_UNESCAPED_UNICODE));
        KnowledgeDraftAgent::fake(fn (): string => json_encode([
            'entries' => [['action' => 'create', 'slug' => 'zwroty', 'title' => 'Zwroty', 'content' => 'Zwroty w 30 dni.', 'metadata' => []]],
        ], JSON_UNESCAPED_UNICODE));

        // The worker's real starting state: no request, no header, no ambient workspace.
        app(TenantContext::class)->clear();
        app(TenantManager::class)->forget();

        (new GenerateKnowledgeDraftsJob((string) $session->id, (string) $this->workspace->id))
            ->handle(app(KnowledgeDraftService::class));

        $this->useTenant();

        $session->refresh();

        $this->assertSame('ready', $session->status?->value);
        $this->assertSame(1, $session->drafts()->count(), 'the draft was written to the TENANT database');

        $this->assertSame(
            0,
            (int) DB::connection(config('database.default'))->table('knowledge_entries')->count(),
            'a job that inherited the central connection would have left the draft here',
        );
    }

    // ---- provisioning ---------------------------------------------------------------------

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
