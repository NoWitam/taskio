<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Calendar\DTOs\CalendarWindow;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * THE TWO HOURS A YEAR WHEN A CALENDAR IS HARDEST TO BE RIGHT ABOUT.
 *
 * Every other timezone test in this module works in a zone whose offset is constant for the length of
 * the assertion. These do not: they sit on the two instants where the offset CHANGES underneath the
 * arithmetic, which is where "convert to UTC and back" stops being reversible.
 *
 * Three distinct mechanisms meet a transition, and each fails differently:
 *
 *   THE WINDOW      A day is 23 or 25 hours long across a transition. `CalendarWindow` widens a pair
 *                   of `Y-m-d` days to their true UTC edges in the workspace zone; get that wrong and
 *                   an occurrence in the first or last hour of the DST day falls outside the window
 *                   the user is looking at — invisible, with nothing reporting it.
 *
 *   THE CADENCE     A schedule fixed at a wall-clock hour must keep that HOUR, not its UTC offset.
 *                   "09:00 every day" that silently becomes 08:00 after March is the failure users
 *                   describe as "the automation drifted".
 *
 *   THE WRITE       A wall clock in the transition hour is either IMPOSSIBLE (spring: 02:30 never
 *                   happens) or AMBIGUOUS (autumn: 02:30 happens twice). Neither has a "correct"
 *                   answer; both need a DETERMINISTIC one, and — this is the point of the last two
 *                   tests in this file — the SAME one the client already picked.
 *
 * WHY THE CLIENT MATTERS HERE. `resources/js/next/pages/calendar/calendarZone.ts` resolves both edges
 * itself, deterministically, and its own spec pins the answers (`calendarZone.spec.ts`):
 * `2026-03-29T02:30` → `+01:00`, and the doubled `2026-10-25T02:30` → `+01:00` (the LATER, post-
 * transition instant). If the server disagreed, the drawer would show one hour and the grid another,
 * for the same event, with no error anywhere. So the client's two answers are asserted here as
 * SERVER behaviour, side by side with the zone-less input the server resolves on its own.
 *
 * NOTE ON CONFIGURATION. Nothing here leans on `config('app.timezone')` or on whatever a developer's
 * `.env` happens to hold: every test sets the workspace's zone explicitly, and the app default is
 * pinned in setUp so a fallback can never quietly become the thing under test.
 */
class CalendarDaylightSavingTest extends TestCase
{
    use RefreshDatabase;

    /** Europe/Warsaw springs forward: 2026-03-29 02:00 → 03:00 (CET +01:00 → CEST +02:00). */
    private const SPRING_FORWARD_DAY = '2026-03-29';

