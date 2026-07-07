<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Enums\BotInboxState;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Models\BotAction;
use App\Modules\Bot\Services\BotInboxService;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\FakesBotExecutionAgent;
use Tests\TestCase;

/**
 * B7: the Bot Inbox — per-bot operational buckets + cap-exempt manual retry.
 */
class BotInboxTest extends TestCase
{
    use FakesBotExecutionAgent, RefreshDatabase;

    private function executingBot(User $owner): Bot
    {
        return Bot::factory()->executesTasks()->create(['creator_id' => $owner->id]);
    }

    private function botTask(Bot $bot, array $attributes = []): Task
    {
        return Task::factory()->create(array_merge([
            'creator_id' => $bot->creator_id,
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
            'status' => TaskStatus::TO_DO,
            'bot_run_state' => 'idle',
            'bot_runs_used' => 0,
        ], $attributes));
    }

    private function recordAction(Bot $bot, Task $task, BotActionType $type): void
    {
        BotAction::create(['bot_id' => $bot->id, 'task_id' => $task->id, 'type' => $type, 'status' => 'ok']);
    }

    // --- stateFor per bucket --------------------------------------------------

    public function test_state_done(): void
    {
        $bot = $this->executingBot(User::factory()->create());
        $task = $this->botTask($bot, ['status' => TaskStatus::DONE, 'bot_runs_used' => 1]);

        $this->assertSame(BotInboxState::Done, app(BotInboxService::class)->stateFor($task, null));
    }

    public function test_state_in_approval(): void
    {
        $bot = $this->executingBot(User::factory()->create());
        $task = $this->botTask($bot, ['status' => TaskStatus::IN_TEST, 'bot_runs_used' => 1]);

        $this->assertSame(BotInboxState::InApproval, app(BotInboxService::class)->stateFor($task, null));
    }

    public function test_state_waiting(): void
    {
        $bot = $this->executingBot(User::factory()->create());
        $task = $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_run_state' => 'waiting', 'bot_runs_used' => 1]);

        $this->assertSame(BotInboxState::Waiting, app(BotInboxService::class)->stateFor($task, null));
    }

    public function test_state_running(): void
    {
        $bot = $this->executingBot(User::factory()->create());
        $task = $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_run_state' => 'running', 'bot_runs_used' => 1]);

        $this->assertSame(BotInboxState::Running, app(BotInboxService::class)->stateFor($task, null));
    }

    public function test_state_failed_on_execution_failed(): void
    {
        $bot = $this->executingBot(User::factory()->create());
        $task = $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_run_state' => 'idle', 'bot_runs_used' => 1]);

        $this->assertSame(
            BotInboxState::Failed,
            app(BotInboxService::class)->stateFor($task, BotActionType::ExecutionFailed->value)
        );
    }

    public function test_state_failed_on_handed_over(): void
    {
        $bot = $this->executingBot(User::factory()->create());
        $task = $this->botTask($bot, ['status' => TaskStatus::TO_DO, 'bot_run_state' => 'idle', 'bot_runs_used' => 2]);

        $this->assertSame(
            BotInboxState::Failed,
            app(BotInboxService::class)->stateFor($task, BotActionType::HandedOver->value)
        );
    }

    public function test_state_revision(): void
    {
        $bot = $this->executingBot(User::factory()->create());
        // Returned to to_do after a run, latest action not a failure.
        $task = $this->botTask($bot, ['status' => TaskStatus::TO_DO, 'bot_run_state' => 'idle', 'bot_runs_used' => 1]);

        $this->assertSame(
            BotInboxState::Revision,
            app(BotInboxService::class)->stateFor($task, BotActionType::RevisionStarted->value)
        );
    }

    public function test_state_queued(): void
    {
        $bot = $this->executingBot(User::factory()->create());
        $task = $this->botTask($bot, ['status' => TaskStatus::TO_DO, 'bot_run_state' => 'idle', 'bot_runs_used' => 0]);

        $this->assertSame(BotInboxState::Queued, app(BotInboxService::class)->stateFor($task, null));
    }

    // --- endpoint -------------------------------------------------------------

