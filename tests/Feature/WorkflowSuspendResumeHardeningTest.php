<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Workflows\Enums\WaitStatus;
use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Exceptions\StepSuspended;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\Support\FakeStepFactory;
use Tests\Support\FakeSuspendableStep;
use Tests\Support\FakeWaitResolver;
use Tests\TestCase;

/**
 * HARDENING regressions for the suspend/resume engine (BE-0), each one a reproduction of a defect an
 * adversarial review PROVED with a throwaway probe. Every test here was RED before its fix.
 *
 * The happy path lives in {@see WorkflowSuspendResumeTest}; the byte-identity pin for workflows
 * WITHOUT a suspending step lives in {@see WorkflowRunEngineRegressionTest}.
 */
class WorkflowSuspendResumeHardeningTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    private FakeSuspendableStep $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeSuspendableStep;
        $this->app->instance(WorkflowStepFactory::class, new FakeStepFactory($this->fake));
    }

    /** The standard three-step shape: an ordinary step, the suspending step, an ordinary step. */
    private function workflowWithASuspendingStep(User $owner, array $waitConfig = ['echo' => 'hello']): Workflow
    {
        return Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'before', 'config' => ['title' => 'Before the wait']],
                ['type' => FakeSuspendableStep::TYPE, 'key' => 'wait', 'config' => $waitConfig],
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'after', 'config' => [
                    'title' => 'After the wait: {{steps.wait.settled}}',
                ]],
            ],
        ]);
    }

    private function startAndSuspend(Workflow $workflow): WorkflowRun
    {
        return app(WorkflowRunManager::class)
            ->start($workflow, WorkflowRunOrigin::EVENT, ['source' => 'test'])
            ->fresh();
    }

    /** Deliver a resume job. $waitingKey defaults to the key the run is CURRENTLY parked on. */
    private function resume(WorkflowRun $run, ?string $waitingKey = null): void
    {
        (new WorkflowRunResumeJob($run->id, '', $waitingKey ?? (string) $run->waiting_key))->handle(
            app(WorkflowRunManager::class),
            app(WorkflowStepRunner::class),
        );
    }

    /** Encode an `@[ai-text]` directive exactly as the editor does (JSON then `"`→`\"`). */
    private function aiText(string $prompt): string
    {
        $payload = json_encode([
            'v' => 1,
            'data' => ['id' => 'ai_1', 'personaId' => null, 'prompt' => $prompt, 'labels' => []],
        ]);

        return '@[ai-text]("' . str_replace('"', '\\"', $payload) . '")';
    }

    /** Fake the ai-text agent with a counting script; returns a by-ref call counter. */
    private function countingAiAgent(): object
    {
        $counter = new class
        {
            public int $calls = 0;
        };

        AiTextAgent::fake(function () use ($counter): string {
            $counter->calls++;

            return 'GEN' . $counter->calls;
        });

        return $counter;
    }

    // ---------------------------------------------------------------- FIX 1: correlated resume claim

    /**
     * FIX 1 (BLOCKING). Two resume jobs exist for leg A (a settle listener racing the sweep, two
     * sweeps both seeing SETTLED, or a retry_after redelivery). Job 1 resumes leg A and the step
     * RE-SUSPENDS on leg B — explicitly allowed by the SuspendableWorkflowStep contract. Job 2 must
     * NOT be able to resume leg B, whose external work has not settled.
     *
     * Before the fix `claimResume()` guarded `state = waiting` only, so job 2 won the claim on the
     * NEW wait: resumeCalls=2, legs=a,b, state=completed — the run finished while leg B's work was
     * still in flight.
     */
    public function test_a_duplicate_resume_job_cannot_resume_a_different_leg(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $run = $this->startAndSuspend($this->workflowWithASuspendingStep($owner));
        $legA = (string) $run->waiting_key;

        // The settled leg-A work spawned more external work, so the step parks the run again.
        $this->fake->suspendOnResumeKey = 'fake_wait:leg-b';

        $this->resume($run, $legA);
        $run->refresh();

        $this->assertSame(WorkflowRunState::WAITING, $run->state);
        $this->assertSame('fake_wait:leg-b', $run->waiting_key);

        // The DUPLICATE delivery for leg A. It must lose the claim: its key no longer matches.
        $this->resume($run, $legA);
        $run->refresh();

        $this->assertSame(1, $this->fake->resumeCalls, 'The duplicate leg-A job must not resume leg B.');
        $this->assertSame(WorkflowRunState::WAITING, $run->state);
        $this->assertSame('fake_wait:leg-b', $run->waiting_key);
        $this->assertDatabaseMissing('tasks', ['title' => 'After the wait: yes']);

        // Leg B settling for real DOES resume the run, and it completes.
        $this->resume($run, 'fake_wait:leg-b');
        $run->refresh();

        $this->assertSame(2, $this->fake->resumeCalls);
        $this->assertSame(['fake_wait:abc-123', 'fake_wait:leg-b'], $this->fake->legs);
        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertDatabaseHas('tasks', ['title' => 'After the wait: yes']);
    }

    /** A resume job carrying a key the run is not parked on is a clean, silent no-op. */
    public function test_a_resume_job_with_a_stale_waiting_key_is_a_no_op(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $run = $this->startAndSuspend($this->workflowWithASuspendingStep($owner));

        $this->resume($run, 'fake_wait:not-this-one');
        $run->refresh();

        $this->assertSame(0, $this->fake->resumeCalls);
        $this->assertSame(WorkflowRunState::WAITING, $run->state);
        $this->assertSame('fake_wait:abc-123', $run->waiting_key);
    }

    /** The sweep dispatches the key it OBSERVED, so the job it creates is correlated to that wait. */
    public function test_the_waiting_sweep_dispatches_the_key_it_observed(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        app(WaitResolverRegistry::class)->register(new FakeWaitResolver(WaitStatus::SETTLED, 'test_wait'));

        $run = WorkflowRun::factory()->waiting()->create([
            'workflow_id' => $workflow->id,
            'waiting_key' => 'test_wait:observed',
        ]);

        app(WorkflowRunManager::class)->reapWaitingRuns();

        Queue::assertPushed(
            WorkflowRunResumeJob::class,
            fn ($job) => $job->runId === $run->id && $job->waitingKey === 'test_wait:observed',
        );
    }

    // ---------------------------------------------------------------- FIX 2: persist the resolved config

    /**
     * FIX 2 (BLOCKING for BE-2). The resolved config is captured AT SUSPEND and replayed on resume,
     * so a spend-incurring directive inside a suspendable step's config is paid EXACTLY ONCE.
     *
     * Before the fix the resumed pass re-resolved the raw config from the live definition, firing the
     * `@[ai-text]` directive a SECOND time (2 provider calls, and a different generated value).
     */
    public function test_a_spend_incurring_directive_in_a_suspended_steps_config_is_paid_once(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $counter = $this->countingAiAgent();

        $run = $this->startAndSuspend(
            $this->workflowWithASuspendingStep($owner, ['echo' => $this->aiText('write the echo')]),
        );

        $this->assertSame(1, $counter->calls);
        $this->assertSame(['echo' => 'GEN1'], $run->waiting_on['config'], 'The RESOLVED config is persisted at suspend.');

        $this->resume($run);
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertSame(1, $counter->calls, 'The resumed pass must NOT re-run the directive.');
        $this->assertSame(['echo' => 'GEN1'], $this->fake->lastResumeConfig);
    }

    /** A pre-change `waiting_on` (no persisted config, no fingerprint) still resumes by re-resolving. */
    public function test_a_wait_without_a_persisted_config_falls_back_to_re_resolution(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $run = $this->startAndSuspend($this->workflowWithASuspendingStep($owner));

        // Simulate a row written before this batch: the two new keys are absent.
        $legacy = $run->waiting_on;
        unset($legacy['config'], $legacy['definition_hash']);
        $run->update(['waiting_on' => $legacy]);

        $this->resume($run->fresh());
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertSame(1, $this->fake->resumeCalls);
        $this->assertSame(['echo' => 'hello'], $this->fake->lastResumeConfig, 'Re-resolved from the live definition.');
    }

    // ---------------------------------------------------------------- FIX 3: the per-run ai-text cap

    /**
     * FIX 3 (HIGH). `workflows.ai_text_max_calls_per_run` is documented as a per-RUN hard cap. Before
     * the fix the counter was instance state on a service resolved fresh per JOB, so a run that
     * suspended once got the cap TWICE over.
     */
    public function test_the_per_run_ai_text_budget_survives_a_resume(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        config(['workflows.ai_text_max_calls_per_run' => 1]);
        $counter = $this->countingAiAgent();

        $workflow = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'before', 'config' => [
                    'title' => 'Before: ' . $this->aiText('first'),
                ]],
                ['type' => FakeSuspendableStep::TYPE, 'key' => 'wait', 'config' => []],
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'after', 'config' => [
                    'title' => 'After: ' . $this->aiText('second'),
                ]],
            ],
        ]);

        $run = $this->startAndSuspend($workflow);

        $this->assertSame(1, $counter->calls);
        $this->assertDatabaseHas('tasks', ['title' => 'Before: GEN1']);

        $this->resume($run);
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertSame(1, $counter->calls, 'The per-RUN cap must not reset on the resumed pass.');
        // Over budget → the directive resolves to '' (fail-closed), so only the literal survives.
        $this->assertDatabaseHas('tasks', ['title' => 'After: ']);
    }

    // ---------------------------------------------------------------- FIX 4: nested-run queue leak

    /**
     * FIX 4 (HIGH, latent). Under the sync override a re-triggered CHILD run executes IN-PROCESS. Its
     * job's `finally` used to `clear()` the RealQueueConnection singleton unconditionally, wiping the
     * PARENT's published value: the parent's suspending step then saw null and would dispatch its
     * "external" work onto `sync`, running it INLINE and defeating suspension entirely.
     *
     * Probe before the fix: `seen=NULL`.
     */
    public function test_a_nested_run_leaves_the_parents_real_queue_connection_intact(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // The CHILD: an ordinary workflow, executed in-process from inside the parent's step.
        $child = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'child', 'config' => ['title' => 'Child task']],
            ],
        ]);
        $childRun = WorkflowRun::factory()->pending()->create(['workflow_id' => $child->id]);

        $parent = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => FakeSuspendableStep::TYPE, 'key' => 'wait', 'config' => []],
            ],
        ]);
        $parentRun = WorkflowRun::factory()->pending()->create(['workflow_id' => $parent->id]);

        // The parent's step triggers the child run inline (what the sync override makes of any
        // re-trigger), and only THEN reads the escape hatch.
        $this->fake->beforeRun = function () use ($childRun): void {
            (new WorkflowRunJob($childRun->id))->handle(app(WorkflowRunManager::class), app(WorkflowStepRunner::class));
        };

        Queue::setDefaultDriver('database');

        (new WorkflowRunJob($parentRun->id))->handle(app(WorkflowRunManager::class), app(WorkflowStepRunner::class));

        $this->assertSame(WorkflowRunState::COMPLETED, $childRun->fresh()->state);
        $this->assertSame(
            'database',
            $this->fake->seenRealQueueConnection,
            'The child run must not wipe the parent’s published real-queue connection.',
        );

        // The parent still restores + un-publishes at the end of ITS own finally.
        $this->assertSame('database', Queue::getDefaultDriver());
        $this->assertNull(app(RealQueueConnection::class)->current());

        Queue::setDefaultDriver('sync');
    }

    /** The resume job observes the same save/restore discipline. */
    public function test_a_nested_run_inside_a_resumed_pass_also_leaves_the_hatch_intact(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $child = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'child', 'config' => ['title' => 'Child task']],
            ],
        ]);

        $run = $this->startAndSuspend($this->workflowWithASuspendingStep($owner));

        $childRun = WorkflowRun::factory()->pending()->create(['workflow_id' => $child->id]);
        $seen = null;
        $this->fake->beforeRun = null;
        $this->fake->suspendOnResumeKey = null;

        Queue::setDefaultDriver('database');

        // Nest a child run inside the RESUMED pass by triggering it from the step-after-the-wait's
        // side effect seam: run the child job from a model event fired during the resumed pass.
        \Illuminate\Support\Facades\Event::listen('eloquent.created: ' . \App\Modules\Tasks\Models\Task::class, function () use ($childRun, &$seen): void {
            if ($childRun->fresh()->state !== WorkflowRunState::PENDING) {
                return;
            }

            (new WorkflowRunJob($childRun->id))->handle(app(WorkflowRunManager::class), app(WorkflowStepRunner::class));
            $seen = app(RealQueueConnection::class)->current();
        });

        $this->resume($run);

        $this->assertSame('database', $seen, 'The resumed pass’ hatch must survive an in-process child run.');
        $this->assertSame('database', Queue::getDefaultDriver());
        $this->assertNull(app(RealQueueConnection::class)->current());

        Queue::setDefaultDriver('sync');
    }

    // ---------------------------------------------------------------- FIX 5: nested-run CONTEXT leak

    /**
     * FIX 5 (the SAME defect class as FIX 4, one seam over). WorkflowStepRunner::execute() published the
     * active run to WorkflowRunContext and `clear()`ed it unconditionally in its `finally`. A re-triggered
     * CHILD run executes IN-PROCESS inside a step (the sync override), so the runner can be nested inside
     * itself — and the child's finally wiped the PARENT's published run. Every row a LATER parent step
     * authored then stopped being stamped with the parent run: HasCreator fell through to the ambient
     * AUTHENTICATED USER, silently mis-attributing an engine-authored record to a human (ADR-0015).
     *
     * Probe before the fix: the second task's creator is the acting USER, not the parent run.
     */
    public function test_a_nested_run_leaves_the_parents_published_run_context_intact(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $child = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'child', 'config' => ['title' => 'Child task']],
            ],
        ]);
        $childRun = WorkflowRun::factory()->pending()->create(['workflow_id' => $child->id]);

        // The parent re-triggers the child from its FIRST step, then authors a row from a LATER step —
        // the row whose attribution the leak used to break.
        $parent = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => FakeSuspendableStep::TYPE, 'key' => 'nest', 'config' => []],
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'after', 'config' => ['title' => 'After the nested run']],
            ],
        ]);
        $parentRun = WorkflowRun::factory()->pending()->create(['workflow_id' => $parent->id]);

        $this->fake->suspendOnRun = false;
        $this->fake->beforeRun = function () use ($childRun): void {
            (new WorkflowRunJob($childRun->id))->handle(app(WorkflowRunManager::class), app(WorkflowStepRunner::class));
        };

        (new WorkflowRunJob($parentRun->id))->handle(app(WorkflowRunManager::class), app(WorkflowStepRunner::class));

        $this->assertSame(WorkflowRunState::COMPLETED, $childRun->fresh()->state);
        $this->assertSame(WorkflowRunState::COMPLETED, $parentRun->fresh()->state);

        $after = \App\Modules\Tasks\Models\Task::where('title', 'After the nested run')->firstOrFail();
        $this->assertSame('workflow_run', $after->creator_type);
        $this->assertSame($parentRun->id, $after->creator_id, 'the child run must not wipe the parent’s published run context');

        // The child's own row is still stamped with the CHILD — the save/restore is a stack, not a no-op.
        $childTask = \App\Modules\Tasks\Models\Task::where('title', 'Child task')->firstOrFail();
        $this->assertSame($childRun->id, $childTask->creator_id);

        // The OUTERMOST frame still un-publishes, exactly as the old clear() did.
        $this->assertNull(app(\App\Modules\Workflows\Services\WorkflowRunContext::class)->current());
    }

    // ---------------------------------------------------------------- FIX 5: whole-definition drift

    /**
     * FIX 5 (MEDIUM). The drift guard validated ONE position, so steps appended/removed/edited AFTER
     * the suspended index were read live and executed on the resumed pass — a mid-flight definition
     * change silently altering an in-flight run.
     */
    public function test_appending_a_step_during_the_wait_fails_the_run(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->workflowWithASuspendingStep($owner);
        $run = $this->startAndSuspend($workflow);

        $steps = $workflow->steps;
        $steps[] = ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'appended', 'config' => ['title' => 'Appended']];
        $workflow->update(['steps' => $steps]);

        $this->resume($run->fresh());
        $run->refresh();

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.runs.definition_changed'), $run->error);
        $this->assertSame(0, $this->fake->resumeCalls);
        $this->assertDatabaseMissing('tasks', ['title' => 'Appended']);
    }

    /**
     * The other half of FIX 2's drift: editing the SUSPENDED step's own config during the wait passes
     * the per-position key+type check, so before the fingerprint it silently collected the outcome
     * under a config the step never started the work with (`resumeConfig={"echo":"EDITED"}`).
     */
    public function test_editing_the_suspended_steps_config_during_the_wait_fails_the_run(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->workflowWithASuspendingStep($owner);
        $run = $this->startAndSuspend($workflow);

        $steps = $workflow->steps;
        $steps[1]['config'] = ['echo' => 'EDITED'];
        $workflow->update(['steps' => $steps]);

        $this->resume($run->fresh());
        $run->refresh();

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(__('workflows.runs.definition_changed'), $run->error);
        $this->assertSame(0, $this->fake->resumeCalls);
    }

    /** An untouched definition resumes normally — the fingerprint must not be a false positive. */
    public function test_an_untouched_definition_still_resumes(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->workflowWithASuspendingStep($owner);
        $run = $this->startAndSuspend($workflow);

        // A no-op save of the SAME steps must not look like drift.
        $workflow->update(['steps' => $workflow->steps]);

        $this->resume($run->fresh());

        $this->assertSame(WorkflowRunState::COMPLETED, $run->fresh()->state);
        $this->assertSame(1, $this->fake->resumeCalls);
    }

    // ---------------------------------------------------------------- FIX 6: a dropped park

    /**
     * FIX 6 (LOW). suspend() is GUARDED on `state = running`. If the run was released concurrently
     * (a failure, the stale-running reaper) the park is DROPPED — the external work is in flight and
     * now orphaned. It must report that, not swallow it.
     */
    public function test_a_dropped_park_returns_zero_and_is_logged(): void
    {
        Log::spy();

        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);
        $run = WorkflowRun::factory()->completed()->create(['workflow_id' => $workflow->id]);

        $affected = app(WorkflowRunManager::class)->suspend(
            $run,
            0,
            'wait',
            FakeSuspendableStep::TYPE,
            new StepSuspended('fake_wait', 'fake_wait:orphan', ['secret' => 'do-not-log']),
            [],
            [],
            'hash',
            0,
        );

        $this->assertSame(0, $affected);
        $this->assertSame(WorkflowRunState::COMPLETED, $run->fresh()->state);
        $this->assertNull($run->fresh()->waiting_key);

        Log::shouldHaveReceived('warning')->once();
    }

    // ---------------------------------------------------------------- FIX 7: the keyless legacy step

    /**
     * FIX 7 (LOW). The loop keys a step `$step['key'] ?? $position` while the drift guard used
     * `$step['key'] ?? ''`, so a KEYLESS legacy step parked as `step_key='1'` and then ALWAYS failed
     * as drift on resume.
     */
    public function test_a_keyless_step_resumes_instead_of_failing_as_drift(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'before', 'config' => ['title' => 'Before the wait']],
                // No 'key' at all — the legacy shape.
                ['type' => FakeSuspendableStep::TYPE, 'config' => ['echo' => 'hello']],
            ],
        ]);

        $run = $this->startAndSuspend($workflow);

        $this->assertSame('1', $run->waiting_on['step_key']);

        $this->resume($run);
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertNull($run->error);
        $this->assertSame(1, $this->fake->resumeCalls);
    }

    // ---------------------------------------------------------------- FIX 8: failed() vs a parked run

    /**
     * FIX 8 (LOW). Both failed() hooks skipped only on `isTerminal()`. A run parked `waiting` is NOT
     * terminal, so an exception escaping around handle() AFTER the park would fail a perfectly
     * healthy waiting run (and orphan its external work).
     */
    public function test_the_run_jobs_failed_hook_leaves_a_parked_run_waiting(): void
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);
        $run = WorkflowRun::factory()->waiting()->create(['workflow_id' => $workflow->id]);

        (new WorkflowRunJob($run->id))->failed(new \RuntimeException('escaped after the park'));

        $this->assertSame(WorkflowRunState::WAITING, $run->fresh()->state);
        $this->assertNull($run->fresh()->error);
    }

    public function test_the_resume_jobs_failed_hook_leaves_a_parked_run_waiting(): void
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);
        $run = WorkflowRun::factory()->waiting()->create(['workflow_id' => $workflow->id]);

        (new WorkflowRunResumeJob($run->id, '', (string) $run->waiting_key))
            ->failed(new \RuntimeException('escaped after the re-park'));

        $this->assertSame(WorkflowRunState::WAITING, $run->fresh()->state);
        $this->assertNull($run->fresh()->error);
    }

    // ---------------------------------------------------------------- FIX 9: the chunked sweep

    /**
     * FIX 9 (LOW). The sweep now partitions on `waiting_since` in SQL (so the (state, waiting_since)
     * index is actually used) and CHUNKS the fetch. This pins the SEMANTICS that refactor must not
     * change: every waiting run is still asked the resolver first, and a SETTLED wait is resumed even
     * when it is already past the wait timeout (a real outcome beats a timeout).
     */
    public function test_the_chunked_sweep_preserves_the_settled_beats_timeout_order(): void
    {
        Queue::fake();
        config(['workflows.wait_timeout' => 2700]);

        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        $registry = app(WaitResolverRegistry::class);
        $registry->register(new FakeWaitResolver(WaitStatus::SETTLED, 'settled_kind'));
        $registry->register(new FakeWaitResolver(WaitStatus::PENDING, 'pending_kind'));

        $make = function (string $kind, bool $old) use ($workflow): WorkflowRun {
            $run = WorkflowRun::factory()->waiting()->create(['workflow_id' => $workflow->id]);
            $waitingOn = $run->waiting_on;
            $waitingOn['kind'] = $kind;

            $run->update([
                'waiting_on' => $waitingOn,
                'waiting_since' => $old ? now()->subHours(2) : now(),
            ]);

            return $run->fresh();
        };

        // More rows than one chunk page, so the chunking itself is exercised.
        $youngSettled = collect(range(1, 25))->map(fn () => $make('settled_kind', false));
        $oldSettled = $make('settled_kind', true);
        $oldPending = $make('pending_kind', true);
        $youngPending = $make('pending_kind', false);

        $handled = app(WorkflowRunManager::class)->reapWaitingRuns();

        $this->assertSame(27, $handled, '25 young settled + 1 old settled + 1 timed out.');

        Queue::assertPushed(WorkflowRunResumeJob::class, 26);
        Queue::assertPushed(
            WorkflowRunResumeJob::class,
            fn ($job) => $job->runId === $oldSettled->id,
            // A SETTLED wait past the timeout is RESUMED, never failed as timed out.
        );

        $this->assertSame(WorkflowRunState::WAITING, $oldSettled->fresh()->state);
        $this->assertSame(WorkflowRunState::WAITING, $youngSettled->first()->fresh()->state);
        $this->assertSame(WorkflowRunState::FAILED, $oldPending->fresh()->state);
        $this->assertSame(__('workflows.runs.wait_timed_out'), $oldPending->fresh()->error);
        $this->assertSame(WorkflowRunState::WAITING, $youngPending->fresh()->state);
    }

    /** A NULL waiting_since orphan is still failed as timed out (it lands in the stale partition). */
    public function test_a_null_waiting_since_orphan_is_still_timed_out(): void
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        $run = WorkflowRun::factory()->waiting()->create([
            'workflow_id' => $workflow->id,
            'waiting_since' => null,
        ]);

        $this->assertSame(1, app(WorkflowRunManager::class)->reapWaitingRuns());
        $this->assertSame(WorkflowRunState::FAILED, $run->fresh()->state);
        $this->assertSame(__('workflows.runs.wait_timed_out'), $run->fresh()->error);
    }
}
