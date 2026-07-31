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
            'status' => BotStatus::INACTIVE,
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

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => BotStatus::INACTIVE]);
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

    /**
     * Bot with a configured VISUAL module. Defaults describe a usable identity (a descriptor +
     * aesthetic + the steerable wardrobe) with no images yet; $overrides layer on the file-bearing
     * parts (candidates / canonical_file_id / reference_file_id), which must reference files
     * actually owned by the bot.
     */
    public function withVisual(array $overrides = [], bool $enabled = true): static
    {
        return $this->state(fn () => ['visual' => array_merge([
            'enabled' => $enabled,
            'descriptor' => 'A cheerful red-haired illustrator in her late twenties.',
            'aesthetic' => 'Soft flat illustration, warm palette, gentle rim light.',
            'wardrobe' => 'A simple green summer dress.',
            'prohibitions' => [],
            'reference_file_id' => null,
            'candidates' => [],
            'canonical_file_id' => null,
            'prompt' => null,
        ], $overrides)]);
    }
}
