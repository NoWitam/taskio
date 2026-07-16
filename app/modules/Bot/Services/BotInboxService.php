<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Enums\BotInboxState;
use App\Modules\Bot\Enums\BotRunTrigger;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Models\BotAction;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Bot Inbox (B7): an operational view of a bot's tasks bucketed by execution state, plus
 * a cap-exempt manual retry. Owns the inbox_state derivation, the bucketed query, the
 * bucket counts, and the monthly run count (cost proxy).
 */
class BotInboxService
{
    public function __construct(
        private BotTaskRunManager $runManager,
    ) {}

    /**
     * Derive the single inbox_state for a task the given bot is assigned to.
     *
     * Precedence (first match wins):
     *   done        status == done
     *   in_approval status == in_test (deliverable submitted, awaiting approval)
     *   waiting     bot_run_state == waiting (asked a question, awaiting a human reply)
     *   running     bot_run_state == running (a run is executing)
     *   failed      latest bot_action is execution_failed OR handed_over AND the task has
     *               not since advanced (run_state idle, still to_do/in_progress) — retryable
     *   revision    to_do AND bot_runs_used > 0 (was worked on, then returned) and not failed
     *   queued      everything else (not yet run: runs_used == 0, run_state idle)
     *
     * $latestActionType is the task's most recent bot_action `type` (string) or null,
     * passed in so the caller can batch-load it (avoid N+1).
     */
    public function stateFor(Task $task, ?string $latestActionType): BotInboxState
    {
        if ($task->status === TaskStatus::DONE) {
            return BotInboxState::Done;
        }

        if ($task->status === TaskStatus::IN_TEST) {
            return BotInboxState::InApproval;
        }

        if ($task->bot_run_state === BotTaskRunManager::STATE_WAITING) {
            return BotInboxState::Waiting;
        }

        if ($task->bot_run_state === BotTaskRunManager::STATE_RUNNING) {
            return BotInboxState::Running;
        }

        // Failed / handed-over and not since advanced (idle, still to_do/in_progress).
        $isFailedAction = in_array($latestActionType, [
            BotActionType::ExecutionFailed->value,
            BotActionType::HandedOver->value,
        ], true);

        if ($isFailedAction && $task->bot_run_state === BotTaskRunManager::STATE_IDLE) {
            return BotInboxState::Failed;
        }

        // Returned for another pass (rejected/returned to to_do after at least one run).
        if ($task->status === TaskStatus::TO_DO && (int) $task->bot_runs_used > 0) {
            return BotInboxState::Revision;
        }

        return BotInboxState::Queued;
    }

    /**
     * Cursor-paginated inbox tasks for a bot, optionally filtered to one bucket. Each
     * task carries its computed inbox_state (attached to the model attributes) and the
     * latest-action type is selected inline (no N+1).
     */
    public function tasks(Bot $bot, Request $request): CursorPaginator
    {
        $state = $request->enum('state', BotInboxState::class);

        $query = $this->bucketedQuery($bot)->orderByDesc('updated_at');

        // Push the bucket filter into SQL BEFORE paginating. Filtering the derived state
        // after cursorPaginate() (the old approach) yielded short/empty pages, since a
        // bucket's rows can sit beyond the first page. The SQL precedence mirrors
        // stateFor() exactly — a parity test locks the two together.
        if ($state !== null) {
            $this->applyStateFilter($query, $state);
        }

        $paginator = $query->cursorPaginate(15)->withQueryString();

        // Attach the derived state for the resource (cheap: only the page's rows).
        $paginator->getCollection()->each(fn (Task $task) => $task->setAttribute(
            'inbox_state',
            $this->stateFor($task, $task->getAttribute('latest_action_type'))->value
        ));

        return $paginator;
    }

    /**
     * Bucket counts for the bot's tasks. Computed by grouping on the cheap stored signals
     * and folding in the latest-action-type once, rather than materializing every row
     * through the resource.
     *
     * @return array<string, int> keyed by every BotInboxState (missing buckets = 0)
     */
    public function bucketCounts(Bot $bot): array
    {
        $counts = array_fill_keys(BotInboxState::keys(), 0);

        // One query pulls the tasks + the inlined latest-action type (no per-row queries);
        // the derivation folds them into bucket counts in memory.
        $this->baseQuery($bot)
            ->get()
            ->each(function (Task $task) use (&$counts) {
                $counts[$this->stateFor($task, $task->getAttribute('latest_action_type'))->value]++;
            });

        return $counts;
    }

