<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for a KNOWLEDGE ENTRY CHUNK — the unit of RETRIEVAL, and the
 * only table in this module that carries vectors. Mirrors central `knowledge_entry_chunks` MINUS
 * workspace_id, including the raw `vector(1536)` column and the HNSW cosine index (both raw DDL,
 * because Blueprint has no pgvector type). The extension it needs is installed by the FIRST tenant
 * migration of this module. See the central migration for the full rationale.
 */
return new class extends Migration
{
    /** Must match config('knowledge.embedding.dimensions') — see the central migration. */
    private const DIMENSIONS = 1536;

    public function up(): void
    {
        Schema::create('knowledge_entry_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('knowledge_entry_id')->constrained('knowledge_entries')->cascadeOnDelete();
            $table->uuid('knowledge_base_id')->index();

            $table->smallInteger('ordinal');
            $table->string('heading_path', 500)->nullable();
            $table->text('content');
            $table->integer('char_start');
            $table->integer('char_length');
            $table->char('digest', 64)->index();

            $table->string('embedding_model')->nullable();
            $table->timestamp('indexed_at')->nullable();

            $table->timestamps();

            $table->unique(['knowledge_entry_id', 'ordinal']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE knowledge_entry_chunks ADD COLUMN embedding vector(' . self::DIMENSIONS . ')');

        DB::statement(
            'CREATE INDEX knowledge_entry_chunks_embedding_hnsw ON knowledge_entry_chunks '
            . 'USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_entry_chunks');
    }
};
