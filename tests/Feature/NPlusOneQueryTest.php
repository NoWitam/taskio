<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Changelog\Managers\ChangelogManager;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the list/queue endpoints against N+1 regressions. The signature of an
 * N+1 is that the query count GROWS with the number of rows; each test therefore
 * measures the same endpoint with 1 row and with several rows and asserts the
 * count is identical (constant work regardless of row count).
 */
class NPlusOneQueryTest extends TestCase
{
    use RefreshDatabase;

    /** Run $callback and return how many DB queries it issued. */
    private function countQueries(callable $callback): int
    {
        // The ChangelogManager singleton buffers writes and flushes them in its
        // destructor; persist anything buffered by setup now so those deferred
        // INSERTs don't leak into the measured (read-only) window.
        app(ChangelogManager::class)->flush();

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    /** A pipeline with a single user-approver stage (approver defaults to a new user). */
    private function userStagePipeline(?User $approver = null): ApprovalPipeline
    {
        $pipeline = ApprovalPipeline::factory()->create();
        $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => ($approver ?? User::factory()->create())->id,
            'order' => 1,
        ]);

        return $pipeline;
    }

    /** Create an in_test task on $pipeline and kick off its (pending) approval process. */
    private function startApproval(User $owner, ApprovalPipeline $pipeline): Task
    {
        $task = Task::factory()->create([
            'creator_id' => $owner->id,
            'assigned_id' => $owner->id,
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        app(ApprovalService::class)->startProcess($task, $owner);

        return $task;
    }

    public function test_tasks_list_is_in_approval_does_not_query_per_row(): void
    {
        $user = User::factory()->create();
        $pipeline = $this->userStagePipeline();
        $this->actingAs($user);

        $this->startApproval($user, $pipeline);
        $oneRow = $this->countQueries(
            fn () => $this->getJson('/api/tasks?status=in_test')->assertOk()->assertJsonCount(1, 'data')
        );

        $this->startApproval($user, $pipeline);
        $this->startApproval($user, $pipeline);
        $this->startApproval($user, $pipeline);
        $manyRows = $this->countQueries(
            fn () => $this->getJson('/api/tasks?status=in_test')->assertOk()->assertJsonCount(4, 'data')
        );

        $this->assertSame(
            $oneRow,
            $manyRows,
            "Tasks list issued extra queries as rows grew — is_in_approval N+1 (1 row: {$oneRow}, 4 rows: {$manyRows})."
        );
    }

    public function test_approval_pipelines_list_can_be_edited_does_not_query_per_row(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Each pipeline carries a pending process so can_be_edited / can_be_deleted
        // (hasActiveProcesses) actually exercises the existence check.
        $this->startApproval($user, $this->userStagePipeline());
        $oneRow = $this->countQueries(
            fn () => $this->getJson('/api/approval-pipelines')->assertOk()->assertJsonCount(1, 'data')
        );

        $this->startApproval($user, $this->userStagePipeline());
        $this->startApproval($user, $this->userStagePipeline());
        $manyRows = $this->countQueries(
            fn () => $this->getJson('/api/approval-pipelines')->assertOk()->assertJsonCount(3, 'data')
        );

        $this->assertSame(
            $oneRow,
            $manyRows,
            "Pipelines list issued extra queries as rows grew — can_be_edited/can_be_deleted N+1 (1 row: {$oneRow}, 3 rows: {$manyRows})."
        );
    }

    public function test_approval_queue_does_not_query_per_row(): void
    {
        $approver = User::factory()->create();
        $pipeline = $this->userStagePipeline($approver);
        $this->actingAs($approver);

        // Processes are routed to $approver because they are the stage approver.
        $this->startApproval(User::factory()->create(), $pipeline);
        $oneItem = $this->countQueries(
            fn () => $this->getJson('/api/approvals/queue')->assertOk()->assertJsonCount(1, 'data')
        );

        $this->startApproval(User::factory()->create(), $pipeline);
        $this->startApproval(User::factory()->create(), $pipeline);
        $manyItems = $this->countQueries(
            fn () => $this->getJson('/api/approvals/queue')->assertOk()->assertJsonCount(3, 'data')
        );

        $this->assertSame(
            $oneItem,
            $manyItems,
            "Approval queue issued extra queries as items grew — toApprovalQueueItem() loadMissing N+1 (1 item: {$oneItem}, 3 items: {$manyItems})."
        );
    }

    public function test_task_create_response_does_not_lazy_load_relations(): void
    {
        $user = User::factory()->create();

        // With the create response eager-loading DETAIL_RELATIONS, serializing the
        // TaskResource must not trip a lazy-load (which would be an N+1 at scale).
        Model::preventLazyLoading(true);

        try {
            $this->actingAs($user)
                ->postJson('/api/tasks', [
                    'title' => 'New task',
                    'priority' => 'medium',
                    'assigned_id' => $user->id,
                ])
                ->assertCreated()
                ->assertJsonPath('data.assigned.id', $user->id)
                ->assertJsonPath('data.is_in_approval', false);
        } finally {
            Model::preventLazyLoading(false);
        }
    }
}
