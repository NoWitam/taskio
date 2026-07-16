<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotTaskRunManager;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stale-claim reaper (hardening). A worker killed mid-run (SIGKILL/OOM) never fires the
 * job's failed() hook, so a task can strand in `bot_run_state = running` forever — and the
 * atomic claim only matches idle/waiting, so nothing else could recover it. The reaper
 * releases such runs to idle and records a failure so the inbox surfaces them as retryable.
 */
class BotStaleRunReaperTest extends TestCase
{
    use RefreshDatabase;

    private function executingBot(): Bot
    {
        return Bot::factory()->executesTasks()->create(['creator_id' => User::factory()->create()->id]);
    }

    private function runningTask(Bot $bot, ?\DateTimeInterface $startedAt): Task
    {
        return Task::factory()->create([
            'creator_id' => $bot->creator_id,
            'assignee_type' => 'bot',
            'assignee_id' => $bot->id,
            'status' => TaskStatus::IN_PROGRESS,
            'bot_run_state' => 'running',
            'bot_runs_used' => 1,
            'bot_run_started_at' => $startedAt,
        ]);
    }

    public function test_it_reaps_a_run_stuck_past_the_timeout(): void
    {
        config(['ai.bot_run_timeout' => 900]);
        $bot = $this->executingBot();
        $task = $this->runningTask($bot, now()->subHour());

        $reaped = app(BotTaskRunManager::class)->reapStaleRuns();

        $this->assertSame(1, $reaped);
        $task->refresh();
        $this->assertSame('idle', $task->bot_run_state);
        $this->assertNull($task->bot_run_started_at);
        // Recorded as a failure so the inbox shows it as failed → retryable.
        $this->assertDatabaseHas('bot_actions', [
            'task_id' => $task->id,
            'type' => BotActionType::ExecutionFailed->value,
            'status' => 'failed',
        ]);
    }

    public function test_it_leaves_a_fresh_run_alone(): void
    {
        config(['ai.bot_run_timeout' => 900]);
        $bot = $this->executingBot();
        $task = $this->runningTask($bot, now()->subSeconds(10));

        $this->assertSame(0, app(BotTaskRunManager::class)->reapStaleRuns());
        $this->assertSame('running', $task->fresh()->bot_run_state);
        $this->assertDatabaseMissing('bot_actions', [
            'task_id' => $task->id,
            'type' => BotActionType::ExecutionFailed->value,
        ]);
    }

    public function test_it_reaps_a_pre_deploy_orphan_with_null_started_at(): void
    {
        // A row claimed before the timestamp column existed: definitionally stale.
        $bot = $this->executingBot();
        $task = $this->runningTask($bot, null);

        $this->assertSame(1, app(BotTaskRunManager::class)->reapStaleRuns());
        $this->assertSame('idle', $task->fresh()->bot_run_state);
    }

    public function test_it_ignores_idle_and_waiting_tasks(): void
    {
        config(['ai.bot_run_timeout' => 900]);
        $bot = $this->executingBot();

        // Old, but not `running` — the reaper must never touch these.
        $idle = $this->runningTask($bot, now()->subHour());
        $idle->forceFill(['bot_run_state' => 'idle'])->save();
        $waiting = $this->runningTask($bot, now()->subHour());
        $waiting->forceFill(['bot_run_state' => 'waiting'])->save();

        $this->assertSame(0, app(BotTaskRunManager::class)->reapStaleRuns());
        $this->assertSame('idle', $idle->fresh()->bot_run_state);
        $this->assertSame('waiting', $waiting->fresh()->bot_run_state);
    }

    public function test_command_reports_the_number_reaped(): void
    {
        config(['ai.bot_run_timeout' => 900]);
        $bot = $this->executingBot();
        $this->runningTask($bot, now()->subHour());

        $this->artisan('bots:reap-stale-runs')
            ->expectsOutputToContain('Reaped 1 stale bot run(s).')
            ->assertSuccessful();
    }
}
