<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Models\Template;
use App\Modules\Workspaces\Models\Workspace;
use Database\Factories\TemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRUD for TEMPLATES — user-created, workspace-scoped reusable content RECIPES with declared typed slots.
 * Pins the happy paths, the resource wire shape (content_type + per-part content + server-authoritative
 * capability flags; NO legacy type/prompt_body/parameters), the workspace-scoped authorization (shared
 * mode; own mode is covered by TemplateOwnDatabaseTest) and the creator-only mutation rule (TemplatePolicy).
 * The per-part content write validation lives in TemplateContentValidationTest.
 */
class TemplateCrudTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    /** A valid `post` template payload with one text slot referenced by the post body. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Launch post',
            'description' => 'A product launch post',
            'content_type' => 'post',
            'slots' => [
                ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]],
            ],
            'content' => [
                'body' => ['markdown' => 'Write about ' . TemplateFactory::directive('slots.topic') . '.'],
            ],
        ], $overrides);
    }

    /** A minimal valid payload for each content type (only the REQUIRED parts). */
    private function payloadFor(string $contentType): array
    {
        $slots = [['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]]];
        $body = ['markdown' => 'About ' . TemplateFactory::directive('slots.topic') . '.'];

        $content = match ($contentType) {
            'post' => ['body' => $body],
            'post_with_image' => ['body' => $body],
            'video_script' => ['shot_list' => ['brief' => ['markdown' => 'A short video about ' . TemplateFactory::directive('slots.topic') . '.']]],
            default => [],
        };

        return ['name' => 'T ' . $contentType, 'content_type' => $contentType, 'slots' => $slots, 'content' => $content];
    }

    // ---- happy paths ----------------------------------------------------------

    public function test_can_create_a_template(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Launch post')
            ->assertJsonPath('data.content_type', 'post')
            ->assertJsonPath('data.slots.0.name', 'topic')
            ->assertJsonPath('data.slots.0.descriptor.base', 'text')
            // The per-part content map rides through as authored (keyed by the type's part keys).
            ->assertJsonPath('data.content.body.markdown', 'Write about ' . TemplateFactory::directive('slots.topic') . '.')
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.can_be_edited', true)
            ->assertJsonPath('data.can_be_deleted', true)
            // The legacy fields are gone from the wire.
            ->assertJsonMissingPath('data.type')
            ->assertJsonMissingPath('data.prompt_body')
            ->assertJsonMissingPath('data.parameters');

        $this->assertDatabaseHas('templates', [
            'name' => 'Launch post',
            'content_type' => 'post',
            'creator_id' => $user->id,
        ]);
    }

    public function test_can_create_each_content_type(): void
    {
        $user = User::factory()->create();

        foreach (['post', 'post_with_image', 'video_script'] as $contentType) {
            $this->actingAs($user)
                ->postJson('/api/generator/templates', $this->payloadFor($contentType))
                ->assertCreated()
                ->assertJsonPath('data.content_type', $contentType);
        }

        $this->assertDatabaseCount('templates', 3);
    }

    public function test_can_update_own_template(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->putJson("/api/generator/templates/{$template->id}", $this->payload(['name' => 'Renamed']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_can_delete_own_template(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->deleteJson("/api/generator/templates/{$template->id}")
            ->assertOk();

        $this->assertDatabaseMissing('templates', ['id' => $template->id]);
    }

    public function test_index_lists_workspace_templates_with_the_creator(): void
    {
        $user = User::factory()->create();
        Template::factory()->create(['creator_id' => $user->id, 'name' => 'Alpha']);
        Template::factory()->create(['creator_id' => $user->id, 'name' => 'Beta']);

        $data = $this->actingAs($user)->getJson('/api/generator/templates')->assertOk()->json('data');

        $names = collect($data)->pluck('name');
        $this->assertTrue($names->contains('Alpha'));
        $this->assertTrue($names->contains('Beta'));
        // The list eager-loads creator (whenLoaded) — without it the badge is empty and the policy check
        // lazy-loads per row (N+1).
        $this->assertNotNull($data[0]['creator'] ?? null);
    }

    public function test_index_is_searchable_by_name(): void
    {
        $user = User::factory()->create();
        Template::factory()->create(['creator_id' => $user->id, 'name' => 'Instagram launch']);
        Template::factory()->create(['creator_id' => $user->id, 'name' => 'Newsletter']);

        $names = collect(
            $this->actingAs($user)->getJson('/api/generator/templates?search=instagram')->assertOk()->json('data')
        )->pluck('name');

        $this->assertTrue($names->contains('Instagram launch'));
        $this->assertFalse($names->contains('Newsletter'));
    }

    // ---- validation shape -----------------------------------------------------

    public function test_name_and_content_type_are_required_and_type_must_be_known(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->payload(['name' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->payload(['content_type' => 'tweet']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content_type']);
    }

    // ---- workspace-scoped authorization --------------------------------------

    public function test_a_non_member_cannot_list_templates(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/generator/templates')
            ->assertForbidden();
    }

    public function test_a_foreign_workspace_template_is_404_on_show(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);
        $foreign = Template::factory()->create(['creator_id' => $user->id, 'workspace_id' => $workspaceB->id]);

        // WorkspaceScope filters the foreign row → route-model binding 404s under workspace A.
        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson("/api/generator/templates/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_another_user_cannot_update_or_delete_a_template(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $template = Template::factory()->create(['creator_id' => $owner->id]);

        $this->actingAs($other)
            ->putJson("/api/generator/templates/{$template->id}", $this->payload(['name' => 'Hijacked']))
            ->assertForbidden();

        $this->actingAs($other)
            ->deleteJson("/api/generator/templates/{$template->id}")
            ->assertForbidden();
    }

    public function test_a_non_owner_sees_read_only_capability_flags(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $template = Template::factory()->create(['creator_id' => $owner->id]);

        // A workspace MEMBER may read a template but not mutate it — the flags are server-authoritative.
        $this->actingAs($other)
            ->getJson("/api/generator/templates/{$template->id}")
            ->assertOk()
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.can_be_edited', false)
            ->assertJsonPath('data.can_be_deleted', false);
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson('/api/generator/templates', $this->payload())
            ->assertUnauthorized();
    }
}
