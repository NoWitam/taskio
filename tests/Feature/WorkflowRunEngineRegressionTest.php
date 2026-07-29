<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Jobs\CreateFormReport;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormReport;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowRunStepStatus;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowRunManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * THE REGRESSION PIN for the suspend/resume engine (BE-0).
 *
 * The engine changed the run loop that EVERY existing workflow uses. The single most important
 * property of that change is that a workflow WITHOUT a suspending step must behave exactly as it did
 * before — same state transitions, same step rows, same context, and the three new waiting columns
 * never written. This test is the guard on that property, asserted END TO END over a real two-step
 * workflow (create_task -> create_form_report), not a mocked loop.
 *
 * If this test fails, the suspend/resume work has leaked into the ordinary path.
 */
class WorkflowRunEngineRegressionTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    public function test_a_workflow_without_a_suspending_step_runs_identically(): void
    {
        // Fake ONLY the AI report job (as the pre-existing end-to-end chain test does) so the run
        // itself still executes synchronously and no provider is called.
        Queue::fake([CreateFormReport::class]);

        $owner = User::factory()->create();
        $this->actingAs($owner);

        $form = Form::factory()->enabled()->create(['creator_id' => $owner->id]);

        $workflow = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'make_task', 'config' => [
                    'title' => 'Regression task',
                ]],
                ['type' => WorkflowStepType::CREATE_FORM_REPORT->value, 'key' => 'make_report', 'config' => [
                    'form_id' => $form->id,
                    'name' => 'Report for {{steps.make_task.title}}',
                    'sources' => ['form'],
                ]],
            ],
        ]);

        // MID-RUN OBSERVATION: the task created by step 1 lets us read the run row from INSIDE the
        // loop, which is the only way to assert the intermediate `running` state under the sync queue.
        $midRun = null;
        Event::listen('eloquent.created: ' . Task::class, function () use (&$midRun): void {
            $midRun = WorkflowRun::query()->latest('created_at')->first();
        });

        $run = app(WorkflowRunManager::class)->start($workflow, WorkflowRunOrigin::EVENT, ['source' => 'regression']);
        $run->refresh();

        // ---- STATE TRANSITIONS: pending (at creation) -> running (mid-loop) -> completed. --------
        $this->assertNotNull($midRun, 'The run should have been observable while step 1 executed.');
        $this->assertSame(WorkflowRunState::RUNNING, $midRun->state);
        $this->assertNotNull($midRun->started_at);
        $this->assertNull($midRun->finished_at);

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state);
        $this->assertNull($run->error);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        // ---- STEP ROWS: count / order / status / payload. ----------------------------------------
        $steps = $run->steps()->get();
        $this->assertCount(2, $steps);

        $this->assertSame(0, $steps[0]->position);
        $this->assertSame('make_task', $steps[0]->key);
        $this->assertSame(WorkflowStepType::CREATE_TASK, $steps[0]->type);
        $this->assertSame(WorkflowRunStepStatus::SUCCEEDED, $steps[0]->status);
        $this->assertNull($steps[0]->error);
        $this->assertSame(['task_id', 'title'], array_keys($steps[0]->payload));
        $this->assertSame('Regression task', $steps[0]->payload['title']);

        $report = FormReport::query()->firstOrFail();

        $this->assertSame(1, $steps[1]->position);
        $this->assertSame('make_report', $steps[1]->key);
        $this->assertSame(WorkflowStepType::CREATE_FORM_REPORT, $steps[1]->type);
        $this->assertSame(WorkflowRunStepStatus::SUCCEEDED, $steps[1]->status);
        $this->assertNull($steps[1]->error);
        $this->assertSame(['report_id', 'report_name', 'form_id'], array_keys($steps[1]->payload));
        $this->assertSame($report->id, $steps[1]->payload['report_id']);

        // Step 2 resolved {{steps.make_task.title}} from step 1's output — the chaining still works.
        $this->assertSame('Report for Regression task', $steps[1]->payload['report_name']);

        // ---- FINAL CONTEXT: exactly the three roots, with both step outputs merged. --------------
        $this->assertSame(['trigger', 'steps', 'globals'], array_keys($run->context));
        $this->assertSame(['source' => 'regression'], $run->context['trigger']);
        $this->assertSame(['make_task', 'make_report'], array_keys($run->context['steps']));
        $this->assertSame($steps[0]->payload, $run->context['steps']['make_task']);
        $this->assertSame($steps[1]->payload, $run->context['steps']['make_report']);

        // ---- THE WAITING COLUMNS WERE NEVER WRITTEN — mid-run and at the end. --------------------
        $this->assertNull($midRun->waiting_on);
        $this->assertNull($midRun->waiting_key);
        $this->assertNull($midRun->waiting_since);

        $this->assertNull($run->waiting_on);
        $this->assertNull($run->waiting_key);
        $this->assertNull($run->waiting_since);
        $this->assertDatabaseHas('workflow_runs', [
            'id' => $run->id,
            'state' => WorkflowRunState::COMPLETED->value,
            'waiting_on' => null,
            'waiting_key' => null,
            'waiting_since' => null,
        ]);

        // The real side effects still happened, and the report job was queued as before.
        $this->assertDatabaseHas('tasks', ['id' => $steps[0]->payload['task_id'], 'title' => 'Regression task']);
        Queue::assertPushed(CreateFormReport::class);
    }

    /**
     * The other half of "identically": a FAILING ordinary step still fails the run the old way —
     * one step row, the error recorded, later steps skipped, and no waiting column touched (a
     * failure must never be mistaken for a suspension).
     */
    public function test_a_failing_ordinary_step_still_fails_the_run_without_touching_the_waiting_columns(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $workflow = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'bad', 'config' => []],
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'never', 'config' => ['title' => 'Should not run']],
            ],
        ]);

        $run = app(WorkflowRunManager::class)->start($workflow, WorkflowRunOrigin::EVENT, []);
        $run->refresh();

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertNotNull($run->error);
        $this->assertCount(1, $run->steps()->get());
        $this->assertSame(WorkflowRunStepStatus::FAILED, $run->steps()->first()->status);
        $this->assertDatabaseMissing('tasks', ['title' => 'Should not run']);

        $this->assertNull($run->waiting_on);
        $this->assertNull($run->waiting_key);
        $this->assertNull($run->waiting_since);
    }
}
