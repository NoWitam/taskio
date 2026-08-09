<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror of the SHADOW DRAFT columns, CHECK constraint included. See the central
 * migration for what a shadow is, why the target's revision is frozen, and why the "a shadow is always
 * a draft" invariant is enforced by the database rather than only by the service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->uuid('targets_entry_id')->nullable()->index();
            $table->uuid('target_revision_id')->nullable();
        });

        // `ALTER TABLE … ADD CONSTRAINT` is not portable; the module is pgsql-only by design and the
        // migrator points DB:: at the tenant connection for the duration of the run (same posture as
        // the chunk table's raw pgvector DDL).
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'alter table knowledge_entries add constraint knowledge_entries_shadow_is_draft
             check (targets_entry_id is null or draft_session_id is not null)'
        );
    }

    public function down(): void
    {
        DB::statement('alter table knowledge_entries drop constraint if exists knowledge_entries_shadow_is_draft');

        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropColumn(['targets_entry_id', 'target_revision_id']);
        });
    }
};
