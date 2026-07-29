<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Models\Template;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRUD + authorization for generation SESSIONS (R2 sub-stage 2b). Pins the happy paths, the resource wire
 * shape (status + slot_values + results + capability flags; the recipe snapshot is intentionally NOT on
 * the wire), the workspace-scoped authorization (shared mode) and the creator-only mutation rule
 * (GenerationSessionPolicy). The GENERATE flow lives in GenerationSessionGenerateTest.
 *
 * The snapshot-immutability test is the load-bearing one: a session captures the template recipe at
 * creation and a later template edit/delete never changes it (asserted on the model — the resource omits
 * the blob; the RUN also reads only the snapshot, proven in GenerationSessionGenerateTest).
 */
class GenerationSessionCrudTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    /** A `post` template with a plain body (no directives), owned by $user. */
    private function postTemplate(User $user, string $body = 'A post body'): Template
    {
        return Template::factory()->create([
            'creator_id' => $user->id,
            'content_type' => 'post',
            'slots' => [['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]]],
            'content' => ['body' => ['markdown' => $body]],
        ]);
    }

    // ---- happy paths ----------------------------------------------------------

    public function test_can_create_a_session_from_a_template(): void
    {
        $user = User::factory()->create();
        $template = $this->postTemplate($user);

        $this->actingAs($user)
            ->postJson('/api/generator/sessions', [
                'template_id' => $template->id,
                'name' => 'My launch draft',
                'slot_values' => ['topic' => 'Widgets'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'My launch draft')
            ->assertJsonPath('data.template_id', $template->id)
            ->assertJsonPath('data.content_type', 'post')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.slot_values.topic', 'Widgets')
            ->assertJsonPath('data.results', null)
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.can_generate', true)
            ->assertJsonPath('data.can_edit', true)
            // The recipe snapshot is a large immutable blob — never on the wire.
            ->assertJsonMissingPath('data.recipe_snapshot');

        $this->assertDatabaseHas('generation_sessions', [
            'template_id' => $template->id,
            'content_type' => 'post',
            'status' => 'draft',
            'creator_id' => $user->id,
        ]);
    }

    public function test_name_defaults_to_the_template_name(): void
    {
        $user = User::factory()->create();
        $template = $this->postTemplate($user);

        $this->actingAs($user)
            ->postJson('/api/generator/sessions', ['template_id' => $template->id])
            ->assertCreated()
            ->assertJsonPath('data.name', $template->name);
    }

    public function test_can_update_name_and_slot_values_while_draft(): void
    {
        $user = User::factory()->create();
        $session = GenerationSession::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->patchJson("/api/generator/sessions/{$session->id}", [
                'name' => 'Renamed',
                'slot_values' => ['topic' => 'Rockets'],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.slot_values.topic', 'Rockets');
    }

    public function test_cannot_update_while_generating(): void
    {
        $user = User::factory()->create();
        $session = GenerationSession::factory()->status(GenerationSessionStatus::Generating)->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->patchJson("/api/generator/sessions/{$session->id}", ['name' => 'Nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_can_delete_own_session(): void
    {
        $user = User::factory()->create();
        $session = GenerationSession::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->deleteJson("/api/generator/sessions/{$session->id}")
            ->assertOk();

        // Soft-deleted (trash), not hard-removed.
        $this->assertSoftDeleted('generation_sessions', ['id' => $session->id]);
    }

    public function test_index_lists_workspace_sessions_newest_first_with_the_creator(): void
    {
        $user = User::factory()->create();
        $older = GenerationSession::factory()->create(['creator_id' => $user->id, 'name' => 'Older', 'created_at' => now()->subDay()]);
        $newer = GenerationSession::factory()->create(['creator_id' => $user->id, 'name' => 'Newer', 'created_at' => now()]);

        $data = $this->actingAs($user)->getJson('/api/generator/sessions')->assertOk()->json('data');

        $this->assertSame($newer->id, $data[0]['id']);
        $this->assertSame($older->id, $data[1]['id']);
        $this->assertNotNull($data[0]['creator'] ?? null);
    }

    public function test_index_filters_by_status_and_search(): void
    {
        $user = User::factory()->create();
        GenerationSession::factory()->status(GenerationSessionStatus::Ready)->create(['creator_id' => $user->id, 'name' => 'Ready alpha']);
        GenerationSession::factory()->status(GenerationSessionStatus::Draft)->create(['creator_id' => $user->id, 'name' => 'Draft beta']);

        $names = collect($this->actingAs($user)->getJson('/api/generator/sessions?status=ready')->assertOk()->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Ready alpha'));
        $this->assertFalse($names->contains('Draft beta'));

        $names = collect($this->actingAs($user)->getJson('/api/generator/sessions?search=beta')->assertOk()->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Draft beta'));
        $this->assertFalse($names->contains('Ready alpha'));
    }

    // ---- validation -----------------------------------------------------------

    public function test_template_id_is_required_and_must_resolve(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/sessions', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['template_id']);

        $this->actingAs($user)
            ->postJson('/api/generator/sessions', ['template_id' => (string) \Illuminate\Support\Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['template_id']);
    }

    // ---- snapshot immutability (the load-bearing property) --------------------

    public function test_create_snapshots_the_recipe_and_is_immutable_to_later_template_edits(): void
    {
        $user = User::factory()->create();
        $template = $this->postTemplate($user, 'ORIGINAL body');

        $id = $this->actingAs($user)
            ->postJson('/api/generator/sessions', ['template_id' => $template->id, 'slot_values' => ['topic' => 'X']])
            ->assertCreated()
            ->json('data.id');

        // The snapshot captured the recipe at creation (asserted on the model — omitted from the wire).
        $snapshot = GenerationSession::find($id)->recipe_snapshot;
        $this->assertSame('ORIGINAL body', $snapshot['content']['body']['markdown']);
        $this->assertSame('post', $snapshot['content_type']);
        $this->assertSame('topic', $snapshot['slots'][0]['name']);

        // Edit the source template's body.
        $this->actingAs($user)
            ->putJson("/api/generator/templates/{$template->id}", [
                'name' => $template->name,
                'content_type' => 'post',
                'slots' => [['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]]],
                'content' => ['body' => ['markdown' => 'CHANGED body']],
            ])
            ->assertOk();

        // The session's snapshot is UNCHANGED by the edit.
        $this->assertSame('ORIGINAL body', GenerationSession::find($id)->recipe_snapshot['content']['body']['markdown']);

        // Deleting the template leaves the session (and its snapshot) intact — no hard FK dependency.
        $this->actingAs($user)->deleteJson("/api/generator/templates/{$template->id}")->assertOk();
        $survivor = GenerationSession::find($id);
        $this->assertNotNull($survivor);
        $this->assertSame('ORIGINAL body', $survivor->recipe_snapshot['content']['body']['markdown']);
    }

    // ---- workspace-scoped authorization --------------------------------------

    public function test_a_non_member_cannot_list_sessions(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceFor($owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->withHeader('X-Workspace-Id', $workspace->id)
            ->getJson('/api/generator/sessions')
            ->assertForbidden();
    }

    public function test_a_foreign_workspace_session_is_404_on_show(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);
        $foreign = GenerationSession::factory()->create(['creator_id' => $user->id, 'workspace_id' => $workspaceB->id]);

        // WorkspaceScope filters the foreign row → route-model binding 404s under workspace A.
        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson("/api/generator/sessions/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_another_user_cannot_update_or_delete_a_session(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $session = GenerationSession::factory()->create(['creator_id' => $owner->id]);

        $this->actingAs($other)
            ->patchJson("/api/generator/sessions/{$session->id}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($other)
            ->deleteJson("/api/generator/sessions/{$session->id}")
            ->assertForbidden();
    }

    public function test_a_non_owner_sees_read_only_capability_flags(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $session = GenerationSession::factory()->create(['creator_id' => $owner->id]);

        $this->actingAs($other)
            ->getJson("/api/generator/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.is_owner', false)
            ->assertJsonPath('data.can_generate', false)
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_be_deleted', false);
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson('/api/generator/sessions', ['template_id' => 'x'])
            ->assertUnauthorized();
    }
}
