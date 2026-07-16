<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_endpoint_returns_owner_and_members_with_owner_flag(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach([$owner->id, $member->id]);

        $response = $this->actingAs($owner)->getJson("/api/workspaces/{$workspace->id}/members");

        $response->assertOk()->assertJsonCount(2, 'data');

        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($byId[$owner->id]['is_owner']);
        $this->assertFalse($byId[$member->id]['is_owner']);
        $this->assertArrayHasKey('email', $byId[$owner->id]);
    }

    public function test_owner_is_included_even_when_not_in_pivot(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($member->id); // owner not attached

        $response = $this->actingAs($owner)->getJson("/api/workspaces/{$workspace->id}/members");

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_non_member_cannot_list_members(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($stranger)
            ->getJson("/api/workspaces/{$workspace->id}/members")
            ->assertForbidden();
    }

    public function test_members_endpoint_does_not_n_plus_one(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach([
            $owner->id,
            User::factory()->create()->id,
            User::factory()->create()->id,
            User::factory()->create()->id,
        ]);

        DB::enableQueryLog();
        $this->actingAs($owner)->getJson("/api/workspaces/{$workspace->id}/members")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // owner is resolved in PHP from the loaded owner_id; a handful of queries
        // total regardless of member count proves no per-member query.
        $this->assertLessThan(10, $count);
    }

    public function test_owner_cannot_be_removed(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($owner->id);

        $response = $this->actingAs($owner)
            ->deleteJson("/api/workspaces/{$workspace->id}/members/{$owner->id}");

        $response->assertStatus(422)->assertJsonPath('errors.user.0', 'cannot_remove_owner');

        $this->assertDatabaseHas('workspace_user', [
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_non_owner_cannot_remove_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach([$owner->id, $member->id]);

        $this->actingAs($member)
            ->deleteJson("/api/workspaces/{$workspace->id}/members/{$member->id}")
            ->assertForbidden();
    }

    public function test_owner_can_remove_a_regular_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach([$owner->id, $member->id]);

        $this->actingAs($owner)
            ->deleteJson("/api/workspaces/{$workspace->id}/members/{$member->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('workspace_user', [
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
        ]);
    }
}
