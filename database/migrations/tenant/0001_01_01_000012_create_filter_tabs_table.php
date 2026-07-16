<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `filter_tabs` MINUS
 * workspace_id. The whole database is one workspace, so the workspace_id column and its
 * place in the composite indexes/unique are dropped — the constraints collapse onto
 * (user_id, context[, sort_order|name]). user_id references the CENTRAL users table and
 * carries no FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filter_tabs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('context');
            $table->string('name');
            $table->string('icon')->nullable();
            $table->json('filters');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'context']);
            $table->index(['user_id', 'context', 'sort_order']);
            $table->unique(['user_id', 'context', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filter_tabs');
    }
};
