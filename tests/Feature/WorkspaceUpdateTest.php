<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_rename_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id, 'name' => 'Old']);

        $this->actingAs($owner)
            ->putJson("/api/workspaces/{$workspace->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');

        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id, 'name' => 'New']);
    }

    public function test_non_owner_cannot_rename_workspace(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($member->id);

        $this->actingAs($member)
            ->putJson("/api/workspaces/{$workspace->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    }
}
