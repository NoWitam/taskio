<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for workflow GLOBALS. Mirrors central `workflow_globals`
 * MINUS workspace_id (the whole tenant DB is one workspace). creator_id references the CENTRAL
 * users table and carries no FK (no cross-DB constraint). The key is unique across the tenant DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_globals', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('key')->unique();

            $table->json('descriptor');
            $table->json('value')->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamps();

            $table->index(['creator_type', 'creator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_globals');
    }
};
