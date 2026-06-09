<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPipeline;
use Database\Factories\ApprovalPipelineFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalPipelineCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_pipelines(): void
    {
        $user = User::factory()->create();
        ApprovalPipeline::factory()->count(3)->create(['creator_id' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/approval-pipelines');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_pipeline_with_stages(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/approval-pipelines', [
                'name' => 'Test Pipeline',
                'icon' => 'badge-check',
                'description' => 'A test pipeline',
                'stages' => [
                    [
                        'name' => 'Manager Review',
                        'icon' => 'user',
                        'description' => null,
                        'approver_type' => 'user',
                        'approver_id' => $approver->id,
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Test Pipeline')
            ->assertJsonCount(1, 'data.stages');

        $this->assertDatabaseHas('approval_pipelines', ['name' => 'Test Pipeline']);
        $this->assertDatabaseHas('approval_stages', ['name' => 'Manager Review']);
    }

    public function test_cannot_create_pipeline_without_stages(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/approval-pipelines', [
                'name' => 'Empty Pipeline',
                'stages' => [],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['stages']);
    }

    public function test_cannot_create_pipeline_without_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/approval-pipelines', [
                'name' => '',
                'stages' => [
                    [
                        'name' => 'Stage',
                        'approver_type' => 'ai',
                    ],
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_can_update_own_pipeline(): void
    {
        $user = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->withStages(1)->create(['creator_id' => $user->id]);

        $response = $this->actingAs($user)
            ->putJson("/api/approval-pipelines/{$pipeline->id}", [
                'name' => 'Updated Name',
                'stages' => [
                    [
                        'name' => 'New Stage',
                        'approver_type' => 'ai',
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');
    }

    public function test_cannot_update_another_users_pipeline(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->withStages(1)->create(['creator_id' => $owner->id]);

        $response = $this->actingAs($other)
            ->putJson("/api/approval-pipelines/{$pipeline->id}", [
                'name' => 'Hacked',
                'stages' => [['name' => 'X', 'approver_type' => 'ai']],
            ]);

        $response->assertForbidden();
    }

    public function test_can_delete_own_pipeline(): void
    {
        $user = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $user->id]);

        $response = $this->actingAs($user)
            ->deleteJson("/api/approval-pipelines/{$pipeline->id}");

        $response->assertOk();
        $this->assertSoftDeleted('approval_pipelines', ['id' => $pipeline->id]);
    }

    public function test_can_search_pipelines(): void
    {
        $user = User::factory()->create();
        ApprovalPipeline::factory()->create(['creator_id' => $user->id, 'name' => 'Alpha Pipeline']);
        ApprovalPipeline::factory()->create(['creator_id' => $user->id, 'name' => 'Beta Pipeline']);

        $response = $this->actingAs($user)
            ->getJson('/api/approval-pipelines?search=Alpha');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Pipeline');
    }

    public function test_user_stage_requires_approver_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/approval-pipelines', [
                'name' => 'Pipeline',
                'stages' => [
                    [
                        'name' => 'Stage',
                        'approver_type' => 'user',
                        'approver_id' => null,
                    ],
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['stages.0.approver_id']);
    }
}
