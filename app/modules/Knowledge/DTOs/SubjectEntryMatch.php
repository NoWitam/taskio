<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * One entry whose CURRENT authored state names the subject — the category that costs the whole entry.
 *
 * The cascade figures (`revisions`, `chunks`, `incomingLinks`) are carried so the dry run can state
 * the true blast radius before anything happens: "this deletes 1 entry" and "this deletes 1 entry,
 * 14 revisions, 9 embedded passages and cuts 3 backlinks" are different decisions.
 */
final readonly class SubjectEntryMatch
{
    /** @param  list<string>  $matchedIn */
    public function __construct(
        public string $id,
        public string $baseId,
        public string $slug,
        public string $title,
        public bool $trashed,
        public array $matchedIn,
        public int $revisions,
        public int $chunks,
        public int $incomingLinks,
        /**
         * An unaccepted AI draft. It is purged like any other row — a draft holds the person's name in
         * exactly the same sense a published entry does — but it is FLAGGED, because an operator
         * reading the report needs to know that some of what is being destroyed is work nobody has
         * approved and nobody else can see. "3 entries" and "1 entry plus 2 drafts from an abandoned
         * composer session" are different things to certify.
         */
        public bool $isDraft = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'knowledge_base_id' => $this->baseId,
            'slug' => $this->slug,
            'title' => $this->title,
            'trashed' => $this->trashed,
            'matched_in' => $this->matchedIn,
            'revisions' => $this->revisions,
            'chunks' => $this->chunks,
            'incoming_links' => $this->incomingLinks,
            'is_draft' => $this->isDraft,
        ];
    }
}
