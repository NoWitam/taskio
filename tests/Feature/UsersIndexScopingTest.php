<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersIndexScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_current_workspace_members(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach([$owner->id, $member->id]);

        // The outsider only belongs to a DIFFERENT workspace.
        $other = Workspace::factory()->create(['owner_id' => $outsider->id]);
        $other->users()->attach($outsider->id);

        $response = $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/users?per_page=20');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($owner->id, $ids);
        $this->assertContains($member->id, $ids);
        $this->assertNotContains($outsider->id, $ids, 'Users from other workspaces must not leak.');
    }

    public function test_owner_is_included_even_without_a_pivot_row(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        // Deliberately do NOT attach the owner to the pivot.

        $response = $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/users');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($owner->id, $ids);
    }

    public function test_get_by_ids_excludes_non_members(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($owner->id);

        $response = $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/users?ids[]=' . $owner->id . '&ids[]=' . $outsider->id);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($owner->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }
}
