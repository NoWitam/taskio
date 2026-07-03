<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-database (db_mode = own) mirror of the partial unique index that makes bot
 * execution dispatch atomic — at most ONE `task_started` action per (bot_id, task_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX bot_actions_task_started_unique
             ON bot_actions (bot_id, task_id)
             WHERE type = \'task_started\''
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bot_actions_task_started_unique');
    }
};
