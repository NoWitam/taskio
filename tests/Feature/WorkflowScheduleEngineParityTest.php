<?php

namespace Tests\Feature;

use App\Modules\Workflows\Services\LegacyScheduleUpgrader;
use App\Modules\Workflows\Services\WorkflowScheduleCompiler;
use App\Modules\Workflows\Services\WorkflowScheduleService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * STATELESSNESS PIN for the schedule engine.
 *
 * `WorkflowScheduleService` must answer a given (descriptor, anchor) with the same instant whether it is
 * the first question the instance has been asked or the ten-thousandth. Today it holds no state at all,
 * so that is free — and this file exists to keep it free, because the obvious future optimization is a
 * cache and this service is the hot path of `RunScheduledWorkflowsCommand`, which runs every minute and
 * decides whether customers' automations fire.
 *
 * A cache that changed WHICH instant comes back would not look like a bug. It would look like a schedule
 * that quietly drifted, discovered weeks later by a customer whose report went out on the wrong day.
 *
 * So every call is made TWICE: once against a service that has never seen the descriptor (a fresh
 * instance, guaranteed cold) and once against a long-lived instance warmed by every earlier call. The
 * two must agree to the microsecond, across every cadence shape the grammar can produce, several DST and
 * calendar edges, and descriptors that differ only in a nested value.
 *
 * (A compilation cache WAS built here and then removed — the profile said compiling is under 2% of a
 * projection, so it could not pay for itself. See the note on WorkflowScheduleService's constructor. The
 * pin outlived the change it was written for, which is the point of writing it first.)
 */
