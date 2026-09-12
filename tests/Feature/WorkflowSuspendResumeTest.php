<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workflows\Enums\WaitStatus;
use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowRunStepStatus;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Jobs\WorkflowRunJob;
use App\Modules\Workflows\Jobs\WorkflowRunResumeJob;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WaitResolverRegistry;
use App\Modules\Workflows\Services\WorkflowRunManager;
use App\Modules\Workflows\Services\WorkflowStepFactory;
use App\Modules\Workflows\Services\WorkflowStepRunner;
use App\Modules\Workflows\Support\RealQueueConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\Support\FakeStepFactory;
use Tests\Support\FakeSuspendableStep;
use Tests\Support\FakeWaitResolver;
use Tests\TestCase;

/**
 * The GENERIC suspend/resume engine (BE-0), proven WITHOUT any feature riding on it: a TEST-ONLY
 * suspendable step ({@see FakeSuspendableStep}, injected through {@see FakeStepFactory}) and a
 * TEST-ONLY wait resolver ({@see FakeWaitResolver}) stand in for whatever a later batch registers.
 *
 * The byte-identical behaviour of workflows WITHOUT a suspending step is pinned separately in
 * {@see WorkflowRunEngineRegressionTest}.
 */
class WorkflowSuspendResumeTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    private FakeSuspendableStep $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeSuspendableStep;
        $this->app->instance(WorkflowStepFactory::class, new FakeStepFactory($this->fake));
    }

    /**
     * A workflow whose MIDDLE step suspends: an ordinary step before it (so we can prove the
     * accumulated context survives) and an ordinary step after it (so we can prove the loop stopped
     * and later resumed).
     */
    private function workflowWithASuspendingStep(User $owner): Workflow
    {
        return Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'before', 'config' => ['title' => 'Before the wait']],
                ['type' => FakeSuspendableStep::TYPE, 'key' => 'wait', 'config' => ['echo' => 'hello']],
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'after', 'config' => [
                    'title' => 'After the wait: {{steps.wait.settled}}',
                ]],
            ],
        ]);
    }

    /** Start a run and let it suspend (QUEUE_CONNECTION=sync drives the whole first pass). */
    private function startAndSuspend(Workflow $workflow): WorkflowRun
    {
        $run = app(WorkflowRunManager::class)->start($workflow, WorkflowRunOrigin::EVENT, ['source' => 'test']);

        return $run->fresh();
    }

    private function resume(WorkflowRun $run): void
    {
        (new WorkflowRunResumeJob($run->id, ''))->handle(
            app(WorkflowRunManager::class),
            app(WorkflowStepRunner::class),
        );
    }

    // ---------------------------------------------------------------- suspension

    public function test_a_suspending_step_parks_the_run_and_stops_the_loop(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $run = $this->startAndSuspend($this->workflowWithASuspendingStep($owner));

        // The run is parked, NOT terminal, and not marked finished.
        $this->assertSame(WorkflowRunState::WAITING, $run->state);
        $this->assertNull($run->finished_at);
        $this->assertNull($run->error);

        // started_at is LEFT as claimed (G3: the stale-RUNNING reaper matches `running` only, so a
        // waiting run must not carry a re-stamped claim time).
        $this->assertNotNull($run->started_at);

        // The wait descriptors: kind + key + type + position + the step's own payload, plus the
        // three keys the HARDENED resume contract adds — the already-RESOLVED config (replayed on
        // resume instead of being re-resolved), the whole-definition fingerprint, and the run's
        // ai-text spend so far (so the per-RUN budget survives the resume). See
        // WorkflowSuspendResumeHardeningTest for what each of them prevents.
        $waitingOn = $run->waiting_on;

        $this->assertSame(sha1(json_encode($run->workflow->steps)), $waitingOn['definition_hash']);
        unset($waitingOn['definition_hash']);

        $this->assertSame([
            'kind' => FakeSuspendableStep::KIND,
            'step_key' => 'wait',
            'step_type' => FakeSuspendableStep::TYPE,
            'position' => 1,
            'payload' => ['echo' => 'hello'],
            'config' => ['echo' => 'hello'],
            'ai_text_calls' => 0,
        ], $waitingOn);
        $this->assertSame('fake_wait:abc-123', $run->waiting_key);
        $this->assertNotNull($run->waiting_since);

        // The EARLIER step's output was persisted with the suspension; the suspended step published
        // nothing.
        $this->assertSame(['before'], array_keys($run->context['steps']));
        $this->assertSame('Before the wait', $run->context['steps']['before']['title']);

        // D14: no step row for the suspension — only the completed earlier step.
        $steps = $run->steps()->get();
        $this->assertCount(1, $steps);
        $this->assertSame('before', $steps[0]->key);

        // The LATER step never ran.
        $this->assertDatabaseMissing('tasks', ['title' => 'After the wait: yes']);
        $this->assertSame(1, $this->fake->runCalls);
        $this->assertSame(0, $this->fake->resumeCalls);
    }

    // ---------------------------------------------------------------- resumption

    public function test_resume_runs_the_suspended_step_clears_the_wait_and_continues(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $run = $this->startAndSuspend($this->workflowWithASuspendingStep($owner));

        $this->resume($run);
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertNotNull($run->finished_at);

        // The suspended step re-entered through resume() — exactly once — with its config RESOLVED
        // again and the context REBUILT FROM THE DATABASE (the earlier step's output is there).
        $this->assertSame(1, $this->fake->resumeCalls);
        $this->assertSame(1, $this->fake->runCalls);
        $this->assertSame(['echo' => 'hello'], $this->fake->lastResumeConfig);
        $this->assertSame('Before the wait', $this->fake->lastResumeContext['steps']['before']['title']);
        $this->assertSame(FakeSuspendableStep::KIND, $this->fake->lastResumeWait['kind']);

        // The wait was cleared in the same write that published the output.
        $this->assertNull($run->waiting_on);
        $this->assertNull($run->waiting_key);
        $this->assertNull($run->waiting_since);

        // Step rows: all three, in order, the resumed one recorded like any other succeeded step.
        $steps = $run->steps()->get();
        $this->assertCount(3, $steps);
        $this->assertSame(['before', 'wait', 'after'], $steps->pluck('key')->all());
        $this->assertSame([0, 1, 2], $steps->pluck('position')->all());
        $this->assertSame(WorkflowRunStepStatus::SUCCEEDED, $steps[1]->status);
        $this->assertSame(['settled' => 'yes', 'echo' => 'hello'], $steps[1]->payload);

        // The step AFTER the wait resolved {{steps.wait.settled}} from the resumed output.
        $this->assertDatabaseHas('tasks', ['title' => 'After the wait: yes']);
        $this->assertSame(['before', 'wait', 'after'], array_keys($run->context['steps']));
    }

    public function test_a_second_resume_is_a_clean_no_op(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $run = $this->startAndSuspend($this->workflowWithASuspendingStep($owner));

        // A settle listener and the waiting sweep both dispatching, or an at-least-once redelivery.
        $this->resume($run);
        $this->resume($run->fresh());

        $this->assertSame(1, $this->fake->resumeCalls);
        $this->assertSame(WorkflowRunState::COMPLETED, $run->fresh()->state);
        $this->assertSame(3, $run->steps()->count());
        $this->assertSame(1, \App\Modules\Tasks\Models\Task::where('title', 'After the wait: yes')->count());
    }

    public function test_claim_resume_is_atomic(): void
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);
        $run = WorkflowRun::factory()->waiting()->create(['workflow_id' => $workflow->id]);

        $manager = app(WorkflowRunManager::class);

        $this->assertTrue($manager->claimResume($run));
        $this->assertFalse($manager->claimResume($run->fresh()));
        $this->assertSame(WorkflowRunState::RUNNING, $run->fresh()->state);
        // started_at is re-stamped so the existing stale-RUNNING reaper owns the resumed pass.
        $this->assertNotNull($run->fresh()->started_at);
    }

    public function test_a_failing_resume_records_a_failed_step_row_and_fails_the_run(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $run = $this->startAndSuspend($this->workflowWithASuspendingStep($owner));

        $this->fake->failOnResume = true;
        $this->resume($run);
        $run->refresh();

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertStringContainsString('Settled work was unusable.', $run->error);

        $steps = $run->steps()->get();
        $this->assertCount(2, $steps);
        $this->assertSame(WorkflowRunStepStatus::FAILED, $steps[1]->status);
        $this->assertSame('wait', $steps[1]->key);
        $this->assertDatabaseMissing('tasks', ['title' => 'After the wait: yes']);
    }

    // ---------------------------------------------------------------- definition drift

    public function test_a_changed_step_key_fails_the_run_instead_of_skipping_it(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->workflowWithASuspendingStep($owner);
        $run = $this->startAndSuspend($workflow);

        // The author renamed the waiting step while the run was parked.
        $steps = $workflow->steps;
        $steps[1]['key'] = 'renamed';
        $workflow->update(['steps' => $steps]);

        $this->resume($run);
        $run->refresh();

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.runs.definition_changed'), $run->error);
        $this->assertSame(0, $this->fake->resumeCalls);
        // Never a silent skip-to-completed, and no later step ran.
        $this->assertDatabaseMissing('tasks', ['title' => 'After the wait: yes']);
    }

    public function test_reordered_steps_fail_the_run(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->workflowWithASuspendingStep($owner);
        $run = $this->startAndSuspend($workflow);

        // The suspending step was removed, so position 1 now holds a completely different step.
        $workflow->update(['steps' => [
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'before', 'config' => ['title' => 'Before the wait']],
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'after', 'config' => ['title' => 'Moved up']],
        ]]);

        $this->resume($run);
        $run->refresh();

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.runs.definition_changed'), $run->error);
        $this->assertSame(0, $this->fake->resumeCalls);
        $this->assertDatabaseMissing('tasks', ['title' => 'Moved up']);
    }

    public function test_a_deleted_workflow_fails_the_run(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->workflowWithASuspendingStep($owner);
        $run = $this->startAndSuspend($workflow);

        $workflow->delete();

        $this->resume($run->fresh());
        $run->refresh();

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.runs.definition_changed'), $run->error);
        $this->assertSame(0, $this->fake->resumeCalls);
    }

    public function test_a_resume_without_a_wait_record_fails_instead_of_restarting_the_run(): void
    {
        $owner = User::factory()->create();
        $workflow = $this->workflowWithASuspendingStep($owner);

        $run = WorkflowRun::factory()->running()->create([
            'workflow_id' => $workflow->id,
            'waiting_on' => null,
        ]);

        app(WorkflowStepRunner::class)->resume($run);
        $run->refresh();

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.runs.resume_without_wait'), $run->error);
        $this->assertSame(0, $this->fake->runCalls);
        $this->assertSame(0, $run->steps()->count());
    }

    // ---------------------------------------------------------------- the reapers

    public function test_a_waiting_run_is_never_reaped_by_the_stale_running_reaper(): void
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        config(['workflows.run_timeout' => 900]);

        // Claimed long before the run timeout, but parked — the stale-RUNNING reaper matches
        // `state = running` ONLY, so this is an invariant of the engine, not an accident (G3).
        $run = WorkflowRun::factory()->waiting()->create([
            'workflow_id' => $workflow->id,
            'started_at' => now()->subHours(3),
            'waiting_since' => now()->subMinute(),
        ]);

        $this->assertSame(0, app(WorkflowRunManager::class)->reapStaleRuns());
        $this->assertSame(WorkflowRunState::WAITING, $run->fresh()->state);
    }

    public function test_the_waiting_sweep_resumes_a_settled_wait(): void
    {
        Queue::fake();

        $run = $this->waitingRunForKind(WaitStatus::SETTLED);

        $this->assertSame(1, app(WorkflowRunManager::class)->reapWaitingRuns());
        $this->assertSame(WorkflowRunState::WAITING, $run->fresh()->state, 'The sweep dispatches; the JOB claims.');

        Queue::assertPushed(WorkflowRunResumeJob::class, fn ($job) => $job->runId === $run->id);
    }

    public function test_the_waiting_sweep_fails_a_wait_whose_work_is_gone(): void
    {
        $run = $this->waitingRunForKind(WaitStatus::GONE);

        $this->assertSame(1, app(WorkflowRunManager::class)->reapWaitingRuns());

        $run->refresh();
        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.runs.wait_gone'), $run->error);
        $this->assertNotNull($run->finished_at);
    }

    public function test_the_waiting_sweep_leaves_a_pending_wait_alone(): void
    {
        $run = $this->waitingRunForKind(WaitStatus::PENDING);

        $this->assertSame(0, app(WorkflowRunManager::class)->reapWaitingRuns());
        $this->assertSame(WorkflowRunState::WAITING, $run->fresh()->state);
    }

    public function test_the_waiting_sweep_fails_a_wait_past_the_wait_timeout(): void
    {
        config(['workflows.wait_timeout' => 2700]);

        $run = $this->waitingRunForKind(WaitStatus::PENDING, ['waiting_since' => now()->subHours(2)]);

        $this->assertSame(1, app(WorkflowRunManager::class)->reapWaitingRuns());

        $run->refresh();
        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.runs.wait_timed_out'), $run->error);
    }

    public function test_an_unregistered_wait_kind_is_left_waiting_until_the_timeout(): void
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        // The run below parks on the factory's default kind, for which NOTHING is registered. (The engine
        // itself still hard-codes no kind: every registered one — `generation_session` since R2, and
        // `publication` since R4 B6 — is REGISTERED by a module provider like any other, and this test
        // asserts the engine's behaviour for a kind that is not.) A missing resolver is a deploy fault,
        // not proof the work vanished, so the run is left parked (the timeout still bounds it).
        //
        // The registered set is asserted as a CONTENTS check rather than an exact list on purpose: the
        // fact this test needs is "`test_wait` is not registered", and pinning the exact roster here made
        // an unrelated batch that adds a wait kind fail a test about unregistered kinds. Each kind's own
        // registration is pinned by its own module's boundary test, which is where that belongs.
        $kinds = app(WaitResolverRegistry::class)->kinds();
        $this->assertContains('generation_session', $kinds);
        $this->assertNotContains('test_wait', $kinds, 'the factory parks on an UNregistered kind by design');

        $run = WorkflowRun::factory()->waiting()->create([
            'workflow_id' => $workflow->id,
            'waiting_since' => now(),
        ]);

        $this->assertSame(0, app(WorkflowRunManager::class)->reapWaitingRuns());
        $this->assertSame(WorkflowRunState::WAITING, $run->fresh()->state);
    }

    public function test_a_throwing_resolver_leaves_the_run_waiting_instead_of_aborting_the_sweep(): void
    {
        $run = $this->waitingRunForKind(WaitStatus::PENDING, [], throws: true);

        $this->assertSame(0, app(WorkflowRunManager::class)->reapWaitingRuns());
        $this->assertSame(WorkflowRunState::WAITING, $run->fresh()->state);
    }

    public function test_the_reaper_command_reports_both_sweeps(): void
    {
        $this->waitingRunForKind(WaitStatus::GONE);

        $this->artisan('workflows:reap-stale-runs')
            ->expectsOutputToContain('Reaped 0 stale workflow run(s).')
            ->expectsOutputToContain('Handled 1 waiting workflow run(s).')
            ->assertSuccessful();
    }

    /** A `waiting` run whose kind is answered by a scripted resolver. */
    private function waitingRunForKind(WaitStatus $status, array $attributes = [], bool $throws = false): WorkflowRun
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        app(WaitResolverRegistry::class)->register(new FakeWaitResolver($status, 'test_wait', $throws));

        return WorkflowRun::factory()->waiting()->create(['workflow_id' => $workflow->id] + $attributes);
    }

    // ---------------------------------------------------------------- the real-queue escape hatch

    public function test_the_real_queue_connection_is_published_during_the_loop_and_cleared_after(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => FakeSuspendableStep::TYPE, 'key' => 'wait', 'config' => []],
            ],
        ]);

        $run = WorkflowRun::factory()->pending()->create(['workflow_id' => $workflow->id]);

        // Pretend the worker is running on a REAL queue connection: the job forces `sync` around the
        // loop, so a suspending step needs the pre-override name to put its external work on the
        // real queue.
        Queue::setDefaultDriver('database');

        (new WorkflowRunJob($run->id))->handle(app(WorkflowRunManager::class), app(WorkflowStepRunner::class));

        $this->assertSame('database', $this->fake->seenRealQueueConnection);
        // Restored AND cleared in the same finally — a leak would mis-route a later job's dispatches
        // in a long-running worker.
        $this->assertSame('database', Queue::getDefaultDriver());
        $this->assertNull(app(RealQueueConnection::class)->current());

        Queue::setDefaultDriver('sync');

        $this->assertSame(WorkflowRunState::WAITING, $run->fresh()->state);
    }

    public function test_the_resume_job_also_publishes_and_clears_the_real_queue_connection(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $run = $this->startAndSuspend($this->workflowWithASuspendingStep($owner));

        $this->fake->seenRealQueueConnection = null;

        Queue::setDefaultDriver('database');
        $this->resume($run);

        // The resumed step saw the hatch too (it may need to suspend again onto the real queue) …
        $this->assertSame('database', $this->fake->seenRealQueueConnection);
        // … and the resume job restored + cleared it in the same finally.
        $this->assertSame('database', Queue::getDefaultDriver());
        $this->assertNull(app(RealQueueConnection::class)->current());

        Queue::setDefaultDriver('sync');
    }

    public function test_the_resume_job_no_ops_on_a_missing_or_unclaimable_run(): void
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        // Missing run.
        (new WorkflowRunResumeJob('00000000-0000-0000-0000-000000000000', ''))->handle(
            app(WorkflowRunManager::class),
            app(WorkflowStepRunner::class),
        );

        // A run that is not waiting — the resume claim loses.
        $run = WorkflowRun::factory()->completed()->create(['workflow_id' => $workflow->id]);
        $this->resume($run);

        $this->assertSame(WorkflowRunState::COMPLETED, $run->fresh()->state);
        $this->assertSame(0, $this->fake->resumeCalls);
    }

    public function test_the_resume_jobs_failed_hook_releases_a_non_terminal_run(): void
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        $run = WorkflowRun::factory()->running()->create(['workflow_id' => $workflow->id]);

        (new WorkflowRunResumeJob($run->id, ''))->failed(new \RuntimeException('worker died mid-resume'));

        $this->assertSame(WorkflowRunState::FAILED, $run->fresh()->state);
        $this->assertStringContainsString('worker died mid-resume', $run->fresh()->error);
    }
}
