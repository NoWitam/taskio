<?php

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-point a shadow draft at its target's current revision. No AI, no content change — see the
 * controller for why rebasing deliberately does not try to merge anything.
 *
 * WHICH draft is decided by the service, resolving the id through the session's own drafts and
 * requiring a target — so an id naming a plain draft, another session's draft, or a real entry simply
 * is not found.
 */
class RebaseKnowledgeDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('session') instanceof KnowledgeDraftSession
            && ($this->user()?->can('compose', KnowledgeEntry::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'entry_id' => ['required', 'uuid'],
        ];
    }
}
