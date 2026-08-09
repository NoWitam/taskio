<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for a KNOWLEDGE ENTRY REVISION. Mirrors central
 * `knowledge_entry_revisions` MINUS workspace_id. Append-only: created_at without updated_at, no soft
 * deletes. author_id references the CENTRAL users table with no cross-DB constraint. See the central
 * migration for the rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entry_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('knowledge_entry_id')->index()->constrained('knowledge_entries')->cascadeOnDelete();

            $table->string('title');
            $table->text('content');
            $table->jsonb('metadata')->default('{}');

            $table->string('change_note')->nullable();

            $table->uuid('author_id')->nullable();
            $table->string('author_type')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['author_type', 'author_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_entry_revisions');
    }
};
