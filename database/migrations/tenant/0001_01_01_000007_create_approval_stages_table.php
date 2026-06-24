<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `approval_stages`. This is a
 * child of `approval_pipelines` (no workspace_id even centrally — isolated through its
 * scoped parent), so the intra-tenant FK to `approval_pipelines` is KEPT. approver_id
 * references the CENTRAL users table and carries no FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('approval_pipeline_id')->constrained('approval_pipelines')->cascadeOnDelete();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->string('approver_type')->index();
            $table->uuid('approver_id')->nullable();
            $table->unsignedInteger('order');
            $table->timestamps();

            $table->unique(['approval_pipeline_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_stages');
    }
};
