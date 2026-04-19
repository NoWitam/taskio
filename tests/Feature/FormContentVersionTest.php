<?php

namespace Tests\Feature;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormContentVersion;
use App\Modules\Forms\Models\FormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormContentVersionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper to create valid form content with proper structure
     */
    private function validContent(string $id = 'field_1', string $label = 'Test Field', string $type = 'short_text'): array
    {
        return [
            [
                'id' => $id,
                'type' => $type,
                'config' => [
                    'label' => $label,
                    'required' => false,
                ],
            ],
        ];
    }

    public function test_enabling_form_creates_content_version_snapshot(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent(),
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $form->refresh();
        $this->assertEquals(1, $form->content_version);
        $this->assertNotNull($form->content_updated_at);

        $version = FormContentVersion::where('form_id', $form->id)->first();
        $this->assertNotNull($version);
        $this->assertEquals(1, $version->version);
        $this->assertEquals($form->content, $version->content);
        $this->assertNotEmpty($version->json_schema);
    }

    public function test_updating_content_on_enabled_form_creates_new_version_snapshot(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent('field_1', 'Original'),
            'enabled_at' => now(),
            'content_version' => 1,
            'content_updated_at' => now()->subHour(),
        ]);

        // Create version 1 snapshot manually (simulating it was created on enable)
        FormContentVersion::create([
            'form_id' => $form->id,
            'version' => 1,
            'content' => $form->content,
            'json_schema' => $form->getJsonSchema(),
        ]);

        $newContent = $this->validContent('field_2', 'Updated', 'long_text');

        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => $form->name,
                'content' => $newContent,
                'is_anonymous' => false,
            ])
            ->assertOk();

        $form->refresh();
        $this->assertEquals(2, $form->content_version);

        $versions = FormContentVersion::where('form_id', $form->id)
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertEquals(1, $versions[0]->version);
        $this->assertEquals(2, $versions[1]->version);
        $this->assertEquals($newContent, $versions[1]->content);
    }

    public function test_updating_metadata_only_does_not_create_new_version(): void
    {
        $user = User::factory()->create();
        $contentUpdatedAt = now()->subHour();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent(),
            'enabled_at' => now(),
            'content_version' => 1,
            'content_updated_at' => $contentUpdatedAt,
        ]);

        FormContentVersion::create([
            'form_id' => $form->id,
            'version' => 1,
            'content' => $form->content,
            'json_schema' => $form->getJsonSchema(),
        ]);

        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => 'Updated Name',
                'description' => 'Updated Description',
                'is_anonymous' => false,
            ])
            ->assertOk();

        $form->refresh();
        $this->assertEquals(1, $form->content_version);
        $this->assertEquals(
            $contentUpdatedAt->timestamp,
            $form->content_updated_at->timestamp
        );

        $this->assertEquals(1, FormContentVersion::where('form_id', $form->id)->count());
    }

    public function test_updating_content_on_disabled_form_does_not_create_version(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent('field_1', 'Original'),
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => $form->name,
                'content' => $this->validContent('field_2', 'Updated', 'long_text'),
                'is_anonymous' => false,
            ])
            ->assertOk();

        $form->refresh();
        $this->assertEquals(0, $form->content_version);
        $this->assertNull($form->content_updated_at);
        $this->assertEquals(0, FormContentVersion::where('form_id', $form->id)->count());
    }

    public function test_anonymous_form_creation_creates_content_version_snapshot(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/forms', [
                'name' => 'Anonymous Form',
                'is_anonymous' => true,
                'content' => $this->validContent(),
            ]);

        $response->assertCreated();

        $form = Form::find($response->json('data.id'));
        $this->assertEquals(1, $form->content_version);
        $this->assertNotNull($form->content_updated_at);

        $version = FormContentVersion::where('form_id', $form->id)->first();
        $this->assertNotNull($version);
        $this->assertEquals(1, $version->version);
    }

    public function test_non_anonymous_form_creation_does_not_create_version(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/forms', [
                'name' => 'Regular Form',
                'is_anonymous' => false,
                'content' => $this->validContent(),
            ]);

        $response->assertCreated();

        $form = Form::find($response->json('data.id'));
        $this->assertEquals(0, $form->content_version);
        $this->assertNull($form->content_updated_at);
        $this->assertEquals(0, FormContentVersion::where('form_id', $form->id)->count());
    }

    public function test_reenabling_form_creates_new_version_snapshot(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent('field_1', 'Original'),
            'enabled_at' => now(),
            'content_version' => 1,
            'content_updated_at' => now(),
        ]);

        FormContentVersion::create([
            'form_id' => $form->id,
            'version' => 1,
            'content' => $form->content,
            'json_schema' => $form->getJsonSchema(),
        ]);

        // Disable
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/disable")
            ->assertOk();

        // Modify content while disabled
        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => $form->name,
                'content' => $this->validContent('field_2', 'Modified Field'),
                'is_anonymous' => false,
            ])
            ->assertOk();

        // Re-enable
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $form->refresh();
        $this->assertEquals(2, $form->content_version);

        $versions = FormContentVersion::where('form_id', $form->id)
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertEquals(2, $versions[1]->version);
    }

    public function test_submission_gets_stamped_with_content_version_id(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent('name', 'Name'),
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable (creates version 1)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $form->refresh();
        $versionRecord = FormContentVersion::where('form_id', $form->id)->first();
        $this->assertNotNull($versionRecord);

        // Create submission
        $response = $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => ['name' => 'John'],
            ]);

        $response->assertCreated();

        // Verify in DB
        $submission = $form->submissions()->first();
        $this->assertNotNull($submission);
        $this->assertEquals($versionRecord->id, $submission->form_content_version_id);
    }

    public function test_content_version_response_includes_version_fields(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent(),
            'enabled_at' => null,
        ]);

        // Enable to get version fields populated
        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable");

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'content_version',
                'content_updated_at',
            ]]);

        $this->assertEquals(1, $response->json('data.content_version'));
        $this->assertNotNull($response->json('data.content_updated_at'));
    }

    public function test_submission_response_includes_version_fields(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->enabled()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent('name', 'Name'),
        ]);

        // Create the content version snapshot (simulating enable flow)
        FormContentVersion::create([
            'form_id' => $form->id,
            'version' => $form->content_version,
            'content' => $form->content,
            'json_schema' => $form->getJsonSchema(),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => ['name' => 'Test'],
            ]);

        $response->assertCreated();

        // Verify version fields in response (resource is NOT data-wrapped)
        $response->assertJsonStructure([
            'form_content_version_id',
        ]);
        $this->assertNotNull($response->json('form_content_version_id'));
    }

    public function test_content_version_json_schema_is_stored(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => [
                        'label' => 'Name',
                        'required' => true,
                    ],
                ],
                [
                    'id' => 'age',
                    'type' => 'number',
                    'config' => [
                        'label' => 'Age',
                    ],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $version = FormContentVersion::where('form_id', $form->id)->first();
        $this->assertNotNull($version->json_schema);
        $this->assertArrayHasKey('type', $version->json_schema);
        $this->assertEquals('object', $version->json_schema['type']);
    }

    public function test_enabling_idempotent_does_not_create_duplicate_version(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->enabled()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent(),
        ]);

        FormContentVersion::create([
            'form_id' => $form->id,
            'version' => $form->content_version,
            'content' => $form->content,
            'json_schema' => $form->getJsonSchema(),
        ]);

        // Enabling already-enabled form should be idempotent
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $this->assertEquals(1, FormContentVersion::where('form_id', $form->id)->count());
    }

    // ========================================
    // Correction 2: Parent-child version history
    // ========================================

    public function test_first_version_has_no_parent(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent(),
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $version = FormContentVersion::where('form_id', $form->id)->first();
        $this->assertNotNull($version);
        $this->assertNull($version->parent_id);
    }

    public function test_second_version_links_to_first_as_parent(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent('field_1', 'Original'),
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable (creates version 1)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $firstVersion = FormContentVersion::where('form_id', $form->id)->first();

        // Update content (creates version 2)
        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => $form->name,
                'content' => $this->validContent('field_2', 'Updated'),
                'is_anonymous' => false,
            ])
            ->assertOk();

        $versions = FormContentVersion::where('form_id', $form->id)
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $versions);
        $this->assertNull($versions[0]->parent_id);
        $this->assertEquals($firstVersion->id, $versions[1]->parent_id);
    }

    public function test_version_parent_child_relationships_work(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent('field_1', 'V1'),
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable (version 1)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        // Update (version 2)
        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => $form->name,
                'content' => $this->validContent('field_2', 'V2'),
                'is_anonymous' => false,
            ])
            ->assertOk();

        $versions = FormContentVersion::where('form_id', $form->id)
            ->orderBy('version')
            ->get();

        // Test parent() relationship
        $this->assertNull($versions[0]->parent);
        $this->assertNotNull($versions[1]->parent);
        $this->assertEquals($versions[0]->id, $versions[1]->parent->id);

        // Test children() relationship
        $this->assertCount(1, $versions[0]->children);
        $this->assertEquals($versions[1]->id, $versions[0]->children->first()->id);
        $this->assertCount(0, $versions[1]->children);
    }

    // ========================================
    // Correction 3: Submissions no longer write content_version int
    // ========================================

    public function test_submission_does_not_write_content_version_integer(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent('name', 'Name'),
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable (creates version 1, content_version = 1)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        // Create submission
        $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => ['name' => 'John'],
            ])
            ->assertCreated();

        // Submission should have form_content_version_id set (content_version column removed)
        $submission = $form->submissions()->first();
        $this->assertNotNull($submission->form_content_version_id);
    }

    // ========================================
    // Correction 3: isSubmissionCompatible uses form_content_version_id
    // ========================================

    public function test_submission_compatibility_uses_content_version_id(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent('name', 'Name'),
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable (creates version 1)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $form->refresh();
        $versionRecord = FormContentVersion::where('form_id', $form->id)->first();

        // Create compatible submission
        $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => ['name' => 'John'],
            ])
            ->assertCreated();

        $submission = $form->submissions()->first();
        $this->assertTrue($form->isSubmissionCompatible($submission));

        // Update form content (creates version 2)
        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => $form->name,
                'content' => $this->validContent('email', 'Email'),
                'is_anonymous' => false,
            ])
            ->assertOk();

        $form->refresh();

        // Now the submission is incompatible (linked to version 1, form is on version 2)
        $this->assertFalse($form->isSubmissionCompatible($submission));
    }

    public function test_legacy_submission_without_version_id_is_incompatible(): void
    {
        $user = User::factory()->create();

        // Use actingAs to provide auth context for changelog/observer
        $this->actingAs($user);

        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => $this->validContent('name', 'Name'),
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable the form via API (creates version snapshot)
        $this->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $form->refresh();

        // Create a "legacy" submission without form_content_version_id
        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
            'form_content_version_id' => null,
            'creator_id' => $user->id,
        ]);

        // Legacy submissions are incompatible
        $this->assertFalse($form->isSubmissionCompatible($submission));
    }
}
