<?php

namespace App\Modules\Knowledge\DTOs;

/**
 * A knowledge base whose CHARTER names the subject — REPORTED, NEVER DELETED.
 *
 * The only report-only category in this command, and the distinction is deliberate rather than an
 * omission. Everything else here is something an erasure destroys; this is something an erasure has to
 * TELL THE OPERATOR ABOUT and cannot destroy on their behalf.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY IT MUST BE REPORTED
 *
 * The charter is not dormant text. {@see \App\Modules\Knowledge\Support\KnowledgeCompiler} prepends it
 * to the compiled knowledge block, so it is sent to the model on EVERY call made against this base. A
 * person named in a charter is injected into every prompt, which makes it one of the most active
 * copies of their data in the system — and it was invisible to the scan, so an operator could read
 * "0 records", certify the erasure, and leave the name going out to the provider indefinitely.
 *
 * ------------------------------------------------------------------------------------------------
 * WHY IT MUST NOT BE DELETED
 *
 * A charter is the base's own editorial policy — "this base is about our returns process, written for
 * support staff, in Polish" — and it belongs to the base, not to the person it happens to mention.
 * Deleting the BASE because of one sentence would destroy every entry in it. Blanking the CHARTER would
 * silently change how every future generation against that base behaves, which is a product decision
 * nobody asked this command to make. Editing out the sentence needs a human who can read the rest of
 * the paragraph and keep it coherent.
 *
 * So the command names the base and stops. The operator edits it.
 *
 * It does NOT say which phrase it found, and carries no `matched_in`: there is only one field it could
 * have matched, and attributing per match would write the erased name into an archived report line —
 * the rule that keeps it out of every other section here.
 */
final readonly class SubjectCharterMatch
{
    public function __construct(
        public string $baseId,
        public string $baseName,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'knowledge_base_id' => $this->baseId,
            'knowledge_base_name' => $this->baseName,
            // Said in the payload itself, not only in the prose above it: a machine-readable report is
            // archived and re-read by people who never saw the terminal output, and "flagged" must not
            // be mistaken for "done".
            'action_required' => 'manual_edit',
            'deleted' => false,
        ];
    }
}
