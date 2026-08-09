<?php

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Open a drafting session on some raw material.
 *
 * Authorized as "may write entries in this base" — the composer produces entries, and its output is
 * reviewed by a human before anything becomes visible, so it needs no ability beyond what typing the
 * same entries by hand would need. Gating it harder would make the AI path a privilege while the
 * manual path stayed open, which protects nothing.
 *
 * The BUDGET refusal is deliberately NOT here. It is a state of the workspace rather than of the
 * request, it has to produce a 429 (not a 422), and both entry points share it — so it lives in the
 * service and renders itself.
 */
class StoreKnowledgeDraftSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('base') instanceof KnowledgeBase
            && ($this->user()?->can('compose', KnowledgeEntry::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'source_text' => ['required', 'string', 'max:' . (int) config('knowledge.drafting.source_max_chars')],
            // "Write the entry this red link points at": the composer is opened FROM a ghost link, so
            // the slug is already decided and the run must honour it.
            'seed_slug' => ['nullable', 'string', 'max:200'],
            'seed_title' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function seedSlug(): ?string
    {
        $slug = $this->input('seed_slug');

        return is_string($slug) && trim($slug) !== '' ? \Illuminate\Support\Str::slug($slug) : null;
    }

    public function seedTitle(): ?string
    {
        $title = $this->input('seed_title');

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }
}
