<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Variables\Models\CustomFunction;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRUD for CUSTOM FUNCTIONS (Phase 3a) — user-created, workspace-scoped variable transforms. Pins the
 * happy paths (create/update/delete/list/show), the resource shape + capability flags, and the
 * workspace-scoped authorization (member read, creator-only mutation). Definition + body + cycle
 * validation live in CustomFunctionValidationTest.
 */
class CustomFunctionCrudTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    /** A valid text→text function payload (uppercase the input, no args). */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Uppercase',
            'description' => 'Uppercases the input',
            'input_type' => 'text',
            'args' => [],
            'return_type' => 'text',
            'body' => [['op' => 'text_uppercase']],
        ], $overrides);
    }

    // ---- happy paths ----------------------------------------------------------

    public function test_can_create_a_function(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/functions', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Uppercase')
            ->assertJsonPath('data.input_type', 'text')
            ->assertJsonPath('data.return_type', 'text')
            ->assertJsonPath('data.args', [])
            ->assertJsonPath('data.body.0.op', 'text_uppercase')
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.can_be_edited', true)
            ->assertJsonPath('data.can_be_deleted', true);

        $this->assertDatabaseHas('custom_functions', [
            'name' => 'Uppercase',
            'input_type' => 'text',
            'return_type' => 'text',
            'creator_id' => $user->id,
        ]);
    }

    public function test_can_create_a_function_with_typed_args_referenced_in_the_body(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/functions', $this->payload([
                'name' => 'Append suffix',
                'args' => [['name' => 'suffix', 'description' => 'The text to append', 'type' => 'text']],
                'body' => [[
                    'op' => 'text_append',
                    'args' => ['value' => ['kind' => 'variable', 'ref' => ['source' => 'scope', 'path' => 'suffix', 'type' => 'text']]],
                ]],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.args.0.name', 'suffix')
            ->assertJsonPath('data.args.0.type', 'text');
    }

    public function test_can_update_own_function(): void
    {
        $user = User::factory()->create();
        $function = CustomFunction::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->putJson("/api/functions/{$function->id}", $this->payload(['name' => 'Renamed']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_can_delete_own_function(): void
    {
        $user = User::factory()->create();
        $function = CustomFunction::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->deleteJson("/api/functions/{$function->id}")
            ->assertOk();

        $this->assertDatabaseMissing('custom_functions', ['id' => $function->id]);
    }

    public function test_show_returns_the_definition(): void
    {
        $user = User::factory()->create();
        $function = CustomFunction::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->getJson("/api/functions/{$function->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $function->id)
            ->assertJsonPath('data.input_type', $function->input_type);
    }

    public function test_index_lists_workspace_functions(): void
    {
        $user = User::factory()->create();
        CustomFunction::factory()->create(['creator_id' => $user->id, 'name' => 'Alpha']);
        CustomFunction::factory()->create(['creator_id' => $user->id, 'name' => 'Beta']);

        $names = collect(
            $this->actingAs($user)->getJson('/api/functions')->assertOk()->json('data')
        )->pluck('name');

        $this->assertTrue($names->contains('Alpha'));
        $this->assertTrue($names->contains('Beta'));
    }

    public function test_index_exposes_the_creator_of_each_function(): void
    {
        // The list eager-loads `creator` (CustomFunctionResource uses whenLoaded); without it the row
        // badge is empty AND the policy check lazy-loads it per row (N+1).
        $user = User::factory()->create();
        CustomFunction::factory()->create(['creator_id' => $user->id]);

        $data = $this->actingAs($user)->getJson('/api/functions')->assertOk()->json('data');

        $this->assertNotNull($data[0]['creator'] ?? null, 'The index must expose each function creator (eager-loaded, not lazy).');
    }

    // ---- workspace-scoped authorization --------------------------------------

    public function test_a_non_member_cannot_list_functions(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/functions')
            ->assertForbidden();
    }

    public function test_a_foreign_workspace_function_is_404_on_show(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);
        $foreign = CustomFunction::factory()->create(['creator_id' => $user->id, 'workspace_id' => $workspaceB->id]);

        // WorkspaceScope filters the foreign row → route-model binding 404s under workspace A.
        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson("/api/functions/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_another_user_cannot_update_or_delete_a_function(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $function = CustomFunction::factory()->create(['creator_id' => $owner->id]);

        $this->actingAs($other)
            ->putJson("/api/functions/{$function->id}", $this->payload(['name' => 'Hacked']))
            ->assertForbidden();

        $this->actingAs($other)
            ->deleteJson("/api/functions/{$function->id}")
            ->assertForbidden();
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson('/api/functions', $this->payload())
            ->assertUnauthorized();
    }
}
