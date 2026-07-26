<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `folders` MINUS workspace_id (the
 * whole tenant DB is one workspace) — which also means the sibling-name unique constraint is
 * (parent_id, name) here. creator_id references the CENTRAL users table and carries no FK
 * (no cross-DB constraint).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->uuid('parent_id')->nullable()->index();

            // Materialized path of ANCESTOR ids: '/{root}/{child}/' (a root folder's is '/').
            $table->string('path', 400);

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['parent_id', 'name']);
        });

        DB::statement('CREATE INDEX folders_path_prefix_idx ON folders (path text_pattern_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
