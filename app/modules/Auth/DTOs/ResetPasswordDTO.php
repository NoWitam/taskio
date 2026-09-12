<?php

namespace App\Modules\Auth\DTOs;

use App\Modules\Auth\Http\Requests\ResetPasswordRequest;

/**
 * The three values a password reset needs, carried from the request into the service.
 *
 * ASYMMETRY WORTH NAMING: its sibling, "forgot password", takes a bare string through the
 * controller — the same shape `AuthService::login()` already uses in this module for its
 * scalars. A DTO earns its place here and not there because THESE THREE TRAVEL TOGETHER
 * and must not be reordered by accident: `token` and `email` are both strings, and a
 * transposed pair would still typecheck while silently validating the wrong thing.
 *
 * `$password` is the PLAINTEXT the user just chose. It exists for exactly one hop — DTO to
 * broker to the `hashed` cast — and this object is never serialized, logged, queued or put
 * on an event. Neither is `$token`: it is the value mailed to the account's inbox, and the
 * store holds only its hash.
 */
final class ResetPasswordDTO
{
    public function __construct(
        public readonly string $token,
        public readonly string $email,
        public readonly string $password,
    ) {}

    public static function fromRequest(ResetPasswordRequest $request): self
    {
        return new self(
            token: $request->string('token')->toString(),
            email: $request->string('email')->toString(),
            password: $request->string('password')->toString(),
        );
    }
}
