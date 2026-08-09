<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Enums\BotRunTrigger;
use App\Modules\Bot\Jobs\BotTaskExecutionJob;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Support\BotRunEstimate;
use App\Modules\Comments\DTOs\CommentDTO;
use App\Modules\Comments\Services\CommentService;
use App\Modules\Tasks\Models\Task;
use App\Modules\Variables\Contracts\MeteredAiCall;
use App\Modules\Variables\Exceptions\AiBudgetExceededException;
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
        private MeteredAiCall $meter,
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

        // BUDGET GATE, before the claim (the same posture the Generator's run manager takes): an
        // already-over-cap workspace never starts a run, so it never burns one of the task's five run
        // slots on work it cannot pay for and never leaves a task claimed as `running`. This is the
        // CHEAP question ("is there any money left"); the job asks the expensive one (a projection of
        // this specific run) once it has the assembled context to project against.
        //
        // No-op whenever the cap is off — the shipped default — so a workspace that never set a limit
        // sees byte-identical behaviour.
        if (!$this->affordable($bot, $task)) {
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

        // Clear the claim timestamp: the run has ended (idle) or handed off to a human
        // (waiting), so neither is an active run the reaper should ever count.
        Task::withoutGlobalScopes()
            ->whereKey($task->id)
            ->update(['bot_run_state' => $state, 'bot_run_started_at' => null]);
    }

    /**
     * Stale-claim reaper: release runs stuck in `running` past the configured timeout. A
     * worker killed mid-run (SIGKILL/OOM) never fires the job's failed() hook, so the task
     * would sit in `running` forever — and claim() only matches idle/waiting, so no future
     * run could ever recover it. This finds those tasks, releases the claim to idle, and
     * records an execution_failed so the inbox surfaces them as retryable.
     *
     * Runs on the CURRENTLY ACTIVE connection; the `bots:reap-stale-runs` command sweeps
     * the shared DB and every own-database tenant. Returns the number of runs reaped.
     */
    public function reapStaleRuns(): int
    {
        $timeout = max(60, (int) config('ai.bot_run_timeout', 900));
        $cutoff = now()->subSeconds($timeout);

        $stale = Task::withoutGlobalScopes()
            ->where('bot_run_state', self::STATE_RUNNING)
            ->where(function ($query) use ($cutoff) {
                // NULL only for rows claimed before this column existed (pre-deploy
                // orphans): post-deploy every claim stamps bot_run_started_at atomically.
                $query->whereNull('bot_run_started_at')
                    ->orWhere('bot_run_started_at', '<', $cutoff);
            })
            ->get();

        foreach ($stale as $task) {
            $this->release($task, null);

            $bot = $task->assignee_type === 'bot' && $task->assignee_id !== null
                ? Bot::find($task->assignee_id)
                : null;

            if ($bot !== null) {
                $this->actions->record(
                    $bot, $task, BotActionType::ExecutionFailed,
                    status: 'failed',
                    error: 'Run reaped: stuck in running past the timeout.',
                );
            }
        }

        return $stale->count();
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
                // Stamp the claim time atomically with the state flip so the reaper can
                // tell a genuinely stuck run from a slow-but-alive one.
                'bot_run_started_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * Whether the workspace can still pay for AI at all. False RECORDS the refusal against the task
     * (an `execution_failed` action carrying the budget reason) rather than failing silently: a bot
     * that simply stops doing anything, with nothing in its timeline, is the worst possible way for a
     * cap to be enforced — it looks exactly like a broken bot. The action also makes the task retryable
     * from the inbox, which is the right affordance: raising the cap or waiting for the month to roll
     * over makes the same run work.
     *
     * NO comment is posted (unlike the run-cap hand-over). A budget ceiling is an operator's concern,
     * not something to explain to everyone reading the task's conversation.
     */
    private function affordable(Bot $bot, Task $task): bool
    {
        try {
            $this->meter->assertWithinBudget(BotRunEstimate::CHANNEL);
        } catch (AiBudgetExceededException) {
            $this->actions->record(
                $bot, $task, BotActionType::ExecutionFailed,
                status: 'failed',
                error: __('bot.budget.run_refused'),
            );

            return false;
        }

        return true;
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
