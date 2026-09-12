<?php

namespace App\Modules\Publishing\Http\Requests;

use App\Modules\Publishing\Http\Requests\Concerns\ExplainsAReviewHold;
use App\Modules\Publishing\Models\Publication;

/**
 * Validates a PUBLICATION on update — the same payload as a create, authorised against the row.
 *
 * It inherits every rule rather than restating them, and that is load-bearing rather than tidy: an
 * update is a WHOLE-ROW write, so a rule present on create and absent here would let a second call
 * store something the first call refused. The `prohibited` list in particular has to hold on both doors
 * — a client that cannot set `status` on create but can on update has a state machine with a hole in
 * it.
 *
 * WHAT CHANGES IS THE AUTHORIZATION, and it now has a row to ask about. `PublicationPolicy::update()`
 * composes three independent answers — who is asking, whether the row is still editable at all
 * (`PublicationStatus::isEditable()`, which refuses `publishing`, `published` and `needs_reconcile`),
 * and, since B6, whether a REVIEW is holding it.
 *
 * A 403 is the right shape for the first two: neither is about the payload. The third is answered as a
 * 422 that names the hold — see {@see ExplainsAReviewHold}. Editing a publication somebody is currently
 * deciding about would make their decision a statement about content that no longer exists, which is
 * why it is refused; but the person is not forbidden anything, they are early.
 */
class UpdatePublicationRequest extends StorePublicationRequest
{
    use ExplainsAReviewHold;

    public function authorize(): bool
    {
        $publication = $this->route('publication');

        if (!$publication instanceof Publication) {
            return false;
        }

        return $this->user()?->can('update', $publication) ?? false;
    }
}
