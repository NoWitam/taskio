<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression for the WorkspaceMemberScope rollout: user identity attached to
 * IMMUTABLE/AUDIT records (comment authors, changelog causers) must stay resolvable
 * even after that user leaves the workspace — both to avoid a 500 (CommentResource
 * dereferenced a now-null author) and to preserve audit history.
 */
class MemberRemovalHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceWith(User $owner, array $memberIds = []): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach(array_merge([$owner->id], $memberIds));

        return $workspace;
    }

    private function createTaskAs(User $user, Workspace $workspace): string
    {
        return $this->actingAs($user)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/tasks', [
                'title' => 'History task',
                'priority' => 'medium',
                'assigned_id' => $user->id,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    public function test_comments_list_survives_author_removal_and_keeps_the_author(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspaceWith($owner, [$member->id]);

        $taskId = $this->createTaskAs($member, $workspace);

        $this->actingAs($member)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson("/api/tasks/{$taskId}/comments", ['content' => 'a comment'])
            ->assertCreated();

        // The member leaves the workspace.
        $workspace->users()->detach($member->id);

        // The owner can still open the comments — no 500 — and the author is preserved.
        $response = $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/tasks/{$taskId}/comments")
            ->assertOk();

        $this->assertSame($member->id, $response->json('data.0.author.id'));
    }

    public function test_changelog_preserves_the_causer_after_removal(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspaceWith($owner, [$member->id]);

        $taskId = $this->createTaskAs($member, $workspace);

        // A status change the member is allowed to make → a changelog entry caused by them.
        $this->actingAs($member)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->patchJson("/api/tasks/{$taskId}/status/in_progress")
            ->assertOk();

        $workspace->users()->detach($member->id);

        // NOTE: the changelog endpoint resolves the subject via the morph map, where
        // Task is registered as 'task' (singular) — unlike comments ('tasks'). The
        // 'task' vs 'tasks' inconsistency is a separate pre-existing bug; here we use
        // the alias that resolves so we exercise the causer-bypass fix.
        $response = $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson("/api/task/{$taskId}/changelog")
            ->assertOk();

        $causerIds = collect($response->json('data'))->pluck('causer.id')->filter()->all();
        $this->assertContains($member->id, $causerIds, 'The causer must survive the member leaving.');
    }
}
