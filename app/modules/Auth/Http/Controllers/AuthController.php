<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\DTOs\ResetPasswordDTO;
use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\PasswordResetService;
use Illuminate\Http\Request;

class AuthController
{
    public function __construct(
        private AuthService $service,
        private PasswordResetService $passwordResets,
    ) {}

    public function login(LoginRequest $request)
    {
        return response()->json(
            $this->service->login(
                $request->string('email')->toString(),
                $request->string('password')->toString(),
                $request->boolean('remember'),
            )
        );
    }

    public function me(Request $request)
    {
        return response()->json(
            $this->service->context($request->user(), $request->header('X-Workspace-Id'))
        );
    }

    public function logout(Request $request)
    {
        $this->service->logout($request->user(), $request->bearerToken());

        return response()->noContent();
    }

    /**
     * Always 200, always the same body. The service decides what may be said out loud;
     * this method must not learn to branch on the outcome.
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        return response()->json([
            'message' => $this->passwordResets->request($request->string('email')->toString()),
        ]);
    }

    /**
     * Login-shaped payload on success (message + token + context): the person just proved
     * control of the inbox AND set the password, and every other session of theirs was just
     * cut — leaving them on a login form would only delay the one thing they came to do.
     * A bad/expired/used link is a 422 from the service.
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        return response()->json(
            $this->passwordResets->reset(ResetPasswordDTO::fromRequest($request))
        );
    }
}
