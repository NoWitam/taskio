<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Agents\ApprovalEvaluationAgent;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Models\ApprovalProcess;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Bot\Models\Bot;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * Batch 3: a Bot can be a NAMED AI approver. A bot-approver stage evaluates through
 * the SAME AI path as a generic `ai` stage, but the bot's persona colors the verdict.
 */
class BotApproverTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    private function botPipeline(User $owner, Bot $bot): ApprovalPipeline
    {
        $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $owner->id]);
        $pipeline->stages()->create([
            'name' => 'Bot Review',
            'icon' => 'archive',
            'description' => 'Sprawdź jakość pracy.',
            'approver_type' => ApproverType::Bot,
            'approver_id' => $bot->id,
            'order' => 1,
        ]);

        return $pipeline;
    }

    private function inTestTask(User $owner, ApprovalPipeline $pipeline): Task
    {
        return Task::factory()->create([
            'creator_id' => $owner->id,
            'assignee_type' => 'user',
            'assignee_id' => $owner->id,
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);
    }

    public function test_bot_approver_stage_evaluates_with_persona_and_records_decision(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $bot = Bot::factory()->create([
            'creator_id' => $owner->id,
            'name' => 'Strict Editor',
            'persona' => 'Rygorystyczny redaktor dbający o jakość.',
        ]);

        $captured = null;
        $this->fakeApprovalEvaluationUsing(function () use (&$captured) {
            return ['decision' => ApprovalProcessStatus::Approved->value, 'note' => 'Wygląda dobrze.'];
        });

        $pipeline = $this->botPipeline($owner, $bot);
        $task = $this->inTestTask($owner, $pipeline);

        // Starting the process on a bot stage dispatches the AI evaluation job (sync).
        $process = app(ApprovalService::class)->startProcess($task, $owner);

        // The persona was injected into the agent instructions.
        ApprovalEvaluationAgent::assertPrompted(function ($prompt) use ($bot) {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, $bot->name)
                && str_contains($instructions, 'Rygorystyczny redaktor');
        });

        // The decision was recorded and the run reached approved (single-stage pipeline).
        $latest = ApprovalProcess::where('run_id', $process->run_id)->latest('id')->first();
        $this->assertSame(ApprovalProcessStatus::Approved, $latest->status);
        $this->assertSame(ApproverType::Bot, $latest->approver_type);
        $this->assertSame($bot->id, $latest->approver_id);
    }

    public function test_bot_approver_reject_restores_the_task_and_records_the_decision(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = Bot::factory()->create([
            'creator_id' => $owner->id,
            'name' => 'Strict Editor',
            'persona' => 'Surowy redaktor.',
        ]);

        $this->fakeApprovalEvaluationUsing(fn () => [
            'decision' => ApprovalProcessStatus::Rejected->value,
            'note' => 'Do poprawy.',
        ]);

        $pipeline = $this->botPipeline($owner, $bot);
        $task = $this->inTestTask($owner, $pipeline);

        $process = app(ApprovalService::class)->startProcess($task, $owner);

        // The bot's reject decision (with reasons) is recorded for the bot approver.
        $latest = ApprovalProcess::where('run_id', $process->run_id)->latest('id')->first();
        $this->assertSame(ApprovalProcessStatus::Rejected, $latest->status);
        $this->assertSame(ApproverType::Bot, $latest->approver_type);
        $this->assertSame('Do poprawy.', $latest->note);

        // On reject the task returns to the board.
        $task->refresh();
        $this->assertSame(TaskStatus::TO_DO, $task->status);
    }

    public function test_human_cannot_decide_a_bot_process_over_http(): void
    {
        // Keep the bot process pending: faking the queue stops the AI job from
        // auto-deciding it on startProcess.
        Queue::fake();

        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = Bot::factory()->create(['creator_id' => $owner->id]);
        $pipeline = $this->botPipeline($owner, $bot);
        $task = $this->inTestTask($owner, $pipeline);

        $process = app(ApprovalService::class)->startProcess($task, $owner);
        $this->assertTrue($process->isPending());

        // The decide policy must exclude automated (bot/ai) approvers by intent — a human
        // cannot push a decision onto a bot stage.
        $this->postJson("/api/approvals/processes/{$process->id}/decide", [
            'decision' => ApprovalProcessStatus::Approved->value,
            'note' => 'trying to override the bot',
        ])->assertForbidden();
    }

    public function test_generic_ai_stage_still_works_without_persona(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $owner->id]);
        $pipeline->stages()->create([
            'name' => 'AI Review',
            'icon' => 'archive',
            'description' => null,
            'approver_type' => ApproverType::Ai,
            'approver_id' => null,
            'order' => 1,
        ]);
        $task = $this->inTestTask($owner, $pipeline);

        $this->fakeApprovalEvaluation([
            'decision' => ApprovalProcessStatus::Approved->value,
            'note' => 'ok',
        ]);

        $process = app(ApprovalService::class)->startProcess($task, $owner);

        // A generic AI stage carries no persona — instructions must not name a bot.
        ApprovalEvaluationAgent::assertPrompted(function ($prompt) {
            $instructions = (string) $prompt->agent->instructions();

            return !str_contains($instructions, 'OCENIASZ JAKO BOT');
        });

        $latest = ApprovalProcess::where('run_id', $process->run_id)->latest('id')->first();
        $this->assertSame(ApprovalProcessStatus::Approved, $latest->status);
    }

    public function test_pipeline_resource_exposes_bot_approver_identity(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = Bot::factory()->create(['creator_id' => $owner->id, 'name' => 'Reviewer Bot']);
        $pipeline = $this->botPipeline($owner, $bot);

        $this->getJson("/api/approval-pipelines/{$pipeline->id}")
            ->assertOk()
            ->assertJsonPath('data.stages.0.approver_type', 'bot')
            ->assertJsonPath('data.stages.0.approver_identity.type', 'bot')
            ->assertJsonPath('data.stages.0.approver_identity.id', $bot->id)
            ->assertJsonPath('data.stages.0.approver_identity.name', 'Reviewer Bot')
            ->assertJsonPath('data.stages.0.approver_identity.is_bot', true)
            // Back-compat: the user-only approver is null for a bot stage.
            ->assertJsonPath('data.stages.0.approver', null);
    }

    public function test_store_accepts_a_bot_approver_stage(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = Bot::factory()->create(['creator_id' => $owner->id]);

        $this->postJson('/api/approval-pipelines', [
            'name' => 'With bot',
            'stages' => [[
                'name' => 'Bot stage',
                'approver_type' => 'bot',
                'approver_id' => $bot->id,
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.stages.0.approver_type', 'bot')
            ->assertJsonPath('data.stages.0.approver_identity.id', $bot->id);
    }

    public function test_store_rejects_a_cross_workspace_bot_approver(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($owner->id);

        // A bot that belongs to a DIFFERENT workspace must not be referenceable.
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $foreignBot = Bot::factory()->create([
            'creator_id' => $otherOwner->id,
            'workspace_id' => $otherWorkspace->id,
        ]);

        $this->actingAs($owner)
            ->withHeader('X-Workspace-Id', $workspace->id)
            ->postJson('/api/approval-pipelines', [
                'name' => 'Cross-tenant',
                'stages' => [[
                    'name' => 'Bot stage',
                    'approver_type' => 'bot',
                    'approver_id' => $foreignBot->id,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stages.0.approver_id']);
    }

    public function test_bot_approver_id_is_required(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $this->postJson('/api/approval-pipelines', [
            'name' => 'Missing bot id',
            'stages' => [[
                'name' => 'Bot stage',
                'approver_type' => 'bot',
            ]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stages.0.approver_id']);
    }

    public function test_marked_done_is_recorded_for_a_bot_executed_task_on_completion(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = Bot::factory()->executesTasks()->create(['creator_id' => $owner->id]);

        // Pipeline with a single USER approver so we control the decision manually.
        $approver = User::factory()->create();
        $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $owner->id]);
        $pipeline->stages()->create([
            'name' => 'Human review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        $this->scriptBotRun([['post_comment', ['text' => 'Bot work done.']], ['finish']]);

        // The bot executes; the task reaches in_test and a (pending) approval starts.
        $taskId = $this->postJson('/api/tasks', [
            'title' => 'Bot work',
            'priority' => 'medium',
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
            'approval_pipeline_id' => $pipeline->id,
        ])->assertCreated()->json('data.id');

        $process = ApprovalProcess::where('approvable_id', $taskId)->latest('id')->firstOrFail();

        // Approve -> task completes -> marked_done recorded for the executing bot.
        app(ApprovalService::class)->decide($process, ApprovalProcessStatus::Approved, 'ok');

        $this->assertSame(TaskStatus::DONE, Task::find($taskId)->status);
        $this->assertDatabaseHas('bot_actions', [
            'task_id' => $taskId,
            'bot_id' => $bot->id,
            'type' => 'marked_done',
        ]);
    }
}
