<?php

namespace App\Modules\Generator\Http\Requests;

use App\Modules\Generator\Models\Template;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the DRAFT-FRIENDLY catalog request `POST /generator/catalog` `{slots:[{name, descriptor}]}`.
 * It pins only the coarse SHAPE — the catalog is built for an IN-PROGRESS template (possibly with
 * malformed slots), so a bad slot is skipped by the catalog builder (fail-soft) rather than 422'd here.
 * Authorization is workspace-member read (TemplatePolicy::viewAny); the workspace's globals + functions
 * the catalog merges in are workspace-scoped by the model.
 */
class TemplateCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Template::class);
    }

    public function rules(): array
    {
        return [
            'slots' => ['present', 'array'],
            'slots.*.name' => ['nullable', 'string'],
            'slots.*.descriptor' => ['nullable', 'array'],
            // OPTIONAL cross-part scoping (Phase A): when the editor is authoring a specific content-type
            // part, it passes the content type + the current part key so the catalog also offers the EARLIER
            // parts as `parts.<key>` variables. Absent → no `parts.*` (unchanged for callers that omit them).
            'content_type' => ['nullable', 'string'],
            'part_key' => ['nullable', 'string'],
        ];
    }

    /** The declared slots to build `slots.<name>` catalog variables from (coarse-validated). */
    public function slots(): array
    {
        return $this->array('slots');
    }

    /** The content type to scope the `parts.*` offering to (null → no cross-part scoping). */
    public function contentType(): ?string
    {
        $value = $this->input('content_type');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The current part key whose EARLIER parts are offered as `parts.<key>` (null → no cross-part scoping). */
    public function partKey(): ?string
    {
        $value = $this->input('part_key');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
