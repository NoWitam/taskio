<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for a KNOWLEDGE BASE. Mirrors central `knowledge_bases`
 * MINUS workspace_id (the whole tenant DB is one workspace). creator_id references the CENTRAL users
 * table with no cross-DB constraint. See the central migration for the column rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_bases', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->text('description')->nullable();
            $table->text('charter')->nullable();

            $table->jsonb('metadata_schema')->default('[]');

            $table->string('language', 5);

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['creator_type', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_bases');
    }
};
