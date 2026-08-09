<?php

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\Models\KnowledgeEntry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the READ of one entry's typed relations.
 *
 * `include_historical` is the whole surface. Ended and retracted relations are hidden by default
 * because the panel's question is "what is true about this entry"; showing five years of former
 * memberships alongside the current one would bury the answer. They are one flag away rather than
 * unreachable, because "what WAS true" is the other question a knowledge base exists to answer.
 */
class IndexKnowledgeRelationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entry = $this->route('entry');

        return $entry instanceof KnowledgeEntry && ($this->user()?->can('view', $entry) ?? false);
    }

    public function rules(): array
    {
        return [
            'include_historical' => ['sometimes', 'boolean'],
        ];
    }

    public function includeHistorical(): bool
    {
        return $this->boolean('include_historical');
    }
}
