<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror of KNOWLEDGE RELATIONS — the typed, human-approved statements between two
 * entries. One tenant database is one workspace, so there is no workspace_id column.
 *
 * See the central migration for the substance: why relations are a table of their own rather than a
 * column on the link CACHE (a sweep must not be able to reach them), why `to_entry_id` is NOT NULL
 * where a link's is nullable, why the vocabulary is enforced in code rather than by a CHECK, and why
 * the pair+type+valid_from uniqueness is a rule about the ACTIVE subset that no plain index can state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_relations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('knowledge_base_id')->index();

            $table->foreignUuid('from_entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->foreignUuid('to_entry_id')->constrained('knowledge_entries')->cascadeOnDelete();

            $table->string('relation_type', 40);
            $table->string('description', 300)->nullable();
            $table->jsonb('properties')->default('{}');

            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->string('state', 20)->default('active');
            // FK added after the create — Blueprint emits foreign keys before the primary key, so a
            // self-reference declared inline cannot find one to point at.
            $table->uuid('superseded_by_id')->nullable();

            $table->string('origin', 20);
            $table->uuid('draft_session_id')->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();

            $table->index(['creator_type', 'creator_id']);
            $table->index(['knowledge_base_id', 'from_entry_id', 'state']);
            $table->index(['knowledge_base_id', 'to_entry_id', 'state']);
            $table->index(['from_entry_id', 'to_entry_id', 'relation_type']);
        });

        Schema::table('knowledge_relations', function (Blueprint $table) {
            $table->foreign('superseded_by_id')->references('id')->on('knowledge_relations')->nullOnDelete();
        });

        // `ALTER TABLE … ADD CONSTRAINT` is not portable; the module is pgsql-only by design and the
        // migrator points DB:: at the tenant connection for the duration of the run (same posture as
        // the shadow-column mirror and the chunk table's raw pgvector DDL).
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'alter table knowledge_relations add constraint knowledge_relations_not_self
             check (from_entry_id <> to_entry_id)'
        );
    }

    public function down(): void
    {
        DB::statement('alter table knowledge_relations drop constraint if exists knowledge_relations_not_self');

        Schema::dropIfExists('knowledge_relations');
    }
};
