<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskApprovalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_with_pipeline_cannot_be_manually_set_to_done_from_in_test(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->create();
        $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_PROGRESS,
        ]);

        // Move to in_test should start approval process
        $response = $this->actingAs($user)
            ->patchJson("/api/tasks/{$task->id}/status/in_test");

        $response->assertOk();
        $task->refresh();
        $this->assertEquals(TaskStatus::IN_TEST, $task->status);
        $this->assertTrue($task->isInApproval());
    }

    public function test_task_in_approval_cannot_be_updated(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->create();
        $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        app(ApprovalService::class)->startProcess($task, $user);

        $response = $this->actingAs($user)
            ->putJson("/api/tasks/{$task->id}", [
                'title' => 'Changed',
                'priority' => 'high',
                'assigned_id' => $user->id,
            ]);

        $response->assertForbidden();
    }

    public function test_task_status_cannot_change_during_approval(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->create();
        $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        app(ApprovalService::class)->startProcess($task, $user);

        $response = $this->actingAs($user)
            ->patchJson("/api/tasks/{$task->id}/status/to_do");

        $response->assertForbidden();
    }

    public function test_can_create_task_with_approval_pipeline(): void
    {
        $user = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->withStages(1)->create();

        $response = $this->actingAs($user)
            ->postJson('/api/tasks', [
                'title' => 'Task with approval',
                'priority' => 'medium',
                'assigned_id' => $user->id,
                'approval_pipeline_id' => $pipeline->id,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.approval_pipeline_id', $pipeline->id);
    }

    public function test_task_without_pipeline_can_change_status_normally(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::IN_TEST,
            'approval_pipeline_id' => null,
        ]);

        $response = $this->actingAs($user)
            ->patchJson("/api/tasks/{$task->id}/status/done");

        $response->assertOk();
        $this->assertEquals(TaskStatus::DONE, $task->fresh()->status);
    }

    public function test_show_returns_wrapped_approval_pipeline_and_pending_process(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();

        $this->actingAs($user);

        $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $user->id]);
        $stage = $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        app(ApprovalService::class)->startProcess($task, $user);

        $response = $this->getJson("/api/tasks/{$task->id}");

        $response->assertOk()
            // pipeline wrapped in ApprovalPipelineResource with its stages
            ->assertJsonPath('data.approval_pipeline.id', $pipeline->id)
            ->assertJsonPath('data.approval_pipeline.stages.0.id', $stage->id)
            ->assertJsonPath('data.approval_pipeline.stages.0.approver.id', $approver->id)
            // scalar contract fields untouched
            ->assertJsonPath('data.approval_pipeline_id', $pipeline->id)
            ->assertJsonPath('data.is_in_approval', true)
            // pending process wrapped in ApprovalProcessResource
            ->assertJsonPath('data.pending_approval_process.status', 'pending')
            ->assertJsonPath('data.pending_approval_process.stage.id', $stage->id);

        $process = $response->json('data.pending_approval_process');
        $this->assertArrayHasKey('run_id', $process);
        $this->assertArrayHasKey('approver', $process);
        $this->assertArrayHasKey('pipeline', $process);
        // No raw DB columns leak through the resource wrapper.
        $this->assertArrayNotHasKey('approval_pipeline_id', $process);
        $this->assertArrayNotHasKey('approvable_type', $process);
    }

    public function test_show_omits_approval_relations_for_task_without_pipeline(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'approval_pipeline_id' => null,
            'status' => TaskStatus::TO_DO,
        ]);

        $response = $this->getJson("/api/tasks/{$task->id}");

        $response->assertOk()
            ->assertJsonPath('data.approval_pipeline_id', null)
            ->assertJsonPath('data.is_in_approval', false)
            // Relations loaded but empty: no MissingValue leakage, just null.
            ->assertJsonPath('data.approval_pipeline', null)
            ->assertJsonPath('data.pending_approval_process', null);
    }

    public function test_available_status_transitions_for_assignee_on_in_progress_task_without_pipeline(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::IN_PROGRESS,
            'approval_pipeline_id' => null,
        ]);

        $transitions = $this->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->json('data.available_status_transitions');

        // New tree: an assignee on in_progress may only go to to_do or in_test.
        // in_progress -> done is no longer allowed (must route via in_test).
        sort($transitions);
        $this->assertSame(['in_test', 'to_do'], $transitions);
        $this->assertNotContains('done', $transitions);
        $this->assertNotContains('trash', $transitions);
        // Cannot transition to the status it already has.
        $this->assertNotContains('in_progress', $transitions);
    }

    public function test_available_status_transitions_excludes_done_for_in_test_task_with_pipeline(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $pipeline = ApprovalPipeline::factory()->withStages(1)->create();

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::IN_TEST,
            'approval_pipeline_id' => $pipeline->id,
        ]);

        $transitions = $this->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->json('data.available_status_transitions');

        // in_test + pipeline must go to done through approval, not directly.
        // Both creator-only in_test transitions require NO pipeline, so neither is offered.
        $this->assertNotContains('done', $transitions);
        $this->assertNotContains('to_do', $transitions);
    }

    public function test_available_status_transitions_empty_while_in_approval(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $pipeline = ApprovalPipeline::factory()->withStages(1)->create();

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::IN_TEST,
            'approval_pipeline_id' => $pipeline->id,
        ]);

        app(ApprovalService::class)->startProcess($task, $user);

        $transitions = $this->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->json('data.available_status_transitions');

        $this->assertSame([], $transitions);
    }

    public function test_available_status_transitions_excludes_assignee_gated_for_non_assignee(): void
    {
        $creator = User::factory()->create();
        $assignee = User::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($creator);

        $task = Task::factory()->create([
            'creator_id' => $creator->id,
            'assigned_id' => $assignee->id,
            'status' => TaskStatus::IN_PROGRESS,
            'approval_pipeline_id' => null,
        ]);

        $transitions = $this->actingAs($outsider)
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->json('data.available_status_transitions');

        // Assignee-gated transitions must not be offered to an unrelated user.
        $this->assertNotContains('to_do', $transitions);
        $this->assertNotContains('in_test', $transitions);
        // Creator-only archive is not available either (outsider is not the creator).
        $this->assertNotContains('archive', $transitions);
    }

    public function test_capability_flags_reflect_policy(): void
    {
        $creator = User::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($creator);

        $task = Task::factory()->create([
            'creator_id' => $creator->id,
            'assigned_id' => $creator->id,
            'status' => TaskStatus::IN_PROGRESS,
            'approval_pipeline_id' => null,
        ]);

        $this->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.can_update', true)
            ->assertJsonPath('data.can_delete', true)
            ->assertJsonPath('data.can_restore', true)
            ->assertJsonPath('data.can_force_delete', true);

        $this->actingAs($outsider)
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.can_update', false)
            ->assertJsonPath('data.can_delete', false)
            ->assertJsonPath('data.can_restore', false)
            ->assertJsonPath('data.can_force_delete', false);
    }

    public function test_can_update_is_false_while_in_approval(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $pipeline = ApprovalPipeline::factory()->withStages(1)->create();

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::IN_TEST,
            'approval_pipeline_id' => $pipeline->id,
        ]);

        app(ApprovalService::class)->startProcess($task, $user);

        $this->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.can_update', false);
    }

    public function test_approval_run_id_returns_pending_run(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $pipeline = ApprovalPipeline::factory()->withStages(1)->create();

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::IN_TEST,
            'approval_pipeline_id' => $pipeline->id,
        ]);

        $process = app(ApprovalService::class)->startProcess($task, $user);

        $this->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.approval_run_id', $process->run_id);
    }

    public function test_approval_run_id_returns_latest_run_after_decision(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $pipeline = ApprovalPipeline::factory()->withStages(1)->create();

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::IN_TEST,
            'approval_pipeline_id' => $pipeline->id,
        ]);

        $service = app(ApprovalService::class);
        $process = $service->startProcess($task, $user);
        $runId = $process->run_id;

        // Fully decide the single-stage run: no pending process remains.
        $service->decide($process, ApprovalProcessStatus::Approved);

        $this->assertFalse($task->fresh()->isInApproval());

        $this->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.pending_approval_process', null)
            ->assertJsonPath('data.approval_run_id', $runId);
    }

    public function test_approval_run_id_is_null_without_pipeline(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'status' => TaskStatus::TO_DO,
            'approval_pipeline_id' => null,
        ]);

        $this->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.approval_run_id', null);
    }

    // -- Transition tree: ASSIGNEE-gated edges --

    public function test_assignee_can_move_to_do_to_in_progress(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($assignee);

        $task = $this->makeTask($creator, $assignee, TaskStatus::TO_DO);

        $this->actingAs($assignee)
            ->patchJson("/api/tasks/{$task->id}/status/in_progress")
            ->assertOk();

        $this->assertEquals(TaskStatus::IN_PROGRESS, $task->fresh()->status);
    }

    public function test_assignee_can_move_in_progress_to_to_do(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($assignee);

        $task = $this->makeTask($creator, $assignee, TaskStatus::IN_PROGRESS);

        $this->actingAs($assignee)
            ->patchJson("/api/tasks/{$task->id}/status/to_do")
            ->assertOk();

        $this->assertEquals(TaskStatus::TO_DO, $task->fresh()->status);
    }

    public function test_assignee_can_move_in_progress_to_in_test(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($assignee);

        $task = $this->makeTask($creator, $assignee, TaskStatus::IN_PROGRESS);

        $this->actingAs($assignee)
            ->patchJson("/api/tasks/{$task->id}/status/in_test")
            ->assertOk();

        $this->assertEquals(TaskStatus::IN_TEST, $task->fresh()->status);
    }

    public function test_assignee_can_move_done_to_in_progress(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($assignee);

        $task = $this->makeTask($creator, $assignee, TaskStatus::DONE);

        $this->actingAs($assignee)
            ->patchJson("/api/tasks/{$task->id}/status/in_progress")
            ->assertOk();

        $this->assertEquals(TaskStatus::IN_PROGRESS, $task->fresh()->status);
    }

    public function test_assignee_cannot_move_in_progress_directly_to_done(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($assignee);

        $task = $this->makeTask($creator, $assignee, TaskStatus::IN_PROGRESS);

        $this->actingAs($assignee)
            ->patchJson("/api/tasks/{$task->id}/status/done")
            ->assertForbidden();

        $this->assertEquals(TaskStatus::IN_PROGRESS, $task->fresh()->status);
    }

    public function test_assignee_cannot_move_in_test_to_done(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($assignee);

        // No pipeline: in_test -> done is creator-only, so a non-creator assignee is blocked.
        $task = $this->makeTask($creator, $assignee, TaskStatus::IN_TEST);

        $this->actingAs($assignee)
            ->patchJson("/api/tasks/{$task->id}/status/done")
            ->assertForbidden();

        $this->assertEquals(TaskStatus::IN_TEST, $task->fresh()->status);
    }

    // -- Transition tree: CREATOR-gated edges --

    public function test_creator_without_pipeline_can_move_in_test_to_to_do(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($creator);

        $task = $this->makeTask($creator, $assignee, TaskStatus::IN_TEST);

        $this->actingAs($creator)
            ->patchJson("/api/tasks/{$task->id}/status/to_do")
            ->assertOk();

        $this->assertEquals(TaskStatus::TO_DO, $task->fresh()->status);
    }

    public function test_creator_without_pipeline_can_move_in_test_to_done(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($creator);

        $task = $this->makeTask($creator, $assignee, TaskStatus::IN_TEST);

        $this->actingAs($creator)
            ->patchJson("/api/tasks/{$task->id}/status/done")
            ->assertOk();

        $this->assertEquals(TaskStatus::DONE, $task->fresh()->status);
    }

    public function test_creator_with_pipeline_cannot_move_in_test_to_to_do(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($creator);

        $pipeline = ApprovalPipeline::factory()->withStages(1)->create();
        $task = $this->makeTask($creator, $assignee, TaskStatus::IN_TEST, $pipeline->id);

        $this->actingAs($creator)
            ->patchJson("/api/tasks/{$task->id}/status/to_do")
            ->assertForbidden();

        $this->assertEquals(TaskStatus::IN_TEST, $task->fresh()->status);
    }

    public function test_creator_with_pipeline_cannot_move_in_test_to_done(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($creator);

        $pipeline = ApprovalPipeline::factory()->withStages(1)->create();
        $task = $this->makeTask($creator, $assignee, TaskStatus::IN_TEST, $pipeline->id);

        $this->actingAs($creator)
            ->patchJson("/api/tasks/{$task->id}/status/done")
            ->assertForbidden();

        $this->assertEquals(TaskStatus::IN_TEST, $task->fresh()->status);
    }

    public function test_creator_can_move_done_to_to_do(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($creator);

        $task = $this->makeTask($creator, $assignee, TaskStatus::DONE);

        $this->actingAs($creator)
            ->patchJson("/api/tasks/{$task->id}/status/to_do")
            ->assertOk();

        $this->assertEquals(TaskStatus::TO_DO, $task->fresh()->status);
    }

    public function test_creator_cannot_move_done_to_in_progress(): void
    {
        // done -> in_progress is assignee-only; a non-assignee creator is blocked.
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($creator);

        $task = $this->makeTask($creator, $assignee, TaskStatus::DONE);

        $this->actingAs($creator)
            ->patchJson("/api/tasks/{$task->id}/status/in_progress")
            ->assertForbidden();

        $this->assertEquals(TaskStatus::DONE, $task->fresh()->status);
    }

    // -- Outsider: every transition forbidden --

    public function test_outsider_cannot_perform_any_transition(): void
    {
        [$creator, $assignee, $outsider] = [
            User::factory()->create(),
            User::factory()->create(),
            User::factory()->create(),
        ];
        $this->actingAs($creator);

        $task = $this->makeTask($creator, $assignee, TaskStatus::IN_PROGRESS);

        foreach (['to_do', 'in_test', 'done', 'archive'] as $target) {
            $this->actingAs($outsider)
                ->patchJson("/api/tasks/{$task->id}/status/{$target}")
                ->assertForbidden();
        }

        $this->assertEquals(TaskStatus::IN_PROGRESS, $task->fresh()->status);
    }

    // -- In-approval guard blocks every manual transition --

    public function test_in_approval_task_blocks_every_transition(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $pipeline = ApprovalPipeline::factory()->withStages(1)->create();
        $task = $this->makeTask($user, $user, TaskStatus::IN_TEST, $pipeline->id);

        app(ApprovalService::class)->startProcess($task, $user);
        $this->assertTrue($task->fresh()->isInApproval());

        foreach (['to_do', 'in_progress', 'done', 'archive'] as $target) {
            $this->actingAs($user)
                ->patchJson("/api/tasks/{$task->id}/status/{$target}")
                ->assertForbidden();
        }
    }

    // -- Archive / trash lifecycle (separate from the tree) --

    public function test_creator_can_archive_a_done_task(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($creator);

        $task = $this->makeTask($creator, $assignee, TaskStatus::DONE);

        $this->actingAs($creator)
            ->patchJson("/api/tasks/{$task->id}/status/archive")
            ->assertOk();
    }

    public function test_non_creator_cannot_archive_a_done_task(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($creator);

        $task = $this->makeTask($creator, $assignee, TaskStatus::DONE);

        $this->actingAs($assignee)
            ->patchJson("/api/tasks/{$task->id}/status/archive")
            ->assertForbidden();
    }

    public function test_only_creator_can_trash_via_delete_endpoint(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($creator);

        $task = $this->makeTask($creator, $assignee, TaskStatus::TO_DO);

        $this->actingAs($assignee)
            ->deleteJson("/api/tasks/{$task->id}")
            ->assertForbidden();

        $this->actingAs($creator)
            ->deleteJson("/api/tasks/{$task->id}")
            ->assertOk();

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    // -- Resource reflects the tree --

    public function test_available_transitions_for_creator_on_no_pipeline_in_test_includes_to_do_and_done(): void
    {
        [$creator, $assignee] = [User::factory()->create(), User::factory()->create()];
        $this->actingAs($creator);

        $task = $this->makeTask($creator, $assignee, TaskStatus::IN_TEST);

        $transitions = $this->actingAs($creator)
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->json('data.available_status_transitions');

        $this->assertContains('to_do', $transitions);
        $this->assertContains('done', $transitions);
    }

    /**
     * Build a task with creator and assignee set EXPLICITLY (the factory would
     * otherwise create distinct users for each), so creator != assignee cases are real.
     */
    private function makeTask(User $creator, User $assignee, TaskStatus $status, ?string $pipelineId = null): Task
    {
        return Task::factory()->create([
            'creator_id' => $creator->id,
            'assigned_id' => $assignee->id,
            'status' => $status,
            'approval_pipeline_id' => $pipelineId,
        ]);
    }
}
