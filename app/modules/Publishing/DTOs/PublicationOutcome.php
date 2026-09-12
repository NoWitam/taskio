<?php

namespace App\Modules\Publishing\DTOs;

use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Events\PublicationConcluded;

/**
 * WHERE ONE PUBLICATION STANDS, for a caller outside this module that is waiting on it.
 *
 * The answer to {@see \App\Modules\Publishing\Services\PublicationAutomationService::outcomeFor()}, and
 * deliberately the narrowest thing that answers the question: a status and one boolean. A caller that
 * wanted the row would ask for the row.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY A REJECTED REVIEW NEEDS ITS OWN FIELD
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A publication a reviewer turned down is a DRAFT — the same status as one nobody has looked at yet, and
 * the same status as one somebody is still reviewing. Without this boolean the three are indistinguishable
 * from outside, and a caller waiting for an answer would wait for one that has already been given.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `isConcluded()` SPEAKS THIS MODULE'S VOCABULARY, NOT THE CALLER'S
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * It says "this publication has an answer", which is a fact about a publication. It deliberately does not
 * say "settled", "done" or anything else borrowed from whoever is waiting — that translation belongs on
 * their side of the seam, and keeping it there is what stops this module from growing an opinion about
 * workflow runs.
 *
 * `needs_reconcile` IS NOT CONCLUDED, and the whole module hangs off that: it means we do not know whether
 * a post exists, which is the absence of an answer rather than a bad one. See {@see PublicationConcluded}.
 */
final readonly class PublicationOutcome
{
    public function __construct(
        public PublicationStatus $status,
        /** A review looked at this publication and refused it; the row is still a draft. */
        public bool $reviewRejected,
    ) {}

    /** Whether this publication has an answer — see the class docblock for what does not count. */
    public function isConcluded(): bool
    {
        return $this->reviewRejected
            || in_array($this->status, PublicationConcluded::concludingStatuses(), true);
    }
}
