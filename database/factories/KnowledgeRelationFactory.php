<?php

namespace Database\Factories;

use App\Modules\Knowledge\Enums\KnowledgeRelationOrigin;
use App\Modules\Knowledge\Enums\KnowledgeRelationState;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeRelationFactory extends Factory
{
    protected $model = KnowledgeRelation::class;

    public function definition(): array
    {
        return [
            // Declared AFTER the source entry so the closures see a resolved id: the base is
            // denormalized off that entry, never invented.
            'from_entry_id' => KnowledgeEntry::factory(),
            'knowledge_base_id' => fn (array $attributes) => KnowledgeEntry::query()
                ->find($attributes['from_entry_id'])?->knowledge_base_id,
            // The far end is created IN THE SAME BASE. A plain `KnowledgeEntry::factory()` here would
            // mint its own base, producing a relation that spans two of them — which the service
            // refuses and which would make any test built on the bare factory a test of something the
            // product cannot do.
            'to_entry_id' => fn (array $attributes) => KnowledgeEntry::factory()
                ->create(['knowledge_base_id' => $attributes['knowledge_base_id']])
                ->getKey(),
            // `part_of` is the default because it is the one verb whose type matrix accepts almost
            // everything — a factory that defaulted to `member_of` would fail the pair check the
            // moment a test typed its entries.
            'relation_type' => KnowledgeRelationType::PART_OF,
            'description' => null,
            'properties' => [],
            'valid_from' => null,
            'valid_to' => null,
            'state' => KnowledgeRelationState::ACTIVE,
            'superseded_by_id' => null,
            'origin' => KnowledgeRelationOrigin::HUMAN,
            'draft_session_id' => null,
        ];
    }

    /** A relation between two existing entries; the base is taken from the source entry. */
    public function between(KnowledgeEntry $from, KnowledgeEntry $to): static
    {
        return $this->state(fn () => [
            'knowledge_base_id' => $from->knowledge_base_id,
            'from_entry_id' => $from->getKey(),
            'to_entry_id' => $to->getKey(),
        ]);
    }

    public function ofType(KnowledgeRelationType $type): static
    {
        return $this->state(fn () => ['relation_type' => $type]);
    }

    /** A relation that WAS true and stopped — hidden from the default graph and panel. */
    public function ended(?string $validTo = null): static
    {
        return $this->state(fn () => [
            'state' => KnowledgeRelationState::ENDED,
            'valid_to' => $validTo ?? now()->toDateString(),
        ]);
    }

    public function retracted(): static
    {
        return $this->state(fn () => ['state' => KnowledgeRelationState::RETRACTED]);
    }

    public function origin(KnowledgeRelationOrigin $origin): static
    {
        return $this->state(fn () => ['origin' => $origin]);
    }
}
