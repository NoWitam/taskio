<?php

namespace Database\Factories;

use App\Modules\Workflows\Enums\WorkflowRunStepStatus;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Models\WorkflowRunStep;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowRunStepFactory extends Factory
{
    protected $model = WorkflowRunStep::class;

    public function definition(): array
    {
        return [
            'workflow_run_id' => WorkflowRun::factory(),
            'position' => 0,
            'type' => WorkflowStepType::CREATE_TASK->value,
            'key' => 'step_1',
            'status' => WorkflowRunStepStatus::SUCCEEDED,
            'payload' => [],
            'error' => null,
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => WorkflowRunStepStatus::SUCCEEDED,
            'error' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => WorkflowRunStepStatus::FAILED,
            'payload' => null,
            'error' => 'Step failed.',
        ]);
    }
}
