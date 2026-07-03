<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Enums\BotRunTrigger;
use App\Modules\Bot\Jobs\BotTaskExecutionJob;
use App\Modules\Bot\Models\Bot;
use App\Modules\Comments\DTOs\CommentDTO;
use App\Modules\Comments\Services\CommentService;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle/state manager for a bot's interactive runs on a task. Owns the run-state
 * machine on the tasks table:
 *
 *   idle ──claim──▶ running ──release(finished|null)──▶ idle
 *                       └──release(waiting)──▶ waiting ──claim(resume)──▶ running
 *
 * A run is CLAIMED with an ATOMIC conditional UPDATE:
 *
 *   UPDATE tasks
 *   SET bot_run_state='running', bot_runs_used = bot_runs_used + 1
 *   WHERE id=? AND bot_run_state IN ('idle','waiting') AND bot_runs_used < :cap
 *
 * Postgres locks the row for the UPDATE, so exactly one concurrent caller can flip
 * running -> and see affected=1. This is the concurrency guard: no two runs execute at
 * once, and it survives a non-sync queue (no check-then-act race). When the claim fails
 * because the run CAP is reached (state was claimable but bot_runs_used >= cap), the bot
 * hands the task over to a human. A claim that fails because a run is already active is
 * simply a no-op.
 */
class BotTaskRunManager
{
    public const STATE_IDLE = 'idle';

    public const STATE_RUNNING = 'running';

    public const STATE_WAITING = 'waiting';

    public function __construct(
        private BotActionService $actions,
        private CommentService $comments,
    ) {}

    /**
     * Attempt to start a run for a bot-assigned, execution-capable task and dispatch
     * the job on success. Idempotent and race-safe. $trigger records why the run began.
     */
    public function dispatch(Task $task, BotRunTrigger $trigger): void
    {
        if ($task->assignee_type !== 'bot' || $task->assignee_id === null) {
            return;
        }

        $bot = Bot::find($task->assignee_id);

        if ($bot === null || !$bot->canExecuteTasks()) {
            return;
        }

        $cap = (int) config('ai.max_runs_per_task', 5);

        // A human-initiated retry is exempt from the per-task cap, but NOT unbounded: it
        // still claims against an absolute hard ceiling so a retry→fail→retry loop can't
        // run up unlimited AI spend. A cap-reached failure here never hands over (the
        // operator asked for another run) — it's simply refused by the atomic claim.
        if ($trigger->isCapExempt()) {
            $hardCap = (int) config('ai.max_runs_hard_cap', 20);

            if (!$this->claim($task, $hardCap)) {
                return; // already running OR hard ceiling reached — no-op (race-safe).
            }

            BotTaskExecutionJob::dispatch($bot, $task->fresh(), $trigger);

            return;
        }

        if (!$this->claim($task, $cap)) {
            // Distinguish cap-reached (hand over) from already-running (no-op).
            if ($this->capReached($task, $cap)) {
                $this->handOver($bot, $task);
            }

            return;
        }

        // Re-fetch so the job sees the incremented run counter / running state.
        BotTaskExecutionJob::dispatch($bot, $task->fresh(), $trigger);
    }

    /**
     * Release the run-state when a run ends.
     *  - waiting  : ask_and_wait fired — await a human reply.
     *  - otherwise: back to idle (finished/failed/plain stop).
     */
    public function release(Task $task, ?string $outcome): void
    {
        $state = $outcome === BotTaskInteractionService::OUTCOME_WAITING
            ? self::STATE_WAITING
            : self::STATE_IDLE;

        Task::withoutGlobalScopes()
            ->whereKey($task->id)
            ->update(['bot_run_state' => $state]);
    }

    /** Whether the task is currently awaiting a human reply. */
    public function isWaiting(Task $task): bool
    {
        return $task->bot_run_state === self::STATE_WAITING;
    }

    /**
     * Atomic claim: flip an idle/waiting task to running and increment the run counter.
     * A non-null $cap adds the `bot_runs_used < cap` guard (normal runs); $cap === null
     * is cap-EXEMPT (a human retry) but still atomic and still increments the counter, so
     * the cost proxy stays honest. Returns true iff THIS call won the claim.
     */
    private function claim(Task $task, ?int $cap): bool
    {
        $affected = Task::withoutGlobalScopes()
            ->whereKey($task->id)
            ->whereIn('bot_run_state', [self::STATE_IDLE, self::STATE_WAITING])
            ->when($cap !== null, fn ($query) => $query->where('bot_runs_used', '<', $cap))
            ->update([
                'bot_run_state' => self::STATE_RUNNING,
                'bot_runs_used' => DB::raw('bot_runs_used + 1'),
            ]);

        return $affected === 1;
    }

    /** Whether the task has exhausted its run budget. */
    private function capReached(Task $task, int $cap): bool
    {
        $used = Task::withoutGlobalScopes()->whereKey($task->id)->value('bot_runs_used');

        return $used !== null && $used >= $cap;
    }

    /**
     * Hand the task over to a human: post a bot comment explaining it, record a
     * handed_over action, and leave the task where it is (do NOT advance).
     */
    private function handOver(Bot $bot, Task $task): void
    {
        $message = 'Osiągnąłem limit prób automatycznej realizacji tego zadania. '
            . 'Przekazuję je człowiekowi do dalszej obsługi.';

        $this->comments->create($task, CommentDTO::fromBot($bot, $message));
        $this->actions->record($bot, $task, BotActionType::HandedOver, status: 'handed_over');
    }
}
