<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `form_submissions` — composed
 * from create_form_submissions_table + add_approved_at + add_soft_deletes +
 * form_content_version_id (create_form_content_versions) + indexed_at — MINUS workspace_id
 * and MINUS the transient content_version integer (added then dropped centrally).
 * form_id / form_content_version_id are intra-tenant FKs and are KEPT. creator_id and the
 * submittable morph point at central/runtime data and carry no FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('form_id')->constrained('forms')->cascadeOnDelete();
            $table->uuidMorphs('submittable');
            $table->json('data');
            $table->timestamp('approved_at')->nullable()->index();
            $table->foreignUuid('form_content_version_id')
                ->nullable()
                ->constrained('form_content_versions')
                ->nullOnDelete();
            $table->timestamp('indexed_at')->nullable();
            $table->uuid('creator_id');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
