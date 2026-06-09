<?php

namespace Tests\Feature;

use App\Models\User;
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
}
