<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * One AI drafting session whose RAW MATERIAL names the subject.
 *
 * This category exists because a drafting session stores the text a person PASTED IN — a meeting
 * transcript, an email thread, a policy document — and that blob is never chunked, never indexed, and
 * never reachable from any entry. Erasing every entry in the workspace would leave it sitting in
 * `source_text` untouched, so an erasure request answered without it would be answered wrongly. The
 * instruction history is scanned for the same reason: "rewrite the part about Jan Kowalski" is the
 * person's name, typed by a user, stored verbatim.
 *
 * THE REMEDY IS THE WHOLE SESSION, and that is the only honest granularity available. The source text
 * is an opaque blob the user pasted; there is no field-level surgery to perform on it, and editing it
 * to remove a name would leave drafts that were derived from the removed sentences. So a matched
 * session is ABANDONED exactly as its owner's own discard button abandons it: drafts purged, session
 * deleted, nothing kept.
 *
 * `drafts` is carried so the dry run can state the blast radius before anything happens — "1 session"
 * and "1 session and the 6 drafts generated from it" are different decisions.
 */
final readonly class SubjectSessionMatch
{
    /** @param  list<string>  $matchedIn */
    public function __construct(
        public string $id,
        public string $baseId,
        public string $status,
        public array $matchedIn,
        public int $drafts,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'knowledge_base_id' => $this->baseId,
            'status' => $this->status,
            'matched_in' => $this->matchedIn,
            'drafts' => $this->drafts,
        ];
    }
}
