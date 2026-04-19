<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormContentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class FormSubmissionFactory extends Factory
{
    protected $model = FormSubmission::class;

    public function definition(): array
    {
        return [
            'form_id' => Form::factory()->enabled(),
            'submittable_type' => Form::class,
            'submittable_id' => function (array $attributes) {
                return $attributes['form_id'];
            },
            'data' => [
                'field' => $this->faker->word(),
            ],
            'form_content_version_id' => null,
            'approved_at' => now(), // Default: approved
            'creator_id' => User::factory(),
        ];
    }

    /**
     * Link the submission to a specific content version.
     */
    public function forVersion(FormContentVersion $version): static
    {
        return $this->state(fn (array $attributes) => [
            'form_content_version_id' => $version->id,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => now(),
        ]);
    }
}
