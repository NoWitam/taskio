<?php

namespace App\Modules\Generator\Http\Requests;

use App\Modules\Generator\Models\Template;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the DRAFT-FRIENDLY preview request `POST /generator/preview`
 * `{content_type, content, slots:[{name, descriptor}], slot_values:{<name>:<value>}}`. Like the catalog
 * request it pins only the coarse SHAPE — a preview runs on an UNSAVED draft, so it never 422s on template
 * content; the render service is fail-soft per part (an unresolvable reference → ''/an empty plan) and
 * returns the best-effort per-part result. Authorization is workspace-member read (TemplatePolicy::viewAny).
 */
class TemplatePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Template::class);
    }

    public function rules(): array
    {
        return [
            // The recipe SHAPE to render (draft-friendly — an unknown/absent type renders no parts).
            'content_type' => ['present', 'nullable', 'string'],
            // The per-part authored content map to render (any draft shape; render is fail-soft).
            'content' => ['present', 'array'],
            'slots' => ['present', 'array'],
            'slots.*.name' => ['nullable', 'string'],
            'slots.*.descriptor' => ['nullable', 'array'],
            // The sample values keyed by slot name; any shape a slot may hold (scalar/list/object/null).
            'slot_values' => ['nullable', 'array'],
        ];
    }

    /** The content type id to render (coerced to a string; an absent/null id renders no parts). */
    public function contentType(): string
    {
        $contentType = $this->input('content_type');

        return is_string($contentType) ? $contentType : '';
    }

    /** The per-part authored content map to render. */
    public function content(): array
    {
        return $this->array('content');
    }

    /** The declared slots (for the runtime type map). */
    public function slots(): array
    {
        return $this->array('slots');
    }

    /** The sample slot values keyed by slot name. */
    public function slotValues(): array
    {
        return $this->array('slot_values');
    }
}
