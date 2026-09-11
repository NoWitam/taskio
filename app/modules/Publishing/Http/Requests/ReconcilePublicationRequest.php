<?php

namespace App\Modules\Publishing\Http\Requests;

use App\Modules\Publishing\Models\Publication;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ASK THE PLATFORM WHAT ACTUALLY HAPPENED — the manual half of reconciliation.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A REQUEST WITH NO RULES IS NOT A REQUEST WITH NOTHING TO DO
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * There is deliberately no payload: the whole act is "go and look", and every input it could take would
 * be an input that lets a caller influence the ANSWER. In particular it does not accept a remote id — a
 * client that could hand one over could assert that a post exists, which is the one thing this module
 * insists on establishing from the platform rather than from anybody's belief. `markPublished()` takes a
 * {@see \App\Modules\Publishing\DTOs\RemoteRef}, and from `needs_reconcile` the only producer of one is
 * `PlatformAdapter::findExisting()`.
 *
 * So this class exists entirely for its {@see authorize()}, which is the house rule working as intended:
 * authorization lives in the FormRequest and delegates to the Policy, rather than in a controller `if`
 * or — worse — inside the service that does the work.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS ENDPOINT IS SAFE WHEN A RETRY ENDPOINT WOULD NOT BE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * B1 argued that a retry endpoint written before a reconciliation existed would have an obvious
 * implementation ("set it back to publishing") that is precisely what the state machine refuses. This
 * is the other one: it WRITES NOTHING to a platform. It reads, and the two conclusions it may draw are
 * conclusions about evidence — found, or proven absent. A third outcome, "the platform could not say",
 * leaves the row exactly where it was and answers 200 with it unchanged, which is honest rather than a
 * silent failure.
 */
class ReconcilePublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $publication = $this->route('publication');

        if (!$publication instanceof Publication) {
            return false;
        }

        return $this->user()?->can('reconcile', $publication) ?? false;
    }

    /** Nothing. See the class docblock — the absence is the design. */
    public function rules(): array
    {
        return [];
    }
}
