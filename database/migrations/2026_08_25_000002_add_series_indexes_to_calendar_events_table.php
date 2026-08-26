<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R3 B5 — THE INDEX THE PREVIOUS MIGRATION DELIBERATELY DEFERRED TO THIS BATCH.
 *
 * `2026_08_25_000001_add_recurrence_to_calendar_events_table` closes with: "INDEXES: none added here,
 * deliberately. The projection that reads these columns is the NEXT batch, and an index chosen before
 * its query is a guess that has to be migrated away from." The query now exists, so it is no longer a
 * guess, and this is the migration that handoff asked for.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE QUERY, AND WHY THE EXISTING PAIR DOES NOT COVER IT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The read source now issues four queries per calendar view, two of which select SERIES rows:
 *
 *     … WHERE workspace_id = ? AND recurrence IS NOT NULL
 *           AND starts_at <= <window end>
 *           AND (recurrence_until IS NULL OR recurrence_until >= ?)
 *       ORDER BY starts_at LIMIT 201
 *
 * The existing `(workspace_id, starts_at)` / `(workspace_id, start_date)` pair covers the ONE-SHOT
 * reads exactly, because those are bounded on BOTH sides — an event is on the grid on the day it
 * begins, so the query is a `BETWEEN` over a 62-day span.
 *
 * A SERIES READ HAS NO LOWER BOUND, and that is not an oversight either: a weekly meeting anchored
 * three years ago still draws squares this month, so the anchor filter can only be `<= window end`.
 * On the existing index that is a forward scan from the beginning of the workspace's history, with the
 * `recurrence IS NOT NULL` test applied per row — and since `recurrence` is not in the index, testing
 * it means a heap fetch per candidate. A workspace with fifty thousand ordinary events and three
 * series pays for fifty thousand of them, on a screen the client re-fetches on every month the user
 * pages through.
 *
 * A PARTIAL INDEX makes the scan proportional to the number of SERIES rather than to the number of
 * events, which is the only quantity the query actually cares about. Three series means three index
 * entries. The predicate is spelled exactly as the query spells it (`recurrence IS NOT NULL`), which
 * is what lets the planner match it.
 *
 * Partial indexes are Postgres-specific and written as raw SQL for that reason — the same shape (and
 * the same argument) as `bot_actions_task_started_unique`, which is the established precedent in this
 * codebase for an index the query builder cannot express.
 *
 * WHY TWO, not one over a coalesced expression: the two series reads are separate queries because an
 * all-day event lives in a `date` column and a timed one in a timestamp, and merging them would mean
 * converting a zone-free day into an instant — the one conversion the whole Calendar module is built
 * to refuse. Separate queries need separate indexes.
 *
 * The own-database mirror is
 * database/migrations/tenant/0001_01_01_000077_add_series_indexes_to_calendar_events_table.php — the
 * same two indexes without the `workspace_id` leading column, because a tenant database holds exactly
 * one workspace and its `calendar_events` table has no such column.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX calendar_events_series_start_date_idx
             ON calendar_events (workspace_id, start_date)
             WHERE recurrence IS NOT NULL'
        );

        DB::statement(
            'CREATE INDEX calendar_events_series_starts_at_idx
             ON calendar_events (workspace_id, starts_at)
             WHERE recurrence IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS calendar_events_series_start_date_idx');
        DB::statement('DROP INDEX IF EXISTS calendar_events_series_starts_at_idx');
    }
};
