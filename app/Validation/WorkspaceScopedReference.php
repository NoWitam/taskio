<?php

namespace App\Validation;

use App\Traits\ScopedToWorkspaceMembers;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Source of truth for the set of TABLES whose model carries a workspace-ish global
 * scope — i.e. tables that must NEVER be referenced by a raw `exists:` rule, because
 * the DB presence verifier bypasses those scopes and leaks cross-workspace rows.
 *
 * Built by DISCOVERY (not a hardcoded list) so new workspace-aware models are covered
 * automatically: any model using {@see TenantAware} contributes its `getTable()`, and
 * any model using {@see ScopedToWorkspaceMembers} (today only User) contributes `users`.
 * Used by {@see \Tests\Feature\WorkspaceScopedExistsArchTest} to fail the build when a
 * FormRequest reaches for a raw `exists:<table>` instead of {@see \App\Rules\ScopedExists}.
 */
class WorkspaceScopedReference
{
    /** @var array<int, string>|null */
    private static ?array $tables = null;

    /**
     * Tables whose model is gated by a workspace global scope.
     *
     * @return array<int, string>
     */
    public static function tables(): array
    {
        if (self::$tables !== null) {
            return self::$tables;
        }

        $tables = [];

        foreach (self::modelFiles() as $class) {
            $traits = self::traitsOf($class);

            if (!in_array(TenantAware::class, $traits, true)
                && !in_array(ScopedToWorkspaceMembers::class, $traits, true)) {
                continue;
            }

            /** @var Model $instance */
            $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            $tables[] = $instance->getTable();
        }

        return self::$tables = array_values(array_unique($tables));
    }

    /**
     * Clear the discovery cache (intended for tests that plant temporary models).
     */
    public static function flush(): void
    {
        self::$tables = null;
    }

    /**
     * Resolve every candidate model class: User plus every `app/modules/**\/Models/*.php`.
     *
     * @return array<int, class-string<Model>>
     */
    private static function modelFiles(): array
    {
        $classes = [\App\Models\User::class];

        $finder = (new Finder)
            ->files()
            ->in(app_path('modules'))
            ->path('Models')
            ->name('*.php');

        foreach ($finder as $file) {
            $class = self::classFromFile($file);

            if ($class !== null && is_subclass_of($class, Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private static function classFromFile(SplFileInfo $file): ?string
    {
        $contents = $file->getContents();

        if (!preg_match('/^namespace\s+(.+?);/m', $contents, $ns)
            || !preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $contents, $cls)) {
            return null;
        }

        $class = $ns[1] . '\\' . $cls[1];

        return class_exists($class) ? $class : null;
    }

    /**
     * All traits used by a class, recursively (including those used by parent classes
     * and by other traits).
     *
     * @param  class-string  $class
     * @return array<int, string>
     */
    private static function traitsOf(string $class): array
    {
        $traits = [];

        do {
            $traits = array_merge($traits, class_uses($class) ?: []);
            $class = get_parent_class($class);
        } while ($class !== false);

        foreach ($traits as $trait) {
            $traits = array_merge($traits, class_uses($trait) ?: []);
        }

        return array_values(array_unique($traits));
    }
}
