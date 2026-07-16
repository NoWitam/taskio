<?php

namespace Tests\Unit;

use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use RuntimeException;
use Tests\TestCase;

class WorkspaceProvisionerTest extends TestCase
{
    private function provisioner(): WorkspaceProvisioner
    {
        return new WorkspaceProvisioner(new TenantManager);
    }

    private function workspace(string $id): Workspace
    {
        $workspace = new Workspace;
        $workspace->forceFill(['id' => $id]);

        return $workspace;
    }

    public function test_database_name_is_a_fixed_tenant_hex_format(): void
    {
        $workspace = $this->workspace('0f8fad5b-d9cb-469f-a165-70867728950e');

        $this->assertSame(
            'tenant_0f8fad5bd9cb469fa16570867728950e',
            $this->provisioner()->databaseName($workspace)
        );
    }

    public function test_postgres_statement_uses_a_quoted_identifier(): void
    {
        $this->assertSame(
            'create database "tenant_abc"',
            $this->provisioner()->createDatabaseStatement('pgsql', 'tenant_abc')
        );
    }

    public function test_mysql_statement_is_idempotent_with_if_not_exists(): void
    {
        $this->assertSame(
            'create database if not exists `tenant_abc`',
            $this->provisioner()->createDatabaseStatement('mysql', 'tenant_abc')
        );

        $this->assertSame(
            'create database if not exists `tenant_abc`',
            $this->provisioner()->createDatabaseStatement('mariadb', 'tenant_abc')
        );
    }

    public function test_own_mode_is_refused_on_sqlite_with_a_clear_message(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sqlite');

        $this->provisioner()->createDatabaseStatement('sqlite', 'tenant_abc');
    }

    public function test_unsupported_driver_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->provisioner()->createDatabaseStatement('sqlsrv', 'tenant_abc');
    }
}
