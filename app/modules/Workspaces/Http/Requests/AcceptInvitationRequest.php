<?php

namespace App\Modules\Workspaces\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public (unauthenticated) accept request. Authorization is intentionally open —
 * possession of the valid token IS the authorization, verified in the service.
 * `name`/`password` are only required for new-user registration, which the
 * service enforces contextually (so it can return a structured 422, never 401).
 */
class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
        ];
    }
}
