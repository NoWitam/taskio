<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Services\WorkflowScheduleService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Unit coverage for the schedule cadence math after the frequency-family redesign. Pure
 * computation (no DB) but extends the app's TestCase so the container resolves
 * config('app.timezone') for the tz default. Conventions asserted here:
 *   - config shape: { family, params: {…}, tz? }.
 *   - weekday 0=Sunday .. 6=Saturday (Carbon::dayOfWeek AND cron dow 0=Sunday).
 *   - wall-clock cron families resolve IN the schedule tz, then convert to UTC for storage.
 *   - every returned time is STRICTLY AFTER $from and returned in UTC.
 *   - every_n_minutes is a bespoke interval (from + N minutes); every other family is cron.
 *   - the optional times[] (union of fire times) and exclusions (skip filter) extensions; an
 *     over-constrained exclusion set with no reachable occurrence yields null.
 */
class WorkflowScheduleServiceTest extends TestCase
{
    private WorkflowScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowScheduleService;
    }

    /** @param array<string, mixed> $params */
    private function nextDueAt(string $family, array $params, Carbon $from, ?string $tz = null): Carbon
    {
        $schedule = ['family' => $family, 'params' => $params];

        if ($tz !== null) {
            $schedule['tz'] = $tz;
        }

        return $this->service->nextDueAt($schedule, $from);
    }

    /** Compute nextDueAt for a full schedule block (with times[]/exclusions), tolerating null. */
    private function nextForBlock(array $schedule, Carbon $from): ?Carbon
    {
        return $this->service->nextDueAt($schedule, $from);
    }

    // ---- every_n_minutes (bespoke interval) ----------------------------------

    public function test_every_n_minutes_is_a_simple_interval_from_now(): void
    {
        $from = Carbon::parse('2026-07-07 10:02:30', 'UTC');

        $next = $this->nextDueAt('every_n_minutes', ['n' => 15], $from);

        // Pure interval: from + 15 minutes, no wall-clock alignment.
        $this->assertSame('2026-07-07 10:17:30', $next->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $next->timezone->getName());
    }

    public function test_every_n_minutes_ignores_wall_clock_grid(): void
    {
        // Armed at 10:02 with n=15 fires 10:17, NOT 10:15 — phase follows the arm time.
        $from = Carbon::parse('2026-07-07 10:02:00', 'UTC');

        $next = $this->nextDueAt('every_n_minutes', ['n' => 15], $from);

        $this->assertSame('2026-07-07 10:17:00', $next->format('Y-m-d H:i:s'));
    }

    // ---- hourly / hourly_at --------------------------------------------------

    public function test_hourly_returns_the_next_top_of_hour_strictly_after(): void
    {
        $from = Carbon::parse('2026-07-07 10:02:30', 'UTC');

        $next = $this->nextDueAt('hourly', [], $from);

        $this->assertSame('2026-07-07 11:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_hourly_on_the_hour_advances_to_the_following_hour(): void
    {
        // Strictly-after: exactly on the hour must roll forward, never return $from.
        $from = Carbon::parse('2026-07-07 10:00:00', 'UTC');

        $next = $this->nextDueAt('hourly', [], $from);

        $this->assertSame('2026-07-07 11:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_hourly_at_rolls_to_the_next_hour_when_minute_has_passed(): void
    {
        // minute 15, from 10:20 -> next is 11:15 (this hour's :15 already passed).
        $from = Carbon::parse('2026-07-07 10:20:00', 'UTC');

        $next = $this->nextDueAt('hourly_at', ['minute' => 15], $from);

        $this->assertSame('2026-07-07 11:15:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_hourly_at_stays_this_hour_when_minute_is_still_future(): void
    {
        $from = Carbon::parse('2026-07-07 10:05:00', 'UTC');

        $next = $this->nextDueAt('hourly_at', ['minute' => 15], $from);

        $this->assertSame('2026-07-07 10:15:00', $next->format('Y-m-d H:i:s'));
    }

    // ---- every_n_hours (hour-of-day modulo) ----------------------------------

    public function test_every_n_hours_snaps_to_the_hour_of_day_grid(): void
    {
        // every 3 hours at :00 -> grid is 00,03,06,…; from 22:30 -> next is 00:00 next day.
        $from = Carbon::parse('2026-07-07 22:30:00', 'UTC');

        $next = $this->nextDueAt('every_n_hours', ['n' => 3], $from);

        $this->assertSame('2026-07-08 00:00:00', $next->format('Y-m-d H:i:s'));
    }

    // ---- daily ---------------------------------------------------------------

    public function test_daily_returns_today_when_the_time_is_still_future(): void
    {
        $from = Carbon::parse('2026-07-07 08:00:00', 'UTC');

        $next = $this->nextDueAt('daily', ['time' => '09:00'], $from);

        $this->assertSame('2026-07-07 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_daily_rolls_to_tomorrow_when_the_time_has_passed(): void
    {
        $from = Carbon::parse('2026-07-07 09:30:00', 'UTC');

        $next = $this->nextDueAt('daily', ['time' => '09:00'], $from);

        $this->assertSame('2026-07-08 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_daily_exactly_at_the_time_rolls_to_tomorrow_strictly_after(): void
    {
        $from = Carbon::parse('2026-07-07 09:00:00', 'UTC');

        $next = $this->nextDueAt('daily', ['time' => '09:00'], $from);

        $this->assertSame('2026-07-08 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_daily_wall_clock_time_is_resolved_in_tz_and_stored_as_utc(): void
    {
        // 09:00 Europe/Warsaw in July (CEST, UTC+2) = 07:00 UTC.
        $from = Carbon::parse('2026-07-07 05:00:00', 'UTC');

        $next = $this->nextDueAt('daily', ['time' => '09:00'], $from, 'Europe/Warsaw');

        $this->assertSame('2026-07-07 07:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $next->timezone->getName());
    }

    // ---- twice_daily ---------------------------------------------------------

    public function test_twice_daily_fires_at_the_earlier_of_the_two_hours(): void
    {
        $from = Carbon::parse('2026-07-07 07:00:00', 'UTC');

        $next = $this->nextDueAt('twice_daily', ['first_hour' => 9, 'second_hour' => 17], $from);

        $this->assertSame('2026-07-07 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_twice_daily_rolls_to_the_second_hour_then_next_day(): void
    {
        $from = Carbon::parse('2026-07-07 10:00:00', 'UTC');
        $this->assertSame(
            '2026-07-07 17:00:00',
            $this->nextDueAt('twice_daily', ['first_hour' => 9, 'second_hour' => 17], $from)->format('Y-m-d H:i:s'),
        );

        $afterBoth = Carbon::parse('2026-07-07 18:00:00', 'UTC');
        $this->assertSame(
            '2026-07-08 09:00:00',
            $this->nextDueAt('twice_daily', ['first_hour' => 9, 'second_hour' => 17], $afterBoth)->format('Y-m-d H:i:s'),
        );
    }

    // ---- weekly --------------------------------------------------------------

    public function test_weekly_returns_the_next_occurrence_of_the_weekday(): void
    {
        // 2026-07-07 is a Tuesday; weekday 5 = Friday -> 2026-07-10.
        $from = Carbon::parse('2026-07-07 12:00:00', 'UTC');

        $next = $this->nextDueAt('weekly', ['weekday' => 5, 'time' => '09:00'], $from);

        $this->assertSame('2026-07-10 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(5, $next->dayOfWeek); // Carbon: Friday = 5.
    }

    public function test_weekly_same_weekday_but_time_passed_rolls_to_next_week(): void
    {
        // 2026-07-07 is a Tuesday (weekday 2); asking for Tuesday 09:00 at 10:00 -> next Tuesday.
        $from = Carbon::parse('2026-07-07 10:00:00', 'UTC');

        $next = $this->nextDueAt('weekly', ['weekday' => 2, 'time' => '09:00'], $from);

        $this->assertSame('2026-07-14 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(2, $next->dayOfWeek);
    }

    public function test_weekly_same_weekday_time_still_future_stays_today(): void
    {
        // Tuesday 09:00 asked at 08:00 the same Tuesday -> today.
        $from = Carbon::parse('2026-07-07 08:00:00', 'UTC');

        $next = $this->nextDueAt('weekly', ['weekday' => 2, 'time' => '09:00'], $from);

        $this->assertSame('2026-07-07 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_weekday_zero_is_sunday(): void
    {
        // 2026-07-07 Tuesday; weekday 0 (Sunday) -> 2026-07-12.
        $from = Carbon::parse('2026-07-07 12:00:00', 'UTC');

        $next = $this->nextDueAt('weekly', ['weekday' => 0, 'time' => '09:00'], $from);

        $this->assertSame('2026-07-12 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(Carbon::SUNDAY, $next->dayOfWeek);
        $this->assertSame(0, Carbon::SUNDAY);
    }

    // ---- monthly / twice_monthly / last_day / quarterly / yearly -------------

    public function test_monthly_returns_the_day_this_month_then_next(): void
    {
        $from = Carbon::parse('2026-07-07 12:00:00', 'UTC');

        $next = $this->nextDueAt('monthly', ['day' => 15, 'time' => '09:00'], $from);

        $this->assertSame('2026-07-15 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_monthly_day_31_skips_months_without_that_day(): void
    {
        // From 2026-02-01, day 31 does not exist in Feb -> next fire is 2026-03-31 (skip Feb).
        $from = Carbon::parse('2026-02-01 00:00:00', 'UTC');

        $next = $this->nextDueAt('monthly', ['day' => 31, 'time' => '00:00'], $from);

        $this->assertSame('2026-03-31 00:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_twice_monthly_fires_on_the_earlier_day_first(): void
    {
        $from = Carbon::parse('2026-07-07 12:00:00', 'UTC');

        // Days 1 and 15; from the 7th the next is the 15th.
        $next = $this->nextDueAt('twice_monthly', ['first_day' => 1, 'second_day' => 15, 'time' => '09:00'], $from);

        $this->assertSame('2026-07-15 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_last_day_of_month_resolves_february_correctly(): void
    {
        // 2026 is not a leap year -> Feb has 28 days.
        $from = Carbon::parse('2026-02-10 00:00:00', 'UTC');

        $next = $this->nextDueAt('last_day_of_month', ['time' => '00:00'], $from);

        $this->assertSame('2026-02-28 00:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_quarterly_targets_the_next_quarter_month(): void
    {
        // Day 1 of Jan/Apr/Jul/Oct; from 2026-07-07 the next is 2026-10-01.
        $from = Carbon::parse('2026-07-07 12:00:00', 'UTC');

        $next = $this->nextDueAt('quarterly', ['day' => 1, 'time' => '09:00'], $from);

        $this->assertSame('2026-10-01 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_yearly_pins_the_month_and_day(): void
    {
        // Dec 25 09:00; from mid-year the next is this year's Dec 25.
        $from = Carbon::parse('2026-07-07 12:00:00', 'UTC');

        $next = $this->nextDueAt('yearly', ['month' => 12, 'day' => 25, 'time' => '09:00'], $from);

        $this->assertSame('2026-12-25 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_yearly_feb_29_fires_only_in_leap_years(): void
    {
        // Cron day-of-month semantics: month=2 day=29 matches only when Feb 29 exists, so a
        // "yearly" Feb-29 schedule fires once per LEAP year (2026/2027 are skipped entirely).
        // Same latent behavior as monthly day-31 skipping short months — pinned on purpose.
        $from = Carbon::parse('2026-01-01 00:00:00', 'UTC');

        $next = $this->nextDueAt('yearly', ['month' => 2, 'day' => 29, 'time' => '09:00'], $from);

        $this->assertSame('2028-02-29 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    // ---- weekly multi-day + legacy tolerance ---------------------------------

    public function test_weekly_multi_day_fires_on_the_next_listed_weekday(): void
    {
        // 2026-07-07 is a Tuesday (2). weekdays [1,3] = Mon,Wed -> next is Wed 2026-07-08.
        $from = Carbon::parse('2026-07-07 12:00:00', 'UTC');

        $next = $this->nextDueAt('weekly', ['weekdays' => [1, 3], 'time' => '08:00'], $from);

        $this->assertSame('2026-07-08 08:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(3, $next->dayOfWeek); // Wednesday.
    }

    public function test_weekly_tolerates_a_legacy_scalar_weekday(): void
    {
        // A legacy record with a scalar `weekday` (no `weekdays`) still resolves (read tolerance).
        // 2026-07-07 Tuesday; legacy weekday 5 = Friday -> 2026-07-10.
        $from = Carbon::parse('2026-07-07 12:00:00', 'UTC');

        $next = $this->nextDueAt('weekly', ['weekday' => 5, 'time' => '09:00'], $from);

        $this->assertSame('2026-07-10 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    // ---- every_n_months (january-anchored grid) ------------------------------

    public function test_every_n_months_follows_the_january_anchored_grid(): void
    {
        // n=2 grid months are 1,3,5,7,9,11 on day 1. From 2026-07-07 the next grid month is Sep.
        $from = Carbon::parse('2026-07-07 12:00:00', 'UTC');

        $next = $this->nextDueAt('every_n_months', ['n' => 2, 'day' => 1, 'time' => '09:00'], $from);

        $this->assertSame('2026-09-01 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_every_n_months_resets_across_the_year_boundary(): void
    {
        // n=5 grid months are 1,6,11 (Jan,Jun,Nov). After Nov the cadence RESETS to next January
        // (a 2-month gap, not 5) — the modulo-year trade-off mirroring every_n_hours' midnight reset.
        $from = Carbon::parse('2026-11-20 00:00:00', 'UTC');

        $next = $this->nextDueAt('every_n_months', ['n' => 5, 'day' => 15, 'time' => '09:30'], $from);

        $this->assertSame('2027-01-15 09:30:00', $next->format('Y-m-d H:i:s'));
    }

    // ---- nth_weekday_of_month ------------------------------------------------

    public function test_nth_weekday_of_month_fires_on_the_first_weekday_occurrence(): void
    {
        // First Monday from 2026-07-01: July's first Monday is the 6th.
        $from = Carbon::parse('2026-07-01 00:00:00', 'UTC');

        $next = $this->nextDueAt('nth_weekday_of_month', ['ordinal' => 1, 'weekday' => 1, 'time' => '09:00'], $from);

        $this->assertSame('2026-07-06 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(1, $next->dayOfWeek); // Monday.
    }

    public function test_nth_weekday_of_month_ordinal_5_skips_months_without_a_fifth_occurrence(): void
    {
        // July 2026 Mondays are 6,13,20,27 — no FIFTH Monday. The 5th-Monday schedule must SKIP
        // July and fire on the next month WITH a 5th Monday: August 31, 2026.
        $from = Carbon::parse('2026-07-07 12:00:00', 'UTC');

        $next = $this->nextDueAt('nth_weekday_of_month', ['ordinal' => 5, 'weekday' => 1, 'time' => '09:00'], $from);

        $this->assertSame('2026-08-31 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(1, $next->dayOfWeek); // Monday.
    }

    // ---- last_weekday_of_month -----------------------------------------------

    public function test_last_weekday_of_month_fires_on_the_final_occurrence(): void
    {
        // Last Friday of July 2026 is the 31st.
        $from = Carbon::parse('2026-07-07 12:00:00', 'UTC');

        $next = $this->nextDueAt('last_weekday_of_month', ['weekday' => 5, 'time' => '09:00'], $from);

        $this->assertSame('2026-07-31 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(5, $next->dayOfWeek); // Friday.
    }

    // ---- last_working_day_of_month (bespoke, not cron) -----------------------

    public function test_last_working_day_of_month_steps_back_over_a_weekend_month_end(): void
    {
        // May 2026 ends on Sunday the 31st (30th is Saturday); the last WORKING day is Fri May 29.
        $from = Carbon::parse('2026-05-10 00:00:00', 'UTC');

        $next = $this->nextDueAt('last_working_day_of_month', ['time' => '17:00'], $from);

        $this->assertSame('2026-05-29 17:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(5, $next->dayOfWeek); // Friday.
    }

    public function test_last_working_day_of_month_uses_the_last_day_when_it_is_a_weekday(): void
    {
        // August 2026 ends on Monday the 31st (a weekday) — no step-back needed.
        $from = Carbon::parse('2026-08-01 00:00:00', 'UTC');

        $next = $this->nextDueAt('last_working_day_of_month', ['time' => '17:00'], $from);

        $this->assertSame('2026-08-31 17:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(1, $next->dayOfWeek); // Monday.
    }

    public function test_last_working_day_of_month_rolls_to_next_month_when_this_one_has_passed(): void
    {
        // From May 31 (after May's last working day of the 29th) the next fire is June's (Tue 30th).
        $from = Carbon::parse('2026-05-31 00:00:00', 'UTC');

        $next = $this->nextDueAt('last_working_day_of_month', ['time' => '17:00'], $from);

        $this->assertSame('2026-06-30 17:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_last_working_day_of_month_resolves_wall_clock_in_tz(): void
    {
        // Jan 2027 ends on Sunday the 31st; last working day is Fri Jan 29. 09:00 Europe/Warsaw in
        // January (CET, UTC+1) = 08:00 UTC.
        $from = Carbon::parse('2027-01-05 00:00:00', 'UTC');

        $next = $this->nextDueAt('last_working_day_of_month', ['time' => '09:00'], $from, 'Europe/Warsaw');

        $this->assertSame('2027-01-29 08:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $next->timezone->getName());
    }

    // ---- tz + DST ------------------------------------------------------------

    /**
     * DST spring-forward boundary. On 2026-03-29 Europe/Warsaw jumps 02:00 -> 03:00, so local
     * 02:30 does not exist that night. A daily 02:30 schedule must not crash or loop; the cron
     * lib resolves the non-existent local time forward past the gap (to 03:30 local = 01:30 UTC).
     * We PIN that observed resolution (mirroring the old preset test's intent), and assert the
     * result is strictly later and a valid UTC instant.
     */
    public function test_daily_across_spring_forward_dst_pins_gap_resolution(): void
    {
        $from = Carbon::parse('2026-03-29 00:15:00', 'UTC');

        $next = $this->nextDueAt('daily', ['time' => '02:30'], $from, 'Europe/Warsaw');

        $this->assertTrue($next->greaterThan($from));
        $this->assertSame('UTC', $next->timezone->getName());
        // Observed gap resolution: 03:30 local (Warsaw, +02:00 after the jump) = 01:30 UTC.
        $this->assertSame('2026-03-29 01:30:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-29 03:30:00', $next->copy()->setTimezone('Europe/Warsaw')->format('Y-m-d H:i:s'));
    }

    /**
     * DST FALL-BACK boundary. On 2026-10-25 Europe/Warsaw rolls 03:00 -> 02:00, so local 02:30
     * happens TWICE that night: once in CEST (+02:00) and again in CET (+01:00) after the clock
     * rolls back. A daily 02:30 therefore fires at two DISTINCT UTC instants — 00:30 UTC (02:30
     * CEST) then 01:30 UTC (02:30 CET). We PIN both, walking the strictly-after cursor from the
     * first to the second: because they are different UTC instants, strictly-after advances to the
     * SECOND rather than looping on the same moment, so the same UTC instant is never fired twice.
     * The following day returns to a single 02:30. This is the cron lib's observed resolution.
     */
    public function test_daily_across_fall_back_dst_fires_both_local_0230_instances(): void
    {
        $tz = 'Europe/Warsaw';

        // Just before the ambiguous night. The first 02:30 (CEST) = 00:30 UTC.
        $before = Carbon::parse('2026-10-24 20:00:00', 'UTC');
        $first = $this->nextDueAt('daily', ['time' => '02:30'], $before, $tz);
        $this->assertSame('2026-10-25 00:30:00', $first->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-25 02:30:00 +02:00', $first->copy()->setTimezone($tz)->format('Y-m-d H:i:s P'));

        // Strictly after the first fire, the SAME wall-clock 02:30 recurs in CET = 01:30 UTC.
        $second = $this->nextDueAt('daily', ['time' => '02:30'], $first, $tz);
        $this->assertSame('2026-10-25 01:30:00', $second->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-25 02:30:00 +01:00', $second->copy()->setTimezone($tz)->format('Y-m-d H:i:s P'));
        $this->assertTrue($second->greaterThan($first), 'the two 02:30 instances are distinct UTC instants');

        // The next day returns to a single 02:30 (CET) = 01:30 UTC.
        $third = $this->nextDueAt('daily', ['time' => '02:30'], $second, $tz);
        $this->assertSame('2026-10-26 01:30:00', $third->format('Y-m-d H:i:s'));
    }

    // ---- multiple fire times (times[]) ---------------------------------------

    public function test_times_list_fires_at_the_earlier_of_the_two_times(): void
    {
        // daily 08:00 + 17:00; from 07:00 the earliest strictly-after is 08:00.
        $from = Carbon::parse('2026-07-07 07:00:00', 'UTC');

        $next = $this->nextForBlock(['family' => 'daily', 'params' => [], 'times' => ['08:00', '17:00'], 'tz' => 'UTC'], $from);

        $this->assertSame('2026-07-07 08:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_times_list_rolls_across_the_two_times_then_to_next_day(): void
    {
        $schedule = ['family' => 'daily', 'params' => [], 'times' => ['08:00', '17:00'], 'tz' => 'UTC'];

        // Between the two times -> the later one today.
        $this->assertSame(
            '2026-07-07 17:00:00',
            $this->nextForBlock($schedule, Carbon::parse('2026-07-07 09:00:00', 'UTC'))->format('Y-m-d H:i:s'),
        );

        // After both -> the earlier one tomorrow.
        $this->assertSame(
            '2026-07-08 08:00:00',
            $this->nextForBlock($schedule, Carbon::parse('2026-07-07 18:00:00', 'UTC'))->format('Y-m-d H:i:s'),
        );
    }

    // ---- exclusions ----------------------------------------------------------

    public function test_exclusions_months_skips_the_excluded_month(): void
    {
        // monthly day 1 09:00 excluding August. From mid-July the next fire skips Aug 1 to Sep 1.
        $from = Carbon::parse('2026-07-15 00:00:00', 'UTC');

        $next = $this->nextForBlock([
            'family' => 'monthly',
            'params' => ['day' => 1, 'time' => '09:00'],
            'exclusions' => ['months' => [8]],
            'tz' => 'UTC',
        ], $from);

        $this->assertSame('2026-09-01 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_exclusions_weekdays_skips_the_weekend(): void
    {
        // 2026-07-10 is a Friday. daily 09:00 excluding Sat(6)+Sun(0); from Fri 10:00 -> Mon Jul 13.
        $from = Carbon::parse('2026-07-10 10:00:00', 'UTC');

        $next = $this->nextForBlock([
            'family' => 'daily',
            'params' => ['time' => '09:00'],
            'exclusions' => ['weekdays' => [0, 6]],
            'tz' => 'UTC',
        ], $from);

        $this->assertSame('2026-07-13 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(1, $next->dayOfWeek); // Monday.
    }

    public function test_exclusions_dates_skips_the_specific_date(): void
    {
        // daily 09:00 excluding 2026-07-08; from Jul 07 10:00 the next is Jul 09 (Jul 08 dropped).
        $from = Carbon::parse('2026-07-07 10:00:00', 'UTC');

        $next = $this->nextForBlock([
            'family' => 'daily',
            'params' => ['time' => '09:00'],
            'exclusions' => ['dates' => ['2026-07-08']],
            'tz' => 'UTC',
        ], $from);

        $this->assertSame('2026-07-09 09:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_interval_exclusion_skips_the_whole_weekend(): void
    {
        // every 15 min excluding the weekend. From Fri 23:50 the 15-min phase would step into Sat;
        // every Sat/Sun candidate is dropped, so it lands on Monday, continuing the 15-min phase
        // (…23:50 -> 00:05 Mon after skipping the weekend candidates).
        $from = Carbon::parse('2026-07-10 23:50:00', 'UTC');

        $next = $this->nextForBlock([
            'family' => 'every_n_minutes',
            'params' => ['n' => 15],
            'exclusions' => ['weekdays' => [0, 6]],
            'tz' => 'UTC',
        ], $from);

        $this->assertSame('2026-07-13 00:05:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(1, $next->dayOfWeek); // Monday.
    }

    public function test_times_and_exclusions_combine(): void
    {
        $schedule = [
            'family' => 'daily',
            'params' => [],
            'times' => ['08:00', '17:00'],
            'exclusions' => ['weekdays' => [0, 6]],
            'tz' => 'UTC',
        ];

        // Friday 09:00 -> the same day's 17:00 is fine (Fri is not excluded).
        $this->assertSame(
            '2026-07-10 17:00:00',
            $this->nextForBlock($schedule, Carbon::parse('2026-07-10 09:00:00', 'UTC'))->format('Y-m-d H:i:s'),
        );

        // Friday 18:00 -> Sat/Sun both times excluded -> Monday's earlier time (08:00).
        $next = $this->nextForBlock($schedule, Carbon::parse('2026-07-10 18:00:00', 'UTC'));
        $this->assertSame('2026-07-13 08:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame(1, $next->dayOfWeek); // Monday.
    }

    public function test_over_constrained_exclusions_yield_null(): void
    {
        // weekly on Monday that ALSO excludes Mondays has NO reachable occurrence — the service
        // returns null (the write validator rejects this up front; here we pin the null directly by
        // calling the service with a block that bypasses validation).
        $from = Carbon::parse('2026-07-07 00:00:00', 'UTC');

        $next = $this->nextForBlock([
            'family' => 'weekly',
            'params' => ['weekdays' => [1], 'time' => '09:00'],
            'exclusions' => ['weekdays' => [1]],
            'tz' => 'UTC',
        ], $from);

        $this->assertNull($next, 'a cadence whose exclusions rule out every fire time yields null');
    }

    public function test_sparse_cadence_beyond_the_horizon_yields_null(): void
    {
        // REGRESSION (C1): the 10-year horizon guard was dead code — Carbon 3's signed
        // diffInYears($start) is NEGATIVE for a later candidate, so the old `>= 10` comparison
        // never fired and a yearly Feb-29 cadence with its next reachable leap days excluded
        // returned an absurd far-future instant (2040) instead of null. Pin the null: few
        // candidates (well under MAX_ITERATIONS), all reachable ones beyond the horizon.
        $from = Carbon::parse('2026-07-07 00:00:00', 'UTC');

        $next = $this->nextForBlock([
            'family' => 'yearly',
            'params' => ['month' => 2, 'day' => 29, 'time' => '09:00'],
            'exclusions' => ['dates' => ['2028-02-29', '2032-02-29', '2036-02-29']],
            'tz' => 'UTC',
        ], $from);

        $this->assertNull($next, 'a cadence whose first reachable occurrence lies beyond the horizon yields null');
    }

    // ---- strictly-after across every family ----------------------------------

    public function test_result_is_always_strictly_after_from(): void
    {
        $from = Carbon::parse('2026-07-07 10:00:00', 'UTC');

        $schedules = [
            ['every_n_minutes', ['n' => 1]],
            ['hourly', []],
            ['hourly_at', ['minute' => 0]],
            ['every_n_hours', ['n' => 2]],
            ['daily', ['time' => '10:00']],
            ['twice_daily', ['first_hour' => 8, 'second_hour' => 10]],
            ['weekly', ['weekdays' => [2, 4], 'time' => '10:00']],
            ['monthly', ['day' => 7, 'time' => '10:00']],
            ['twice_monthly', ['first_day' => 1, 'second_day' => 7, 'time' => '10:00']],
            ['last_day_of_month', ['time' => '10:00']],
            ['quarterly', ['day' => 7, 'time' => '10:00']],
            ['yearly', ['month' => 7, 'day' => 7, 'time' => '10:00']],
            ['every_n_months', ['n' => 2, 'day' => 7, 'time' => '10:00']],
            ['nth_weekday_of_month', ['ordinal' => 1, 'weekday' => 2, 'time' => '10:00']],
            ['last_weekday_of_month', ['weekday' => 5, 'time' => '10:00']],
            ['last_working_day_of_month', ['time' => '10:00']],
        ];

        foreach ($schedules as [$family, $params]) {
            $next = $this->nextDueAt($family, $params, $from);
            $this->assertTrue($next->greaterThan($from), "family {$family} must be strictly after from");
            $this->assertSame('UTC', $next->timezone->getName());
        }
    }
}
