<?php

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ask the composer to revise the whole set.
 *
 * ONE instruction, capped: it is replayed into every later prompt (refinement is cumulative), so an
 * unbounded string here would be unbounded text in every subsequent provider call.
 */
class RefineKnowledgeDraftSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('session') instanceof KnowledgeDraftSession
            && ($this->user()?->can('compose', KnowledgeEntry::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'instruction' => ['required', 'string', 'max:' . (int) config('knowledge.drafting.prompt_max_chars')],
        ];
    }

    public function instruction(): string
    {
        return trim($this->string('instruction')->value());
    }
}
