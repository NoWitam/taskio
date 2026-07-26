<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Models\CustomFunction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomFunction>
 */
class CustomFunctionFactory extends Factory
{
    protected $model = CustomFunction::class;

    public function definition(): array
    {
        // A minimal, valid text→text function: uppercase the input. No args.
        return [
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'description' => null,
            'input_type' => VariableType::TEXT->value,
            'args' => [],
            'return_type' => VariableType::TEXT->value,
            'body' => [['op' => 'text_uppercase']],
            'creator_id' => User::factory(),
        ];
    }

    /**
     * A function with an explicit signature + body (the shape the write-validator + tests drive).
     *
     * @param  array<int, array{name: string, description?: string|null, type: string}>  $args
     * @param  array<int, mixed>  $body
     */
    public function signature(string $inputType, array $args, string $returnType, array $body): static
    {
        return $this->state(fn (): array => [
            'input_type' => $inputType,
            'args' => $args,
            'return_type' => $returnType,
            'body' => $body,
        ]);
    }

    /** A function whose body is exactly $body (input/return default to text). */
    public function body(array $body): static
    {
        return $this->state(fn (): array => ['body' => $body]);
    }
}
