<?php

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Modules\Knowledge\Models\KnowledgeLink;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Guards both halves of a dismissal — `POST …/dismiss` and the `DELETE …/dismiss` that undoes it.
 *
 * WHO is a policy question ({@see \App\Modules\Knowledge\Policies\KnowledgeLinkPolicy}: any member).
 * WHICH is a domain rule and lives here, as a 422 rather than a 403, because the two mean different
 * things to whoever gets the response: 403 says "not you", 422 says "not this kind of edge" — and
 * only the second is true. A wikilink is what the entry's text SAYS; the way to remove it is to edit
 * the `[[…]]`, and pretending otherwise would let the graph disagree with the document it describes.
 * A manual edge was drawn deliberately by a human and is removed by deleting it, not by marking it
 * rejected.
 *
 * The rule is asked of the SOURCE enum ({@see KnowledgeLinkSource::isDismissable()}) rather than
 * spelled out here, so adding a machine-derived kind cannot leave the user unable to refuse it — which
 * is exactly what happened when `mention` landed against a check written as `!== SIMILARITY`.
 */
class DismissKnowledgeLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $link = $this->route('link');

        return $link instanceof KnowledgeLink && ($this->user()?->can('dismiss', $link) ?? false);
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $link = $this->route('link');

            if ($link instanceof KnowledgeLink && !($link->source?->isDismissable() ?? false)) {
                $validator->errors()->add('source', __('knowledge.links.not_dismissable'));
            }
        });
    }
}
