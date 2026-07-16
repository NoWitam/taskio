<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Auth\Models\Group;
use App\Modules\Auth\Services\AuthContextCache;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_group_with_members_and_permissions(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $response = $this->actingAs($owner)->postJson("/api/workspaces/{$workspace->id}/groups", [
            'name' => 'Editors',
            'user_ids' => [$member->id],
            'permissions' => ['view_tasks'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Editors')
            ->assertJsonPath('data.permissions', ['view_tasks']);

        $this->assertDatabaseHas('group_permission', ['permission' => 'view_tasks']);
        $this->assertDatabaseHas('group_user', ['user_id' => $member->id]);
    }

    public function test_non_owner_cannot_create_group(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($stranger)->postJson("/api/workspaces/{$workspace->id}/groups", [
            'name' => 'Editors',
        ])->assertForbidden();
    }

    public function test_unknown_permission_is_rejected(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)->postJson("/api/workspaces/{$workspace->id}/groups", [
            'name' => 'Editors',
            'permissions' => ['definitely_not_a_real_permission'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions.0']);
    }

    public function test_permissions_are_compiled_and_cache_invalidates_on_change(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)->postJson("/api/workspaces/{$workspace->id}/groups", [
            'name' => 'Editors',
            'user_ids' => [$member->id],
            'permissions' => ['view_tasks'],
        ])->assertCreated();

        $cache = app(AuthContextCache::class);

        // Compiled + cached.
        $this->assertSame(['view_tasks'], $cache->permissions($member, $workspace->id));

        // Owner revokes the permission — cache must be invalidated.
        $group = Group::query()->firstOrFail();
        $this->actingAs($owner)->putJson("/api/workspaces/{$workspace->id}/groups/{$group->id}", [
            'name' => 'Editors',
            'user_ids' => [$member->id],
            'permissions' => [],
        ])->assertOk();

        $this->assertSame([], $cache->permissions($member, $workspace->id));
    }

    public function test_me_returns_compiled_permissions_for_active_workspace(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($member->id);

        $this->actingAs($owner)->postJson("/api/workspaces/{$workspace->id}/groups", [
            'name' => 'Editors',
            'user_ids' => [$member->id],
            'permissions' => ['view_tasks'],
        ])->assertCreated();

        $token = $this->postJson('/api/auth/login', [
            'email' => $member->email,
            'password' => 'password',
        ])->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('permissions', ['view_tasks'])
            ->assertJsonPath('current_workspace', $workspace->id);
    }
}
