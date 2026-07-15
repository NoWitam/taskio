<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Comments\Models\Comment;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4 of the polymorphic `creator` refactor: CommentPolicy::delete allows the comment's
 * author OR the OWNER of the commentable to delete it. The object-owner branch became functional
 * once ownership moved to HasCreator::isOwnedBy (it was dead against the old dynamic attribute).
 * These tests pin the observable delete authorisation through the endpoint:
 *   - the human owner of a user-created task may delete another person's comment on it;
 *   - a stranger who neither authored the comment nor owns the task may not; and
 *   - a run-created (system) commentable is owned by nobody, so the object-owner branch fails
 *     closed — a non-author cannot delete through it, while the comment's own author still can.
 */
class CommentOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function commentOn(Task $task, User $author): Comment
    {
        return $task->comments()->create([
            'content' => 'a comment',
            'author_type' => 'user',
            'author_id' => $author->id,
        ]);
    }

    /**
     * A task created by a workflow run: a system record owned by nobody. Task's fillable exposes
     * creator_id but not creator_type, so the workflow_run creator is stamped at the DB level
     * after create, exactly as the engine would.
     */
    private function runCreatedTask(User $workflowAuthor): Task
    {
        $workflow = Workflow::factory()->create(['creator_id' => $workflowAuthor->id]);
        $run = WorkflowRun::factory()->create(['workflow_id' => $workflow->id]);

        $task = Task::factory()->create();

        DB::table('tasks')->where('id', $task->id)->update([
            'creator_type' => 'workflow_run',
            'creator_id' => $run->id,
        ]);

        return $task->refresh();
    }

    public function test_object_owner_can_delete_a_comment_on_their_own_task(): void
    {
        $owner = User::factory()->create();
        $commenter = User::factory()->create();

        $task = Task::factory()->create([
            'creator_id' => $owner->id,
            'assignee_type' => 'user',
            'assignee_id' => $owner->id,
        ]);

        // Authored by someone else, so only the object-owner branch can authorise the delete.
        $comment = $this->commentOn($task, $commenter);

        $this->actingAs($owner)
            ->deleteJson("/api/comments/{$comment->id}")
            ->assertOk();

        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    public function test_a_non_owner_non_author_cannot_delete_the_comment(): void
    {
        $owner = User::factory()->create();
        $commenter = User::factory()->create();
        $stranger = User::factory()->create();

        $task = Task::factory()->create([
            'creator_id' => $owner->id,
            'assignee_type' => 'user',
            'assignee_id' => $owner->id,
        ]);

        $comment = $this->commentOn($task, $commenter);

        // Neither the comment's author nor the task's owner: both policy branches are false.
        $this->actingAs($stranger)
            ->deleteJson("/api/comments/{$comment->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('comments', ['id' => $comment->id]);
    }

    public function test_object_owner_branch_fails_closed_for_a_run_created_commentable(): void
    {
        $workflowAuthor = User::factory()->create(); // triggered the workflow, but owns nothing
        $commenter = User::factory()->create();

        $task = $this->runCreatedTask($workflowAuthor);
        $comment = $this->commentOn($task, $commenter);

        // The commentable is a system record owned by nobody (isOwnedBy is false for everyone), so
        // a non-author cannot reach the delete through the object-owner branch — not even the human
        // who set the workflow running.
        $this->actingAs($workflowAuthor)
            ->deleteJson("/api/comments/{$comment->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('comments', ['id' => $comment->id]);

        // Contrast: the comment's own author still deletes it via the isAuthor branch, proving the
        // fail-closed above is specific to the ownerless object-owner branch.
        $this->actingAs($commenter)
            ->deleteJson("/api/comments/{$comment->id}")
            ->assertOk();

        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }
}
