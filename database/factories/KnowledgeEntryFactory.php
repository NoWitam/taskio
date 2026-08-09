<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Support\KnowledgeDigest;
use App\Modules\Knowledge\Support\WikilinkParser;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeEntryFactory extends Factory
{
    protected $model = KnowledgeEntry::class;

    public function definition(): array
    {
        $title = ucfirst($this->faker->unique()->words(3, true));
        $content = $this->faker->paragraphs(2, true);

        return [
            'knowledge_base_id' => KnowledgeBase::factory(),
            'title' => $title,
            // Suffixed so a factory batch cannot collide on the per-base unique slug.
            'slug' => WikilinkParser::normalize($title) . '-' . $this->faker->unique()->numberBetween(1, 999999),
            'content' => $content,
            'metadata' => [],
            'status' => KnowledgeEntryStatus::DRAFT,
            'stale_at' => null,
            'position' => 0,
            'index_digest' => KnowledgeDigest::for($title, $content, []),
            'creator_id' => User::factory(),
        ];
    }

    /** An entry with an exact title + slug (the shape a wikilink test needs to point at). */
    public function slugged(string $slug, ?string $title = null): static
    {
        return $this->state(fn () => [
            'slug' => $slug,
            'title' => $title ?? ucfirst(str_replace('-', ' ', $slug)),
        ]);
    }

    public function withContent(string $content): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $content,
            'index_digest' => KnowledgeDigest::for(
                (string) ($attributes['title'] ?? ''),
                $content,
                is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
            ),
        ]);
    }

    public function status(KnowledgeEntryStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** Past the review date — the "this fact may have rotted" filter's fixture. */
    public function stale(): static
    {
        return $this->state(fn () => ['stale_at' => now()->subDay()]);
    }
}
