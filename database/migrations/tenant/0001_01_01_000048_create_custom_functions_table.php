<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for user CUSTOM FUNCTIONS (Phase 3a). Mirrors central
 * `custom_functions` MINUS workspace_id (the whole tenant DB is one workspace). creator_id references
 * the CENTRAL users table and carries no FK (no cross-DB constraint). Identity is the uuid (`fn:<uuid>`);
 * `name` is a user-facing label only (no unique). See the central migration for the column rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_functions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->text('description')->nullable();

            $table->string('input_type');
            $table->json('args');
            $table->string('return_type');

            $table->json('body');

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();

            $table->index(['creator_type', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_functions');
    }
};
