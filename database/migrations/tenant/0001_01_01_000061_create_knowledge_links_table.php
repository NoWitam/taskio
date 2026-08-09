<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for a KNOWLEDGE LINK. Mirrors central `knowledge_links`
 * MINUS workspace_id. See the central migration for the ghost/source/evidence rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_links', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('knowledge_base_id')->index();

            $table->foreignUuid('from_entry_id')->index()->constrained('knowledge_entries')->cascadeOnDelete();
            $table->foreignUuid('to_entry_id')->nullable()->index()->constrained('knowledge_entries')->nullOnDelete();

            $table->string('target_slug');
            $table->string('source');

            $table->decimal('score', 5, 4)->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestamp('dismissed_at')->nullable();

            $table->timestamps();

            $table->unique(['from_entry_id', 'target_slug', 'source']);
            $table->index(['knowledge_base_id', 'target_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_links');
    }
};
