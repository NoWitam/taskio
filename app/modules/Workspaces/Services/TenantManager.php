<?php

namespace App\Modules\Workspaces\Services;

use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Registers and tears down the dynamic "tenant" database connection used by
 * own-database workspaces. Tenant-aware models route their queries to this
 * connection while such a workspace is active (see TenantAware::getConnectionName).
 */
class TenantManager
{
    public const CONNECTION = 'tenant';

    public function configure(Workspace $workspace): void
    {
        Config::set('database.connections.' . self::CONNECTION, $workspace->connectionConfig());

        DB::purge(self::CONNECTION);
    }

    public function forget(): void
    {
        DB::purge(self::CONNECTION);
    }
}