    public function test_inbox_buckets_and_rows(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $queued = $this->botTask($bot); // queued
        $done = $this->botTask($bot, ['status' => TaskStatus::DONE, 'bot_runs_used' => 1]);
        $failed = $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_runs_used' => 1]);
        $this->recordAction($bot, $failed, BotActionType::ExecutionFailed);

        $response = $this->getJson("/api/bots/{$bot->id}/inbox")->assertOk();

        $response->assertJsonPath('buckets.queued', 1);
        $response->assertJsonPath('buckets.done', 1);
        $response->assertJsonPath('buckets.failed', 1);
        $response->assertJsonPath('buckets.running', 0);

        // Rows carry inbox_state and the full task-list fields.
        $states = collect($response->json('data'))->pluck('inbox_state')->sort()->values()->all();
        $this->assertEqualsCanonicalizing(['queued', 'done', 'failed'], $states);
        $this->assertArrayHasKey('title', $response->json('data.0'));
    }

    public function test_inbox_state_filter(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $this->botTask($bot); // queued
        $failed = $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_runs_used' => 1]);
        $this->recordAction($bot, $failed, BotActionType::ExecutionFailed);

        $response = $this->getJson("/api/bots/{$bot->id}/inbox?state=failed")->assertOk();

        $states = collect($response->json('data'))->pluck('inbox_state')->unique()->all();
        $this->assertSame(['failed'], $states);
    }

    public function test_state_filter_pushes_precedence_into_sql(): void
    {
        // Every bucket, filtered in SQL, must return EXACTLY the task stateFor() would
        // classify into it — the parity lock between the SQL precedence and stateFor().
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);
        $service = app(BotInboxService::class);

        $expected = [
            'done' => $this->botTask($bot, ['status' => TaskStatus::DONE, 'bot_runs_used' => 1])->id,
            'in_approval' => $this->botTask($bot, ['status' => TaskStatus::IN_TEST, 'bot_runs_used' => 1])->id,
            'waiting' => $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_run_state' => 'waiting', 'bot_runs_used' => 1])->id,
            'running' => $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_run_state' => 'running', 'bot_runs_used' => 1])->id,
        ];

        $failed = $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_runs_used' => 1]);
        $this->recordAction($bot, $failed, BotActionType::ExecutionFailed);
        $expected['failed'] = $failed->id;

        $revision = $this->botTask($bot, ['status' => TaskStatus::TO_DO, 'bot_runs_used' => 1]);
        $this->recordAction($bot, $revision, BotActionType::RevisionStarted);
        $expected['revision'] = $revision->id;

        $expected['queued'] = $this->botTask($bot, ['status' => TaskStatus::TO_DO, 'bot_runs_used' => 0])->id;

