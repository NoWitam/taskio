<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Services\LegacyScheduleUpgrader;
use PHPUnit\Framework\TestCase;

/**
 * Read-shim coverage: every legacy `{ family, params }` cadence maps to the v2 compositional
 * descriptor exactly once (one case per family, plus the legacy scalar-weekday tolerance, the
 * times[] passthrough, and the exclusions/tz carry-through). A block WITHOUT a `family` key is
 * already v2 and returned verbatim (idempotent); an unknown family is returned unchanged so v2
 * validation rejects it honestly.
 */
class WorkflowScheduleLegacyUpgraderTest extends TestCase
{
    private LegacyScheduleUpgrader $upgrader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->upgrader = new LegacyScheduleUpgrader;
    }

    private function toV2(array $legacy): array
    {
        return $this->upgrader->toV2($legacy);
    }

    public function test_every_n_minutes_maps_to_time_every_minutes(): void
    {
        $this->assertSame(
            ['time' => ['mode' => 'every_minutes', 'minutes' => 15]],
            $this->toV2(['family' => 'every_n_minutes', 'params' => ['n' => 15]]),
        );
    }

    public function test_hourly_and_hourly_at_map_to_every_hours(): void
    {
        $this->assertSame(
            ['time' => ['mode' => 'every_hours', 'hours' => 1, 'minute' => 0]],
            $this->toV2(['family' => 'hourly', 'params' => []]),
        );
        $this->assertSame(
            ['time' => ['mode' => 'every_hours', 'hours' => 1, 'minute' => 30]],
            $this->toV2(['family' => 'hourly_at', 'params' => ['minute' => 30]]),
        );
    }

    public function test_every_n_hours_maps_to_every_hours(): void
    {
        $this->assertSame(
            ['time' => ['mode' => 'every_hours', 'hours' => 6, 'minute' => 15]],
            $this->toV2(['family' => 'every_n_hours', 'params' => ['n' => 6, 'minute' => 15]]),
        );
    }

    public function test_daily_maps_to_time_at(): void
    {
        $this->assertSame(
            ['time' => ['mode' => 'at', 'at' => ['09:30']]],
            $this->toV2(['family' => 'daily', 'params' => ['time' => '09:30']]),
        );
    }

    public function test_twice_daily_maps_both_hours_at_the_shared_minute(): void
    {
        $this->assertSame(
            ['time' => ['mode' => 'at', 'at' => ['09:00', '17:00']]],
            $this->toV2(['family' => 'twice_daily', 'params' => ['first_hour' => 9, 'second_hour' => 17]]),
        );
        $this->assertSame(
            ['time' => ['mode' => 'at', 'at' => ['01:30', '13:30']]],
            $this->toV2(['family' => 'twice_daily', 'params' => ['first_hour' => 1, 'second_hour' => 13, 'minute' => 30]]),
        );
    }

    public function test_weekly_maps_to_day_weekdays(): void
    {
        $this->assertSame(
            ['time' => ['mode' => 'at', 'at' => ['09:00']], 'day' => ['mode' => 'weekdays', 'weekdays' => [1, 3]]],
            $this->toV2(['family' => 'weekly', 'params' => ['weekdays' => [1, 3], 'time' => '09:00']]),
        );
    }

    public function test_weekly_tolerates_a_legacy_scalar_weekday(): void
    {
        // A row written before the multi-day list still carries a scalar `weekday` — read as [weekday].
        $this->assertSame(
            ['time' => ['mode' => 'at', 'at' => ['09:00']], 'day' => ['mode' => 'weekdays', 'weekdays' => [5]]],
            $this->toV2(['family' => 'weekly', 'params' => ['weekday' => 5, 'time' => '09:00']]),
        );
    }

    public function test_monthly_and_twice_monthly_map_to_month_days(): void
    {
        $this->assertSame(
            ['time' => ['mode' => 'at', 'at' => ['09:00']], 'day' => ['mode' => 'month_days', 'days' => [15]]],
            $this->toV2(['family' => 'monthly', 'params' => ['day' => 15, 'time' => '09:00']]),
        );
        $this->assertSame(
            ['time' => ['mode' => 'at', 'at' => ['09:00']], 'day' => ['mode' => 'month_days', 'days' => [1, 15]]],
            $this->toV2(['family' => 'twice_monthly', 'params' => ['first_day' => 1, 'second_day' => 15, 'time' => '09:00']]),
        );
    }

    public function test_last_day_of_month_maps_to_special_last_day(): void
    {
        $this->assertSame(
            ['time' => ['mode' => 'at', 'at' => ['18:00']], 'day' => ['mode' => 'special', 'special' => 'last_day']],
            $this->toV2(['family' => 'last_day_of_month', 'params' => ['time' => '18:00']]),
        );
    }

    public function test_quarterly_maps_to_month_days_plus_quarter_month_set(): void
    {
        $this->assertSame(
            [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'month_days', 'days' => [1]],
                'month' => ['mode' => 'months', 'months' => [1, 4, 7, 10]],
            ],
            $this->toV2(['family' => 'quarterly', 'params' => ['day' => 1, 'time' => '09:00']]),
        );
    }

    public function test_yearly_maps_to_month_days_plus_single_month(): void
    {
        $this->assertSame(
            [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'month_days', 'days' => [25]],
                'month' => ['mode' => 'months', 'months' => [12]],
            ],
            $this->toV2(['family' => 'yearly', 'params' => ['month' => 12, 'day' => 25, 'time' => '09:00']]),
        );
    }

    public function test_every_n_months_preserves_the_january_anchored_grid_via_a_window(): void
    {
        $this->assertSame(
            [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'month_days', 'days' => [1]],
                'month' => ['mode' => 'every_n_months', 'n' => 2, 'from' => 1, 'to' => 12],
            ],
            $this->toV2(['family' => 'every_n_months', 'params' => ['n' => 2, 'day' => 1, 'time' => '09:00']]),
        );
    }

    public function test_nth_and_last_weekday_of_month_map_to_special(): void
    {
        $this->assertSame(
            ['time' => ['mode' => 'at', 'at' => ['09:00']], 'day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => 1, 'weekday' => 1]],
            $this->toV2(['family' => 'nth_weekday_of_month', 'params' => ['ordinal' => 1, 'weekday' => 1, 'time' => '09:00']]),
        );
        $this->assertSame(
            ['time' => ['mode' => 'at', 'at' => ['09:00']], 'day' => ['mode' => 'special', 'special' => 'last_weekday', 'weekday' => 5]],
            $this->toV2(['family' => 'last_weekday_of_month', 'params' => ['weekday' => 5, 'time' => '09:00']]),
        );
    }

    public function test_last_working_day_of_month_maps_to_special(): void
    {
        $this->assertSame(
            ['time' => ['mode' => 'at', 'at' => ['17:00']], 'day' => ['mode' => 'special', 'special' => 'last_working_day']],
            $this->toV2(['family' => 'last_working_day_of_month', 'params' => ['time' => '17:00']]),
        );
    }

    public function test_a_times_list_becomes_the_at_list_wholesale(): void
    {
        // A wall-clock family carrying the legacy times[] extension maps the whole list into time.at.
        $this->assertSame(
            ['time' => ['mode' => 'at', 'at' => ['08:00', '17:00']]],
            $this->toV2(['family' => 'daily', 'params' => [], 'times' => ['08:00', '17:00']]),
        );
    }

    public function test_tz_and_exclusions_pass_through_unchanged(): void
    {
        $this->assertSame(
            [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'tz' => 'Europe/Warsaw',
                'exclusions' => ['weekdays' => [0, 6]],
            ],
            $this->toV2([
                'family' => 'daily',
                'params' => ['time' => '09:00'],
                'tz' => 'Europe/Warsaw',
                'exclusions' => ['weekdays' => [0, 6]],
            ]),
        );
    }

    public function test_a_v2_block_is_returned_verbatim(): void
    {
        // No `family` key -> already v2 -> idempotent passthrough.
        $v2 = ['time' => ['mode' => 'at', 'at' => ['09:00']], 'day' => ['mode' => 'weekdays', 'weekdays' => [1]]];

        $this->assertSame($v2, $this->toV2($v2));
    }

    public function test_an_unknown_family_is_returned_unchanged(): void
    {
        $unknown = ['family' => 'every_full_moon', 'params' => []];

        $this->assertSame($unknown, $this->toV2($unknown));
    }
}
