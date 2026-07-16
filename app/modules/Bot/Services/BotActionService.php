<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Models\BotAction;
use App\Modules\Tasks\Models\Task;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;

/**
 * Owns persistence of the bot-action audit log. The execution job records every step
 * through here so the DB write lives in one place (and can later be cached/replaced).
 */
class BotActionService
{
    public function record(
        Bot $bot,
        ?Task $task,
        BotActionType $type,
        array $payload = [],
        string $status = 'ok',
        ?string $error = null,
    ): BotAction {
        return BotAction::create([
            'bot_id' => $bot->id,
            'task_id' => $task?->id,
            'type' => $type,
            'payload' => $payload ?: null,
            'status' => $status,
            'error' => $error,
        ]);
    }

    /** Cursor-paginated action history for a single bot (newest first). */
    public function indexForBot(Bot $bot, Request $request): CursorPaginator
    {
        return BotAction::query()
            ->where('bot_id', $bot->id)
            ->when(
                $type = $request->enum('type', BotActionType::class),
                fn ($query) => $query->where('type', $type)
            )
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(15);
    }

    /** Action history for a single task (newest first), for the task detail / approval view. */
    public function indexForTask(Task $task, Request $request): CursorPaginator
    {
        return BotAction::query()
            ->where('task_id', $task->id)
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(15);
    }
}
