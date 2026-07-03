<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Bot\Enums\BotStatus;
use App\Modules\Bot\Models\Bot;
use Illuminate\Database\Eloquent\Factories\Factory;

class BotFactory extends Factory
{
    protected $model = Bot::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'status' => BotStatus::DRAFT,
            'description' => $this->faker->optional()->sentence(),
            'persona' => $this->faker->paragraph(),
            'style' => $this->faker->optional()->sentence(),
            'dictionary' => [],
            'phrases' => [],
            'prohibitions' => [],
            'task_execution' => null,
            'knowledge' => ['enabled' => false, 'entries' => []],
            'visual' => null,
            'audio' => null,
            'creator_id' => User::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => BotStatus::ACTIVE]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status' => BotStatus::DISABLED]);
    }

    public function executesTasks(): static
    {
        return $this->state(fn () => [
            'status' => BotStatus::ACTIVE,
            'task_execution' => [
                'enabled' => true,
                'tools' => [],
            ],
        ]);
    }

    /** Bot with an ENABLED knowledge module holding the given entries. */
    public function withKnowledge(array $entries, bool $enabled = true): static
    {
        return $this->state(fn () => ['knowledge' => ['enabled' => $enabled, 'entries' => $entries]]);
    }
}
