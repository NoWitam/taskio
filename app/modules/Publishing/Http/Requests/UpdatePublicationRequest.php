<?php

namespace App\Modules\Publishing\Http\Requests;

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
 * composes two independent answers — who is asking, and whether the row is still editable at all
 * (`PublicationStatus::isEditable()`, which refuses `publishing`, `published` and `needs_reconcile`).
 * A 403 rather than a 422 is the right shape for both halves: neither is about the payload.
 */
class UpdatePublicationRequest extends StorePublicationRequest
{
    public function authorize(): bool
    {
        $publication = $this->route('publication');

        if (!$publication instanceof Publication) {
            return false;
        }

        return $this->user()?->can('update', $publication) ?? false;
    }
}
