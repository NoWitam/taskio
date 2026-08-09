<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Services\TenantManager;
use App\Modules\Workspaces\Services\WorkspaceProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Real-DDL check that the Knowledge schema PROVISIONS on an own-database workspace. GUARDED behind
 * TENANT_DB_TESTS=1 (it issues CREATE DATABASE / DROP DATABASE), mirroring
 * WorkspaceProvisioningIntegrationTest — which it deliberately does not extend, so the two can be run
 * independently.
 *
 * This is the test the module most needs and the one a shared-database test suite can never give it.
 * A tenant database is created EMPTY and inherits nothing from the central one — extensions included
 * — so the `vector` extension has to be installed by the module's own first tenant migration. If it
 * were not, everything would keep passing here in shared mode and provisioning would break for
 * own-database workspaces only: late, in production, for one customer at a time.
 */
class KnowledgeTenantSchemaTest extends TestCase
{
    private ?Workspace $workspace = null;

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
    }

    public function test_provisioning_creates_the_knowledge_schema_with_the_vector_extension(): void
    {
        $user = User::factory()->create();

        $this->workspace = Workspace::factory()->create([
            'owner_id' => $user->id,
            'db_mode' => 'own',
            'status' => 'provisioning',
        ]);

        $provisioner = app(WorkspaceProvisioner::class);
        $this->tenantDatabase = $provisioner->databaseName($this->workspace);

        $provisioner->provision($this->workspace);

        $connection = DB::connection(TenantManager::CONNECTION);
        $schema = Schema::connection(TenantManager::CONNECTION);

        // The extension the chunk table's vector column depends on — installed by the module's own
        // FIRST tenant migration, because a fresh tenant database inherits nothing.
        $extension = $connection->selectOne("select extname from pg_extension where extname = 'vector'");
        $this->assertNotNull($extension, 'the tenant database must have the pgvector extension');

        foreach ([
            'knowledge_bases',
            'knowledge_entries',
            'knowledge_entry_revisions',
            'knowledge_links',
            'knowledge_entry_chunks',
            'knowledge_bindings',
            'knowledge_draft_sessions',
            // The typed-relation layer. `knowledge_relation_events` being HERE is the decisive reason
            // it is not the shared `changelogs` table: that one exists only centrally, so an
            // own-database workspace could not log a relation change anywhere at all.
            'knowledge_relations',
            'knowledge_relation_events',
        ] as $table) {
            $this->assertTrue($schema->hasTable($table), "expected the tenant {$table} table to exist");

            // One tenant database = one workspace, so there is no per-row scoping column.
            $this->assertFalse(
                $schema->hasColumn($table, 'workspace_id'),
                "tenant table {$table} must not carry a workspace_id column",
            );
        }

        // The AI COMPOSER's columns on the entries table. They are what make a draft a draft, so a
        // tenant database missing them would not merely lack a feature — `draft_session_id` is the
        // column the global invisibility scope filters on, and a query against a column that is not
        // there fails on the FIRST read of any entry, taking the whole knowledge module down for that
        // workspace.
        foreach (['draft_session_id', 'targets_entry_id', 'target_revision_id'] as $column) {
            $this->assertTrue(
                $schema->hasColumn('knowledge_entries', $column),
                "expected the tenant knowledge_entries.{$column} column to exist",
            );
        }

        foreach (['context_expanded_at', 'notes', 'resolution_set', 'graph_ops', 'applied_ops'] as $column) {
            $this->assertTrue(
                $schema->hasColumn('knowledge_draft_sessions', $column),
                "expected the tenant knowledge_draft_sessions.{$column} column to exist",
            );
        }

        // The typed-relation columns on the tables that layer does not own.
        $this->assertTrue($schema->hasColumn('knowledge_entries', 'entry_type'));
        $this->assertTrue($schema->hasColumn('knowledge_bases', 'relation_types'));

        // The shadow invariant is enforced by the DATABASE, so the mirror has to carry the constraint
        // too — without it a tenant workspace could hold a row the central schema makes impossible.
        $constraint = $connection->selectOne(
            "select conname from pg_constraint where conname = 'knowledge_entries_shadow_is_draft'"
        );

        $this->assertNotNull($constraint, 'the tenant schema must carry the shadow-is-draft CHECK constraint');

        // Same argument for the relation self-check: a self-relation is never a fact, and a tenant
        // database without the constraint could hold a row the central schema makes impossible.
        $selfCheck = $connection->selectOne(
            "select conname from pg_constraint where conname = 'knowledge_relations_not_self'"
        );

        $this->assertNotNull($selfCheck, 'the tenant schema must carry the relation self-reference CHECK constraint');

        // The vector column + its HNSW index must exist on the tenant side too.
        $type = $connection->selectOne(
            "select format_type(a.atttypid, a.atttypmod) as type
             from pg_attribute a
             join pg_class c on c.oid = a.attrelid
             where c.relname = 'knowledge_entry_chunks' and a.attname = 'embedding'"
        );

        $this->assertNotNull($type);
        $this->assertSame('vector(' . (int) config('knowledge.embedding.dimensions') . ')', $type->type);

        $index = $connection->selectOne(
            "select indexdef from pg_indexes
             where tablename = 'knowledge_entry_chunks' and indexname = 'knowledge_entry_chunks_embedding_hnsw'"
        );

        $this->assertNotNull($index, 'the tenant chunk table must carry the HNSW index');
    }

    protected function tearDown(): void
    {
        // Drop ONLY the generated tenant database — never the central app DB.
        if ($this->tenantDatabase !== null) {
            app(TenantManager::class)->forget();

            $default = config('database.default');
            DB::connection($default)->statement('drop database if exists "' . $this->tenantDatabase . '"');
        }

        if ($this->workspace !== null) {
            $this->workspace->forceDelete();
            $this->workspace->owner?->forceDelete();
        }

        parent::tearDown();
    }
}
