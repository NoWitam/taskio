<?php

namespace App\Modules\Auth\Support;

/**
 * The set of assignable permissions, sourced from the per-module permission
 * declarations in config/modules.php. Group permissions must come from this set.
 */
class PermissionRegistry
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return collect(config('modules.modules', []))
            ->flatMap(fn (array $module): array => $module['permissions'] ?? [])
            ->unique()
            ->values()
            ->all();
    }

    public static function has(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }
}
