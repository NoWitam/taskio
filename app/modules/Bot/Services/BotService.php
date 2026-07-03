<?php

namespace App\Modules\Bot\Services;

use App\Modules\Bot\DTOs\BotDTO;
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
            ->orderBy('created_at', 'desc')
            ->cursorPaginate(8);
    }

    public function create(BotDTO $dto): Bot
    {
        return DB::transaction(fn () => Bot::create($this->attributes($dto)));
    }

    public function update(Bot $bot, BotDTO $dto): Bot
    {
        return DB::transaction(function () use ($bot, $dto) {
            $bot->update($this->attributes($dto));

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
     * Map the DTO onto persisted columns. task_execution is only written when
     * supplied (null leaves the stored config untouched on update).
     */
    private function attributes(BotDTO $dto): array
    {
        $attributes = [
            'name' => $dto->name,
            'status' => $dto->status,
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

        return $attributes;
    }
}
