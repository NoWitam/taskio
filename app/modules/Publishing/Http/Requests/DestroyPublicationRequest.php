<?php

namespace App\Modules\Publishing\Http\Requests;

use App\Modules\Publishing\Models\Publication;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises moving a publication to the trash.
 *
 * A request with no rules exists here because authorization is the whole of what a delete needs
 * checking for, and it belongs in a FormRequest rather than in the controller — the house rule, and the
 * same shape `DestroyCalendarEventRequest` has.
 *
 * `PublicationPolicy::delete()` composes who is asking with `PublicationStatus::isDeletable()`, which
 * refuses two states and allows one that looks like it should be refused:
 *
 *   publishing        a worker is holding this row right now. Deleting it destroys the only handle onto
 *                     an attempt in flight.
 *   needs_reconcile   the row may correspond to a live post, and it is the only record of which post.
 *                     Delete it and the artifact, if it exists, becomes permanently unattributable.
 *   published         ALLOWED. Deleting hides our record and changes nothing in the world — the post
 *                     stays up, because nothing written here can recall it. That is the honest
 *                     asymmetry with editing, which is refused for `published` precisely because an
 *                     edited record would actively lie about a post that still exists.
 */
class DestroyPublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $publication = $this->route('publication');

        if (!$publication instanceof Publication) {
            return false;
        }

        return $this->user()?->can('delete', $publication) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
