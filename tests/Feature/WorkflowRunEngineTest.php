<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Variables\Agents\AiTextAgent;
use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowRunStepStatus;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Jobs\WorkflowRunJob;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * Batch 2 run engine. QUEUE_CONNECTION=sync runs the dispatched WorkflowRunJob in-process
 * (with QueueTenancy) the moment start() commits, so a single call drives the whole run:
 * claim -> steps in order -> record step rows -> merge outputs -> terminal state.
 */
class WorkflowRunEngineTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    /** @param array<int, array{type: string, key: string, config: array}> $steps */
    private function workflowWithSteps(User $owner, array $steps): Workflow
    {
        return Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => $steps,
        ]);
    }

    private function start(Workflow $workflow, array $trigger = []): WorkflowRun
    {
        return app(WorkflowRunManager::class)->start($workflow, WorkflowRunOrigin::EVENT, $trigger);
    }

    public function test_run_executes_steps_in_order_and_completes(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = $this->workflowWithSteps($owner, [
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'make_task', 'config' => ['title' => 'Generated task']],
        ]);

        $run = $this->start($workflow, ['source' => 'test']);
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        // A step row was recorded as succeeded, and the created task exists in TO_DO.
        $step = $run->steps()->first();
        $this->assertSame(WorkflowRunStepStatus::SUCCEEDED, $step->status);
        $this->assertSame('make_task', $step->key);

        $taskId = $step->payload['task_id'];
        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'title' => 'Generated task', 'status' => TaskStatus::TO_DO->value]);
    }

    public function test_later_step_receives_a_prior_steps_output_via_reference(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // Step 2 references the TITLE step 1 produced: {{steps.make_task.title}} — proving
        // step-output chaining flows through the WorkflowVariableResolver into a later step's config.
        $workflow = $this->workflowWithSteps($owner, [
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'make_task', 'config' => ['title' => 'Origin task']],
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'child', 'config' => [
                'title' => 'Child of {{steps.make_task.title}}',
            ]],
        ]);

        $run = $this->start($workflow);
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);

        $steps = $run->steps()->get();

        // The second step resolved the prior step's title into its own created task.
        $this->assertSame('Child of Origin task', $steps[1]->payload['title']);
        $this->assertDatabaseHas('tasks', ['id' => $steps[1]->payload['task_id'], 'title' => 'Child of Origin task']);
    }

    public function test_run_executes_directive_pipeline_and_if_block_in_a_title(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // The title carries a variable directive with an uppercase pipeline AND a fenced if-block
        // that selects a branch by a boolean condition — both must resolve before the task is made.
        $directive = $this->directive('trigger.fields.priority', [
            ['stepId' => 's1', 'operationId' => 'text_uppercase', 'args' => [], 'outputType' => 'text'],
        ]);
        $ifBlock = '```if-block ' . json_encode(['id' => 'if_1', 'v' => 1]) . "\n"
            . '[[IF ' . json_encode(['id' => 'b1', 'condition' => [
                'variableId' => 'trigger.fields.priority',
                'pipeline' => [['stepId' => 's2', 'operationId' => 'text_equals', 'args' => ['value' => 'high'], 'outputType' => 'boolean']],
                'resultType' => 'boolean',
            ]]) . ']]' . "\n" . 'URGENT' . "\n"
            . '[[ELSE ' . json_encode(['id' => 'b2']) . ']]' . "\n" . 'normal' . "\n```";

        $workflow = $this->workflowWithSteps($owner, [
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'make_task', 'config' => [
                'title' => 'P=' . $directive,
                'description' => $ifBlock,
            ]],
        ]);

        $run = $this->start($workflow, ['fields' => ['priority' => 'high']]);
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertDatabaseHas('tasks', ['id' => $run->steps()->first()->payload['task_id'], 'title' => 'P=HIGH']);
    }

    public function test_run_executes_value_or_variable_deadline_pipeline(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // deadline = the trigger's scheduled date + 5 days, via a value-or-variable pipeline.
        $workflow = $this->workflowWithSteps($owner, [
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'make_task', 'config' => [
                'title' => 'Deadline task',
                'deadline' => [
                    'kind' => 'variable',
                    'ref' => ['source' => 'trigger', 'path' => 'scheduled_at', 'type' => 'date'],
                    'pipeline' => [['op' => 'date_add_days', 'args' => ['value' => 5]]],
                ],
            ]],
        ]);

        $run = $this->start($workflow, ['scheduled_at' => '2026-03-01']);
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);

        $task = \App\Modules\Tasks\Models\Task::findOrFail($run->steps()->first()->payload['task_id']);
        $this->assertSame('2026-03-06', $task->deadline->format('Y-m-d'));
    }

    public function test_run_generates_a_task_title_from_an_ai_text_directive(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // The whole pipeline: the create_task title is an `@[ai-text]` directive. The agent is
        // faked (Promptable::fake), so the run generates the title deterministically end-to-end.
        AiTextAgent::fake(['AI Generated Task Title']);

        $payload = json_encode([
            'v' => 1,
            'data' => ['id' => 'ai_1', 'personaId' => 'concise', 'prompt' => 'Write a task title', 'labels' => []],
        ]);
        $aiText = '@[ai-text]("' . str_replace('"', '\\"', $payload) . '")';

        $workflow = $this->workflowWithSteps($owner, [
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'make_task', 'config' => ['title' => $aiText]],
        ]);

        $run = $this->start($workflow);
        $run->refresh();

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertDatabaseHas('tasks', [
            'id' => $run->steps()->first()->payload['task_id'],
            'title' => 'AI Generated Task Title',
        ]);
    }

    /** A variable directive on $path whose editor pipeline is $pipeline. */
    private function directive(string $path, array $pipeline): string
    {
        $payload = json_encode([
            'v' => 1,
            'data' => ['id' => $path, 'name' => $path, 'type' => 'text', 'locked' => false, 'pipeline' => $pipeline, 'resultType' => 'text'],
        ]);

        return '@[variable]("' . str_replace('"', '\\"', $payload) . '")';
    }

    public function test_step_failure_stops_the_run_and_records_the_error(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // Step 1 fails (create_task with no title); step 2 must NOT execute.
        $workflow = $this->workflowWithSteps($owner, [
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'bad', 'config' => []],
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'never', 'config' => ['title' => 'Should not run']],
        ]);

        $run = $this->start($workflow);
        $run->refresh();

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertNotNull($run->error);

        // Exactly one step row (the failure); the second step never ran.
        $steps = $run->steps()->get();
        $this->assertCount(1, $steps);
        $this->assertSame(WorkflowRunStepStatus::FAILED, $steps[0]->status);
        $this->assertDatabaseMissing('tasks', ['title' => 'Should not run']);
    }

    public function test_claim_is_atomic_a_second_claim_loses(): void
    {
        $owner = User::factory()->create();
        $workflow = $this->workflowWithSteps($owner, [
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'k', 'config' => ['title' => 'x']],
        ]);

        $run = WorkflowRun::factory()->pending()->create(['workflow_id' => $workflow->id]);
        $manager = app(WorkflowRunManager::class);

        // First claim wins and flips to running; a second claim on the same (now running)
        // run loses.
        $this->assertTrue($manager->claim($run));
        $this->assertFalse($manager->claim($run->fresh()));
        $this->assertSame(WorkflowRunState::RUNNING, $run->fresh()->state);
        $this->assertNotNull($run->fresh()->started_at);
    }

    public function test_reaper_fails_a_stale_running_run_and_leaves_a_fresh_one(): void
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        config(['workflows.run_timeout' => 900]);

        $stale = WorkflowRun::factory()->running()->create([
            'workflow_id' => $workflow->id,
            'started_at' => now()->subHour(),
        ]);
        $fresh = WorkflowRun::factory()->running()->create([
            'workflow_id' => $workflow->id,
            'started_at' => now()->subSeconds(10),
        ]);

        $reaped = app(WorkflowRunManager::class)->reapStaleRuns();

        $this->assertSame(1, $reaped);
        $this->assertSame(WorkflowRunState::FAILED, $stale->fresh()->state);
        $this->assertNotNull($stale->fresh()->error);
        $this->assertSame(WorkflowRunState::RUNNING, $fresh->fresh()->state);
    }

    public function test_reaper_fails_a_null_started_orphan(): void
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        $orphan = WorkflowRun::factory()->running()->create([
            'workflow_id' => $workflow->id,
            'started_at' => null,
        ]);

        $this->assertSame(1, app(WorkflowRunManager::class)->reapStaleRuns());
        $this->assertSame(WorkflowRunState::FAILED, $orphan->fresh()->state);
    }

    public function test_reaper_command_reports_the_number_reaped(): void
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        config(['workflows.run_timeout' => 900]);
        WorkflowRun::factory()->running()->create([
            'workflow_id' => $workflow->id,
            'started_at' => now()->subHour(),
        ]);

        $this->artisan('workflows:reap-stale-runs')
            ->expectsOutputToContain('Reaped 1 stale workflow run(s).')
            ->assertSuccessful();
    }

    public function test_job_failed_hook_releases_a_running_run(): void
    {
        $owner = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        $run = WorkflowRun::factory()->running()->create(['workflow_id' => $workflow->id]);

        (new WorkflowRunJob($run->id))->failed(new \RuntimeException('worker died'));

        $run->refresh();
        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertStringContainsString('worker died', $run->error);
    }

    public function test_lost_claim_is_a_silent_no_op_in_the_job(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $workflow = $this->workflowWithSteps($owner, [
            ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'k', 'config' => ['title' => 'x']],
        ]);

        // A run already claimed (running) — the job's claim loses and it does nothing.
        $run = WorkflowRun::factory()->running()->create(['workflow_id' => $workflow->id]);

        (new WorkflowRunJob($run->id))->handle(
            app(WorkflowRunManager::class),
            app(\App\Modules\Workflows\Services\WorkflowStepRunner::class),
        );

        // Still running, untouched — no steps recorded.
        $this->assertSame(WorkflowRunState::RUNNING, $run->fresh()->state);
        $this->assertSame(0, $run->steps()->count());
    }
}
