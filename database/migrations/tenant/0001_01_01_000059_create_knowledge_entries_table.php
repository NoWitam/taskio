<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for a KNOWLEDGE ENTRY. Mirrors central `knowledge_entries`
 * MINUS workspace_id (the whole tenant DB is one workspace). Slug uniqueness stays a SERVICE rule
 * here too (the trash must be able to hold a same-slug row). See the central migration for the column
 * rationale, especially why there is no `embedding` column on an entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();

            $table->string('title');
            $table->string('slug')->index();
            $table->text('content');

            $table->jsonb('metadata')->default('{}');

            $table->string('status')->default('draft')->index();
            $table->timestamp('stale_at')->nullable()->index();
            $table->integer('position')->default(0);

            $table->smallInteger('chunks_count')->default(0);
            $table->char('index_digest', 64)->nullable();
            $table->char('indexed_digest', 64)->nullable();
            $table->string('index_status')->default('pending')->index();
            $table->timestamp('index_started_at')->nullable();
            $table->char('index_params', 16)->nullable();

            $table->uuid('current_revision_id')->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['creator_type', 'creator_id']);
            $table->index(['knowledge_base_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_entries');
    }
};
