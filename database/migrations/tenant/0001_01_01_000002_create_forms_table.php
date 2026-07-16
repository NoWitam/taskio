<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors the central `forms` table — composed
 * from create_forms_table + add_enabled_at + add_indexing_and_versioning_to_forms +
 * create_form_content_versions (content_updated_at) + add_parent_id_and_indexing_started_at
 * (indexing_started_at) — MINUS workspace_id. creator_id references the CENTRAL users
 * table, kept as a plain uuid column WITHOUT a foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->json('content');
            $table->boolean('is_anonymous')->default(false)->index();
            $table->timestamp('enabled_at')->nullable()->index();
            $table->timestamp('indexed_at')->nullable()->index();
            $table->unsignedInteger('content_version')->default(0);
            $table->json('content_backup')->nullable();
            $table->json('index_backup')->nullable();
            $table->timestamp('content_updated_at')->nullable();
            $table->timestamp('indexing_started_at')->nullable();
            $table->uuid('creator_id');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
