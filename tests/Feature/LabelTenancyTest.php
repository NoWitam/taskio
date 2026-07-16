<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_labels_are_isolated_by_active_workspace(): void
    {
        $user = User::factory()->create();

        $workspaceA = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspaceA->users()->attach($user->id);
        $workspaceB = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspaceB->users()->attach($user->id);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->postJson('/api/labels', ['name' => 'Alpha'])
            ->assertSuccessful();

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceB->id)
            ->postJson('/api/labels', ['name' => 'Beta'])
            ->assertSuccessful();

        // Each new label is stamped with the active workspace.
        $this->assertDatabaseHas('labels', ['name' => 'Alpha', 'workspace_id' => $workspaceA->id]);
        $this->assertDatabaseHas('labels', ['name' => 'Beta', 'workspace_id' => $workspaceB->id]);

        // Listing inside a workspace returns only that workspace's labels.
        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson('/api/labels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha');

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceB->id)
            ->getJson('/api/labels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beta');
    }

    public function test_accessing_a_workspace_you_are_not_a_member_of_is_forbidden(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $foreign = Workspace::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $foreign->id)
            ->getJson('/api/labels')
            ->assertForbidden();
    }

    public function test_labels_have_no_cross_workspace_leak_without_header(): void
    {
        // Without an active workspace the scope does not constrain — existing
        // (non-tenant) behaviour is preserved.
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/labels', ['name' => 'Alpha'])
            ->assertSuccessful();

        $this->actingAs($user)
            ->getJson('/api/labels')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
