<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Services\WorkflowScheduleService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Unit coverage for the v2 schedule cadence math. Pure computation (no DB) but extends the app's
 * TestCase so the container resolves config('app.timezone') for the tz default. Conventions:
 *   - descriptor shape: { time, day?, month?, tz?, exclusions? }.
 *   - weekday 0=Sunday .. 6=Saturday (Carbon::dayOfWeek AND cron dow 0=Sunday).
 *   - wall-clock fields resolve IN the schedule tz, then convert to UTC for storage.
 *   - every next fire is STRICTLY AFTER $from and returned in UTC.
 *   - NO interval kind: every_minutes/every_hours are wall-clock grids now (a schedule armed at 10:02
 *     with a 15-minute grid fires 10:15, not 10:17).
 *   - occurrencesFrom(anchor) returns the occurrence AT-OR-BEFORE the anchor first (prev-or-at), then
 *     the strictly-later ones; exclusions are respected walking backwards too.
 */
class WorkflowScheduleServiceTest extends TestCase
{
    private WorkflowScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowScheduleService;
    }

    private function next(array $schedule, Carbon $from): ?Carbon
    {
        return $this->service->nextDueAt($schedule, $from);
    }

    /** A time.mode=at descriptor for one or more HH:mm fire times. */
    private function at(string ...$times): array
    {
        return ['mode' => 'at', 'at' => array_values($times)];
    }

    // ---- every_minutes (wall-clock grid) -------------------------------------

    public function test_every_minutes_snaps_to_the_wall_clock_grid_not_the_arm_phase(): void
    {
        // 15-minute grid: from 10:02 the next is 10:15 (NOT 10:17 — the old interval phase is gone).
        $from = Carbon::parse('2026-07-07 10:02:30', 'UTC');

        $next = $this->next(['time' => ['mode' => 'every_minutes', 'minutes' => 15]], $from);

        $this->assertSame('2026-07-07 10:15:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $next->timezone->getName());
    }

    public function test_every_minutes_resets_on_the_hour_boundary(): void
    {
        // From 09:50 the grid's next slot is 10:00 (the :45..:00 reset), not 10:05.
        $from = Carbon::parse('2026-07-07 09:50:00', 'UTC');

        $next = $this->next(['time' => ['mode' => 'every_minutes', 'minutes' => 15]], $from);

        $this->assertSame('2026-07-07 10:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_every_minutes_window_across_the_hour_boundary_unions_the_slots(): void
    {
        // 09:30..10:15 every 15 -> slots 09:30,09:45,10:00,10:15 then next day.
        $schedule = ['time' => ['mode' => 'every_minutes', 'minutes' => 15, 'from' => '09:30', 'to' => '10:15'], 'tz' => 'UTC'];

        $this->assertSame('2026-07-07 09:30:00', $this->next($schedule, Carbon::parse('2026-07-07 09:00:00', 'UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-07 09:45:00', $this->next($schedule, Carbon::parse('2026-07-07 09:40:00', 'UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-07 10:00:00', $this->next($schedule, Carbon::parse('2026-07-07 09:50:00', 'UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-07 10:15:00', $this->next($schedule, Carbon::parse('2026-07-07 10:05:00', 'UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-08 09:30:00', $this->next($schedule, Carbon::parse('2026-07-07 10:20:00', 'UTC'))->format('Y-m-d H:i:s'));
    }

    // ---- every_hours (hour-of-day modulo) ------------------------------------

    public function test_every_hours_snaps_to_the_hour_of_day_grid(): void
    {
        // every 3 hours at :00 -> 00,03,…,21; from 22:30 the next is 00:00 next day (midnight reset).
        $from = Carbon::parse('2026-07-07 22:30:00', 'UTC');

        $next = $this->next(['time' => ['mode' => 'every_hours', 'hours' => 3]], $from);

        $this->assertSame('2026-07-08 00:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_every_hours_window_limits_to_the_range(): void
    {
        // every 2 hours in 9..17 at :00 -> 9,11,13,15,17; from 16:00 the next is 17:00.
        $schedule = ['time' => ['mode' => 'every_hours', 'hours' => 2, 'from' => 9, 'to' => 17], 'tz' => 'UTC'];

        $this->assertSame('2026-07-07 17:00:00', $this->next($schedule, Carbon::parse('2026-07-07 16:00:00', 'UTC'))->format('Y-m-d H:i:s'));
        // After 17:00 the window is done for the day -> next day's 09:00.
        $this->assertSame('2026-07-08 09:00:00', $this->next($schedule, Carbon::parse('2026-07-07 17:30:00', 'UTC'))->format('Y-m-d H:i:s'));
    }

    // ---- time.at -------------------------------------------------------------

    public function test_at_returns_today_then_rolls_to_tomorrow_strictly_after(): void
    {
        $schedule = ['time' => $this->at('09:00')];

        $this->assertSame('2026-07-07 09:00:00', $this->next($schedule, Carbon::parse('2026-07-07 08:00:00', 'UTC'))->format('Y-m-d H:i:s'));
        // Exactly at the time rolls forward (strictly-after).
        $this->assertSame('2026-07-08 09:00:00', $this->next($schedule, Carbon::parse('2026-07-07 09:00:00', 'UTC'))->format('Y-m-d H:i:s'));
    }

    public function test_at_wall_clock_is_resolved_in_tz_and_stored_utc(): void
    {
        // 09:00 Europe/Warsaw in July (CEST, +02:00) = 07:00 UTC.
        $next = $this->next(['time' => $this->at('09:00'), 'tz' => 'Europe/Warsaw'], Carbon::parse('2026-07-07 05:00:00', 'UTC'));

        $this->assertSame('2026-07-07 07:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $next->timezone->getName());
    }

    public function test_at_multiple_times_fires_the_earliest_strictly_after(): void
    {
        $schedule = ['time' => $this->at('08:00', '17:00'), 'tz' => 'UTC'];

        $this->assertSame('2026-07-07 08:00:00', $this->next($schedule, Carbon::parse('2026-07-07 07:00:00', 'UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-07 17:00:00', $this->next($schedule, Carbon::parse('2026-07-07 09:00:00', 'UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-08 08:00:00', $this->next($schedule, Carbon::parse('2026-07-07 18:00:00', 'UTC'))->format('Y-m-d H:i:s'));
    }

    // ---- day axis ------------------------------------------------------------

    public function test_day_weekdays_returns_the_next_listed_weekday(): void
    {
        // 2026-07-07 is a Tuesday (2); weekday 5 = Friday -> 2026-07-10.
        $next = $this->next(['time' => $this->at('09:00'), 'day' => ['mode' => 'weekdays', 'weekdays' => [5]]], Carbon::parse('2026-07-07 12:00:00', 'UTC'));

        $this->assertSame('2026-07-10 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(5, $next->dayOfWeek);
    }

    public function test_day_weekday_zero_is_sunday(): void
    {
        $next = $this->next(['time' => $this->at('09:00'), 'day' => ['mode' => 'weekdays', 'weekdays' => [0]]], Carbon::parse('2026-07-07 12:00:00', 'UTC'));

        $this->assertSame('2026-07-12 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(Carbon::SUNDAY, $next->dayOfWeek);
    }

    public function test_day_weekdays_multi_day_fires_on_the_next_listed(): void
    {
        // weekdays [1,3] Mon,Wed; from Tuesday -> Wednesday 2026-07-08.
        $next = $this->next(['time' => $this->at('08:00'), 'day' => ['mode' => 'weekdays', 'weekdays' => [1, 3]]], Carbon::parse('2026-07-07 12:00:00', 'UTC'));

        $this->assertSame('2026-07-08 08:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(3, $next->dayOfWeek);
    }

    public function test_day_month_days_returns_the_day_then_skips_short_months(): void
    {
        $this->assertSame(
            '2026-07-15 09:00:00',
            $this->next(['time' => $this->at('09:00'), 'day' => ['mode' => 'month_days', 'days' => [15]]], Carbon::parse('2026-07-07 12:00:00', 'UTC'))->format('Y-m-d H:i:s'),
        );
        // Day 31 does not exist in February -> the fire skips Feb to 2026-03-31.
        $this->assertSame(
            '2026-03-31 00:00:00',
            $this->next(['time' => $this->at('00:00'), 'day' => ['mode' => 'month_days', 'days' => [31]]], Carbon::parse('2026-02-01 00:00:00', 'UTC'))->format('Y-m-d H:i:s'),
        );
    }

    public function test_day_every_n_days_window_steps_within_the_range(): void
    {
        // n=2 window 5..10 -> dom 5-10/2 = days 5,7,9. From the 1st the next is the 5th; from the 5th
        // the next is the 7th; after the 9th it rolls to next month's 5th.
        $schedule = ['time' => $this->at('09:00'), 'day' => ['mode' => 'every_n_days', 'n' => 2, 'from' => 5, 'to' => 10], 'tz' => 'UTC'];

        $this->assertSame('2026-07-05 09:00:00', $this->next($schedule, Carbon::parse('2026-07-01 00:00:00', 'UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-07 09:00:00', $this->next($schedule, Carbon::parse('2026-07-05 12:00:00', 'UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-05 09:00:00', $this->next($schedule, Carbon::parse('2026-07-09 12:00:00', 'UTC'))->format('Y-m-d H:i:s'));
    }

    public function test_day_special_last_day_resolves_february(): void
    {
        // 2026 is not a leap year -> Feb has 28 days.
        $next = $this->next(['time' => $this->at('00:00'), 'day' => ['mode' => 'special', 'special' => 'last_day']], Carbon::parse('2026-02-10 00:00:00', 'UTC'));

        $this->assertSame('2026-02-28 00:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_day_special_nth_weekday_first_occurrence(): void
    {
        // First Monday from 2026-07-01 is the 6th.
        $next = $this->next(['time' => $this->at('09:00'), 'day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => 1, 'weekday' => 1]], Carbon::parse('2026-07-01 00:00:00', 'UTC'));

        $this->assertSame('2026-07-06 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(1, $next->dayOfWeek);
    }

    public function test_day_special_nth_weekday_ordinal_5_skips_months_without_a_fifth(): void
    {
        // July 2026 has only four Mondays -> the 5th-Monday schedule skips to August 31.
        $next = $this->next(['time' => $this->at('09:00'), 'day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => 5, 'weekday' => 1]], Carbon::parse('2026-07-07 12:00:00', 'UTC'));

        $this->assertSame('2026-08-31 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(1, $next->dayOfWeek);
    }

    public function test_day_special_last_weekday_final_occurrence(): void
    {
        // Last Friday of July 2026 is the 31st.
        $next = $this->next(['time' => $this->at('09:00'), 'day' => ['mode' => 'special', 'special' => 'last_weekday', 'weekday' => 5]], Carbon::parse('2026-07-07 12:00:00', 'UTC'));

        $this->assertSame('2026-07-31 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(5, $next->dayOfWeek);
    }

    // ---- month axis ----------------------------------------------------------

    public function test_month_months_pins_a_specific_month_and_day(): void
    {
        // Dec 25 09:00; from mid-year the next is this year's Dec 25.
        $next = $this->next([
            'time' => $this->at('09:00'),
            'day' => ['mode' => 'month_days', 'days' => [25]],
            'month' => ['mode' => 'months', 'months' => [12]],
        ], Carbon::parse('2026-07-07 12:00:00', 'UTC'));

        $this->assertSame('2026-12-25 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_month_months_feb_29_fires_only_in_leap_years(): void
    {
        $next = $this->next([
            'time' => $this->at('09:00'),
            'day' => ['mode' => 'month_days', 'days' => [29]],
            'month' => ['mode' => 'months', 'months' => [2]],
        ], Carbon::parse('2026-01-01 00:00:00', 'UTC'));

        $this->assertSame('2028-02-29 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_month_every_n_months_window_follows_the_grid(): void
    {
        // n=2 window 1..12 -> months 1,3,5,7,9,11 on day 1. From 2026-07-07 the next grid month is Sep.
        $next = $this->next([
            'time' => $this->at('09:00'),
            'day' => ['mode' => 'month_days', 'days' => [1]],
            'month' => ['mode' => 'every_n_months', 'n' => 2, 'from' => 1, 'to' => 12],
        ], Carbon::parse('2026-07-07 12:00:00', 'UTC'));

        $this->assertSame('2026-09-01 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    // ---- last_working_day (bespoke) ------------------------------------------

    public function test_last_working_day_steps_back_over_a_weekend_month_end(): void
    {
        // May 2026 ends Sunday the 31st (30th Saturday); the last working day is Fri May 29.
        $next = $this->next(['time' => $this->at('17:00'), 'day' => ['mode' => 'special', 'special' => 'last_working_day']], Carbon::parse('2026-05-10 00:00:00', 'UTC'));

        $this->assertSame('2026-05-29 17:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(5, $next->dayOfWeek);
    }

    public function test_last_working_day_uses_the_last_day_when_it_is_a_weekday(): void
    {
        // August 2026 ends Monday the 31st.
        $next = $this->next(['time' => $this->at('17:00'), 'day' => ['mode' => 'special', 'special' => 'last_working_day']], Carbon::parse('2026-08-01 00:00:00', 'UTC'));

        $this->assertSame('2026-08-31 17:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(1, $next->dayOfWeek);
    }

    public function test_last_working_day_rolls_to_next_month_when_passed(): void
    {
        $next = $this->next(['time' => $this->at('17:00'), 'day' => ['mode' => 'special', 'special' => 'last_working_day']], Carbon::parse('2026-05-31 00:00:00', 'UTC'));

        $this->assertSame('2026-06-30 17:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_last_working_day_resolves_wall_clock_in_tz(): void
    {
        // Jan 2027 ends Sunday the 31st; last working day Fri Jan 29. 09:00 Warsaw (CET) = 08:00 UTC.
        $next = $this->next(['time' => $this->at('09:00'), 'day' => ['mode' => 'special', 'special' => 'last_working_day'], 'tz' => 'Europe/Warsaw'], Carbon::parse('2027-01-05 00:00:00', 'UTC'));

        $this->assertSame('2027-01-29 08:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $next->timezone->getName());
    }

    public function test_last_working_day_honours_the_month_filter(): void
    {
        // last_working_day restricted to December only: from January it must walk forward to Dec's LWD.
        $schedule = [
            'time' => $this->at('17:00'),
            'day' => ['mode' => 'special', 'special' => 'last_working_day'],
            'month' => ['mode' => 'months', 'months' => [12]],
        ];

        $next = $this->next($schedule, Carbon::parse('2026-01-15 00:00:00', 'UTC'));

        $this->assertSame(12, $next->month);
        $this->assertSame(2026, $next->year);
        $this->assertTrue($next->isWeekday(), 'the last working day is a weekday');
        $this->assertSame('17:00:00', $next->format('H:i:s'));
    }

    // ---- DST ------------------------------------------------------------------

    public function test_spring_forward_gap_resolves_forward(): void
    {
        // 2026-03-29 Europe/Warsaw jumps 02:00->03:00, so 02:30 does not exist; the lib resolves it to
        // 03:30 local (+02:00) = 01:30 UTC that day. Strictly later, valid UTC.
        $from = Carbon::parse('2026-03-29 00:15:00', 'UTC');

        $next = $this->next(['time' => $this->at('02:30'), 'tz' => 'Europe/Warsaw'], $from);

        $this->assertTrue($next->greaterThan($from));
        $this->assertSame('2026-03-29 01:30:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-29 03:30:00', $next->copy()->setTimezone('Europe/Warsaw')->format('Y-m-d H:i:s'));
    }

    public function test_fall_back_fires_both_local_0230_instances(): void
    {
        // 2026-10-25 Warsaw rolls 03:00->02:00, so 02:30 happens twice: 00:30 UTC (CEST) then 01:30 UTC
        // (CET). The strictly-after cursor advances from the first to the second (distinct UTC instants).
        $tz = 'Europe/Warsaw';
        $schedule = ['time' => $this->at('02:30'), 'tz' => $tz];

        $first = $this->next($schedule, Carbon::parse('2026-10-24 20:00:00', 'UTC'));
        $this->assertSame('2026-10-25 00:30:00', $first->format('Y-m-d H:i:s'));

        $second = $this->next($schedule, $first);
        $this->assertSame('2026-10-25 01:30:00', $second->format('Y-m-d H:i:s'));
        $this->assertTrue($second->greaterThan($first));

        $third = $this->next($schedule, $second);
        $this->assertSame('2026-10-26 01:30:00', $third->format('Y-m-d H:i:s'));
    }

    // ---- exclusions ----------------------------------------------------------

    public function test_exclusions_months_skips_the_excluded_month(): void
    {
        $next = $this->next([
            'time' => $this->at('09:00'),
            'day' => ['mode' => 'month_days', 'days' => [1]],
            'exclusions' => ['months' => [8]],
            'tz' => 'UTC',
        ], Carbon::parse('2026-07-15 00:00:00', 'UTC'));

        $this->assertSame('2026-09-01 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_exclusions_weekdays_skips_the_weekend(): void
    {
        // 2026-07-10 is a Friday. Daily 09:00 excluding Sat(6)+Sun(0); from Fri 10:00 -> Mon Jul 13.
        $next = $this->next([
            'time' => $this->at('09:00'),
            'exclusions' => ['weekdays' => [0, 6]],
            'tz' => 'UTC',
        ], Carbon::parse('2026-07-10 10:00:00', 'UTC'));

        $this->assertSame('2026-07-13 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(1, $next->dayOfWeek);
    }

    public function test_exclusions_dates_skips_the_specific_date(): void
    {
        $next = $this->next([
            'time' => $this->at('09:00'),
            'exclusions' => ['dates' => ['2026-07-08']],
            'tz' => 'UTC',
        ], Carbon::parse('2026-07-07 10:00:00', 'UTC'));

        $this->assertSame('2026-07-09 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_every_minutes_grid_skips_the_whole_excluded_weekend(): void
    {
        // 15-minute grid excluding the weekend: from Fri 23:50 the next grid slot Sat 00:00 (and all of
        // Sat/Sun) is excluded, so it lands on Monday 00:00 (the grid slot, not an arm phase).
        $next = $this->next([
            'time' => ['mode' => 'every_minutes', 'minutes' => 15],
            'exclusions' => ['weekdays' => [0, 6]],
            'tz' => 'UTC',
        ], Carbon::parse('2026-07-10 23:50:00', 'UTC'));

        $this->assertSame('2026-07-13 00:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(1, $next->dayOfWeek);
    }

    public function test_over_constrained_exclusions_yield_null(): void
    {
        // weekly-on-Monday that ALSO excludes Mondays has no reachable occurrence -> null.
        $next = $this->next([
            'time' => $this->at('09:00'),
            'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
            'exclusions' => ['weekdays' => [1]],
            'tz' => 'UTC',
        ], Carbon::parse('2026-07-07 00:00:00', 'UTC'));

        $this->assertNull($next);
    }

    public function test_sparse_cadence_beyond_the_horizon_yields_null(): void
    {
        // REGRESSION (Carbon 3 signed diffInYears): a Feb-29 cadence whose next reachable leap days are
        // all excluded must return null (the next unexcluded one lies beyond the 10-year horizon), not
        // an absurd far-future instant.
        $next = $this->next([
            'time' => $this->at('09:00'),
            'day' => ['mode' => 'month_days', 'days' => [29]],
            'month' => ['mode' => 'months', 'months' => [2]],
            'exclusions' => ['dates' => ['2028-02-29', '2032-02-29', '2036-02-29']],
            'tz' => 'UTC',
        ], Carbon::parse('2026-07-07 00:00:00', 'UTC'));

        $this->assertNull($next);
    }

    // ---- anchored occurrences (prev-or-at) -----------------------------------

    public function test_occurrences_from_anchor_between_starts_with_the_earlier_occurrence(): void
    {
        // Daily 09:00 UTC, anchor 12:00 (between the 07-07 and 07-08 fires): the first occurrence is the
        // earlier 07-07 09:00 (<= anchor), then the strictly-later ones.
        $occurrences = $this->service->occurrencesFrom(
            ['time' => $this->at('09:00'), 'tz' => 'UTC'],
            CarbonImmutable::parse('2026-07-07 12:00:00', 'UTC'),
            3,
        );

        $iso = array_map(fn ($o) => $o->format('Y-m-d H:i:s'), $occurrences);
        $this->assertSame(['2026-07-07 09:00:00', '2026-07-08 09:00:00', '2026-07-09 09:00:00'], $iso);
    }

    public function test_occurrences_from_anchor_exactly_on_an_occurrence_lists_it_first(): void
    {
        $occurrences = $this->service->occurrencesFrom(
            ['time' => $this->at('09:00'), 'tz' => 'UTC'],
            CarbonImmutable::parse('2026-07-07 09:00:00', 'UTC'),
            2,
        );

        $iso = array_map(fn ($o) => $o->format('Y-m-d H:i:s'), $occurrences);
        $this->assertSame(['2026-07-07 09:00:00', '2026-07-08 09:00:00'], $iso);
    }

    public function test_occurrences_from_respects_exclusions_walking_backwards(): void
    {
        // Daily 09:00 excluding 2026-07-07; anchor 07-07 12:00. The prev-or-at 07-07 09:00 is excluded,
        // so the earlier occurrence is 07-06 09:00; forward skips the excluded 07-07 to 07-08.
        $occurrences = $this->service->occurrencesFrom(
            ['time' => $this->at('09:00'), 'exclusions' => ['dates' => ['2026-07-07']], 'tz' => 'UTC'],
            CarbonImmutable::parse('2026-07-07 12:00:00', 'UTC'),
            3,
        );

        $iso = array_map(fn ($o) => $o->format('Y-m-d H:i:s'), $occurrences);
        $this->assertSame(['2026-07-06 09:00:00', '2026-07-08 09:00:00', '2026-07-09 09:00:00'], $iso);
    }

    public function test_occurrences_from_anchor_before_any_reachable_occurrence_has_no_prev(): void
    {
        // A Feb-29 cadence whose backward leap days are all excluded has NO occurrence at-or-before the
        // anchor within the horizon -> the list is simply the occurrences strictly AFTER the anchor.
        $schedule = [
            'time' => $this->at('09:00'),
            'day' => ['mode' => 'month_days', 'days' => [29]],
            'month' => ['mode' => 'months', 'months' => [2]],
            'exclusions' => ['dates' => ['2024-02-29', '2020-02-29', '2016-02-29']],
            'tz' => 'UTC',
        ];

        $occurrences = $this->service->occurrencesFrom($schedule, CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'), 2);

        $iso = array_map(fn ($o) => $o->format('Y-m-d H:i:s'), $occurrences);
        $this->assertSame(['2028-02-29 09:00:00', '2032-02-29 09:00:00'], $iso);
    }

    public function test_previous_or_at_occurrence_is_null_before_the_backward_horizon(): void
    {
        // The prev-or-at helper returns null when no occurrence lies in [anchor-10y, anchor].
        $prev = $this->service->previousOrAtOccurrence(
            [
                'time' => $this->at('09:00'),
                'day' => ['mode' => 'month_days', 'days' => [29]],
                'month' => ['mode' => 'months', 'months' => [2]],
                'exclusions' => ['dates' => ['2024-02-29', '2020-02-29', '2016-02-29']],
                'tz' => 'UTC',
            ],
            CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'),
        );

        $this->assertNull($prev);
    }

    // ---- read-shim: a legacy block still computes -----------------------------

    public function test_legacy_block_is_upgraded_and_still_fires(): void
    {
        // A stored legacy { family: daily } row is upgraded to v2 on read and computes the same instant.
        $next = $this->next(['family' => 'daily', 'params' => ['time' => '09:00'], 'tz' => 'UTC'], Carbon::parse('2026-07-07 08:00:00', 'UTC'));

        $this->assertSame('2026-07-07 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    // ---- strictly-after invariant --------------------------------------------

    public function test_result_is_always_strictly_after_from(): void
    {
        $from = Carbon::parse('2026-07-07 10:00:00', 'UTC');

        $schedules = [
            ['time' => ['mode' => 'every_minutes', 'minutes' => 1]],
            ['time' => ['mode' => 'every_hours', 'hours' => 2]],
            ['time' => $this->at('10:00')],
            ['time' => $this->at('10:00'), 'day' => ['mode' => 'weekdays', 'weekdays' => [2, 4]]],
            ['time' => $this->at('10:00'), 'day' => ['mode' => 'month_days', 'days' => [7]]],
            ['time' => $this->at('10:00'), 'day' => ['mode' => 'every_n_days', 'n' => 3]],
            ['time' => $this->at('10:00'), 'day' => ['mode' => 'special', 'special' => 'last_day']],
            ['time' => $this->at('10:00'), 'day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => 1, 'weekday' => 2]],
            ['time' => $this->at('10:00'), 'day' => ['mode' => 'special', 'special' => 'last_weekday', 'weekday' => 5]],
            ['time' => $this->at('10:00'), 'day' => ['mode' => 'special', 'special' => 'last_working_day']],
            ['time' => $this->at('10:00'), 'month' => ['mode' => 'months', 'months' => [7]]],
            ['time' => $this->at('10:00'), 'month' => ['mode' => 'every_n_months', 'n' => 2]],
        ];

        foreach ($schedules as $schedule) {
            $next = $this->next($schedule, $from);
            $this->assertNotNull($next);
            $this->assertTrue($next->greaterThan($from), 'must be strictly after from: ' . json_encode($schedule));
            $this->assertSame('UTC', $next->timezone->getName());
        }
    }
}
