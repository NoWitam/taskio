<?php

namespace App\Modules\Bot\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Bot\Enums\BotInboxState;
use App\Modules\Bot\Http\Resources\BotInboxTaskResource;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Models\BotAction;
use App\Modules\Bot\Services\BotInboxService;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bot Inbox (B7): a per-bot operational view of its tasks bucketed by execution state,
 * plus a cap-exempt manual retry of a failed task.
 */
class BotInboxController extends Controller
{
    public function __construct(
        private BotInboxService $service,
    ) {}

    /** GET /bots/{bot}/inbox?state=&cursor= */
    public function index(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $request->validate([
            'state' => ['nullable', Rule::in(BotInboxState::keys())],
        ]);

        $paginator = $this->service->tasks($bot, $request);

        return response()->json([
            'data' => BotInboxTaskResource::collection($paginator->items()),
            'meta' => ['next_cursor' => $paginator->nextCursor()?->encode()],
            'buckets' => $this->service->bucketCounts($bot),
            'runs_this_month' => $this->service->runsThisMonth($bot),
        ]);
    }

    /** POST /bots/{bot}/tasks/{task}/retry */
    public function retry(Bot $bot, string $task): JsonResponse
    {
        // Retry is a WRITE that dispatches a cap-exempt AI run (real spend) — gated to the
        // bot OWNER, not any workspace member (see BotPolicy::retry).
        $this->authorize('retry', $bot);

        // Workspace-scoped resolution (a cross-workspace task 404s via the scope).
        $task = Task::query()->findOrFail($task);

        if ($task->assignee_type !== 'bot' || $task->assignee_id !== $bot->id) {
            throw ValidationException::withMessages([
                'task' => ['To zadanie nie jest przypisane do tego bota.'],
            ]);
        }

        if ($this->service->stateFor($task, $this->latestActionType($bot, $task)) !== BotInboxState::Failed) {
            throw ValidationException::withMessages([
                'task' => ['Ponowić można wyłącznie zadanie, które zakończyło się błędem lub zostało przekazane człowiekowi.'],
            ]);
        }

        // Even a manual retry is bounded by an absolute ceiling to prevent runaway spend.
        if ((int) $task->bot_runs_used >= (int) config('ai.max_runs_hard_cap', 20)) {
            throw ValidationException::withMessages([
                'task' => ['To zadanie osiągnęło twardy limit prób. Dalsze ponawianie jest zablokowane.'],
            ]);
        }

        $this->service->retry($bot, $task);

        return response()->json(['message' => 'Uruchomiono ponowną próbę wykonania zadania.']);
    }

    private function latestActionType(Bot $bot, Task $task): ?string
    {
        // Eloquent's value() applies the model's enum cast, so read ->value off it.
        // Tie-break on id (UUIDv7 = monotonic) so same-timestamp actions resolve deterministically.
        $type = BotAction::query()
            ->where('bot_id', $bot->id)
            ->where('task_id', $task->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('type');

        return $type?->value;
    }
}
