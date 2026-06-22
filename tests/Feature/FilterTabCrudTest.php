<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\FilterTabs\Models\FilterTab;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilterTabCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a workspace the given user belongs to and return it. The active
     * workspace must be passed via the X-Workspace-Id header on each request so
     * the WorkspaceScope (TenantAware) stamps and isolates rows correctly.
     */
    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'context' => 'tasks',
            'name' => 'My View',
            'icon' => 'filter',
            'filters' => ['status' => 'to_do'],
        ], $overrides);
    }

    // ---------------------------------------------------------------------
    // index
    // ---------------------------------------------------------------------

    public function test_index_returns_only_own_tabs_for_the_given_context_sorted_by_sort_order(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        $other = User::factory()->create();

        // Own tabs in the requested context, intentionally out of order.
        FilterTab::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'context' => 'tasks',
            'name' => 'Third',
            'sort_order' => 3,
        ]);
        FilterTab::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'context' => 'tasks',
            'name' => 'First',
            'sort_order' => 1,
        ]);
        FilterTab::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'context' => 'tasks',
            'name' => 'Second',
            'sort_order' => 2,
        ]);

        // Same user, different context — must not appear.
        FilterTab::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'context' => 'forms',
            'name' => 'Other context',
        ]);

        // Another user's tab in the same context/workspace — must not appear.
        FilterTab::factory()->create([
            'user_id' => $other->id,
            'workspace_id' => $workspace->id,
            'context' => 'tasks',
            'name' => 'Foreign',
        ]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/filter-tabs?context=tasks')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'First')
            ->assertJsonPath('data.1.name', 'Second')
            ->assertJsonPath('data.2.name', 'Third');
    }

    public function test_index_requires_context(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/filter-tabs')
            ->assertStatus(422)
            ->assertJsonValidationErrors('context');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/filter-tabs?context=tasks')
            ->assertUnauthorized();
    }

    // ---------------------------------------------------------------------
    // store
    // ---------------------------------------------------------------------

    public function test_store_creates_tab_stamped_with_user_and_workspace_and_returns_resource_shape(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $response = $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/filter-tabs', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'My View')
            ->assertJsonPath('data.icon', 'filter')
            ->assertJsonPath('data.filters.status', 'to_do')
            ->assertJsonStructure(['data' => ['id', 'name', 'icon', 'filters', 'sort_order']]);

        $this->assertDatabaseHas('filter_tabs', [
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'context' => 'tasks',
            'name' => 'My View',
        ]);
    }

    public function test_store_assigns_next_sort_order_as_max_plus_one(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        FilterTab::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'context' => 'tasks',
            'name' => 'Existing',
            'sort_order' => 7,
        ]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/filter-tabs', $this->validPayload(['name' => 'New']))
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 8);
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/filter-tabs', $this->validPayload())
            ->assertUnauthorized();
    }

    // ---------------------------------------------------------------------
    // validation
    // ---------------------------------------------------------------------

    public function test_store_requires_name_context_and_filters(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/filter-tabs', ['icon' => 'filter'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'context', 'filters']);
    }

    public function test_store_rejects_name_longer_than_60_chars(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/filter-tabs', $this->validPayload(['name' => str_repeat('a', 61)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_store_rejects_non_array_filters(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/filter-tabs', $this->validPayload(['filters' => 'not-an-array']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('filters');
    }

    public function test_store_rejects_filters_payload_larger_than_16kb(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        // Build a filters array whose JSON encoding exceeds 16384 bytes.
        $huge = ['blob' => str_repeat('x', 17000)];

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/filter-tabs', $this->validPayload(['filters' => $huge]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('filters');
    }

    public function test_store_rejects_invalid_icon_enum(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/filter-tabs', $this->validPayload(['icon' => 'not-a-real-icon']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('icon');
    }

    public function test_store_allows_omitting_optional_icon(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $payload = $this->validPayload();
        unset($payload['icon']);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/filter-tabs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.icon', null);
    }

    // ---------------------------------------------------------------------
    // uniqueness
    // ---------------------------------------------------------------------

    public function test_store_rejects_duplicate_name_within_same_user_workspace_context(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        FilterTab::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'context' => 'tasks',
            'name' => 'Dup',
        ]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/filter-tabs', $this->validPayload(['name' => 'Dup']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_store_allows_same_name_in_a_different_context(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        FilterTab::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'context' => 'forms',
            'name' => 'Shared',
        ]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/filter-tabs', $this->validPayload(['context' => 'tasks', 'name' => 'Shared']))
            ->assertCreated();
    }

    public function test_update_with_same_name_on_self_is_allowed(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $tab = FilterTab::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'context' => 'tasks',
            'name' => 'Keep',
        ]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->patchJson("/api/filter-tabs/{$tab->id}", ['name' => 'Keep', 'icon' => 'flag'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Keep')
            ->assertJsonPath('data.icon', 'flag');
    }

    // ---------------------------------------------------------------------
    // limit
    // ---------------------------------------------------------------------

    public function test_store_rejects_the_thirty_first_tab_in_a_context(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        for ($i = 1; $i <= 30; $i++) {
            FilterTab::factory()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'context' => 'tasks',
                'name' => "Tab {$i}",
                'sort_order' => $i,
            ]);
        }

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/filter-tabs', $this->validPayload(['name' => 'Overflow']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('context');
    }

    // ---------------------------------------------------------------------
    // update / destroy authorization
    // ---------------------------------------------------------------------

    public function test_update_own_tab_succeeds(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $tab = FilterTab::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'context' => 'tasks',
            'name' => 'Before',
        ]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->patchJson("/api/filter-tabs/{$tab->id}", [
                'name' => 'After',
                'filters' => ['status' => 'done'],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'After')
            ->assertJsonPath('data.filters.status', 'done');

        $this->assertDatabaseHas('filter_tabs', ['id' => $tab->id, 'name' => 'After']);
    }

    public function test_update_foreign_tab_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $intruder = User::factory()->create();
        $workspace->users()->attach($intruder->id);

        $tab = FilterTab::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => $workspace->id,
            'context' => 'tasks',
            'name' => 'Owners',
        ]);

        $this->actingAs($intruder)->withHeader('X-Workspace-Id', $workspace->id)
            ->patchJson("/api/filter-tabs/{$tab->id}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertDatabaseHas('filter_tabs', ['id' => $tab->id, 'name' => 'Owners']);
    }

    public function test_destroy_own_tab_succeeds(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $tab = FilterTab::factory()->create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'context' => 'tasks',
        ]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->deleteJson("/api/filter-tabs/{$tab->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('filter_tabs', ['id' => $tab->id]);
    }

    public function test_destroy_foreign_tab_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $intruder = User::factory()->create();
        $workspace->users()->attach($intruder->id);

        $tab = FilterTab::factory()->create([
            'user_id' => $owner->id,
            'workspace_id' => $workspace->id,
            'context' => 'tasks',
        ]);

        $this->actingAs($intruder)->withHeader('X-Workspace-Id', $workspace->id)
            ->deleteJson("/api/filter-tabs/{$tab->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('filter_tabs', ['id' => $tab->id]);
    }

    // ---------------------------------------------------------------------
    // reorder
    // ---------------------------------------------------------------------

    public function test_reorder_sets_sort_order_following_the_given_id_sequence(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $a = FilterTab::factory()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'context' => 'tasks', 'name' => 'A', 'sort_order' => 1]);
        $b = FilterTab::factory()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'context' => 'tasks', 'name' => 'B', 'sort_order' => 2]);
        $c = FilterTab::factory()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'context' => 'tasks', 'name' => 'C', 'sort_order' => 3]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson('/api/filter-tabs/reorder', [
                'context' => 'tasks',
                'ids' => [$c->id, $a->id, $b->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'C')
            ->assertJsonPath('data.1.name', 'A')
            ->assertJsonPath('data.2.name', 'B');

        $this->assertDatabaseHas('filter_tabs', ['id' => $c->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('filter_tabs', ['id' => $a->id, 'sort_order' => 1]);
        $this->assertDatabaseHas('filter_tabs', ['id' => $b->id, 'sort_order' => 2]);
    }

    public function test_reorder_rejects_set_containing_a_foreign_tab_and_is_transactional(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        $other = User::factory()->create();
        $workspace->users()->attach($other->id);

        $a = FilterTab::factory()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'context' => 'tasks', 'name' => 'A', 'sort_order' => 1]);
        $b = FilterTab::factory()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'context' => 'tasks', 'name' => 'B', 'sort_order' => 2]);
        $foreign = FilterTab::factory()->create(['user_id' => $other->id, 'workspace_id' => $workspace->id, 'context' => 'tasks', 'name' => 'Foreign', 'sort_order' => 1]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson('/api/filter-tabs/reorder', [
                'context' => 'tasks',
                'ids' => [$b->id, $foreign->id, $a->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');

        // No partial writes — original ordering preserved.
        $this->assertDatabaseHas('filter_tabs', ['id' => $a->id, 'sort_order' => 1]);
        $this->assertDatabaseHas('filter_tabs', ['id' => $b->id, 'sort_order' => 2]);
    }

    public function test_reorder_rejects_set_containing_a_tab_from_another_context(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $a = FilterTab::factory()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'context' => 'tasks', 'name' => 'A', 'sort_order' => 1]);
        $otherContext = FilterTab::factory()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'context' => 'forms', 'name' => 'F', 'sort_order' => 1]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson('/api/filter-tabs/reorder', [
                'context' => 'tasks',
                'ids' => [$a->id, $otherContext->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    public function test_reorder_rejects_set_containing_a_nonexistent_id(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $a = FilterTab::factory()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'context' => 'tasks', 'name' => 'A', 'sort_order' => 1]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->putJson('/api/filter-tabs/reorder', [
                'context' => 'tasks',
                'ids' => [$a->id, '11111111-1111-1111-1111-111111111111'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ids');
    }

    // ---------------------------------------------------------------------
    // tenancy isolation
    // ---------------------------------------------------------------------

    public function test_tabs_are_isolated_by_active_workspace(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->postJson('/api/filter-tabs', $this->validPayload(['name' => 'In A']))
            ->assertCreated();

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceB->id)
            ->postJson('/api/filter-tabs', $this->validPayload(['name' => 'In B']))
            ->assertCreated();

        $this->assertDatabaseHas('filter_tabs', ['name' => 'In A', 'workspace_id' => $workspaceA->id]);
        $this->assertDatabaseHas('filter_tabs', ['name' => 'In B', 'workspace_id' => $workspaceB->id]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson('/api/filter-tabs?context=tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'In A');

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceB->id)
            ->getJson('/api/filter-tabs?context=tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'In B');
    }

    public function test_same_name_is_allowed_in_a_different_workspace(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->postJson('/api/filter-tabs', $this->validPayload(['name' => 'Shared']))
            ->assertCreated();

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceB->id)
            ->postJson('/api/filter-tabs', $this->validPayload(['name' => 'Shared']))
            ->assertCreated();
    }
}
