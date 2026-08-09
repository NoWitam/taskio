<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * One TYPED RELATION whose own text names the subject.
 *
 * A relation is a small row, and it is easy to assume it holds no personal data — the two entries it
 * joins are what carry the names, and purging those cascades the relation away with them. That
 * assumption is wrong in exactly one place, which is why this category exists: `description` and
 * `properties` are FREE TEXT a person or a model wrote onto the edge itself. "Wprowadzony przez Annę
 * Kowalską", `{"role": "asystentka Anny Kowalskiej"}` — neither entry need mention her at all, so
 * neither would be purged, and the name would survive an erasure that reported itself complete.
 *
 * THE REMEDY IS DELETION, not ending or retracting. Those two are lifecycle states that KEEP the row
 * precisely so the base remembers what was once asserted — which is the correct default everywhere
 * except here, where remembering is the thing being erased.
 *
 * `matchedIn` names which of the two fields carried the phrase, so the operator's report says what was
 * actually found rather than only that something was.
 */
final readonly class SubjectRelationMatch
{
    /** @param  list<string>  $matchedIn */
    public function __construct(
        public string $id,
        public string $baseId,
        public string $fromEntryId,
        public string $toEntryId,
        public string $relationType,
        public array $matchedIn,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'knowledge_base_id' => $this->baseId,
            'from_entry_id' => $this->fromEntryId,
            'to_entry_id' => $this->toEntryId,
            'relation_type' => $this->relationType,
            'matched_in' => $this->matchedIn,
        ];
    }
}
