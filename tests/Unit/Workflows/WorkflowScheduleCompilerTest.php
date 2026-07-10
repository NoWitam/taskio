<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Services\WorkflowScheduleCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Compiler coverage: the single family->interval/cron/bespoke mapping. Pure computation, no
 * container needed. Asserts the EXACT cron string per family (including `L`, the quarterly month
 * list, the weekly weekday set, every_n_months' January-anchored month grid, and the `#`/`WL`
 * weekday-of-month tokens) so the cadence grammar is pinned. last_working_day_of_month is a bespoke
 * kind (the `LW` token is broken in dragonmantank v3.6.0), asserted on the compiled kind not a cron.
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

    private function compile(string $family, array $params = []): \App\Modules\Workflows\Services\CompiledSchedule
    {
        return $this->compiler->compile(['family' => $family, 'params' => $params]);
    }

    /** Compile a schedule block that may carry the optional `times[]`/`exclusions` extensions. */
    private function compileBlock(array $schedule): \App\Modules\Workflows\Services\CompiledSchedule
    {
        return $this->compiler->compile($schedule);
    }

    public function test_every_n_minutes_is_an_interval_not_cron(): void
    {
        $compiled = $this->compile('every_n_minutes', ['n' => 15]);

        $this->assertTrue($compiled->isInterval());
        $this->assertSame(15, $compiled->minutes);
        $this->assertNull($compiled->expression);
    }

    public function test_hourly_compiles_to_top_of_every_hour(): void
    {
        $compiled = $this->compile('hourly');

        $this->assertTrue($compiled->isCron());
        $this->assertSame('0 * * * *', $compiled->expression);
    }

    public function test_hourly_at_pins_the_minute(): void
    {
        $this->assertSame('30 * * * *', $this->compile('hourly_at', ['minute' => 30])->expression);
    }

    public function test_every_n_hours_uses_hour_of_day_modulo(): void
    {
        // Laravel's everyThreeHours => `M */3 * * *` (hour-of-day modulo, not a rolling interval).
        $this->assertSame('0 */3 * * *', $this->compile('every_n_hours', ['n' => 3])->expression);
        $this->assertSame('15 */6 * * *', $this->compile('every_n_hours', ['n' => 6, 'minute' => 15])->expression);
    }

    public function test_daily_compiles_to_minute_hour(): void
    {
        $this->assertSame('30 9 * * *', $this->compile('daily', ['time' => '09:30'])->expression);
    }

    public function test_twice_daily_lists_both_hours(): void
    {
        $this->assertSame('0 9,17 * * *', $this->compile('twice_daily', ['first_hour' => 9, 'second_hour' => 17])->expression);
        $this->assertSame('30 1,13 * * *', $this->compile('twice_daily', ['first_hour' => 1, 'second_hour' => 13, 'minute' => 30])->expression);
    }

    public function test_weekly_lists_the_weekday_set_sorted_and_deduped(): void
    {
        // weekday 0 = Sunday (our convention AND cron dow 0=Sunday). Single-day list.
        $this->assertSame('0 9 * * 0', $this->compile('weekly', ['weekdays' => [0], 'time' => '09:00'])->expression);
        // Multi-day: sorted ascending and comma-joined.
        $this->assertSame('0 8 * * 1,3', $this->compile('weekly', ['weekdays' => [1, 3], 'time' => '08:00'])->expression);
        // Out-of-order + duplicate input is sorted and deduped.
        $this->assertSame('30 8 * * 1,3,5', $this->compile('weekly', ['weekdays' => [5, 1, 3, 1], 'time' => '08:30'])->expression);
    }

    public function test_weekly_tolerates_a_legacy_scalar_weekday_on_read(): void
    {
        // Bot-module read tolerance: a legacy record carrying a scalar `weekday` (no `weekdays`)
        // still compiles as a single-element list. New writes are validated to the list shape.
        $this->assertSame('30 8 * * 5', $this->compile('weekly', ['weekday' => 5, 'time' => '08:30'])->expression);
        $this->assertSame('0 9 * * 0', $this->compile('weekly', ['weekday' => 0, 'time' => '09:00'])->expression);
    }

    public function test_every_n_months_uses_a_january_anchored_month_grid(): void
    {
        // n=2 anchored at January: 1,3,5,7,9,11 (every other month, resets each January).
        $this->assertSame('0 8 1 1,3,5,7,9,11 *', $this->compile('every_n_months', ['n' => 2, 'day' => 1, 'time' => '08:00'])->expression);
        // n=5 anchored at January: 1,6,11 (the last gap Nov->Jan is 2 months, not 5 — modulo-year).
        $this->assertSame('30 9 15 1,6,11 *', $this->compile('every_n_months', ['n' => 5, 'day' => 15, 'time' => '09:30'])->expression);
    }

    public function test_nth_weekday_of_month_uses_the_hash_token(): void
    {
        // dragonmantank `#`: the O-th weekday W of the month -> `W#O` in the dow field.
        $this->assertSame('0 8 * * 1#1', $this->compile('nth_weekday_of_month', ['ordinal' => 1, 'weekday' => 1, 'time' => '08:00'])->expression);
        // ordinal 5 compiled verbatim — months without a 5th occurrence simply won't match (skip).
        $this->assertSame('30 9 * * 3#5', $this->compile('nth_weekday_of_month', ['ordinal' => 5, 'weekday' => 3, 'time' => '09:30'])->expression);
    }

    public function test_last_weekday_of_month_uses_the_weekday_l_token(): void
    {
        // dragonmantank `WL`: the last weekday W of the month.
        $this->assertSame('0 8 * * 5L', $this->compile('last_weekday_of_month', ['weekday' => 5, 'time' => '08:00'])->expression);
        $this->assertSame('30 9 * * 0L', $this->compile('last_weekday_of_month', ['weekday' => 0, 'time' => '09:30'])->expression);
    }

    public function test_last_working_day_of_month_is_a_bespoke_kind_not_cron(): void
    {
        // NOT cron: dragonmantank's `LW` token is broken (it parses as "nearest weekday to day 0"),
        // so this family compiles to a bespoke last-working-day cadence the service resolves.
        $compiled = $this->compile('last_working_day_of_month', ['time' => '17:00']);

        $this->assertTrue($compiled->isLastWorkingDay());
        $this->assertNull($compiled->expression);
        $this->assertSame(17, $compiled->hour);
        $this->assertSame(0, $compiled->minute);
    }

    public function test_monthly_pins_the_day_of_month(): void
    {
        $this->assertSame('0 9 15 * *', $this->compile('monthly', ['day' => 15, 'time' => '09:00'])->expression);
        // Day 31 is compiled verbatim — short months simply won't match (documented skip).
        $this->assertSame('0 0 31 * *', $this->compile('monthly', ['day' => 31, 'time' => '00:00'])->expression);
    }

    public function test_twice_monthly_lists_both_days(): void
    {
        $this->assertSame('0 9 1,15 * *', $this->compile('twice_monthly', ['first_day' => 1, 'second_day' => 15, 'time' => '09:00'])->expression);
    }

    public function test_last_day_of_month_uses_the_l_token(): void
    {
        $this->assertSame('0 18 L * *', $this->compile('last_day_of_month', ['time' => '18:00'])->expression);
    }

    public function test_quarterly_targets_jan_apr_jul_oct(): void
    {
        $this->assertSame('0 9 1 1,4,7,10 *', $this->compile('quarterly', ['day' => 1, 'time' => '09:00'])->expression);
    }

    public function test_yearly_pins_month_and_day(): void
    {
        $this->assertSame('0 9 25 12 *', $this->compile('yearly', ['month' => 12, 'day' => 25, 'time' => '09:00'])->expression);
    }

    // ---- multiple fire times (times[]) ---------------------------------------

    public function test_single_time_compiles_to_a_one_element_expression_list(): void
    {
        // A scalar params.time yields a one-element expressions list; `expression` mirrors the first.
        $compiled = $this->compile('daily', ['time' => '09:30']);

        $this->assertSame(['30 9 * * *'], $compiled->expressions);
        $this->assertSame('30 9 * * *', $compiled->expression);
    }

    public function test_times_list_expands_to_one_expression_per_time(): void
    {
        // daily with times ["08:00","17:00"] -> one cron expression per time (same date fields).
        $compiled = $this->compileBlock(['family' => 'daily', 'params' => [], 'times' => ['08:00', '17:00']]);

        $this->assertTrue($compiled->isCron());
        $this->assertSame(['0 8 * * *', '0 17 * * *'], $compiled->expressions);
        // `expression` back-compat accessor is the first of the list.
        $this->assertSame('0 8 * * *', $compiled->expression);
    }

    public function test_times_list_shares_the_family_date_fields(): void
    {
        // weekly Mon (1) at 08:15 and 20:45 -> both expressions keep the `* * 1` weekday field.
        $compiled = $this->compileBlock([
            'family' => 'weekly',
            'params' => ['weekdays' => [1]],
            'times' => ['08:15', '20:45'],
        ]);

        $this->assertSame(['15 8 * * 1', '45 20 * * 1'], $compiled->expressions);
    }

    public function test_last_working_day_times_list_carries_every_time(): void
    {
        // last_working_day_of_month is bespoke (not cron); times[] populates its HH:mm pair list.
        $compiled = $this->compileBlock([
            'family' => 'last_working_day_of_month',
            'params' => [],
            'times' => ['09:00', '17:30'],
        ]);

        $this->assertTrue($compiled->isLastWorkingDay());
        $this->assertSame(
            [['hour' => 9, 'minute' => 0], ['hour' => 17, 'minute' => 30]],
            $compiled->times,
        );
        // First-time back-compat accessors.
        $this->assertSame(9, $compiled->hour);
        $this->assertSame(0, $compiled->minute);
    }
}
