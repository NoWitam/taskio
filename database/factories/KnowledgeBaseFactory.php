<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Variables\Enums\VariableType;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeBaseFactory extends Factory
{
    protected $model = KnowledgeBase::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'description' => $this->faker->sentence(),
            'charter' => null,
            'metadata_schema' => [],
            'language' => 'pl',
            'creator_id' => User::factory(),
        ];
    }

    /**
     * A base declaring metadata fields. Pass entries built with {@see self::field()} so a test never
     * has to hand-write a descriptor and drift from the type system.
     *
     * @param  array<int, array{key: string, label: string, descriptor: array<string, mixed>}>  $fields
     */
    public function withSchema(array $fields): static
    {
        return $this->state(fn () => ['metadata_schema' => array_values($fields)]);
    }

    public function withCharter(string $charter): static
    {
        return $this->state(fn () => ['charter' => $charter]);
    }

    /**
     * One schema FIELD entry `{key, label, descriptor}` — built from the shared type system, so a
     * factory-made schema is exactly what the write path would have accepted.
     *
     * @param  array<int, array{key: string, label: string}>  $options  for an enum base
     */
    public static function field(
        string $key,
        VariableType $type = VariableType::TEXT,
        ?string $label = null,
        bool $nullable = false,
        bool $array = false,
        array $options = [],
    ): array {
        return [
            'key' => $key,
            'label' => $label ?? ucfirst($key),
            'descriptor' => $type->descriptor(options: $options, nullable: $nullable, array: $array),
        ];
    }
}
