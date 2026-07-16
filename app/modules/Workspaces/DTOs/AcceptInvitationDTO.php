<?php

namespace App\Modules\Workspaces\DTOs;

use Illuminate\Http\Request;

/**
 * Carries the optional registration/login credentials from the public accept
 * request. The email is never carried here: it is bound to the invitation and
 * the user cannot choose a different one.
 */
class AcceptInvitationDTO
{
    public function __construct(
        public readonly string $token,
        public readonly ?string $name,
        public readonly ?string $password,
    ) {}

    public static function fromRequest(Request $request, string $token): self
    {
        return new self(
            token: $token,
            name: $request->filled('name') ? $request->string('name')->toString() : null,
            password: $request->filled('password') ? $request->string('password')->toString() : null,
        );
    }
}
