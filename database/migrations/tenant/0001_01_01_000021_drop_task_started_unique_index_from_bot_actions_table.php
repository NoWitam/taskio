<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-database (db_mode = own) mirror: drop the task_started partial unique index —
 * many runs per task are now expected (concurrency is guarded by the tasks run-state).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS bot_actions_task_started_unique');
    }

    public function down(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX bot_actions_task_started_unique
             ON bot_actions (bot_id, task_id)
             WHERE type = \'task_started\''
        );
    }
};
