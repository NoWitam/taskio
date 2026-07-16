<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Agents\ApprovalEvaluationAgent;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Approvals\Tools\GetEntityDetails;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalEvaluationAgentTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(?string $stageDescription): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $pipeline = ApprovalPipeline::factory()->create(['name' => 'Pipeline X']);
        $stage = $pipeline->stages()->create([
            'name' => 'AI Stage',
            'icon' => 'archive',
            'description' => $stageDescription,
            'approver_type' => ApproverType::Ai,
            'approver_id' => null,
            'order' => 1,
        ]);

        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assigned_id' => $user->id,
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $process = app(ApprovalService::class)->startProcess($task, $user);

        return [new ApprovalEvaluationAgent($task, $stage, $process), $stage];
    }

    public function test_instructions_with_explicit_criteria_mention_the_criteria(): void
    {
        [$agent] = $this->makeAgent('Sprawdź, czy klient został powiadomiony mailowo.');

        $instructions = (string) $agent->instructions();

        $this->assertNotEmpty($instructions);
        $this->assertStringContainsString('KRYTERIA ETAPU', $instructions);
        $this->assertStringContainsString('Sprawdź, czy klient został powiadomiony mailowo.', $instructions);
        // It judges the executed work, not the design.
        $this->assertStringContainsString('WYKONAN', $instructions);
    }

    public function test_instructions_without_criteria_bias_toward_approve(): void
    {
        [$agent] = $this->makeAgent(null);

        $instructions = (string) $agent->instructions();

        $this->assertNotEmpty($instructions);
        // No explicit criteria => default-approve guidance present.
        $this->assertStringContainsString('NIE MA jawnych kryteriów', $instructions);
        $this->assertStringContainsString('ZATWIERDŹ', $instructions);
    }

    public function test_agent_structure_is_intact(): void
    {
        [$agent] = $this->makeAgent('kryterium');

        $tools = collect($agent->tools());
        $this->assertTrue($tools->contains(fn ($tool) => $tool instanceof GetEntityDetails));

        $schema = $agent->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory);
        $this->assertArrayHasKey('decision', $schema);
        $this->assertArrayHasKey('note', $schema);
    }
}
