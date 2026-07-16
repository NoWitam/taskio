<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Enums\BotRunTrigger;
use App\Modules\Bot\Models\Bot;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;

/**
 * Entry points for triggering a bot's interactive runs on a task. The atomic claim /
 * run-cap / hand-over lifecycle lives in BotTaskRunManager; this service just decides
 * WHEN a trigger applies (initial assignment, human reply, approval reject).
 */
class BotTaskExecutionService
{
    public function __construct(
        private BotActionService $actions,
        private BotTaskRunManager $runManager,
    ) {}

    /**
     * INITIAL run: a task freshly assigned to an execution-capable bot. Only TO_DO
     * tasks are eligible (a task already in progress/test/done is not a fresh start).
     * Race-safe and capped via the run manager.
     */
    public function maybeDispatch(Task $task): void
    {
        if ($task->status !== TaskStatus::TO_DO) {
            return;
        }

        $this->runManager->dispatch($task, BotRunTrigger::Initial);
    }

    /**
     * RESUME run: a HUMAN posted a comment on a task whose bot assignee is waiting for a
     * reply. Re-dispatches with fully refreshed context. The waiting-state gate ensures
     * one resume per genuine wait; the run manager's atomic claim prevents double-runs.
     */
    public function resumeFromHumanReply(Task $task): void
    {
        if (!$this->runManager->isWaiting($task)) {
            return;
        }

        $this->runManager->dispatch($task, BotRunTrigger::Resume);
    }

    /**
     * REVISION run: an approval was rejected and the restored original assignee is a
     * bot. The rejection reasons are in the approval history (context builder surfaces
     * them), so the bot re-runs to address them.
     */
    public function reviseAfterReject(Task $task): void
    {
        $this->runManager->dispatch($task, BotRunTrigger::Revision);
    }

    /**
     * Record a `marked_done` action for the bot that executed this task, when the task
     * completes its approval and reaches done. The executing bot is the ORIGINAL
     * assignee snapshotted in the approval context (the task entered approval as
     * bot-assigned). No-op for user-executed tasks.
     *
     * @param  array<string, mixed>  $context  the approval process context
     */
    public function recordMarkedDoneFromContext(Task $task, array $context): void
    {
        if (($context['original_assignee_type'] ?? null) !== 'bot') {
            return;
        }

        $botId = $context['original_assignee_id'] ?? null;

        if ($botId === null) {
            return;
        }

        $bot = Bot::find($botId);

        if ($bot === null) {
            return;
        }

        $this->actions->record($bot, $task, BotActionType::MarkedDone);
    }
}
