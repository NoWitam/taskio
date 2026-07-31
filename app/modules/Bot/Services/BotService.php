<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\DTOs\BotDTO;
use App\Modules\Bot\Enums\BotStatus;
use App\Modules\Bot\Models\Bot;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BotService
{
    public function index(Request $request): CursorPaginator
    {
        return Bot::query()
            ->search(['name', 'description'], $request->get('search'))
            // Opt-in `can_execute_tasks=1`: only bots that can actually RUN a task —
            // the SQL mirror of Bot::canExecuteTasks() (active + the task-execution
            // module enabled). The task-assignee pickers pass it so a bot that could
            // never start is not offerable in the first place; every other consumer
            // (filters, approver picker, ai-text author) is untouched by default.
            ->when(
                $request->boolean('can_execute_tasks'),
                fn ($query) => $query
                    ->where('status', BotStatus::ACTIVE)
                    ->where('task_execution->enabled', true)
            )
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(8);
    }

    public function create(BotDTO $dto): Bot
    {
        return DB::transaction(fn () => Bot::create(
            // A bot is always created INACTIVE; status is toggled only via changeStatus().
            $this->attributes($dto) + ['status' => BotStatus::INACTIVE]
        ));
    }

    public function update(Bot $bot, BotDTO $dto): Bot
    {
        return DB::transaction(function () use ($bot, $dto) {
            // update() never touches status — the status endpoint owns that transition.
            $bot->update($this->attributes($dto));

            return $bot->refresh();
        });
    }

    /** Toggle a bot's status (active|inactive). The only path that mutates status. */
    public function changeStatus(Bot $bot, BotStatus $status): Bot
    {
        return DB::transaction(function () use ($bot, $status) {
            $bot->update(['status' => $status]);

            return $bot->refresh();
        });
    }

    public function delete(Bot $bot): void
    {
        $bot->delete();
    }

    public function restore(Bot $bot): Bot
    {
        $bot->restore();

        return $bot;
    }

    /**
     * Map the DTO onto persisted columns. task_execution and visual are only written when
     * supplied (null leaves the stored config untouched on update) — for `visual` that is a
     * data-safety property, not a convenience: its candidates/canonical are filled in by the
     * async identity generator, so an ordinary save that omits the module must not blank them.
     */
    private function attributes(BotDTO $dto): array
    {
        $attributes = [
            'name' => $dto->name,
            'description' => $dto->description,
            'icon' => $dto->icon,
            'persona' => $dto->persona,
            'style' => $dto->style,
            'dictionary' => $dto->dictionary,
            'phrases' => $dto->phrases,
            'prohibitions' => $dto->prohibitions,
            'knowledge' => $dto->knowledge,
        ];

        if ($dto->taskExecution !== null) {
            $attributes['task_execution'] = $dto->taskExecution;
        }

        if ($dto->visual !== null) {
            $attributes['visual'] = $dto->visual;
        }

        return $attributes;
    }
}
