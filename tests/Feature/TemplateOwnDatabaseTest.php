<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Generator\Models\Template;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TEMPLATE persistence in OWN-database (tenant) mode — the second db_mode. A template created while an
 * own-database workspace is active routes to the DEDICATED tenant connection (no workspace_id column,
 * because the whole database is the tenant) and never lands in the central database. This proves the
 * TenantAware behavior + the tenant table mirror for `templates`, alongside the shared-mode isolation
 * that TemplateCrudTest pins.
 */
class TemplateOwnDatabaseTest extends TestCase
{
    use RefreshDatabase;

    /** Create the `templates` schema on the tenant connection (mirrors the tenant migration, no workspace_id). */
    private function createTenantTemplatesTable(): void
    {
        Schema::connection(TenantManager::CONNECTION)->create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('content_type');
            $table->json('slots');
            $table->json('content');
            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();
            $table->timestamps();
            $table->index(['creator_type', 'creator_id']);
        });
    }

    public function test_a_template_created_in_own_mode_lives_in_the_tenant_database(): void
    {
        $user = User::factory()->create();

        $dbFile = tempnam(sys_get_temp_dir(), 'tenant_') . '.sqlite';
        touch($dbFile);

        $workspace = Workspace::factory()->create([
            'owner_id' => $user->id,
            'db_mode' => 'own',
            'db_driver' => 'sqlite',
            'db_database' => $dbFile,
        ]);

        app(TenantManager::class)->configure($workspace);
        $this->createTenantTemplatesTable();

        app(TenantContext::class)->set($workspace);

        try {
            $template = Template::create([
                'name' => 'Tenant post',
                'content_type' => 'post',
                'slots' => [['name' => 'topic', 'descriptor' => ['base' => 'text']]],
                'content' => ['body' => ['markdown' => 'Body']],
                'creator_id' => $user->id,
            ]);

            // The model routes to the tenant connection...
            $this->assertSame(TenantManager::CONNECTION, $template->getConnectionName());

            // ...and the row lives in the tenant database, not the central one.
            $this->assertSame(1, DB::connection(TenantManager::CONNECTION)->table('templates')->count());
            $this->assertSame(0, DB::connection(config('database.default'))->table('templates')->count());
        } finally {
            app(TenantContext::class)->clear();
            @unlink($dbFile);
        }
    }
}
