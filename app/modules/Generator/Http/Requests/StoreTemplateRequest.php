<?php

namespace App\Modules\Generator\Http\Requests;

use App\Modules\Generator\Models\Template;
use App\Modules\Generator\Services\ContentTypeRegistry;
use App\Modules\Generator\Services\TemplateContentValidator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a TEMPLATE on create. A template is a user-authored, workspace-scoped RECIPE for a finished
 * post: an identity (`name`, optional `description`), a `content_type` (a ContentTypeRegistry id), a list
 * of DECLARED typed `slots`, and a per-part `content` map.
 *
 * The coarse SHAPE is pinned by the base rules; the DEEP checks — each slot's identity + descriptor, and
 * each part's content by KIND (body directives / image plans / scene plans against the template catalog)
 * — are delegated in withValidator() to {@see TemplateContentValidator} (the ONE place a template's slots
 * + content are validated, so the accepted shape can never drift from what the engine understands). The
 * workspace's globals + functions are read THROUGH the model (WorkspaceScope in shared mode, the tenant
 * connection in own mode), so the content reference index is correct in BOTH db_modes without a
 * workspace_id branch.
 */
class StoreTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Template::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            // The recipe SHAPE — a known ContentTypeRegistry id. The type's PARTS drive the content check.
            'content_type' => ['required', 'string', Rule::in(app(ContentTypeRegistry::class)->ids())],
            // The slots are deep-validated by TemplateContentValidator; this only pins the coarse shape.
            'slots' => ['present', 'array'],
            // The per-part authored content map, keyed by the type's part keys. Deep-validated (per kind)
            // in withValidator(); `present` keeps the FE contract honest (the key is always sent).
            'content' => ['present', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            app(TemplateContentValidator::class)->validate(
                $validator,
                $this->input('content_type'),
                $this->input('content'),
                $this->input('slots'),
            );
        });
    }
}
