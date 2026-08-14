<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
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
 * THE WHOLE CALENDAR, ONCE, ON AN OWN-DATABASE WORKSPACE. Guarded behind TENANT_DB_TESTS=1 (it issues
 * CREATE DATABASE / DROP DATABASE), mirroring {@see KnowledgeTenantIndexingTest} — which it
 * deliberately does not extend, so each can be run alone.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE CALENDAR IN PARTICULAR NEEDS THIS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The Calendar ships a tenant migration and FOUR sources, three of which live in other modules and
 * every one of which resolves its own connection through the tenancy layer. That is the exact surface
 * that fails SILENTLY in own-database mode: in shared mode every query lands in the one place there is,
 * so nothing about the routing is ever exercised. A source that resolved to the default connection
 * would return an empty (or, worse, another tenant's) grid for own-database workspaces only — late, in
 * production, one customer at a time.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE SPLIT THIS FILE EXISTS TO PIN
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `workspaces` is CENTRAL. `calendar_events`, `tasks`, `workflows` and `workflow_runs` are NOT, for an
 * own-database workspace. Yet every day boundary the grid draws comes from `workspaces.timezone`
 * ({@see \App\Modules\Calendar\Services\CalendarTimezoneResolver}), read while the tenant connection is
 * the active one. Two failure modes live in that seam and neither raises anything:
 *
 *   • the resolver queries the tenant database for a table that is not there → the calendar breaks for
 *     own-database workspaces only;
 *   • the resolver silently falls back to `config('app.timezone')` → the grid renders, in the WRONG
 *     zone, and every day boundary is off by the offset with nothing to see.
 *
 * The second is why {@see test_the_workspace_timezone_resolves_from_the_central_table} does not merely
 * assert that a timezone came back: it asserts the workspace's OWN zone came back, and picks a zone
 * that is not the application default so a fallback cannot masquerade as success.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE STRONGEST ASSERTIONS HERE ARE THE NEGATIVE ONES
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Central DECOY rows are planted carrying this very workspace's id — precisely the rows a mis-routed
 * query would find. Without them, a source reading the central database would still make every
 * positive assertion pass: the data would be found, because it would be found in the wrong place.
 *
 * NOTE: no RefreshDatabase — the provisioning DDL cannot run inside the suite transaction — so every
 * central row this file creates is removed by hand in tearDown.
 */
class CalendarTenantDatabaseTest extends TestCase
{
    /** Not the application default, so a fallback can never look like a success. */
    private const WORKSPACE_TIMEZONE = 'Europe/Warsaw';

    private const WINDOW_FROM = '2026-08-01';

    private const WINDOW_TO = '2026-08-31';

    private ?Workspace $workspace = null;

    private ?User $user = null;

    private ?string $tenantDatabase = null;

    /** Central decoy ids, deleted by hand in tearDown. */
    private array $centralDecoyEventIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (env('TENANT_DB_TESTS') !== '1') {
            $this->markTestSkipped('Set TENANT_DB_TESTS=1 to run real tenant-database provisioning (issues DDL).');
        }

        if (config('database.connections.' . config('database.default') . '.driver') !== 'pgsql') {
            $this->markTestSkipped('Tenant-database provisioning requires the pgsql driver.');
        }

        // Named, never inherited: the suite reads the developer's `.env`, and this file's subject is
        // partly a timezone. Pinning the app default is what makes "the workspace's zone came back"
        // distinguishable from "a fallback came back".
        config(['app.timezone' => 'UTC']);
        $this->assertNotSame(self::WORKSPACE_TIMEZONE, config('app.timezone'));
    }

    /**
     * The schema half: the tenant database really has the table, and really does NOT have the
     * per-row scoping column. One tenant database IS one workspace, so `workspace_id` there would be a
     * constant — and a source that filtered on it would crash on the first read.
     */
    public function test_the_tenant_schema_carries_calendar_events_without_a_workspace_column(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $schema = Schema::connection(TenantManager::CONNECTION);

        $this->assertTrue($schema->hasTable('calendar_events'), 'the tenant database must have calendar_events');

        $this->assertFalse(
            $schema->hasColumn('calendar_events', 'workspace_id'),
            'one tenant database is one workspace — the scoping column must not exist there',
        );

        // The columns the all-day discriminator rests on. A tenant mirror missing one of them would
        // make every event on that workspace unreadable, and only for that workspace.
        foreach (['all_day', 'start_date', 'starts_at', 'ends_at', 'subject_type', 'subject_id', 'deleted_at'] as $column) {
            $this->assertTrue(
                $schema->hasColumn('calendar_events', $column),
                "expected the tenant calendar_events.{$column} column to exist",
            );
        }

        // The PARITY itself, rather than a hand-kept list that drifts one column at a time: the same
        // models read both databases, so anything present on one side only is a defect visible to
        // exactly one workspace — and invisible to everybody testing on the other. `workspace_id` is the
        // one and only intended difference.
        $central = collect(Schema::connection(config('database.default'))->getColumnListing('calendar_events'))
            ->reject(fn (string $column): bool => $column === 'workspace_id')
            ->sort()
            ->values()
            ->all();

        $tenant = collect($schema->getColumnListing('calendar_events'))->sort()->values()->all();

        $this->assertSame($central, $tenant, 'the tenant mirror must match central minus workspace_id');
        $this->assertNotContains('color', $tenant, 'an event has no colour, in either database');
    }

    /**
     * The WRITE path, through the real endpoint — so the connection is chosen by middleware from a
     * header, the way it is chosen in production, rather than by a test calling `configure()`.
     */
    public function test_an_event_written_through_the_api_lands_in_the_tenant_database_only(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $timedId = $this->asUser()
            ->postJson('/api/calendar/events', [
                'title' => 'Nagranie odcinka',
                'all_day' => false,
                // Zone-less on purpose: it must be read in the workspace zone, which lives in the
                // CENTRAL table while this write goes to the tenant one.
                'starts_at' => '2026-08-10T14:30:00',
            ])
            ->assertCreated()
            ->json('data.id');

        $allDayId = $this->asUser()
            ->postJson('/api/calendar/events', [
                'title' => 'Dzien premiery',
                'all_day' => true,
                'start_date' => '2026-08-12',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->useTenant();

        $this->assertSame(2, CalendarEvent::query()->count(), 'both events must be in the tenant database');

        // The zone-less instant was read in EUROPE/WARSAW (+02:00 in August) — proof that the central
        // workspaces row was consulted from inside a tenant-routed request.
        $this->assertSame(
            '2026-08-10T12:30:00+00:00',
            CalendarEvent::query()->findOrFail($timedId)->starts_at->utc()->toIso8601String(),
            'a zone-less instant must be read in the workspace zone, not the app default',
        );

        // The day is re-printed, never converted — the same invariant, now across a connection.
        $this->assertSame('2026-08-12', CalendarEvent::query()->findOrFail($allDayId)->startDateString());

        // ── THE NEGATIVE ──────────────────────────────────────────────────────────
        $central = DB::connection(config('database.default'));

        $this->assertSame(
            0,
            (int) $central->table('calendar_events')->whereIn('id', [$timedId, $allDayId])->count(),
            'an own-database workspace must not leave its events in the central database',
        );
        $this->assertSame(
            0,
            (int) $central->table('calendar_events')->where('workspace_id', $this->workspace->id)->count(),
            'nor any event scoped to it — this is the assertion a hardcoded connection would fail',
        );
    }

    /**
     * The READ path, all four sources at once, with a central decoy planted for each shape the
     * calendar can draw.
     *
     * This is the test that would have caught a source resolving to the default connection: every
     * decoy carries this workspace's id and sits inside the window, so a mis-routed query finds it and
     * the titles below stop matching.
     */
    public function test_every_source_reads_the_tenant_database_and_never_the_central_one(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        $this->plantCentralDecoys();

        $this->useTenant();

        // event
        CalendarEvent::factory()->allDay('2026-08-12')->create([
            'title' => 'TENANT event',
            'creator_id' => $this->user->id,
        ]);

        // task deadline
        Task::factory()->create([
            'title' => 'TENANT task',
            'deadline' => '2026-08-09',
            'creator_id' => $this->user->id,
            'assigned_id' => $this->user->id,
        ]);

        // workflow schedule (future) + workflow run (past)
        $workflow = Workflow::factory()->active()->scheduled()->create([
            'name' => 'TENANT automation',
            'creator_id' => $this->user->id,
        ]);

        WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'state' => WorkflowRunState::COMPLETED,
            'started_at' => '2026-08-10T08:00:00Z',
            'finished_at' => '2026-08-10T08:05:00Z',
        ]);

        $response = $this->asUser()
            ->getJson('/api/calendar/occurrences?from=' . self::WINDOW_FROM . '&to=' . self::WINDOW_TO)
            ->assertOk();

        $data = $response->json('data');

        $this->assertNotEmpty($data, 'the tenant grid must not be empty — that would make every check below vacuous');

        // ANTI-VACUITY: every source actually contributed, so "no central row leaked" is a statement
        // about four live sources rather than four silent ones.
        $bySource = [];
        foreach ($data as $occurrence) {
            $bySource[$occurrence['source']][] = $occurrence['title'];
        }

        foreach (['event', 'task', 'workflow_schedule', 'workflow_run'] as $source) {
            $this->assertArrayHasKey($source, $bySource, "the {$source} source produced nothing from the tenant database");
        }

        $this->assertSame([], $response->json('meta.unavailable_sources'), 'no source may fail in tenant mode');

        // ── THE NEGATIVE: not one decoy from the central database ────────────────
        foreach ($data as $occurrence) {
            $this->assertStringStartsWith(
                'TENANT',
                $occurrence['title'],
                'a CENTRAL row reached an own-database workspace\'s grid: ' . $occurrence['title'],
            );
        }

        CarbonImmutable::setTestNow();
    }

    /**
     * `workspaces` is CENTRAL; the calendar's data is not. The zone the grid is drawn in has to cross
     * that seam on every read, and it must arrive as the WORKSPACE's zone — never as the application
     * default, which is what a broken lookup would degrade to without a word.
     */
    public function test_the_workspace_timezone_resolves_from_the_central_table(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $this->asUser()
            ->getJson('/api/calendar/occurrences?from=' . self::WINDOW_FROM . '&to=' . self::WINDOW_TO)
            ->assertOk()
            ->assertJsonPath('meta.timezone', self::WORKSPACE_TIMEZONE);

        // And it FOLLOWS the central row when that row changes — a cached or copied value would pass
        // the assertion above and fail this one.
        $this->workspace->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();

        $this->asUser()
            ->getJson('/api/calendar/occurrences?from=' . self::WINDOW_FROM . '&to=' . self::WINDOW_TO)
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'Pacific/Kiritimati');

        // The widest offset there is, and an all-day event still names its own day. The day survives a
        // connection boundary AND a 14-hour zone, which is the whole point of the discriminator.
        $this->asUser()
            ->postJson('/api/calendar/events', [
                'title' => 'Dzien premiery',
                'all_day' => true,
                'start_date' => '2026-08-12',
            ])
            ->assertCreated()
            ->assertJsonPath('data.start_date', '2026-08-12');

        $this->asUser()
            ->getJson('/api/calendar/occurrences?from=2026-08-12&to=2026-08-12&sources[]=event')
            ->assertOk()
            ->assertJsonPath('data.0.start_date', '2026-08-12');
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
     * Rows in the CENTRAL database, scoped to THIS workspace, inside the window — the rows a
     * mis-routed query would happily return. Written with the query builder rather than through the
     * models, because the models are exactly the thing under test and would route them away.
     */
    private function plantCentralDecoys(): void
    {
        $central = DB::connection(config('database.default'));

        foreach ([
            ['title' => 'CENTRAL all-day decoy', 'all_day' => true, 'start_date' => '2026-08-12', 'starts_at' => null, 'ends_at' => null],
            ['title' => 'CENTRAL timed decoy', 'all_day' => false, 'start_date' => null, 'starts_at' => '2026-08-20 09:00:00', 'ends_at' => null],
        ] as $decoy) {
            $id = (string) Str::uuid();
            $this->centralDecoyEventIds[] = $id;

            $central->table('calendar_events')->insert($decoy + [
                'id' => $id,
                'workspace_id' => $this->workspace->id,
                'description' => null,
                'subject_type' => null,
                'subject_id' => null,
                'creator_id' => $this->user->id,
                'creator_type' => 'user',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            2,
            (int) $central->table('calendar_events')->where('workspace_id', $this->workspace->id)->count(),
            'the decoys must really be in the central database, or the negative assertion proves nothing',
        );
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

        if ($this->centralDecoyEventIds !== []) {
            $central->table('calendar_events')->whereIn('id', $this->centralDecoyEventIds)->delete();
            $this->centralDecoyEventIds = [];
        }

        if ($this->workspace !== null) {
            $central->table('workspace_user')->where('workspace_id', $this->workspace->id)->delete();
        }

        $this->workspace?->forceDelete();
        $this->user?->forceDelete();

        parent::tearDown();
    }
}
