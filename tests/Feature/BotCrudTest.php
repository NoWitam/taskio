<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Enums\BotStatus;
use App\Modules\Bot\Models\Bot;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotCrudTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Copywriter Bot',
            'persona' => 'A friendly marketing copywriter who writes upbeat posts.',
            'style' => 'Casual and concise.',
            'dictionary' => [['term' => 'CTA', 'meaning' => 'call to action']],
            'phrases' => [['phrase' => 'Stay tuned!', 'context' => null]],
            'prohibitions' => ['No politics'],
        ], $overrides);
    }

    public function test_can_create_bot_with_text_module(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/bots', $this->validPayload([
                // status in the body is IGNORED — a bot is always created inactive.
                'status' => 'active',
                'task_execution' => [
                    'enabled' => true,
                    // Legacy key from an old client — must be silently ignored (B6).
                    'knowledge_source' => 'docs',
                    'tools' => ['fetch_url'],
                ],
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Copywriter Bot')
            // Created inactive regardless of the request body.
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.persona', 'A friendly marketing copywriter who writes upbeat posts.')
            ->assertJsonPath('data.task_execution.enabled', true)
            // Inactive => cannot execute tasks even with task_execution enabled.
            ->assertJsonPath('data.can_execute_tasks', false)
            ->assertJsonPath('data.is_owner', true);

        // knowledge_source is neither persisted nor returned (silently dropped, no 422).
        $this->assertArrayNotHasKey('knowledge_source', $response->json('data.task_execution'));
        $this->assertDatabaseHas('bots', ['name' => 'Copywriter Bot', 'creator_id' => $user->id]);
        $bot = \App\Modules\Bot\Models\Bot::firstWhere('name', 'Copywriter Bot');
        $this->assertArrayNotHasKey('knowledge_source', $bot->task_execution);
    }

    public function test_persona_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/bots', $this->validPayload(['persona' => '']));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['persona']);
    }

    public function test_name_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/bots', $this->validPayload(['name' => '']));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_can_update_own_bot(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);

        $response = $this->actingAs($user)
            ->putJson("/api/bots/{$bot->id}", $this->validPayload([
                'name' => 'Renamed Bot',
                'persona' => 'Updated persona text.',
            ]));

        $response->assertOk()
            ->assertJsonPath('data.name', 'Renamed Bot')
            ->assertJsonPath('data.persona', 'Updated persona text.');
    }

    public function test_cannot_update_another_users_bot(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $owner->id]);

        $this->actingAs($other)
            ->putJson("/api/bots/{$bot->id}", $this->validPayload())
            ->assertForbidden();
    }

    public function test_can_show_bot(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->getJson("/api/bots/{$bot->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $bot->id)
            ->assertJsonPath('data.persona', $bot->persona);
    }

    public function test_can_list_bots(): void
    {
        $user = User::factory()->create();
        Bot::factory()->count(3)->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->getJson('/api/bots')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.has_text_module', true);
    }

    public function test_index_is_cursor_paginated(): void
    {
        $user = User::factory()->create();
        Bot::factory()->count(10)->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->getJson('/api/bots')
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonStructure(['data', 'links', 'meta' => ['next_cursor']]);
    }

    public function test_can_search_bots(): void
    {
        $user = User::factory()->create();
        Bot::factory()->create(['creator_id' => $user->id, 'name' => 'Alpha Bot']);
        Bot::factory()->create(['creator_id' => $user->id, 'name' => 'Beta Bot']);

        $this->actingAs($user)
            ->getJson('/api/bots?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Bot');
    }

    /**
     * `can_execute_tasks=1` narrows the list to bots that can actually RUN a task — the
     * SQL mirror of Bot::canExecuteTasks(). The task-assignee pickers rely on it, so a
     * bot missing EITHER half (active status / task_execution.enabled) must be excluded.
     */
    public function test_index_can_be_narrowed_to_task_executing_bots(): void
    {
        $user = User::factory()->create();
        Bot::factory()->executesTasks()->create(['creator_id' => $user->id, 'name' => 'Runner']);
        // Module on, but the bot is not active.
        Bot::factory()->create([
            'creator_id' => $user->id,
            'name' => 'Paused',
            'status' => BotStatus::INACTIVE,
            'task_execution' => ['enabled' => true, 'tools' => []],
        ]);
        // Active, module explicitly off.
        Bot::factory()->active()->create([
            'creator_id' => $user->id,
            'name' => 'Off',
            'task_execution' => ['enabled' => false, 'tools' => []],
        ]);
        // Active, module never configured (NULL column).
        Bot::factory()->active()->create(['creator_id' => $user->id, 'name' => 'Unconfigured']);

        $this->actingAs($user)
            ->getJson('/api/bots?can_execute_tasks=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Runner');

        // Unfiltered listing is untouched.
        $this->actingAs($user)
            ->getJson('/api/bots')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_can_soft_delete_and_restore_bot(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->deleteJson("/api/bots/{$bot->id}")
            ->assertOk();

        $this->assertSoftDeleted('bots', ['id' => $bot->id]);

        $this->actingAs($user)
            ->postJson("/api/bots/{$bot->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $bot->id);

        $this->assertDatabaseHas('bots', ['id' => $bot->id, 'deleted_at' => null]);
    }

    public function test_cannot_delete_another_users_bot(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $owner->id]);

        $this->actingAs($other)
            ->deleteJson("/api/bots/{$bot->id}")
            ->assertForbidden();
    }

    public function test_bots_are_isolated_by_active_workspace(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->postJson('/api/bots', $this->validPayload(['name' => 'Bot A']))
            ->assertCreated();

        $this->assertDatabaseHas('bots', ['name' => 'Bot A', 'workspace_id' => $workspaceA->id]);

        // Listing inside workspace A returns only A's bot.
        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson('/api/bots')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bot A');

        // Workspace B sees nothing from A (no cross-workspace leak).
        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceB->id)
            ->getJson('/api/bots')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_non_member_cannot_access_workspace_bots(): void
    {
        $owner = User::factory()->create();
        $foreignWorkspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->withHeader('X-Workspace-Id', $foreignWorkspace->id)
            ->getJson('/api/bots')
            ->assertForbidden();
    }

    public function test_guest_cannot_access_bots(): void
    {
        $this->getJson('/api/bots')->assertUnauthorized();
    }
}
