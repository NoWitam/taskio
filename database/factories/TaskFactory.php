<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Tasks\Enums\TaskPriority;
use App\Modules\Tasks\Enums\TaskStatus;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'description' => null,
            'status' => TaskStatus::TO_DO,
            'priority' => $this->faker->randomElement(TaskPriority::cases()),
            'deadline' => $this->faker->optional()->dateTimeBetween('now', '+30 days'),
            'creator_id' => User::factory(),
            'assigned_id' => User::factory(),
        ];
    }
}
