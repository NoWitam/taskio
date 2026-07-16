<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) tasks: interactive bot run-state.
 *
 * B4 turns the bot into an interactive participant, so a task can see many runs
 * (initial + resume-after-reply + revision-after-reject). Two columns drive the loop:
 *
 *   - bot_run_state: idle | running | waiting. A run is CLAIMED via an atomic
 *     conditional UPDATE (state -> running, guarded by the current state + run cap),
 *     so at most one run is active per task even on a non-sync queue.
 *   - bot_runs_used: monotonic run counter, incremented at claim time and compared
 *     against config('ai.max_runs_per_task') to enforce the hard cap.
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
