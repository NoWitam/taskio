<?php

namespace Tests\Support\Schedule;

use App\Modules\Workflows\Services\WorkflowScheduleService;
use App\Support\Recurrence\LegacyScheduleUpgrader;
use App\Support\Recurrence\ScheduleCompiler;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * THE INPUT HALF of the schedule engine's golden matrix — the descriptors, the anchors and the
 * calls. The OUTPUT half (the absolute expected instants) lives in `schedule-golden-matrix.json`
 * next to this file, generated once from the engine as it stood at commit e501cf2 and committed.
 *
 * WHY THIS EXISTS (read before touching it — see WorkflowScheduleGoldenMatrixTest for the full
 * note): the schedule engine is about to be EXTRACTED from the Workflows module into a shared
 * layer so the Calendar can reuse it for recurring events. The extraction must not move a single
 * fire instant. The pre-existing WorkflowScheduleEngineParityTest cannot police that — it compares
 * a cold instance against a warm one, so both sides move together under a refactor. This file
 * names the ABSOLUTE answers instead.
 *
 * NOTHING HERE COMPUTES AN EXPECTATION. This class only produces ACTUALS; every expectation is a
 * literal in the committed JSON.
 *
 * COVERAGE CONTRACT (kept honest by the count floors in the test):
 *   - every mode of the TIME axis, including all four shapes the every_minutes window compiles to
 *     (none / inside one hour / adjacent hours without a middle expression / three expressions),
 *   - every mode of the DAY axis, including all four `special` rules and the bespoke
 *     last_working_day crossed with all three month modes,
 *   - every mode of the MONTH axis, windowed and not,
 *   - every legacy `{family, params}` shape the read-shim maps, plus its tolerances (times[] over
 *     params.time, a scalar weekday, a missing time),
 *   - exclusions by month / weekday / date, combined, empty, malformed, unreachable, and crossed
 *     with the non-cron cadence,
 *   - timezones with positive, negative and half-hour offsets, with and without DST, in both
 *     hemispheres, plus the config-default fallback (a descriptor with no `tz` key at all).
 */
final class ScheduleGoldenMatrix
{
    /** The committed expectations. Absolute instants, never recomputed by the test. */
    public const FIXTURE = __DIR__ . '/schedule-golden-matrix.json';

    /**
     * The application timezone the matrix was generated under, set EXPLICITLY by both the test and
     * the regenerator. This repo has no `.env.testing`, so `config('app.timezone')` is whatever the
     * developer's environment last left it — and the descriptors that carry no `tz` key resolve
     * through exactly that value. Naming it here is what stops the matrix going red for a reason
     * that has nothing to do with the engine.
     */
    public const APP_TIMEZONE = 'UTC';

    /**
     * The frozen clock. Only one call reads it — `nextOccurrences($schedule, $count)` with no
     * anchor, which falls back to `CarbonImmutable::now('UTC')` — but that call is a real seam (the
     * preview endpoint's default), so it is pinned rather than avoided.
     */
    public const FROZEN_NOW = '2026-08-25T09:17:33Z';

    /** How many occurrences each projection call asks for. Changing either invalidates the fixture. */
    public const OCCURRENCES_FROM_COUNT = 5;

    public const NEXT_OCCURRENCES_COUNT = 4;

