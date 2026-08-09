<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeEntryRevision;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `knowledge:purge-subject` against an OWN-DATABASE workspace. GUARDED behind TENANT_DB_TESTS=1 (it
 * issues CREATE DATABASE / DROP DATABASE), mirroring KnowledgeTenantSchemaTest.
 *
 * The shared-database suite cannot cover the one thing that is genuinely different here: the command
 * opens a TRANSACTION and runs its deletes against the connection the models resolve to, which for an
 * own-database workspace is the dynamic `tenant` connection and not the default one. A transaction
 * opened on the default connection would guard nothing, and queries issued there would find no rows —
 * so an erasure request against such a workspace would report "nothing matched" and be certified as
 * fulfilled while every row it was meant to destroy sat untouched in the tenant database.
 *
 * That failure is invisible in shared mode, which is exactly why it needs its own test: the two modes
 * differ in where the data lives, and this command's whole job is to leave nothing behind.
 */
class KnowledgeTenantSubjectPurgeTest extends TestCase
{
    private ?Workspace $workspace = null;

    private ?User $user = null;

    private ?string $tenantDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('TENANT_DB_TESTS') !== '1') {
            $this->markTestSkipped('Set TENANT_DB_TESTS=1 to run real tenant-database provisioning (issues DDL).');
        }

        if (config('database.connections.' . config('database.default') . '.driver') !== 'pgsql') {
            $this->markTestSkipped('Tenant-database provisioning requires the pgsql driver.');
        }

        config(['knowledge.index.enabled' => false]);
    }

    public function test_the_purge_reaches_an_own_database_workspace(): void
    {
        $this->user = User::factory()->create();

        $this->workspace = Workspace::factory()->create([
            'owner_id' => $this->user->id,
            'db_mode' => 'own',
            'status' => 'provisioning',
        ]);

        $provisioner = app(WorkspaceProvisioner::class);
        $this->tenantDatabase = $provisioner->databaseName($this->workspace);
        $provisioner->provision($this->workspace);
        $this->workspace->forceFill(['status' => WorkspaceStatus::Ready])->save();

        $this->useTenant();

        $base = KnowledgeBase::factory()->create(['creator_id' => $this->user->id]);

        $purged = KnowledgeEntry::factory()
            ->slugged('raport-q3')
            ->withContent('Ustalenia ze spotkania z Kowalska.')
            ->create(['knowledge_base_id' => $base->id, 'creator_id' => $this->user->id]);

        $kept = KnowledgeEntry::factory()
            ->slugged('cennik')
            ->withContent('Aktualny cennik.')
            ->create(['knowledge_base_id' => $base->id, 'creator_id' => $this->user->id]);

        $historical = KnowledgeEntryRevision::factory()->create([
            'knowledge_entry_id' => $kept->id,
            'content' => 'Stara wersja: ustalone z Kowalska.',
            'author_id' => $this->user->id,
        ]);

        // Let the COMMAND establish tenancy itself, exactly as an operator's shell would.
        app(TenantContext::class)->clear();
        app(TenantManager::class)->forget();

        $exit = Artisan::call('knowledge:purge-subject', [
            'workspace' => $this->workspace->id,
            'phrase' => ['Kowalska'],
            '--apply' => true,
            '--force' => true,
        ]);

        $this->assertSame(0, $exit, Artisan::output());

        $this->useTenant();

        $this->assertNull(
            KnowledgeEntry::withTrashed()->find($purged->id),
            'the entry lives on the tenant connection and must actually be destroyed there',
        );
        $this->assertNotNull(KnowledgeEntry::withTrashed()->find($kept->id), 'unrelated entries survive');
        $this->assertNull(
            KnowledgeEntryRevision::query()->find($historical->id),
            'and its history-only match is erased at revision granularity',
        );
    }

    private function useTenant(): void
    {
        app(TenantContext::class)->set($this->workspace);
        app(TenantManager::class)->configure($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        // Drop ONLY the generated tenant database — never the central app DB.
        if ($this->tenantDatabase !== null) {
            app(TenantManager::class)->forget();

            DB::connection(config('database.default'))
                ->statement('drop database if exists "' . $this->tenantDatabase . '"');
        }

        // No RefreshDatabase here (the provisioning DDL cannot run inside the suite transaction), so
        // the central rows this test made are removed by hand.
        $this->workspace?->forceDelete();
        $this->user?->forceDelete();

        parent::tearDown();
    }
}
