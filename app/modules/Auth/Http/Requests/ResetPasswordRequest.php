<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public (unauthenticated) "set my new password" request.
 *
 * Authorization is intentionally open, exactly as on the invitation accept request:
 * POSSESSION OF THE MAILED TOKEN IS THE AUTHORIZATION, and only the password broker can
 * verify it (it holds a hash, not the value). A Policy here would have no subject and no
 * way to check anything, so the check stays where the proof is — in the service.
 *
 * The password rules are the SAME ONES THE REST OF THE APP ALREADY ENFORCES when a
 * password is chosen: `min:8` + `confirmed`, matching
 * {@see \App\Modules\Settings\Http\Requests\UpdatePasswordRequest} (change password) and
 * the `min:8` of {@see \App\Modules\Workspaces\Http\Requests\AcceptInvitationRequest}
 * (registration, the only way an account is created today). Reset must not be the weakest
 * or the strictest door onto the same field — a stricter rule here would lock out anyone
 * whose existing password the app itself accepted.
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