    /** Europe/Warsaw falls back: 2026-10-25 03:00 → 02:00 (CEST +02:00 → CET +01:00). */
    private const FALL_BACK_DAY = '2026-10-25';

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Named, not inherited. The suite reads the developer's `.env`, and a test whose subject IS a
        // timezone must not take one from the environment.
        config(['app.timezone' => 'UTC']);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create([
            'owner_id' => $this->owner->id,
            'timezone' => 'Europe/Warsaw',
        ]);
        $this->workspace->users()->attach($this->owner->id);

        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function asOwner(): self
    {
        parent::actingAs($this->owner)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    // ── the window ───────────────────────────────────────────────────────────────

    /**
     * A transition day is not 24 hours long, and the window has to say so. Both edges are asserted
     * because they fail in opposite directions: a short day whose end was computed as "start + 24h"
     * would reach an hour into the next day, and a long day computed the same way would lose its last
     * hour entirely.
     */
    public function test_a_transition_day_is_widened_to_its_real_utc_edges(): void
    {
        $spring = new CalendarWindow(
            startDate: self::SPRING_FORWARD_DAY,
            endDate: self::SPRING_FORWARD_DAY,
            timezone: 'Europe/Warsaw',
        );

        // 23 hours: midnight is still CET (+01:00), the day ends already on CEST (+02:00).
        $this->assertSame('2026-03-28T23:00:00+00:00', $spring->startsAt()->toIso8601String());
        $this->assertSame('2026-03-29T21:59:59+00:00', $spring->endsAt()->toIso8601String());
        $this->assertSame(23 * 60, (int) $spring->startsAt()->diffInMinutes($spring->endsAt()->addSecond()));

        $autumn = new CalendarWindow(
            startDate: self::FALL_BACK_DAY,
            endDate: self::FALL_BACK_DAY,
            timezone: 'Europe/Warsaw',
        );

        // 25 hours: the mirror image.
        $this->assertSame('2026-10-24T22:00:00+00:00', $autumn->startsAt()->toIso8601String());
        $this->assertSame('2026-10-25T22:59:59+00:00', $autumn->endsAt()->toIso8601String());
        $this->assertSame(25 * 60, (int) $autumn->startsAt()->diffInMinutes($autumn->endsAt()->addSecond()));
    }

    /**
     * The consequence of the above, through the real endpoint: an event in the FIRST and in the LAST
     * minute of a 25-hour day is on the grid, and the minute before that day began is not.
     *
     * This is the assertion that would fail if anyone "simplified" the window to instants computed in
     * UTC — the neighbouring event would slide in and the last-hour one would drop out, and both would
     * look like ordinary data.
     */
    public function test_the_first_and_last_minute_of_a_long_day_are_both_on_the_grid(): void
    {
        // 2026-10-25 in Warsaw runs 22:00Z (24 Oct) → 22:59:59Z (25 Oct).
        CalendarEvent::factory()->timed('2026-10-24 22:00:00')->create(['title' => 'First minute']);
        CalendarEvent::factory()->timed('2026-10-25 22:59:00')->create(['title' => 'Last minute']);
        CalendarEvent::factory()->timed('2026-10-24 21:59:00')->create(['title' => 'The evening before']);

        $titles = array_column(
            $this->asOwner()
                ->getJson('/api/calendar/occurrences?from=2026-10-25&to=2026-10-25&sources[]=event')
                ->assertOk()
                ->json('data'),
            'title'
        );

        sort($titles);

        $this->assertSame(['First minute', 'Last minute'], $titles);
    }

    // ── the cadence ──────────────────────────────────────────────────────────────

    /**
     * "Every day at 09:00" means 09:00 ON THE WALL, on both sides of a transition — so the UTC instant
     * it projects to MOVES BY AN HOUR, and that movement is the proof the wall clock held.
     *
     * Both directions are checked in one window each, because the two failures are different: after a
     * spring-forward a naive UTC cadence fires an hour late, after a fall-back an hour early, and only
     * one of the two would be noticed by anyone.
     */
    public function test_a_fixed_wall_clock_cadence_keeps_its_hour_across_the_spring_forward(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-03-27T00:00:00Z'));

        $this->scheduleAt('09:00');

        $instants = $this->projectedInstants('2026-03-27', '2026-03-31');

        // 27–28 March are CET (+01:00) → 08:00Z. From the 29th the zone is CEST (+02:00) → 07:00Z.
        // The wall clock never changes; the UTC instant does, which is exactly right.
        $this->assertSame([
            '2026-03-27T08:00:00+00:00',
            '2026-03-28T08:00:00+00:00',
            '2026-03-29T07:00:00+00:00',
            '2026-03-30T07:00:00+00:00',
            '2026-03-31T07:00:00+00:00',
        ], $instants);

        foreach ($instants as $instant) {
            $this->assertSame(
                '09:00',
                CarbonImmutable::parse($instant)->setTimezone('Europe/Warsaw')->format('H:i'),
                'the cadence must keep its WALL CLOCK, not its offset'
            );
        }
    }

    public function test_a_fixed_wall_clock_cadence_keeps_its_hour_across_the_fall_back(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-23T00:00:00Z'));

        $this->scheduleAt('09:00');

        $instants = $this->projectedInstants('2026-10-23', '2026-10-27');

        $this->assertSame([
            '2026-10-23T07:00:00+00:00',
            '2026-10-24T07:00:00+00:00',
            '2026-10-25T08:00:00+00:00',
            '2026-10-26T08:00:00+00:00',
            '2026-10-27T08:00:00+00:00',
        ], $instants);

        foreach ($instants as $instant) {
            $this->assertSame(
                '09:00',
                CarbonImmutable::parse($instant)->setTimezone('Europe/Warsaw')->format('H:i')
            );
        }
    }

    /**
     * The cadence that lands INSIDE the hole. 02:30 does not exist on the spring-forward day, so a
     * daily 02:30 schedule must still produce exactly one occurrence for that day — never zero (the
     * automation silently skipping a day) and never two.
     */
    public function test_a_cadence_inside_the_missing_hour_still_fires_once_that_day(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-03-27T00:00:00Z'));

        $this->scheduleAt('02:30');

        $instants = $this->projectedInstants('2026-03-28', '2026-03-30');

        $days = array_map(
            static fn (string $iso): string => CarbonImmutable::parse($iso)->setTimezone('Europe/Warsaw')->format('Y-m-d'),
            $instants
        );

        $this->assertSame(['2026-03-28', '2026-03-29', '2026-03-30'], $days, 'no day may be skipped or doubled');
    }

    // ── the write ────────────────────────────────────────────────────────────────

    /**
     * THE HOUR THAT DOES NOT EXIST — and the client's answer for it, asserted as server behaviour.
     *
     * `zonedWallClockToInstant('2026-03-29T02:30', 'Europe/Warsaw')` emits `…T02:30:00+01:00`
     * (pinned in `calendarZone.spec.ts`). The server, given the same wall clock with NO zone, resolves
     * it in the workspace's zone through `Carbon::parse`. Both must land on the same instant, or the
     * form and the grid would disagree about an event neither of them can flag.
     */
    public function test_the_hour_that_never_happens_resolves_the_same_way_on_both_sides(): void
    {
        $zoneLess = $this->createTimedEvent('2026-03-29T02:30:00');
        $asTheClientSendsIt = $this->createTimedEvent('2026-03-29T02:30:00+01:00');

        // PHP resolves the non-existent local time forward onto the post-transition offset, which is
        // the same instant `+01:00` names.
        $this->assertSame('2026-03-29T01:30:00+00:00', $zoneLess);
        $this->assertSame($zoneLess, $asTheClientSendsIt, 'the client and the server must pick the same instant');

        // And it is a REAL moment, which reads back as 03:30 — the wall clock that does exist. The
        // value is honest about what it is rather than pretending 02:30 happened.
        $this->assertSame(
            '2026-03-29 03:30',
            CarbonImmutable::parse($zoneLess)->setTimezone('Europe/Warsaw')->format('Y-m-d H:i')
        );
    }

    /**
     * THE HOUR THAT HAPPENS TWICE. Two right answers exist; what matters is that there is exactly one
     * ANSWER, that it never wobbles between requests, and that it is the one the client also picked.
     *
     * The client resolves the doubled `2026-10-25T02:30` to `+01:00` — the LATER, post-transition
     * instant. So does the server, given the same wall clock with no zone. Both `+01:00` and `+02:00`
     * remain accepted verbatim when a caller states one, because an explicit offset is speech and the
     * workspace zone only ever interprets silence.
     */
    public function test_the_hour_that_happens_twice_resolves_to_the_later_instant_on_both_sides(): void
    {
        $zoneLess = $this->createTimedEvent('2026-10-25T02:30:00');

        $this->assertSame('2026-10-25T01:30:00+00:00', $zoneLess);
        $this->assertSame(
            $zoneLess,
            $this->createTimedEvent('2026-10-25T02:30:00+01:00'),
            'the client emits +01:00 for this wall clock; the server must agree'
        );

        // Determinism, stated rather than assumed: the same input twice is the same instant twice.
        $this->assertSame($zoneLess, $this->createTimedEvent('2026-10-25T02:30:00'));

        // The FIRST pass of the doubled hour is still reachable — by saying so.
        $this->assertSame('2026-10-25T00:30:00+00:00', $this->createTimedEvent('2026-10-25T02:30:00+02:00'));
    }

    /**
     * Both events of the doubled hour are ordinary occurrences on the grid, and both read back as
     * 02:30 in the workspace zone — the same wall clock, an hour apart. Nothing here may collapse them
     * or renumber one of them.
     */
    public function test_both_passes_of_the_doubled_hour_render_at_the_same_wall_clock(): void
    {
        CalendarEvent::factory()->timed('2026-10-25 00:30:00')->create(['title' => 'First pass']);
        CalendarEvent::factory()->timed('2026-10-25 01:30:00')->create(['title' => 'Second pass']);

        $data = $this->asOwner()
            ->getJson('/api/calendar/occurrences?from=2026-10-25&to=2026-10-25&sources[]=event')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);

        foreach ($data as $occurrence) {
            $this->assertSame(
                '02:30',
                CarbonImmutable::parse($occurrence['starts_at'])->setTimezone('Europe/Warsaw')->format('H:i')
            );
        }
    }

