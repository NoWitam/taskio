<?php

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Retrieve the base's context again for this session.
 *
 * No body: the material is already on the session, and asking the caller to restate it would only
 * create a way for the request and the record to disagree.
 *
 * It SPENDS (one embedding), so it is a deliberate action with its own endpoint rather than something a
 * refinement does implicitly — the client shows the cost before the user asks for it. The budget
 * refusal is the service's, and renders itself as the same 429 every other composer entry point emits.
 */
class ExpandKnowledgeDraftContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('session') instanceof KnowledgeDraftSession
            && ($this->user()?->can('compose', KnowledgeEntry::class) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
