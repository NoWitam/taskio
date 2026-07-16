<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Labels\Models\Label;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OwnDatabaseTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_workspace_routes_models_to_a_dedicated_connection(): void
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

        // Register the tenant connection and create the labels schema there —
        // no workspace_id column, because the whole database is the tenant.
        app(TenantManager::class)->configure($workspace);

        Schema::connection(TenantManager::CONNECTION)->create('labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('color')->nullable();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        app(TenantContext::class)->set($workspace);

        try {
            $label = Label::create(['name' => 'TenantOnly']);

            $this->assertSame(TenantManager::CONNECTION, $label->getConnectionName());

            // The row lives in the tenant database, not the central one.
            $this->assertSame(1, DB::connection(TenantManager::CONNECTION)->table('labels')->count());
            $this->assertSame(0, DB::connection(config('database.default'))->table('labels')->count());
        } finally {
            app(TenantContext::class)->clear();
            @unlink($dbFile);
        }
    }

    public function test_models_use_the_default_connection_without_an_own_workspace(): void
    {
        $label = new Label;

        $this->assertNotSame(TenantManager::CONNECTION, $label->getConnectionName());
    }
}
