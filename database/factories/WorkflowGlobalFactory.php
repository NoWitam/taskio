<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Workflows\Enums\WorkflowVariableType;
use App\Modules\Workflows\Models\WorkflowGlobal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WorkflowGlobalFactory extends Factory
{
    protected $model = WorkflowGlobal::class;

    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'name' => $name,
            'key' => Str::slug($name, '_') . '_' . $this->faker->unique()->numberBetween(1, 999999),
            'descriptor' => WorkflowVariableType::TEXT->descriptor(),
            'value' => $this->faker->sentence(),
            'creator_id' => User::factory(),
        ];
    }

    /** A text literal global with the given key/value. */
    public function text(string $key, string $value): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => WorkflowVariableType::TEXT->descriptor(),
            'value' => $value,
        ]);
    }

    /** A number literal global. */
    public function number(string $key, int|float $value): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => WorkflowVariableType::NUMBER->descriptor(),
            'value' => $value,
        ]);
    }

    /** A boolean literal global. */
    public function boolean(string $key, bool $value): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => WorkflowVariableType::BOOLEAN->descriptor(),
            'value' => $value,
        ]);
    }

    /** A date literal global (ISO date string). */
    public function date(string $key, string $value): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => WorkflowVariableType::DATE->descriptor(),
            'value' => $value,
        ]);
    }

    /**
     * An enum literal global. $options is a list of {key,label} (or bare values); $value must be
     * one of the option keys.
     *
     * @param  array<int, array{key: string, label: string}|string>  $options
     */
    public function enum(string $key, array $options, string $value): static
    {
        $normalized = array_map(
            fn ($option) => is_array($option) ? $option : ['key' => (string) $option, 'label' => (string) $option],
            $options,
        );

        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => WorkflowVariableType::ENUM->descriptor($normalized),
            'value' => $value,
        ]);
    }

    /**
     * An array<text> literal global (e.g. hashtags).
     *
     * @param  array<int, string>  $value
     */
    public function textList(string $key, array $value): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => WorkflowVariableType::TEXT->descriptor(array: true),
            'value' => array_values($value),
        ]);
    }
}
