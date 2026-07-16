<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `form_content_versions`.
 * This is a child of `forms` (no workspace_id even centrally — isolated through its
 * scoped parent), so the intra-tenant FK to `forms` is KEPT. The self-referential
 * parent_id FK is added in a SEPARATE alter migration (000014), mirroring the central
 * ordering — Postgres cannot resolve a self-FK inside the same create statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_content_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('form_id')->constrained('forms')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('content');
            $table->json('json_schema');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['form_id', 'version']);
            $table->index(['form_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_content_versions');
    }
};
