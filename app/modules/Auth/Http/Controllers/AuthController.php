<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\Request;

class AuthController
{
    public function __construct(
        private AuthService $service
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
}
