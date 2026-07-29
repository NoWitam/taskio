<?php

namespace App\Modules\Generator\Http\Requests;

use App\Modules\Generator\Models\GenerationSession;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a session EDIT — the two user-mutable inputs (`name`, `slot_values`). Authorization is
 * creator-only ({@see \App\Modules\Generator\Policies\GenerationSessionPolicy::update}); on top of that,
 * a session may be edited only while it is NOT mid-run: `draft` (being filled) or `ready` (tweaked before
 * a re-generate). A `generating` or `failed` session rejects the edit (a state guard, server-authoritative
 * — the resource's `can_edit` flag mirrors it for the UI).
 */
class UpdateGenerationSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('session'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slot_values' => ['sometimes', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var GenerationSession $session */
            $session = $this->route('session');

            if (!$session->status->isEditable()) {
                $validator->errors()->add('status', __('generator.sessions.not_editable'));
            }
        });
    }
}
