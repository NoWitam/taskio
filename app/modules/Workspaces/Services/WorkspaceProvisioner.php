<?php

namespace App\Modules\Workspaces\Services;

use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates and migrates the dedicated database that backs an own-mode workspace.
 *
 * The tenant database lives on the SAME server as the default connection: only
 * its name is workspace-specific (a fixed-format, generated identifier — no user
 * input — so there is no injection surface). Host/driver/user/pass stay null on
 * the workspace and are inherited from the base server via connectionConfig().
 */
class WorkspaceProvisioner
{
    public function __construct(
        private TenantManager $tenants,
    ) {}

    /**
     * Deterministic, collision-free database name for a workspace. Always
     * `tenant_<32 hex>`, derived from the workspace uuid — never from user input.
     */
    public function databaseName(Workspace $workspace): string
    {
        return 'tenant_' . str_replace('-', '', (string) $workspace->id);
    }

    /**
     * Create the tenant database (if missing) and run its schema. Idempotent on
     * retry: CREATE is guarded against "already exists" and migrations are
     * tracked by the tenant migrations table.
     */
    public function provision(Workspace $workspace): void
    {
        $this->createDatabase($workspace);
        $this->migrate($workspace);
    }

    /**
     * Create the dedicated database on the default server and persist its name
     * onto the workspace (before migrating, so the tenant connection can reach
     * it). Idempotent: an existing database is left untouched.
     */
    public function createDatabase(Workspace $workspace): void
    {
        $name = $this->databaseName($workspace);
        $driver = $this->serverDriver();
        $connection = DB::connection($this->defaultConnectionName());

        // Skip the CREATE when the database already exists so retries are no-ops.
        if (!$this->databaseExists($connection, $driver, $name)) {
            $connection->statement($this->createDatabaseStatement($driver, $name));
        }

        $workspace->forceFill(['db_database' => $name])->save();
    }

    /**
     * Build the driver-specific CREATE DATABASE statement for a generated tenant
     * name. Pure (no DB access) so it can be asserted in isolation. Throws for
     * drivers that cannot host a server-side database (e.g. sqlite).
     */
    public function createDatabaseStatement(string $driver, string $name): string
    {
        return match ($driver) {
            // Quoting the generated `tenant_<hex>` identifier (never user input)
            // is sufficient to keep the statement safe.
            'pgsql' => 'create database "' . $name . '"',
            'mysql', 'mariadb' => 'create database if not exists `' . $name . '`',
            'sqlite' => throw new RuntimeException(
                'Own-database workspaces are not supported on the sqlite driver; '
                . 'sqlite cannot host server-side databases.'
            ),
            default => throw new RuntimeException(
                "Own-database provisioning is not supported for the [{$driver}] driver."
            ),
        };
    }

    /**
     * Run the tenant schema against the workspace's dedicated connection. This
     * is the single migrate path shared by the provisioner, the job and the
     * console command.
     */
    public function migrate(Workspace $workspace): void
    {
        $this->tenants->configure($workspace);

        Artisan::call('migrate', [
            '--database' => TenantManager::CONNECTION,
            '--path' => self::TENANT_MIGRATION_PATH,
            '--force' => true,
        ]);
    }

    /** Path (relative to the app root) holding the own-mode tenant schema. */
    public const TENANT_MIGRATION_PATH = 'database/migrations/tenant';

    /**
     * Whether the target database already exists. Postgres cannot CREATE DATABASE
     * inside a transaction and has no IF NOT EXISTS, so it is checked against
     * pg_database; MySQL/MariaDB rely on CREATE DATABASE IF NOT EXISTS instead.
     */
    private function databaseExists(Connection $connection, string $driver, string $name): bool
    {
        if ($driver !== 'pgsql') {
            return false;
        }

        return $connection->selectOne(
            'select 1 from pg_database where datname = ?',
            [$name]
        ) !== null;
    }

    private function serverDriver(): string
    {
        return (string) config('database.connections.' . $this->defaultConnectionName() . '.driver');
    }

    private function defaultConnectionName(): string
    {
        return (string) config('database.default');
    }
}
