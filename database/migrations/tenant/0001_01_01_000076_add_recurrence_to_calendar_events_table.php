<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database mirror (db_mode = own) of R3 B4's recurrence columns.
 *
 * IDENTICAL to central here — unlike the create migration, which drops `workspace_id` because one
 * tenant database IS one workspace, there is nothing workspace-shaped about a cadence. Both columns
 * exist on both sides, with the same types and the same nullability.
 *
 * See the central migration
 * (database/migrations/2026_08_25_000001_add_recurrence_to_calendar_events_table.php) for why the rule
 * lives on the event row rather than in a series table, why the anchor is the event's own start, why
 * the timezone is stamped rather than re-derived, and why "repeat N times" is stored as a day.
 *
 * The parity is asserted, not trusted: CalendarTenantDatabaseTest compares the two column sets
 * directly, because the same models read both databases and a column present on one side only is a
 * defect visible to exactly one workspace — and invisible to everybody testing on the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->json('recurrence')->nullable()->after('ends_at');
            $table->date('recurrence_until')->nullable()->after('recurrence');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn(['recurrence', 'recurrence_until']);
        });
    }
};
