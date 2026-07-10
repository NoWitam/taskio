<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Bot\Models\Bot;
use App\Modules\Forms\Models\Form;
use App\Modules\Labels\Models\Label;
use App\Modules\Tasks\DTOs\TaskDTO;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowStepFactory;
use App\Modules\Workflows\Steps\CreateTaskStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * The MVP step (create_task) in isolation: a happy path (acting through the real domain
 * service) plus a missing-required-config failure (a clear RuntimeException the runner records
 * as a step failure). A run row is passed only as the step contract requires it — the step
 * does not read run state.
 */
class WorkflowStepsTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    private function runRow(User $owner): WorkflowRun
    {
        return WorkflowRun::factory()->running()->create([
            'workflow_id' => \App\Modules\Workflows\Models\Workflow::factory()->create(['creator_id' => $owner->id])->id,
        ]);
    }

    public function test_create_task_step_creates_a_task(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $run = $this->runRow($owner);

        $output = app(CreateTaskStep::class)->run(
            ['title' => 'Made by step', 'description' => 'Body', 'priority' => 'high'],
            $run,
            [],
        );

        $this->assertArrayHasKey('task_id', $output);
        $this->assertSame('Made by step', $output['title']);
        $this->assertDatabaseHas('tasks', [
            'id' => $output['task_id'],
            'title' => 'Made by step',
            'priority' => 'high',
            'status' => TaskStatus::TO_DO->value,
        ]);
    }

    public function test_create_task_step_defaults_priority_to_medium(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $output = app(CreateTaskStep::class)->run(['title' => 'No priority'], $this->runRow($owner), []);

        $this->assertDatabaseHas('tasks', ['id' => $output['task_id'], 'priority' => 'medium']);
    }

    public function test_create_task_step_requires_title(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $this->expectException(RuntimeException::class);
        app(CreateTaskStep::class)->run(['title' => '  '], $this->runRow($owner), []);
    }

    public function test_create_task_step_full_field_parity(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $label = Label::create(['name' => 'Marketing']);
        $bot = Bot::factory()->create(['creator_id' => $owner->id]);
        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);
        $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $owner->id]);

        // The context a real run would carry: a form field value and a scheduled date the
        // structured union fields reference.
        $context = [
            'trigger' => [
                'fields' => ['level' => 'urgent'],
                'scheduled_at' => '2026-09-01T10:00:00+00:00',
            ],
            'steps' => [],
        ];

        $output = app(CreateTaskStep::class)->run([
            'title' => 'Full task',
            'description' => 'A **rich** body',
            // priority from a variable → 'urgent'.
            'priority' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.level', 'type' => 'enum']],
            // deadline from a variable → the scheduled date.
            'deadline' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'scheduled_at', 'type' => 'date']],
            'labels' => [$label->id],
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
            'form_id' => $form->id,
            'approval_pipeline_id' => $pipeline->id,
        ], $this->runRow($owner), $context);

        $task = Task::findOrFail($output['task_id']);

        $this->assertSame('Full task', $task->title);
        $this->assertSame(TaskPriority::URGENT, $task->priority);
        $this->assertSame('2026-09-01', $task->deadline->format('Y-m-d'));
        $this->assertSame('bot', $task->assignee_type);
        $this->assertSame($bot->id, $task->assignee_id);
        $this->assertSame($form->id, $task->form_id);
        $this->assertSame($pipeline->id, $task->approval_pipeline_id);
        $this->assertTrue($task->labels->contains('id', $label->id));
    }

    public function test_create_task_step_defaults_an_unknown_priority_to_medium(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // A literal that is not a TaskPriority value soft-defaults to medium (no failure).
        $output = app(CreateTaskStep::class)->run([
            'title' => 'Bad priority',
            'priority' => ['kind' => 'literal', 'value' => 'ultra'],
        ], $this->runRow($owner), []);

        $this->assertDatabaseHas('tasks', ['id' => $output['task_id'], 'priority' => 'medium']);
    }

    public function test_create_task_step_unresolvable_deadline_is_null(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // A variable pointing at an absent path resolves to null → deadline null (soft).
        $output = app(CreateTaskStep::class)->run([
            'title' => 'No deadline',
            'deadline' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'fields.missing', 'type' => 'date']],
        ], $this->runRow($owner), ['trigger' => ['fields' => []], 'steps' => []]);

        $this->assertNull(Task::findOrFail($output['task_id'])->deadline);
    }

    public function test_create_task_step_ignores_nonexistent_label_ids(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        // A label id that does not exist is silently ignored by TaskService's attach — no throw.
        $output = app(CreateTaskStep::class)->run([
            'title' => 'Ghost label',
            'labels' => [\Illuminate\Support\Str::uuid()->toString()],
        ], $this->runRow($owner), []);

        $this->assertCount(0, Task::findOrFail($output['task_id'])->labels);
    }

    public function test_create_form_report_step_creates_a_report_and_dispatches_the_job(): void
    {
        // The report's `created` event dispatches CreateFormReport (the AI job). Queue::fake
        // proves the step is fire-and-forget — the row is created and the job is queued, but
        // the AI analysis never runs in the test (no real provider call).
        \Illuminate\Support\Facades\Queue::fake();

        $owner = User::factory()->create();
        $this->actingAs($owner);
        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        $output = app(\App\Modules\Workflows\Steps\CreateFormReportStep::class)->run([
            'form_id' => $form->id,
            'name' => 'Weekly digest',
            'guidelines' => 'Focus on trends',
            'sources' => ['form'],
        ], $this->runRow($owner), []);

        $this->assertArrayHasKey('report_id', $output);
        $this->assertSame('Weekly digest', $output['report_name']);
        $this->assertDatabaseHas('form_reports', [
            'id' => $output['report_id'],
            'form_id' => $form->id,
            'name' => 'Weekly digest',
            'creator_id' => $owner->id,
        ]);

        Queue::assertPushed(\App\Modules\Forms\Jobs\CreateFormReport::class);
    }

    public function test_create_form_report_step_resolves_a_name_variable(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $owner = User::factory()->create();
        $this->actingAs($owner);
        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        // The runner resolves {{trigger.form.name}} before the step runs; here we feed the
        // already-resolved name to mirror that (the resolver is unit-tested separately).
        $output = app(\App\Modules\Workflows\Steps\CreateFormReportStep::class)->run([
            'form_id' => $form->id,
            'name' => 'Report for Q3 survey',
        ], $this->runRow($owner), []);

        $this->assertDatabaseHas('form_reports', ['id' => $output['report_id'], 'name' => 'Report for Q3 survey']);
    }

    public function test_create_form_report_step_fails_on_a_missing_form(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $this->expectException(RuntimeException::class);
        app(\App\Modules\Workflows\Steps\CreateFormReportStep::class)->run([
            'form_id' => \Illuminate\Support\Str::uuid()->toString(),
            'name' => 'Orphan',
        ], $this->runRow($owner), []);
    }

    public function test_create_form_report_step_requires_a_name(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $owner = User::factory()->create();
        $this->actingAs($owner);
        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        $this->expectException(RuntimeException::class);
        app(\App\Modules\Workflows\Steps\CreateFormReportStep::class)->run([
            'form_id' => $form->id,
            'name' => '   ',
        ], $this->runRow($owner), []);
    }

    public function test_step_factory_resolves_every_step_type(): void
    {
        $factory = app(WorkflowStepFactory::class);

        // A new WorkflowStepType case without a factory mapping must fail here,
        // not at run time (match throws UnhandledMatchError for unmapped cases).
        foreach (WorkflowStepType::cases() as $type) {
            $this->assertSame($type, $factory->make($type)->type());
        }
    }

    public function test_task_dto_shape_is_pinned_for_create_task_step(): void
    {
        $params = array_map(
            fn (ReflectionParameter $parameter) => $parameter->getName(),
            (new ReflectionMethod(TaskDTO::class, '__construct'))->getParameters()
        );

        // CreateTaskStep builds a TaskDTO by positional/named args through
        // TaskService::create(). If this assertion fails, TaskDTO gained/renamed a field —
        // update CreateTaskStep FIRST, or the step will build a malformed DTO.
        $this->assertSame([
            'title',
            'description',
            'priority',
            'deadline',
            'assigneeType',
            'assigneeId',
            'labels',
            'attachments',
            'form_id',
            'approval_pipeline_id',
        ], $params);
    }
}
