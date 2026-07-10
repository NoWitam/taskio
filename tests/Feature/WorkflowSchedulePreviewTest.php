<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The LIVE SCHEDULE-PREVIEW endpoint (POST /api/workflows/meta/schedule-preview). It projects the
 * next N fire instants of a PROPOSED cadence (family/params/tz/times/exclusions) so the FE schedule
 * builder renders a running preview as the user edits.
 *
 * The backend contract under test:
 *   - occurrences are ISO8601 UTC, ascending, with the schedule tz correctly folded into UTC;
 *   - `times[]` produces the interleaved union of fire times;
 *   - `exclusions.dates` drops the named day;
 *   - an OVER-CONSTRAINED cadence (exclusions rule out every fire time) returns 200 with
 *     `empty: true` and `occurrences: []` — NOT a 422, so the FE can warn before saving;
 *   - `approximate` is true ONLY for the interval family (every_n_minutes);
 *   - `count` defaults to 6, honours 1..12, and rejects 0 / 13;
 *   - a STRUCTURAL error (unknown family) is still a 422; a guest is 401.
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

        // A fixed SUMMER anchor: 2026-07-01 00:00 UTC. Europe/Warsaw is on CEST (+02:00) here, so a
        // wall-clock 08:00 schedule fires at 06:00 UTC — the DST fold the happy path asserts.
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
        $response = $this->actingAs($user)->postJson(self::ENDPOINT, $payload);

        $response->assertOk();

        return $response->json('occurrences');
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson(self::ENDPOINT, [
            'schedule' => ['family' => 'daily', 'params' => ['time' => '08:00']],
        ])->assertUnauthorized();
    }

    public function test_daily_wall_clock_projects_six_ascending_utc_instants(): void
    {
        $user = User::factory()->create();

        $occurrences = $this->preview($user, [
            'schedule' => [
                'family' => 'daily',
                'params' => ['time' => '08:00'],
                'tz' => 'Europe/Warsaw',
            ],
        ]);

        $this->assertCount(6, $occurrences);

        // Every instant is 06:00Z (08:00 CEST) and the six days are strictly ascending.
        $previous = null;
        foreach ($occurrences as $iso) {
            // A trailing-Z ISO8601 instant: zero UTC offset, formatted at 06:00 (08:00 CEST).
            $this->assertStringEndsWith('Z', $iso);
            $moment = Carbon::parse($iso);
            $this->assertSame(0, $moment->getOffset());
            $this->assertSame('06:00:00', $moment->format('H:i:s'));

            if ($previous !== null) {
                $this->assertTrue($moment->greaterThan($previous));
            }
            $previous = $moment;
        }

        // First fire is the day after the anchor (strictly-after now = 2026-07-01T00:00Z).
        $this->assertSame('2026-07-01', Carbon::parse($occurrences[0])->format('Y-m-d'));
    }

    public function test_response_reports_count_and_non_empty_non_approximate_flags(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'schedule' => ['family' => 'daily', 'params' => ['time' => '08:00']],
            ])
            ->assertOk()
            ->assertJsonPath('count', 6)
            ->assertJsonPath('empty', false)
            ->assertJsonPath('approximate', false)
            ->assertJsonCount(6, 'occurrences');
    }

    public function test_times_list_interleaves_the_two_fire_times(): void
    {
        $user = User::factory()->create();

        // Two wall-clock times in UTC keep the fold trivial: 08:00Z and 20:00Z, alternating.
        $occurrences = $this->preview($user, [
            'schedule' => [
                'family' => 'daily',
                'times' => ['08:00', '20:00'],
                'tz' => 'UTC',
            ],
            'count' => 4,
        ]);

        $this->assertCount(4, $occurrences);

        $hours = array_map(fn ($iso) => Carbon::parse($iso)->format('H:i'), $occurrences);

        // Anchored at midnight, the union fires 08:00, 20:00, 08:00, 20:00 across two days.
        $this->assertSame(['08:00', '20:00', '08:00', '20:00'], $hours);
    }

    public function test_exclusions_dates_skips_the_named_day(): void
    {
        $user = User::factory()->create();

        // Weekly on Monday; the first Monday after the anchor is 2026-07-06 — excluded by date, so
        // the projection skips straight to the following Monday (2026-07-13).
        $occurrences = $this->preview($user, [
            'schedule' => [
                'family' => 'weekly',
                'params' => ['weekdays' => [1], 'time' => '08:00'],
                'tz' => 'UTC',
                'exclusions' => ['dates' => ['2026-07-06']],
            ],
            'count' => 3,
        ]);

        $days = array_map(fn ($iso) => Carbon::parse($iso)->format('Y-m-d'), $occurrences);

        $this->assertNotContains('2026-07-06', $days);
        $this->assertSame('2026-07-13', $days[0]);
        $this->assertSame(['2026-07-13', '2026-07-20', '2026-07-27'], $days);
    }

    public function test_over_constrained_schedule_returns_empty_not_422(): void
    {
        $user = User::factory()->create();

        // Weekly on Monday that ALSO excludes Mondays can never fire. The write path rejects this
        // with a 422; the preview returns it as data so the FE can warn before saving.
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'schedule' => [
                    'family' => 'weekly',
                    'params' => ['weekdays' => [1], 'time' => '08:00'],
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

    public function test_interval_family_is_flagged_approximate(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'schedule' => ['family' => 'every_n_minutes', 'params' => ['n' => 15]],
                'count' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('approximate', true)
            ->assertJsonPath('empty', false)
            ->assertJsonCount(3, 'occurrences');
    }

    public function test_wall_clock_family_is_not_approximate(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'schedule' => ['family' => 'daily', 'params' => ['time' => '08:00']],
            ])
            ->assertOk()
            ->assertJsonPath('approximate', false);
    }

    public function test_count_defaults_to_six(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'schedule' => ['family' => 'daily', 'params' => ['time' => '08:00']],
            ])
            ->assertOk()
            ->assertJsonPath('count', 6)
            ->assertJsonCount(6, 'occurrences');
    }

    public function test_count_is_honoured_when_within_bounds(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'schedule' => ['family' => 'daily', 'params' => ['time' => '08:00']],
                'count' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonCount(2, 'occurrences');
    }

    public function test_count_above_twelve_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'schedule' => ['family' => 'daily', 'params' => ['time' => '08:00']],
                'count' => 13,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['count']);
    }

    public function test_count_zero_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'schedule' => ['family' => 'daily', 'params' => ['time' => '08:00']],
                'count' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['count']);
    }

    public function test_unknown_family_is_still_a_structural_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'schedule' => ['family' => 'not_a_family', 'params' => []],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['schedule.family']);
    }
}
