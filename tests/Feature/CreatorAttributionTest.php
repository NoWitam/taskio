<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 of the polymorphic `creator` refactor (App\Traits\HasCreator + CreatorResource):
 * a record can be authored by a human User, an engine WorkflowRun, or a Bot. These feature
 * tests pin the OBSERVABLE behaviour a run-created ("system") task must exhibit end to end:
 *   - it serialises as a workflow_run creator (never a user shape) — {@see CreatorResource};
 *   - it is excluded from the triggering user's "created by me" list;
 *   - being owned by nobody, only the active workspace's OWNER may mutate it (fallback), while
 *     a plain member (even the run's author) cannot;
 *   - a normal user-created task keeps full owner control; and
 *   - approval-completion never promotes a run creator into the assignee slot.
 */
class CreatorAttributionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a run-created ("system") Task. The Task fillable exposes `creator_id` but NOT
     * `creator_type`, and HasCreator defaults an explicitly-set creator_id to the 'user' morph
     * alias — so a deterministic workflow_run creator is written at the DB level after create,
     * exactly as a real engine run would have stamped it. Optionally pins the workspace_id so
     * the row resolves under an active-workspace route-model binding.
     *
     * @param  array<string, mixed>  $taskAttributes
     * @return array{0: Task, 1: WorkflowRun, 2: Workflow}
     */
    private function runCreatedTask(
        User $author,
        string $workflowName,
        array $taskAttributes = [],
        ?string $workspaceId = null,
    ): array {
        $workflow = Workflow::factory()->create([
            'name' => $workflowName,
            'creator_id' => $author->id,
        ]);
        $run = WorkflowRun::factory()->create(['workflow_id' => $workflow->id]);

        $task = Task::factory()->create($taskAttributes);

        $override = ['creator_type' => 'workflow_run', 'creator_id' => $run->id];
        if ($workspaceId !== null) {
            $override['workspace_id'] = $workspaceId;
        }

        DB::table('tasks')->where('id', $task->id)->update($override);
        $task->refresh();

        return [$task, $run, $workflow];
    }

    /**
     * @return array{0: User, 1: Workspace} [owner, active workspace with owner attached]
     */
    private function workspace(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($owner->id);

        return [$owner, $workspace];
    }

    // 1. A run-created task serialises a workflow_run creator, not a user.

    public function test_run_created_task_serializes_a_workflow_run_creator(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();

        [$task, $run] = $this->runCreatedTask($author, 'Nightly Cleanup', [
            'assignee_type' => 'user',
            'assignee_id' => $author->id,
        ]);

        $creator = $this->actingAs($viewer)
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->json('data.creator');

        $this->assertSame('workflow_run', $creator['type']);
        $this->assertSame('Nightly Cleanup', $creator['label']); // parent workflow name
        $this->assertSame($run->id, $creator['run_id']);

        // It must NOT be a user shape: the discriminator is not 'user' and there is no email key.
        $this->assertNotSame('user', $creator['type']);
        $this->assertArrayNotHasKey('email', $creator);
    }

    // 2. A run-created task is NOT in the triggering user's "created by me" list.

    public function test_run_created_task_is_excluded_from_the_authors_created_by_me_list(): void
    {
        $author = User::factory()->create();
        $stranger = User::factory()->create();

        // The author's own, genuinely user-created task.
        $ownTask = Task::factory()->create([
            'creator_id' => $author->id,
            'assignee_type' => 'user',
            'assignee_id' => $author->id,
        ]);

        // A run-created task whose assignee is NOT the author, so it cannot match on assignee.
        [$runTask] = $this->runCreatedTask($author, 'Nightly Cleanup', [
            'assignee_type' => 'user',
            'assignee_id' => $stranger->id,
        ]);

        $ids = collect(
            $this->actingAs($author)
                ->getJson('/api/tasks?user_id[]=' . $author->id)
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($ownTask->id, $ids);
        $this->assertNotContains($runTask->id, $ids);
    }

    // 3. A run-created system task: a plain member (even its author) cannot edit it, but the
    //    workspace owner may act on it via the null-creatorUser workspace-owner fallback.

    public function test_run_created_task_is_editable_only_by_the_workspace_owner_fallback(): void
    {
        [$owner, $workspace] = $this->workspace();

        // The run's author is a member but NOT the workspace owner, and — because the creator is
        // the run — NOT the record's owner either.
        $author = User::factory()->create();
        $workspace->users()->attach($author->id);

        $stranger = User::factory()->create();

        [$runTask] = $this->runCreatedTask($author, 'Nightly Cleanup', [
            'assignee_type' => 'user',
            'assignee_id' => $stranger->id, // not the author -> no assignee escape hatch
        ], workspaceId: $workspace->id);

        // authorize() runs before validation, so a forbidden update is 403 even with a bare body.
        $this->actingAs($author)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson("/api/tasks/{$runTask->id}", [])
            ->assertForbidden();

        // The workspace owner falls back to acting on the ownerless system record.
        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->deleteJson("/api/tasks/{$runTask->id}")
            ->assertSuccessful();

        $this->assertSoftDeleted('tasks', ['id' => $runTask->id]);
    }

    // 4. A normal user-created task: serialises a user creator and the creator keeps full control.

    public function test_user_created_task_serializes_a_user_creator_and_is_owner_mutable(): void
    {
        $creator = User::factory()->create();

        $task = Task::factory()->create([
            'creator_id' => $creator->id,
            'assignee_type' => 'user',
            'assignee_id' => $creator->id,
            'status' => TaskStatus::TO_DO,
            'approval_pipeline_id' => null,
        ]);

        $creatorPayload = $this->actingAs($creator)
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->json('data.creator');

        $this->assertSame('user', $creatorPayload['type']);
        $this->assertSame($creator->id, $creatorPayload['id']);
        $this->assertSame($creator->name, $creatorPayload['name']);
        $this->assertSame($creator->email, $creatorPayload['email']);

        // The owner may update...
        $this->actingAs($creator)
            ->putJson("/api/tasks/{$task->id}", [
                'title' => 'Updated by owner',
                'priority' => 'high',
                'assigned_id' => $creator->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated by owner');

        // ...and delete their own task.
        $this->actingAs($creator)
            ->deleteJson("/api/tasks/{$task->id}")
            ->assertOk();

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    // 5. Approval completion skips the creator->assignee reassignment when the creator is a run.

    public function test_approval_completion_never_promotes_a_run_creator_to_assignee(): void
    {
        $author = User::factory()->create();
        $assignee = User::factory()->create(); // neither the run nor the author
        $this->actingAs($author);

        // Run-created task assigned to a real user.
        [$runTask] = $this->runCreatedTask($author, 'Nightly Cleanup', [
            'assignee_type' => 'user',
            'assignee_id' => $assignee->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $runProcess = new ApprovalProcess(['context' => $runTask->getApprovalContext()]);
        $runTask->onApprovalCompleted($runProcess);
        $runTask->refresh();

        // Status advances to done, but the run creator must NEVER become the assignee: the
        // user assignee is left exactly as it was.
        $this->assertSame(TaskStatus::DONE, $runTask->status);
        $this->assertSame('user', $runTask->assignee_type);
        $this->assertSame($assignee->id, $runTask->assignee_id);

        // Contrast: for a user-created task, completion DOES return it to its human creator.
        $userTask = Task::factory()->create([
            'creator_id' => $author->id,
            'assignee_type' => 'user',
            'assignee_id' => $assignee->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $userProcess = new ApprovalProcess(['context' => $userTask->getApprovalContext()]);
        $userTask->onApprovalCompleted($userProcess);
        $userTask->refresh();

        $this->assertSame(TaskStatus::DONE, $userTask->status);
        $this->assertSame('user', $userTask->assignee_type);
        $this->assertSame($author->id, $userTask->assignee_id); // reassigned to the creator
    }

    // 6. Approval REJECTION mirrors completion: it must never promote a run creator into the
    //    assignee slot. All three reject branches are covered.

    public function test_approval_rejection_restores_the_snapshot_assignee_never_the_run(): void
    {
        $author = User::factory()->create();
        $original = User::factory()->create();   // the assignee snapshotted when approval started
        $current = User::factory()->create();    // a different, later assignee
        $this->actingAs($author);

        // Branch A — WITH a snapshot: the reject restores the snapshotted human, not the run
        // and not the current holder. Current assignee differs from the snapshot so the restore
        // is observable rather than a no-op.
        [$runTask] = $this->runCreatedTask($author, 'Nightly Cleanup', [
            'assignee_type' => 'user',
            'assignee_id' => $current->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $snapshot = new ApprovalProcess(['context' => [
            'original_assignee_type' => 'user',
            'original_assignee_id' => $original->id,
            'original_assigned_id' => $original->id,
        ]]);
        $runTask->onApprovalRejected($snapshot);
        $runTask->refresh();

        $this->assertSame(TaskStatus::TO_DO, $runTask->status);
        $this->assertSame('user', $runTask->assignee_type);
        $this->assertSame($original->id, $runTask->assignee_id); // restored from the snapshot
    }

    public function test_approval_rejection_without_a_snapshot_leaves_a_run_task_assignee_untouched(): void
    {
        $author = User::factory()->create();
        $assignee = User::factory()->create();
        $this->actingAs($author);

        // Branch B — NO snapshot AND a run creator (creatorUser() null): the human-creator
        // fallback is skipped, so the status moves but the current assignee is left exactly
        // as it was. The run must NEVER become a user assignee.
        [$runTask] = $this->runCreatedTask($author, 'Nightly Cleanup', [
            'assignee_type' => 'user',
            'assignee_id' => $assignee->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $runTask->onApprovalRejected(new ApprovalProcess(['context' => []]));
        $runTask->refresh();

        $this->assertSame(TaskStatus::TO_DO, $runTask->status);
        $this->assertSame('user', $runTask->assignee_type);
        $this->assertSame($assignee->id, $runTask->assignee_id); // untouched — not the run

        // Contrast — a user-created task with NO snapshot DOES fall back to its human creator,
        // proving the skip above is specific to the ownerless system record.
        $userTask = Task::factory()->create([
            'creator_id' => $author->id,
            'assignee_type' => 'user',
            'assignee_id' => $assignee->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $userTask->onApprovalRejected(new ApprovalProcess(['context' => []]));
        $userTask->refresh();

        $this->assertSame(TaskStatus::TO_DO, $userTask->status);
        $this->assertSame('user', $userTask->assignee_type);
        $this->assertSame($author->id, $userTask->assignee_id); // fell back to the human creator
    }

    // 7. Removal asymmetry for a run-created system task: `changeStatus` (ARCHIVE/TRASH) gates on
    //    isOwnedBy ALONE — no workspace-owner fallback — so even the workspace owner cannot
    //    archive/trash it through the status route; the destroy endpoint (which DOES fall back to
    //    the workspace owner) is its only removal path.

    public function test_workspace_owner_cannot_archive_a_run_task_via_status_but_can_destroy_it(): void
    {
        [$owner, $workspace] = $this->workspace();

        $author = User::factory()->create();
        $workspace->users()->attach($author->id);

        // A run-created system task that is DONE (the only status ARCHIVE is reachable from) with
        // no approval pipeline, so canSetOn reaches the ARCHIVE branch cleanly.
        [$runTask] = $this->runCreatedTask($author, 'Nightly Cleanup', [
            'assignee_type' => 'user',
            'assignee_id' => $author->id,
            'status' => TaskStatus::DONE,
            'approval_pipeline_id' => null,
        ], workspaceId: $workspace->id);

        // ARCHIVE via the status route: canSetOn requires isOwnedBy(owner) — false for a system
        // record — and the enum has NO workspace-owner fallback, so the owner is refused.
        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->patchJson("/api/tasks/{$runTask->id}/status/archive")
            ->assertForbidden();

        // TRASH via the status route is likewise refused (the policy blocks TRASH there outright).
        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->patchJson("/api/tasks/{$runTask->id}/status/trash")
            ->assertForbidden();

        $this->assertSame(TaskStatus::DONE, $runTask->fresh()->status); // nothing moved

        // The destroy endpoint DOES fall back to the workspace owner — the one removal path.
        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->deleteJson("/api/tasks/{$runTask->id}")
            ->assertSuccessful();

        $this->assertSoftDeleted('tasks', ['id' => $runTask->id]);
    }
}
