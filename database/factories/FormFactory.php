<?php

namespace Database\Factories;

use App\Enums\IconEnum;
use App\Models\User;
use App\Modules\Forms\Models\Form;
use Illuminate\Database\Eloquent\Factories\Factory;

class FormFactory extends Factory
{
    protected $model = Form::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'icon' => $this->faker->randomElement(IconEnum::cases()),
            'description' => $this->faker->optional()->paragraph(),
            'content' => [
                [
                    'type' => 'short_text',
                    'label' => 'Sample Field',
                    'required' => false,
                ]
            ],
            'is_anonymous' => false,
            'creator_id' => User::factory(),
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled_at' => now(),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled_at' => null,
        ]);
    }

    public function anonymous(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_anonymous' => true,
            'enabled_at' => now(), // Anonymous forms are auto-enabled
        ]);
    }

    public function withoutInputFields(): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => [
                [
                    'type' => 'heading',
                    'text' => 'Just a heading',
                ],
                [
                    'type' => 'text_block',
                    'text' => 'Some text',
                ],
            ],
        ]);
    }
}