    /**
     * One descriptor per distinct SHAPE the grammar can take — not 70 variations of the same shape.
     * The name is the fixture key and the failure message, so it says what the row is FOR.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function descriptors(): array
    {
        return [
            // ---- TIME axis: at ------------------------------------------------------------
            // No `tz` key at all: the ONLY descriptor that resolves the config fallback, which is
            // why the app timezone is pinned above rather than inherited.
            'time.at - one fire time, tz from config' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
            ],
            'time.at - three fire times (union)' => [
                'time' => ['mode' => 'at', 'at' => ['07:15', '13:45', '22:05']],
                'tz' => 'UTC',
            ],
            'time.at - midnight' => [
                'time' => ['mode' => 'at', 'at' => ['00:00']],
                'tz' => 'UTC',
            ],

            // ---- TIME axis: every_minutes (all four compiled shapes) ----------------------
            'time.every_minutes - every single minute' => [
                'time' => ['mode' => 'every_minutes', 'minutes' => 1],
                'tz' => 'UTC',
            ],
            'time.every_minutes - 7 minute grid, no window' => [
                'time' => ['mode' => 'every_minutes', 'minutes' => 7],
                'tz' => 'UTC',
            ],
            'time.every_minutes - window inside one hour' => [
                'time' => ['mode' => 'every_minutes', 'minutes' => 5, 'from' => '09:10', 'to' => '09:50'],
                'tz' => 'UTC',
            ],
            // h1 + 1 > h2 - 1, so the middle expression is NOT emitted: a two-expression union.
            'time.every_minutes - window over adjacent hours, no middle expression' => [
                'time' => ['mode' => 'every_minutes', 'minutes' => 10, 'from' => '09:40', 'to' => '10:20'],
                'tz' => 'UTC',
            ],
            'time.every_minutes - window over many hours, three expressions' => [
                'time' => ['mode' => 'every_minutes', 'minutes' => 10, 'from' => '08:30', 'to' => '17:20'],
                'tz' => 'UTC',
            ],

            // ---- TIME axis: every_hours ---------------------------------------------------
            'time.every_hours - 3 hour grid at :20' => [
                'time' => ['mode' => 'every_hours', 'hours' => 3, 'minute' => 20],
                'tz' => 'UTC',
            ],
            'time.every_hours - hour window 8..18 step 2' => [
                'time' => ['mode' => 'every_hours', 'hours' => 2, 'minute' => 5, 'from' => 8, 'to' => 18],
                'tz' => 'UTC',
            ],
            // `minute` omitted: pins the compiler's default of 0.
            'time.every_hours - minute defaulted to zero' => [
                'time' => ['mode' => 'every_hours', 'hours' => 5],
                'tz' => 'UTC',
            ],

            // ---- DAY axis -----------------------------------------------------------------
            'day.every_day - stated explicitly' => [
                'time' => ['mode' => 'at', 'at' => ['06:00']],
                'day' => ['mode' => 'every_day'],
                'tz' => 'UTC',
            ],
            'day.every_n_days - step 3, no window' => [
                'time' => ['mode' => 'at', 'at' => ['06:00']],
                'day' => ['mode' => 'every_n_days', 'n' => 3],
                'tz' => 'UTC',
            ],
            'day.every_n_days - step 5 inside a 1..20 window' => [
                'time' => ['mode' => 'at', 'at' => ['06:00']],
                'day' => ['mode' => 'every_n_days', 'n' => 5, 'from' => 1, 'to' => 20],
                'tz' => 'UTC',
            ],
            'day.weekdays - Mon Wed Fri' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [1, 3, 5]],
                'tz' => 'UTC',
            ],
            // Listed out of order on purpose: 0 must mean SUNDAY on both sides of the compiler.
            'day.weekdays - Saturday and Sunday, listed out of order' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [6, 0]],
                'tz' => 'UTC',
            ],
            'day.weekdays - duplicates and disorder normalised' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [3, 1, 3]],
                'tz' => 'UTC',
            ],
            // Day 31 is SKIPPED in short months, never clamped to the 28th/30th.
            'day.month_days - 1, 15 and 31, listed out of order' => [
                'time' => ['mode' => 'at', 'at' => ['06:00']],
                'day' => ['mode' => 'month_days', 'days' => [31, 15, 1]],
                'tz' => 'UTC',
            ],
            'day.month_days - 29 and 30 (February edges)' => [
                'time' => ['mode' => 'at', 'at' => ['06:00']],
                'day' => ['mode' => 'month_days', 'days' => [29, 30]],
                'tz' => 'UTC',
            ],
            'day.special last_day' => [
                'time' => ['mode' => 'at', 'at' => ['23:30']],
                'day' => ['mode' => 'special', 'special' => 'last_day'],
                'tz' => 'UTC',
            ],
            'day.special nth_weekday - 2nd Tuesday' => [
                'time' => ['mode' => 'at', 'at' => ['10:00']],
                'day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => 2, 'weekday' => 2],
                'tz' => 'UTC',
            ],
            'day.special nth_weekday - 1st Sunday' => [
                'time' => ['mode' => 'at', 'at' => ['10:00']],
                'day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => 1, 'weekday' => 0],
                'tz' => 'UTC',
            ],
            // Ordinal 5: months without a fifth Friday are skipped entirely.
            'day.special nth_weekday - 5th Friday' => [
                'time' => ['mode' => 'at', 'at' => ['10:00']],
                'day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => 5, 'weekday' => 5],
                'tz' => 'UTC',
            ],
            'day.special last_weekday - last Friday' => [
                'time' => ['mode' => 'at', 'at' => ['10:00']],
                'day' => ['mode' => 'special', 'special' => 'last_weekday', 'weekday' => 5],
                'tz' => 'UTC',
            ],
            'day.special last_weekday - last Sunday' => [
                'time' => ['mode' => 'at', 'at' => ['10:00']],
                'day' => ['mode' => 'special', 'special' => 'last_weekday', 'weekday' => 0],
                'tz' => 'UTC',
            ],

            // ---- DAY axis: the bespoke non-cron cadence, crossed with every month mode -----
            'day.special last_working_day - every month' => [
                'time' => ['mode' => 'at', 'at' => ['17:00']],
                'day' => ['mode' => 'special', 'special' => 'last_working_day'],
                'tz' => 'UTC',
            ],
            // Fire times listed LATE first: the earliest strictly-after must still win.
            'day.special last_working_day - two fire times, later listed first' => [
                'time' => ['mode' => 'at', 'at' => ['17:00', '08:30']],
                'day' => ['mode' => 'special', 'special' => 'last_working_day'],
                'tz' => 'UTC',
            ],
            'day.special last_working_day - month.months' => [
                'time' => ['mode' => 'at', 'at' => ['17:00']],
                'day' => ['mode' => 'special', 'special' => 'last_working_day'],
                'month' => ['mode' => 'months', 'months' => [3, 6, 9, 12]],
                'tz' => 'UTC',
            ],
            'day.special last_working_day - month.every_n_months, no window' => [
                'time' => ['mode' => 'at', 'at' => ['17:00']],
                'day' => ['mode' => 'special', 'special' => 'last_working_day'],
                'month' => ['mode' => 'every_n_months', 'n' => 4],
                'tz' => 'UTC',
            ],
            'day.special last_working_day - month.every_n_months inside a 2..11 window' => [
                'time' => ['mode' => 'at', 'at' => ['17:00']],
                'day' => ['mode' => 'special', 'special' => 'last_working_day'],
                'month' => ['mode' => 'every_n_months', 'n' => 3, 'from' => 2, 'to' => 11],
                'tz' => 'UTC',
            ],
            // The bespoke cadence in a tz whose local month-end crosses a UTC date boundary.
            'day.special last_working_day - tz Europe/Warsaw at 00:30' => [
                'time' => ['mode' => 'at', 'at' => ['00:30']],
                'day' => ['mode' => 'special', 'special' => 'last_working_day'],
                'tz' => 'Europe/Warsaw',
            ],

            // ---- MONTH axis ---------------------------------------------------------------
            'month.every_month - stated explicitly' => [
                'time' => ['mode' => 'at', 'at' => ['08:00']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
                'month' => ['mode' => 'every_month'],
                'tz' => 'UTC',
            ],
            'month.months - quarterly on the 1st' => [
                'time' => ['mode' => 'at', 'at' => ['08:00']],
                'day' => ['mode' => 'month_days', 'days' => [1]],
                'month' => ['mode' => 'months', 'months' => [1, 4, 7, 10]],
                'tz' => 'UTC',
            ],
            // `*/2` is a MODULO grid that resets each January, so Dec -> Jan can be shorter than n.
            'month.every_n_months - step 2, no window' => [
                'time' => ['mode' => 'at', 'at' => ['08:00']],
                'day' => ['mode' => 'month_days', 'days' => [1]],
                'month' => ['mode' => 'every_n_months', 'n' => 2],
                'tz' => 'UTC',
            ],
            'month.every_n_months - step 3 inside a 2..11 window' => [
                'time' => ['mode' => 'at', 'at' => ['08:00']],
                'day' => ['mode' => 'month_days', 'days' => [1]],
                'month' => ['mode' => 'every_n_months', 'n' => 3, 'from' => 2, 'to' => 11],
                'tz' => 'UTC',
            ],

            // ---- TIMEZONES ----------------------------------------------------------------
            // 02:30 does not exist on the EU spring-forward day and happens TWICE on the fall-back
            // day. Both behaviours are documented on the service; these rows are what pin them.
            'tz Europe/Warsaw - daily 02:30 (DST gap and overlap)' => [
                'time' => ['mode' => 'at', 'at' => ['02:30']],
                'tz' => 'Europe/Warsaw',
            ],
            'tz Europe/Warsaw - daily 09:00' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'tz' => 'Europe/Warsaw',
            ],
            // Negative offset: a 23:30 local month-end fire lands on the NEXT UTC day.
            'tz America/New_York - last day of month at 23:30' => [
                'time' => ['mode' => 'at', 'at' => ['23:30']],
                'day' => ['mode' => 'special', 'special' => 'last_day'],
                'tz' => 'America/New_York',
            ],
            // PINS A KNOWN DEFECT, ON PURPOSE. In the US the hour that repeats is 01:00-02:00, so a
            // 02:30 daily should fire ONCE on the fall-back day. The cron library fires it TWICE:
            // once at 01:30 EST (2026-11-01 06:30Z) and once at 02:30 EST (07:30Z) — the first is a
            // wall-clock time the descriptor never asked for. Europe/Warsaw's twice-firing above is
            // legitimate (02:30 really does happen twice there); this one is not. The matrix
            // preserves it so the EXTRACTION stays a no-op; fixing it is a separate, deliberate
            // change that must land with this row's expectation updated in the same commit.
            'tz America/New_York - daily 02:30 (US DST edges)' => [
                'time' => ['mode' => 'at', 'at' => ['02:30']],
                'tz' => 'America/New_York',
            ],
            // +05:30, no DST: a 00:15 local Monday fire is a SUNDAY instant in UTC.
            'tz Asia/Kolkata - 00:15 on Mondays' => [
                'time' => ['mode' => 'at', 'at' => ['00:15']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
                'tz' => 'Asia/Kolkata',
            ],
            // A half-hour offset against a wall-clock minute grid: the local grid is :00/:20/:40,
            // so the UTC instants land on :30/:50/:10 — OFF the UTC grid. (A 30 minute grid would
            // have proved nothing here: a half-hour offset maps that grid onto itself.)
            'tz Asia/Kolkata - 20 minute grid' => [
                'time' => ['mode' => 'every_minutes', 'minutes' => 20],
                'tz' => 'Asia/Kolkata',
            ],
            // Negative HALF-hour offset that also observes DST (-03:30 / -02:30).
            'tz America/St_Johns - daily 02:30' => [
                'time' => ['mode' => 'at', 'at' => ['02:30']],
                'tz' => 'America/St_Johns',
            ],
            // Southern hemisphere: its transitions fall in April and October, the opposite way round.
            'tz Australia/Sydney - daily 02:30' => [
                'time' => ['mode' => 'at', 'at' => ['02:30']],
                'tz' => 'Australia/Sydney',
            ],

            // ---- EXCLUSIONS ---------------------------------------------------------------
            'exclusions.weekdays - weekends dropped' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'tz' => 'UTC',
                'exclusions' => ['weekdays' => [0, 6]],
            ],
            'exclusions.months - July and August dropped' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'tz' => 'UTC',
                'exclusions' => ['months' => [7, 8]],
            ],
            // The candidate is re-expressed in the SCHEDULE tz before the date is compared: a
            // 00:30 Warsaw fire is the previous day in UTC, so a UTC-side comparison would drop
            // the wrong days.
            'exclusions.dates - compared in the schedule tz' => [
                'time' => ['mode' => 'at', 'at' => ['00:30']],
                'tz' => 'Europe/Warsaw',
                'exclusions' => ['dates' => ['2026-08-16', '2026-08-17']],
            ],
            'exclusions - months, weekdays and dates together' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'tz' => 'Europe/Warsaw',
                'exclusions' => ['months' => [12], 'weekdays' => [0], 'dates' => ['2026-08-17']],
            ],
            'exclusions - all lists empty is a no-op' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'tz' => 'UTC',
                'exclusions' => ['months' => [], 'weekdays' => [], 'dates' => []],
            ],
            // A stale or half-written row must not crash the every-minute sweep.
            'exclusions - malformed values tolerated' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'tz' => 'UTC',
                'exclusions' => ['months' => 'nope', 'weekdays' => null, 'dates' => 5],
            ],
            // Over-constrained: Mondays only, Mondays excluded. Must stay unreachable (null) in
            // BOTH directions, and must not spin.
            'exclusions - unreachable cadence' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
                'tz' => 'UTC',
                'exclusions' => ['weekdays' => [1]],
            ],
            // The exclusion loop crossed with the NON-CRON cadence: 2026-08-31 is the last working
            // day of August, and it is excluded by date.
            'exclusions - excluded date on the last working day' => [
                'time' => ['mode' => 'at', 'at' => ['17:00']],
                'day' => ['mode' => 'special', 'special' => 'last_working_day'],
                'tz' => 'UTC',
                'exclusions' => ['dates' => ['2026-08-31']],
            ],

            // ---- LEGACY {family, params} through the read-shim -----------------------------
            'legacy every_n_minutes' => ['family' => 'every_n_minutes', 'params' => ['n' => 15], 'tz' => 'UTC'],
            'legacy hourly' => ['family' => 'hourly', 'params' => [], 'tz' => 'UTC'],
            'legacy hourly_at' => ['family' => 'hourly_at', 'params' => ['minute' => 20], 'tz' => 'UTC'],
            'legacy every_n_hours' => ['family' => 'every_n_hours', 'params' => ['n' => 6, 'minute' => 10], 'tz' => 'UTC'],
            'legacy daily' => ['family' => 'daily', 'params' => ['time' => '09:00'], 'tz' => 'UTC'],
            // times[] wins over params.time.
            'legacy daily - times[] beats params.time' => [
                'family' => 'daily',
                'times' => ['06:00', '18:00'],
                'params' => ['time' => '09:00'],
                'tz' => 'UTC',
            ],
            // Neither times[] nor params.time: the compiler's defensive midnight.
            'legacy daily - no time at all' => ['family' => 'daily', 'params' => [], 'tz' => 'UTC'],
            'legacy twice_daily' => [
                'family' => 'twice_daily',
                'params' => ['first_hour' => 8, 'second_hour' => 20, 'minute' => 30],
                'tz' => 'UTC',
            ],
            // tz is carried through the shim verbatim.
            'legacy weekly - weekdays list, tz carried' => [
                'family' => 'weekly',
                'params' => ['weekdays' => [2, 4], 'time' => '11:30'],
                'tz' => 'Europe/Warsaw',
            ],
            // The Bot module's scalar `weekday` tolerance.
            'legacy weekly - scalar weekday tolerated' => [
                'family' => 'weekly',
                'params' => ['weekday' => 3, 'time' => '11:30'],
                'tz' => 'UTC',
            ],
            'legacy monthly' => ['family' => 'monthly', 'params' => ['day' => 15, 'time' => '07:00'], 'tz' => 'UTC'],
            'legacy twice_monthly' => [
                'family' => 'twice_monthly',
                'params' => ['first_day' => 1, 'second_day' => 16, 'time' => '07:00'],
                'tz' => 'UTC',
            ],
            'legacy last_day_of_month' => [
                'family' => 'last_day_of_month',
                'params' => ['time' => '23:30'],
                'tz' => 'UTC',
            ],
            'legacy quarterly' => ['family' => 'quarterly', 'params' => ['day' => 5, 'time' => '08:00'], 'tz' => 'UTC'],
            // Fires only in leap years — inside the 10 year horizon, so it must resolve, not go null.
            'legacy yearly - 29 February' => [
                'family' => 'yearly',
                'params' => ['month' => 2, 'day' => 29, 'time' => '12:00'],
                'tz' => 'UTC',
            ],
            // The shim pins the legacy January-anchored grid with an explicit 1..12 window.
            'legacy every_n_months' => [
                'family' => 'every_n_months',
                'params' => ['n' => 3, 'day' => 10, 'time' => '08:00'],
                'tz' => 'UTC',
            ],
            'legacy nth_weekday_of_month' => [
                'family' => 'nth_weekday_of_month',
                'params' => ['ordinal' => 3, 'weekday' => 4, 'time' => '10:00'],
                'tz' => 'UTC',
            ],
            'legacy last_weekday_of_month' => [
                'family' => 'last_weekday_of_month',
                'params' => ['weekday' => 1, 'time' => '10:00'],
                'tz' => 'UTC',
            ],
            // exclusions are carried through the shim verbatim too.
            'legacy last_working_day_of_month - exclusions carried' => [
                'family' => 'last_working_day_of_month',
                'params' => ['time' => '16:00'],
                'tz' => 'UTC',
                'exclusions' => ['months' => [1]],
            ],
        ];
    }

    /**
     * The time anchors every descriptor is asked about. Chosen for the edges the arithmetic can
     * fall off, not for variety: both sides of AND inside three different DST transitions (EU, US,
     * southern hemisphere), the exact-equality edge that decides whether prev-or-at returns the
     * anchor itself, both February ends, and the month/year boundaries.
     *
     * @return array<string, string>
     */
    public static function anchors(): array
    {
        return [
            'ordinary summer afternoon' => '2026-08-15T12:34:56Z',
            // Exactly ON a 09:00 fire: prev-or-at must return the anchor, next must step past it.
            'exactly on a 09:00 fire' => '2026-08-15T09:00:00Z',
            'month boundary, last second' => '2026-08-31T23:59:59Z',
            'day before EU spring forward' => '2026-03-28T12:00:00Z',
            // 01:45 CET, a quarter of an hour before the 02:00 -> 03:00 jump.
            'inside the EU spring-forward gap' => '2026-03-29T00:45:00Z',
            'day after EU spring forward' => '2026-03-30T12:00:00Z',
            'day before EU fall back' => '2026-10-24T12:00:00Z',
            // 02:45 CEST — the FIRST pass of an hour that repeats.
            'inside the EU fall-back overlap' => '2026-10-25T00:45:00Z',
            'day after EU fall back' => '2026-10-26T12:00:00Z',
            // 01:45 EST, before the US 02:00 -> 03:00 jump (a different date from the EU one).
            'inside the US spring-forward gap' => '2026-03-08T06:45:00Z',
            // 01:45 EDT — the first pass of the repeated US hour.
            'inside the US fall-back overlap' => '2026-11-01T05:45:00Z',
            // 02:45 AEDT — the first pass of Sydney's repeated hour, in April.
            'inside the Sydney fall-back overlap' => '2026-04-04T15:45:00Z',
            'leap day' => '2028-02-29T06:00:00Z',
            'end of a non-leap February' => '2027-02-28T12:00:00Z',
            // The classic day-31 edge: the next month has no 31st.
            'end of January' => '2026-01-31T12:00:00Z',
            'year end' => '2026-12-31T23:30:00Z',
        ];
    }

    /**
     * The ACTUAL per-descriptor answers (anchor independent): the read-shim's output, the compiled
     * cadence, the resolved timezone, the approximation flag, and the one projection that reads the
     * clock. Produces actuals only — the expectations are the committed JSON.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function perDescriptor(WorkflowScheduleService $service): array
    {
        $upgrader = new LegacyScheduleUpgrader;
        $compiler = new ScheduleCompiler;

        $rows = [];

        foreach (self::descriptors() as $name => $descriptor) {
            $compiled = $compiler->compile($descriptor);

            $rows[$name] = [
                'upgraded' => $upgrader->toV2($descriptor),
                'compiled' => [
                    'kind' => $compiled->kind,
                    'expressions' => $compiled->expressions,
                    'times' => $compiled->times,
                    'months' => $compiled->months,
                ],
                'timezone' => $service->timezone($descriptor),
                'is_approximate' => $service->isApproximate($descriptor),
                // No anchor: the ONLY call that reads the (frozen) clock.
                'next_occurrences_from_frozen_now' => array_map(
                    self::stamp(...),
                    $service->nextOccurrences($descriptor, self::NEXT_OCCURRENCES_COUNT),
                ),
            ];
        }

        return $rows;
    }

    /**
     * The ACTUAL descriptor x anchor answers for the four anchored methods. Produces actuals only.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cases(WorkflowScheduleService $service): array
    {
        $rows = [];

        foreach (self::descriptors() as $name => $descriptor) {
            foreach (self::anchors() as $anchorName => $anchor) {
                $from = CarbonImmutable::parse($anchor)->utc();

                $rows[self::caseKey($name, $anchorName)] = [
                    'next_due_at' => self::stamp($service->nextDueAt($descriptor, $from)),
                    'previous_or_at' => self::stamp($service->previousOrAtOccurrence($descriptor, $from)),
                    'occurrences_from' => array_map(
                        self::stamp(...),
                        $service->occurrencesFrom($descriptor, $from, self::OCCURRENCES_FROM_COUNT),
                    ),
                    'next_occurrences' => array_map(
                        self::stamp(...),
                        $service->nextOccurrences($descriptor, self::NEXT_OCCURRENCES_COUNT, $from),
                    ),
                ];
            }
        }

        return $rows;
    }

    /** The fixture key (and the failure message) for one descriptor/anchor pair. */
    public static function caseKey(string $descriptor, string $anchor): string
    {
        return $descriptor . ' @ ' . $anchor;
    }

    /**
     * A returned instant as a UTC wall-clock string. Non-mutating (the mutable Carbon the service
     * returns must reach the timezone assertions unchanged). The engine never produces sub-second
     * values; the test asserts the timezone separately, so seconds precision loses nothing.
     */
    public static function stamp(?CarbonInterface $moment): ?string
    {
        return $moment === null
            ? null
            : CarbonImmutable::instance($moment)->utc()->format('Y-m-d H:i:s');
    }
}
