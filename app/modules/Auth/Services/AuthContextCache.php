<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Caches per-user authentication context (compiled permissions) so requests do
 * not recompute permissions from groups on every call. Invalidation bumps a
 * per-user version embedded in the cache key, which works on every cache store
 * (no tag support required).
 */
class AuthContextCache
{
    private const TTL = 3600;

    public function __construct(
        private PermissionCompiler $compiler
    ) {}

    public function permissions(User $user, ?string $workspaceId): array
    {
        return Cache::remember(
            $this->permissionsKey($user->id, $workspaceId),
            self::TTL,
            fn () => $this->compile($user, $workspaceId)
        );
    }

    /**
     * Invalidate every cached permission set for a user across all workspaces.
     */
    public function forget(string $userId): void
    {
        $current = (int) Cache::get($this->versionKey($userId), 1);
        Cache::forever($this->versionKey($userId), $current + 1);
    }

    /**
     * Compile the flat permission list for a user within a workspace
     * (the union of the permissions of the user's groups in that workspace).
     */
    private function compile(User $user, ?string $workspaceId): array
    {
        return $this->compiler->compileFor($user, $workspaceId);
    }

    private function permissionsKey(string $userId, ?string $workspaceId): string
    {
        $version = (int) Cache::get($this->versionKey($userId), 1);

        return "auth:perms:{$userId}:v{$version}:" . ($workspaceId ?? 'none');
    }

    private function versionKey(string $userId): string
    {
        return "auth:perms-version:{$userId}";
    }
}
