<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-database (db_mode = own) mirror of the two PARTIAL indexes the series projection reads
 * through — see the central migration
 * (database/migrations/2026_08_25_000002_add_series_indexes_to_calendar_events_table.php) for the
 * whole argument: a series read has no lower bound on its anchor, so without a predicate on
 * `recurrence` it scans the workspace's entire event history on every calendar navigation.
 *
 * The `workspace_id` leading column is absent HERE and present there, which is the standing rule for
 * this whole directory rather than a difference in intent: a tenant database holds exactly one
 * workspace, so its `calendar_events` table has no such column and the index that would lead with it
 * indexes the date (or the instant) alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX calendar_events_series_start_date_idx
             ON calendar_events (start_date)
             WHERE recurrence IS NOT NULL'
        );

        DB::statement(
            'CREATE INDEX calendar_events_series_starts_at_idx
             ON calendar_events (starts_at)
             WHERE recurrence IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS calendar_events_series_start_date_idx');
        DB::statement('DROP INDEX IF EXISTS calendar_events_series_starts_at_idx');
    }
};
