<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'status' => WorkflowStatus::INACTIVE,
            'description' => $this->faker->optional()->sentence(),
            'icon' => null,
            'trigger_type' => WorkflowTriggerType::FORM_SUBMITTED->value,
            'trigger_config' => [],
            'conditions' => [],
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'step_1', 'config' => []],
            ],
            'last_scheduled_run_at' => null,
            'next_due_at' => null,
            'creator_id' => User::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => WorkflowStatus::ACTIVE]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => WorkflowStatus::INACTIVE]);
    }

    /** A schedule-triggered workflow (daily 09:00 cadence). */
    public function scheduled(): static
    {
        return $this->state(fn () => [
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_config' => ['schedule' => ['family' => 'daily', 'params' => ['time' => '09:00']]],
        ]);
    }
}
