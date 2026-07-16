<?php

namespace Database\Factories;

use App\Modules\Workflows\Enums\WorkflowRunOrigin;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowRunFactory extends Factory
{
    protected $model = WorkflowRun::class;

    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'state' => WorkflowRunState::PENDING,
            'origin' => WorkflowRunOrigin::EVENT,
            'trigger_type' => WorkflowTriggerType::FORM_SUBMITTED->value,
            'trigger_payload' => [],
            'context' => null,
            'depth' => 0,
            'origin_run_id' => null,
            'creator_id' => null,
            'started_at' => null,
            'finished_at' => null,
            'error' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'state' => WorkflowRunState::PENDING,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'state' => WorkflowRunState::RUNNING,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'state' => WorkflowRunState::COMPLETED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'state' => WorkflowRunState::FAILED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'error' => 'Step failed.',
        ]);
    }

    /** A run stuck in `running` with a stale started_at (reaper target). */
    public function stale(): static
    {
        return $this->state(fn () => [
            'state' => WorkflowRunState::RUNNING,
            'started_at' => now()->subHour(),
            'finished_at' => null,
        ]);
    }
}
