<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Adds the self-referential parent_id link to
 * `form_content_versions` (central add_parent_id_and_indexing_started_at). Kept in its
 * own alter migration, exactly like the central schema: Postgres rejects a self-FK
 * declared inside the original create statement, so the table/PK must already exist.
 * This intra-tenant FK is KEPT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_content_versions', function (Blueprint $table) {
            $table->foreignUuid('parent_id')
                ->nullable()
                ->after('form_id')
                ->constrained('form_content_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('form_content_versions', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
