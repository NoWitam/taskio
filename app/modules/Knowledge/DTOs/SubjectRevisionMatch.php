<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * One revision that names the subject while its entry's CURRENT text does not — the category this
 * whole command exists for.
 *
 * Revision history is append-only, so "we removed her name from the page" leaves the name in every
 * snapshot taken before the edit. Nothing in the ordinary product surface can reach those rows: they
 * are the audit trail, and the API deliberately has no way to rewrite one. An erasure request is the
 * one occasion on which that trail must yield, and it yields at the smallest possible granularity —
 * the individual revision — so the entry, its other versions and its current text all survive.
 */
final readonly class SubjectRevisionMatch
{
    /** @param  list<string>  $matchedIn */
    public function __construct(
        public string $id,
        public string $entryId,
        public string $entrySlug,
        public ?string $createdAt,
        public array $matchedIn,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'knowledge_entry_id' => $this->entryId,
            'entry_slug' => $this->entrySlug,
            'created_at' => $this->createdAt,
            'matched_in' => $this->matchedIn,
        ];
    }
}
