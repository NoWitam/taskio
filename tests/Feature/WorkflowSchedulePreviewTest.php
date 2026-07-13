<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The LIVE SCHEDULE-PREVIEW endpoint (POST /api/workflows/meta/schedule-preview). It projects the
 * next N fire instants of a PROPOSED v2 cadence ({ time, day, month, tz, exclusions }) so the FE
 * schedule builder renders a running preview as the user edits.
 *
 * The backend contract under test:
 *   - occurrences are ISO8601 UTC, ascending, with the schedule tz correctly folded into UTC;
 *   - time.at with multiple times produces the interleaved union of fire times;
 *   - exclusions.dates drops the named day;
 *   - an OVER-CONSTRAINED cadence (rules out every fire time) returns 200 with `empty: true` and
 *     `occurrences: []` — NOT a 422, so the FE can warn before saving;
 *   - `approximate` is ALWAYS false (every v2 cadence is a wall-clock grid);
 *   - `count` defaults to 6, honours 1..12, and rejects 0 / 13;
 *   - an optional `anchor` centres the projection: the occurrence AT-OR-BEFORE it comes first;
 *   - the response is FLAT: exactly { occurrences, count, empty, approximate };
 *   - a STRUCTURAL error (unknown time.mode) is still a 422; a guest is 401.
 *
 * Time is frozen so the now-anchored projection is deterministic. A summer anchor keeps Europe/Warsaw
 * on CEST (+02:00), so a wall-clock 08:00 folds to 06:00Z.
 */
class WorkflowSchedulePreviewTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/workflows/meta/schedule-preview';

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed SUMMER anchor: 2026-07-01 00:00 UTC. Europe/Warsaw is on CEST (+02:00) here.
        Carbon::setTestNow(Carbon::parse('2026-07-01T00:00:00Z'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<int, string> the response's occurrences list */
    private function preview(User $user, array $payload): array
    {
        return $this->actingAs($user)->postJson(self::ENDPOINT, $payload)->assertOk()->json('occurrences');
    }

    /** A time.mode=at block for one or more HH:mm fire times. */
    private function at(string ...$times): array
    {
        return ['mode' => 'at', 'at' => array_values($times)];
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson(self::ENDPOINT, ['schedule' => ['time' => $this->at('08:00')]])->assertUnauthorized();
    }

    public function test_daily_wall_clock_projects_six_ascending_utc_instants(): void
    {
        $user = User::factory()->create();

        $occurrences = $this->preview($user, [
            'schedule' => ['time' => $this->at('08:00'), 'tz' => 'Europe/Warsaw'],
        ]);

        $this->assertCount(6, $occurrences);

        $previous = null;
        foreach ($occurrences as $iso) {
            $this->assertStringEndsWith('Z', $iso);
            $moment = Carbon::parse($iso);
            $this->assertSame(0, $moment->getOffset());
            $this->assertSame('06:00:00', $moment->format('H:i:s')); // 08:00 CEST folds to 06:00Z

            if ($previous !== null) {
                $this->assertTrue($moment->greaterThan($previous));
            }
            $previous = $moment;
        }

        // First fire is the day of the anchor (08:00 CEST is strictly after 2026-07-01T00:00Z).
        $this->assertSame('2026-07-01', Carbon::parse($occurrences[0])->format('Y-m-d'));
    }

    public function test_response_reports_count_and_non_empty_non_approximate_flags(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['schedule' => ['time' => $this->at('08:00')]])
            ->assertOk()
            ->assertJsonPath('count', 6)
            ->assertJsonPath('empty', false)
            ->assertJsonPath('approximate', false)
            ->assertJsonCount(6, 'occurrences');
    }

    public function test_time_at_list_interleaves_the_two_fire_times(): void
    {
        $user = User::factory()->create();

        // Two wall-clock UTC times keep the fold trivial: 08:00Z and 20:00Z, alternating.
        $occurrences = $this->preview($user, [
            'schedule' => ['time' => $this->at('08:00', '20:00'), 'tz' => 'UTC'],
            'count' => 4,
        ]);

        $this->assertCount(4, $occurrences);

        $hours = array_map(fn ($iso) => Carbon::parse($iso)->format('H:i'), $occurrences);
        $this->assertSame(['08:00', '20:00', '08:00', '20:00'], $hours);
    }

    public function test_exclusions_dates_skips_the_named_day(): void
    {
        $user = User::factory()->create();

        // Weekly on Monday; the first Monday after the anchor is 2026-07-06 — excluded by date, so the
        // projection skips to the following Monday (2026-07-13).
        $occurrences = $this->preview($user, [
            'schedule' => [
                'time' => $this->at('08:00'),
                'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
                'tz' => 'UTC',
                'exclusions' => ['dates' => ['2026-07-06']],
            ],
            'count' => 3,
        ]);

        $days = array_map(fn ($iso) => Carbon::parse($iso)->format('Y-m-d'), $occurrences);

        $this->assertNotContains('2026-07-06', $days);
        $this->assertSame(['2026-07-13', '2026-07-20', '2026-07-27'], $days);
    }

    public function test_over_constrained_schedule_returns_empty_not_422(): void
    {
        $user = User::factory()->create();

        // Weekly on Monday that ALSO excludes Mondays can never fire. The write path rejects this with a
        // 422; the preview returns it as data so the FE can warn before saving. Response stays FLAT.
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'schedule' => [
                    'time' => $this->at('08:00'),
                    'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
                    'exclusions' => ['weekdays' => [1]],
                ],
            ])
            ->assertOk()
            ->assertExactJson([
                'occurrences' => [],
                'count' => 6,
                'empty' => true,
                'approximate' => false,
            ]);
    }

    public function test_every_minutes_is_not_approximate_and_projects_a_grid(): void
    {
        $user = User::factory()->create();

        // The interval kind is gone: every_minutes is a wall-clock grid, so its preview is EXACT.
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'schedule' => ['time' => ['mode' => 'every_minutes', 'minutes' => 15]],
                'count' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('approximate', false)
            ->assertJsonPath('empty', false)
            ->assertJsonCount(3, 'occurrences');
    }

    public function test_wall_clock_family_is_not_approximate(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['schedule' => ['time' => $this->at('08:00')]])
            ->assertOk()
            ->assertJsonPath('approximate', false);
    }

    public function test_count_defaults_to_six(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['schedule' => ['time' => $this->at('08:00')]])
            ->assertOk()
            ->assertJsonPath('count', 6)
            ->assertJsonCount(6, 'occurrences');
    }

    public function test_count_is_honoured_when_within_bounds(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['schedule' => ['time' => $this->at('08:00')], 'count' => 2])
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonCount(2, 'occurrences');
    }

    public function test_count_above_twelve_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['schedule' => ['time' => $this->at('08:00')], 'count' => 13])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['count']);
    }

    public function test_count_zero_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['schedule' => ['time' => $this->at('08:00')], 'count' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['count']);
    }

    public function test_unknown_time_mode_is_still_a_structural_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['schedule' => ['time' => ['mode' => 'not_a_mode']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schedule.time.mode']);
    }

    // ---- anchor (prev-or-at) -------------------------------------------------

    public function test_anchor_between_occurrences_lists_the_earlier_one_first(): void
    {
        $user = User::factory()->create();

        // Daily 08:00 UTC, anchor 2026-07-10 12:00 (between the 07-10 and 07-11 fires): the projection
        // starts with the occurrence AT-OR-BEFORE the anchor (07-10 08:00Z), then the later ones.
        $occurrences = $this->preview($user, [
            'schedule' => ['time' => $this->at('08:00'), 'tz' => 'UTC'],
            'anchor' => '2026-07-10T12:00:00',
            'count' => 3,
        ]);

        $days = array_map(fn ($iso) => Carbon::parse($iso)->format('Y-m-d H:i:s'), $occurrences);
        $this->assertSame(['2026-07-10 08:00:00', '2026-07-11 08:00:00', '2026-07-12 08:00:00'], $days);
        $this->assertTrue(Carbon::parse($occurrences[0])->lessThanOrEqualTo(Carbon::parse('2026-07-10T12:00:00Z')));
    }

    public function test_anchor_exactly_on_an_occurrence_lists_it_first(): void
    {
        $user = User::factory()->create();

        $occurrences = $this->preview($user, [
            'schedule' => ['time' => $this->at('08:00'), 'tz' => 'UTC'],
            'anchor' => '2026-07-10T08:00:00',
            'count' => 2,
        ]);

        $days = array_map(fn ($iso) => Carbon::parse($iso)->format('Y-m-d H:i:s'), $occurrences);
        $this->assertSame(['2026-07-10 08:00:00', '2026-07-11 08:00:00'], $days);
    }

    public function test_anchor_with_an_offset_is_an_absolute_instant(): void
    {
        $user = User::factory()->create();

        // With an explicit offset the anchor is absolute: 08:00+02:00 = 06:00Z, so the prev-or-at daily
        // 08:00Z occurrence is the PREVIOUS day's (2026-07-09 08:00Z <= 2026-07-10 06:00Z).
        $occurrences = $this->preview($user, [
            'schedule' => ['time' => $this->at('08:00'), 'tz' => 'UTC'],
            'anchor' => '2026-07-10T08:00:00+02:00',
            'count' => 2,
        ]);

        $days = array_map(fn ($iso) => Carbon::parse($iso)->format('Y-m-d H:i:s'), $occurrences);
        $this->assertSame(['2026-07-09 08:00:00', '2026-07-10 08:00:00'], $days);
    }
}