    /**
     * Runs this bot performed in the CURRENT calendar month (each `task_started` action =
     * one AI invocation = the cost proxy).
     */
    public function runsThisMonth(Bot $bot): int
    {
        return BotAction::query()
            ->where('bot_id', $bot->id)
            ->where('type', BotActionType::TaskStarted)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * Human-initiated retry of a FAILED task: dispatch a fresh, cap-exempt run.
     */
    public function retry(Bot $bot, Task $task): void
    {
        $this->runManager->dispatch($task, BotRunTrigger::Retry);
    }

    /**
     * The bot's tasks wrapped in a DERIVED TABLE so the correlated `latest_action_type`
     * becomes a real column the bucket filter can reference in a WHERE (Postgres cannot
     * filter on a SELECT alias). The inner query keeps the global scopes (soft-deletes,
     * workspace); the outer drops them so they are not re-applied to the derived table.
     */
    private function bucketedQuery(Bot $bot): Builder
    {
        return Task::query()->withoutGlobalScopes()->fromSub($this->baseQuery($bot), 'tasks');
    }

    /**
     * Constrain the query to one inbox bucket, in SQL. Each arm is a FULL predicate that
     * encodes the stateFor() precedence (higher-precedence buckets are excluded), so the
     * arms are mutually exclusive and their union is every task — mirroring the derived
     * state exactly. Kept in lockstep with stateFor() by a parity test.
     */
    private function applyStateFilter(Builder $query, BotInboxState $state): void
    {
        $closed = [TaskStatus::DONE->value, TaskStatus::IN_TEST->value];
        $failedActions = [BotActionType::ExecutionFailed->value, BotActionType::HandedOver->value];
        $idle = BotTaskRunManager::STATE_IDLE;

        // "latest action is not a failure" must also match rows with no action at all
        // (NULL): `latest_action_type NOT IN (...)` is NULL — i.e. false — for those.
        $notFailed = fn (Builder $q) => $q
            ->whereNotIn('latest_action_type', $failedActions)
            ->orWhereNull('latest_action_type');

        match ($state) {
            BotInboxState::Done => $query->where('status', TaskStatus::DONE->value),
            BotInboxState::InApproval => $query->where('status', TaskStatus::IN_TEST->value),
            BotInboxState::Waiting => $query
                ->whereNotIn('status', $closed)
                ->where('bot_run_state', BotTaskRunManager::STATE_WAITING),
            BotInboxState::Running => $query
                ->whereNotIn('status', $closed)
                ->where('bot_run_state', BotTaskRunManager::STATE_RUNNING),
            BotInboxState::Failed => $query
                ->whereNotIn('status', $closed)
                ->where('bot_run_state', $idle)
                ->whereIn('latest_action_type', $failedActions),
            BotInboxState::Revision => $query
                ->where('status', TaskStatus::TO_DO->value)
                ->where('bot_runs_used', '>', 0)
                ->where('bot_run_state', $idle)
                ->where($notFailed),
            BotInboxState::Queued => $query
                ->whereNotIn('status', $closed)
                ->where('bot_run_state', $idle)
                ->where($notFailed)
                ->whereNot(fn (Builder $q) => $q
                    ->where('status', TaskStatus::TO_DO->value)
                    ->where('bot_runs_used', '>', 0)),
        };
    }

    /** The bot's tasks, with the latest bot_action type selected inline (no N+1). */
    private function baseQuery(Bot $bot): Builder
    {
        // Tie-break on id (UUIDv7 = monotonic) so two same-timestamp actions resolve to a
        // deterministic "latest" (avoids a flaky failed-vs-other misclassification).
        $latestActionType = BotAction::query()
            ->select('type')
            ->whereColumn('bot_actions.task_id', 'tasks.id')
            ->where('bot_actions.bot_id', $bot->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1);

        return Task::query()
            ->where('assignee_type', 'bot')
            ->where('assignee_id', $bot->id)
            ->addSelect('tasks.*')
            ->addSelect(['latest_action_type' => $latestActionType]);
    }
}
