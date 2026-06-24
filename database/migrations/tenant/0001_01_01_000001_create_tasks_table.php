<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors the central `tasks` table MINUS
 * workspace_id. creator_id / assigned_id reference the CENTRAL users table, so they
 * are kept as plain uuid columns WITHOUT a foreign key (Postgres has no cross-database
 * FKs). form_id / approval_pipeline_id are added by later create migrations once those
 * intra-tenant parents exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->index();
            $table->text('priority')->index();
            $table->date('deadline')->nullable();
            $table->uuid('creator_id');
            $table->uuid('assigned_id');
            $table->timestamp('archived_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
