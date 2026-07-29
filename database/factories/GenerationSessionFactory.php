<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Generator\Enums\GenerationSessionStatus;
use App\Modules\Generator\Models\GenerationSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GenerationSession>
 */
class GenerationSessionFactory extends Factory
{
    protected $model = GenerationSession::class;

    public function definition(): array
    {
        // A minimal draft over a `post` recipe: one text slot referenced by the post body, filled.
        return [
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'template_id' => (string) $this->faker->uuid(),
            'content_type' => 'post',
            'recipe_snapshot' => [
                'content_type' => 'post',
                'slots' => [
                    ['name' => 'topic', 'description' => 'The subject', 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]],
                ],
                'content' => [
                    'body' => ['markdown' => 'Write about ' . TemplateFactory::directive('slots.topic') . '.'],
                ],
            ],
            'slot_values' => ['topic' => 'launch day'],
            'results' => null,
            'status' => GenerationSessionStatus::Draft,
            'creator_id' => User::factory(),
        ];
    }

    /** A session snapshotting an explicit recipe ({content_type, slots, content}) + filled slot values. */
    public function snapshot(string $contentType, array $content, array $slots, array $slotValues = []): static
    {
        return $this->state(fn (): array => [
            'content_type' => $contentType,
            'recipe_snapshot' => ['content_type' => $contentType, 'slots' => $slots, 'content' => $content],
            'slot_values' => $slotValues,
        ]);
    }

    /** Force the session status (e.g. generating / ready / failed). */
    public function status(GenerationSessionStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
