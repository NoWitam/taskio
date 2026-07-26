<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Models\Constant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ConstantFactory extends Factory
{
    protected $model = Constant::class;

    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'name' => $name,
            'key' => Str::slug($name, '_') . '_' . $this->faker->unique()->numberBetween(1, 999999),
            'descriptor' => VariableType::TEXT->descriptor(),
            'value' => $this->faker->sentence(),
            'creator_id' => User::factory(),
        ];
    }

    /** A text literal constant with the given key/value. */
    public function text(string $key, string $value): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => VariableType::TEXT->descriptor(),
            'value' => $value,
        ]);
    }

    /** A number literal constant. */
    public function number(string $key, int|float $value): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => VariableType::NUMBER->descriptor(),
            'value' => $value,
        ]);
    }

    /** A boolean literal constant. */
    public function boolean(string $key, bool $value): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => VariableType::BOOLEAN->descriptor(),
            'value' => $value,
        ]);
    }

    /** A date literal constant (ISO date string). */
    public function date(string $key, string $value): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => VariableType::DATE->descriptor(),
            'value' => $value,
        ]);
    }

    /**
     * An enum literal constant. $options is a list of {key,label} (or bare values); $value must be
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
            'descriptor' => VariableType::ENUM->descriptor($normalized),
            'value' => $value,
        ]);
    }

    /**
     * An array<text> literal constant (e.g. hashtags).
     *
     * @param  array<int, string>  $value
     */
    public function textList(string $key, array $value): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => VariableType::TEXT->descriptor(array: true),
            'value' => array_values($value),
        ]);
    }

    /**
     * An OBJECT literal constant (a structured constant, e.g. a brand/company record). $fields is the
     * descriptor's ordered `{key, label, descriptor}` list — build each entry with self::field() — and
     * $value the matching `{key: literal}` map.
     *
     * @param  array<int, array{key: string, label: string, descriptor: array<string, mixed>}>  $fields
     * @param  array<string, mixed>  $value
     */
    public function object(string $key, array $fields, array $value): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => VariableType::OBJECT->descriptor(fields: $fields, array: false),
            'value' => $value,
        ]);
    }

    /**
     * An ARRAY<object> literal constant (a repeater-like structured LIST, e.g. line items). $fields is the
     * element object's ordered `{key, label, descriptor}` list — build each with self::field() — and $value
     * a LIST of matching `{key: literal}` rows.
     *
     * @param  array<int, array{key: string, label: string, descriptor: array<string, mixed>}>  $fields
     * @param  array<int, array<string, mixed>>  $value
     */
    public function objectList(string $key, array $fields, array $value): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'descriptor' => VariableType::OBJECT->descriptor(fields: $fields, array: true),
            'value' => array_values($value),
        ]);
    }

    /**
     * One object-descriptor FIELD entry `{key, label, descriptor}` — the child shape both an object
     * constant's descriptor and the catalog's container descriptors use.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array{key: string, label: string, descriptor: array<string, mixed>}
     */
    public static function field(string $key, array $descriptor, ?string $label = null): array
    {
        return ['key' => $key, 'label' => $label ?? ucfirst($key), 'descriptor' => $descriptor];
    }
}
