<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Forms\Models\Form;
use App\Modules\Workspaces\Models\Workspace;
use App\Rules\ScopedExists;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Security matrix for {@see ScopedExists}: proves the write-side leak is closed for
 * BOTH user-membership references (assigned_id / approver_id / group user_ids) and
 * tenant-entity references (form_id / approval_pipeline_id). All requests run with an
 * active workspace (X-Workspace-Id) so the User/Tenant global scopes are engaged.
 */
class ScopedExistsSecurityTest extends TestCase
{
    use RefreshDatabase;

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

    // --- assigned_id (User WorkspaceMemberScope) --------------------------

    public function test_task_assigned_to_a_member_passes(): void
    {
        [$owner, $workspace] = $this->workspace();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id);

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/tasks', [
                'title' => 'Task',
                'priority' => 'medium',
                'assigned_id' => $member->id,
            ])
            ->assertCreated();
    }

    public function test_task_assigned_to_a_non_member_is_rejected(): void
    {
        [$owner, $workspace] = $this->workspace();
        $outsider = User::factory()->create(); // never attached to the workspace

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/tasks', [
                'title' => 'Task',
                'priority' => 'medium',
                'assigned_id' => $outsider->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_id']);
    }

    // --- pipeline approver_id (User WorkspaceMemberScope) -----------------

    public function test_pipeline_with_a_member_approver_passes(): void
    {
        [$owner, $workspace] = $this->workspace();
        $approver = User::factory()->create();
        $workspace->users()->attach($approver->id);

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/approval-pipelines', [
                'name' => 'Pipeline',
                'stages' => [[
                    'name' => 'Review',
                    'approver_type' => 'user',
                    'approver_id' => $approver->id,
                ]],
            ])
            ->assertCreated();
    }

    public function test_pipeline_with_a_non_member_approver_is_rejected(): void
    {
        [$owner, $workspace] = $this->workspace();
        $outsider = User::factory()->create();

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/approval-pipelines', [
                'name' => 'Pipeline',
                'stages' => [[
                    'name' => 'Review',
                    'approver_type' => 'user',
                    'approver_id' => $outsider->id,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stages.0.approver_id']);
    }

    // --- group user_ids.* (User WorkspaceMemberScope) ---------------------

    public function test_group_with_a_member_user_id_passes(): void
    {
        [$owner, $workspace] = $this->workspace();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id);

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson("/api/workspaces/{$workspace->id}/groups", [
                'name' => 'Editors',
                'user_ids' => [$member->id],
            ])
            ->assertCreated();
    }

    public function test_group_with_a_non_member_user_id_is_rejected(): void
    {
        [$owner, $workspace] = $this->workspace();
        $outsider = User::factory()->create();

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson("/api/workspaces/{$workspace->id}/groups", [
                'name' => 'Editors',
                'user_ids' => [$outsider->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_ids.0']);
    }

    // --- form_id (TenantAware WorkspaceScope, shared mode) ----------------

    public function test_task_referencing_a_same_workspace_form_passes(): void
    {
        [$owner, $workspace] = $this->workspace();
        $form = Form::factory()->create([
            'creator_id' => $owner->id,
            'workspace_id' => $workspace->id,
        ]);

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/tasks', [
                'title' => 'Task',
                'priority' => 'medium',
                'assigned_id' => $owner->id,
                'form_id' => $form->id,
            ])
            ->assertCreated();
    }

    public function test_task_referencing_a_form_from_another_workspace_is_rejected(): void
    {
        [$owner, $workspace] = $this->workspace();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => User::factory()->create()->id]);
        $foreignForm = Form::factory()->create([
            'creator_id' => $owner->id,
            'workspace_id' => $otherWorkspace->id,
        ]);

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/tasks', [
                'title' => 'Task',
                'priority' => 'medium',
                'assigned_id' => $owner->id,
                'form_id' => $foreignForm->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['form_id']);
    }

    public function test_form_submission_for_a_form_from_another_workspace_is_rejected(): void
    {
        [$owner, $workspace] = $this->workspace();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => User::factory()->create()->id]);
        $foreignForm = Form::factory()->enabled()->create([
            'creator_id' => $owner->id,
            'workspace_id' => $otherWorkspace->id,
        ]);

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/form-submissions', [
                'form_id' => $foreignForm->id,
                'data' => ['name' => 'John'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['form_id']);
    }

    // --- approval_pipeline_id (TenantAware WorkspaceScope, shared mode) ----

    public function test_task_referencing_a_pipeline_from_another_workspace_is_rejected(): void
    {
        [$owner, $workspace] = $this->workspace();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => User::factory()->create()->id]);
        $foreignPipeline = ApprovalPipeline::factory()->create([
            'creator_id' => $owner->id,
            'workspace_id' => $otherWorkspace->id,
        ]);

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/tasks', [
                'title' => 'Task',
                'priority' => 'medium',
                'assigned_id' => $owner->id,
                'approval_pipeline_id' => $foreignPipeline->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approval_pipeline_id']);
    }

    // --- the rule runs via Eloquent (own-mode routing depends on this) ----

    public function test_rule_runs_through_eloquent_so_global_scopes_apply(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($owner->id);

        $member = User::factory()->create();
        $workspace->users()->attach($member->id);
        $outsider = User::factory()->create();

        // Engage the User WorkspaceMemberScope by activating the workspace.
        app(TenantContext::class)->set($workspace);

        try {
            $passes = Validator::make(
                ['user' => $member->id],
                ['user' => [new ScopedExists(User::class)]],
            );
            $this->assertFalse($passes->fails(), 'A member must pass the scoped rule.');

            $fails = Validator::make(
                ['user' => $outsider->id],
                ['user' => [new ScopedExists(User::class)]],
            );
            $this->assertTrue($fails->fails(), 'A non-member must fail the scoped rule.');
        } finally {
            app(TenantContext::class)->clear();
        }
    }

    // --- addMember of a not-yet-member still works (raw exists preserved) --

    public function test_add_member_of_a_not_yet_member_still_works(): void
    {
        [$owner, $workspace] = $this->workspace();
        $newcomer = User::factory()->create();

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson("/api/workspaces/{$workspace->id}/members", [
                'user_id' => $newcomer->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('workspace_user', [
            'workspace_id' => $workspace->id,
            'user_id' => $newcomer->id,
        ]);
    }
}
