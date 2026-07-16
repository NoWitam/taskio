<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Services\ApprovalService;
use App\Modules\Bot\Models\Bot;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase A regression: the task assignee became polymorphic (User|Bot). This guards
 * the BACK-COMPAT surface (legacy `assigned_id` / `assigned`) and the new additive
 * `assignee` field for both a User and a Bot assignee.
 */
class TaskPolymorphicAssigneeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_assigned_task_round_trips_with_legacy_and_new_fields(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assignee_type' => 'user',
            'assignee_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk();

        // Back-compat: legacy user-only fields still emitted.
        $response->assertJsonPath('data.assigned.id', $user->id);
        $response->assertJsonPath('data.assigned.email', $user->email);

        // New polymorphic field renders the User.
        $response->assertJsonPath('data.assignee.type', 'user');
        $response->assertJsonPath('data.assignee.id', $user->id);
        $response->assertJsonPath('data.assignee.is_bot', false);
    }

    public function test_bot_assigned_task_nulls_legacy_field_and_renders_bot_assignee(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);
        $task = Task::factory()->create([
            'creator_id' => $user->id,
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/tasks/{$task->id}")
            ->assertOk();

        // Back-compat: user-only `assigned` is null when the task is a bot's.
        $response->assertJsonPath('data.assigned', null);

        // New polymorphic field renders the Bot.
        $response->assertJsonPath('data.assignee.type', 'bot');
        $response->assertJsonPath('data.assignee.id', $bot->id);
        $response->assertJsonPath('data.assignee.is_bot', true);
        $response->assertJsonPath('data.assignee.name', $bot->name);
    }

    public function test_assigned_id_accessor_returns_user_id_only(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);

        $userTask = Task::factory()->create(['assignee_type' => 'user', 'assignee_id' => $user->id]);
        $botTask = Task::factory()->create(['assignee_type' => 'bot', 'assignee_id' => $bot->id]);

        $this->assertSame($user->id, $userTask->assigned_id);
        $this->assertNull($botTask->assigned_id);
    }

    public function test_legacy_assigned_id_setter_maps_to_user_assignee(): void
    {
        $user = User::factory()->create();

        // Legacy write path: setting assigned_id assigns a User.
        $task = Task::factory()->create(['assigned_id' => $user->id]);

        $this->assertSame('user', $task->assignee_type);
        $this->assertSame($user->id, $task->assignee_id);
        $this->assertSame($user->id, $task->assigned_id);
    }

    public function test_store_accepts_explicit_bot_assignee(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson('/api/tasks', [
                'title' => 'Bot task',
                'priority' => 'medium',
                'assignee_type' => 'bot',
                'assignee_id' => $bot->id,
            ])
            ->assertCreated();

        $response->assertJsonPath('data.assignee.type', 'bot');
        $response->assertJsonPath('data.assignee.id', $bot->id);
        $response->assertJsonPath('data.assigned', null);
    }

    public function test_store_still_accepts_legacy_assigned_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/tasks', [
                'title' => 'User task',
                'priority' => 'medium',
                'assigned_id' => $user->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.assigned.id', $user->id)
            ->assertJsonPath('data.assignee.type', 'user');
    }

    public function test_store_requires_an_assignee(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/tasks', [
                'title' => 'No assignee',
                'priority' => 'medium',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_id']);
    }

    public function test_approval_reject_restores_a_bot_assignee(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $approver = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $owner->id]);

        $pipeline = ApprovalPipeline::factory()->create(['creator_id' => $owner->id]);
        $pipeline->stages()->create([
            'name' => 'Review',
            'approver_type' => ApproverType::User,
            'approver_id' => $approver->id,
            'order' => 1,
        ]);

        // Task assigned to the bot, entering approval.
        $task = Task::factory()->create([
            'creator_id' => $owner->id,
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
            'approval_pipeline_id' => $pipeline->id,
            'status' => TaskStatus::IN_TEST,
        ]);

        $process = app(ApprovalService::class)->startProcess($task, $owner);

        // Approval snapshots the polymorphic original assignee (the bot).
        $this->assertSame('bot', $process->context['original_assignee_type']);
        $this->assertSame($bot->id, $process->context['original_assignee_id']);

        // Simulate the task being reassigned to a human while under review.
        $task->update(['assignee_type' => 'user', 'assignee_id' => $approver->id]);

        app(ApprovalService::class)->decide($process, ApprovalProcessStatus::Rejected, 'redo it');

        // On reject the original BOT assignee is restored.
        $task->refresh();
        $this->assertSame(TaskStatus::TO_DO, $task->status);
        $this->assertSame('bot', $task->assignee_type);
        $this->assertSame($bot->id, $task->assignee_id);
    }

    public function test_bot_id_filter_returns_only_bot_assigned_tasks(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);

        $botTask = Task::factory()->create(['creator_id' => $user->id, 'assignee_type' => 'bot', 'assignee_id' => $bot->id]);
        Task::factory()->create(['creator_id' => $user->id, 'assignee_type' => 'user', 'assignee_id' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/tasks?bot_id[]=' . $bot->id)
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($botTask->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_list_resource_renders_bot_assignee_and_nulls_legacy_field(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['creator_id' => $user->id]);
        Task::factory()->create([
            'creator_id' => $user->id,
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
        ]);

        // The LIST resource (not just detail) must keep the legacy `assigned` null for a
        // bot-assigned task while exposing the polymorphic `assignee` — legacy consumers
        // that read `assigned` rely on this shape.
        $this->actingAs($user)
            ->getJson('/api/tasks?bot_id[]=' . $bot->id)
            ->assertOk()
            ->assertJsonPath('data.0.assigned', null)
            ->assertJsonPath('data.0.assignee.type', 'bot')
            ->assertJsonPath('data.0.assignee.id', $bot->id)
            ->assertJsonPath('data.0.assignee.is_bot', true);
    }
}
