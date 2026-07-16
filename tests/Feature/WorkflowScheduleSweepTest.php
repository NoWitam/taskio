<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * Batch 3b: the schedule forward-sweep. `workflows:run-scheduled` fires schedule-type
 * workflows whose next_due_at has arrived, via a race-safe compare-and-swap claim that
 * advances the slot before starting the run. QUEUE_CONNECTION=sync runs the dispatched
 * WorkflowRunJob in-process, so a single sweep drives the whole run (claim -> steps ->
 * terminal). Arming (create/update/activate) is exercised through the API + service.
 */
class WorkflowScheduleSweepTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A create_task step no longer re-triggers any workflow (no task triggers survive);
        // keep caps generous so only the tests that assert on caps hit a gate.
        config([
            'workflows.max_depth' => 3,
            'workflows.max_runs_per_month' => 1000,
            'workflows.max_runs_hard_cap' => 1000,
        ]);
    }

    /** A schedule workflow with one create_task step; armed to a concrete next_due_at. */
    private function scheduledWorkflow(
        User $owner,
        ?\Carbon\CarbonInterface $nextDueAt = null,
        WorkflowStatus $status = WorkflowStatus::ACTIVE,
        array $schedule = ['time' => ['mode' => 'at', 'at' => ['09:00']]],
    ): Workflow {
        return Workflow::factory()->create([
            'creator_id' => $owner->id,
            'status' => $status,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_config' => ['schedule' => $schedule],
            'next_due_at' => $nextDueAt,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'noop', 'config' => ['title' => 'Scheduled task']],
            ],
        ]);
    }

    private function runsFor(Workflow $workflow): \Illuminate\Support\Collection
    {
        return WorkflowRun::query()->where('workflow_id', $workflow->id)->get();
    }

    private function sweep(): void
    {
        $this->artisan('workflows:run-scheduled')->assertSuccessful();
    }

    // ---- Firing --------------------------------------------------------------

    public function test_due_active_schedule_workflow_fires_and_advances_next_due_at(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow($owner, nextDueAt: now()->subMinute());

        $this->sweep();

        $runs = $this->runsFor($workflow);
        $this->assertCount(1, $runs);
        $run = $runs->first();
        $this->assertSame(WorkflowRunOrigin::SCHEDULE, $run->origin);
        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertSame(0, $run->depth);
        $this->assertNull($run->origin_run_id);
        // `origin` (SCHEDULE) is the authoritative engine-vs-manual signal — never creator_id.
        // An engine run is attributed to the workflow AUTHOR (WorkflowRunManager::start), so the
        // run row is a 'user'-typed record carrying $owner regardless of who (if anyone) is
        // authenticated. The task its step creates is attributed to the RUN (see the smoke test).
        $this->assertSame($owner->id, $run->creator_id);
        $this->assertSame('user', $run->creator_type);
        $this->assertArrayHasKey('scheduled_at', $run->trigger_payload);

        // The step actually executed.
        $this->assertDatabaseHas('tasks', ['title' => 'Scheduled task']);

        // The slot advanced strictly beyond now, and last_scheduled_run_at was stamped.
        $workflow->refresh();
        $this->assertTrue($workflow->next_due_at->greaterThan(now()));
        $this->assertNotNull($workflow->last_scheduled_run_at);
    }

    public function test_engine_run_with_no_auth_attributes_created_records_to_the_run(): void
    {
        // Regression for the reported "creator_id null" crash. No actingAs: this mirrors a real
        // cron sweep (no authenticated user), so the create_task step runs on the queue path with
        // NO auth. HasCreator must attribute the task to the active WorkflowRun — creator_type=
        // 'workflow_run', creator_id=run->id — instead of stamping a NULL into tasks.creator_id
        // (NOT NULL), which previously failed the step and stranded the run.
        $owner = User::factory()->create();
        $workflow = $this->scheduledWorkflow($owner, nextDueAt: now()->subMinute());

        $this->sweep();

        $run = $this->runsFor($workflow)->first();
        $this->assertNotNull($run);
        $this->assertSame(WorkflowRunOrigin::SCHEDULE, $run->origin);
        // The run COMPLETED: the task write no longer crashes on the NOT NULL creator_id.
        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        // The run itself inherits the workflow author (engine runs are never NULL-creator).
        $this->assertSame($owner->id, $run->creator_id);
        $this->assertSame('user', $run->creator_type);

        // The task the step created is a SYSTEM record owned by the RUN, with a non-null id.
        $this->assertDatabaseHas('tasks', [
            'title' => 'Scheduled task',
            'creator_type' => 'workflow_run',
            'creator_id' => $run->id,
        ]);
    }

    public function test_not_due_workflow_is_untouched(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $future = now()->addHour();
        $workflow = $this->scheduledWorkflow($owner, nextDueAt: $future);

        $this->sweep();

        $this->assertCount(0, $this->runsFor($workflow));
        $workflow->refresh();
        // Slot unchanged (not consumed).
        $this->assertSame($future->format('Y-m-d H:i'), $workflow->next_due_at->format('Y-m-d H:i'));
        $this->assertNull($workflow->last_scheduled_run_at);
    }

    public function test_inactive_schedule_workflow_never_fires(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // Even with a due next_due_at, an inactive workflow is out of the sweep's WHERE.
        $workflow = $this->scheduledWorkflow($owner, nextDueAt: now()->subMinute(), status: WorkflowStatus::INACTIVE);

        $this->sweep();

        $this->assertCount(0, $this->runsFor($workflow));
    }

    public function test_non_schedule_workflow_is_never_touched_by_the_sweep(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // A form_submitted workflow with a (nonsensical) due next_due_at must be ignored.
        $workflow = Workflow::factory()->active()->create([
            'creator_id' => $owner->id,
            'trigger_type' => WorkflowTriggerType::FORM_SUBMITTED->value,
            'next_due_at' => now()->subMinute(),
        ]);

        $this->sweep();

        $this->assertCount(0, $this->runsFor($workflow));
    }

    // ---- Race safety (CAS) ---------------------------------------------------

    public function test_second_immediate_sweep_fires_nothing(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow($owner, nextDueAt: now()->subMinute());

        $this->sweep();
        $this->assertCount(1, $this->runsFor($workflow));

        // The first sweep advanced next_due_at into the future, so a second immediate sweep
        // sees nothing due — no new run.
        $this->sweep();
        $this->assertCount(1, $this->runsFor($workflow));
    }

    public function test_cas_claim_wins_once_then_loses(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow($owner, nextDueAt: now()->subMinute());
        $schedule = app(WorkflowScheduleService::class);

        // First claim wins (returns the newly-armed time); the second claim on the SAME read
        // model loses (0 rows — the stored next_due_at no longer matches the read value).
        $this->assertNotNull($schedule->claimDue($workflow), 'first CAS claim must win');
        $this->assertNull($schedule->claimDue($workflow), 'second CAS claim on the stale read must lose');
    }

    public function test_pre_bumped_slot_simulating_a_rival_win_fires_nothing(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow($owner, nextDueAt: now()->subMinute());

        // Simulate a rival sweep that already claimed the slot: bump next_due_at into the
        // future directly. The sweep must then find nothing due.
        Workflow::withoutGlobalScopes()->whereKey($workflow->id)->update(['next_due_at' => now()->addDay()]);

        $this->sweep();

        $this->assertCount(0, $this->runsFor($workflow));
    }

    // ---- Caps ----------------------------------------------------------------

    public function test_cap_reached_starts_no_run_but_still_consumes_the_slot(): void
    {
        // Per-workflow monthly cap of 0: any run is over budget.
        config(['workflows.max_runs_per_month' => 0]);

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $due = now()->subMinute();
        $workflow = $this->scheduledWorkflow($owner, nextDueAt: $due);

        $this->sweep();

        // No run (cap), but the slot was consumed: next_due_at advanced past the due value and
        // last_scheduled_run_at was stamped — a capped workflow does not backlog-fire.
        $this->assertCount(0, $this->runsFor($workflow));
        $workflow->refresh();
        $this->assertTrue($workflow->next_due_at->greaterThan($due));
        $this->assertNotNull($workflow->last_scheduled_run_at);
    }

    // ---- Self-healing --------------------------------------------------------

    public function test_null_due_active_schedule_workflow_is_armed_not_fired(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // Legacy/edge: active schedule workflow with a NULL next_due_at (e.g. activated before
        // this batch landed). The sweep ARMS it (no fire this pass).
        $workflow = $this->scheduledWorkflow($owner, nextDueAt: null);

        $this->sweep();

        $this->assertCount(0, $this->runsFor($workflow), 'arming must not fire on the same pass');
        $workflow->refresh();
        $this->assertNotNull($workflow->next_due_at, 'a null-due active schedule workflow gets armed');
        $this->assertTrue($workflow->next_due_at->greaterThan(now()));
    }

    // ---- Arming via write path ----------------------------------------------

    public function test_activating_a_schedule_workflow_arms_next_due_at(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow($owner, nextDueAt: null, status: WorkflowStatus::INACTIVE);
        $this->assertNull($workflow->next_due_at);

        $this->patchJson("/api/workflows/{$workflow->id}/status", ['status' => 'active'])->assertOk();

        $workflow->refresh();
        $this->assertNotNull($workflow->next_due_at, 'activation arms the schedule');
        $this->assertTrue($workflow->next_due_at->greaterThan(now()));
    }

    public function test_deactivating_a_schedule_workflow_nulls_next_due_at(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow($owner, nextDueAt: now()->addDay(), status: WorkflowStatus::ACTIVE);

        $this->patchJson("/api/workflows/{$workflow->id}/status", ['status' => 'inactive'])->assertOk();

        $workflow->refresh();
        $this->assertNull($workflow->next_due_at, 'deactivation clears the schedule');
    }

    public function test_restoring_an_active_schedule_workflow_re_arms_past_now(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // An ACTIVE schedule workflow soft-deleted with a stale PAST next_due_at (frozen while deleted
        // and invisible to the sweep). Without re-arming, restore would leave the past slot in place and
        // the very next sweep would fire it immediately (then backlog-fire further past slots).
        $stale = now()->subDays(3);
        $workflow = $this->scheduledWorkflow($owner, nextDueAt: $stale, status: WorkflowStatus::ACTIVE);
        $workflow->delete();

        $this->postJson("/api/workflows/{$workflow->id}/restore")->assertOk();

        $workflow->refresh();
        $this->assertNull($workflow->deleted_at, 'the workflow is restored');
        // Re-armed from now: the next fire is strictly in the future, never the stale past slot.
        $this->assertNotNull($workflow->next_due_at);
        $this->assertTrue($workflow->next_due_at->greaterThan(now()), 'restore re-arms past now');

        // A sweep right after restore therefore fires NOTHING — the stale immediate-fire is gone.
        $this->sweep();
        $this->assertCount(0, $this->runsFor($workflow), 'a freshly restored schedule workflow does not fire immediately');
    }

    public function test_updating_cadence_while_active_re_arms_next_due_at(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: now()->addDay(),
            status: WorkflowStatus::ACTIVE,
            schedule: ['time' => ['mode' => 'at', 'at' => ['09:00']]],
        );

        // Change the cadence to hourly via the update endpoint; the active workflow re-arms.
        $this->putJson("/api/workflows/{$workflow->id}", [
            'name' => $workflow->name,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'every_hours', 'hours' => 1]]],
            'steps' => $workflow->steps,
        ])->assertOk();

        $workflow->refresh();
        $this->assertNotNull($workflow->next_due_at);
        // Hourly => next due within the next hour (well under the previous next-day value).
        $this->assertTrue($workflow->next_due_at->lessThanOrEqualTo(now()->addHour()->addSecond()));
    }

    public function test_updating_to_add_an_exclusion_re_arms_past_the_excluded_slot(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // 2026-07-10 is a Friday. A daily 09:00 active schedule armed to fire this coming Monday.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: \Carbon\Carbon::parse('2026-07-13 09:00:00', 'UTC'), // Monday
            status: WorkflowStatus::ACTIVE,
            schedule: ['time' => ['mode' => 'at', 'at' => ['09:00']], 'tz' => 'UTC'],
        );

        // Edit adds an exclusion that eliminates the nearest slots (Sat/Sun AND Monday) — the whole
        // block (time+tz+exclusions) is compared/re-armed, so next_due_at must move to the next
        // non-excluded day (Tuesday 2026-07-14).
        $this->putJson("/api/workflows/{$workflow->id}", [
            'name' => $workflow->name,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_config' => ['schedule' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'tz' => 'UTC',
                'exclusions' => ['weekdays' => [0, 6, 1]], // Sun, Sat, Mon
            ]],
            'steps' => $workflow->steps,
        ])->assertOk();

        $workflow->refresh();
        $this->assertNotNull($workflow->next_due_at);
        $this->assertSame('2026-07-14 09:00:00', $workflow->next_due_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, $workflow->next_due_at->dayOfWeek); // Tuesday.

        \Carbon\Carbon::setTestNow();
    }

    public function test_schedule_with_an_excluded_nearest_slot_fires_at_the_next_slot(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // A schedule whose stored next_due_at is due now but whose cadence excludes Fri+Sat+Sun:
        // when the sweep claims and advances, the recomputed slot skips every excluded day and lands
        // on the next non-excluded day (Monday). Firing this due slot still happens — the exclusion
        // only affects the ADVANCED next_due_at, never whether the already-due slot runs.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-10 09:00:00', 'UTC')); // Friday 09:00

        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: now()->subMinute(),
            status: WorkflowStatus::ACTIVE,
            schedule: ['time' => ['mode' => 'at', 'at' => ['09:00']], 'tz' => 'UTC', 'exclusions' => ['weekdays' => [0, 5, 6]]],
        );

        $this->sweep();

        // The due slot fired once, and the advanced next_due_at skips Fri/Sat/Sun to Monday 09:00.
        $this->assertCount(1, $this->runsFor($workflow));
        $workflow->refresh();
        $this->assertSame('2026-07-13 09:00:00', $workflow->next_due_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $workflow->next_due_at->dayOfWeek); // Monday.

        \Carbon\Carbon::setTestNow();
    }

    public function test_arm_orphans_does_not_loop_on_an_unfireable_schedule(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // A NULL-due active schedule whose exclusions rule out every occurrence (weekly-Monday that
        // also excludes Mondays). armOrphans must leave next_due_at NULL (not busy-loop, not fire).
        $workflow = $this->scheduledWorkflow(
            $owner,
            nextDueAt: null,
            status: WorkflowStatus::ACTIVE,
            schedule: [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
                'tz' => 'UTC',
                'exclusions' => ['weekdays' => [1]],
            ],
        );

        $this->sweep();

        $workflow->refresh();
        $this->assertNull($workflow->next_due_at, 'an unfireable schedule stays null-due, never armed');
        $this->assertCount(0, $this->runsFor($workflow), 'an unfireable schedule never fires');
    }

    public function test_created_schedule_workflow_is_inactive_with_null_due(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $response = $this->postJson('/api/workflows', [
            'name' => 'Nightly digest',
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'at', 'at' => ['09:00']]]],
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'noop', 'config' => ['title' => 'x']],
            ],
        ])->assertCreated();

        $workflow = Workflow::findOrFail($response->json('data.id'));
        $this->assertSame(WorkflowStatus::INACTIVE, $workflow->status);
        $this->assertNull($workflow->next_due_at, 'a workflow is created inactive, so it is not armed');
    }
}
