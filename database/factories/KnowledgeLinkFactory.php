<?php

namespace Database\Factories;

use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeLink;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeLinkFactory extends Factory
{
    protected $model = KnowledgeLink::class;

    public function definition(): array
    {
        return [
            // Declared AFTER the source entry so the closure sees a resolved id: the base is
            // denormalized off the entry, never invented.
            'from_entry_id' => KnowledgeEntry::factory(),
            'knowledge_base_id' => fn (array $attributes) => KnowledgeEntry::query()
                ->find($attributes['from_entry_id'])?->knowledge_base_id,
            'to_entry_id' => null,
            'target_slug' => $this->faker->unique()->slug(2),
            'source' => KnowledgeLinkSource::WIKILINK,
            'score' => null,
            'evidence' => null,
            'dismissed_at' => null,
        ];
    }

    /** A resolved edge between two existing entries (base taken from the source entry). */
    public function between(KnowledgeEntry $from, KnowledgeEntry $to): static
    {
        return $this->state(fn () => [
            'knowledge_base_id' => $from->knowledge_base_id,
            'from_entry_id' => $from->getKey(),
            'to_entry_id' => $to->getKey(),
            'target_slug' => $to->slug,
        ]);
    }

    /** An edge pointing at a slug that does not exist (yet). */
    public function ghost(KnowledgeEntry $from, string $targetSlug): static
    {
        return $this->state(fn () => [
            'knowledge_base_id' => $from->knowledge_base_id,
            'from_entry_id' => $from->getKey(),
            'to_entry_id' => null,
            'target_slug' => $targetSlug,
        ]);
    }

    public function source(KnowledgeLinkSource $source): static
    {
        return $this->state(fn () => ['source' => $source]);
    }
}
