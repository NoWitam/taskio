<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (shared-database) columns backing the SUSPEND/RESUME engine: a step may park its run in
 * `waiting` until some external work settles, and a later, FRESH job resumes it from the DB.
 *
 * ADDITIVE on purpose — `workflow_runs` is a live, committed table, so its create migration is left
 * untouched and these three NULLABLE columns arrive here. A workflow WITHOUT a suspending step never
 * writes them (they stay NULL for the whole run), which is the byte-identical-behavior guarantee.
 *
 *   waiting_on     descriptive, KIND-AGNOSTIC record of what the run is parked on:
 *                  {kind, step_key, step_type, position, payload:{...}}. Both the step KEY and its
 *                  POSITION are stored so the resume can detect a definition that drifted during the
 *                  wait (reordered/edited steps) instead of silently skipping work.
 *   waiting_key    an opaque correlation key (e.g. `<kind>:<uuid>`), INDEXED. A separate plain column
 *                  ON PURPOSE: an index-backed equality lookup works identically on every driver,
 *                  unlike a JSON-path predicate (whose Postgres json-vs-jsonb behavior is a
 *                  portability trap). This is what a settle listener looks a run up by.
 *   waiting_since  when the wait started — the LAST-RESORT stale-waiting sweep's cutoff.
 *
 * The (state, waiting_since) index backs that sweep, mirroring the existing (state, started_at) index
 * the stale-RUNNING reaper scans. The own-database mirror in database/migrations/tenant is identical
 * (none of these columns carry workspace_id).
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