        foreach ($expected as $state => $id) {
            $ids = collect($service->tasks($bot, Request::create('/', 'GET', ['state' => $state]))->items())
                ->pluck('id')->all();

            $this->assertSame([$id], $ids, "Bucket [{$state}] should return exactly its own task.");
        }
    }

    public function test_state_filter_finds_rows_beyond_the_first_page(): void
    {
        // Regression: filtering used to happen AFTER cursorPaginate(15), so a bucket whose
        // rows sat past page 1 came back empty. The one FAILED task is the OLDEST → last in
        // the updated_at-desc order, behind 20 newer queued tasks (> one page).
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $failed = $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_runs_used' => 1]);
        $this->recordAction($bot, $failed, BotActionType::ExecutionFailed);
        Task::withoutGlobalScopes()->whereKey($failed->id)->update(['updated_at' => now()->subDay()]);

        for ($i = 0; $i < 20; $i++) {
            $this->botTask($bot);
        }

        $response = $this->getJson("/api/bots/{$bot->id}/inbox?state=failed")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$failed->id], $ids);
    }

    public function test_inbox_invalid_state_is_rejected(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $this->getJson("/api/bots/{$bot->id}/inbox?state=bogus")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['state']);
    }

    public function test_runs_this_month_counts_task_started_this_month_only(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);
        $task = $this->botTask($bot);

        // Two runs this month + one last month + a non-run action this month.
        $this->recordAction($bot, $task, BotActionType::TaskStarted);
        $this->recordAction($bot, $task, BotActionType::TaskStarted);
        $this->recordAction($bot, $task, BotActionType::Commented);
        $old = BotAction::create(['bot_id' => $bot->id, 'task_id' => $task->id, 'type' => BotActionType::TaskStarted, 'status' => 'ok']);
        $old->forceFill(['created_at' => now()->subMonthNoOverflow()->startOfMonth()])->save();

        $this->getJson("/api/bots/{$bot->id}/inbox")
            ->assertOk()
            ->assertJsonPath('runs_this_month', 2);
    }

    public function test_inbox_requires_auth(): void
    {
        $bot = Bot::factory()->create(['creator_id' => User::factory()->create()->id]);

        $this->getJson("/api/bots/{$bot->id}/inbox")->assertUnauthorized();
    }

    // --- retry ----------------------------------------------------------------

    public function test_retry_redispatches_a_failed_task(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $task = $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_runs_used' => 1]);
        $this->recordAction($bot, $task, BotActionType::ExecutionFailed);

        $this->scriptBotRun([['post_comment', ['text' => 'retry attempt']], ['finish']]);

        $this->postJson("/api/bots/{$bot->id}/tasks/{$task->id}/retry")->assertOk();

        // A fresh run started (task_started with trigger=retry) and it executed.
        $started = BotAction::where('task_id', $task->id)->where('type', BotActionType::TaskStarted->value)->get();
        $this->assertTrue($started->contains(fn ($a) => ($a->payload['trigger'] ?? null) === 'retry'));
        $this->assertEquals(TaskStatus::IN_TEST, $task->fresh()->status);
    }

    public function test_retry_is_cap_exempt(): void
    {
        config(['ai.max_runs_per_task' => 2]);

        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        // Already at the cap and failed.
        $task = $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_runs_used' => 2]);
        $this->recordAction($bot, $task, BotActionType::ExecutionFailed);

        $this->scriptBotRun([['post_comment', ['text' => 'over cap retry']], ['finish']]);

        $this->postJson("/api/bots/{$bot->id}/tasks/{$task->id}/retry")->assertOk();

        // The run counter still incremented past the cap (cost proxy stays honest).
        $this->assertSame(3, $task->fresh()->bot_runs_used);
        $this->assertEquals(TaskStatus::IN_TEST, $task->fresh()->status);
    }

    public function test_retry_rejected_on_non_failed_task(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        $task = $this->botTask($bot); // queued, not failed

        $this->postJson("/api/bots/{$bot->id}/tasks/{$task->id}/retry")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['task']);

        $this->assertDatabaseMissing('bot_actions', ['task_id' => $task->id, 'type' => BotActionType::TaskStarted->value]);
    }

    public function test_retry_rejected_when_task_not_assigned_to_this_bot(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);
        $otherBot = $this->executingBot($owner);

        // Task assigned to the OTHER bot, failed.
        $task = $this->botTask($otherBot, ['status' => TaskStatus::IN_PROGRESS, 'bot_runs_used' => 1]);
        $this->recordAction($otherBot, $task, BotActionType::ExecutionFailed);

        $this->postJson("/api/bots/{$bot->id}/tasks/{$task->id}/retry")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['task']);
    }

    public function test_retry_requires_auth(): void
    {
        // auth:sanctum rejects before the controller resolves the task, so a real task
        // isn't needed — a random id suffices to exercise the guard.
        $bot = Bot::factory()->create(['creator_id' => User::factory()->create()->id]);

        $this->postJson("/api/bots/{$bot->id}/tasks/" . \Illuminate\Support\Str::uuid() . '/retry')
            ->assertUnauthorized();
    }

    public function test_retry_forbidden_for_a_non_creator_member(): void
    {
        // Retry dispatches cap-exempt AI spend → gated to the bot OWNER, not any member.
        $owner = User::factory()->create();
        $bot = $this->executingBot($owner);
        $task = $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_runs_used' => 1]);
        $this->recordAction($bot, $task, BotActionType::ExecutionFailed);

        // A DIFFERENT authenticated user (not the creator) cannot retry.
        $this->actingAs(User::factory()->create())
            ->postJson("/api/bots/{$bot->id}/tasks/{$task->id}/retry")
            ->assertForbidden();

        $this->assertDatabaseMissing('bot_actions', ['task_id' => $task->id, 'type' => BotActionType::TaskStarted->value]);
    }

    public function test_retry_blocked_at_the_hard_cap(): void
    {
        config(['ai.max_runs_hard_cap' => 3]);

        $owner = User::factory()->create();
        $this->actingAs($owner);
        $bot = $this->executingBot($owner);

        // Already at the absolute hard ceiling and failed → retry is refused.
        $task = $this->botTask($bot, ['status' => TaskStatus::IN_PROGRESS, 'bot_runs_used' => 3]);
        $this->recordAction($bot, $task, BotActionType::ExecutionFailed);

        $this->postJson("/api/bots/{$bot->id}/tasks/{$task->id}/retry")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['task']);

        $this->assertSame(3, $task->fresh()->bot_runs_used);
        $this->assertDatabaseMissing('bot_actions', ['task_id' => $task->id, 'type' => BotActionType::TaskStarted->value]);
    }
}
