<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Calendar\Contracts\CalendarSource;
use App\Modules\Calendar\DTOs\CalendarSourceResult;
use App\Modules\Calendar\DTOs\CalendarWindow;
use App\Modules\Calendar\Services\CalendarSourceRegistry;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowScheduleService;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * R3 B1+B2 — the whole calendar READ path: the window contract, the workspace-timezone decision, the
 * all-day/instant split, and the three sources that register themselves from their own modules.
 */
class CalendarOccurrencesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id);

        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function actingAsMember(): self
    {
        $this->actingAs($this->owner)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    /** @param  array<string, mixed>  $query */
    private function read(array $query = [])
    {
        return $this->getJson('/api/calendar/occurrences?' . http_build_query($query + [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]));
    }

    // ---- the window contract --------------------------------------------------

    public function test_it_refuses_a_request_without_an_active_workspace(): void
    {
        // No X-Workspace-Id: the sources' workspace scopes would go inert and the grid would aggregate
        // across tenants, so RequireWorkspace refuses before anything is looked up.
        $this->actingAs($this->owner)
            ->getJson('/api/calendar/occurrences?from=2026-08-01&to=2026-08-31')
            ->assertStatus(400);
    }

    public function test_it_refuses_an_unauthenticated_request(): void
    {
        $this->getJson('/api/calendar/occurrences?from=2026-08-01&to=2026-08-31')
            ->assertUnauthorized();
    }

    /**
     * The endpoint has no Policy — it deliberately leans on ResolveWorkspace proving membership, which
     * makes that the one security claim in this batch worth asserting rather than describing.
     */
    public function test_a_non_member_cannot_read_a_workspaces_calendar(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->withHeader('X-Workspace-Id', $this->workspace->id)
            ->getJson('/api/calendar/occurrences?from=2026-08-01&to=2026-08-31')
            ->assertForbidden();
    }

    public function test_it_accepts_a_window_at_the_cap_and_refuses_one_day_more(): void
    {
        // 62 days inclusive — covers the 42-day month grid and the 31-day agenda.
        $this->actingAsMember()
            ->getJson('/api/calendar/occurrences?from=2026-08-01&to=2026-10-01')
            ->assertOk();

        $this->actingAsMember()
            ->getJson('/api/calendar/occurrences?from=2026-08-01&to=2026-10-02')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_it_refuses_a_reversed_or_malformed_window(): void
    {
        $this->actingAsMember()
            ->getJson('/api/calendar/occurrences?from=2026-08-10&to=2026-08-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');

        // An INSTANT where a calendar day belongs. Accepting it would be the first step towards the
        // conversion this module exists to refuse.
        $this->actingAsMember()
            ->getJson('/api/calendar/occurrences?from=2026-08-01T00:00:00Z&to=2026-08-31')
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    public function test_an_empty_workspace_returns_a_well_formed_envelope(): void
    {
        $response = $this->actingAsMember()->read()->assertOk();

        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.truncated', false);
        $response->assertJsonPath('meta.truncations', []);
        $response->assertJsonPath('meta.unavailable_sources', []);

        // Every registered source is named and labelled, so the filter chips need no client-side
        // knowledge of what a source is. `publication` joined in R4 B1 from a module that did not exist
        // when this was written — an INVENTORY line, not a Calendar change.
        $ids = array_column($response->json('meta.sources'), 'id');
        sort($ids);
        $this->assertSame(['event', 'publication', 'task', 'workflow_run', 'workflow_schedule'], $ids);

        foreach ($response->json('meta.sources') as $source) {
            $this->assertNotSame('', $source['label']);
            $this->assertNotSame($source['id'], $source['label'], 'a source label must be prose, not its id');
        }
    }

    // ---- the timezone decision ------------------------------------------------

    public function test_it_reports_the_application_timezone_when_the_workspace_has_none(): void
    {
        $this->assertNull($this->workspace->timezone);

        $this->actingAsMember()->read()
            ->assertOk()
            ->assertJsonPath('meta.timezone', config('app.timezone'));
    }

    public function test_it_reports_the_workspace_timezone_when_set(): void
    {
        $this->workspace->update(['timezone' => 'Europe/Warsaw']);

        $this->actingAsMember()->read()
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'Europe/Warsaw');
    }

    public function test_the_client_cannot_override_the_timezone(): void
    {
        $this->workspace->update(['timezone' => 'Europe/Warsaw']);

        // A `tz` parameter is not part of the contract: a shared grid must have ONE answer to whose
        // midnight it is drawn against. An unknown parameter is ignored, never honoured.
        $this->actingAsMember()->read(['tz' => 'America/Los_Angeles'])
            ->assertOk()
            ->assertJsonPath('meta.timezone', 'Europe/Warsaw');
    }

    public function test_an_unusable_stored_timezone_degrades_to_the_application_default(): void
    {
        // Only reachable by a write that bypassed validation (a console fix, a restored dump). Taking
        // the whole calendar down for one bad string would be the wrong trade.
        Workspace::withoutEvents(fn () => $this->workspace->forceFill(['timezone' => 'Mars/Olympus'])->save());

        $this->actingAsMember()->read()
            ->assertOk()
            ->assertJsonPath('meta.timezone', config('app.timezone'));
    }

    public function test_the_workspace_timezone_is_set_through_the_existing_update_endpoint(): void
    {
        $this->actingAsMember()
            ->patchJson('/api/workspaces/' . $this->workspace->id, [
                'name' => $this->workspace->name,
                'timezone' => 'Europe/Warsaw',
            ])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Europe/Warsaw');

        // Explicit null clears it back to inheriting the application timezone...
        $this->actingAsMember()
            ->patchJson('/api/workspaces/' . $this->workspace->id, [
                'name' => $this->workspace->name,
                'timezone' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.timezone', null);

        $this->workspace->update(['timezone' => 'Europe/Warsaw']);

        // ...while an ABSENT key leaves it alone. Collapsing the two would silently reset every
        // workspace's timezone on the first rename from a client that does not send the field.
        $this->actingAsMember()
            ->patchJson('/api/workspaces/' . $this->workspace->id, ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.timezone', 'Europe/Warsaw');

        $this->actingAsMember()
            ->patchJson('/api/workspaces/' . $this->workspace->id, [
                'name' => $this->workspace->name,
                'timezone' => 'Not/AZone',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');
    }

    // ---- the all-day / instant split -----------------------------------------

    public function test_a_task_deadline_is_an_all_day_occurrence_that_is_never_converted(): void
    {
        // A zone 14 hours from UTC: any conversion of a bare date moves it a whole day, so this fails
        // loudly if the deadline is ever parsed as an instant.
        $this->workspace->update(['timezone' => 'Pacific/Kiritimati']);

        $task = Task::factory()->create([
            'title' => 'Ship the thing',
            'deadline' => '2026-08-09',
            'status' => TaskStatus::IN_PROGRESS,
            'priority' => TaskPriority::URGENT,
            'creator_id' => $this->owner->id,
            'assigned_id' => $this->owner->id,
        ]);

        $response = $this->actingAsMember()->read(['sources' => ['task']])->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.all_day', true);
        $response->assertJsonPath('data.0.start_date', '2026-08-09');
        $response->assertJsonPath('data.0.starts_at', null);
        $response->assertJsonPath('data.0.ends_at', null);
        $response->assertJsonPath('data.0.id', 'task:' . $task->id);
        $response->assertJsonPath('data.0.subject.type', 'task');
        $response->assertJsonPath('data.0.subject.id', $task->id);
        $response->assertJsonPath('data.0.editable', false);
        $response->assertJsonPath('data.0.dense', false);
        // A deadline is a ROW, not a projection: one task, one square, nothing computed from a rule.
        $response->assertJsonPath('data.0.recurring', false);
        $response->assertJsonPath('data.0.occurrence_date', null);
        $response->assertJsonPath('data.0.color', 'danger');
        $response->assertJsonPath('data.0.badge.label', __('tasks.status.in_progress'));

        // The very same read from the other side of the planet answers identically.
        $this->workspace->update(['timezone' => 'Pacific/Midway']);

        $this->actingAsMember()->read(['sources' => ['task']])
            ->assertOk()
            ->assertJsonPath('data.0.start_date', '2026-08-09');
    }

    public function test_task_deadlines_outside_the_window_and_in_the_bin_are_excluded(): void
    {
        $base = [
            'creator_id' => $this->owner->id,
            'assigned_id' => $this->owner->id,
        ];

        Task::factory()->create($base + ['title' => 'inside', 'deadline' => '2026-08-15']);
        Task::factory()->create($base + ['title' => 'before', 'deadline' => '2026-07-31']);
        Task::factory()->create($base + ['title' => 'after', 'deadline' => '2026-09-01']);
        Task::factory()->create($base + ['title' => 'no deadline', 'deadline' => null]);

        $archived = Task::factory()->create($base + ['title' => 'archived', 'deadline' => '2026-08-16']);
        $archived->forceFill(['archived_at' => now()])->save();

        Task::factory()->create($base + ['title' => 'trashed', 'deadline' => '2026-08-17'])->delete();

        $response = $this->actingAsMember()->read(['sources' => ['task']])->assertOk();

        $this->assertSame(['inside'], array_column($response->json('data'), 'title'));
    }

    public function test_the_window_edges_are_inclusive(): void
    {
        $base = ['creator_id' => $this->owner->id, 'assigned_id' => $this->owner->id];

        Task::factory()->create($base + ['title' => 'first day', 'deadline' => '2026-08-01']);
        Task::factory()->create($base + ['title' => 'last day', 'deadline' => '2026-08-31']);

        $titles = array_column(
            $this->actingAsMember()->read(['sources' => ['task']])->assertOk()->json('data'),
            'title'
        );

        sort($titles);
        $this->assertSame(['first day', 'last day'], $titles);
    }

    // ---- schedules: the future half ------------------------------------------

    public function test_a_schedule_projects_only_the_future(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        Workflow::factory()->active()->scheduled()->create([
            'name' => 'Daily digest',
            'creator_id' => $this->owner->id,
        ]);

        $response = $this->actingAsMember()->read(['sources' => ['workflow_schedule']])->assertOk();

        $starts = array_column($response->json('data'), 'starts_at');
        $this->assertNotEmpty($starts);

        foreach ($starts as $startsAt) {
            $this->assertTrue(
                CarbonImmutable::parse($startsAt)->greaterThanOrEqualTo(CarbonImmutable::parse('2026-08-15T12:00:00Z')),
                'a schedule must never claim to describe a day that has already passed: ' . $startsAt
            );
        }

        $response->assertJsonPath('data.0.all_day', false);
        $response->assertJsonPath('data.0.start_date', null);
        $response->assertJsonPath('data.0.ends_at', null);
        $response->assertJsonPath('data.0.subject.type', 'workflow');
        $response->assertJsonPath('data.0.badge.label', __('workflows.calendar.scheduled_badge'));

        CarbonImmutable::setTestNow();
    }

    public function test_a_schedule_occurrence_id_is_stable_across_reads(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        Workflow::factory()->active()->scheduled()->create([
            'name' => 'Daily digest',
            'creator_id' => $this->owner->id,
        ]);

        $first = $this->actingAsMember()->read(['sources' => ['workflow_schedule']])->json('data.0.id');
        $second = $this->actingAsMember()->read(['sources' => ['workflow_schedule']])->json('data.0.id');

        $this->assertSame($first, $second);
        $this->assertStringStartsWith('workflow_schedule:', $first);

        CarbonImmutable::setTestNow();
    }

    public function test_an_inactive_or_non_schedule_workflow_is_not_projected(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        Workflow::factory()->inactive()->scheduled()->create([
            'name' => 'Switched off',
            'creator_id' => $this->owner->id,
        ]);

        Workflow::factory()->active()->create([
            'name' => 'Form triggered',
            'creator_id' => $this->owner->id,
        ]);

        $this->actingAsMember()->read(['sources' => ['workflow_schedule']])
            ->assertOk()
            ->assertJsonPath('data', []);

        CarbonImmutable::setTestNow();
    }

    /**
     * THE PRE-FILTER'S CORRECTNESS, and the trap it must not fall into.
     *
     * A workflow whose next fire is past the end of the window is not projected at all — that is what
     * makes a workspace full of sparse automations affordable. The failure mode of that optimization is
     * silent: a DENSE cadence also has occurrences far in the future, and an implementation that
     * reasoned "this workflow's next_due_at is beyond the window" from the wrong field, or that filtered
     * on a stale value, would erase a minute-cadence automation from a grid it fills.
     */
    public function test_a_dense_cadence_is_never_filtered_out_by_the_next_due_pre_filter(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        // Its next fire is a minute away; it also fires continuously through the window and far beyond.
        Workflow::factory()->active()->create([
            'name' => 'Every minute',
            'creator_id' => $this->owner->id,
            'trigger_type' => 'schedule',
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'every_minutes', 'minutes' => 1]]],
            'next_due_at' => CarbonImmutable::parse('2026-08-15T12:01:00Z'),
        ]);

        $data = $this->actingAsMember()->read(['sources' => ['workflow_schedule']])->assertOk()->json('data');

        $this->assertNotEmpty($data, 'a dense cadence must never be pre-filtered away');

        CarbonImmutable::setTestNow();
    }

    /**
     * `dense: true` on its own is half a sentence. It says "there is more of this than you are seeing"
     * and gives a client nothing to write but "Series — showing 64", which a reader could have counted.
     * The schedule source knows the interval, so it says it — as PROSE it translated itself, exactly
     * like a badge label, so a fifth source with a cadence needs no frontend change to be understood.
     */
    public function test_a_dense_schedule_carries_its_cadence_as_translated_prose(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        Workflow::factory()->active()->create([
            'name' => 'Every five minutes',
            'creator_id' => $this->owner->id,
            'trigger_type' => 'schedule',
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'every_minutes', 'minutes' => 5]]],
            'next_due_at' => CarbonImmutable::parse('2026-08-15T12:05:00Z'),
        ]);

        $data = $this->actingAsMember()->read(['sources' => ['workflow_schedule']])->assertOk()->json('data');

        $this->assertNotEmpty($data);
        // The marker and the explanation travel together — the flag is only worth rendering because
        // there is something to say beside it.
        $this->assertTrue($data[0]['dense']);
        $this->assertSame(__('workflows.calendar.cadence.every_minutes', ['count' => 5]), $data[0]['cadence_label']);

        // Every square this source draws is computed from a cadence and has no row behind it.
        $this->assertTrue($data[0]['recurring']);

        // …and it carries NO occurrence date, deliberately: that field is the name a subject's own
        // write surface addresses ONE occurrence by, and a schedule firing has none. Filling it would
        // publish an identifier that addresses nothing.
        $this->assertNull($data[0]['occurrence_date']);

        CarbonImmutable::setTestNow();
    }

    /**
     * An interval's optional active window is part of the same claim: 64 dots on a day mean something
     * different when the series only runs office hours.
     */
    public function test_an_interval_window_is_included_in_the_cadence(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        Workflow::factory()->active()->create([
            'name' => 'Office hours ping',
            'creator_id' => $this->owner->id,
            'trigger_type' => 'schedule',
            'trigger_config' => ['schedule' => ['time' => [
                'mode' => 'every_minutes', 'minutes' => 5, 'from' => '09:00', 'to' => '17:00',
            ]]],
            'next_due_at' => CarbonImmutable::parse('2026-08-17T09:00:00Z'),
        ]);

        $data = $this->actingAsMember()->read(['sources' => ['workflow_schedule']])->assertOk()->json('data');

        $this->assertNotEmpty($data);
        $this->assertStringContainsString('09:00–17:00', (string) $data[0]['cadence_label']);

        CarbonImmutable::setTestNow();
    }

    /**
     * A FIXED-TIMES schedule reports no cadence, and that is a correctness decision rather than a gap:
     * an honest sentence for `at` would have to include the day and month axes ("daily at 09:00" is a
     * lie for a schedule that only fires on the 1st of December), and humanizing all three axes is a
     * different piece of work. Null is the truthful answer, and the field is optional so a client reads
     * absent as absent.
     */
    public function test_a_fixed_times_schedule_reports_no_cadence(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        Workflow::factory()->active()->create([
            'name' => 'Daily at nine',
            'creator_id' => $this->owner->id,
            'trigger_type' => 'schedule',
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'at', 'at' => ['09:00']]]],
            'next_due_at' => CarbonImmutable::parse('2026-08-16T09:00:00Z'),
        ]);

        $data = $this->actingAsMember()->read(['sources' => ['workflow_schedule']])->assertOk()->json('data');

        $this->assertNotEmpty($data);
        $this->assertFalse($data[0]['dense']);
        $this->assertNull($data[0]['cadence_label']);

        // THE CASE `recurring` EXISTS FOR, and the reason it is not "cadence_label is not null": this
        // schedule REPEATS and has no sentence to say about how often. A client inferring the marker
        // from the prose would call every fixed-times automation a one-off.
        $this->assertTrue($data[0]['recurring']);

        CarbonImmutable::setTestNow();
    }

    /**
     * The field is present on EVERY occurrence, from every source, and null for the sources that have
     * no notion of a cadence. An absent key and a null one must not be two different things a client
     * has to handle.
     */
    public function test_a_source_without_a_cadence_reports_null_rather_than_omitting_the_field(): void
    {
        Task::factory()->create([
            'title' => 'Ship it',
            'creator_id' => $this->owner->id,
            'deadline' => '2026-08-12',
            'status' => TaskStatus::TO_DO,
            'priority' => TaskPriority::HIGH,
        ]);

        $data = $this->actingAsMember()->read(['sources' => ['task']])->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertArrayHasKey('cadence_label', $data[0]);
        $this->assertNull($data[0]['cadence_label']);
    }

    public function test_a_workflow_firing_after_the_window_is_not_projected_and_has_nothing_to_lose(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        // Yearly in December: its next fire is months past the end of an August window.
        $sparse = Workflow::factory()->active()->create([
            'name' => 'Annual report',
            'creator_id' => $this->owner->id,
            'trigger_type' => 'schedule',
            'trigger_config' => ['schedule' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'month_days', 'days' => [1]],
                'month' => ['mode' => 'months', 'months' => [12]],
            ]],
            'next_due_at' => CarbonImmutable::parse('2026-12-01T09:00:00Z'),
        ]);

        $response = $this->actingAsMember()->read(['sources' => ['workflow_schedule']])->assertOk();

        $this->assertSame([], $response->json('data'));

        // Skipping it is not a LOSS — there was nothing in the window to lose — so nothing is reported.
        // Proven independently of the filter: projecting the cadence directly finds nothing in range
        // either. If this ever disagrees, the pre-filter is hiding something.
        $this->assertSame([], $response->json('meta.truncations'));

        $projected = app(WorkflowScheduleService::class)->occurrencesFrom(
            $sparse->trigger_config['schedule'],
            CarbonImmutable::parse('2026-08-15T12:00:00Z'),
            64,
        );

        $inWindow = array_filter(
            $projected,
            fn ($moment): bool => $moment->between(
                CarbonImmutable::parse('2026-08-15T12:00:00Z'),
                CarbonImmutable::parse('2026-08-31T23:59:59Z'),
            ),
        );

        $this->assertSame([], $inWindow, 'the pre-filter and a direct projection must agree');

        CarbonImmutable::setTestNow();
    }

    public function test_an_unarmed_workflow_is_still_projected_rather_than_silently_dropped(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        // next_due_at NULL means "not armed", not "never fires". Excluding it would trade a guaranteed
        // silent loss for one skipped projection.
        Workflow::factory()->active()->create([
            'name' => 'Never armed',
            'creator_id' => $this->owner->id,
            'trigger_type' => 'schedule',
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'at', 'at' => ['09:00']]]],
            'next_due_at' => null,
        ]);

        $data = $this->actingAsMember()->read(['sources' => ['workflow_schedule']])->assertOk()->json('data');

        $this->assertNotEmpty($data);
        $this->assertSame('Never armed', $data[0]['title']);

        CarbonImmutable::setTestNow();
    }

    /**
     * The work cap: past it, whole automations are absent from the ENTIRE window. That is a different
     * statement from "the far end of the window was cut", and it is reported as one.
     */
    public function test_exceeding_the_item_budget_drops_whole_automations_and_says_so(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');
        config(['calendar.max_source_items' => 2]);

        foreach (['Alpha', 'Beta', 'Zulu'] as $index => $name) {
            Workflow::factory()->active()->create([
                'name' => $name,
                'creator_id' => $this->owner->id,
                'trigger_type' => 'schedule',
                'trigger_config' => ['schedule' => ['time' => ['mode' => 'at', 'at' => ['09:00']]]],
                'next_due_at' => CarbonImmutable::parse('2026-08-16T09:00:00Z')->addMinutes($index),
            ]);
        }

        $response = $this->actingAsMember()->read(['sources' => ['workflow_schedule']])->assertOk();

        $titles = array_values(array_unique(array_column($response->json('data'), 'title')));
        sort($titles);

        // The two whose next fire is soonest survive; the third is gone from the whole grid.
        $this->assertSame(['Alpha', 'Beta'], $titles);

        $response->assertJsonPath('meta.truncated', true);
        $this->assertContains(
            ['source' => 'workflow_schedule', 'kind' => 'items_dropped', 'omitted_occurrences' => null, 'affected_items' => null],
            $response->json('meta.truncations'),
        );

        CarbonImmutable::setTestNow();
    }

    public function test_a_minute_cadence_schedule_is_capped_and_flagged_dense(): void
    {
        // The arithmetic the cap exists for: a one-minute cadence over this window is 44 640
        // occurrences. Unbounded, it would be the only thing on the grid — and would take the browser
        // with it.
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        Workflow::factory()->active()->create([
            'name' => 'Every minute',
            'creator_id' => $this->owner->id,
            'trigger_type' => 'schedule',
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'every_minutes', 'minutes' => 1]]],
        ]);

        $response = $this->actingAsMember()->read(['sources' => ['workflow_schedule']])->assertOk();

        $data = $response->json('data');

        $this->assertCount((int) config('calendar.max_occurrences_per_source_item'), $data);

        foreach ($data as $occurrence) {
            $this->assertTrue(
                $occurrence['dense'],
                'a capped series must SAY it is a sample; a silent trim is indistinguishable from the truth'
            );
        }

        // ...and the loss is reported with its own KIND: one item repeats faster than it is drawn. Not
        // the same fact as "the window was cut", and not conveyed by the same words.
        $this->assertContains(
            ['source' => 'workflow_schedule', 'kind' => 'item_densified', 'omitted_occurrences' => null, 'affected_items' => 1],
            $response->json('meta.truncations'),
        );

        CarbonImmutable::setTestNow();
    }

    /**
     * THE RESPONSE CEILING BITING IN THE MIDDLE OF ONE SERIES.
     *
     * The schedule source is the only source that COUNTS its occurrences instead of reading rows, so it
     * is the only one that can stop mid-item — and it was the only one breaking the contract the whole
     * truncation shape exists for. It abandoned the rest of the series, flagged nothing `dense`, and
     * reported its loss only if the outer loop happened to reach another workflow. With a single
     * automation there was no next iteration, so a ten-occurrence series came back as three occurrences,
     * `dense: false` on every one of them, and one exact-looking `omitted_occurrences: 1` from the global
     * trim. Every signal in the payload said "this is the complete series"; seven occurrences were gone.
     *
     * Note WHY a second kind is needed rather than one: truncations merge per (source, KIND), so an
     * `items_dropped` or `item_densified` sibling does not touch the global trim's figure. The exact `1`
     * is only made honest by the source saying, in the SAME kind, that it also dropped an unknown number
     * — which is exactly what TaskDeadlineCalendarSource does when it stops loading at its own bound.
     */
    public function test_the_response_ceiling_biting_inside_one_series_is_reported_rather_than_hidden(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');
        config(['calendar.max_occurrences' => 3]);

        // Ten fires in the window, and a per-item budget (64) far above them — so the ONLY thing that can
        // cut this series is the response ceiling.
        Workflow::factory()->active()->create([
            'name' => 'Daily digest',
            'creator_id' => $this->owner->id,
            'trigger_type' => 'schedule',
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'at', 'at' => ['09:00']]]],
            'next_due_at' => '2026-08-16T09:00:00Z',
        ]);

        $response = $this->actingAsMember()
            ->read(['sources' => ['workflow_schedule'], 'from' => '2026-08-16', 'to' => '2026-08-25'])
            ->assertOk();

        $data = $response->json('data');

        $this->assertCount(3, $data);

        // What is shown is a SAMPLE of the series, and every occurrence says so where it is drawn.
        foreach ($data as $occurrence) {
            $this->assertTrue(
                $occurrence['dense'],
                'a series cut by the response ceiling is still a sample, and a silent sample is '
                . 'indistinguishable from a complete series'
            );
        }

        $truncations = collect($response->json('meta.truncations'))->keyBy('kind');

        $response->assertJsonPath('meta.truncated', true);

        // The item whose series was cut is named, with the count left null — its true length was never
        // computed, so there is no figure to give.
        $this->assertTrue($truncations->has('item_densified'));
        $this->assertSame(1, $truncations['item_densified']['affected_items']);
        $this->assertNull($truncations['item_densified']['omitted_occurrences']);

        // ...and the global trim's own figure is now UNKNOWN rather than a precise-looking 1. Seven
        // occurrences never reached the trim, so no number it could report would be the truth.
        $this->assertTrue($truncations->has('window_trimmed'));
        $this->assertNull(
            $truncations['window_trimmed']['omitted_occurrences'],
            'the trim can only count what reached it; reporting that figure as the whole loss is the '
            . 'precise-looking undercount this response shape replaced'
        );

        CarbonImmutable::setTestNow();
    }

    /**
     * The mirror image, and the reason the fix above cannot simply flag everything: a series that fits
     * ENTIRELY inside the budget is complete, says nothing about density, and reports no loss at all.
     */
    public function test_a_series_that_fits_is_neither_flagged_nor_reported(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        Workflow::factory()->active()->create([
            'name' => 'Daily digest',
            'creator_id' => $this->owner->id,
            'trigger_type' => 'schedule',
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'at', 'at' => ['09:00']]]],
            'next_due_at' => '2026-08-16T09:00:00Z',
        ]);

        $response = $this->actingAsMember()
            ->read(['sources' => ['workflow_schedule'], 'from' => '2026-08-16', 'to' => '2026-08-25'])
            ->assertOk();

        $data = $response->json('data');

        $this->assertCount(10, $data);
        $this->assertSame([false], array_values(array_unique(array_column($data, 'dense'))));
        $this->assertSame([], $response->json('meta.truncations'));

        CarbonImmutable::setTestNow();
    }

    // ---- runs: the past half --------------------------------------------------

    public function test_a_run_is_a_past_occurrence_carrying_its_state(): void
    {
        $workflow = Workflow::factory()->create([
            'name' => 'Nightly export',
            'creator_id' => $this->owner->id,
        ]);

        $run = WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'state' => WorkflowRunState::FAILED,
            'started_at' => '2026-08-10T08:00:00Z',
            'finished_at' => '2026-08-10T08:05:00Z',
        ]);

        $response = $this->actingAsMember()->read(['sources' => ['workflow_run']])->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', 'workflow_run:' . $run->id);
        $response->assertJsonPath('data.0.all_day', false);
        $response->assertJsonPath('data.0.title', 'Nightly export');
        $response->assertJsonPath('data.0.subject.type', 'workflow_run');
        $response->assertJsonPath('data.0.color', 'danger');
        $response->assertJsonPath('data.0.badge.label', __('workflows.run_states.failed'));
        $this->assertNotNull($response->json('data.0.ends_at'));

        // A run HAPPENED — it is a row, read back. The schedule that may have caused it is a different
        // source, and that one is the projection.
        $response->assertJsonPath('data.0.recurring', false);
        $response->assertJsonPath('data.0.occurrence_date', null);
    }

    public function test_a_run_that_never_started_is_not_on_the_calendar(): void
    {
        $workflow = Workflow::factory()->create(['creator_id' => $this->owner->id]);

        WorkflowRun::factory()->pending()->create(['workflow_id' => $workflow->id]);

        $this->actingAsMember()->read(['sources' => ['workflow_run']])
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_a_running_run_has_no_end(): void
    {
        $workflow = Workflow::factory()->create(['creator_id' => $this->owner->id]);

        WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'state' => WorkflowRunState::RUNNING,
            'started_at' => '2026-08-10T08:00:00Z',
            'finished_at' => null,
        ]);

        $this->actingAsMember()->read(['sources' => ['workflow_run']])
            ->assertOk()
            ->assertJsonPath('data.0.ends_at', null);
    }

    /**
     * The reason past and future are two sources rather than one: the run says what HAPPENED, the
     * schedule says what is PLANNED, and on a month grid both are on screen at once.
     */
    public function test_the_past_comes_from_runs_and_the_future_from_schedules(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        $workflow = Workflow::factory()->active()->scheduled()->create([
            'name' => 'Daily digest',
            'creator_id' => $this->owner->id,
        ]);

        WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'state' => WorkflowRunState::COMPLETED,
            'started_at' => '2026-08-10T09:00:00Z',
            'finished_at' => '2026-08-10T09:01:00Z',
        ]);

        $data = $this->actingAsMember()->read()->assertOk()->json('data');

        $past = array_values(array_filter($data, fn (array $o): bool => $o['source'] === 'workflow_run'));
        $future = array_values(array_filter($data, fn (array $o): bool => $o['source'] === 'workflow_schedule'));

        $this->assertCount(1, $past);
        $this->assertNotEmpty($future);

        // No schedule occurrence claims a day that is already history.
        foreach ($future as $occurrence) {
            $this->assertGreaterThanOrEqual('2026-08-15', substr($occurrence['starts_at'], 0, 10));
        }

        CarbonImmutable::setTestNow();
    }

    // ---- filtering, ordering, honesty ----------------------------------------

    public function test_the_source_filter_narrows_the_result_and_an_unknown_source_is_refused(): void
    {
        Task::factory()->create([
            'title' => 'a task',
            'deadline' => '2026-08-15',
            'creator_id' => $this->owner->id,
            'assigned_id' => $this->owner->id,
        ]);

        $response = $this->actingAsMember()->read(['sources' => ['workflow_run']])->assertOk();
        $this->assertSame([], $response->json('data'));

        // The CATALOGUE does not shrink with the filter. It is what the chips are built from, so
        // narrowing it to the current selection would delete the chip the user just switched off and
        // leave them no way to switch it back on.
        $ids = array_column($response->json('meta.sources'), 'id');
        sort($ids);
        $this->assertSame(['event', 'publication', 'task', 'workflow_run', 'workflow_schedule'], $ids);

        // A filter naming something the server does not have is a stale saved view or a typo. Serving a
        // cheerful subset would teach a user to trust a grid that is quietly lying.
        //
        // THE PROBE USED TO BE `publication`, chosen when that was a source nobody had built yet — and
        // R4 B1 built it, which turned this assertion from "an unknown id is refused" into "a real
        // source is refused" overnight. The replacement is a string that cannot become a source id,
        // because the thing under test is the REFUSAL, not any particular name.
        $this->actingAsMember()->read(['sources' => ['not_a_source']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sources.0');
    }

    /**
     * Every source must slice its own query from the SAME end the global trim cuts from. A source
     * ordering newest-first would hand over precisely the rows the merge discards first — contributing
     * nothing at all under overflow, while `meta.truncated` said only "some things were cut".
     */
    public function test_a_source_and_the_global_trim_agree_on_which_end_is_expendable(): void
    {
        config(['calendar.max_occurrences' => 3]);

        $workflow = Workflow::factory()->create(['creator_id' => $this->owner->id, 'name' => 'export']);

        foreach (['01', '02', '03', '04', '05'] as $day) {
            WorkflowRun::factory()->create([
                'workflow_id' => $workflow->id,
                'state' => WorkflowRunState::COMPLETED,
                'started_at' => "2026-08-{$day}T08:00:00Z",
                'finished_at' => "2026-08-{$day}T08:05:00Z",
            ]);
        }

        $response = $this->actingAsMember()->read(['sources' => ['workflow_run']])->assertOk();

        $response->assertJsonPath('meta.truncated', true);

        $days = array_map(
            fn (array $occurrence): string => substr($occurrence['starts_at'], 0, 10),
            $response->json('data')
        );

        // A contiguous run from the START of the window — not a middle slice, which is what a
        // newest-first source limit plus an earliest-first global trim would leave behind.
        $this->assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], $days);
    }

    public function test_occurrences_are_ordered_by_day_with_all_day_first(): void
    {
        $workflow = Workflow::factory()->create(['creator_id' => $this->owner->id, 'name' => 'run']);

        WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'state' => WorkflowRunState::COMPLETED,
            'started_at' => '2026-08-15T09:00:00Z',
            'finished_at' => '2026-08-15T09:30:00Z',
        ]);

        Task::factory()->create([
            'title' => 'same day deadline',
            'deadline' => '2026-08-15',
            'creator_id' => $this->owner->id,
            'assigned_id' => $this->owner->id,
        ]);

        Task::factory()->create([
            'title' => 'earlier deadline',
            'deadline' => '2026-08-02',
            'creator_id' => $this->owner->id,
            'assigned_id' => $this->owner->id,
        ]);

        $sources = array_column(
            $this->actingAsMember()->read(['sources' => ['task', 'workflow_run']])->assertOk()->json('data'),
            'source'
        );

        // 02 Aug (task), then 15 Aug: the all-day deadline before the timed run.
        $this->assertSame(['task', 'task', 'workflow_run'], $sources);
    }

    public function test_search_filters_by_the_subjects_own_name(): void
    {
        $base = ['creator_id' => $this->owner->id, 'assigned_id' => $this->owner->id];

        Task::factory()->create($base + ['title' => 'Quarterly report', 'deadline' => '2026-08-10']);
        Task::factory()->create($base + ['title' => 'Buy coffee', 'deadline' => '2026-08-11']);

        $titles = array_column(
            $this->actingAsMember()->read(['sources' => ['task'], 'q' => 'quarterly'])->assertOk()->json('data'),
            'title'
        );

        $this->assertSame(['Quarterly report'], $titles);
    }

    /**
     * A run has no name of its own and a schedule occurrence has no row — for both, the thing a user
     * types is the WORKFLOW's name, which is also what the square is labelled with. The filter and the
     * labels on screen have to agree.
     */
    public function test_search_reaches_the_workflow_name_behind_runs_and_schedules(): void
    {
        CarbonImmutable::setTestNow('2026-08-15T12:00:00Z');

        $wanted = Workflow::factory()->active()->scheduled()->create([
            'name' => 'Nightly export',
            'creator_id' => $this->owner->id,
        ]);

        $other = Workflow::factory()->active()->scheduled()->create([
            'name' => 'Weekly digest',
            'creator_id' => $this->owner->id,
        ]);

        foreach ([$wanted, $other] as $workflow) {
            WorkflowRun::factory()->create([
                'workflow_id' => $workflow->id,
                'state' => WorkflowRunState::COMPLETED,
                'started_at' => '2026-08-10T08:00:00Z',
                'finished_at' => '2026-08-10T08:05:00Z',
            ]);
        }

        $data = $this->actingAsMember()
            ->read(['sources' => ['workflow_run', 'workflow_schedule'], 'q' => 'nightly'])
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data);

        foreach ($data as $occurrence) {
            $this->assertSame('Nightly export', $occurrence['title']);
        }

        // Both halves actually contributed — otherwise this would pass with one of them silently broken.
        $sources = array_unique(array_column($data, 'source'));
        sort($sources);
        $this->assertSame(['workflow_run', 'workflow_schedule'], $sources);

        CarbonImmutable::setTestNow();
    }

    public function test_a_failing_source_is_skipped_reported_and_does_not_blank_the_calendar(): void
    {
        Task::factory()->create([
            'title' => 'still here',
            'deadline' => '2026-08-15',
            'creator_id' => $this->owner->id,
            'assigned_id' => $this->owner->id,
        ]);

        app(CalendarSourceRegistry::class)->register(new class implements CalendarSource
        {
            public function id(): string
            {
                return 'exploding';
            }

            public function label(): string
            {
                return 'Exploding';
            }

            public function occurrences(CalendarWindow $window): CalendarSourceResult
            {
                throw new RuntimeException('this module is having a bad day');
            }
        });

        $response = $this->actingAsMember()->read()->assertOk();

        // The rest of the grid rendered...
        $this->assertSame(['still here'], array_column($response->json('data'), 'title'));

        // ...and the failure is NAMED, because "nothing scheduled" and "the source is broken" are
        // different facts a user is entitled to tell apart — WITH the reason, which here is the one that
        // invites a retry.
        //
        // THIS IS ALSO THE PIN FOR "NOT A QUERY EXCEPTION". A plain RuntimeException carries no
        // SQLSTATE to classify by, so it must land on the retryable side: an unrecognised failure gets
        // the benefit of the doubt, and only a code that positively identifies itself as structural is
        // allowed to withhold the button.
        $this->assertSame(
            [['source' => 'exploding', 'reason' => 'failed']],
            $response->json('meta.unavailable_sources'),
        );
    }

    /**
     * THE DEFECT THIS SPLIT WAS WRITTEN FOR, reproduced end to end: a source whose table was never
     * migrated. The interface used to answer that with "this is usually temporary — try again", over a
     * button that could not conjure a table, because the registry classified EVERY throw as `failed`.
     *
     * The query is real rather than a stand-in, so the SQLSTATE under test is Postgres's own `42P01`
     * and not one this test invented — the whole classification hangs on the driver actually reporting
     * that code in `errorInfo`, which only a genuine failure can demonstrate.
     *
     * WHY THE NESTED TRANSACTION: Postgres aborts an entire transaction on error, and the suite runs
     * each test inside one (`RefreshDatabase`). Without a SAVEPOINT to roll back to, every query AFTER
     * this one — including the ones that build the response being asserted — would fail with `25P02`
     * and the test would prove nothing about the classifier. `DB::transaction()` opens the savepoint,
     * rolls back to it, and rethrows the original exception untouched.
     */
    public function test_a_missing_table_is_reported_as_broken_rather_than_as_a_bad_minute(): void
    {
        app(CalendarSourceRegistry::class)->register(new class implements CalendarSource
        {
            public function id(): string
            {
                return 'unmigrated';
            }

            public function label(): string
            {
                return 'Unmigrated';
            }

            public function occurrences(CalendarWindow $window): CalendarSourceResult
            {
                return DB::transaction(fn (): CalendarSourceResult => CalendarSourceResult::complete(
                    DB::table('a_table_no_migration_ever_created')->get()->all(),
                ));
            }
        });

        $response = $this->actingAsMember()->read()->assertOk();

        // `broken`, NOT `failed` — the frontend offers its retry button for `failed` alone, so this one
        // value is the difference between an honest report and a promise nothing can keep.
        $this->assertSame(
            [['source' => 'unmigrated', 'reason' => 'broken']],
            $response->json('meta.unavailable_sources'),
        );
    }

    /**
     * The other side of the split, and the reason it is drawn on the SQLSTATE rather than on "was it a
     * database error": a statement timeout IS a database error and IS often a bad minute. Widening the
     * structural label to every QueryException would have taken the retry away from precisely the
     * failures where retrying works.
     */
    public function test_a_query_failure_outside_class_42_is_still_reported_as_retryable(): void
    {
        app(CalendarSourceRegistry::class)->register(new class implements CalendarSource
        {
            public function id(): string
            {
                return 'timing_out';
            }

            public function label(): string
            {
                return 'Timing out';
            }

            public function occurrences(CalendarWindow $window): CalendarSourceResult
            {
                // 57014 — `query_canceled`, what a statement timeout raises. Shaped exactly as PDO
                // hands it over, because that is the field the classifier reads.
                $driver = new PDOException('canceling statement due to statement timeout');
                $driver->errorInfo = ['57014', 7, 'canceling statement due to statement timeout'];

                throw new QueryException('pgsql', 'select 1', [], $driver);
            }
        });

        $response = $this->actingAsMember()->read()->assertOk();

        $this->assertSame(
            [['source' => 'timing_out', 'reason' => 'failed']],
            $response->json('meta.unavailable_sources'),
        );
    }

    /**
     * A QueryException whose previous is not a PDOException carries NO `errorInfo` at all, and the
     * classifier must not read the absence as a structural code. It is the branch a null-safe access
     * makes invisible: with `errorInfo` null there is nothing to compare, and the only safe reading of
     * "no SQLSTATE" is the retryable one.
     */
    public function test_a_query_failure_with_no_sqlstate_at_all_is_still_reported_as_retryable(): void
    {
        app(CalendarSourceRegistry::class)->register(new class implements CalendarSource
        {
            public function id(): string
            {
                return 'stateless';
            }

            public function label(): string
            {
                return 'Stateless';
            }

            public function occurrences(CalendarWindow $window): CalendarSourceResult
            {
                throw new QueryException('pgsql', 'select 1', [], new RuntimeException('connection lost'));
            }
        });

        $response = $this->actingAsMember()->read()->assertOk();

        $this->assertSame(
            [['source' => 'stateless', 'reason' => 'failed']],
            $response->json('meta.unavailable_sources'),
        );
    }

    /**
     * The other half of fail-soft, and the half that is easy to miss: a source that returns the wrong
     * SHAPE does not throw where it is called — it throws later, during the merge, outside every
     * handler. Since the module's promise is that a stranger can add a source without reading Calendar
     * code, that stranger's type error must not be able to 500 the modules that were working.
     */
    public function test_a_source_returning_junk_is_skipped_rather_than_crashing_the_calendar(): void
    {
        Task::factory()->create([
            'title' => 'still here',
            'deadline' => '2026-08-15',
            'creator_id' => $this->owner->id,
            'assigned_id' => $this->owner->id,
        ]);

        app(CalendarSourceRegistry::class)->register(new class implements CalendarSource
        {
            public function id(): string
            {
                return 'malformed';
            }

            public function label(): string
            {
                return 'Malformed';
            }

            public function occurrences(CalendarWindow $window): CalendarSourceResult
            {
                return new CalendarSourceResult(['this is not an occurrence']);
            }
        });

        $response = $this->actingAsMember()->read()->assertOk();

        $this->assertSame(['still here'], array_column($response->json('data'), 'title'));

        // A DIFFERENT reason from the one above, and the distinction is the point: this source is
        // answering — wrongly — so no amount of retrying will change what comes back.
        $this->assertSame(
            [['source' => 'malformed', 'reason' => 'malformed']],
            $response->json('meta.unavailable_sources'),
        );
    }

    /**
     * A different failure mode: a source that never comes up at all. Its factory throws, so there is
     * nothing to ask — as distinct from a source that was asked and failed, which is the one a user
     * might reasonably retry.
     */
    public function test_a_source_that_cannot_be_constructed_is_reported_as_such(): void
    {
        app(CalendarSourceRegistry::class)->registerLazy('unbuildable', function (): CalendarSource {
            throw new RuntimeException('this source cannot be built');
        });

        $response = $this->actingAsMember()->read()->assertOk();

        $this->assertSame(
            [['source' => 'unbuildable', 'reason' => 'not_constructed']],
            $response->json('meta.unavailable_sources'),
        );
    }

    public function test_the_global_cap_trims_the_latest_and_says_so(): void
    {
        config(['calendar.max_occurrences' => 3]);

        $base = ['creator_id' => $this->owner->id, 'assigned_id' => $this->owner->id];

        foreach (['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05'] as $day) {
            Task::factory()->create($base + ['title' => $day, 'deadline' => $day]);
        }

        $response = $this->actingAsMember()->read(['sources' => ['task']])->assertOk();

        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.truncated', true);

        // What survives is the EARLIEST part of the window — a reader working left-to-right through a
        // month should lose the far end of it, not today.
        $this->assertSame(
            ['2026-08-01', '2026-08-02', '2026-08-03'],
            array_column($response->json('data'), 'title')
        );

        // The loss is attributed to the source it came from. The COUNT is null here, and that is the
        // honest answer rather than a missing feature: the source had already stopped loading at its own
        // bound, so 2 of the 5 are missing but only 1 of them ever reached the trim. Reporting "1" would
        // have been a precise-looking undercount — the exact failure mode this shape replaced.
        $this->assertSame(
            [['source' => 'task', 'kind' => 'window_trimmed', 'omitted_occurrences' => null, 'affected_items' => null]],
            $response->json('meta.truncations'),
        );
    }

    /**
     * When nothing was hidden before the merge, the count IS exact. Both halves matter: an always-null
     * figure would be useless, and an always-exact one would be untrue.
     */
    public function test_the_window_trim_reports_an_exact_count_when_it_knows_one(): void
    {
        config(['calendar.max_occurrences' => 4]);

        $base = ['creator_id' => $this->owner->id, 'assigned_id' => $this->owner->id];

        // Two sources, three occurrences each: neither reaches its own fetch bound of 5, so everything
        // that exists reaches the trim and the trim can count precisely what it cut.
        $workflow = Workflow::factory()->create(['creator_id' => $this->owner->id, 'name' => 'export']);

        foreach (['05', '06', '07'] as $day) {
            Task::factory()->create($base + ['title' => 'task ' . $day, 'deadline' => "2026-08-{$day}"]);

            WorkflowRun::factory()->create([
                'workflow_id' => $workflow->id,
                'state' => WorkflowRunState::COMPLETED,
                'started_at' => "2026-08-{$day}T08:00:00Z",
                'finished_at' => "2026-08-{$day}T08:05:00Z",
            ]);
        }

        $response = $this->actingAsMember()->read(['sources' => ['task', 'workflow_run']])->assertOk();

        $truncations = collect($response->json('meta.truncations'))->keyBy('source');

        // 6 existed, 4 survived: the reported omissions account for exactly the other 2.
        $this->assertSame(2, $truncations->sum('omitted_occurrences'));

        foreach ($truncations as $truncation) {
            $this->assertSame('window_trimmed', $truncation['kind']);
            $this->assertNotNull($truncation['omitted_occurrences']);
        }
    }

    /** Two sources losing something under one ceiling produce two separate, separately-counted reports. */
    public function test_the_window_trim_is_attributed_to_each_source_that_lost_something(): void
    {
        config(['calendar.max_occurrences' => 4]);

        $base = ['creator_id' => $this->owner->id, 'assigned_id' => $this->owner->id];
        $workflow = Workflow::factory()->create(['creator_id' => $this->owner->id, 'name' => 'export']);

        foreach (['05', '06', '07'] as $day) {
            Task::factory()->create($base + ['title' => 'task ' . $day, 'deadline' => "2026-08-{$day}"]);

            WorkflowRun::factory()->create([
                'workflow_id' => $workflow->id,
                'state' => WorkflowRunState::COMPLETED,
                'started_at' => "2026-08-{$day}T08:00:00Z",
                'finished_at' => "2026-08-{$day}T08:05:00Z",
            ]);
        }

        $response = $this->actingAsMember()
            ->read(['sources' => ['task', 'workflow_run']])
            ->assertOk();

        $truncations = collect($response->json('meta.truncations'))->keyBy('source');

        $this->assertSame(['task', 'workflow_run'], $truncations->keys()->sort()->values()->all());

        foreach ($truncations as $truncation) {
            $this->assertSame('window_trimmed', $truncation['kind']);
            $this->assertGreaterThan(0, $truncation['omitted_occurrences']);
        }
    }

    public function test_another_workspaces_subjects_never_appear(): void
    {
        $other = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $other->users()->attach($this->owner->id);

        $context = app(TenantContext::class);
        $context->set($other);

        Task::factory()->create([
            'title' => 'someone elses deadline',
            'deadline' => '2026-08-15',
            'creator_id' => $this->owner->id,
            'assigned_id' => $this->owner->id,
        ]);

        $context->set($this->workspace);

        $this->actingAsMember()->read(['sources' => ['task']])
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
