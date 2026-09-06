<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublicationAttempt;
use App\Modules\Publishing\Services\PublicationPublisher;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * THE WHOLE PUBLISHING MODULE, ONCE, ON AN OWN-DATABASE WORKSPACE. Guarded behind TENANT_DB_TESTS=1 (it
 * issues CREATE DATABASE / DROP DATABASE), mirroring {@see CalendarTenantDatabaseTest} — which it
 * deliberately does not extend, so each can be run alone.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * STATUS AT THE TIME OF WRITING: WRITTEN, NOT YET RUN
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The owner has not yet approved `CREATE DATABASE` / `DROP DATABASE` for this chapter, so this file has
 * never had a green run. It ships anyway, and the reason is the one CLAUDE.md already names: the default
 * suite still LOADS this class, so a parse error or a dead import is caught, while a method body
 * scripting a contract that has moved on is NOT. Writing it now means the shared-mode twin and the
 * own-database twin were authored against the same contract on the same day, which is the only moment
 * they are ever guaranteed to agree.
 *
 * BEFORE COMMITTING ANY CHANGE TO THIS MODULE'S PERSISTENCE, run it by hand:
 *
 *     TENANT_DB_TESTS=1 php artisan test --filter=PublishingTenantDatabaseTest
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY PUBLISHING IN PARTICULAR NEEDS THIS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Two tenant tables, a model that reads them both, a calendar source that resolves its own connection
 * through the tenancy layer, and a state machine that writes on every step. That is the exact surface
 * that fails SILENTLY in own-database mode: in shared mode every query lands in the one place there is,
 * so nothing about the routing is ever exercised.
 *
 * And the consequence here is worse than a blank grid. A `publication_attempts` write that landed in the
 * CENTRAL database while the publication lived in the tenant one would make `findExisting()` unable to
 * see its own evidence — which is the reconciliation, blinded, on exactly the customers nobody tests on.
 * That is the scenario {@see test_the_attempt_trail_and_its_publication_live_in_the_same_database}
 * exists for.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE STRONGEST ASSERTIONS HERE ARE THE NEGATIVE ONES
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Central DECOY rows are planted carrying this very workspace's id — precisely the rows a mis-routed
 * query would find. Without them, a source reading the central database would still make every positive
 * assertion pass: the data would be found, because it would be found in the wrong place.
 *
 * NOTE: no RefreshDatabase — the provisioning DDL cannot run inside the suite transaction — so every
 * central row this file creates is removed by hand in tearDown.
 */
class PublishingTenantDatabaseTest extends TestCase
{
    /** Not the application default, so a fallback can never look like a success. */
    private const WORKSPACE_TIMEZONE = 'Europe/Warsaw';

    private const WINDOW_FROM = '2026-09-01';

    private const WINDOW_TO = '2026-09-30';

    private ?Workspace $workspace = null;

    private ?User $user = null;

    private ?string $tenantDatabase = null;

    /** Central decoy ids, deleted by hand in tearDown. */
    private array $centralDecoyIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (env('TENANT_DB_TESTS') !== '1') {
            $this->markTestSkipped('Set TENANT_DB_TESTS=1 to run real tenant-database provisioning (issues DDL).');
        }

        if (config('database.connections.' . config('database.default') . '.driver') !== 'pgsql') {
            $this->markTestSkipped('Tenant-database provisioning requires the pgsql driver.');
        }