    /**
     * The all-day discriminator does not care about transitions, and that is the whole reason it
     * exists. A day written on a transition day is the SAME day on read — no conversion has any
     * business touching it.
     */
    public function test_an_all_day_event_never_moves_across_a_transition(): void
    {
        foreach ([self::SPRING_FORWARD_DAY, self::FALL_BACK_DAY] as $day) {
            $this->asOwner()
                ->postJson('/api/calendar/events', [
                    'title' => 'Whole day ' . $day,
                    'all_day' => true,
                    'start_date' => $day,
                ])
                ->assertCreated()
                ->assertJsonPath('data.start_date', $day);

            $this->asOwner()
                ->getJson("/api/calendar/occurrences?from={$day}&to={$day}&sources[]=event")
                ->assertOk()
                ->assertJsonPath('data.0.start_date', $day);
        }
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────

    /** POST one timed event and return the instant it actually stored, as UTC ISO-8601. */
    private function createTimedEvent(string $startsAt): string
    {
        $id = $this->asOwner()
            ->postJson('/api/calendar/events', [
                'title' => 'Transition ' . $startsAt,
                'all_day' => false,
                'starts_at' => $startsAt,
            ])
            ->assertCreated()
            ->json('data.id');

        return CalendarEvent::findOrFail($id)->starts_at->utc()->toIso8601String();
    }

    /** An ACTIVE daily schedule at one WARSAW wall-clock time — the schedule carries its own zone. */
    private function scheduleAt(string $time): Workflow
    {
        return Workflow::factory()->create([
            'creator_id' => $this->owner->id,
            'name' => 'Daily at ' . $time,
            'status' => WorkflowStatus::ACTIVE,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_config' => [
                'schedule' => [
                    // The SCHEDULE's zone, not the workspace's: a cadence is compiled in its own tz.
                    // Named explicitly so this never depends on `config('app.timezone')`.
                    'tz' => 'Europe/Warsaw',
                    'time' => ['mode' => 'at', 'at' => [$time]],
                ],
            ],
            // Deliberately unarmed: the projection includes NULL `next_due_at` rows on purpose, so the
            // cadence is what is under test rather than the arming.
            'next_due_at' => null,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'noop', 'config' => ['title' => 'x']],
            ],
        ]);
    }

    /**
     * The schedule occurrences the grid shows for a window, as UTC ISO-8601 instants.
     *
     * @return array<int, string>
     */
    private function projectedInstants(string $from, string $to): array
    {
        $data = $this->asOwner()
            ->getJson("/api/calendar/occurrences?from={$from}&to={$to}&sources[]=workflow_schedule")
            ->assertOk()
            ->json('data');

        return array_map(
            static fn (array $occurrence): string => CarbonImmutable::parse($occurrence['starts_at'])->utc()->toIso8601String(),
            $data
        );
    }
}
