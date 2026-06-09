<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalDecisionFlowTest extends TestCase
{
    use RefreshDatabase;

    private function createPipelineWithUserStages(User $approver1, User $approver2): ApprovalPipeline
    {
        $pipeline = ApprovalPipeline::factory()->create();
        $pipeline->stages()->create([
            'name' => 'Stage 1',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver1->id,
            'order' => 1,
        ]);
        $pipeline->stages()->create([
            'name' => 'Stage 2',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver2->id,
            'order' => 2,
        ]);

        return $pipeline;
    }

    public function test_approver_can_approve_pending_process(): void
    {
        $creator = User::factory()->create();
        $approver1 = User::factory()->create();
        $approver2 = User::factory()->create();
        $pipeline = $this->createPipelineWithUserStages($approver1, $approver2);

        $task = Task::factory()->create([
            'creator_id' => $creator->id,
            'assigned_id' => $creator->id,
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $service = app(ApprovalService::class);
        $process = $service->startProcess($task, $creator);

        $response = $this->actingAs($approver1)
            ->postJson("/api/approvals/processes/{$process->id}/decide", [
                'decision' => 'approved',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Should advance to stage 2
        $nextProcess = ApprovalProcess::where('run_id', $process->run_id)
            ->where('id', '!=', $process->id)
            ->first();

        $this->assertNotNull($nextProcess);
        $this->assertEquals(ApprovalProcessStatus::Pending, $nextProcess->status);
        $this->assertEquals($approver2->id, $nextProcess->approver_id);
    }

    public function test_approver_can_reject_pending_process(): void
    {
        $creator = User::factory()->create();
        $approver = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->create();
        $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        $task = Task::factory()->create([
            'creator_id' => $creator->id,
            'assigned_id' => $creator->id,
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $service = app(ApprovalService::class);
        $process = $service->startProcess($task, $creator);

        $response = $this->actingAs($approver)
            ->postJson("/api/approvals/processes/{$process->id}/decide", [
                'decision' => 'rejected',
                'note' => 'Needs more work',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $task->refresh();
        $this->assertEquals(TaskStatus::TO_DO, $task->status);
    }

    public function test_reject_requires_note(): void
    {
        $approver = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->create();
        $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        $task = Task::factory()->create([
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $service = app(ApprovalService::class);
        $process = $service->startProcess($task, $task->creator);

        $response = $this->actingAs($approver)
            ->postJson("/api/approvals/processes/{$process->id}/decide", [
                'decision' => 'rejected',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['note']);
    }

    public function test_non_approver_cannot_decide(): void
    {
        $approver = User::factory()->create();
        $other = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->create();
        $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        $task = Task::factory()->create([
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $service = app(ApprovalService::class);
        $process = $service->startProcess($task, $task->creator);

        $response = $this->actingAs($other)
            ->postJson("/api/approvals/processes/{$process->id}/decide", [
                'decision' => 'approved',
            ]);

        $response->assertForbidden();
    }

    public function test_cannot_decide_on_already_decided_process(): void
    {
        $approver = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->create();
        $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        $task = Task::factory()->create([
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $service = app(ApprovalService::class);
        $process = $service->startProcess($task, $task->creator);

        // First decision
        $this->actingAs($approver)
            ->postJson("/api/approvals/processes/{$process->id}/decide", [
                'decision' => 'approved',
            ]);

        // Second decision on same process
        $response = $this->actingAs($approver)
            ->postJson("/api/approvals/processes/{$process->id}/decide", [
                'decision' => 'approved',
            ]);

        $response->assertForbidden();
    }

    public function test_full_pipeline_completion_marks_task_done(): void
    {
        $creator = User::factory()->create();
        $approver = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->create();
        $pipeline->stages()->create([
            'name' => 'Final Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        $task = Task::factory()->create([
            'creator_id' => $creator->id,
            'assigned_id' => $creator->id,
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $service = app(ApprovalService::class);
        $process = $service->startProcess($task, $creator);

        $this->actingAs($approver)
            ->postJson("/api/approvals/processes/{$process->id}/decide", [
                'decision' => 'approved',
            ])
            ->assertOk();

        $task->refresh();
        $this->assertEquals(TaskStatus::DONE, $task->status);
    }

    public function test_queue_returns_only_pending_for_user(): void
    {
        $approver = User::factory()->create();
        $other = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->create();
        $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        $task = Task::factory()->create([
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $service = app(ApprovalService::class);
        $service->startProcess($task, $task->creator);

        // Approver sees it
        $response = $this->actingAs($approver)
            ->getJson('/api/approvals/queue');
        $response->assertOk()
            ->assertJsonCount(1, 'data');

        // Other user doesn't
        $response = $this->actingAs($other)
            ->getJson('/api/approvals/queue');
        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_queue_count_returns_correct_number(): void
    {
        $approver = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->create();
        $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        foreach (range(1, 3) as $_) {
            $task = Task::factory()->create([
                'approval_pipeline_id' => $pipeline->id,
                'status' => TaskStatus::IN_TEST,
            ]);
            app(ApprovalService::class)->startProcess($task, $task->creator);
        }

        $response = $this->actingAs($approver)
            ->getJson('/api/approvals/queue/count');

        $response->assertOk()
            ->assertJsonPath('count', 3);
    }
}
