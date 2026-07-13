<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Services\CompiledSchedule;
use App\Modules\Workflows\Services\WorkflowScheduleCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Compiler coverage: the ONE { time, day, month } -> cron/last-working-day mapping. Pure computation,
 * no container. Asserts the EXACT cron string(s) per axis combination so the cadence grammar is
 * pinned — including the minute-window UNION, the `from-to/n` step fields, the `L`/`#`/`WL` day
 * tokens, and the fact that every time expression carries the SAME day/month fields. last_working_day
 * is a bespoke kind (the `LW` token is broken in dragonmantank v3.6.0), asserted on the compiled kind
 * plus its times[] and allowed-months filter.
 *
 * Cron field order is: minute hour day-of-month month day-of-week.
 */
class WorkflowScheduleCompilerTest extends TestCase
{
    private WorkflowScheduleCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compiler = new WorkflowScheduleCompiler;
    }

    private function compile(array $schedule): CompiledSchedule
    {
        return $this->compiler->compile($schedule);
    }

    /** @return array<int, string> */
    private function expressions(array $schedule): array
    {
        return $this->compile($schedule)->expressions;
    }

    // ---- time.at -------------------------------------------------------------

    public function test_time_at_single_compiles_to_minute_hour(): void
    {
        $this->assertSame(['30 9 * * *'], $this->expressions(['time' => ['mode' => 'at', 'at' => ['09:30']]]));
    }

    public function test_time_at_multiple_yields_one_expression_per_time(): void
    {
        $compiled = $this->compile(['time' => ['mode' => 'at', 'at' => ['08:00', '17:00']]]);

        $this->assertTrue($compiled->isCron());
        $this->assertSame(['0 8 * * *', '0 17 * * *'], $compiled->expressions);
    }

    // ---- time.every_minutes --------------------------------------------------

    public function test_every_minutes_without_window_is_a_whole_hour_grid(): void
    {
        $this->assertSame(['*/15 * * * *'], $this->expressions(['time' => ['mode' => 'every_minutes', 'minutes' => 15]]));
    }

    public function test_every_minutes_window_within_one_hour_is_a_single_step_in_range(): void
    {
        // 09:00..09:45 every 15 -> a single `0-45/15 9` (spike-confirmed step-in-range).
        $this->assertSame(
            ['0-45/15 9 * * *'],
            $this->expressions(['time' => ['mode' => 'every_minutes', 'minutes' => 15, 'from' => '09:00', 'to' => '09:45']]),
        );
    }

    public function test_every_minutes_window_across_hours_is_the_three_expression_union(): void
    {
        // 09:30..17:45 every 15 -> head (30-59/15 @9), full middle (*/15 @10-16), tail (0-45/15 @17).
        $this->assertSame(
            ['30-59/15 9 * * *', '*/15 10-16 * * *', '0-45/15 17 * * *'],
            $this->expressions(['time' => ['mode' => 'every_minutes', 'minutes' => 15, 'from' => '09:30', 'to' => '17:45']]),
        );
    }

    public function test_every_minutes_window_across_adjacent_hours_omits_the_empty_middle(): void
    {
        // 09:30..10:15: H1+1 (10) > H2-1 (9), so no full middle expression is emitted.
        $this->assertSame(
            ['30-59/15 9 * * *', '0-15/15 10 * * *'],
            $this->expressions(['time' => ['mode' => 'every_minutes', 'minutes' => 15, 'from' => '09:30', 'to' => '10:15']]),
        );
    }

    // ---- time.every_hours ----------------------------------------------------

    public function test_every_hours_without_window_uses_hour_of_day_modulo(): void
    {
        $this->assertSame(['0 */3 * * *'], $this->expressions(['time' => ['mode' => 'every_hours', 'hours' => 3]]));
        $this->assertSame(['15 */6 * * *'], $this->expressions(['time' => ['mode' => 'every_hours', 'hours' => 6, 'minute' => 15]]));
    }

    public function test_every_hours_with_window_uses_a_range_step(): void
    {
        $this->assertSame(
            ['0 9-17/2 * * *'],
            $this->expressions(['time' => ['mode' => 'every_hours', 'hours' => 2, 'from' => 9, 'to' => 17]]),
        );
    }

    // ---- day axis ------------------------------------------------------------

    public function test_day_weekdays_lists_the_sorted_deduped_set_in_the_dow_field(): void
    {
        $at = ['mode' => 'at', 'at' => ['09:00']];
        $this->assertSame(['0 9 * * 0'], $this->expressions(['time' => $at, 'day' => ['mode' => 'weekdays', 'weekdays' => [0]]]));
        $this->assertSame(['0 9 * * 1,3'], $this->expressions(['time' => $at, 'day' => ['mode' => 'weekdays', 'weekdays' => [3, 1]]]));
        $this->assertSame(['0 9 * * 1,3,5'], $this->expressions(['time' => $at, 'day' => ['mode' => 'weekdays', 'weekdays' => [5, 1, 3, 1]]]));
    }

    public function test_day_month_days_lists_the_days_in_the_dom_field(): void
    {
        $this->assertSame(
            ['0 9 1,15 * *'],
            $this->expressions(['time' => ['mode' => 'at', 'at' => ['09:00']], 'day' => ['mode' => 'month_days', 'days' => [15, 1]]]),
        );
    }

    public function test_day_every_n_days_uses_a_dom_step_field(): void
    {
        $at = ['mode' => 'at', 'at' => ['09:00']];
        // No window: a bare `*/n` dom (resets on the 1st each month).
        $this->assertSame(['0 9 */2 * *'], $this->expressions(['time' => $at, 'day' => ['mode' => 'every_n_days', 'n' => 2]]));
        // Window: a `from-to/n` dom step (spike-confirmed `5-10/2`).
        $this->assertSame(
            ['0 9 5-10/2 * *'],
            $this->expressions(['time' => $at, 'day' => ['mode' => 'every_n_days', 'n' => 2, 'from' => 5, 'to' => 10]]),
        );
    }

    public function test_day_special_last_day_uses_the_l_token(): void
    {
        $this->assertSame(['0 18 L * *'], $this->expressions(['time' => ['mode' => 'at', 'at' => ['18:00']], 'day' => ['mode' => 'special', 'special' => 'last_day']]));
    }

    public function test_day_special_nth_weekday_uses_the_hash_token(): void
    {
        $at = ['mode' => 'at', 'at' => ['08:00']];
        $this->assertSame(['0 8 * * 1#1'], $this->expressions(['time' => $at, 'day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => 1, 'weekday' => 1]]));
        // ordinal 5 compiled verbatim (months without a 5th occurrence simply won't match).
        $this->assertSame(['0 8 * * 2#5'], $this->expressions(['time' => $at, 'day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => 5, 'weekday' => 2]]));
    }

    public function test_day_special_last_weekday_uses_the_weekday_l_token(): void
    {
        $this->assertSame(['0 8 * * 5L'], $this->expressions(['time' => ['mode' => 'at', 'at' => ['08:00']], 'day' => ['mode' => 'special', 'special' => 'last_weekday', 'weekday' => 5]]));
    }

    // ---- month axis ----------------------------------------------------------

    public function test_month_months_lists_the_set_in_the_month_field(): void
    {
        $this->assertSame(
            ['0 9 1 1,4,7,10 *'],
            $this->expressions(['time' => ['mode' => 'at', 'at' => ['09:00']], 'day' => ['mode' => 'month_days', 'days' => [1]], 'month' => ['mode' => 'months', 'months' => [1, 4, 7, 10]]]),
        );
    }

    public function test_month_every_n_months_uses_a_step_field_with_and_without_a_window(): void
    {
        $at = ['mode' => 'at', 'at' => ['09:00']];
        $this->assertSame(['0 9 * */2 *'], $this->expressions(['time' => $at, 'month' => ['mode' => 'every_n_months', 'n' => 2]]));
        $this->assertSame(['0 9 * 3-11/3 *'], $this->expressions(['time' => $at, 'month' => ['mode' => 'every_n_months', 'n' => 3, 'from' => 3, 'to' => 11]]));
    }

    // ---- composition ---------------------------------------------------------

    public function test_axes_compose_into_one_expression(): void
    {
        // Mondays of March at 09:00: dow 1, month 3, dom *.
        $this->assertSame(
            ['0 9 * 3 1'],
            $this->expressions([
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
                'month' => ['mode' => 'months', 'months' => [3]],
            ]),
        );
    }

    public function test_every_time_expression_shares_the_day_and_month_fields(): void
    {
        // Two times, Monday only -> both expressions keep the `* * 1` day/month fields.
        $this->assertSame(
            ['15 8 * * 1', '45 20 * * 1'],
            $this->expressions([
                'time' => ['mode' => 'at', 'at' => ['08:15', '20:45']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
            ]),
        );
    }

    // ---- last_working_day (bespoke) ------------------------------------------

    public function test_last_working_day_is_a_bespoke_kind_over_all_months_by_default(): void
    {
        $compiled = $this->compile([
            'time' => ['mode' => 'at', 'at' => ['17:00']],
            'day' => ['mode' => 'special', 'special' => 'last_working_day'],
        ]);

        $this->assertTrue($compiled->isLastWorkingDay());
        $this->assertSame([['hour' => 17, 'minute' => 0]], $compiled->times);
        $this->assertSame(range(1, 12), $compiled->months);
    }

    public function test_last_working_day_carries_every_time_and_the_allowed_month_filter(): void
    {
        // last_working_day restricted to a quarter-end month set, at two fire times.
        $compiled = $this->compile([
            'time' => ['mode' => 'at', 'at' => ['09:00', '17:30']],
            'day' => ['mode' => 'special', 'special' => 'last_working_day'],
            'month' => ['mode' => 'months', 'months' => [3, 6, 9, 12]],
        ]);

        $this->assertTrue($compiled->isLastWorkingDay());
        $this->assertSame([['hour' => 9, 'minute' => 0], ['hour' => 17, 'minute' => 30]], $compiled->times);
        $this->assertSame([3, 6, 9, 12], $compiled->months);
    }

    public function test_last_working_day_month_filter_expands_an_every_n_months_grid(): void
    {
        // every_n_months n=2 (no window) -> the January-anchored grid 1,3,5,7,9,11.
        $compiled = $this->compile([
            'time' => ['mode' => 'at', 'at' => ['17:00']],
            'day' => ['mode' => 'special', 'special' => 'last_working_day'],
            'month' => ['mode' => 'every_n_months', 'n' => 2],
        ]);

        $this->assertSame([1, 3, 5, 7, 9, 11], $compiled->months);
    }

    // ---- read-shim: a legacy block compiles through the upgrader -------------

    public function test_a_legacy_block_is_upgraded_before_compilation(): void
    {
        // The compiler upgrades a legacy { family, params } block to v2 first (read-shim), so a
        // stored legacy row compiles to the same grammar as its v2 equivalent.
        $this->assertSame(['30 9 * * *'], $this->expressions(['family' => 'daily', 'params' => ['time' => '09:30']]));
        $this->assertSame(['*/15 * * * *'], $this->expressions(['family' => 'every_n_minutes', 'params' => ['n' => 15]]));
    }
}
