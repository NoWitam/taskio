<?php

namespace Database\Factories;

use App\Enums\IconEnum;
use App\Models\User;
use App\Modules\Approvals\Enums\ApproverType;
use App\Modules\Approvals\Models\ApprovalPipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApprovalPipelineFactory extends Factory
{
    protected $model = ApprovalPipeline::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(2),
            'icon' => $this->faker->randomElement(IconEnum::cases()),
            'description' => $this->faker->optional()->sentence(),
            'creator_id' => User::factory(),
        ];
    }

    public function withStages(int $count = 2): static
    {
        return $this->afterCreating(function (ApprovalPipeline $pipeline) use ($count) {
            for ($i = 1; $i <= $count; $i++) {
                $pipeline->stages()->create([
                    'name' => "Stage {$i}",
                    'icon' => $this->faker->randomElement(IconEnum::cases()),
                    'description' => null,
                    'approver_type' => ApproverType::User,
                    'approver_id' => User::factory()->create()->id,
                    'order' => $i,
                ]);
            }
        });
    }

    public function withAiStage(): static
    {
        return $this->afterCreating(function (ApprovalPipeline $pipeline) {
            $maxOrder = $pipeline->stages()->max('order') ?? 0;
            $pipeline->stages()->create([
                'name' => 'AI Review',
                'icon' => 'brain',
                'description' => null,
                'approver_type' => ApproverType::Ai,
                'approver_id' => null,
                'order' => $maxOrder + 1,
            ]);
        });
    }
}