        config(['app.timezone' => 'UTC']);
        $this->assertNotSame(self::WORKSPACE_TIMEZONE, config('app.timezone'));
    }

    /**
     * The schema half: both tenant tables exist, neither carries the per-row scoping column, and each
     * matches central EXACTLY apart from it.
     *
     * The parity is compared rather than spot-checked, for the reason the calendar's twin gives: the
     * same models read both databases, so anything present on one side only is a defect visible to
     * exactly one workspace — and invisible to everybody testing on the other.
     */
    public function test_the_tenant_schema_mirrors_central_minus_the_workspace_column(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $schema = Schema::connection(TenantManager::CONNECTION);

        foreach (['publications', 'publication_attempts'] as $table) {
            $this->assertTrue($schema->hasTable($table), "the tenant database must have {$table}");

            $this->assertFalse(
                $schema->hasColumn($table, 'workspace_id'),
                "one tenant database is one workspace — {$table}.workspace_id must not exist there",
            );

            $central = collect(Schema::connection(config('database.default'))->getColumnListing($table))
                ->reject(fn (string $column): bool => $column === 'workspace_id')
                ->sort()
                ->values()
                ->all();

            $tenant = collect($schema->getColumnListing($table))->sort()->values()->all();

            $this->assertSame($central, $tenant, "the tenant {$table} mirror must match central minus workspace_id");
        }

        // THE TWO IDEMPOTENCY COLUMNS, named individually. A tenant mirror missing either would make
        // every publish on that workspace unable to resume — and would do it silently, producing
        // duplicate containers for exactly one customer.
        foreach (['remote_id', 'remote_draft_id', 'platform_connection_id', 'scheduled_at', 'media'] as $column) {
            $this->assertTrue(
                $schema->hasColumn('publications', $column),
                "expected the tenant publications.{$column} column to exist",
            );
        }

        // Append-only, on both sides: a trail row is never amended, so there is no updated_at to mirror.
        $this->assertFalse(
            $schema->hasColumn('publication_attempts', 'updated_at'),
            'the attempt trail is append-only — an updated_at would invite amending evidence',
        );
    }

    /** The WRITE path, through the real endpoint, so the connection is chosen by middleware from a header. */
    public function test_a_publication_written_through_the_api_lands_in_the_tenant_database_only(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $id = $this->asUser()
            ->postJson('/api/publishing/publications', [
                'title' => 'Zapowiedź odcinka',
                'platform' => 'dry_run',
                // Zone-less on purpose: it must be read in the workspace zone, which lives in the
                // CENTRAL table while this write goes to the tenant one.
                'scheduled_at' => '2026-09-10T09:00:00',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->useTenant();

        $publication = Publication::query()->findOrFail($id);

        // +02:00 in September — proof that the central workspaces row was consulted from inside a
        // tenant-routed request.
        $this->assertSame('2026-09-10T07:00:00+00:00', $publication->scheduled_at->utc()->toIso8601String());

        // ── THE NEGATIVE ──────────────────────────────────────────────────────────
        $central = DB::connection(config('database.default'));

        $this->assertSame(
            0,
            (int) $central->table('publications')->where('id', $id)->count(),
            'an own-database workspace must not leave its publications in the central database',
        );
        $this->assertSame(
            0,
            (int) $central->table('publications')->where('workspace_id', $this->workspace->id)->count(),
            'nor any publication scoped to it — this is the assertion a hardcoded connection would fail',
        );
    }

    /**
     * THE FULL PUBLISH, on a tenant connection — and the trail lands beside its subject.
     *
     * This is the test that would catch the worst own-database defect available here: attempts written
     * to one database while the publication lives in another. `findExisting()` reads the trail, so a
     * split would leave reconciliation permanently unable to see its own evidence — it would answer
     * "proven absent" for a post that exists, and the retry it authorises would publish it twice.
     */
    public function test_the_attempt_trail_and_its_publication_live_in_the_same_database(): void
    {
        $this->provisionOwnDatabaseWorkspace();
        $this->useTenant();

        $publication = Publication::factory()->create([
            'title' => 'TENANT publication',
            'creator_id' => $this->user->id,
        ]);

        app(PublicationManager::class)->arm($publication, CarbonImmutable::parse('2026-09-10 09:00:00', 'UTC'));
        app(PublicationPublisher::class)->publish($publication);

        $this->assertSame(PublicationStatus::PUBLISHED, $publication->fresh()->status);

        $this->assertSame(
            2,
            PublicationAttempt::query()->where('publication_id', $publication->id)->count(),
            'both phases must be on record in the TENANT database',
        );

        // ── THE NEGATIVE ──────────────────────────────────────────────────────────
        $central = DB::connection(config('database.default'));

        $this->assertSame(
            0,
            (int) $central->table('publication_attempts')->where('publication_id', $publication->id)->count(),
            'the trail must not be split from its subject across databases — reconciliation reads it',
        );
    }

    /**
     * RECONCILIATION READS THE TENANT TRAIL.
     *
     * The scenario a crash leaves behind: the platform call landed, the status write did not. The
     * evidence is a tenant row, and a decoy is planted CENTRALLY under the same publication id — so a
     * mis-routed read would answer with the decoy's remote id instead of the real one.
     */
    public function test_reconciliation_reads_the_tenant_trail_and_never_the_central_one(): void
    {
        $this->provisionOwnDatabaseWorkspace();
        $this->useTenant();

        $publication = Publication::factory()->needsReconcile()->create([
            'title' => 'TENANT publication',
            'creator_id' => $this->user->id,
        ]);

        PublicationAttempt::create([
            'publication_id' => $publication->id,
            'platform' => 'dry_run',
            'phase' => 'publish',
            'succeeded' => true,
            'attempt' => 1,
            'remote_id' => 'dryrun_tenant_artifact',
        ]);

        $this->plantCentralAttemptDecoy($publication->id);

        app(PublicationPublisher::class)->reconcile($publication);

        $fresh = $publication->fresh();

        $this->assertSame(PublicationStatus::PUBLISHED, $fresh->status);
        $this->assertSame(
            'dryrun_tenant_artifact',
            $fresh->remote_id,
            'a CENTRAL attempt row reached an own-database workspace\'s reconciliation',
        );
    }

    /** The calendar source resolves the tenant connection like every other source. */
    public function test_the_calendar_source_reads_the_tenant_database(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $this->plantCentralPublicationDecoy();

        $this->useTenant();

        Publication::factory()->scheduled('2026-09-10 09:00:00')->create([
            'title' => 'TENANT publication',
            'creator_id' => $this->user->id,
        ]);

        $data = $this->asUser()
            ->getJson('/api/calendar/occurrences?from=' . self::WINDOW_FROM . '&to=' . self::WINDOW_TO . '&sources[]=publication')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data, 'the tenant grid must not be empty — that would make the check below vacuous');

        foreach ($data as $occurrence) {
            $this->assertStringStartsWith(
                'TENANT',
                $occurrence['title'],
                'a CENTRAL publication reached an own-database workspace\'s grid: ' . $occurrence['title'],
            );
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────

    private function asUser(): self
    {
        parent::actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    private function provisionOwnDatabaseWorkspace(): void
    {
        $this->user = User::factory()->create();

        $this->workspace = Workspace::factory()->create([
            'owner_id' => $this->user->id,
            'db_mode' => 'own',
            'status' => 'provisioning',
            'timezone' => self::WORKSPACE_TIMEZONE,
        ]);
        $this->workspace->users()->attach($this->user->id);

        $provisioner = app(WorkspaceProvisioner::class);
        $this->tenantDatabase = $provisioner->databaseName($this->workspace);
        $provisioner->provision($this->workspace);

        $this->workspace->forceFill(['status' => WorkspaceStatus::Ready])->save();
    }

    /**
     * A publication in the CENTRAL database, scoped to THIS workspace, inside the window — the row a
     * mis-routed query would happily return. Written with the query builder rather than through the
     * model, because the model is exactly the thing under test and would route it away.
     */
    private function plantCentralPublicationDecoy(): void
    {
        $central = DB::connection(config('database.default'));
        $id = (string) Str::uuid();
        $this->centralDecoyIds[] = $id;

        $central->table('publications')->insert([
            'id' => $id,
            'workspace_id' => $this->workspace->id,
            'title' => 'CENTRAL decoy',
            'body' => null,
            'platform' => 'dry_run',
            'platform_connection_id' => null,
            'status' => 'scheduled',
            'scheduled_at' => '2026-09-12 09:00:00',
            'published_at' => null,
            'media' => '[]',
            'options' => '{}',
            'remote_id' => null,
            'remote_draft_id' => null,
            'remote_url' => null,
            'attempts' => 0,
            'last_attempt_at' => null,
            'failure_code' => null,
            'failure_context' => null,
            'creator_id' => $this->user->id,
            'creator_type' => 'user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A CENTRAL attempt row for a TENANT publication — the row a mis-routed reconciliation would find. */
    private function plantCentralAttemptDecoy(string $publicationId): void
    {
        DB::connection(config('database.default'))->table('publication_attempts')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'publication_id' => $publicationId,
            'platform' => 'dry_run',
            'phase' => 'publish',
            'succeeded' => true,
            'attempt' => 1,
            'remote_draft_id' => null,
            'remote_id' => 'CENTRAL_decoy_artifact',
            'request' => null,
            'failure_code' => null,
            'failure_context' => null,
            'creator_id' => $this->user->id,
            'creator_type' => 'user',
            'created_at' => now(),
        ]);
    }

    private function useTenant(): void
    {
        app(TenantContext::class)->set($this->workspace);
        app(TenantManager::class)->configure($this->workspace);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(TenantContext::class)->clear();

        // Drop ONLY the generated tenant database — never the central app DB.
        if ($this->tenantDatabase !== null) {
            app(TenantManager::class)->forget();

            DB::connection(config('database.default'))
                ->statement('drop database if exists "' . $this->tenantDatabase . '"');
        }

        $central = DB::connection(config('database.default'));

        if ($this->workspace !== null) {
            $central->table('publication_attempts')->where('workspace_id', $this->workspace->id)->delete();
            $central->table('publications')->where('workspace_id', $this->workspace->id)->delete();
            $central->table('workspace_user')->where('workspace_id', $this->workspace->id)->delete();
        }

        $this->centralDecoyIds = [];

        $this->workspace?->forceDelete();
        $this->user?->forceDelete();

        parent::tearDown();
    }
}
