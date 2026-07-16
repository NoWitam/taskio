<?php

namespace Database\Factories;

use App\Enums\IconEnum;
use App\Models\User;
use App\Modules\FilterTabs\Models\FilterTab;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Modules\FilterTabs\Models\FilterTab>
 */
class FilterTabFactory extends Factory
{
    protected $model = FilterTab::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'context' => $this->faker->randomElement(['tasks', 'forms', 'submissions']),
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'icon' => $this->faker->randomElement(IconEnum::cases()),
            'filters' => [
                'status' => $this->faker->randomElement(['to_do', 'in_progress', 'done']),
            ],
            'sort_order' => 0,
        ];
    }

    public function context(string $context): static
    {
        return $this->state(fn () => ['context' => $context]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id]);
    }
}
