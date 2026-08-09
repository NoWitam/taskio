<?php

namespace App\Modules\Knowledge\Enums;

/**
 * WHO ASSERTED a relation. Provenance, not authorship — the `creator` columns already record which
 * account performed the write.
 *
 * The distinction that matters is the first one: `composer` means a MODEL proposed this and a human
 * approved it, so a base can later be asked "what do we believe only because an AI said so". That
 * question stops being answerable the moment approval erases the difference, which is precisely what
 * a single "created by whoever clicked accept" would do.
 *
 *   composer  proposed by the AI composer, then approved by a person.
 *   human     drawn in the UI by a person, from nothing.
 *   promoted  upgraded from a machine SUGGESTION in the link graph (a similarity or mention edge) into
 *             a typed statement. Its own value rather than `human` because the human's contribution
 *             was a JUDGEMENT on somebody else's guess, not an assertion of their own — and because a
 *             base full of promoted edges is telling you something about how it was built.
 */
enum KnowledgeRelationOrigin: string
{
    case COMPOSER = 'composer';

    /**
     * NOTHING WRITES THESE TWO. Hand-authorship and link-promotion were withdrawn (ADR-0049 D2), and
     * `KnowledgeGraphOpsApplier` — the one surviving caller of `KnowledgeRelationService::create()` —
     * always passes `COMPOSER`. A relation carrying either value was asserted before that pivot.
     *
     * Unlike the `OP_PROMOTE` constant deleted from {@see \App\Modules\Knowledge\Models\KnowledgeRelationEvent}
     * for being exactly this dead, these two are kept, and the difference is where the value LIVES: a
     * write-side constant nothing emits misleads a reader and costs nothing to delete, while these are
     * a CAST on a stored column and a published API value (`origin` on the relation and graph
     * resources). Deleting a case makes the row that carries it unreadable rather than merely
     * old — the cast throws — and narrows a documented response field. That is a product decision and
     * a client change, not a tidy-up.
     */
    case HUMAN = 'human';
    case PROMOTED = 'promoted';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Whether a model had a hand in this statement — the "trust" question a reviewer asks. */
    public function isMachineProposed(): bool
    {
        return $this !== self::HUMAN;
    }
}
