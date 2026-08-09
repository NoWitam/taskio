<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * One AUDIT LINE of a relation's history that names the subject while the relation itself no longer
 * does — the entry-versus-revision asymmetry, one table further out.
 *
 * `knowledge_relation_events` stores a `before` and an `after` snapshot of the relation on every
 * change, and both copy `description` and `properties` verbatim. So the ordinary, responsible remedy —
 * "somebody edited the description to take her name out" — leaves the name in the `before` snapshot of
 * the very event that recorded the removal. The relation is clean, every entry is clean, and the name
 * is still in the database.
 *
 * The log is deliberately unreachable from the product: it has no API that rewrites a line, and it
 * carries no foreign key to the relation, precisely so that deleting a relation cannot delete the
 * record of the deletion. Both properties are right, and both are why nothing else in this command
 * reaches these rows.
 *
 * Deleted at the granularity of the individual EVENT, like a revision: the relation keeps the rest of
 * its history, and an erasure removes the lines that carry the name rather than the trail.
 */
final readonly class SubjectRelationEventMatch
{
    /** @param  list<string>  $matchedIn */
    public function __construct(
        public string $id,
        public string $relationId,
        public string $operation,
        public ?string $createdAt,
        public array $matchedIn,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'relation_id' => $this->relationId,
            'operation' => $this->operation,
            'created_at' => $this->createdAt,
            'matched_in' => $this->matchedIn,
        ];
    }
}
