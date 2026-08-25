<?php

namespace Tests\Unit\Recurrence;

use App\Support\Recurrence\ScheduleEngine;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * DAY-SHAPED projection — {@see ScheduleEngine::occurrenceDaysBetween()} and the anchor it rests on.
 *
 * The contract is days in, days out: an all-day recurring subject has no fire time, and the engine
 * underneath has nothing but fire times, so the layer supplies one internally and formats the result
 * back to `Y-m-d` in the descriptor's own zone. Nothing here may hand an instant to a caller — a
 * caller that received one would have to re-derive the day in a zone it would have to guess.
 *
 * THE FIRST TEST IS THE IMPORTANT ONE. The anchor's VALUE is a decision (noon, not midnight) and the
 * damage from getting it wrong is a square on the wrong day — which looks like nothing in a diff. So
 * it is pinned by the property that made noon the answer, re-derived from the live tzdata on every
 * run, rather than by a comment.
 */
class RecurrenceDayProjectionTest extends TestCase
{
    private ScheduleEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ScheduleEngine;
    }

    /** A plain daily cadence in $tz. The time axis is irrelevant to a day projection — see below. */
    private function daily(string $tz): array
    {
        return ['time' => ['mode' => 'at', 'at' => ['09:00']], 'tz' => $tz];
    }

    /**
     * THE ANCHOR DECISION, RE-DERIVED RATHER THAN RESTATED: over every IANA zone, no local day in
     * 2020-2035 either SKIPS the anchor hour or has it TWICE.
     *
     * Midnight — the obvious choice — fails both halves: DST transitions at or across 00:00 are
     * common, so the hour a day projection asks for either does not exist that day (and the answer
     * becomes whatever the resolution rule invents) or exists twice (and the cadence fires twice into
     * one date, spending a slot of the caller's cap on a duplicate square). At the time of writing
     * that is 112 zone-days with no midnight and 45 with two; the assertion below does not hardcode
     * those numbers, it recomputes them, so the day tzdata moves the anchor out from under this
     * decision is the day this test says so.
     *
     * THE WINDOW IS PART OF THE ASSERTION. Noon is clean from 2020 through 2050, which is the span a
     * calendar projects into — but not before: 21 zone-days across 1900-2020 skip noon (Sudan moved
     * its clocks at midday on 2000-01-15) and 4 repeat it. Widening this scan backwards would turn it
     * red without saying anything about the decision it guards. See DAY_ANCHOR for the full account.
     *
     * Change DAY_ANCHOR to '00:00' and this goes red with the offending zones named.
     */
    public function test_the_day_anchor_exists_exactly_once_on_every_day_of_every_timezone(): void
    {
        [$hour, $minute] = array_map('intval', explode(':', ScheduleEngine::DAY_ANCHOR));

        $skipped = [];
        $repeated = [];
        $zones = 0;

        $from = (new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC')))->getTimestamp();
        $to = (new DateTimeImmutable('2036-01-01', new DateTimeZone('UTC')))->getTimestamp();

        foreach (timezone_identifiers_list() as $name) {
            $zones++;
            $transitions = (new DateTimeZone($name))->getTransitions($from, $to);

            for ($i = 1; $i < count($transitions); $i++) {
                $shift = $transitions[$i]['offset'] - $transitions[$i - 1]['offset'];

                if ($shift === 0) {
                    continue;
                }

                $moment = (new DateTimeImmutable('@' . $transitions[$i]['ts']));

                // The same instant read on the OLD offset and on the NEW one. Between those two wall
                // clock readings lies the affected interval: skipped when the clock jumped forward,
                // lived through twice when it jumped back.
                $before = $moment->modify($transitions[$i - 1]['offset'] . ' seconds');
                $after = $moment->modify($transitions[$i]['offset'] . ' seconds');

                [$start, $end] = $shift > 0 ? [$before, $after] : [$after, $before];

                for ($probe = $start, $guard = 0; $probe < $end && $guard < 3000; $probe = $probe->modify('+1 minute'), $guard++) {
                    if ((int) $probe->format('H') !== $hour || (int) $probe->format('i') !== $minute) {
                        continue;
                    }

                    $offender = $name . ' ' . $probe->format('Y-m-d');

                    if ($shift > 0) {
                        $skipped[] = $offender;
                    } else {
                        $repeated[] = $offender;
                    }

                    break;
                }
            }
        }

        $this->assertSame([], $skipped, 'The day anchor (' . ScheduleEngine::DAY_ANCHOR . ') does not exist on '
            . count($skipped) . ' zone-day(s), so a day projection there asks for an hour the day does not have. '
            . 'Noon is the hour no zone skips. Offenders: ' . implode(', ', array_slice($skipped, 0, 8)));

        $this->assertSame([], $repeated, 'The day anchor (' . ScheduleEngine::DAY_ANCHOR . ') happens TWICE on '
            . count($repeated) . ' zone-day(s), so a day projection there returns the same day twice and pays '
            . 'for it out of the caller\'s cap. Offenders: ' . implode(', ', array_slice($repeated, 0, 8)));

        // ANTI-VACUITY: a scan over no zones, or over a tzdata with no transitions, would pass in
        // silence and would be guarding nothing.
        $this->assertGreaterThan(100, $zones, 'the anchor scan saw almost no timezones — it is not looking at tzdata');
    }

    /**
     * THE SAME DECISION, OBSERVED THROUGH THE PUBLIC SEAM. America/Havana turns its clocks back from
     * 01:00 to 00:00 every November, so a midnight-anchored daily cadence fires twice into 1 November
     * and the day list comes back with six entries for five days. At noon it comes back with five.
     */
    public function test_a_daily_cadence_yields_each_day_once_across_a_repeated_midnight(): void
    {
        $days = $this->engine->occurrenceDaysBetween($this->daily('America/Havana'), '2026-10-30', '2026-11-03', 32);

        $this->assertSame(
            ['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03'],
            $days,
            'the fall-back day appeared more than once — the day anchor is no longer an hour that '
            . 'happens exactly once'
        );
    }

    /** And the mirror case: a zone that SKIPS midnight still gets its day. */
    public function test_a_day_series_survives_a_skipped_midnight(): void
    {
        // Africa/Cairo springs forward from 00:00 to 01:00 on 2026-04-24.
        $this->assertSame(
            ['2026-04-23', '2026-04-24', '2026-04-25'],
            $this->engine->occurrenceDaysBetween($this->daily('Africa/Cairo'), '2026-04-23', '2026-04-25', 32),
        );
    }

    /**
     * THE TIME AXIS IS NOT CONSULTED. A day has no fire time, so a descriptor that names one — even a
     * cadence that fires every five minutes — contributes exactly one entry per matching day.
     */
    public function test_the_descriptor_time_axis_is_ignored(): void
    {
        $everyFiveMinutes = ['time' => ['mode' => 'every_minutes', 'minutes' => 5], 'tz' => 'Europe/Warsaw'];

        $this->assertSame(
            ['2026-08-10', '2026-08-11', '2026-08-12'],
            $this->engine->occurrenceDaysBetween($everyFiveMinutes, '2026-08-10', '2026-08-12', 32),
        );
    }

    /**
     * The whole day/month grammar is expressible as a day series, including the bespoke cadence that
     * is normally RESTRICTED to explicit fire times — the injected anchor is what satisfies that
     * restriction, so "the last working day of every month" needs no special case here.
     */
    public function test_the_bespoke_last_working_day_cadence_is_expressible_as_days(): void
    {
        $schedule = [
            'time' => ['mode' => 'every_minutes', 'minutes' => 5],
            'day' => ['mode' => 'special', 'special' => 'last_working_day'],
            'tz' => 'Europe/Warsaw',
        ];

        $this->assertSame(
            ['2026-01-30', '2026-02-27', '2026-03-31', '2026-04-30'],
            $this->engine->occurrenceDaysBetween($schedule, '2026-01-01', '2026-04-30', 12),
        );
    }

    /** Weekday and month-day axes survive the anchor injection untouched. */
    public function test_the_day_and_month_axes_are_untouched(): void
    {
        $mondays = [
            'time' => ['mode' => 'at', 'at' => ['23:30']],
            'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
            'tz' => 'Europe/Warsaw',
        ];

        $this->assertSame(
            ['2026-08-03', '2026-08-10', '2026-08-17', '2026-08-24', '2026-08-31'],
            $this->engine->occurrenceDaysBetween($mondays, '2026-08-01', '2026-08-31', 12),
        );
    }

    /**
     * A LEGACY `{ family, params }` descriptor is upgraded BEFORE the anchor is injected. Injecting
     * first would write a `time` key the read-shim then rebuilds from `family`, silently projecting
     * the legacy fire time instead of the anchor — which for a day contract is invisible until a zone
     * puts that hour on the other side of midnight.
     */
    public function test_a_legacy_descriptor_is_upgraded_before_the_anchor_is_injected(): void
    {
        $legacy = ['family' => 'weekly', 'params' => ['weekdays' => [1], 'time' => '07:30'], 'tz' => 'Europe/Warsaw'];

        $this->assertSame(
            ['2026-08-03', '2026-08-10', '2026-08-17'],
            $this->engine->occurrenceDaysBetween($legacy, '2026-08-01', '2026-08-20', 12),
        );
    }

    /** Exclusions are evaluated on the anchored candidate, so a `dates` entry excludes its own day. */
    public function test_exclusions_apply_to_a_day_series(): void
    {
        $schedule = [
            'time' => ['mode' => 'at', 'at' => ['09:00']],
            'tz' => 'Europe/Warsaw',
            'exclusions' => ['dates' => ['2026-08-12'], 'weekdays' => [0, 6]],
        ];

        $this->assertSame(
            ['2026-08-10', '2026-08-11', '2026-08-13', '2026-08-14', '2026-08-17'],
            $this->engine->occurrenceDaysBetween($schedule, '2026-08-10', '2026-08-17', 32),
        );
    }

    /**
     * DAYS IN, DAYS OUT, in any zone: the same descriptor and the same bounds produce the same DAYS
     * on opposite sides of the date line. This is the property that says no instant leaked — an
     * implementation that formatted in UTC, or that took its bounds at midnight, would shift the list
     * by a day at one of these extremes.
     */
    public function test_the_day_list_does_not_shift_with_the_descriptor_timezone(): void
    {
        $expected = ['2026-08-10', '2026-08-11', '2026-08-12'];

        foreach (['Pacific/Kiritimati', 'Pacific/Pago_Pago', 'UTC', 'Asia/Kathmandu'] as $tz) {
            $this->assertSame(
                $expected,
                $this->engine->occurrenceDaysBetween($this->daily($tz), '2026-08-10', '2026-08-12', 12),
                'the day list shifted in ' . $tz
            );
        }
    }

    /** The cap is the same fuse it is on the windowed projection, counted in DAYS. */
    public function test_the_cap_bounds_the_day_list(): void
    {
        $this->assertSame(
            ['2026-08-10', '2026-08-11'],
            $this->engine->occurrenceDaysBetween($this->daily('UTC'), '2026-08-10', '2026-08-31', 2),
        );
    }

    /** A single-day window is a legal window, and answers about that day. */
    public function test_a_single_day_window_answers_about_that_day(): void
    {
        $this->assertSame(
            ['2026-11-01'],
            $this->engine->occurrenceDaysBetween($this->daily('America/Havana'), '2026-11-01', '2026-11-01', 5),
        );
    }

    /** A bound that is not a calendar day is a programming error, not an empty answer. */
    public function test_a_malformed_bound_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->engine->occurrenceDaysBetween($this->daily('UTC'), 'next tuesday', '2026-08-12', 5);
    }
}
