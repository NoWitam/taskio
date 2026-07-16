<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskFormSubmissionApprovalTest extends TestCase
{
    use RefreshDatabase;

    /** A task's form submission starts as a draft (submittable = Task, not approved). */
    private function draftSubmission(User $user, Form $form, Task $task): FormSubmission
    {
        return FormSubmission::create([
            'form_id' => $form->id,
            'submittable_type' => $task->getMorphClass(),
            'submittable_id' => $task->id,
            'data' => ['field' => 'value'],
            'approved_at' => null,
            'creator_id' => $user->id,
        ]);
    }

    public function test_moving_task_to_done_manually_approves_its_form_submission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->enabled()->create();
        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::IN_TEST,
            'approval_pipeline_id' => null,
            'form_id' => $form->id,
        ]);
        $submission = $this->draftSubmission($user, $form, $task);
        $this->assertFalse($submission->isApproved());

        // creator + no pipeline → in_test -> done is allowed.
        $this->patchJson("/api/tasks/{$task->id}/status/done")->assertOk();

        $this->assertTrue($submission->fresh()->isApproved());
        $this->assertNotNull($submission->fresh()->approved_at);
    }

    public function test_completing_approval_pipeline_approves_form_submission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $pipeline = ApprovalPipeline::factory()->withStages(1)->create();
        $form = Form::factory()->enabled()->create();
        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::IN_TEST,
            'approval_pipeline_id' => $pipeline->id,
            'form_id' => $form->id,
        ]);
        $submission = $this->draftSubmission($user, $form, $task);

        // A task with a pipeline reaches DONE only through approval completion.
        $service = app(ApprovalService::class);
        $process = $service->startProcess($task, $user);
        $service->decide($process, ApprovalProcessStatus::Approved);

        $this->assertEquals(TaskStatus::DONE, $task->fresh()->status);
        $this->assertTrue($submission->fresh()->isApproved());
    }

    public function test_already_approved_submission_keeps_its_original_approval(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->enabled()->create();
        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::IN_TEST,
            'approval_pipeline_id' => null,
            'form_id' => $form->id,
        ]);
        $submission = $this->draftSubmission($user, $form, $task);
        $submission->approve();
        $approvedAt = $submission->fresh()->approved_at;

        $this->patchJson("/api/tasks/{$task->id}/status/done")->assertOk();

        // approve() is idempotent: the timestamp must not be overwritten.
        $this->assertEquals($approvedAt, $submission->fresh()->approved_at);
    }

    public function test_moving_task_to_non_done_status_does_not_approve_submission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Form::factory()->enabled()->create();
        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::TO_DO,
            'approval_pipeline_id' => null,
            'form_id' => $form->id,
        ]);
        $submission = $this->draftSubmission($user, $form, $task);

        $this->patchJson("/api/tasks/{$task->id}/status/in_progress")->assertOk();

        $this->assertFalse($submission->fresh()->isApproved());
    }

    public function test_moving_task_without_submission_to_done_succeeds(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::IN_TEST,
            'approval_pipeline_id' => null,
        ]);

        $this->patchJson("/api/tasks/{$task->id}/status/done")->assertOk();

        $this->assertEquals(TaskStatus::DONE, $task->fresh()->status);
    }
}
