<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Knowledge\Enums\KnowledgeDraftSessionStatus;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeDraftSessionFactory extends Factory
{
    protected $model = KnowledgeDraftSession::class;

    public function definition(): array
    {
        return [
            'knowledge_base_id' => KnowledgeBase::factory(),
            'source_text' => $this->faker->paragraphs(3, true),
            'status' => KnowledgeDraftSessionStatus::IDLE,
            'prompt_history' => [],
            'creator_id' => User::factory(),
        ];
    }

    public function status(KnowledgeDraftSessionStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** A session holding a live claim — the fixture the reaper and the double-click guard need. */
    public function generating(?\Illuminate\Support\Carbon $since = null): static
    {
        return $this->state(fn () => [
            'status' => KnowledgeDraftSessionStatus::GENERATING,
            'claimed_at' => $since ?? now(),
        ]);
    }

    /** "Write the entry this red link points at." */
    public function seeded(string $slug, ?string $title = null): static
    {
        return $this->state(fn () => [
            'seed_slug' => $slug,
            'seed_title' => $title ?? ucfirst(str_replace('-', ' ', $slug)),
        ]);
    }
}
