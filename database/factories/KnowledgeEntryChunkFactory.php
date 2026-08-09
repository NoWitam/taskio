<?php

namespace Database\Factories;

use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Chunks WITHOUT embeddings: the vector column is raw pgvector DDL with no Eloquent cast, so a
 * factory cannot meaningfully fill it and should not pretend to. B2a writes it with an explicit
 * expression; until then a chunk fixture exercises the metadata half (ordinals, digests, cascade).
 */
class KnowledgeEntryChunkFactory extends Factory
{
    protected $model = KnowledgeEntryChunk::class;

    public function definition(): array
    {
        $content = $this->faker->paragraph();

        return [
            'knowledge_entry_id' => KnowledgeEntry::factory(),
            // Denormalized off the entry (declared after it, so the closure sees a resolved id).
            'knowledge_base_id' => fn (array $attributes) => KnowledgeEntry::query()
                ->find($attributes['knowledge_entry_id'])?->knowledge_base_id,
            'ordinal' => 0,
            'heading_path' => null,
            'content' => $content,
            'char_start' => 0,
            'char_length' => mb_strlen($content),
            'digest' => hash('sha256', $content),
            'embedding_model' => null,
            'indexed_at' => null,
        ];
    }

    /** A chunk belonging to a specific entry, at a specific position in it. */
    public function forEntry(KnowledgeEntry $entry, int $ordinal = 0): static
    {
        return $this->state(fn () => [
            'knowledge_entry_id' => $entry->getKey(),
            'knowledge_base_id' => $entry->knowledge_base_id,
            'ordinal' => $ordinal,
        ]);
    }
}
