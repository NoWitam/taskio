<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeEntryRevisionFactory extends Factory
{
    protected $model = KnowledgeEntryRevision::class;

    public function definition(): array
    {
        return [
            'knowledge_entry_id' => KnowledgeEntry::factory(),
            'title' => ucfirst($this->faker->words(3, true)),
            'content' => $this->faker->paragraph(),
            'metadata' => [],
            'change_note' => null,
            'author_id' => User::factory(),
            // Append-only: the model has no updated_at, so created_at is stamped explicitly.
            'created_at' => now(),
        ];
    }
}
