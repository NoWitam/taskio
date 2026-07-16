<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B4: the interactive model allows MANY runs per task (initial + resumes + revisions),
 * each recording its own `task_started` action. The old partial unique index that
 * capped task_started to one row per (bot_id, task_id) is therefore dropped —
 * concurrency is now guarded by the atomic run-state claim on the tasks table instead.
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
