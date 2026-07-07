<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) tasks: when a bot run was CLAIMED.
 *
 * The stale-claim reaper (`bots:reap-stale-runs`) needs to know how long a task has been
 * sitting in `bot_run_state = running` so it can release runs stranded by a worker that
 * died mid-run (SIGKILL/OOM never fires the job's failed() hook). The claim stamps this
 * atomically alongside the state flip; release clears it back to null.
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
