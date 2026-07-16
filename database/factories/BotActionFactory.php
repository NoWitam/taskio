<?php

namespace Database\Factories;

use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Models\Bot;
use App\Modules\Bot\Models\BotAction;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class BotActionFactory extends Factory
{
    protected $model = BotAction::class;

    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'task_id' => Task::factory(),
            'type' => BotActionType::TaskStarted,
            'payload' => null,
            'status' => 'ok',
            'error' => null,
            'creator_id' => null,
        ];
    }
}
