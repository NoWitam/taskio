<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public (unauthenticated) "send me a reset link" request.
 *
 * Authorization is intentionally open — anyone may ASK; what the asker learns is the
 * point, and that is settled in {@see \App\Modules\Auth\Services\PasswordResetService}
 * (one answer for every address). There is no Policy to delegate to, because there is no
 * subject to authorize against: naming a Policy here would mean this endpoint could tell
 * an unknown address apart from a known one, which is exactly the leak it exists to avoid.
 *
 * VALIDATION IS DELIBERATELY THIN. `email` is checked for SHAPE only — `exists:users` (or
 * any rule that consults the table) would turn a 422 into an account-existence oracle,
 * undoing the whole design in the layer that is meant to protect it. A syntactically
 * invalid address is refused because it could never be a real account, and refusing it
 * says nothing about who is registered.
 */
class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
