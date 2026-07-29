<?php

namespace App\Modules\Generator\Http\Requests;

use App\Modules\Generator\Models\GenerationSession;
use App\Modules\Generator\Models\Template;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a NEW generation session. A session is created FROM a template: `{template_id, name?,
 * slot_values?}`. The coarse shape is pinned by the base rules; the template is RESOLVED through the
 * model in withValidator() — so WorkspaceScope (shared mode) / the tenant connection (own mode) ensure a
 * foreign-workspace template is simply not found (a clean 422, never a cross-tenant read) — and the
 * viewer is authorized against {@see \App\Modules\Generator\Policies\TemplatePolicy}. The resolved
 * template is STASHED for the DTO so the service never re-queries it.
 *
 * `slot_values` is a LENIENT json map here (the shape is validated against the snapshot at generate
 * time, not at creation — a draft may be filled incrementally).
 */
class StoreGenerationSessionRequest extends FormRequest
{
    private ?Template $resolvedTemplate = null;

    public function authorize(): bool
    {
        return $this->user()->can('create', GenerationSession::class);
    }

    public function rules(): array
    {
        return [
            'template_id' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'slot_values' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $template = Template::find($this->input('template_id'));

            if ($template === null) {
                $validator->errors()->add('template_id', __('generator.sessions.template_not_found'));

                return;
            }

            if (!$this->user()->can('view', $template)) {
                $validator->errors()->add('template_id', __('generator.sessions.template_forbidden'));

                return;
            }

            $this->resolvedTemplate = $template;
        });
    }

    /** The workspace-scoped, view-authorized template resolved in withValidator() (present post-validation). */
    public function template(): Template
    {
        return $this->resolvedTemplate;
    }
}
