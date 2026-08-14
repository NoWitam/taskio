<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * EXACT-INSTANT pin for `workflows:run-scheduled`.
 *
 * WorkflowScheduleSweepTest already covers the sweep's SHAPE — that a due workflow fires, that a capped
 * one consumes its slot without running, that a rival CAS loses. What it deliberately does not do is
 * name the instants: it asserts `next_due_at->greaterThan(now())`, which is the right assertion for
 * "the slot advanced" and the wrong one for "the slot advanced to the RIGHT place". A change that moved
 * every schedule by an hour, or that picked the second candidate instead of the first, passes it.
 *
 * That gap is exactly the blast radius of caching the descriptor compilation inside the engine, so this
 * file closes it: a frozen clock, and the resulting `next_due_at`, `last_scheduled_run_at` and
 * `{{trigger.scheduled_at}}` asserted to the second against hand-computed values. Every cadence family
 * whose arithmetic the sweep depends on is here — a plain daily, a minute grid, an exclusion that has to
 * skip a weekend, and a wall-clock time across a DST transition.
 *
 * This command runs every minute in production and decides whether customers' automations fire at all.
 * If a change to the engine cannot be felt here, it cannot be claimed to be behaviour-preserving.
 */
class WorkflowScheduleSweepDeterminismTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workflows.max_depth' => 3,
            'workflows.max_runs_per_month' => 1000,
            'workflows.max_runs_hard_cap' => 1000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $schedule */
    private function scheduledWorkflow(
        User $owner,
        ?string $nextDueAt,
        array $schedule,
        WorkflowStatus $status = WorkflowStatus::ACTIVE,
    ): Workflow {
        return Workflow::factory()->create([
            'creator_id' => $owner->id,
            'status' => $status,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_config' => ['schedule' => $schedule],
            'next_due_at' => $nextDueAt === null ? null : Carbon::parse($nextDueAt),
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'noop', 'config' => ['title' => 'Scheduled task']],
            ],
        ]);
    }

    private function sweep(): void
    {
        $this->artisan('workflows:run-scheduled')->assertSuccessful();
    }

    private function dueAt(Workflow $workflow): ?string
    {
        return $workflow->refresh()->next_due_at?->utc()->format('Y-m-d H:i:s');
    }

    // ---- the arithmetic, named to the second --------------------------------

    public function test_a_daily_cadence_advances_to_the_exact_next_slot(): void
    {
        Carbon::setTestNow('2026-08-15T09:00:30Z');

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: '2026-08-15T09:00:00Z',
            schedule: ['time' => ['mode' => 'at', 'at' => ['09:00']]],
        );

        $this->sweep();

        $this->assertSame('2026-08-16 09:00:00', $this->dueAt($workflow));
        $this->assertSame('2026-08-15 09:00:30', $workflow->last_scheduled_run_at->utc()->format('Y-m-d H:i:s'));

        // The payload is anchored to the SLOT that fired, not to the moment the sweep noticed it — a
        // run that started 30 seconds late still reports the 09:00 slot.
        $run = WorkflowRun::query()->where('workflow_id', $workflow->id)->sole();
        $this->assertSame(
            Carbon::parse('2026-08-15T09:00:00Z')->toIso8601String(),
            $run->trigger_payload['scheduled_at'],
        );
    }

    public function test_a_minute_grid_advances_by_exactly_one_minute(): void
    {
        Carbon::setTestNow('2026-08-15T12:00:30Z');

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: '2026-08-15T12:00:00Z',
            schedule: ['time' => ['mode' => 'every_minutes', 'minutes' => 1]],
        );

        $this->sweep();

        $this->assertSame('2026-08-15 12:01:00', $this->dueAt($workflow));
    }

    public function test_several_times_a_day_advance_to_the_next_listed_time(): void
    {
        Carbon::setTestNow('2026-08-15T13:45:10Z');

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: '2026-08-15T13:45:00Z',
            schedule: ['time' => ['mode' => 'at', 'at' => ['07:15', '13:45', '22:05']]],
        );

        $this->sweep();

        $this->assertSame('2026-08-15 22:05:00', $this->dueAt($workflow));
    }

    /**
     * The exclusion post-filter runs per candidate, so it is the part of the engine most sensitive to
     * anything that changes WHICH candidate is examined first.
     */
    public function test_an_exclusion_skips_the_whole_weekend_to_the_exact_monday_slot(): void
    {
        // 2026-08-14 is a Friday.
        Carbon::setTestNow('2026-08-14T09:00:05Z');

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: '2026-08-14T09:00:00Z',
            schedule: [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'exclusions' => ['weekdays' => [0, 6]],
            ],
        );

        $this->sweep();

        $this->assertSame('2026-08-17 09:00:00', $this->dueAt($workflow), 'Saturday and Sunday are skipped, landing on Monday');
    }

    /**
     * A wall-clock cadence in a zone that observes DST. The stored instant must move by an hour across
     * the transition while the LOCAL time stays put — the behaviour the engine documents and the one a
     * caching mistake would flatten.
     */
    public function test_a_wall_clock_cadence_holds_its_local_time_across_spring_forward(): void
    {
        // 2026-03-28 09:00 Warsaw = 08:00 UTC (CET). The next slot is after the spring-forward, so
        // 2026-03-29 09:00 Warsaw = 07:00 UTC (CEST).
        Carbon::setTestNow('2026-03-28T08:00:05Z');

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: '2026-03-28T08:00:00Z',
            schedule: ['time' => ['mode' => 'at', 'at' => ['09:00']], 'tz' => 'Europe/Warsaw'],
        );

        $this->sweep();

        $this->assertSame('2026-03-29 07:00:00', $this->dueAt($workflow));
    }

    // ---- the slot-consumed doctrine, to the second --------------------------

    public function test_a_capped_workflow_consumes_exactly_one_slot_and_starts_nothing(): void
    {
        Carbon::setTestNow('2026-08-15T09:00:30Z');
        config(['workflows.max_runs_per_month' => 0]);

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: '2026-08-15T09:00:00Z',
            schedule: ['time' => ['mode' => 'at', 'at' => ['09:00']]],
        );

        $this->sweep();

        $this->assertSame(0, WorkflowRun::query()->where('workflow_id', $workflow->id)->count());

        // ONE slot, not a backlog: the next due time is tomorrow's 09:00, exactly as if it had run.
        $this->assertSame('2026-08-16 09:00:00', $this->dueAt($workflow));
        $this->assertSame('2026-08-15 09:00:30', $workflow->last_scheduled_run_at->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * The capped workflow must not accumulate missed slots either. Three sweeps across three days
     * consume three slots and start nothing — the doctrine's actual promise.
     */
    public function test_a_capped_workflow_never_backlogs_across_several_sweeps(): void
    {
        config(['workflows.max_runs_per_month' => 0]);

        $owner = User::factory()->create();
        $this->actingAs($owner);

        Carbon::setTestNow('2026-08-15T09:00:30Z');

        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: '2026-08-15T09:00:00Z',
            schedule: ['time' => ['mode' => 'at', 'at' => ['09:00']]],
        );

        $this->sweep();
        $this->assertSame('2026-08-16 09:00:00', $this->dueAt($workflow));

        Carbon::setTestNow('2026-08-16T09:00:30Z');
        $this->sweep();
        $this->assertSame('2026-08-17 09:00:00', $this->dueAt($workflow));

        Carbon::setTestNow('2026-08-17T09:00:30Z');
        $this->sweep();
        $this->assertSame('2026-08-18 09:00:00', $this->dueAt($workflow));

        $this->assertSame(0, WorkflowRun::query()->where('workflow_id', $workflow->id)->count());
    }

    // ---- arming, to the second ----------------------------------------------

    public function test_an_orphan_is_armed_to_the_exact_first_slot_and_does_not_fire(): void
    {
        Carbon::setTestNow('2026-08-15T10:00:00Z');

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: null,
            schedule: ['time' => ['mode' => 'at', 'at' => ['09:00']]],
        );

        $this->sweep();

        $this->assertSame(0, WorkflowRun::query()->where('workflow_id', $workflow->id)->count());
        // 10:00 is past today's 09:00, so the first reachable slot is tomorrow's.
        $this->assertSame('2026-08-16 09:00:00', $this->dueAt($workflow));
    }

    /**
     * An over-constrained cadence has no reachable occurrence. Winning the CAS must CLEAR the slot
     * rather than leave it in place, or the sweep would re-examine the same workflow every minute
     * forever.
     */
    public function test_an_unreachable_cadence_clears_its_slot_on_a_winning_claim(): void
    {
        Carbon::setTestNow('2026-08-17T09:00:30Z');

        $owner = User::factory()->create();
        $this->actingAs($owner);

        // Weekly on Mondays, excluding Mondays.
        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: '2026-08-17T09:00:00Z',
            schedule: [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
                'exclusions' => ['weekdays' => [1]],
            ],
        );

        $this->sweep();

        $this->assertNull($this->dueAt($workflow), 'a cadence with no reachable occurrence stops firing');
    }

    // ---- ordering across several due workflows ------------------------------

    public function test_every_due_workflow_lands_on_its_own_exact_slot(): void
    {
        Carbon::setTestNow('2026-08-15T12:00:30Z');

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $daily = $this->scheduledWorkflow($owner, '2026-08-15T09:00:00Z', ['time' => ['mode' => 'at', 'at' => ['09:00']]]);
        $minutes = $this->scheduledWorkflow($owner, '2026-08-15T12:00:00Z', ['time' => ['mode' => 'every_minutes', 'minutes' => 15]]);
        $hours = $this->scheduledWorkflow($owner, '2026-08-15T10:20:00Z', ['time' => ['mode' => 'every_hours', 'hours' => 3, 'minute' => 20]]);

        $this->sweep();

        $this->assertSame('2026-08-16 09:00:00', $this->dueAt($daily));
        $this->assertSame('2026-08-15 12:15:00', $this->dueAt($minutes));
        // NOT 13:20. `every_hours` compiles to `20 */3 …`, which is a MODULO grid anchored to
        // midnight (00:20, 03:20, … 12:20), not "three hours after the last fire" — so a slot at
        // 10:20 is followed by 12:20. Worth pinning precisely: this is the arithmetic a reader is
        // most likely to assume works the other way.
        $this->assertSame('2026-08-15 12:20:00', $this->dueAt($hours));

        foreach ([$daily, $minutes, $hours] as $workflow) {
            $this->assertSame(1, WorkflowRun::query()->where('workflow_id', $workflow->id)->count());
        }
    }

    public function test_a_workflow_not_yet_due_keeps_its_slot_untouched(): void
    {
        Carbon::setTestNow('2026-08-15T08:59:59Z');

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: '2026-08-15T09:00:00Z',
            schedule: ['time' => ['mode' => 'at', 'at' => ['09:00']]],
        );

        $this->sweep();

        $this->assertSame('2026-08-15 09:00:00', $this->dueAt($workflow));
        $this->assertNull($workflow->last_scheduled_run_at);
        $this->assertSame(0, WorkflowRun::query()->where('workflow_id', $workflow->id)->count());
    }
}
