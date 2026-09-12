<?php

namespace App\Modules\Publishing\Http\Requests\Concerns;

use App\Modules\Publishing\Exceptions\PublicationUnderReview;
use App\Modules\Publishing\Models\Publication;

/**
 * SAYS WHY, when the policy refused because a review is holding the publication.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT MOVES NO DECISION OUT OF THE POLICY, AND THAT IS THE ENTIRE DESIGN
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `PublicationPolicy::update()` / `::schedule()` compose `Publication::isInApproval()` — the same
 * mechanism `TaskPolicy::update()` uses, and the same computation the resource's `can_be_edited` /
 * `can_be_scheduled` flags route through. This trait runs strictly AFTERWARDS, in Laravel's
 * `failedAuthorization()` hook, and only re-reads that one predicate in order to choose a SENTENCE. If
 * it disagreed with the policy about anything, the policy would still be what decided.
 *
 * The ordinary 403 is left intact for every other refusal (a published row, somebody else's draft, no
 * workspace): those really are "not you" or "not any more", and a 422 naming a review would be a lie
 * about them.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT IS WORTH A TRAIT FOR TWO CALLERS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Because the two callers must not drift. Editing and arming are refused for the SAME reason by the SAME
 * predicate, and a reader who met a 422 on one and a bare 403 on the other would reasonably conclude the
 * two refusals had different causes. One trait, one sentence, one code.
 */
trait ExplainsAReviewHold
{
    /**
     * @throws PublicationUnderReview when a live review is why the policy said no
     * @throws \Illuminate\Auth\Access\AuthorizationException for every other refusal
     */
    protected function failedAuthorization(): void
    {
        $publication = $this->route('publication');

        if ($publication instanceof Publication && $publication->isInApproval()) {
            throw PublicationUnderReview::for($publication);
        }

        parent::failedAuthorization();
    }
}