class WorkflowScheduleEngineParityTest extends TestCase
{
    /**
     * One descriptor of every shape the compiler can emit, plus the axes that ride along outside the
     * cron grammar (tz, exclusions) and a LEGACY v1 block that only exists after the read-shim runs.
     *
     * @return array<string, array<string, mixed>>
     */
    private function descriptors(): array
    {
        return [
            'at one time' => ['time' => ['mode' => 'at', 'at' => ['09:00']]],
            'at several times' => ['time' => ['mode' => 'at', 'at' => ['07:15', '13:45', '22:05']]],
            'every minute' => ['time' => ['mode' => 'every_minutes', 'minutes' => 1]],
            'every 7 minutes' => ['time' => ['mode' => 'every_minutes', 'minutes' => 7]],
            'minute window in one hour' => ['time' => ['mode' => 'every_minutes', 'minutes' => 5, 'from' => '09:10', 'to' => '09:50']],
            'minute window across hours' => ['time' => ['mode' => 'every_minutes', 'minutes' => 10, 'from' => '08:30', 'to' => '17:20']],
            'every 3 hours' => ['time' => ['mode' => 'every_hours', 'hours' => 3, 'minute' => 20]],
            'hour window' => ['time' => ['mode' => 'every_hours', 'hours' => 2, 'minute' => 5, 'from' => 8, 'to' => 18]],
            'weekdays' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [1, 3, 5]],
            ],
            'month days' => [
                'time' => ['mode' => 'at', 'at' => ['06:00']],
                'day' => ['mode' => 'month_days', 'days' => [1, 15, 31]],
            ],
            'every n days' => [
                'time' => ['mode' => 'at', 'at' => ['06:00']],
                'day' => ['mode' => 'every_n_days', 'n' => 3],
            ],
            'last day of month' => [
                'time' => ['mode' => 'at', 'at' => ['23:30']],
                'day' => ['mode' => 'special', 'special' => 'last_day'],
            ],
            'nth weekday' => [
                'time' => ['mode' => 'at', 'at' => ['10:00']],
                'day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => 2, 'weekday' => 2],
            ],
            'last weekday' => [
                'time' => ['mode' => 'at', 'at' => ['10:00']],
                'day' => ['mode' => 'special', 'special' => 'last_weekday', 'weekday' => 5],
            ],
            // The bespoke non-cron cadence — a different CompiledSchedule kind entirely.
            'last working day' => [
                'time' => ['mode' => 'at', 'at' => ['17:00']],
                'day' => ['mode' => 'special', 'special' => 'last_working_day'],
            ],
            'last working day in chosen months' => [
                'time' => ['mode' => 'at', 'at' => ['17:00', '18:30']],
                'day' => ['mode' => 'special', 'special' => 'last_working_day'],
                'month' => ['mode' => 'months', 'months' => [3, 6, 9, 12]],
            ],
            'quarterly months' => [
                'time' => ['mode' => 'at', 'at' => ['08:00']],
                'day' => ['mode' => 'month_days', 'days' => [1]],
                'month' => ['mode' => 'months', 'months' => [1, 4, 7, 10]],
            ],
            'every n months' => [
                'time' => ['mode' => 'at', 'at' => ['08:00']],
                'day' => ['mode' => 'month_days', 'days' => [1]],
                'month' => ['mode' => 'every_n_months', 'n' => 2, 'from' => 1, 'to' => 12],
            ],
            // tz and exclusions never reach the cron grammar; they are resolved and post-filtered by
            // the service, so a cache that stored only the compiled cron would lose them.
            'warsaw timezone' => [
                'time' => ['mode' => 'at', 'at' => ['02:30']],
                'tz' => 'Europe/Warsaw',
            ],
            'weekday exclusions' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'exclusions' => ['weekdays' => [0, 6]],
            ],
            'date exclusions in tz' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'tz' => 'Europe/Warsaw',
                'exclusions' => ['dates' => ['2026-08-17', '2026-08-18'], 'months' => [12]],
            ],
            // Over-constrained: weekly Monday that excludes Mondays. Must stay unreachable (null).
            'unreachable cadence' => [
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
                'exclusions' => ['weekdays' => [1]],
            ],
            // A LEGACY v1 block. It only becomes compilable after the read-shim, so it exercises the
            // upgrade half of what is being cached.
            'legacy daily' => ['family' => 'daily', 'params' => ['time' => '09:00']],
            'legacy every n minutes' => ['family' => 'every_n_minutes', 'params' => ['n' => 15]],
            'legacy weekly with carried tz' => [
                'family' => 'weekly',
                'params' => ['weekdays' => [2, 4], 'time' => '11:30'],
                'tz' => 'Europe/Warsaw',
            ],
            'legacy last working day' => ['family' => 'last_working_day_of_month', 'params' => ['time' => '16:00']],
        ];
    }

    /**
     * Anchors chosen to sit on both sides of the two DST transitions the engine documents, on a
     * month boundary, on a leap day, and exactly ON a fire instant (the equality edge that decides
     * whether `previousOrAtOccurrence` returns the anchor itself).
     *
     * @return array<string, string>
     */
    private function anchors(): array
    {
        return [
            'ordinary' => '2026-08-15T12:34:56Z',
            'exactly on a fire' => '2026-08-15T09:00:00Z',
            'month boundary' => '2026-08-31T23:59:59Z',
            'spring forward' => '2026-03-29T00:30:00Z',
            'fall back' => '2026-10-25T00:30:00Z',
            'leap day' => '2028-02-29T06:00:00Z',
            'year end' => '2026-12-31T23:00:00Z',
        ];
    }

    /** A service that has never seen any descriptor. */
    private function coldService(): WorkflowScheduleService
    {
        return new WorkflowScheduleService(new WorkflowScheduleCompiler(new LegacyScheduleUpgrader), new LegacyScheduleUpgrader);
    }

    public function test_next_due_at_is_identical_cold_and_warm(): void
    {
        $warm = $this->coldService();
        $compared = 0;

        foreach ($this->descriptors() as $name => $descriptor) {
            foreach ($this->anchors() as $anchorName => $anchor) {
                $from = CarbonImmutable::parse($anchor);

                $expected = $this->coldService()->nextDueAt($descriptor, $from);

                // Twice against the warm instance: the first call populates, the second reads back.
                $first = $warm->nextDueAt($descriptor, $from);
                $second = $warm->nextDueAt($descriptor, $from);

                $where = $name . ' @ ' . $anchorName;

                $this->assertSame($this->stamp($expected), $this->stamp($first), $where);
                $this->assertSame($this->stamp($expected), $this->stamp($second), $where);
                $compared++;
            }
        }

        $this->assertGreaterThan(150, $compared, 'the descriptor/anchor matrix must not shrink silently');
    }

    public function test_previous_or_at_occurrence_is_identical_cold_and_warm(): void
    {
        $warm = $this->coldService();

        foreach ($this->descriptors() as $name => $descriptor) {
            foreach ($this->anchors() as $anchorName => $anchor) {
                $from = CarbonImmutable::parse($anchor);

                $expected = $this->coldService()->previousOrAtOccurrence($descriptor, $from);
                $actual = $warm->previousOrAtOccurrence($descriptor, $from);
                $again = $warm->previousOrAtOccurrence($descriptor, $from);

                $where = $name . ' @ ' . $anchorName;

                $this->assertSame($this->stamp($expected), $this->stamp($actual), $where);
                $this->assertSame($this->stamp($expected), $this->stamp($again), $where);
            }
        }
    }

    /**
     * The projection seam the calendar uses. Its FIRST element may be at-or-before the anchor
     * (`previousOrAtOccurrence`), and which element that is decides what the calendar filters away —
     * so the whole ordered sequence is compared, not just its head.
     */
    public function test_occurrences_from_produces_an_identical_sequence_cold_and_warm(): void
    {
        $warm = $this->coldService();

        foreach ($this->descriptors() as $name => $descriptor) {
            foreach ($this->anchors() as $anchorName => $anchor) {
                $from = CarbonImmutable::parse($anchor);

                $expected = array_map($this->stamp(...), $this->coldService()->occurrencesFrom($descriptor, $from, 12));
                $actual = array_map($this->stamp(...), $warm->occurrencesFrom($descriptor, $from, 12));

                $this->assertSame($expected, $actual, $name . ' @ ' . $anchorName);
            }
        }
    }

    public function test_next_occurrences_is_identical_cold_and_warm(): void
    {
        $warm = $this->coldService();

        foreach ($this->descriptors() as $name => $descriptor) {
            $from = CarbonImmutable::parse('2026-08-15T12:00:00Z');

            $expected = array_map($this->stamp(...), $this->coldService()->nextOccurrences($descriptor, 8, $from));
            $actual = array_map($this->stamp(...), $warm->nextOccurrences($descriptor, 8, $from));

            $this->assertSame($expected, $actual, $name);
        }
    }

    /**
     * Descriptors that differ only in a nested value must not share a cache slot. A key built from
     * anything coarser than the whole descriptor — the mode, a top-level hash, the `tz` — would return
     * one schedule's cadence for another's, which is the single worst thing this cache could do.
     */
    public function test_similar_descriptors_do_not_share_a_cache_slot(): void
    {
        $warm = $this->coldService();
        $from = CarbonImmutable::parse('2026-08-15T12:00:00Z');

        $pairs = [
            [['time' => ['mode' => 'at', 'at' => ['09:00']]], ['time' => ['mode' => 'at', 'at' => ['09:01']]]],
            [['time' => ['mode' => 'every_minutes', 'minutes' => 5]], ['time' => ['mode' => 'every_minutes', 'minutes' => 6]]],
            [
                ['time' => ['mode' => 'at', 'at' => ['09:00']], 'tz' => 'UTC'],
                ['time' => ['mode' => 'at', 'at' => ['09:00']], 'tz' => 'Europe/Warsaw'],
            ],
            [
                ['time' => ['mode' => 'at', 'at' => ['09:00']]],
                ['time' => ['mode' => 'at', 'at' => ['09:00']], 'exclusions' => ['weekdays' => [6]]],
            ],
            [
                ['time' => ['mode' => 'at', 'at' => ['09:00']], 'day' => ['mode' => 'weekdays', 'weekdays' => [1]]],
                ['time' => ['mode' => 'at', 'at' => ['09:00']], 'day' => ['mode' => 'weekdays', 'weekdays' => [2]]],
            ],
        ];

        foreach ($pairs as $index => [$a, $b]) {
            // Warm A first, then ask B. A shared slot would answer B with A's cadence.
            $warm->nextDueAt($a, $from);

            $this->assertSame(
                $this->stamp($this->coldService()->nextDueAt($b, $from)),
                $this->stamp($warm->nextDueAt($b, $from)),
                'pair ' . $index . ': a descriptor must never be answered with a neighbour\'s cadence'
            );
        }
    }

    /**
     * The schedule timezone falls back to `config('app.timezone')`, which a test (or an operator) can
     * change between calls. A cache that captured the resolved zone and outlived the change would keep
     * answering in the old one.
     */
    public function test_a_changed_application_timezone_is_not_served_from_cache(): void
    {
        $warm = $this->coldService();
        $descriptor = ['time' => ['mode' => 'at', 'at' => ['09:00']]];
        $from = CarbonImmutable::parse('2026-08-15T12:00:00Z');

        config(['app.timezone' => 'UTC']);
        $inUtc = $this->stamp($warm->nextDueAt($descriptor, $from));

        config(['app.timezone' => 'Europe/Warsaw']);
        $inWarsaw = $this->stamp($warm->nextDueAt($descriptor, $from));

        config(['app.timezone' => 'UTC']);

        $this->assertNotSame($inUtc, $inWarsaw, '09:00 UTC and 09:00 Warsaw are different instants');
        $this->assertSame('2026-08-16 09:00:00.000000', $inUtc);
        $this->assertSame('2026-08-16 07:00:00.000000', $inWarsaw);
    }

    private function stamp(mixed $moment): ?string
    {
        return $moment?->utc()->format('Y-m-d H:i:s.u');
    }
}
