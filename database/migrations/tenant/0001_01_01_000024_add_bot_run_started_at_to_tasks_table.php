<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror: when a bot run was CLAIMED, for the
 * stale-claim reaper. See the central migration for the rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('bot_run_started_at')->nullable()->after('bot_runs_used');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('bot_run_started_at');
        });
    }
};
