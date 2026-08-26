<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use App\Support\Recurrence\Enums\ScheduleDayMode;
use App\Support\Recurrence\Enums\ScheduleTimeMode;
use App\Support\Recurrence\ScheduleEngine;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * THE RECURRENCE CHAPTER, ON AN OWN-DATABASE WORKSPACE. Guarded behind TENANT_DB_TESTS=1 (it issues
 * CREATE DATABASE / DROP DATABASE), the same way {@see CalendarTenantDatabaseTest} is — which it
 * deliberately does not extend, so each can be run alone.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY A SECOND TENANT FILE, WHEN ONE ALREADY EXISTS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The sibling file proves the tenant mirror has the recurrence COLUMNS. It proves nothing about what
 * happens in them. The whole of the repeating chapter — stamping a rule, projecting it onto the grid,
 * and the four scoped operations — had never been EXECUTED in own-database mode, and this is precisely
 * the surface that fails silently there: in shared mode every query lands in the one database there
 * is, so nothing about the routing is ever exercised and every assertion passes for the wrong reason.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE SPLIT THIS FILE EXISTS TO PIN — AND THE ONE NEW THING IN IT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `workspaces` is CENTRAL. `calendar_events` is NOT. A rule's `tz` and its single hour are STAMPED at
 * write time from the central row while the event itself lands in the tenant one, so every write here
 * crosses the seam twice. A resolver that queried the tenant database for `workspaces` would break the
 * calendar for own-database workspaces only; one that silently fell back to `config('app.timezone')`
 * would keep rendering, in the wrong zone, with every day boundary off by the offset and nothing to
 * see. The workspace zone below is therefore NOT the application default (which this file pins to UTC
 * rather than inheriting from the developer's `.env`), so a fallback cannot masquerade as success.
 *
 * What this chapter ADDED to that seam is the first TRANSACTION in
 * {@see \App\Modules\Calendar\Services\CalendarEventService}. Two of its four scoped operations are two
 * writes that mean one thing, and the service opens the transaction on the ROW'S connection rather than
 * with `DB::transaction()`, which would open it on the DEFAULT one. In shared mode those are the same
 * connection and the distinction is invisible — a test there passes identically either way. On an
 * own-database workspace they are two different connections, and a transaction on the wrong one is
 * atomicity that reads as present in the source and is absent in production, for exactly the customers
 * nobody tests on.
 *
 * {@see test_a_failed_detach_rolls_back_the_exclusion_on_the_tenant_connection} and its split twin are
 * the only two assertions in the repository that can tell the two apart: they make the SECOND write
 * fail and demand that the FIRST one be gone. With `DB::transaction()` the first write would have been
 * committed on the tenant connection by the time the central transaction rolled back nothing, and both
 * would fail.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE STRONGEST ASSERTIONS HERE ARE THE NEGATIVE ONES
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A central DECOY SERIES is planted carrying this very workspace's id, inside the window, with a valid
 * stamped descriptor — the row a mis-routed projection would find and draw four times. Without it, a
 * read that went to the central database would still make every positive assertion pass, because the
 * data would be found: in the wrong place.
 *
 * NOTE: no RefreshDatabase — the provisioning DDL cannot run inside the suite transaction — so every
 * central row this file creates is removed by hand in tearDown. That absence is also what makes the
 * rollback assertions mean anything: a suite-level transaction would make every write here a savepoint
 * and the two connections indistinguishable again.
 */
class CalendarRecurrenceTenantDatabaseTest extends TestCase
{
    /** Not the application default, so a fallback can never look like a success. */
    private const WORKSPACE_TIMEZONE = 'Europe/Warsaw';

    /** 2026-09-07 is a Monday — the anchor of every weekly fixture below. */
    private const MONDAY = '2026-09-07';

    /** Every Monday of September 2026. */
    private const SEPTEMBER_MONDAYS = ['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28'];

    private const WINDOW_FROM = '2026-09-01';

    private const WINDOW_TO = '2026-09-30';

    /** The message the injected failure carries, so a REAL error is never mistaken for the injected one. */
    private const INJECTED_FAILURE = 'the second half of this write fails, on purpose';

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

        // Named, never inherited: the suite reads the developer's `.env`, and half of what this file
        // asserts is WHICH CLOCK a rule was stamped on. Pinning the app default is what makes "the
        // workspace's zone came back" distinguishable from "a fallback came back".
        config(['app.timezone' => 'UTC']);
        $this->assertNotSame(self::WORKSPACE_TIMEZONE, config('app.timezone'));
    }

    // ── the rule, written across the seam ────────────────────────────────────────

    /**
     * A RULE IS STAMPED FROM THE CENTRAL ROW AND STORED IN THE TENANT ONE.
     *
     * Both halves of the stamp cross the connection boundary in this one request: the zone comes from
     * `workspaces.timezone` (CENTRAL) and the hour is read off the event's own anchor, which was itself
     * parsed in that zone because the payload sends no offset. If the resolver fell back to the app
     * default, the stamp would read `UTC` and the hour would read `07:00` — both of which this asserts
     * against, so the fallback is not merely unlikely but named.
     */
    public function test_a_rule_is_stamped_from_the_central_workspace_row_while_the_row_lands_in_the_tenant_database(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $id = $this->createWeeklySeries();

        $this->useTenant();

        $row = CalendarEvent::query()->findOrFail($id);

        $this->assertSame(
            self::WORKSPACE_TIMEZONE,
            $row->recurrence['tz'],
            'the rule was stamped with the application default — the CENTRAL workspaces row was not consulted',
        );
        $this->assertSame(
            ['09:00'],
            $row->recurrence['time']['at'],
            'the series hour must be the anchor read in the WORKSPACE zone, not in the app default',
        );
        $this->assertSame(ScheduleTimeMode::AT->value, $row->recurrence['time']['mode']);
        $this->assertSame([1], $row->recurrence['day']['weekdays'], 'the cadence itself must survive the crossing');

        // 09:00 Europe/Warsaw in September is 07:00Z. The same fact from the other side.
        $this->assertSame('2026-09-07T07:00:00+00:00', $row->starts_at->utc()->toIso8601String());

        // ── THE NEGATIVE ──────────────────────────────────────────────────────────
        $this->assertCentralHasNoneOf([$id]);
    }

    /**
     * "REPEAT N TIMES" IS WALKED TO A DAY ON THE TENANT PATH.
     *
     * The walk runs in the STAMPED zone, which came from the central table, so this is the count
     * resolution and the seam in one assertion. Eight Mondays from the 7th of September is the 26th of
     * October — the same day the shared-mode suite pins, which is the point: the answer must not depend
     * on which database the row lives in.
     */
    public function test_a_count_is_resolved_to_an_end_day_on_the_tenant_path(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $id = $this->createWeeklySeries(['count' => 8]);

        $this->useTenant();

        $row = CalendarEvent::query()->findOrFail($id);

        $this->assertSame('2026-10-26', $row->recurrenceUntilString());
        $this->assertArrayNotHasKey('count', $row->recurrence, 'the count is resolved once and never stored');

        $this->assertCentralHasNoneOf([$id]);
    }

    // ── the projection ───────────────────────────────────────────────────────────

    /**
     * THE GRID DRAWS THE TENANT'S SERIES AND NEVER THE CENTRAL DECOY.
     *
     * Four Mondays from one row — the projection itself — plus the two keys the write surface needs
     * back (`occurrence_date`, `recurring`), all of it read through the real endpoint so the connection
     * is chosen by middleware from a header, the way production chooses it.
     *
     * The decoy is a genuine SERIES rather than a single event: a mis-routed read would draw it four
     * times, which is the failure shape this is about, and a single-event decoy would understate it.
     */
    public function test_a_series_is_projected_from_the_tenant_database_and_never_from_the_central_one(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $this->plantCentralDecoySeries();

        $this->createWeeklySeries([], 'TENANT narada');

        $data = $this->readGrid();

        $this->assertSame(self::SEPTEMBER_MONDAYS, $this->days($data), 'one tenant row must draw four Mondays');
        $this->assertSame(self::SEPTEMBER_MONDAYS, array_column($data, 'occurrence_date'));

        foreach ($data as $occurrence) {
            $this->assertTrue($occurrence['recurring'], 'a projected occurrence must say it is one of a series');
            $this->assertStringStartsWith(
                'TENANT',
                $occurrence['title'],
                'a CENTRAL row reached an own-database workspace\'s grid: ' . $occurrence['title'],
            );
        }
    }

    /**
     * AN ALL-DAY SERIES — the OTHER half of the chapter, and a different code path end to end.
     *
     * Everything about a timed series crosses the seam as an INSTANT; everything about this one
     * crosses it as a DATE. The rule is stamped at the shared layer's day anchor instead of an hour
     * the user chose, the projection asks the day-shaped seam instead of the instant-shaped one, and
     * the anchor is read back off the DATE COLUMN rather than converted from a moment. None of that
     * shares a line with the timed path, so none of it was covered by the tests above.
     *
     * `Europe/Warsaw` is +02:00 here, so a day that went anywhere near an instant on this journey
     * would come back printed differently at one end of it.
     */
    public function test_an_all_day_series_is_stamped_projected_and_scoped_in_the_tenant_database(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $id = $this->asUser()
            ->postJson('/api/calendar/events', [
                'title' => 'TENANT dzien publikacji',
                'all_day' => true,
                'start_date' => self::MONDAY,
                'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->useTenant();

        $row = CalendarEvent::query()->findOrFail($id);

        // An all-day series has no hour to read off its anchor, so it takes the shared layer's day
        // anchor — the one wall-clock hour that exists exactly once in every timezone.
        $this->assertSame([ScheduleEngine::DAY_ANCHOR], $row->recurrence['time']['at']);
        $this->assertSame(self::WORKSPACE_TIMEZONE, $row->recurrence['tz']);

        // The date is RE-PRINTED, never converted — across a connection boundary as well.
        $this->assertSame(self::MONDAY, $row->startDateString());
        $this->assertNull($row->starts_at, 'an all-day row carries no instant, in either database');

        $data = $this->readGrid();

        $this->assertSame(self::SEPTEMBER_MONDAYS, $this->days($data));

        foreach ($data as $occurrence) {
            $this->assertTrue($occurrence['all_day']);
            $this->assertSame($occurrence['start_date'], $occurrence['occurrence_date']);
        }

        // …and the day the grid published is the day the write surface accepts, on the tenant path.
        $this->asUser()
            ->deleteJson('/api/calendar/events/' . $id, [
                'scope' => 'occurrence',
                'occurrence_date' => $data[2]['occurrence_date'],
            ])
            ->assertNoContent();

        $this->assertSame(
            ['2026-09-07', '2026-09-14', '2026-09-28'],
            $this->days($this->readGrid()),
        );

        $this->assertCentralHasNoneOf([$id]);
    }

    // ── the four scoped operations ───────────────────────────────────────────────

    /**
     * THE TWO SINGLE-STATEMENT SCOPES, against the tenant row, with the GRID re-read after each so the
     * rewrite is observed where a user would see it rather than only in a column.
     */
    public function test_the_single_statement_scopes_rewrite_the_tenant_row_and_the_grid_follows(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $id = $this->createWeeklySeries();

        // ONE OCCURRENCE REMOVED — a date added to the rule's own exclusions.
        $this->asUser()
            ->deleteJson('/api/calendar/events/' . $id, ['scope' => 'occurrence', 'occurrence_date' => '2026-09-14'])
            ->assertNoContent();

        $this->assertSame(
            ['2026-09-07', '2026-09-21', '2026-09-28'],
            $this->days($this->readGrid()),
            'the exclusion did not reach the grid — the rewrite and the read disagree across the seam',
        );

        // THIS ONE AND EVERY LATER ONE, REMOVED — the series is closed the day before.
        $this->asUser()
            ->deleteJson('/api/calendar/events/' . $id, ['scope' => 'following', 'occurrence_date' => '2026-09-28'])
            ->assertNoContent();

        $this->assertSame(['2026-09-07', '2026-09-21'], $this->days($this->readGrid()));

        $this->useTenant();

        $row = CalendarEvent::query()->findOrFail($id);
        $this->assertSame(['2026-09-14'], $row->recurrence['exclusions']['dates']);
        $this->assertSame('2026-09-27', $row->recurrenceUntilString());

        $this->assertCentralHasNoneOf([$id]);
    }

    /**
     * A DETACH — the module's other multi-statement write — performed against an own-database
     * workspace. The sibling file covers the SPLIT; this covers the one it does not.
     *
     * Both halves must land in the tenant database: the day excluded from the rule, and the edited
     * occurrence re-created as an ordinary non-repeating event. Half of that is an occurrence DELETED
     * by a request that asked for an EDIT.
     */
    public function test_a_detach_writes_both_halves_to_the_tenant_database(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $id = $this->createWeeklySeries();

        $detachedId = $this->asUser()
            ->putJson('/api/calendar/events/' . $id, [
                'title' => 'Narada, tym razem po poludniu',
                'all_day' => false,
                'starts_at' => '2026-09-14T15:00:00',
                'scope' => 'occurrence',
                'occurrence_date' => '2026-09-14',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNotSame($id, $detachedId, 'a detach produces a NEW row');

        $this->useTenant();

        $this->assertSame(
            ['2026-09-14'],
            CalendarEvent::query()->findOrFail($id)->recurrence['exclusions']['dates'],
            'the day was not excluded in the TENANT database',
        );

        $detached = CalendarEvent::query()->findOrFail($detachedId);
        $this->assertNull($detached->recurrence, 'one occurrence of a series is not itself a series');
        $this->assertSame('2026-09-14T13:00:00+00:00', $detached->starts_at->utc()->toIso8601String());

        $this->assertCentralHasNoneOf([$id, $detachedId]);
    }

    // ── the transaction, on the connection the row is on ─────────────────────────

    /**
     * THE CROWN OF THIS FILE. A detach whose SECOND write fails must leave the FIRST one undone —
     * IN THE TENANT DATABASE.
     *
     * This is the only assertion in the repository that can distinguish `DB::transaction()` from a
     * transaction opened on the row's own connection. With the former, the exclusion UPDATE would have
     * been committed on the tenant connection (which had no transaction open at all) while the central
     * transaction rolled back nothing, and the user's edit would have become a deletion — reported as
     * a failure, so nobody would look for the missing occurrence.
     *
     * The failure is injected from a MODEL EVENT, which is the only place a test can make the second
     * write fail without reaching past the service and staging something the service would never do.
     */
    public function test_a_failed_detach_rolls_back_the_exclusion_on_the_tenant_connection(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $id = $this->createWeeklySeries();

        $outcome = $this->withFailingInsert(fn () => $this->asUser()->putJson('/api/calendar/events/' . $id, [
            'title' => 'Narada, tym razem po poludniu',
            'all_day' => false,
            'starts_at' => '2026-09-14T15:00:00',
            'scope' => 'occurrence',
            'occurrence_date' => '2026-09-14',
        ]));

        $this->assertFalse($outcome, 'the injected failure did not reach the caller — this test proved nothing');

        $this->useTenant();

        $this->assertNull(
            CalendarEvent::query()->findOrFail($id)->recurrence['exclusions'] ?? null,
            'the exclusion SURVIVED a detach whose second half failed: the transaction was opened on the '
            . 'wrong connection, so the occurrence was deleted instead of edited',
        );

        $this->assertSame(
            1,
            (int) CalendarEvent::query()->count(),
            'the detached half was committed even though its own insert failed',
        );
    }

    /**
     * THE SAME, FOR THE SPLIT. Its first write CLOSES the outgoing series; if that stands alone the
     * appointment simply stops one day and is never replaced — and the row that was supposed to carry
     * it forward does not exist.
     *
     * Deliberately split at the SECOND occurrence: at the first, the service short-circuits to a plain
     * whole-event update, which is one statement and takes no transaction at all, so the fixture would
     * be exercising a path this test is not about.
     */
    public function test_a_failed_split_rolls_back_the_closing_end_date_on_the_tenant_connection(): void
    {
        $this->provisionOwnDatabaseWorkspace();

        $id = $this->createWeeklySeries();

        $outcome = $this->withFailingInsert(fn () => $this->asUser()->putJson('/api/calendar/events/' . $id, [
            'title' => 'Narada, teraz w srody',
            'all_day' => false,
            'starts_at' => '2026-09-23T09:00:00',
            'scope' => 'following',
            'occurrence_date' => '2026-09-21',
            'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [3]]],
        ]));

        $this->assertFalse($outcome, 'the injected failure did not reach the caller — this test proved nothing');

        $this->useTenant();

        $this->assertNull(
            CalendarEvent::query()->findOrFail($id)->recurrenceUntilString(),
            'the outgoing series stayed CLOSED after a split whose second half failed: the series stops '
            . 'on a day nobody chose and nothing replaces it',
        );

        $this->assertSame([1], CalendarEvent::query()->findOrFail($id)->recurrence['day']['weekdays']);
        $this->assertSame(1, (int) CalendarEvent::query()->count());
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────

    private function asUser(): self
    {
        parent::actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    /**
     * A weekly-on-Mondays series anchored on {@see MONDAY}, through the REAL write path.
     *
     * Never assembled by setting columns: a hand-built descriptor is one whose anchor nobody checked,
     * and a fixture whose anchor is not its own first occurrence is a row production cannot create — so
     * an assertion against it would keep passing after the invariant broke.
     *
     * The instant is sent ZONE-LESS on purpose. It has to be read in the workspace zone, which lives in
     * the CENTRAL table while this write goes to the tenant one.
     *
     * @param  array<string, mixed>  $extra  extra keys inside the `recurrence` block
     */
    private function createWeeklySeries(array $extra = [], string $title = 'Cotygodniowa narada'): string
    {
        return $this->asUser()
            ->postJson('/api/calendar/events', [
                'title' => $title,
                'all_day' => false,
                'starts_at' => self::MONDAY . 'T09:00:00',
                'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]] + $extra,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * One calendar read of the EVENT source alone, over September 2026.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readGrid(): array
    {
        return $this->asUser()
            ->getJson('/api/calendar/occurrences?' . http_build_query([
                'from' => self::WINDOW_FROM,
                'to' => self::WINDOW_TO,
                'sources' => ['event'],
            ]))
            ->assertOk()
            ->json('data');
    }

    /**
     * The calendar days a response drew, in order.
     *
     * Deliberately NOT read off `occurrence_date`, which would make every projection assertion here
     * agree with itself by construction: that field is what the source CLAIMS the day is, and these
     * assertions are about where the square actually landed.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @return array<int, string>
     */
    private function days(array $data): array
    {
        return array_map(
            fn (array $occurrence): string => $occurrence['all_day']
                ? $occurrence['start_date']
                : \Carbon\CarbonImmutable::parse($occurrence['starts_at'])
                    ->setTimezone(self::WORKSPACE_TIMEZONE)
                    ->format('Y-m-d'),
            $data,
        );
    }

    /**
     * Run a request with the NEXT insert into `calendar_events` made to fail, and report whether the
     * caller was told.
     *
     * Returns FALSE when the failure reached the caller (as a propagated throwable or as a 5xx) and
     * TRUE when the request answered success anyway — which would mean the injection missed, and every
     * rollback assertion after it would be vacuous.
     *
     * @param  \Closure(): \Illuminate\Testing\TestResponse  $request
     */
    private function withFailingInsert(\Closure $request): bool
    {
        CalendarEvent::creating(function (): void {
            throw new RuntimeException(self::INJECTED_FAILURE);
        });

        try {
            $response = $request();
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== self::INJECTED_FAILURE) {
                throw $exception;
            }

            return false;
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            CalendarEvent::flushEventListeners();
        }

        return $response->status() < 500;
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
     * A REPEATING row in the CENTRAL database, scoped to THIS workspace, anchored inside the window —
     * the row a mis-routed projection would find and draw four times over.
     *
     * Written with the query builder rather than through the model, because the model is exactly the
     * thing under test and would route it away. The descriptor is a valid STAMPED one, so a read that
     * reached it would really project it rather than degrading it to a single event and understating
     * the damage.
     */
    private function plantCentralDecoySeries(): void
    {
        $central = DB::connection(config('database.default'));

        $id = (string) Str::uuid();
        $this->centralDecoyEventIds[] = $id;

        $central->table('calendar_events')->insert([
            'id' => $id,
            'workspace_id' => $this->workspace->id,
            'title' => 'CENTRAL decoy series',
            'description' => null,
            'all_day' => false,
            'start_date' => null,
            'starts_at' => '2026-09-07 07:00:00',
            'ends_at' => null,
            'recurrence' => json_encode([
                'time' => ['mode' => ScheduleTimeMode::AT->value, 'at' => ['09:00']],
                'day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]],
                'tz' => self::WORKSPACE_TIMEZONE,
            ]),
            'recurrence_until' => null,
            'subject_type' => null,
            'subject_id' => null,
            'creator_id' => $this->user->id,
            'creator_type' => 'user',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            1,
            (int) $central->table('calendar_events')->where('workspace_id', $this->workspace->id)->count(),
            'the decoy must really be in the central database, or the negative assertion proves nothing',
        );
    }

    /** @param  array<int, string>  $ids */
    private function assertCentralHasNoneOf(array $ids): void
    {
        $central = DB::connection(config('database.default'));

        $this->assertSame(
            0,
            (int) $central->table('calendar_events')->whereIn('id', $ids)->count(),
            'an own-database workspace must not leave its events in the central database',
        );

        $this->assertSame(
            count($this->centralDecoyEventIds),
            (int) $central->table('calendar_events')->where('workspace_id', $this->workspace->id)->count(),
            'a row scoped to this workspace appeared centrally — this is the assertion a hardcoded '
            . 'connection would fail',
        );
    }

    private function useTenant(): void
    {
        app(TenantContext::class)->set($this->workspace);
        app(TenantManager::class)->configure($this->workspace);
    }

    protected function tearDown(): void
    {
        CalendarEvent::flushEventListeners();
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
