<?php

namespace Tests\Unit\Recurrence;

use App\Support\Recurrence\ScheduleEngine;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * WINDOWED projection — {@see ScheduleEngine::occurrencesBetween()}.
 *
 * The engine's older projections are bounded by COUNT, which is the wrong bound for anything drawing
 * a span of time: a sparse cadence asked for N occurrences walks however far N takes it, and the one
 * caller that survives that does so only because it pre-filters on a stored next-fire column. These
 * tests pin the three properties the windowed walk was added for — it stops at the window, the cap is
 * only a fuse, and both edges belong to the caller — plus the seeding subtlety that makes the lower
 * edge inclusive without admitting the minute before it.
 *
 * Pure arithmetic (no DB), on the app TestCase so `config('app.timezone')` resolves for a descriptor
 * that names no tz.
 */
class RecurrenceWindowProjectionTest extends TestCase
{
    private ScheduleEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ScheduleEngine;
    }

    /** A once-a-year cadence: 15 August at 09:00, Warsaw. */
    private function yearly(): array
    {
        return [
            'time' => ['mode' => 'at', 'at' => ['09:00']],
            'day' => ['mode' => 'month_days', 'days' => [15]],
            'month' => ['mode' => 'months', 'months' => [8]],
            'tz' => 'Europe/Warsaw',
        ];
    }

    /** @return array<int, string> the projected instants as ISO-8601 UTC strings */
    private function between(array $schedule, string $from, string $until, int $cap, string $tz = 'UTC'): array
    {
        return array_map(
            fn ($instant): string => $instant->toIso8601String(),
            $this->engine->occurrencesBetween(
                $schedule,
                CarbonImmutable::parse($from, $tz),
                CarbonImmutable::parse($until, $tz),
                $cap,
            ),
        );
    }

    /**
     * THE REASON THIS METHOD EXISTS. A yearly rule asked for 66 occurrences walks sixty-six years and
     * returns 65 dates outside any window a screen could be showing; the same rule asked for a
     * six-week WINDOW returns the one occurrence inside it — and the cap, generous as it is, is never
     * reached because the window stopped the walk first.
     */
    public function test_a_sparse_cadence_stops_at_the_window_rather_than_walking_to_the_count(): void
    {
        $windowed = $this->between($this->yearly(), '2026-08-01 00:00', '2026-09-12 23:59:59', 66, 'Europe/Warsaw');

        $this->assertSame(['2026-08-15T07:00:00+00:00'], $windowed);

        // The count-bounded seam, for contrast: the same descriptor, the same anchor, the same 66 —
        // and an answer that runs to the year 2090.
        $byCount = $this->engine->occurrencesFrom(
            $this->yearly(),
            CarbonImmutable::parse('2026-08-01 00:00', 'Europe/Warsaw'),
            66,
        );

        $this->assertCount(66, $byCount);
        $this->assertSame('2090', end($byCount)->format('Y'));
    }

    /** Both edges belong to the window: an occurrence exactly ON either bound is returned. */
    public function test_both_edges_of_the_window_are_inclusive(): void
    {
        $schedule = ['time' => ['mode' => 'at', 'at' => ['09:00', '10:00']], 'tz' => 'UTC'];

        $this->assertSame(
            ['2026-08-10T09:00:00+00:00', '2026-08-10T10:00:00+00:00'],
            $this->between($schedule, '2026-08-10 09:00', '2026-08-10 10:00', 10),
        );
    }

    /**
     * The lower edge is made inclusive by seeding the walk ONE MINUTE early, and one minute is exactly
     * how much: the occurrence at 09:00 must not be returned for a window that starts at 09:01, and
     * the occurrence at 09:00 must not sneak into a window starting at 10:00 either.
     */
    public function test_the_seeded_minute_does_not_admit_the_occurrence_before_the_window(): void
    {
        $schedule = ['time' => ['mode' => 'every_minutes', 'minutes' => 1], 'tz' => 'UTC'];

        $this->assertSame(
            ['2026-08-10T09:01:00+00:00', '2026-08-10T09:02:00+00:00'],
            $this->between($schedule, '2026-08-10 09:01', '2026-08-10 09:02', 10),
        );
    }

    /**
     * A lower bound carrying SECONDS is honoured to the second. The seed is a minute wide, so the
     * candidate on the minute the window started inside is produced and then dropped by the explicit
     * lower-bound test rather than by trusting the seeding arithmetic.
     */
    public function test_a_lower_bound_with_seconds_excludes_the_occurrence_on_its_own_minute(): void
    {
        $schedule = ['time' => ['mode' => 'every_minutes', 'minutes' => 1], 'tz' => 'UTC'];

        $this->assertSame(
            ['2026-08-10T09:01:00+00:00'],
            $this->between($schedule, '2026-08-10 09:00:30', '2026-08-10 09:01:30', 10),
        );
    }

    /** The cap bounds the DENSE case a window cannot: a minute cadence over a month. */
    public function test_the_cap_is_the_fuse_for_a_dense_cadence(): void
    {
        $schedule = ['time' => ['mode' => 'every_minutes', 'minutes' => 1], 'tz' => 'UTC'];

        $projected = $this->between($schedule, '2026-08-01 00:00', '2026-08-31 23:59', 65);

        $this->assertCount(65, $projected);
        $this->assertSame('2026-08-01T00:00:00+00:00', $projected[0]);
        $this->assertSame('2026-08-01T01:04:00+00:00', end($projected));
    }

    /** A caller detects the fuse by asking for one more than it can render — nothing else can. */
    public function test_an_overrun_is_detectable_by_asking_for_one_more_than_is_renderable(): void
    {
        $schedule = ['time' => ['mode' => 'at', 'at' => ['09:00']], 'tz' => 'UTC'];

        // Three days in the window, a renderable budget of 3: asking for 4 comes back with 3, which is
        // how the caller learns nothing was cut.
        $this->assertCount(3, $this->between($schedule, '2026-08-10 00:00', '2026-08-12 23:59', 4));

        // Four days in the window, the same budget: asking for 4 comes back FULL, which is the signal.
        $this->assertCount(4, $this->between($schedule, '2026-08-10 00:00', '2026-08-13 23:59', 4));
    }

    /** Degenerate bounds and budgets answer empty rather than throwing or looping. */
    public function test_degenerate_bounds_and_caps_return_nothing(): void
    {
        $schedule = ['time' => ['mode' => 'at', 'at' => ['09:00']], 'tz' => 'UTC'];

        $this->assertSame([], $this->between($schedule, '2026-08-12 00:00', '2026-08-10 00:00', 5));
        $this->assertSame([], $this->between($schedule, '2026-08-10 00:00', '2026-08-12 00:00', 0));
        $this->assertSame([], $this->between($schedule, '2026-08-10 00:00', '2026-08-12 00:00', -3));
    }

    /** A window holding no occurrence is empty, not a walk to the horizon. */
    public function test_a_window_with_no_occurrence_is_empty(): void
    {
        $this->assertSame([], $this->between($this->yearly(), '2026-01-01 00:00', '2026-01-31 23:59', 50, 'Europe/Warsaw'));
    }

    /** Exclusions are the engine's, not the window's: a skipped day is skipped here too. */
    public function test_exclusions_still_apply_inside_a_window(): void
    {
        $schedule = [
            'time' => ['mode' => 'at', 'at' => ['09:00']],
            'tz' => 'Europe/Warsaw',
            'exclusions' => ['dates' => ['2026-08-11']],
        ];

        $this->assertSame(
            ['2026-08-10T07:00:00+00:00', '2026-08-12T07:00:00+00:00'],
            $this->between($schedule, '2026-08-10 00:00', '2026-08-12 23:59', 10, 'Europe/Warsaw'),
        );
    }

    /** Wall-clock fields resolve in the descriptor's tz; what comes back is UTC, as everywhere else. */
    public function test_the_window_is_evaluated_in_the_descriptor_timezone_and_returns_utc(): void
    {
        $schedule = ['time' => ['mode' => 'at', 'at' => ['09:00']], 'tz' => 'Europe/Warsaw'];

        $this->assertSame(
            ['2026-08-10T07:00:00+00:00'],
            $this->between($schedule, '2026-08-10 00:00', '2026-08-10 23:59', 5, 'Europe/Warsaw'),
        );
    }
}
