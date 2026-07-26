<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Variables\Models\Constant;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRUD for CONSTANTS — user-created, workspace-scoped typed LITERAL constants. Pins the happy paths,
 * the value-vs-descriptor type validation (the shared ConstantTypeValidator), the per-workspace key
 * uniqueness + safe-identifier rules, and the workspace-scoped authorization. The runtime WIRE stays
 * `globals.<key>` (byte-identical) even though the model/table/URL renamed to Constant/consts.
 */
class ConstantCrudTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    /** A valid text-constant payload (name → slug key `nazwa_marki`). */
    private function textPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nazwa marki',
            'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false],
            'value' => 'Taskio',
        ], $overrides);
    }

    // ---- happy paths ----------------------------------------------------------

    public function test_can_create_a_text_constant_slugging_the_key(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/consts', $this->textPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nazwa marki')
            ->assertJsonPath('data.key', 'nazwa_marki')
            ->assertJsonPath('data.reference', 'globals.nazwa_marki')
            ->assertJsonPath('data.value', 'Taskio')
            ->assertJsonPath('data.descriptor.base', 'text')
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.can_be_edited', true);

        $this->assertDatabaseHas('consts', [
            'key' => 'nazwa_marki',
            'name' => 'Nazwa marki',
            'creator_id' => $user->id,
        ]);
    }

    public function test_can_create_constants_of_each_literal_type(): void
    {
        $user = User::factory()->create();

        $cases = [
            ['name' => 'Budzet', 'descriptor' => ['base' => 'number'], 'value' => 5000],
            ['name' => 'Aktywny', 'descriptor' => ['base' => 'boolean'], 'value' => true],
            ['name' => 'Deadline', 'descriptor' => ['base' => 'date'], 'value' => '2026-12-24'],
            ['name' => 'Kategoria', 'descriptor' => ['base' => 'enum', 'options' => [['key' => 'blog', 'label' => 'Blog'], ['key' => 'news', 'label' => 'News']]], 'value' => 'blog'],
            ['name' => 'Hashtagi', 'descriptor' => ['base' => 'text', 'array' => true], 'value' => ['#ai', '#automatyzacja']],
        ];

        foreach ($cases as $payload) {
            $this->actingAs($user)
                ->postJson('/api/consts', $payload)
                ->assertCreated();
        }

        $this->assertDatabaseCount('consts', count($cases));
    }

    public function test_can_create_with_an_explicit_key(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/consts', $this->textPayload(['key' => 'brand_name']))
            ->assertCreated()
            ->assertJsonPath('data.key', 'brand_name');
    }

    public function test_can_update_own_constant(): void
    {
        $user = User::factory()->create();
        $constant = Constant::factory()->text('nazwa_marki', 'Old')->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->putJson("/api/consts/{$constant->id}", $this->textPayload(['name' => 'Nazwa marki', 'value' => 'New']))
            ->assertOk()
            ->assertJsonPath('data.value', 'New');
    }

    public function test_can_delete_own_constant(): void
    {
        $user = User::factory()->create();
        $constant = Constant::factory()->text('nazwa_marki', 'X')->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->deleteJson("/api/consts/{$constant->id}")
            ->assertOk();

        $this->assertDatabaseMissing('consts', ['id' => $constant->id]);
    }

    public function test_index_lists_workspace_constants(): void
    {
        $user = User::factory()->create();
        Constant::factory()->text('a', 'A')->create(['creator_id' => $user->id]);
        Constant::factory()->text('b', 'B')->create(['creator_id' => $user->id]);

        $keys = collect(
            $this->actingAs($user)->getJson('/api/consts')->assertOk()->json('data')
        )->pluck('key');

        $this->assertTrue($keys->contains('a'));
        $this->assertTrue($keys->contains('b'));
    }

    public function test_index_exposes_the_creator_of_each_constant(): void
    {
        // The list eager-loads `creator` (ConstantResource uses whenLoaded); without the eager-load
        // the row badge is empty AND the policy check lazy-loads it per row (N+1).
        $user = User::factory()->create();
        Constant::factory()->text('nazwa_marki', 'X')->create(['creator_id' => $user->id]);

        $data = $this->actingAs($user)->getJson('/api/consts')->assertOk()->json('data');

        $this->assertNotNull($data[0]['creator'] ?? null, 'The index must expose each constant creator (eager-loaded, not lazy).');
    }

    // ---- value-vs-descriptor validation --------------------------------------

    public function test_name_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/consts', $this->textPayload(['name' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_number_constant_rejects_a_text_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/consts', ['name' => 'Budzet', 'descriptor' => ['base' => 'number'], 'value' => 'not-a-number'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value']);
    }

    public function test_boolean_constant_rejects_a_non_bool_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/consts', ['name' => 'Aktywny', 'descriptor' => ['base' => 'boolean'], 'value' => 'yes'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value']);
    }

    public function test_date_constant_rejects_an_unparseable_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/consts', ['name' => 'Deadline', 'descriptor' => ['base' => 'date'], 'value' => 'not-a-date'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value']);
    }

    public function test_enum_value_must_be_one_of_the_option_keys(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/consts', [
                'name' => 'Kategoria',
                'descriptor' => ['base' => 'enum', 'options' => [['key' => 'blog', 'label' => 'Blog']]],
                'value' => 'podcast',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value']);
    }

    public function test_enum_descriptor_requires_a_non_empty_options_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/consts', ['name' => 'Kategoria', 'descriptor' => ['base' => 'enum'], 'value' => 'blog'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['descriptor.options']);
    }

    public function test_array_value_must_be_a_list_of_the_element_type(): void
    {
        $user = User::factory()->create();

        // An array<text> constant whose list has a non-text element is rejected at that index.
        $this->actingAs($user)
            ->postJson('/api/consts', [
                'name' => 'Hashtagi',
                'descriptor' => ['base' => 'text', 'array' => true],
                'value' => ['#ai', 42],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value.1']);

        // A scalar where a list is expected is rejected on the value.
        $this->actingAs($user)
            ->postJson('/api/consts', [
                'name' => 'Hashtagi 2',
                'descriptor' => ['base' => 'text', 'array' => true],
                'value' => 'not-a-list',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value']);
    }

    public function test_unsupported_descriptor_base_is_rejected(): void
    {
        $user = User::factory()->create();

        // A constant holds a plain literal — file (composite) and time (no literal semantics) are not
        // authorable, nor is any unknown base.
        foreach (['file', 'time', 'nonsense'] as $base) {
            $this->actingAs($user)
                ->postJson('/api/consts', ['name' => 'X ' . $base, 'descriptor' => ['base' => $base], 'value' => 'x'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['descriptor.base']);
        }
    }

    public function test_nullable_constant_accepts_a_null_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/consts', ['name' => 'Opcjonalne', 'descriptor' => ['base' => 'text', 'nullable' => true], 'value' => null])
            ->assertCreated()
            ->assertJsonPath('data.value', null);
    }

    public function test_non_nullable_constant_rejects_a_null_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/consts', ['name' => 'Wymagane', 'descriptor' => ['base' => 'text', 'nullable' => false], 'value' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value']);
    }

    public function test_a_value_carrying_a_nul_byte_is_rejected(): void
    {
        // The resolver's injection mask assumes resolved values are NUL-free; a constant's `value`
        // column is json (which permits NUL), so the validator rejects it on write — a masked
        // reference placeholder can never be forged through a constant.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/consts', $this->textPayload(['value' => "brand\0name"]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['value']);
    }

    // ---- key rules ------------------------------------------------------------

    public function test_key_must_be_unique_per_workspace(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        Constant::factory()->text('nazwa_marki', 'X')->create(['creator_id' => $user->id, 'workspace_id' => $workspace->id]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/consts', $this->textPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['key']);
    }

    public function test_key_uniqueness_is_scoped_per_workspace(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);

        Constant::factory()->text('nazwa_marki', 'A')->create(['creator_id' => $user->id, 'workspace_id' => $workspaceA->id]);

        // The SAME key in a DIFFERENT workspace is allowed (the reference namespace is per-workspace).
        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceB->id)
            ->postJson('/api/consts', $this->textPayload())
            ->assertCreated();
    }

    public function test_explicit_key_must_be_a_safe_identifier(): void
    {
        $user = User::factory()->create();

        foreach (['my.key', 'my key', 'kebab-key', '1leading'] as $badKey) {
            $this->actingAs($user)
                ->postJson('/api/consts', $this->textPayload(['key' => $badKey]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['key']);
        }
    }

    public function test_a_name_that_slugs_to_nothing_requires_an_explicit_key(): void
    {
        $user = User::factory()->create();

        // "!!!" slugs to '' — no derivable key, so an explicit key is required.
        $this->actingAs($user)
            ->postJson('/api/consts', $this->textPayload(['name' => '!!!']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['key']);
    }

    // ---- workspace-scoped authorization --------------------------------------

    public function test_a_non_member_cannot_list_constants(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $stranger = User::factory()->create();

        // ResolveWorkspace refuses a non-member who names the workspace.
        $this->actingAs($stranger)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/consts')
            ->assertForbidden();
    }

    public function test_a_foreign_workspace_constant_is_404_on_show(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);
        $foreign = Constant::factory()->text('nazwa_marki', 'X')->create(['creator_id' => $user->id, 'workspace_id' => $workspaceB->id]);

        // WorkspaceScope filters the foreign row → route-model binding 404s under workspace A.
        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson("/api/consts/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_another_user_cannot_update_or_delete_a_constant(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $constant = Constant::factory()->text('nazwa_marki', 'X')->create(['creator_id' => $owner->id]);

        $this->actingAs($other)
            ->putJson("/api/consts/{$constant->id}", $this->textPayload(['value' => 'Hacked']))
            ->assertForbidden();

        $this->actingAs($other)
            ->deleteJson("/api/consts/{$constant->id}")
            ->assertForbidden();
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson('/api/consts', $this->textPayload())
            ->assertUnauthorized();
    }
}
