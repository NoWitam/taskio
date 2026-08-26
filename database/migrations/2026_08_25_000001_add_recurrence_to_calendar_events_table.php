<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R3 B4 — A CALENDAR EVENT MAY NOW REPEAT, and the rule lives in TWO COLUMNS on the event itself.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THERE IS NO `calendar_event_series` TABLE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A separate series table earns its keep only when a single OCCURRENCE can be overridden — when the
 * third Tuesday of the month has its own title, its own hour, its own row pointing back at a parent.
 * That is the feature this batch deliberately does not build. Without it, a series is exactly one
 * repeating description of one event, which is a property OF that event and belongs on its row: a
 * table would add a join, a lifecycle and a second place for the all-day discriminator to be true or
 * false, and would buy nothing back.
 *
 * The two columns below are therefore the WHOLE model:
 *
 *   `recurrence`        the cadence, as the shared layer's own v2 descriptor. NULL means the event
 *                       happens once — the state every existing row is in, and the state every write
 *                       that says nothing about repeating stays in.
 *   `recurrence_until`  the last calendar DAY the series may place an occurrence on, or NULL for a
 *                       series with no end.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE ANCHOR IS THE EVENT'S OWN START — WHICH IS WHY THERE IS NO THIRD COLUMN
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A series is anchored at `start_date` / `starts_at`, the columns the row already has, and the write
 * path REFUSES a rule the anchor does not itself satisfy (a Monday-anchored event with a
 * fires-on-Tuesdays rule is a 422, not a series whose first occurrence is a week after its start).
 * That refusal is what keeps "start" meaning FIRST OCCURRENCE rather than "the point we begin counting
 * from" — and it is what makes splitting a series at an occurrence correct by construction instead of
 * by care.
 *
 * The series' DURATION is anchored the same way: `ends_at - starts_at`, applied as a fixed interval to
 * every occurrence, so an hour-long meeting stays an hour long across a daylight-saving transition.
 * No column, because the anchor already answers it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE TIMEZONE IS STAMPED INTO `recurrence`, NOT RE-DERIVED ON READ
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The descriptor's own `tz` key is written at SAVE time from the workspace's timezone and never
 * recomputed. That is not caution, it is the only choice that makes a series behave like the set of
 * single events it stands for: a timed event stores an absolute instant, so changing the workspace's
 * timezone already moves its wall-clock hour. A series that re-derived its zone on every read would
 * instead keep its wall-clock hour and move its instants — the opposite behaviour, in the same grid,
 * for two things a user cannot tell apart.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * "REPEAT N TIMES" IS STORED AS A DAY, AND THE CONSEQUENCE IS NAMED
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A count is resolved to `recurrence_until` ONCE, at write time, by walking the cadence N times. The
 * alternative — storing the count and counting at read time — makes a far-future window cost N
 * projections to answer a question about six weeks.
 *
 * The consequence, stated so nobody discovers it as a bug: deleting one occurrence afterwards does NOT
 * extend the series to keep the promised count. Twelve weekly occurrences minus one is eleven, not
 * twelve-spread-over-thirteen-weeks. "The series runs until day D" is the rule the data actually
 * expresses, and it is the simpler one to explain.
 *
 * INDEXES: none added here, deliberately. The projection that reads these columns is the NEXT batch,
 * and an index chosen before its query is a guess that has to be migrated away from. The existing
 * `(workspace_id, start_date)` / `(workspace_id, starts_at)` pairs still cover every read that exists
 * today, including the anchor row of a series.
 *
 * The own-database mirror is
 * database/migrations/tenant/0001_01_01_000076_add_recurrence_to_calendar_events_table.php — identical
 * columns, because the same models read both databases. CalendarTenantDatabaseTest compares the two
 * column sets directly, so a mirror that drifts fails at once rather than for one customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            // The v2 recurrence descriptor, exactly as App\Support\Recurrence understands it — the
            // Calendar accepts a NARROW SUBSET of that grammar and stores nothing it did not build.
            $table->json('recurrence')->nullable()->after('ends_at');

            // A DAY, not an instant: the series has one time of day, so the day is the whole answer.
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
