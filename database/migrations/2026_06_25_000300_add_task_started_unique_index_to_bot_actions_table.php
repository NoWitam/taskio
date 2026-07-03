<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Atomic idempotency guard for bot execution dispatch: at most ONE `task_started`
 * action per (bot_id, task_id). A PARTIAL unique index (Postgres) constrains only
 * task_started rows, so the many other action types (commented, form_filled, …) are
 * unaffected. This lets BotTaskExecutionService claim the dispatch with a single
 * insert-or-skip, safe even on a non-sync queue.
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
