<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Users\Http\Resources\UserResource;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\WorkspaceService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    public function __construct(
        private AuthContextCache $cache,
        private WorkspaceService $workspaces,
    ) {}

    public function login(string $email, string $password, bool $remember): array
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $expiresAt = $remember ? now()->addDays(30) : now()->addDay();
        $token = $user->createToken('api', ['*'], $expiresAt)->plainTextToken;

        return [
            'token' => $token,
            ...$this->context($user),
        ];
    }

    public function context(User $user, ?string $workspaceId = null): array
    {
        $workspaces = $this->workspaces->forUser($user);

        return [
            'user' => UserResource::make($user)->resolve(),
            'permissions' => $this->cache->permissions($user, $workspaceId),
            'workspaces' => $workspaces->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'db_mode' => $workspace->db_mode->value,
                'is_owner' => $workspace->isOwnedBy($user),
                'created_at' => $workspace->created_at,
            ])->all(),
            'current_workspace' => $workspaceId ?? $workspaces->first()?->id,
        ];
    }

    public function logout(User $user, ?string $bearerToken): void
    {
        // Revoke exactly the presented token. Resolving it by value is robust
        // whether Sanctum authenticated via the token or a stateful guard.
        if ($bearerToken !== null) {
            PersonalAccessToken::findToken($bearerToken)?->delete();
        }

        $this->cache->forget($user->id);
    }
}
