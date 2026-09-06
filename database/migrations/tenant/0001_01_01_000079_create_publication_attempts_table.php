<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own) for a PUBLICATION ATTEMPT. Mirrors central
 * `publication_attempts` MINUS `workspace_id`.
 *
 * See the central migration
 * (database/migrations/2026_09_06_000001_create_publication_attempts_table.php) for why this is a
 * table rather than a column, why it is append-only (`created_at` alone) and never rolled back with
 * the outcome it records, why `publication_id` carries no foreign key, and why `request` may never
 * hold anything derived from a credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('publication_id')->index();

            $table->string('platform', 32);
            $table->string('phase', 16);

            $table->boolean('succeeded');
            $table->unsignedSmallInteger('attempt')->default(1);

            $table->string('remote_draft_id')->nullable();
            $table->string('remote_id')->nullable();

            $table->jsonb('request')->nullable();

            $table->string('failure_code', 64)->nullable();
            $table->jsonb('failure_context')->nullable();

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['publication_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_attempts');
    }
};
