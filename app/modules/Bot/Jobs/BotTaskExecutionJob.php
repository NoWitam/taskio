<?php

namespace App\Modules\Bot\Jobs;

use App\Modules\Bot\Agents\BotTaskExecutionAgent;
use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Enums\BotRunTrigger;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Services\BotActionService;
use App\Modules\Bot\Services\BotTaskContextBuilder;
use App\Modules\Bot\Services\BotTaskInteractionService;
use App\Modules\Bot\Services\BotTaskRunManager;
use App\Modules\Comments\Services\CommentService;
use App\Modules\Forms\Services\FormSubmissionService;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One INTERACTIVE run of a bot against a task (B4). Tenancy is carried across the queue
 * boundary by QueueTenancy (mirrors ProcessAiApprovalJob).
 *
 * The run is already CLAIMED (task.bot_run_state = running, bot_runs_used incremented)
 * before this job is dispatched (see BotTaskRunManager) — so the job just executes and
 * finalizes. The agent READS its context and ACTS through tools (post_comment,
 * fill_form, ask_and_wait, finish). A run ends when the agent calls a terminal tool or
 * simply stops:
 *   - finish   -> task submitted to in_test, run-state released to idle,
 *   - ask_and_wait -> task stays in_progress, run-state set to waiting (a human reply
 *     resumes it),
 *   - otherwise -> run-state released to idle (task stays in_progress).
 *
 * On failure: record execution_failed, release the run-state to idle, do NOT advance.
 */
class BotTaskExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public Bot $bot,
        public Task $task,
        public BotRunTrigger $trigger = BotRunTrigger::Initial,
    ) {}

    public function handle(
        TaskService $taskService,
        CommentService $commentService,
        FormSubmissionService $submissionService,
        BotActionService $actions,
        BotTaskContextBuilder $contextBuilder,
        BotTaskRunManager $runManager,
    ): void {
        $bot = $this->bot;
        $task = $this->task->fresh();

        if ($task === null) {
            return;
        }

        // Record how this run began (initial / resumed / revision_started) + move the
        // task into progress. task_started carries the run number for the timeline.
        $actions->record($bot, $task, BotActionType::TaskStarted, [
            'run' => $task->bot_runs_used,
            'trigger' => $this->trigger->value,
        ]);

        if ($this->trigger === BotRunTrigger::Resume) {
            $actions->record($bot, $task, BotActionType::Resumed, ['run' => $task->bot_runs_used]);
        } elseif ($this->trigger === BotRunTrigger::Revision) {
            $actions->record($bot, $task, BotActionType::RevisionStarted, ['run' => $task->bot_runs_used]);
        }

        $taskService->botStart($task);

        $interaction = new BotTaskInteractionService(
            $bot, $task, $commentService, $submissionService, $taskService, $actions,
        );

        try {
            $context = $contextBuilder->build($task, $bot);

            // Resolve through the container (named args) so the agent is overridable in
            // tests (see ScriptedBotExecutionAgent). In production this builds the real
            // interactive agent.
            $agent = app()->makeWith(BotTaskExecutionAgent::class, [
                'bot' => $bot,
                'task' => $task,
                'context' => $context,
                'interaction' => $interaction,
            ]);

            $agent->prompt(
                prompt: 'Zajmij się zadaniem. Użyj narzędzi. Zakończ przez finish lub ask_and_wait.',
                provider: config('ai.provider'),
                model: config('ai.model'),
            );

            $runManager->release($task, $interaction->outcome());
        } catch (\Throwable $e) {
            Log::error("Bot task execution failed for task {$task->id}: {$e->getMessage()}");
            $actions->record($bot, $task, BotActionType::ExecutionFailed, status: 'failed', error: $e->getMessage());
            $runManager->release($task, null);
        }
    }

    /**
     * Last-resort claim release (S1). handle()'s try/catch covers agent/tool throwables,
     * but a job timeout or an exception escaping before/around handle() would otherwise
     * strand the task in `running` forever — and claim() only matches idle/waiting, so
     * no future run could ever recover it. $tries = 1, so this fires on first failure.
     * (A hard SIGKILL/OOM still bypasses this — do not enable an async worker without
     * a stale-claim reaper.)
     */
    public function failed(\Throwable $e): void
    {
        $task = $this->task->fresh();

        if ($task === null) {
            return;
        }

        Log::error("Bot task execution job failed for task {$task->id}: {$e->getMessage()}");

        app(BotActionService::class)->record(
            $this->bot, $task, BotActionType::ExecutionFailed, status: 'failed', error: $e->getMessage(),
        );

        app(BotTaskRunManager::class)->release($task, null);
    }
}
