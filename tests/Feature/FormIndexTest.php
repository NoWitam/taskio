<?php

namespace Tests\Feature;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormContentVersion;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Services\FormAnalyticalTableService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_index_enabled_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index");

        $response->assertOk()
            ->assertJsonPath('data.is_indexed', true)
            ->assertJsonStructure(['data' => ['indexed_at']]);

        $this->assertNotNull($form->fresh()->indexed_at);
    }

    public function test_cannot_index_disabled_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['form']);

        $this->assertNull($form->fresh()->indexed_at);
    }

    public function test_indexing_already_indexed_form_is_idempotent(): void
    {
        $user = User::factory()->create();
        $indexedAt = now()->subHour();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
            'indexed_at' => $indexedAt,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index");

        $response->assertOk();

        // indexed_at should remain the same
        $this->assertEquals(
            $indexedAt->timestamp,
            $form->fresh()->indexed_at->timestamp
        );
    }

    public function test_only_creator_can_index_form(): void
    {
        $creator = User::factory()->create();
        $otherUser = User::factory()->create();

        $form = Form::factory()->create([
            'creator_id' => $creator->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
        ]);

        $response = $this->actingAs($otherUser)
            ->postJson("/api/forms/{$form->id}/index");

        $response->assertForbidden();
        $this->assertNull($form->fresh()->indexed_at);
    }

    public function test_can_unindex_indexed_form(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
            'indexed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/unindex");

        $response->assertOk()
            ->assertJsonPath('data.is_indexed', false);

        $this->assertNull($form->fresh()->indexed_at);
    }

    public function test_unindexing_already_unindexed_form_is_idempotent(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
            'indexed_at' => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/unindex");

        $response->assertOk();
        $this->assertNull($form->fresh()->indexed_at);
    }

    public function test_unindex_with_backup_creates_index_backup(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
            'indexed_at' => now(),
            'content_version' => 3,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/unindex", [
                'backup_indexes' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.is_indexed', false);

        $form->refresh();
        $this->assertNull($form->indexed_at);
        $this->assertNotNull($form->index_backup);
        $this->assertEquals(3, $form->index_backup['content_version']);
    }

    public function test_unindex_without_backup_does_not_create_index_backup(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
            'indexed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/unindex", [
                'backup_indexes' => false,
            ]);

        $response->assertOk();

        $form->refresh();
        $this->assertNull($form->indexed_at);
        $this->assertNull($form->index_backup);
    }

    public function test_only_creator_can_unindex_form(): void
    {
        $creator = User::factory()->create();
        $otherUser = User::factory()->create();

        $form = Form::factory()->create([
            'creator_id' => $creator->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
            'indexed_at' => now(),
        ]);

        $response = $this->actingAs($otherUser)
            ->postJson("/api/forms/{$form->id}/unindex");

        $response->assertForbidden();
        $this->assertNotNull($form->fresh()->indexed_at);
    }

    public function test_disabled_indexed_form_is_valid_combination(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
            'indexed_at' => now(),
        ]);

        // Disable the form
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/disable")
            ->assertOk();

        $form->refresh();
        // Disabled + Indexed is valid
        $this->assertNull($form->enabled_at);
        $this->assertNotNull($form->indexed_at);
    }

    public function test_index_response_includes_capabilities(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index");

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'is_indexed',
                'indexed_at',
                'content_version',
                'can_be_indexed',
                'can_be_unindexed',
                'can_be_enabled',
                'can_be_disabled',
                'is_draft',
            ]]);
    }

    // ========================================
    // Correction 6: Async indexing lifecycle
    // ========================================

    public function test_indexing_creates_analytical_table(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable first (creates content version)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        // Index
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index")
            ->assertOk();

        // Check analytical table was created (sync queue in test)
        $analyticalService = app(FormAnalyticalTableService::class);
        $this->assertTrue($analyticalService->tableExists($form));

        // Cleanup
        $analyticalService->dropTable($form);
    }

    public function test_indexing_response_includes_is_indexing(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test']
            ],
            'enabled_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index");

        $response->assertOk()
            ->assertJsonStructure(['data' => ['is_indexing']]);

        // After sync job completes, is_indexing should be false
        $this->assertFalse($response->json('data.is_indexing'));
    }

    public function test_indexing_bootstraps_compatible_submissions(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable (creates version 1)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $form->refresh();
        $versionRecord = FormContentVersion::where('form_id', $form->id)->first();

        // Create a compatible submission
        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
            'form_content_version_id' => $versionRecord->id,
            'data' => ['name' => 'John'],
        ]);

        // Index form
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index")
            ->assertOk();

        // The compatible submission should be marked as indexed
        $submission->refresh();
        $this->assertNotNull($submission->indexed_at);

        // Cleanup
        $analyticalService = app(FormAnalyticalTableService::class);
        $analyticalService->dropTable($form);
    }

    public function test_indexing_skips_incompatible_submissions(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable (creates version 1)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $form->refresh();

        // Create an incompatible submission (no form_content_version_id = legacy)
        $incompatibleSubmission = FormSubmission::factory()->create([
            'form_id' => $form->id,
            'form_content_version_id' => null,
            'data' => ['name' => 'Legacy'],
        ]);

        // Index form
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index")
            ->assertOk();

        // The incompatible submission should NOT be marked as indexed
        $incompatibleSubmission->refresh();
        $this->assertNull($incompatibleSubmission->indexed_at);

        // Cleanup
        $analyticalService = app(FormAnalyticalTableService::class);
        $analyticalService->dropTable($form);
    }

    public function test_unindexing_drops_analytical_table(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        // Index
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index")
            ->assertOk();

        $analyticalService = app(FormAnalyticalTableService::class);
        $this->assertTrue($analyticalService->tableExists($form));

        // Unindex
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/unindex")
            ->assertOk();

        $this->assertFalse($analyticalService->tableExists($form));
    }

    public function test_index_backup_includes_form_content_version_id(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        // Index
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index")
            ->assertOk();

        // Unindex with backup
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/unindex", [
                'backup_indexes' => true,
            ])
            ->assertOk();

        $form->refresh();
        $this->assertNotNull($form->index_backup);
        $this->assertArrayHasKey('form_content_version_id', $form->index_backup['table_schema']);
    }

    // ========================================
    // Correction 7: Repeater support in analytical table
    // ========================================

    public function test_analytical_table_supports_repeater_as_jsonb(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
                [
                    'id' => 'items',
                    'type' => 'repeater',
                    'config' => [
                        'name' => 'Items',
                        'min' => 1,
                        'max' => 10,
                        'children' => [
                            [
                                'id' => 'item_name',
                                'type' => 'short_text',
                                'config' => ['label' => 'Item Name', 'required' => false],
                            ],
                            [
                                'id' => 'item_qty',
                                'type' => 'number',
                                'config' => ['label' => 'Quantity'],
                            ],
                        ],
                    ],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $form->refresh();
        $versionRecord = FormContentVersion::where('form_id', $form->id)->first();

        // Create a submission with repeater data
        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
            'form_content_version_id' => $versionRecord->id,
            'data' => [
                'name' => 'Test',
                'items' => [
                    ['item_name' => 'Widget', 'item_qty' => 5],
                    ['item_name' => 'Gadget', 'item_qty' => 3],
                ],
            ],
        ]);

        // Index
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index")
            ->assertOk();

        // Verify the table exists and has the repeater column
        $analyticalService = app(FormAnalyticalTableService::class);
        $this->assertTrue($analyticalService->tableExists($form));

        // Verify the submission was indexed
        $submission->refresh();
        $this->assertNotNull($submission->indexed_at);

        // Verify schema includes repeater type
        $schema = $analyticalService->getTableSchema($form);
        $repeaterField = collect($schema['field_paths'])->firstWhere('path', 'items');
        $this->assertNotNull($repeaterField);
        $this->assertEquals('repeater', $repeaterField['type']);

        // Cleanup
        $analyticalService->dropTable($form);
    }

    // ========================================
    // Step 3: Per-submission indexing on approval
    // ========================================

    public function test_approving_submission_on_indexed_form_indexes_into_analytical_table(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable (creates version)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        // Index the form
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index")
            ->assertOk();

        $form->refresh();

        // Create a submission via API (manual = auto-approved → triggers observer)
        $response = $this->actingAs($user)
            ->postJson('/api/form-submissions', [
                'form_id' => $form->id,
                'data' => ['name' => 'New Entry'],
            ])
            ->assertCreated();

        // The submission should be indexed (indexed_at set by IndexFormSubmissionJob via observer)
        $submission = FormSubmission::find($response->json('id'));
        $this->assertNotNull($submission->indexed_at);

        // Verify the row exists in the analytical table
        $analyticalService = app(FormAnalyticalTableService::class);
        $tableName = $analyticalService->getTableName($form);
        $row = \Illuminate\Support\Facades\DB::table($tableName)
            ->where('submission_id', $submission->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals('New Entry', $row->name);

        // Cleanup
        $analyticalService->dropTable($form);
    }

    public function test_approving_incompatible_submission_on_indexed_form_does_not_index(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable (creates version 1)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        // Index the form
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index")
            ->assertOk();

        $form->refresh();

        // Update content (creates version 2) — submissions created before update are now incompatible
        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => $form->name,
                'content' => [
                    [
                        'id' => 'email',
                        'type' => 'short_text',
                        'config' => ['label' => 'Email', 'required' => false],
                    ],
                ],
                'is_anonymous' => false,
            ])
            ->assertOk();

        // Create a legacy submission directly (linked to old version = version 1)
        $oldVersion = FormContentVersion::where('form_id', $form->id)->orderBy('id')->first();
        $submission = FormSubmission::factory()->create([
            'form_id' => $form->id,
            'form_content_version_id' => $oldVersion->id,
            'data' => ['name' => 'Old Format'],
            'approved_at' => null,
            'creator_id' => $user->id,
        ]);

        // Approve it — observer should dispatch IndexFormSubmissionJob,
        // but job should skip because submission is incompatible
        $submission->update(['approved_at' => now()]);

        $submission->refresh();
        $this->assertNull($submission->indexed_at);

        // Cleanup
        $analyticalService = app(FormAnalyticalTableService::class);
        $analyticalService->dropTable($form);
    }

    // ========================================
    // Step 4: Compatibility info endpoint
    // ========================================

    public function test_compatibility_info_returns_all_compatible(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable (creates version)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $form->refresh();
        $version = FormContentVersion::where('form_id', $form->id)->first();

        // Create 3 compatible submissions
        FormSubmission::factory()->count(3)->create([
            'form_id' => $form->id,
            'form_content_version_id' => $version->id,
            'creator_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/forms/{$form->id}/compatibility");

        $response->assertOk()
            ->assertJsonPath('total_submissions', 3)
            ->assertJsonPath('compatible_count', 3)
            ->assertJsonPath('incompatible_count', 0)
            ->assertJsonPath('incompatible_periods', []);
    }

    public function test_compatibility_info_returns_incompatible_periods(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable (creates version 1)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        $form->refresh();
        $version1 = FormContentVersion::where('form_id', $form->id)->first();

        // Create submissions with version 1
        FormSubmission::factory()->count(2)->create([
            'form_id' => $form->id,
            'form_content_version_id' => $version1->id,
            'creator_id' => $user->id,
        ]);

        // Create legacy submissions (no version)
        FormSubmission::factory()->count(1)->create([
            'form_id' => $form->id,
            'form_content_version_id' => null,
            'creator_id' => $user->id,
        ]);

        // Update content (creates version 2)
        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => $form->name,
                'content' => [
                    [
                        'id' => 'email',
                        'type' => 'short_text',
                        'config' => ['label' => 'Email', 'required' => false],
                    ],
                ],
                'is_anonymous' => false,
            ])
            ->assertOk();

        $form->refresh();
        $version2 = $form->latestContentVersion();

        // Create submissions with version 2 (compatible)
        FormSubmission::factory()->count(2)->create([
            'form_id' => $form->id,
            'form_content_version_id' => $version2->id,
            'creator_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/forms/{$form->id}/compatibility");

        $response->assertOk()
            ->assertJsonPath('total_submissions', 5)
            ->assertJsonPath('compatible_count', 2)
            ->assertJsonPath('incompatible_count', 3);

        $periods = $response->json('incompatible_periods');
        $this->assertNotEmpty($periods);

        // Should have 2 groups: version 0 (legacy) and version 1
        $this->assertCount(2, $periods);
    }

    public function test_compatibility_info_requires_authorization(): void
    {
        $creator = User::factory()->create();
        $otherUser = User::factory()->create();

        $form = Form::factory()->create([
            'creator_id' => $creator->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
            ],
            'enabled_at' => now(),
            'content_version' => 1,
        ]);

        $response = $this->actingAs($otherUser)
            ->getJson("/api/forms/{$form->id}/compatibility");

        $response->assertForbidden();
    }

    // ========================================
    // Index backup restore
    // ========================================

    public function test_can_restore_index_from_compatible_backup(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        // Index
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index")
            ->assertOk();

        // Unindex with backup
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/unindex", ['backup_indexes' => true])
            ->assertOk();

        $form->refresh();
        $this->assertNotNull($form->index_backup);
        $this->assertNull($form->indexed_at);

        // Restore index
        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/restore-index");

        $response->assertOk();

        $form->refresh();
        $this->assertNotNull($form->indexed_at);
        $this->assertNull($form->index_backup);

        // Cleanup
        $analyticalService = app(FormAnalyticalTableService::class);
        $analyticalService->dropTable($form);
    }

    public function test_cannot_restore_index_without_backup(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->enabled()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test'],
            ],
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/restore-index");

        $response->assertUnprocessable();
    }

    public function test_cannot_restore_index_when_already_indexed(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->indexed()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test'],
            ],
            'index_backup' => ['table_schema' => ['form_content_version_id' => 'some-id']],
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/restore-index");

        $response->assertUnprocessable();
    }

    public function test_cannot_restore_incompatible_index_backup(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                [
                    'id' => 'name',
                    'type' => 'short_text',
                    'config' => ['label' => 'Name', 'required' => false],
                ],
            ],
            'enabled_at' => null,
            'content_version' => 0,
        ]);

        // Enable (version 1)
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/enable")
            ->assertOk();

        // Index
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index")
            ->assertOk();

        // Unindex with backup
        $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/unindex", ['backup_indexes' => true])
            ->assertOk();

        // Change content (creates version 2 — backup becomes incompatible)
        $this->actingAs($user)
            ->putJson("/api/forms/{$form->id}", [
                'name' => $form->name,
                'content' => [
                    [
                        'id' => 'email',
                        'type' => 'short_text',
                        'config' => ['label' => 'Email', 'required' => false],
                    ],
                ],
                'is_anonymous' => false,
            ])
            ->assertOk();

        // Try to restore — should fail as incompatible
        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/restore-index");

        $response->assertUnprocessable();
    }

    // ========================================
    // Concurrent indexing protection
    // ========================================

    public function test_concurrent_index_request_is_prevented(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test'],
            ],
            'enabled_at' => now(),
            'indexing_started_at' => now(), // Simulate an ongoing indexing job
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/forms/{$form->id}/index");

        // Should return OK (idempotent) but not trigger another job
        $response->assertOk();

        // indexing_started_at should still be set (not reset)
        $this->assertNotNull($form->fresh()->indexing_started_at);
    }

    // ========================================
    // Available filters & reporting mode
    // ========================================

    public function test_unindexed_form_returns_basic_filters_and_reporting(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->enabled()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test'],
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/forms/{$form->id}");

        $response->assertOk()
            ->assertJsonPath('data.reporting_mode', 'basic')
            ->assertJsonPath('data.available_filters', ['search', 'date_range', 'source', 'creator', 'approval_status']);
    }

    public function test_indexed_form_returns_advanced_filters_and_reporting(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->indexed()->create([
            'creator_id' => $user->id,
            'content' => [
                ['type' => 'short_text', 'label' => 'Test'],
            ],
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/forms/{$form->id}");

        $response->assertOk()
            ->assertJsonPath('data.reporting_mode', 'advanced');

        $filters = $response->json('data.available_filters');
        $this->assertContains('field_values', $filters);
        $this->assertContains('advanced_search', $filters);
        $this->assertContains('aggregate_stats', $filters);
    }
}
