<?php

namespace App\Modules\Knowledge\Support;

use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Modules\Knowledge\Models\KnowledgeRelation;

/**
 * EVERYTHING THE LAUNDERER IS ALLOWED TO BELIEVE — assembled from the database once, so
 * {@see \App\Modules\Knowledge\Support\KnowledgeGraphOps::fromArray()} stays a pure function of its
 * input.
 *
 * That purity is the point rather than an aesthetic. The laundering is the security boundary for a
 * model-written object, and a boundary that queries as it goes is a boundary whose verdict depends on
 * when it ran. Here the facts are fixed first and the decisions are then total, replayable, and
 * testable without a database.
 *
 * ------------------------------------------------------------------------------------------------
 * HANDLES ARE THE WHOLE IDENTITY MODEL
 *
 * `$entities` maps `E<n>` to the entry it stands for. A handle the model invents is simply absent, so
 * the position is dropped — which is why no id, no slug and no uuid ever appears in the graph section
 * of the contract. With raw addresses in the prompt, a hallucinated one is indistinguishable from a
 * real one until it has been written somewhere.
 *
 * `$newEntities` is filled DURING laundering (an `entities[]` declaration mints `N<n>`), which is what
 * lets a run create a person and relate them in the same answer without a round trip.
 */
final class GraphOpsContext
{
    /**
     * @param  array<string, array{id: string, slug: string, title: string, entry_type: ?KnowledgeEntryType, truncated: bool, revision: ?string, content: string}>  $entities
     * @param  array<string, array{id: string, type: string, from_entry_id: string, to_entry_id: string}>  $relations
     * @param  array<int, KnowledgeRelationType>  $allowedTypes  the base's own vocabulary
     * @param  array<int, array{from: string, to: string, type: string, valid_from: ?string, id: string}>  $existing
     *                                                                                                                ACTIVE relations, for the duplicate guard — the same rule the write service enforces
     * @param  array<string, array<int, string>>  $ambiguous  mention text => candidate handles
     */
    public function __construct(
        public readonly array $entities,
        public readonly array $relations,
        public readonly array $allowedTypes,
        public readonly array $existing,
        public readonly array $ambiguous = [],
    ) {}

    public function hasEntity(string $handle): bool
    {
        return isset($this->entities[$handle]);
    }

    /** @return array{id: string, slug: string, title: string, entry_type: ?KnowledgeEntryType, truncated: bool, revision: ?string, content: string}|null */
    public function entity(string $handle): ?array
    {
        return $this->entities[$handle] ?? null;
    }

    /** @return array{id: string, type: string, from_entry_id: string, to_entry_id: string}|null */
    public function relation(string $handle): ?array
    {
        return $this->relations[$handle] ?? null;
    }

    public function allows(KnowledgeRelationType $type): bool
    {
        return in_array($type, $this->allowedTypes, true);
    }

    /**
     * The id of an ACTIVE relation already asserting this exact statement, or null.
     *
     * Same key as {@see \App\Modules\Knowledge\Services\KnowledgeRelationService}'s own guard — pair,
     * verb and start date — so a proposal is refused here for exactly the reason it would be refused
     * at the write, rather than being surfaced for review and then rejected on acceptance.
     */
    public function duplicateOf(string $fromId, string $toId, string $type, ?string $validFrom): ?string
    {
        // SYMMETRY-AWARE, like the guard it mirrors. For a symmetric verb "Anna knows Bob" and "Bob
        // knows Anna" are ONE fact, and the service stores them under a single canonical ordering.
        // Comparing raw ids let a duplicate through this mirror whenever the model happened to name the
        // ends the other way round — so the reviewer was shown a proposal that could only ever be
        // refused at the write, which is the exact outcome this method exists to prevent.
        $relationType = KnowledgeRelationType::tryFrom($type);

        if ($relationType !== null) {
            [$fromId, $toId] = KnowledgeRelation::canonicalPair($relationType, $fromId, $toId);
        }

        foreach ($this->existing as $relation) {
            $existing = [(string) $relation['from'], (string) $relation['to']];

            if ($relationType !== null) {
                $existing = KnowledgeRelation::canonicalPair($relationType, $existing[0], $existing[1]);
            }

            if ($existing[0] === $fromId
                && $existing[1] === $toId
                && $relation['type'] === $type
                && ($relation['valid_from'] ?? null) === $validFrom) {
                return $relation['id'];
            }
        }

        return null;
    }
}
