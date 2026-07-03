<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database (db_mode = own) mirror of the interactive bot run-state columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('bot_run_state')->default('idle')->after('assignee_id');
            $table->unsignedInteger('bot_runs_used')->default(0)->after('bot_run_state');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['bot_run_state', 'bot_runs_used']);
        });
    }
};
