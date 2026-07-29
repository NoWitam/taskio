<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror of the central
 * `2026_08_04_000000_add_waiting_columns_to_workflow_runs_table` migration: the three nullable
 * suspend/resume columns + the stale-waiting sweep index. Identical to the central twin — none of
 * these columns carry workspace_id (the whole tenant DB is one workspace).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->json('waiting_on')->nullable()->after('context');
            $table->string('waiting_key')->nullable()->after('waiting_on')->index();
            $table->timestamp('waiting_since')->nullable()->after('waiting_key');

            $table->index(['state', 'waiting_since']);
        });
    }

    public function down(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropIndex(['state', 'waiting_since']);
            $table->dropIndex(['waiting_key']);
            $table->dropColumn(['waiting_on', 'waiting_key', 'waiting_since']);
        });
    }
};
