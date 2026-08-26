<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Calendar\DTOs\CalendarRecurrence;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarRecurrenceService;
use App\Modules\Calendar\Support\CalendarCadenceLabel;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Support\Recurrence\Enums\ScheduleDayMode;
use App\Support\Recurrence\Enums\ScheduleDaySpecial;
use App\Support\Recurrence\Enums\ScheduleLimits;
use App\Support\Recurrence\Enums\ScheduleMonthMode;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * R3 B5 — THE READ SURFACE OF A REPEATING EVENT: a series drawn as every occurrence the window holds.
 *
 * B4 gave an event a rule and built the whole WRITE surface for it; the grid went on drawing ONE square
 * per series, on the day it started. This file is what makes the rule visible, and four of its themes
 * are places where the wrong behaviour would be silent rather than loud:
 *
 *   THE PAST IS DRAWN. Deliberately unlike the workflow-schedule source, which projects forward only
 *   because a computed occurrence in the past is a claim about EXECUTION that may be false. An event
 *   executes nothing, so its rule is the whole truth about how many times it happened, and refusing to
 *   draw last month would delete data the row unambiguously contains.
 *
 *   WHAT IS CUT IS SAID, AND SAID HONESTLY. A series can be cut by its own per-item cap or by the
 *   response ceiling landing in its middle. Both flag every occurrence they emit as a SAMPLE, and the
 *   second files an UNKNOWN count on purpose: a precise figure that describes part of the damage is a
 *   worse lie than no figure at all.
 *
 *   THE COST DOES NOT GROW WITH THE DATA. The number of queries is fixed at four whether the workspace
 *   holds one series or thirty. Projecting inside the row loop is the natural way to write this and
 *   nobody notices until somebody has thirty series, so it is pinned rather than described.
 *
 *   NOTHING ABOUT A ONE-OFF EVENT CHANGED. That is a compatibility guarantee, so it is asserted.
 */
class CalendarSeriesProjectionTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-09-07 is a Monday — the anchor for nearly every weekly fixture below. */
    private const MONDAY = '2026-09-07';

    /** Every Monday of September 2026. */
    private const SEPTEMBER_MONDAYS = ['2026-09-07', '2026-09-14', '2026-09-21', '2026-09-28'];

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Named, never inherited: half of what this file asserts is which clock a day is reckoned on.
        config(['app.timezone' => 'UTC']);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id);

        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- the projection itself ---------------------------------------------------------------

    /**
     * THE HEADLINE OF THE BATCH: four Mondays, four squares. Before B5 this drew one.
     */
    public function test_a_weekly_series_draws_every_occurrence_in_the_window(): void
    {
        $event = $this->weekly();

        $data = $this->read()->assertOk()->json('data');

        $this->assertSame(self::SEPTEMBER_MONDAYS, $this->days($data));

        // Every square is the same event, with the same title, colour and capability — a projection,
        // not four rows.
        foreach ($data as $occurrence) {
            $this->assertSame($event->id, $occurrence['subject']['id']);
            $this->assertSame('calendar_event', $occurrence['subject']['type']);
            $this->assertSame('Standup', $occurrence['title']);
            $this->assertSame('primary', $occurrence['color']);
            $this->assertTrue($occurrence['editable']);
            $this->assertFalse($occurrence['dense']);
        }
    }

    /**
     * THE ANCHOR IS A FLOOR. The bare cadence fires on the 31st of August and every Monday before it;
     * the series does not exist until its own first occurrence, and the window containing those days
     * must not draw them.
     */
    public function test_a_series_never_draws_a_day_before_its_own_anchor(): void
    {
        $this->weekly();

        // A window that opens a full week before the anchor. 2026-08-31 is a Monday and the cadence
        // fires on it; the series does not.
        $days = $this->days($this->read(['from' => '2026-08-24', 'to' => '2026-09-14'])->assertOk()->json('data'));

        $this->assertSame(['2026-09-07', '2026-09-14'], $days);
    }

    /** THE END IS A CEILING, and the day it names is INCLUDED. */
    public function test_a_series_stops_on_its_end_date(): void
    {
        $this->weekly(['until' => '2026-09-21']);

        $this->assertSame(
            ['2026-09-07', '2026-09-14', '2026-09-21'],
            $this->days($this->read()->assertOk()->json('data')),
        );
    }

    /**
     * A REMOVED OCCURRENCE LEAVES A HOLE — the read half of B4's `occurrence` delete scope, which until
     * now stored an exclusion nothing ever consulted.
     */
    public function test_a_removed_occurrence_leaves_a_hole_in_the_series(): void
    {
        $event = $this->weekly();

        $this->actingAsMember()
            ->deleteJson('/api/calendar/events/' . $event->id, [
                'scope' => 'occurrence',
                'occurrence_date' => '2026-09-14',
            ])
            ->assertNoContent();

        $this->assertSame(
            ['2026-09-07', '2026-09-21', '2026-09-28'],
            $this->days($this->read()->assertOk()->json('data')),
        );
    }

    /**
     * THE PRE-FILTER'S TWO HALVES, from the outside: a series that ENDED before the window and one that
     * STARTS after it both contribute nothing. The row that can contribute is the one that starts no
     * later than the window ends and does not end before it begins.
     */
    public function test_a_series_outside_the_window_on_either_side_draws_nothing(): void
    {
        // Ended in August, a week before the window opens.
        $this->weeklyFrom(['day' => $this->mondays(), 'until' => '2026-08-24'], [
            'title' => 'finished',
            'all_day' => false,
            'starts_at' => '2026-08-03T09:00:00Z',
        ]);

        // Starts in October, after the window closes.
        $this->weeklyFrom(['day' => $this->mondays()], [
            'title' => 'not yet',
            'all_day' => false,
            'starts_at' => '2026-10-05T09:00:00Z',
        ]);

        $this->read()->assertOk()->assertJsonPath('data', []);
    }

    /**
     * A REPEATED WALL-CLOCK HOUR IS STILL ONE SQUARE. The shared engine fires TWICE on a fall-back day
     * when the series' own hour lies in the repeated hour, and it is right to: an automation that says
     * 02:30 really does fire twice that night. A CALENDAR SERIES must not, because everything above the
     * engine holds that a DAY names an occurrence — the grid keys by `{event}:{Y-m-d}`, the write
     * surface names an occurrence by `occurrence_date`, and removing one adds a DATE to the rule.
     *
     * Two instants on one day would mint two squares with the SAME id (a duplicate render key), spend
     * two slots of the per-item cap on one day, and leave `occurrence_date` naming a square the user
     * cannot single out. Nothing else would report any of it.
     *
     * The all-day path is immune for free — it projects at the shared layer's noon anchor, chosen
     * because it is the one hour measured never to repeat in any zone — and that immunity is exactly
     * what does NOT carry over to an hour a user picked.
     */
    public function test_a_repeated_wall_clock_hour_is_still_one_square(): void
    {
        $this->workspace->update(['timezone' => 'Europe/Warsaw']);

        // Europe/Warsaw puts its clocks back on 2026-10-25 (03:00 -> 02:00), so 02:30 local happens at
        // two distinct UTC instants that day: 00:30Z and 01:30Z. The anchor below is 02:30 local.
        $this->weeklyFrom(['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value]], [
            'title' => 'Standup',
            'all_day' => false,
            'starts_at' => '2026-10-24T00:30:00Z',
        ]);

        $data = $this->read(['from' => '2026-10-24', 'to' => '2026-10-26'])->assertOk()->json('data');

        $this->assertSame(['2026-10-24', '2026-10-25', '2026-10-26'], $this->days($data));

        $ids = array_column($data, 'id');
        $this->assertSame($ids, array_values(array_unique($ids)), 'one day, one id');

        // The survivor is the EARLIER of the two — what a person who wrote 02:30 meant.
        $this->assertSame('2026-10-25T00:30:00.000000Z', $data[1]['starts_at']);
    }

    /**
     * THE BOUNDARY THE PRE-FILTER IS MOST LIKELY TO GET WRONG: a series that ends on the window's very
     * FIRST day still draws that day, in both shapes.
     *
     * Note this pins the INCLUSIVE end and nothing about the timed half's one-day slack — with the
     * workspace on UTC the stamped clock and the window's clock agree, so both comparisons pass either
     * way. The slack itself is pinned by
     * {@see test_a_series_stamped_on_a_clock_the_workspace_has_since_left_still_draws_its_last_day()}.
     */
    public function test_a_series_that_ends_on_the_windows_first_day_still_draws_it(): void
    {
        $daily = ['mode' => ScheduleDayMode::EVERY_DAY->value];

        $this->weeklyFrom(['day' => $daily, 'until' => '2026-09-01'], [
            'title' => 'timed',
            'all_day' => false,
            'starts_at' => '2026-08-25T09:00:00Z',
        ]);

        $this->weeklyFrom(['day' => $daily, 'until' => '2026-09-01'], [
            'title' => 'all day',
            'all_day' => true,
            'start_date' => '2026-08-25',
        ]);

        $this->assertSame(['2026-09-01', '2026-09-01'], $this->days($this->read()->assertOk()->json('data')));
    }

    /**
     * THE ONE-DAY SLACK IN THE TIMED PRE-FILTER, executed.
     *
     * `recurrence_until` is a DAY on the series' STAMPED clock — the zone written into the rule at save
     * time and never re-derived — while the window's start is an INSTANT. When a workspace later moves
     * to a different clock the two disagree, and the disagreement is exactly one day wide.
     *
     * Here the series is stamped on a zone seven hours behind UTC and ends on 2026-09-01; its last
     * occurrence is therefore 2026-09-02T01:00Z, which is inside a window that opens on 2026-09-02.
     * The obvious, exact-looking comparison (`recurrence_until >= the window's own first day`) reads
     * "2026-09-01 >= 2026-09-02" and DROPS the row — a square silently missing, with nothing reported.
     */
    public function test_a_series_stamped_on_a_clock_the_workspace_has_since_left_still_draws_its_last_day(): void
    {
        $this->workspace->update(['timezone' => 'America/Los_Angeles']);

        // 18:00 in Los Angeles is 01:00 the NEXT DAY in UTC.
        $this->weeklyFrom(['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value], 'until' => '2026-09-01'], [
            'title' => 'Standup',
            'all_day' => false,
            'starts_at' => '2026-08-26T01:00:00Z',
        ]);

        // The workspace moves; the rule keeps the clock it was stamped on.
        $this->workspace->update(['timezone' => 'UTC']);

        $this->read(['from' => '2026-09-02', 'to' => '2026-09-05'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.starts_at', '2026-09-02T01:00:00.000000Z');
    }

    /**
     * A SERIES PROJECTS BACKWARDS, and that is the deliberate departure from the rule the sibling
     * schedule source follows.
     *
     * The schedule source refuses the past because a computed occurrence there is a claim about
     * EXECUTION — the automation may have been deactivated, edited, or over its budget with the slot
     * consumed and nothing run. An ANNOTATION has no execution history to disagree with: nothing ever
     * fires because an event exists, so the rule is the entire record of how many times the meeting
     * happened. Every square below is in the past relative to the pinned clock.
     */
    public function test_a_series_projects_into_the_past_because_an_annotation_has_no_execution_history(): void
    {
        $this->weekly();

        // Two months after the last occurrence: every day the window holds is history.
        $this->travelTo(CarbonImmutable::parse('2026-11-30 12:00:00', 'UTC'));

        $this->assertSame(self::SEPTEMBER_MONDAYS, $this->days($this->read()->assertOk()->json('data')));

        $this->travelBack();
    }

    // ---- the two shapes ----------------------------------------------------------------------

    /**
     * AN ALL-DAY SERIES PROJECTS DAYS, NOT INSTANTS. The read is answered identically from either side
     * of the planet: a zone-free day that was ever parsed as an instant would move a whole day between
     * these two workspaces, which are 26 hours apart.
     */
    public function test_an_all_day_series_stays_a_day_and_is_never_converted(): void
    {
        $this->workspace->update(['timezone' => 'Pacific/Kiritimati']);

        $this->weeklyFrom(['day' => $this->mondays()], [
            'title' => 'Standup',
            'all_day' => true,
            'start_date' => self::MONDAY,
        ]);

        $data = $this->read()->assertOk()->json('data');

        $this->assertSame(self::SEPTEMBER_MONDAYS, array_column($data, 'start_date'));

        foreach ($data as $occurrence) {
            $this->assertTrue($occurrence['all_day']);
            $this->assertNull($occurrence['starts_at']);
            $this->assertNull($occurrence['ends_at']);

            // THE NEW KEYS ARE SET IN TWO SEPARATE CONSTRUCTOR CALLS — one per shape — so the timed
            // pin elsewhere in this file says nothing about this branch. For an all-day series the day
            // IS the projected day, with no clock anywhere near it.
            $this->assertTrue($occurrence['recurring']);
            $this->assertSame($occurrence['start_date'], $occurrence['occurrence_date']);
        }

        $this->workspace->update(['timezone' => 'Pacific/Midway']);

        $this->assertSame(
            self::SEPTEMBER_MONDAYS,
            array_column($this->read()->assertOk()->json('data'), 'start_date'),
        );
    }

    /**
     * A TIMED SERIES KEEPS ITS WALL-CLOCK HOUR AND ITS DURATION ACROSS A DAYLIGHT-SAVING CHANGE.
     *
     * Europe/Warsaw falls back on 2026-10-25, so 09:00 local is 07:00 UTC before it and 08:00 UTC
     * after. An hour-long meeting has to stay an hour long on both sides — the duration is a FIXED
     * interval taken from the anchor, never a wall-clock end re-derived per occurrence.
     */
    public function test_a_timed_series_keeps_its_duration_across_a_daylight_saving_change(): void
    {
        $this->workspace->update(['timezone' => 'Europe/Warsaw']);

        $this->weeklyFrom(['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value]], [
            'title' => 'Standup',
            'all_day' => false,
            'starts_at' => '2026-10-22T07:00:00Z',
            'ends_at' => '2026-10-22T08:00:00Z',
        ]);

        $data = $this->read(['from' => '2026-10-22', 'to' => '2026-10-26'])->assertOk()->json('data');

        $this->assertSame([
            '2026-10-22T07:00:00.000000Z',
            '2026-10-23T07:00:00.000000Z',
            '2026-10-24T07:00:00.000000Z',
            // The clocks went back overnight: the same 09:00 local is an hour later in UTC.
            '2026-10-25T08:00:00.000000Z',
            '2026-10-26T08:00:00.000000Z',
        ], array_column($data, 'starts_at'));

        foreach ($data as $occurrence) {
            $this->assertSame(
                3600,
                (int) CarbonImmutable::parse($occurrence['starts_at'])->diffInSeconds(CarbonImmutable::parse($occurrence['ends_at'])),
                'every occurrence of an hour-long meeting is an hour long',
            );
        }
    }

    // ---- identity ----------------------------------------------------------------------------

    /**
     * AN OCCURRENCE'S ID IS STABLE ACROSS REFRESHES AND DISTINCT PER DAY. Both halves are load-bearing:
     * an id minted per response makes selection and keying flicker on every navigation, and one id for
     * the whole series makes two squares indistinguishable to a client.
     *
     * The SUBJECT still carries the row's own id, so a click opens the event without parsing anything.
     */
    public function test_a_series_occurrence_id_is_stable_distinct_per_day_and_points_at_the_row(): void
    {
        $event = $this->weekly();

        $expected = array_map(fn (string $day): string => 'event:' . $event->id . ':' . $day, self::SEPTEMBER_MONDAYS);

        $first = array_column($this->read()->assertOk()->json('data'), 'id');
        $second = array_column($this->read()->assertOk()->json('data'), 'id');

        $this->assertSame($expected, $first);
        $this->assertSame($first, $second);
        $this->assertCount(4, array_unique($first));
    }

    /**
     * THE DAY IN A SERIES OCCURRENCE'S ID IS THE SERIES' OWN, not the workspace's current one.
     *
     * The rule below is stamped on a zone fourteen hours ahead of UTC and the workspace is then moved
     * to UTC, so the three readings of the same instant are three different days. The id must follow
     * the STAMPED clock, because that is the clock `occurrence_date` is validated on — reading it off
     * the window's timezone instead would give one occurrence two names the moment a workspace changed
     * its clock, and a scoped edit would then refuse the day the client just read off the grid.
     */
    public function test_a_series_occurrence_is_named_on_the_clock_its_rule_was_stamped_on(): void
    {
        $this->workspace->update(['timezone' => 'Pacific/Kiritimati']);

        // 08:00 in Kiritimati (UTC+14) is 18:00 the PREVIOUS DAY in UTC.
        $event = $this->weeklyFrom(['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value]], [
            'title' => 'Standup',
            'all_day' => false,
            'starts_at' => '2026-09-06T18:00:00Z',
        ]);

        $this->workspace->update(['timezone' => 'UTC']);

        $first = $this->read(['from' => '2026-09-06', 'to' => '2026-09-08'])->assertOk()->json('data.0');

        // The instant is on the 6th in UTC — and the occurrence is named for the 7th, which is the day
        // its own rule falls on and the day a scoped write has to be told.
        $this->assertSame('2026-09-06T18:00:00.000000Z', $first['starts_at']);
        $this->assertSame('event:' . $event->id . ':2026-09-07', $first['id']);
    }

    /**
     * A SQUARE CARRIES THE NAME OF THE OCCURRENCE IT IS, and says outright that it is one of a series.
     *
     * A client needs the day the instant a square is clicked — before it has fetched the event — to
     * send a scoped `PUT`/`DELETE`. The only other routes to it are parsing the `id` (the exact thing
     * `subject.id` exists to make unnecessary) and re-deriving it from an instant in a timezone the
     * client would have to guess. And the marker is explicit rather than "cadence_label is not null",
     * which is sufficient today and NOT necessary: a repeating subject is allowed to have no sentence.
     */
    public function test_a_series_square_carries_its_own_date_and_says_it_is_one_of_a_series(): void
    {
        $this->weekly();

        $data = $this->read()->assertOk()->json('data');

        $this->assertSame(self::SEPTEMBER_MONDAYS, array_column($data, 'occurrence_date'));

        foreach ($data as $occurrence) {
            $this->assertTrue($occurrence['recurring']);
        }

        // The published date is the SAME string the id was built from, so the name a client sends back
        // and the name the grid keys by cannot drift apart.
        foreach ($data as $occurrence) {
            $this->assertStringEndsWith(':' . $occurrence['occurrence_date'], $occurrence['id']);
        }

        // …and it is the day a scoped write actually accepts. Asserted through the real endpoint,
        // because a date the grid publishes and the write refuses is worse than no date at all.
        $this->actingAsMember()
            ->deleteJson('/api/calendar/events/' . $data[1]['subject']['id'], [
                'scope' => 'occurrence',
                'occurrence_date' => $data[1]['occurrence_date'],
            ])
            ->assertNoContent();
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * THE CHAPTER'S INVARIANT, ON THE FOUR SHAPES THE ONE ABOVE DOES NOT REACH
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * A DATE THE GRID PUBLISHES AND THE WRITE PATH REFUSES IS WORSE THAN NO DATE AT ALL: the user
     * clicks a square that exists, asks to remove it, and is told it is not an occurrence — about a
     * day they are looking at. Nothing logs it, because both halves are behaving exactly as written.
     *
     * The assertion above establishes the agreement for ONE shape: a timed weekly series, read over a
     * window with room on both sides, on a workspace whose clock is the one the rule was stamped on.
     * Every clause in that sentence is a place the two halves compute the day DIFFERENTLY, and each
     * one below removes exactly one of them:
     *
     *   ALL-DAY      the grid projects at the shared layer's noon anchor and never converts a day;
     *                the write path reads the anchor off the DATE COLUMN. Two routes to one string.
     *   WINDOW EDGE  the projection is bounded by the window and the write path is bounded by the
     *                SERIES. A square on the window's first or last day is the one place an
     *                off-by-one in either bound is invisible on the grid and fatal on the write.
     *   AFTER A DST  the day after a transition is read at a different UTC offset than the anchor was
     *   TRANSITION   stamped at, in both directions, on a series whose own hour never moved.
     *   AFTER A      a split mints a NEW row with a NEW anchor and re-stamps the rule, and closes the
     *   SPLIT        old one mid-window. Both halves publish squares afterwards and BOTH must accept
     *                what they published — the closed half is the one nobody thinks to try.
     */
    public function test_every_day_an_all_day_series_publishes_is_accepted_by_the_write_surface(): void
    {
        // The widest offset there is, so an all-day day that went anywhere near an instant would be
        // printed one day out and the write path would refuse it.
        $this->workspace->update(['timezone' => 'Pacific/Kiritimati']);

        $event = $this->allDayWeekly();

        $data = $this->read()->assertOk()->json('data');

        $this->assertSame(self::SEPTEMBER_MONDAYS, array_column($data, 'occurrence_date'));

        // An all-day square's own day and the day it publishes are the same string — the discriminator
        // holding across the projection, which is what makes the round trip below meaningful.
        foreach ($data as $occurrence) {
            $this->assertTrue($occurrence['all_day']);
            $this->assertSame($occurrence['start_date'], $occurrence['occurrence_date']);
        }

        $this->assertEveryPublishedDayIsAccepted($data, $event->id);
    }

    /**
     * BOTH EDGES OF THE WINDOW, and the first one is the interesting half: the window opens ON the
     * series' own anchor, so a projection that treated its lower bound as exclusive would simply draw
     * three squares and look like a shorter month.
     */
    public function test_a_day_on_either_edge_of_the_window_is_accepted_by_the_write_surface(): void
    {
        $event = $this->weekly();

        // A window whose first AND last day are occurrences — no slack on either side.
        $data = $this->read(['from' => self::MONDAY, 'to' => '2026-09-28'])->assertOk()->json('data');

        $this->assertSame(self::SEPTEMBER_MONDAYS, $this->days($data));
        $this->assertSame(self::MONDAY, $data[0]['occurrence_date'], 'the window opened ON an occurrence');
        $this->assertSame('2026-09-28', $data[3]['occurrence_date'], 'and closed on one');

        // Deliberately the two edges first: if a bound is off by one, the middle squares still work.
        $this->assertEveryPublishedDayIsAccepted([$data[0], $data[3], $data[1], $data[2]], $event->id);
    }

    /**
     * THE DAY AFTER A TRANSITION, IN BOTH DIRECTIONS.
     *
     * The rule is stamped at one UTC offset and the day after the transition is read at another, while
     * the series' own wall-clock hour never moves. The grid names that square on the STAMPED clock and
     * the write path validates it on the same one — but by two different routes, one of which starts
     * from an instant the window produced. A conversion done on the window's clock instead would print
     * the neighbouring day for an hour close to midnight and be exactly right everywhere else.
     *
     * The hour is 23:30 on purpose: an hour in the middle of the day survives an offset error of one
     * hour without moving, so it would assert nothing.
     */
    public function test_the_day_after_a_daylight_saving_transition_is_accepted_by_the_write_surface(): void
    {
        $this->workspace->update(['timezone' => 'Europe/Warsaw']);

        // Europe/Warsaw springs forward on 2026-03-29 (02:00 → 03:00) and falls back on 2026-10-25
        // (03:00 → 02:00). 2026-03-28 is a Saturday; 22:30Z is 23:30 local on CET (+01:00).
        $spring = $this->weeklyFrom(['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value]], [
            'title' => 'Late standup',
            'all_day' => false,
            'starts_at' => '2026-03-28T22:30:00Z',
        ]);

        $data = $this->squaresOf($this->read(['from' => '2026-03-28', 'to' => '2026-03-31'])->assertOk()->json('data'), $spring->id);

        $this->assertSame(
            ['2026-03-28', '2026-03-29', '2026-03-30', '2026-03-31'],
            array_column($data, 'occurrence_date'),
            'the days across a spring-forward must be named once each, on the series own clock',
        );

        // The offset really did change under the series — otherwise this test asserts nothing about DST.
        $this->assertStringEndsWith('T22:30:00.000000Z', $data[0]['starts_at'], 'CET: 23:30 local is 22:30Z');
        $this->assertStringEndsWith('T21:30:00.000000Z', $data[2]['starts_at'], 'CEST: 23:30 local is 21:30Z');

        // The day AFTER the transition first — it is the one read at the new offset.
        $this->assertEveryPublishedDayIsAccepted([$data[2], $data[3]], $spring->id);

        // And the mirror image, on a series of its own.
        $autumn = $this->weeklyFrom(['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value]], [
            'title' => 'Late standup, autumn',
            'all_day' => false,
            'starts_at' => '2026-10-24T21:30:00Z',
        ]);

        // Filtered to the autumn row: the spring series is daily and open-ended, so it is still drawing
        // in October — which is correct, and would otherwise interleave with what this half asserts.
        $data = $this->squaresOf($this->read(['from' => '2026-10-24', 'to' => '2026-10-27'])->assertOk()->json('data'), $autumn->id);

        $this->assertSame(
            ['2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27'],
            array_column($data, 'occurrence_date'),
        );
        $this->assertStringEndsWith('T21:30:00.000000Z', $data[0]['starts_at'], 'CEST: 23:30 local is 21:30Z');
        $this->assertStringEndsWith('T22:30:00.000000Z', $data[2]['starts_at'], 'CET: 23:30 local is 22:30Z');

        $this->assertEveryPublishedDayIsAccepted([$data[2], $data[3]], $autumn->id);
    }

    /**
     * AFTER A SPLIT — both halves, and the CLOSED one is the half nobody tries.
     *
     * A split closes the outgoing series the day before the split point and mints a new row carrying
     * the payload forward. Afterwards the window holds squares from TWO rows with two anchors and two
     * stamped rules, and every one of those squares is something a user can click. The closed half's
     * squares are the ones a bound computed from the wrong end would publish and then refuse: its
     * `recurrence_until` is now mid-window, and the write path checks the named day against it.
     */
    public function test_every_day_either_half_of_a_split_publishes_is_accepted_by_the_write_surface(): void
    {
        $original = $this->weekly();

        // Split at the THIRD Monday, moving the cadence to Wednesdays. 2026-09-23 is a Wednesday.
        $newId = $this->actingAsMember()
            ->putJson('/api/calendar/events/' . $original->id, [
                'title' => 'Standup, teraz w srody',
                'all_day' => false,
                'starts_at' => '2026-09-23T09:00:00Z',
                'scope' => 'following',
                'occurrence_date' => '2026-09-21',
                'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [3]]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNotSame($original->id, $newId, 'a split at a later occurrence produces a NEW row');

        $data = $this->read()->assertOk()->json('data');

        // Two Mondays from the closed half, two Wednesdays from the new one.
        $this->assertSame(['2026-09-07', '2026-09-14', '2026-09-23', '2026-09-30'], $this->days($data));

        $bySubject = [];
        foreach ($data as $occurrence) {
            $bySubject[$occurrence['subject']['id']][] = $occurrence;
        }

        $this->assertCount(2, $bySubject, 'both halves of the split must be drawing');

        foreach ($bySubject as $subjectId => $squares) {
            $this->assertEveryPublishedDayIsAccepted($squares, (string) $subjectId);
        }
    }

    /**
     * EVERY published day, sent back to the write surface as the occurrence it claims to be.
     *
     * `DELETE scope=occurrence` is the narrowest operation that has to AGREE with the grid: it refuses
     * a day that is not an occurrence of that series, on the series' own stamped clock, inside the
     * series' own bounds — which is the whole of the claim a square makes about itself. A 422 here is
     * the two halves disagreeing about which day a square is.
     *
     * The days are removed one at a time and each removal is a real write, so a day already excluded
     * would be refused the second time round: the loop also proves the published dates are DISTINCT
     * without asserting it separately.
     *
     * @param  array<int, array<string, mixed>>  $squares
     */
    /**
     * The squares ONE row drew, in the order the grid drew them.
     *
     * Needed wherever a fixture holds more than one open-ended series: a window is a window, and every
     * row that reaches into it draws. Filtering keeps a test about one row's arithmetic from becoming a
     * test about which other fixtures happen to overlap it.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @return array<int, array<string, mixed>>
     */
    private function squaresOf(array $data, string $eventId): array
    {
        return array_values(array_filter(
            $data,
            fn (array $occurrence): bool => ($occurrence['subject']['id'] ?? null) === $eventId,
        ));
    }

    private function assertEveryPublishedDayIsAccepted(array $squares, string $eventId): void
    {
        $this->assertNotEmpty($squares, 'a round-trip over no squares proves nothing');

        foreach ($squares as $square) {
            $this->assertSame($eventId, $square['subject']['id'], 'the square must point at the row under test');
            $this->assertTrue($square['recurring']);
            $this->assertNotNull($square['occurrence_date']);

            $this->actingAsMember()
                ->deleteJson('/api/calendar/events/' . $eventId, [
                    'scope' => 'occurrence',
                    'occurrence_date' => $square['occurrence_date'],
                ])
                ->assertNoContent();
        }
    }

    /**
     * BOTH NEW KEYS ARE ALWAYS PRESENT AND EMPTY FOR A ONE-OFF. Absent and empty must not be two cases
     * a client has to handle — that is the house rule the three time keys already follow.
     */
    public function test_a_one_off_square_carries_the_keys_empty_rather_than_omitting_them(): void
    {
        CalendarEvent::factory()->allDay('2026-09-09')->create([
            'title' => 'One-off',
            'creator_id' => $this->owner->id,
        ]);

        $occurrence = $this->read()->assertOk()->json('data.0');

        $this->assertArrayHasKey('occurrence_date', $occurrence);
        $this->assertArrayHasKey('recurring', $occurrence);
        $this->assertNull($occurrence['occurrence_date']);
        $this->assertFalse($occurrence['recurring']);
    }

    /**
     * THE COMPATIBILITY GUARANTEE: an event that happens once is byte-for-byte the occurrence it was
     * before series existed — a bare `event:{uuid}`, no cadence, not dense.
     */
    public function test_an_event_that_happens_once_keeps_exactly_the_shape_it_had(): void
    {
        $single = CalendarEvent::factory()
            ->timed('2026-09-09 09:00:00', '2026-09-09 10:00:00')
            ->create(['title' => 'One-off', 'creator_id' => $this->owner->id]);

        $this->weekly();

        $data = collect($this->read()->assertOk()->json('data'))->firstWhere('title', 'One-off');

        $this->assertSame('event:' . $single->id, $data['id']);
        $this->assertNull($data['cadence_label']);
        $this->assertFalse($data['dense']);
        $this->assertSame('2026-09-09T09:00:00.000000Z', $data['starts_at']);
        $this->assertSame('2026-09-09T10:00:00.000000Z', $data['ends_at']);
    }

    // ---- the cadence sentence ----------------------------------------------------------------

    /**
     * THE SERIES MARKER IS TRANSLATED PROSE, SERVER-SIDE — the same doctrine as a source's label and an
     * occurrence's badge, and the only thing that keeps "a new source needs no frontend change" true.
     */
    public function test_a_series_carries_its_cadence_as_translated_prose(): void
    {
        $this->weekly();

        $this->app->setLocale('en');
        $english = $this->read()->assertOk()->json('data.0.cadence_label');

        $this->app->setLocale('pl');
        $polish = $this->read()->assertOk()->json('data.0.cadence_label');

        $this->assertSame('Weekly on Mon', $english);
        $this->assertSame('Co tydzień: pon.', $polish);
        $this->assertNotSame($english, $polish);
    }

    /**
     * THE EVENT ITSELF CARRIES THE SENTENCE TOO, because the grid is not always there to carry it.
     *
     * A reader who clicked a square already has the cadence. A reader who arrived by DEEP LINK has not
     * — and neither has one looking at a series whose occurrences are all outside the window on screen,
     * which is the ordinary case for a rule that finished last month. Without this key the editor can
     * only say nothing, or the frontend has to compose the prose itself — and the moment a client can
     * build this sentence, the server has stopped owning the vocabulary and "a new source needs no
     * frontend change" is over.
     */
    public function test_the_event_itself_carries_the_cadence_sentence(): void
    {
        $event = $this->weekly(['until' => '2026-09-28']);

        // The window the reader is on holds no occurrence of this series at all.
        $this->read(['from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk()->assertJsonPath('data', []);

        $this->app->setLocale('en');
        $this->show($event)->assertJsonPath('data.recurrence_label', 'Weekly on Mon');

        $this->app->setLocale('pl');
        $this->show($event)->assertJsonPath('data.recurrence_label', 'Co tydzień: pon.');

        // Present and empty for an event that happens once — never absent.
        $single = CalendarEvent::factory()->allDay('2026-09-09')->create([
            'title' => 'One-off',
            'creator_id' => $this->owner->id,
        ]);

        $this->show($single)
            ->assertJsonPath('data.recurrence_label', null)
            ->assertJsonStructure(['data' => ['recurrence_label']]);
    }

    /**
     * A RULE PINNED TO ONE MONTH IS SAID AS A YEARLY RULE, not as a monthly one with a parenthesis.
     *
     * "Monthly on day 25, in August" is true word by word and reads as "again next month" — and the
     * rule it describes is the most human preset there is: a birthday, an anniversary. Someone plans a
     * wedding anniversary and the grid tells them it comes round in four weeks.
     *
     * The month appears INSIDE A DATE here, which in Polish is a different form from the name in a
     * list ("25 sierpnia", never "25 sierpień") — so the two catalogues are exercised in both languages.
     */
    public function test_a_rule_pinned_to_one_month_is_said_as_a_yearly_rule(): void
    {
        $august = ['mode' => ScheduleMonthMode::MONTHS->value, 'months' => [8]];

        $this->app->setLocale('en');

        $this->assertSame(
            'Every year on August 25',
            $this->labelFor(['mode' => ScheduleDayMode::MONTH_DAYS->value, 'days' => [25]], $august),
        );
        $this->assertSame(
            'Every year on the last day of August',
            $this->labelFor(['mode' => ScheduleDayMode::SPECIAL->value, 'special' => ScheduleDaySpecial::LAST_DAY->value], $august),
        );
        $this->assertSame(
            'Every year on the third Tue of August',
            $this->labelFor(['mode' => ScheduleDayMode::SPECIAL->value, 'special' => ScheduleDaySpecial::NTH_WEEKDAY->value, 'ordinal' => 3, 'weekday' => 2], $august),
        );
        $this->assertSame(
            'Every year on the last Friday of August',
            $this->labelFor(['mode' => ScheduleDayMode::SPECIAL->value, 'special' => ScheduleDaySpecial::LAST_WEEKDAY->value, 'weekday' => 5], $august),
        );

        $this->app->setLocale('pl');

        // The genitive, which is the whole reason there are two month catalogues.
        $this->assertSame(
            'Co roku, 25 sierpnia',
            $this->labelFor(['mode' => ScheduleDayMode::MONTH_DAYS->value, 'days' => [25]], $august),
        );
        $this->assertSame(
            'Co roku, ostatniego dnia sierpnia',
            $this->labelFor(['mode' => ScheduleDayMode::SPECIAL->value, 'special' => ScheduleDaySpecial::LAST_DAY->value], $august),
        );

        // The two sentences that put an INFLECTED PHRASE next to the genitive month — the pair most
        // likely to be assembled wrongly, because each half agrees with a different word.
        //
        // Note the deliberate asymmetry inside them: `weekdays` is ABBREVIATED ("wt.") and
        // `last_weekdays` is written out ("ostatni piątek"). That is not a slip — the second carries an
        // adjective that has to agree with the weekday's gender, and an abbreviated "ostatni pt." reads
        // worse than the full phrase. Asserted as it actually renders, so nobody "tidies" one catalogue
        // into the other's style without seeing both sentences change.
        $this->assertSame(
            'Co roku: 3. wt. sierpnia',
            $this->labelFor(['mode' => ScheduleDayMode::SPECIAL->value, 'special' => ScheduleDaySpecial::NTH_WEEKDAY->value, 'ordinal' => 3, 'weekday' => 2], $august),
        );
        $this->assertSame(
            'Co roku: ostatni piątek sierpnia',
            $this->labelFor(['mode' => ScheduleDayMode::SPECIAL->value, 'special' => ScheduleDaySpecial::LAST_WEEKDAY->value, 'weekday' => 5], $august),
        );
    }

    /**
     * THE TWO MONTH CATALOGUES ACTUALLY DIFFER WHERE THE LANGUAGE SAYS THEY MUST.
     *
     * `months_in_date` exists only because Polish puts a month inside a date in the genitive. Copying
     * the nominative into it would render "Co roku, 25 sierpień" and pass every other test in this
     * file — the completeness check asserts uniqueness WITHIN a catalogue, never BETWEEN the two.
     *
     * English legitimately repeats itself here, and that is asserted too rather than left ambiguous:
     * "identical in English" is the decision, not an oversight somebody should later "fix" by deleting
     * the second catalogue.
     */
    public function test_the_month_catalogues_differ_exactly_where_the_language_requires_it(): void
    {
        $months = range(ScheduleLimits::MONTH_MIN, ScheduleLimits::MONTH_MAX);

        $this->app->setLocale('pl');

        foreach ($months as $month) {
            $this->assertNotSame(
                __('calendar.cadence.months.' . $month),
                __('calendar.cadence.months_in_date.' . $month),
                "[pl] month {$month} inside a date is the genitive, never the name on its own",
            );
        }

        $this->app->setLocale('en');

        foreach ($months as $month) {
            $this->assertSame(
                __('calendar.cadence.months.' . $month),
                __('calendar.cadence.months_in_date.' . $month),
                "[en] month {$month} takes one form; the duplicate catalogue is the price of the language that does not",
            );
        }
    }

    /**
     * ONLY THE MONTHLY FAMILY COLLAPSES. A daily or weekly rule confined to one month keeps its own
     * cadence word, because that word is still TRUE of every week inside the month it names — turning
     * "Weekly on Mon, in August" into "Every year…" would trade a misleading sentence for a false one.
     *
     * And TWO months is not a yearly rule in this vocabulary either: it stays monthly with a suffix.
     */
    public function test_only_a_monthly_rule_collapses_into_a_yearly_one(): void
    {
        $this->app->setLocale('en');

        $august = ['mode' => ScheduleMonthMode::MONTHS->value, 'months' => [8]];

        $this->assertSame('Daily, in August', $this->labelFor(['mode' => ScheduleDayMode::EVERY_DAY->value], $august));
        $this->assertSame('Weekly on Mon, in August', $this->labelFor($this->mondays(), $august));

        $this->assertSame(
            'Monthly on day 25, in August, December',
            $this->labelFor(
                ['mode' => ScheduleDayMode::MONTH_DAYS->value, 'days' => [25]],
                ['mode' => ScheduleMonthMode::MONTHS->value, 'months' => [12, 8]],
            ),
        );

        // …and with no month axis at all it is a plain monthly rule, exactly as before.
        $this->assertSame(
            'Monthly on day 25',
            $this->labelFor(['mode' => ScheduleDayMode::MONTH_DAYS->value, 'days' => [25]]),
        );
    }

    /**
     * EVERY CADENCE THE CALENDAR'S SUBSET CAN EXPRESS HAS A SENTENCE — asserted against the renderer
     * directly, because reaching six of these through the API would take six writes to prove one thing.
     *
     * A missing sentence is invisible on a grid (the field is legitimately null for sources with no
     * cadence), so the absence would never be reported by anything.
     */
    public function test_every_cadence_the_calendar_accepts_has_a_sentence(): void
    {
        $this->app->setLocale('en');

        $cases = [
            'Daily' => ['mode' => ScheduleDayMode::EVERY_DAY->value],
            'Weekly on Mon, Wed' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [3, 1]],
            'Monthly on day 1, 15' => ['mode' => ScheduleDayMode::MONTH_DAYS->value, 'days' => [15, 1]],
            'Monthly on the last day' => ['mode' => ScheduleDayMode::SPECIAL->value, 'special' => ScheduleDaySpecial::LAST_DAY->value],
            'Monthly on the third Tue' => ['mode' => ScheduleDayMode::SPECIAL->value, 'special' => ScheduleDaySpecial::NTH_WEEKDAY->value, 'ordinal' => 3, 'weekday' => 2],
            'Monthly on the last Friday' => ['mode' => ScheduleDayMode::SPECIAL->value, 'special' => ScheduleDaySpecial::LAST_WEEKDAY->value, 'weekday' => 5],
        ];

        foreach ($cases as $expected => $day) {
            $this->assertSame($expected, $this->labelFor($day));
        }

        // THE SAME CASES A SECOND TIME, PINNED TO ONE MONTH — because there are now TWO default-less
        // `match`es over this set of modes, and the pass above drives only the first.
        //
        // The hole this closes is precise and quiet: admit a sixth mode into the subset, add it to the
        // cases above and to the ordinary renderer, and the suite goes GREEN — while the YEARLY branch,
        // which no case was reaching, throws the first time it meets that mode on a rule pinned to a
        // single month. On a live grid, where the registry turns it into "the events source is
        // unavailable" and every one-off event disappears with it. That is exactly the failure the
        // class docblock calls unreachable, so it has to actually be unreachable.
        //
        // Asserted as "there is a sentence" rather than as exact strings: the four monthly-family
        // sentences are pinned literally in test_a_rule_pinned_to_one_month_is_said_as_a_yearly_rule,
        // and what is being defended here is that every mode SURVIVES both matches and says something.
        $august = ['mode' => ScheduleMonthMode::MONTHS->value, 'months' => [8]];

        foreach ($cases as $day) {
            $pinned = $this->labelFor($day, $august);

            $this->assertIsString($pinned);
            $this->assertNotSame('', $pinned);
        }

        // EXHAUSTIVE BY CONSTRUCTION, not by the author remembering. The renderer's `match` has no
        // default arm on purpose, so a mode admitted into the Calendar's subset without a sentence
        // written for it THROWS on a live grid — where the registry catches it and the whole events
        // source goes dark. These two assertions are what make that impossible to reach: widening the
        // subset turns this red first.
        $this->assertEqualsCanonicalizing(
            CalendarRecurrence::dayModes(),
            array_values(array_unique(array_column($cases, 'mode'))),
        );

        $this->assertEqualsCanonicalizing(
            CalendarRecurrence::daySpecials(),
            array_values(array_column($cases, 'special')),
        );

        // A DAY AXIS THE DESCRIPTOR OMITS still has a sentence: an absent axis means every day, which is
        // the shared layer's own default and the reason the key is optional at all.
        $this->assertSame('Daily', $this->labelFor(null));

        // THE MONTH AXIS IS NEVER SILENTLY DROPPED. "Weekly on Mon" is FALSE about a rule that only
        // fires in January and July, and a marker that lies is worse than one that is missing.
        $this->assertSame(
            'Weekly on Mon, in January, July',
            $this->labelFor($this->mondays(), ['mode' => ScheduleMonthMode::MONTHS->value, 'months' => [7, 1]]),
        );

        // …and an every-month axis adds nothing, because it restricts nothing.
        $this->assertSame(
            'Weekly on Mon',
            $this->labelFor($this->mondays(), ['mode' => ScheduleMonthMode::EVERY_MONTH->value]),
        );
    }

    /**
     * EVERY ENTRY OF EVERY CADENCE DICTIONARY EXISTS IN BOTH LANGUAGES.
     *
     * A missing entry does not throw — `__()` returns the key — so a gap ships as a raw
     * `calendar.cadence.weekdays.0` inside an otherwise perfect sentence, on the one weekday nobody
     * happened to use in a fixture. The bounds are read from the shared limits rather than written out,
     * so widening an axis makes this red instead of leaving a hole at the new end.
     */
    public function test_the_cadence_dictionaries_are_complete_in_every_language(): void
    {
        $dictionaries = [
            'calendar.cadence.weekdays' => range(ScheduleLimits::WEEKDAY_MIN, ScheduleLimits::WEEKDAY_MAX),
            'calendar.cadence.last_weekdays' => range(ScheduleLimits::WEEKDAY_MIN, ScheduleLimits::WEEKDAY_MAX),
            'calendar.cadence.ordinals' => range(ScheduleLimits::ORDINAL_MIN, ScheduleLimits::ORDINAL_MAX),
            'calendar.cadence.months' => range(ScheduleLimits::MONTH_MIN, ScheduleLimits::MONTH_MAX),
            'calendar.cadence.months_in_date' => range(ScheduleLimits::MONTH_MIN, ScheduleLimits::MONTH_MAX),
        ];

        foreach (['en', 'pl'] as $locale) {
            $this->app->setLocale($locale);

            foreach ($dictionaries as $dictionary => $entries) {
                $rendered = [];

                foreach ($entries as $entry) {
                    $key = $dictionary . '.' . $entry;
                    $value = __($key);

                    $this->assertIsString($value, "[{$locale}] {$key} is not a sentence");
                    $this->assertNotSame($key, $value, "[{$locale}] {$key} has no translation");

                    $rendered[] = $value;
                }

                // Two entries reading the same is a copy-paste, and it would render a rule about
                // Tuesday as a rule about Monday with nothing else going wrong.
                $this->assertCount(count($entries), array_unique($rendered), "[{$locale}] {$dictionary} repeats itself");
            }
        }
    }

    // ---- the caps ----------------------------------------------------------------------------

    /**
     * THE DENSEST SERIES THE CALENDAR CAN EXPRESS, OVER THE LARGEST WINDOW IT ACCEPTS, FITS.
     *
     * The numbers are the whole point. The Calendar's subset has no sub-daily cadence, so the most one
     * series can contribute is one square per day; the largest legal window is 62 days; the per-item
     * budget is 64. So a daily series over a maximal window draws 62 squares, is NOT a sample, and the
     * response reports no loss — the per-item cap has two days of headroom and cannot bite through the
     * API at all.
     */
    public function test_a_daily_series_fills_the_largest_window_without_being_flagged(): void
    {
        $this->weeklyFrom(['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value]], [
            'title' => 'Standup',
            'all_day' => false,
            'starts_at' => '2026-08-01T09:00:00Z',
        ]);

        $response = $this->read(['from' => '2026-08-01', 'to' => '2026-10-01'])->assertOk();

        $response->assertJsonCount(62, 'data');
        $response->assertJsonPath('meta.truncated', false);
        $response->assertJsonPath('meta.truncations', []);

        foreach ($response->json('data') as $occurrence) {
            $this->assertFalse($occurrence['dense']);
        }
    }

    /**
     * THE PER-ITEM CAP: what survives is a SAMPLE, every occurrence says so, and the result reports how
     * many items were sampled.
     *
     * NOT LIVE COVERAGE AT THE SHIPPED CONFIGURATION, and that has to be said out loud so nobody counts
     * it as such. The test above does the arithmetic: the Calendar's cadence subset has no sub-daily
     * mode, the largest legal window is 62 days and the per-item budget is 64, so through the API this
     * cap cannot bite at all. The line below LOWERS it to make the mechanism reachable — which means
     * this test pins a path that only a `CALENDAR_MAX_OCCURRENCES_PER_SOURCE_ITEM` below 62 ever
     * executes in production.
     *
     * That is worth keeping and worth labelling. A defect did hide behind exactly this gap: the fuse
     * that detects an overrun was being spent on a duplicate firing across a daylight-saving fall-back
     * day, so a capped series reported itself COMPLETE one day a year — invisible at the default, and
     * caught only by {@see test_the_fuse_is_not_spent_by_a_duplicate_firing_on_a_fall_back_day} below,
     * which lowers the cap for the same reason.
     */
    public function test_a_series_over_the_per_item_cap_is_sliced_flagged_and_reported(): void
    {
        config(['calendar.max_occurrences_per_source_item' => 5]);

        $this->weeklyFrom(['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value]], [
            'title' => 'Standup',
            'all_day' => false,
            'starts_at' => '2026-09-01T09:00:00Z',
        ]);

        $response = $this->read()->assertOk();

        $response->assertJsonCount(5, 'data');

        foreach ($response->json('data') as $occurrence) {
            $this->assertTrue($occurrence['dense'], 'a capped series marks every square it emits as a sample');
        }

        $this->assertSame(
            [['source' => 'event', 'kind' => 'item_densified', 'omitted_occurrences' => null, 'affected_items' => 1]],
            $response->json('meta.truncations'),
        );
    }

    /**
     * THE FUSE IS NOT SPENT BY A DUPLICATE FIRING — a capped series must report itself the same way on
     * a daylight-saving fall-back day as on any other.
     *
     * The source asks the projection for ONE MORE occurrence than it may render, so that an overrun is
     * DETECTABLE rather than looking like a series that happened to end there. That idiom breaks the
     * moment a projected instant does not become a square: the engine fires TWICE on a fall-back day
     * when the series' hour is the one the zone repeats, the read collapses the pair back to one square,
     * and the extra slot is eaten by the duplicate. The series then came back with NO `dense` flag and
     * an EMPTY truncation report — the exact "complete short series" this file's other cap tests exist
     * to make impossible, occurring one day a year.
     *
     * THE CONTROL IS THE POINT OF THE TEST. The same series over the same number of days a week earlier
     * — no transition inside it — is asserted to produce the identical answer. Without the control the
     * assertion would read as a fact about a fall-back day rather than as an equality between two
     * windows that must not differ.
     *
     * The cap is lowered because it cannot bite at the shipped configuration; see the note on
     * {@see test_a_series_over_the_per_item_cap_is_sliced_flagged_and_reported}.
     */
    public function test_the_fuse_is_not_spent_by_a_duplicate_firing_on_a_fall_back_day(): void
    {
        config(['calendar.max_occurrences_per_source_item' => 3]);

        $this->workspace->forceFill(['timezone' => 'Europe/Warsaw'])->save();
        app(TenantContext::class)->set($this->workspace->fresh());

        // 02:30 Warsaw is inside the hour 2026-10-25 repeats. Four days are available in each window,
        // so a per-item budget of three must be reported as a sample in BOTH. Each series is CLOSED at
        // its own window's end so the two cannot appear in each other's answer and inflate the report.
        $across = $this->dailyAt('2026-10-24T00:30:00Z', '2026-10-27');
        $ordinary = $this->dailyAt('2026-10-17T00:30:00Z', '2026-10-20');

        $withTransition = $this->read(['from' => '2026-10-24', 'to' => '2026-10-27'])->assertOk();
        $without = $this->read(['from' => '2026-10-17', 'to' => '2026-10-20'])->assertOk();

        foreach ([[$withTransition, $across], [$without, $ordinary]] as [$response, $event]) {
            $squares = $this->squaresOf($response->json('data'), $event->id);

            $this->assertCount(3, $squares);

            foreach ($squares as $square) {
                $this->assertTrue($square['dense'], 'a capped series marks every square it emits as a sample');
            }

            $this->assertSame(
                [['source' => 'event', 'kind' => 'item_densified', 'omitted_occurrences' => null, 'affected_items' => 1]],
                $response->json('meta.truncations'),
            );
        }
    }

    /**
     * THE RESPONSE CEILING BITING INSIDE A SERIES — the defect the sibling schedule source shipped and
     * had fixed, repeated here on purpose rather than rediscovered.
     *
     * The wrong behaviour is easy to write and impossible to see: emit until the ceiling, abandon the
     * rest of the series, leave the emitted squares saying `dense: false`, and report nothing. The
     * caller then gets a short series that every signal in the payload calls complete.
     *
     * Both halves are asserted: the slice is decided BEFORE anything is emitted (so every square
     * carries the flag), and a `window_trimmed` with an UNKNOWN count is filed alongside it.
     */
    public function test_the_response_ceiling_biting_inside_a_series_is_reported_rather_than_hidden(): void
    {
        config(['calendar.max_occurrences' => 3]);

        $this->weeklyFrom(['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value]], [
            'title' => 'Standup',
            'all_day' => false,
            'starts_at' => '2026-09-01T09:00:00Z',
        ]);

        $response = $this->read()->assertOk();

        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.truncated', true);

        foreach ($response->json('data') as $occurrence) {
            $this->assertTrue($occurrence['dense']);
        }

        $truncations = collect($response->json('meta.truncations'))->keyBy('kind');

        $this->assertSame(1, $truncations['item_densified']['affected_items']);
        $this->assertArrayHasKey('window_trimmed', $truncations->all());
        $this->assertNull(
            $truncations['window_trimmed']['omitted_occurrences'],
            'the loss inside a series is not countable, and a null is the honest answer',
        );
    }

    /**
     * AN EXACT FIGURE MUST NOT PASS FOR THE WHOLE LOSS. The query service trims the MERGED set and can
     * count precisely what it cut — but it can only count what reached it, and this source dropped the
     * rest of the series before that. Reports merge per (source, kind), and a known count plus an
     * unknown one is UNKNOWN; that merge is what stops the precise number from being read as the total.
     */
    public function test_an_exact_global_figure_cannot_pass_for_the_whole_loss(): void
    {
        config(['calendar.max_occurrences' => 4]);

        Task::factory()->create([
            'title' => 'Ship it',
            'deadline' => '2026-09-01',
            'creator_id' => $this->owner->id,
            'assigned_id' => $this->owner->id,
        ]);

        $this->weeklyFrom(['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value]], [
            'title' => 'Standup',
            'all_day' => false,
            'starts_at' => '2026-09-01T09:00:00Z',
        ]);

        // Both sources, so the merged set overflows and the global trim really does cut event squares.
        $response = $this->actingAsMember()
            ->getJson('/api/calendar/occurrences?' . http_build_query(['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk();

        $response->assertJsonCount(4, 'data');

        $trimmed = collect($response->json('meta.truncations'))
            ->firstWhere(fn (array $truncation): bool => $truncation['source'] === 'event' && $truncation['kind'] === 'window_trimmed');

        $this->assertNotNull($trimmed, 'the source has to say the ceiling bit inside its series');
        $this->assertNull($trimmed['omitted_occurrences'], 'the exact figure the trim knows is not the whole loss');
    }

    /**
     * A RESPONSE ALREADY FULL OF ONE-OFF EVENTS DROPS THE SERIES WHOLE, and says so as a dropped ITEM
     * rather than as a trimmed window.
     *
     * This is the branch that must never try to densify: with no budget left there is no occurrence to
     * carry a `dense` flag, so a slice-then-flag would report a sample of nothing. The series is absent
     * from the ENTIRE window, which is a different statement from "the far end was cut", and the
     * vocabulary has a word for it.
     */
    public function test_a_response_already_full_of_one_off_events_drops_a_series_whole(): void
    {
        config(['calendar.max_occurrences' => 2]);

        foreach (['2026-09-02', '2026-09-03'] as $day) {
            CalendarEvent::factory()->allDay($day)->create([
                'title' => 'one-off ' . $day,
                'creator_id' => $this->owner->id,
            ]);
        }

        $this->weekly();

        $response = $this->read()->assertOk();

        $this->assertSame(['2026-09-02', '2026-09-03'], $this->days($response->json('data')));

        $this->assertSame(
            [['source' => 'event', 'kind' => 'items_dropped', 'omitted_occurrences' => null, 'affected_items' => null]],
            $response->json('meta.truncations'),
        );
    }

    /**
     * THE ITEM BUDGET IS SPENT PER SHAPE, so a workspace full of all-day series cannot hide its timed
     * ones — the failure a combined `take()` over the concatenation would produce silently.
     *
     * There is no total order across a zone-free `start_date` and an instant `starts_at` (comparing
     * them means the conversion this module refuses), so a shared budget would order by nothing at all
     * and keep whichever half happened to be concatenated first. With a budget of ONE, both shapes
     * survive here, and neither half overran its own budget so nothing is reported.
     */
    public function test_the_item_budget_is_spent_per_shape_so_one_shape_cannot_hide_the_other(): void
    {
        config(['calendar.max_source_items' => 1]);

        $this->weeklyFrom(['day' => $this->mondays()], [
            'title' => 'all day',
            'all_day' => true,
            'start_date' => self::MONDAY,
        ]);

        $this->weeklyFrom(['day' => $this->mondays()], [
            'title' => 'timed',
            'all_day' => false,
            'starts_at' => self::MONDAY . 'T09:00:00Z',
        ]);

        $response = $this->read()->assertOk();

        $titles = array_values(array_unique(array_column($response->json('data'), 'title')));
        sort($titles);

        $this->assertSame(['all day', 'timed'], $titles);
        $response->assertJsonPath('meta.truncations', []);
    }

    /** THE ITEM BUDGET drops whole series from the whole window, which is a different loss and is named one. */
    public function test_exceeding_the_item_budget_drops_whole_series_and_says_so(): void
    {
        config(['calendar.max_source_items' => 1]);

        foreach (['first', 'second'] as $title) {
            $this->weeklyFrom(['day' => $this->mondays()], [
                'title' => $title,
                'all_day' => true,
                'start_date' => self::MONDAY,
            ]);
        }

        $response = $this->read()->assertOk();

        // One series survived, whole; the other is absent from the entire window.
        $this->assertCount(1, array_unique(array_column($response->json('data'), 'title')));

        $this->assertSame(
            [['source' => 'event', 'kind' => 'items_dropped', 'omitted_occurrences' => null, 'affected_items' => null]],
            $response->json('meta.truncations'),
        );
    }

    // ---- the cost ----------------------------------------------------------------------------

    /**
     * THE NUMBER OF QUERIES IS FIXED, whatever the workspace holds.
     *
     * Projecting inside the row loop — a `->fresh()`, a lazy relation, a per-series count — is the
     * natural way to write this file and costs nothing observable until somebody has thirty series and
     * a month grid that re-fetches on every navigation. Four is the whole budget for the events table:
     * once vs repeating, times a day vs a moment.
     *
     * The TOTAL is asserted too, not only the events-table share. `editable` is answered per row
     * through the policy, and a policy that grew a lookup — the creator, the workspace owner — would
     * be a per-row query against a DIFFERENT table, which an events-only counter cannot see.
     */
    public function test_the_number_of_queries_does_not_grow_with_the_number_of_series(): void
    {
        $this->makeSeries(1);
        $one = $this->countQueries();

        $this->makeSeries(29);
        $thirty = $this->countQueries();

        $this->assertSame(4, $one['events'], 'once/repeating times day/instant — and nothing per row');
        $this->assertSame($one, $thirty);
    }

    // ---- degradation -------------------------------------------------------------------------

    /**
     * A ROW WHOSE RULE THIS MODULE CAN NO LONGER READ is drawn as the one-off it now effectively is —
     * once, on its anchor — rather than vanishing.
     *
     * The read path already degrades an unreadable descriptor to "not a series" (a console fix, a
     * restored dump). Since a repeating row is excluded from the one-shot queries by construction,
     * doing nothing more would let one bad column erase an event that is otherwise perfectly intact.
     */
    public function test_a_row_whose_rule_cannot_be_read_is_drawn_as_the_one_off_it_now_is(): void
    {
        $event = $this->weekly();

        // A descriptor with no time axis: the value object refuses it, so the row stops being a series.
        DB::table('calendar_events')
            ->where('id', $event->id)
            ->update(['recurrence' => json_encode(['day' => $this->mondays(), 'tz' => 'UTC'])]);

        $response = $this->read()->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', 'event:' . $event->id);
        $response->assertJsonPath('data.0.cadence_label', null);

        // …and it is still bounded by the window, rather than drawn wherever its anchor happens to be.
        $this->read(['from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk()->assertJsonPath('data', []);
    }

    /** Search reaches a series by its own title, exactly as it reaches a one-off event. */
    public function test_search_reaches_a_series_by_its_own_title(): void
    {
        $this->weekly();

        $this->assertSame(self::SEPTEMBER_MONDAYS, $this->days($this->read(['q' => 'stand'])->assertOk()->json('data')));
        $this->read(['q' => 'retro'])->assertOk()->assertJsonPath('data', []);
    }

    // ---- fixtures ----------------------------------------------------------------------------

    private function actingAsMember(): self
    {
        parent::actingAs($this->owner)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    /**
     * One calendar read of the EVENT source alone, over September 2026 unless told otherwise.
     *
     * @param  array<string, mixed>  $query
     */
    private function read(array $query = []): TestResponse
    {
        return $this->actingAsMember()->getJson('/api/calendar/occurrences?' . http_build_query($query + [
            'from' => '2026-09-01',
            'to' => '2026-09-30',
            'sources' => ['event'],
        ]));
    }

    /** The day axis every weekly fixture shares. */
    private function mondays(): array
    {
        return ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]];
    }

    /**
     * A DAILY series with an explicit end, through the real write path — the fixture the cap tests that
     * care about a specific stretch of days need, because an open-ended one would also draw inside the
     * other test's window and inflate its truncation report.
     */
    private function dailyAt(string $startsAt, string $until): CalendarEvent
    {
        return $this->weeklyFrom(
            ['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value], 'until' => $until],
            ['all_day' => false, 'starts_at' => $startsAt],
        );
    }

    /**
     * The ALL-DAY twin of {@see weekly()}, through the same real write path.
     *
     * Its anchor is a zone-free DATE and its rule is stamped at the shared layer's day anchor, so it
     * reaches the projection by a different route than a timed series does — which is the reason it
     * needs its own fixture rather than a flag on the one above.
     */
    private function allDayWeekly(): CalendarEvent
    {
        return $this->weeklyFrom(['day' => $this->mondays()], [
            'title' => 'Standup',
            'all_day' => true,
            'start_date' => self::MONDAY,
        ]);
    }

    /**
     * A weekly-on-Mondays series anchored on {@see MONDAY}, built THROUGH THE REAL WRITE PATH.
     *
     * Never assembled by setting columns: a hand-built descriptor is one whose anchor nobody checked,
     * and a fixture whose anchor is not its own first occurrence is a row production cannot create — so
     * an assertion against it would keep passing after the invariant broke.
     *
     * @param  array<string, mixed>  $extra
     */
    private function weekly(array $extra = []): CalendarEvent
    {
        return $this->weeklyFrom(['day' => $this->mondays()] + $extra);
    }

    /**
     * An event with an arbitrary recurrence block and shape, through the real write path.
     *
     * @param  array<string, mixed>  $recurrence
     * @param  array<string, mixed>  $shape
     */
    private function weeklyFrom(array $recurrence, array $shape = []): CalendarEvent
    {
        $response = $this->actingAsMember()->postJson('/api/calendar/events', [
            'title' => 'Standup',
            ...($shape === [] ? ['all_day' => false, 'starts_at' => self::MONDAY . 'T09:00:00Z'] : $shape),
            'recurrence' => $recurrence,
        ])->assertCreated();

        return CalendarEvent::query()->findOrFail($response->json('data.id'));
    }

    /**
     * $count weekly series, built through the recurrence service and the factory rather than the HTTP
     * write path — thirty round-trips would measure the write path, and what this fixture is for is
     * measuring the READ.
     */
    private function makeSeries(int $count): void
    {
        $service = app(CalendarRecurrenceService::class);
        $anchor = CarbonImmutable::parse(self::MONDAY . ' 09:00:00', 'UTC');

        /** @var CalendarRecurrence $rule */
        $rule = $service->stamp($service->descriptor($this->mondays(), null, [], $anchor));

        for ($i = 0; $i < $count; $i++) {
            CalendarEvent::factory()
                ->timed(self::MONDAY . ' 09:00:00', self::MONDAY . ' 10:00:00')
                ->repeating($rule)
                ->create(['title' => 'Series ' . $i, 'creator_id' => $this->owner->id]);
        }
    }

    /**
     * What ONE read costs in queries: every query it issues, and the share of them that touches the
     * events table.
     *
     * @return array{total: int, events: int}
     */
    private function countQueries(): array
    {
        $total = 0;
        $events = 0;

        DB::listen(function ($query) use (&$total, &$events): void {
            $total++;

            if (str_contains($query->sql, 'calendar_events')) {
                $events++;
            }
        });

        $this->read()->assertOk();

        // Laravel has no public "stop listening", so the listener is dropped with the connection's
        // whole event stack — otherwise the next read in the same test would keep counting into a
        // closure that has already been read.
        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

        return ['total' => $total, 'events' => $events];
    }

    /**
     * One cadence sentence, straight from the renderer.
     *
     * @param  array<string, mixed>|null  $day
     * @param  array<string, mixed>|null  $month
     */
    private function labelFor(?array $day, ?array $month = null): ?string
    {
        $service = app(CalendarRecurrenceService::class);
        $anchor = CarbonImmutable::parse(self::MONDAY . ' 09:00:00', 'UTC');

        /** @var CalendarRecurrence $rule */
        $rule = $service->stamp($service->descriptor($day, $month, [], $anchor));

        return app(CalendarCadenceLabel::class)->for($rule);
    }

    /** One event read back through its own endpoint — the editor's payload, not the grid's. */
    private function show(CalendarEvent $event): TestResponse
    {
        return $this->actingAsMember()->getJson('/api/calendar/events/' . $event->id)->assertOk();
    }

    /**
     * The calendar days a response drew, in order — reading whichever of the two time fields the
     * occurrence's own discriminator says carries the answer.
     *
     * Deliberately NOT read off `occurrence_date`, which would make every projection assertion in this
     * file agree with itself by construction: that field is what the source CLAIMS the day is, and
     * these tests are about where the square actually landed.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @return array<int, string>
     */
    private function days(array $data): array
    {
        return array_map(
            fn (array $occurrence): string => $occurrence['all_day']
                ? $occurrence['start_date']
                : CarbonImmutable::parse($occurrence['starts_at'])->setTimezone($this->workspace->timezone ?? 'UTC')->format('Y-m-d'),
            $data,
        );
    }
}
