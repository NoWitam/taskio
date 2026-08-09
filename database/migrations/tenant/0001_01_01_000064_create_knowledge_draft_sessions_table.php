<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for a KNOWLEDGE DRAFTING SESSION. Mirrors the central table
 * MINUS workspace_id (the whole tenant DB is one workspace). See the central migration for why the
 * drafts themselves are entries rather than rows of their own, and why `retrieval_set` /
 * `relations_cache` are declared before the batches that fill them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_draft_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();

            $table->text('source_text');
            $table->string('status')->default('idle')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->jsonb('prompt_history')->default('[]');
            $table->jsonb('relations_cache')->nullable();
            $table->jsonb('retrieval_set')->nullable();

            $table->string('seed_slug')->nullable();
            $table->string('seed_title')->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();

            $table->index(['creator_type', 'creator_id']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_draft_sessions');
    }
};
