<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the User WorkspaceMemberScope (and every bypass) keeps auth/onboarding
 * working. These are the SECURITY-critical paths: a missed bypass would break
 * login, invitation accept, or member management.
 */
class WorkspaceMemberScopeTest extends TestCase
{
    use RefreshDatabase;

    // --- Scope unit behaviour ---------------------------------------------

    public function test_scope_is_inert_with_no_active_workspace(): void
    {
        User::factory()->count(3)->create();

        // No workspace set in the context: the scope must not constrain anything.
        $this->assertSame(3, User::query()->count());
    }

    public function test_scope_restricts_to_members_when_a_workspace_is_active(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach([$owner->id, $member->id]);

        app(TenantContext::class)->set($workspace);

        $ids = User::query()->pluck('id')->all();

        $this->assertContains($owner->id, $ids);
        $this->assertContains($member->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    public function test_scope_includes_owner_without_a_pivot_row(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        // Deliberately do NOT attach the owner to the pivot.

        app(TenantContext::class)->set($workspace);

        $this->assertContains($owner->id, User::query()->pluck('id')->all());
    }

    public function test_without_scope_macro_bypasses_the_constraint(): void
    {
        $outsider = User::factory()->create();
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        app(TenantContext::class)->set($workspace);

        $this->assertNull(User::query()->find($outsider->id));
        $this->assertNotNull(User::withoutWorkspaceMemberScope()->find($outsider->id));
    }

    // --- hasMember cross-workspace bypass ---------------------------------

    public function test_has_member_is_correct_when_a_different_workspace_is_active(): void
    {
        $user = User::factory()->create();

        $active = Workspace::factory()->create(['owner_id' => User::factory()->create()->id]);
        $other = Workspace::factory()->create(['owner_id' => User::factory()->create()->id]);
        $other->users()->attach($user->id);

        // A DIFFERENT workspace is active; without the hasMember bypass the pivot
        // existence check would be narrowed to $active's members and return false.
        app(TenantContext::class)->set($active);

        $this->assertTrue($other->hasMember($user));
        $this->assertFalse($active->hasMember($user));
    }

    // --- /api/users only returns active-workspace members -----------------

    public function test_users_index_returns_empty_without_workspace_context(): void
    {
        $owner = User::factory()->create();
        User::factory()->count(2)->create();

        // No X-Workspace-Id header -> no active workspace -> directory fails closed.
        $response = $this->actingAs($owner)->getJson('/api/users');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_users_index_returns_only_members_with_workspace_context(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach([$owner->id, $member->id]);

        $response = $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/users');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($owner->id, $ids);
        $this->assertContains($member->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    // --- addMember of a not-yet-member ------------------------------------

    public function test_owner_can_add_an_existing_non_member_user(): void
    {
        $owner = User::factory()->create();
        $newcomer = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($owner->id);

        // Active workspace = the one we add into; the newcomer is NOT a member yet,
        // so the scope would 404 the lookup without the controller's bypass.
        $response = $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson("/api/workspaces/{$workspace->id}/members", [
                'user_id' => $newcomer->id,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('workspace_user', [
            'workspace_id' => $workspace->id,
            'user_id' => $newcomer->id,
        ]);
    }

    // --- alreadyMember guard with an active workspace ---------------------

    public function test_already_member_guard_rejects_inviting_an_existing_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'member@example.com']);

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach([$owner->id, $member->id]);

        // The header makes the workspace active; the alreadyMember lookup must
        // still find the member (via withoutWorkspaceMemberScope) and reject.
        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson("/api/workspaces/{$workspace->id}/invitations", [
                'email' => 'member@example.com',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'already_member');
    }

    public function test_inviting_a_non_member_email_passes_the_already_member_guard(): void
    {
        $owner = User::factory()->create();
        // An existing account that is NOT a member of this workspace.
        User::factory()->create(['email' => 'outsider@example.com']);

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($owner->id);

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson("/api/workspaces/{$workspace->id}/invitations", [
                'email' => 'outsider@example.com',
            ])
            ->assertCreated();
    }

    // --- ordering invariant: token morph under an active workspace --------

    public function test_token_morph_resolves_member_after_forgetting_guard_under_active_workspace(): void
    {
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => User::factory()->create()->id]);
        $workspace->users()->attach($member->id);

        $token = $this->postJson('/api/auth/login', [
            'email' => $member->email,
            'password' => 'password',
        ])->json('token');

        // Drop the cached guard so the next request re-runs Sanctum's token morph
        // (PersonalAccessToken -> User) from scratch — the moment the ordering in
        // ResolveWorkspace protects. The morph must resolve the user while the
        // scope is still inert, even though we send an active workspace header.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $member->id)
            ->assertJsonPath('current_workspace', $workspace->id);
    }
}
