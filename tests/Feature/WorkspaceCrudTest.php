<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_lists_only_their_workspaces(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $owned = Workspace::factory()->create(['owner_id' => $user->id]);
        $owned->users()->attach($user->id);

        $memberOf = Workspace::factory()->create(['owner_id' => $other->id]);
        $memberOf->users()->attach($user->id);

        Workspace::factory()->create(['owner_id' => $other->id]); // not visible

        $response = $this->actingAs($user)->getJson('/api/workspaces');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_creating_workspace_attaches_owner_as_member(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/workspaces', [
            'name' => 'Acme',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Acme')
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.db_mode', 'shared');

        $workspace = Workspace::query()->firstOrFail();
        $this->assertDatabaseHas('workspace_user', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_creating_workspace_requires_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workspaces', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_member_can_view_but_non_member_cannot(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $stranger = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($member->id);

        $this->actingAs($member)->getJson("/api/workspaces/{$workspace->id}")->assertOk();
        $this->actingAs($stranger)->getJson("/api/workspaces/{$workspace->id}")->assertForbidden();
    }

    public function test_owner_can_add_member_but_non_owner_cannot(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $newcomer = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($member->id);

        $this->actingAs($member)
            ->postJson("/api/workspaces/{$workspace->id}/members", ['user_id' => $newcomer->id])
            ->assertForbidden();

        $this->actingAs($owner)
            ->postJson("/api/workspaces/{$workspace->id}/members", ['user_id' => $newcomer->id])
            ->assertOk();

        $this->assertDatabaseHas('workspace_user', [
            'workspace_id' => $workspace->id,
            'user_id' => $newcomer->id,
        ]);
    }
}
