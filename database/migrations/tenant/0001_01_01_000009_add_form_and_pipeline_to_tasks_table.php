<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Adds the intra-tenant task links that the
 * central schema introduced after the base tasks table: form_id (update_tasks_table_add
 * _form_fields, minus the later-dropped form_mode column) and approval_pipeline_id
 * (add_approval_pipeline_id_to_tasks_table). Both are intra-tenant FKs and are KEPT.
 * Runs after the forms + approval_pipelines tenant tables exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUuid('form_id')->nullable()->after('assigned_id')->constrained('forms')->nullOnDelete();
            $table->foreignUuid('approval_pipeline_id')->nullable()->after('form_id')->constrained('approval_pipelines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['form_id']);
            $table->dropForeign(['approval_pipeline_id']);
            $table->dropColumn(['form_id', 'approval_pipeline_id']);
        });
    }
};
