<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Workspaces\Models\Workspace;
use App\Support\Recurrence\Enums\ScheduleDayMode;
use App\Support\Recurrence\Enums\ScheduleDaySpecial;
use App\Support\Recurrence\Enums\ScheduleLimits;
use App\Support\Recurrence\Enums\ScheduleMonthMode;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * R3 B4 — THE WRITE SURFACE OF A REPEATING EVENT: the anchor, the stamp, the end, and the three scopes.
 *
 * Four themes run through this file, and each of them is a place where the wrong behaviour would be
 * invisible rather than loud:
 *
 *   THE ANCHOR. A series' start must be its own first occurrence. Without that, "start" quietly becomes
 *   "the day we begin counting from", the first square drawn is not the one the row says, and splitting
 *   a series at an occurrence stops being correct by construction.
 *
 *   THE STAMP. The timezone is written at save time and never re-derived. A re-derived zone would make a
 *   series behave differently from the single events it stands for when a workspace changes its clock —
 *   the series would keep its wall-clock hour while the events moved.
 *
 *   THE DEFAULT SCOPE. A `PUT` or `DELETE` that names no scope has to behave EXACTLY as it did before
 *   series existed. That is a compatibility guarantee, so it is pinned rather than described.
 *
 *   THE SCOPES THEMSELVES. Removing one occurrence is an exclusion; removing this-and-following is an
 *   end date; editing one occurrence is a detach; editing this-and-following is a split. Each is a
 *   composition of things the module already had, and each is asserted on the ROW, not on the response,
 *   because the response is the part that would be easy to fix without fixing the data.
 */
class CalendarEventRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-09-07 is a Monday — the anchor for nearly every weekly fixture below. */
    private const MONDAY = '2026-09-07';

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Named, never inherited: half this file's subject is which clock a rule is stamped on.
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

    // ---- the default scope is today's contract ---------------------------------

    /**
     * AN EVENT THAT SAYS NOTHING ABOUT REPEATING DOES NOT REPEAT — the state every row written before
     * this batch is in, and the state every payload written before it keeps producing.
     */
    public function test_an_event_that_names_no_rule_does_not_repeat(): void
    {
        $response = $this->actingAsOwner()
            ->postJson('/api/calendar/events', [
                'title' => 'Kickoff call',
                'all_day' => false,
                'starts_at' => '2026-09-07T09:00:00Z',
            ])
            ->assertCreated()
            ->assertJsonPath('data.recurrence', null)
            ->assertJsonPath('data.recurrence_timezone', null);

        $event = CalendarEvent::query()->findOrFail($response->json('data.id'));

        $this->assertNull($event->recurrence);
        $this->assertNull($event->recurrence_until);
        $this->assertFalse($event->repeats());
    }

    /**
     * THE COMPATIBILITY GUARANTEE, executed: an update and a delete that name no scope behave exactly
     * as they did before series existed — a whole-event write and a whole-row soft delete.
     */
    public function test_a_write_without_a_scope_behaves_as_it_always_did(): void
    {
        $event = CalendarEvent::factory()->timed('2026-09-07 09:00:00', '2026-09-07 10:00:00')
            ->create(['creator_id' => $this->owner->id]);

        $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Renamed',
                'all_day' => false,
                'starts_at' => '2026-09-07T11:00:00Z',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.title', 'Renamed')
            ->assertJsonPath('data.recurrence', null);

        $this->actingAsOwner()
            ->deleteJson('/api/calendar/events/' . $event->id)
            ->assertNoContent();

        $this->assertSoftDeleted('calendar_events', ['id' => $event->id]);
    }

    /**
     * THE CONSEQUENCE OF A WHOLE-EVENT WRITE, pinned so it is not discovered as a bug: an update that
     * omits `recurrence` REMOVES the rule, exactly as one that omits `description` clears it.
     *
     * The resource emits the block in the shape the request accepts precisely so a client that edits one
     * field and PUTs the whole event back never has to know this.
     */
    public function test_a_whole_event_update_that_omits_the_rule_removes_it(): void
    {
        $event = $this->weekly();

        $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'No longer weekly',
                'all_day' => false,
                'starts_at' => self::MONDAY . 'T09:00:00Z',
            ])
            ->assertOk()
            ->assertJsonPath('data.recurrence', null);

        $this->assertFalse($event->fresh()->repeats());
    }

    /** The rule survives a round trip through the resource without the client understanding it. */
    public function test_the_rule_round_trips_through_the_resource(): void
    {
        $event = $this->weekly(['exclusions' => ['dates' => ['2026-09-14']], 'until' => '2026-12-31']);

        $read = $this->actingAsOwner()->getJson('/api/calendar/events/' . $event->id)->assertOk();

        $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Standup, renamed',
                'all_day' => false,
                'starts_at' => self::MONDAY . 'T09:00:00Z',
                'recurrence' => $read->json('data.recurrence'),
            ])
            ->assertOk();

        $fresh = $event->fresh();

        $this->assertSame(['2026-09-14'], $fresh->recurrence['exclusions']['dates']);
        $this->assertSame('2026-12-31', $fresh->recurrenceUntilString());
    }

    // ---- the anchor -------------------------------------------------------------

    /**
     * THE ANCHOR MUST SATISFY THE RULE. A Monday-anchored event with a fires-on-Tuesdays rule is a 422
     * on the START, not a series whose first square is a week after the day the row names.
     */
    public function test_a_start_that_is_not_an_occurrence_is_refused(): void
    {
        $this->actingAsOwner()
            ->postJson('/api/calendar/events', [
                'title' => 'Standup',
                'all_day' => false,
                // A Monday, with a rule that only fires on Tuesdays and Thursdays.
                'starts_at' => self::MONDAY . 'T09:00:00Z',
                'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [2, 4]]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    /** The same rule for an all-day series, reported on the field that actually carries its anchor. */
    public function test_an_all_day_start_that_is_not_an_occurrence_is_refused(): void
    {
        $this->actingAsOwner()
            ->postJson('/api/calendar/events', [
                'title' => 'Launch',
                'all_day' => true,
                'start_date' => self::MONDAY,
                'recurrence' => ['day' => [
                    'mode' => ScheduleDayMode::SPECIAL->value,
                    'special' => ScheduleDaySpecial::LAST_DAY->value,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_date');
    }

    /**
     * The projection grid is minute-granular, so an anchor carrying seconds could never be one of its
     * own occurrences. Refused with a sentence about the START rather than silently rounded — rounding
     * would move an instant the caller stated.
     */
    public function test_a_repeating_event_must_start_on_a_whole_minute(): void
    {
        $this->actingAsOwner()
            ->postJson('/api/calendar/events', [
                'title' => 'Standup',
                'all_day' => false,
                'starts_at' => self::MONDAY . 'T09:00:30Z',
                'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    /**
     * THE ANCHOR CHECK ASKS ABOUT THE CADENCE, AND THE EXCLUSIONS ARE STRIPPED BEFORE IT DOES.
     *
     * This is the half of the rule that is easy to lose, because losing it makes the check STRICTER and
     * a stricter validation reads like a safer one. Removing one occurrence is an exclusion, so a user
     * who deletes the FIRST occurrence of a series leaves a row whose own anchor day its own descriptor
     * excludes. If the anchor check honoured exclusions, that row would become UNEDITABLE: renaming it
     * would 422 with "the start is not an occurrence", about a start the user never touched and cannot
     * reach a control for.
     *
     * The two layers are kept apart everywhere else in this module — the CADENCE says which days a
     * series falls on and the EXCLUSIONS subtract from that afterwards — and this is the one place the
     * separation is observable from outside.
     */
    public function test_a_series_whose_first_occurrence_was_removed_can_still_be_edited(): void
    {
        $event = $this->weekly();

        // The user removes the series' own first occurrence, one at a time, from the grid.
        $this->deleteScoped($event, 'occurrence', self::MONDAY)->assertNoContent();
        $this->assertSame([self::MONDAY], $event->fresh()->recurrence['exclusions']['dates']);

        // …and then renames the series. The anchor is untouched and the rule still fires on it; only
        // the SUBTRACTION says otherwise, and a subtraction is not what "start" has to satisfy.
        $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Standup, renamed',
                'all_day' => false,
                'starts_at' => self::MONDAY . 'T09:00:00Z',
                'scope' => 'series',
                'recurrence' => [
                    'day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]],
                    // Carried, never authored — a whole-event write that dropped it would resurrect
                    // the day the user removed.
                    'exclusions' => ['dates' => [self::MONDAY]],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Standup, renamed');

        $this->assertSame([self::MONDAY], $event->fresh()->recurrence['exclusions']['dates']);
    }

    // ---- the stamp --------------------------------------------------------------

    /**
     * THE RULE IS STAMPED WITH THE WORKSPACE'S CLOCK, and the hour it carries is the event's own hour
     * READ ON THAT CLOCK — 09:00 UTC on a Warsaw workspace is an 11:00 series, because 11:00 is what the
     * grid will show.
     */
    public function test_the_rule_is_stamped_with_the_workspace_clock(): void
    {
        $this->useTimezone('Europe/Warsaw');

        $event = $this->weekly();

        $this->assertSame('Europe/Warsaw', $event->recurrence['tz']);
        $this->assertSame(['11:00'], $event->recurrence['time']['at']);
    }

    /**
     * A STAMPED ZONE DOES NOT MOVE WHEN THE WORKSPACE'S DOES — the whole reason for stamping.
     *
     * A single timed event stores an absolute instant, so changing the workspace timezone already moves
     * its wall-clock hour on the grid. Stamping gives a series identical behaviour. Re-deriving would
     * instead pin the wall-clock hour and move the instants, so a series and the single events it
     * stands for would drift apart on a change connected to neither.
     */
    public function test_a_stamped_rule_does_not_follow_a_later_timezone_change(): void
    {
        $this->useTimezone('Europe/Warsaw');

        $event = $this->weekly();

        $this->useTimezone('America/New_York');

        $fresh = $event->fresh();

        $this->assertSame('Europe/Warsaw', $fresh->recurrence['tz'], 'the stamp moved with the workspace');
        $this->assertSame(['11:00'], $fresh->recurrence['time']['at']);
        $this->assertSame('Europe/Warsaw', $this->show($fresh)->json('data.recurrence_timezone'));
    }

    /**
     * AND IT DOES NOT MOVE ON A SAVE EITHER — the half the test above did not cover, and the one that
     * turned a stamped series into an UNEDITABLE one.
     *
     * The stamp ruled how a series was READ; the workspace's CURRENT zone ruled how it was RE-VALIDATED
     * on write, and nothing reconciled the two. So after a workspace moved west, saving re-stamped the
     * new zone and then checked the anchor on it — and a series anchored early on a Monday is anchored
     * on a SUNDAY six hours west. Editing only the TITLE, echoing the rule back verbatim exactly as the
     * drawer does, came back 422 on `starts_at`: a field the user had not touched, about a rule they
     * had not written, on a change nobody connected to either.
     *
     * IT WAS A LOCK-OUT, NOT AN INCONVENIENCE, which is why this is pinned rather than described. The
     * two ways out both destroy data: moving the start forward erases the series' past, and choosing
     * another preset silently rewrites the cadence. The client cannot rescue it either — it stops
     * recognising its own rule and sends it back unchanged.
     *
     * THE RULE THAT FIXES IT: a series is re-stamped only when its ANCHOR MOVES. The stamp records the
     * clock a series was laid out on, and changing it as a side effect of an unrelated edit is exactly
     * the surprise. Asserted on the ROW, because a 200 with a quietly re-stamped column would be the
     * same defect wearing the fix's clothes.
     */
    public function test_a_title_only_save_after_a_timezone_change_keeps_the_series_clock(): void
    {
        $this->useTimezone('Europe/Warsaw');

        // 02:00 Warsaw on a Monday. Six hours west that instant is a SUNDAY, so the weekly-Mondays rule
        // stops describing it the moment the workspace's clock is the one asking.
        $event = $this->weeklyFrom(
            ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]],
            ['all_day' => false, 'starts_at' => self::MONDAY . 'T00:00:00Z'],
        );

        $this->useTimezone('America/New_York');

        // Exactly what the drawer composes: everything read back, with one field changed.
        $read = $this->show($event);

        $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Standup, renamed',
                'all_day' => false,
                'starts_at' => $read->json('data.starts_at'),
                'recurrence' => $read->json('data.recurrence'),
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Standup, renamed')
            ->assertJsonPath('data.recurrence_timezone', 'Europe/Warsaw');

        $fresh = $event->fresh();

        $this->assertSame('Europe/Warsaw', $fresh->recurrence['tz'], 'an unrelated edit re-stamped the clock');
        $this->assertSame(['02:00'], $fresh->recurrence['time']['at'], 'the hour followed the clock it should not have');
    }

    /**
     * THE OTHER HALF OF THE SAME RULE: a save that MOVES the anchor stamps the workspace's current
     * clock, because that is the moment the author is laying the series out again.
     *
     * Without this, "never re-stamp" would be the fix, and it would freeze a series on a zone the
     * workspace abandoned years ago — a rule whose hour nobody in the workspace can read.
     */
    public function test_a_save_that_moves_the_anchor_stamps_the_workspaces_current_clock(): void
    {
        $this->useTimezone('Europe/Warsaw');

        $event = $this->weeklyFrom(
            ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]],
            ['all_day' => false, 'starts_at' => self::MONDAY . 'T00:00:00Z'],
        );

        $this->useTimezone('America/New_York');

        // A NEW start: 09:00 on a Monday in New York, which the rule describes on the new clock.
        $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Standup, moved',
                'all_day' => false,
                'starts_at' => '2026-09-14T13:00:00Z',
                'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]],
            ])
            ->assertOk()
            ->assertJsonPath('data.recurrence_timezone', 'America/New_York');

        $fresh = $event->fresh();

        $this->assertSame('America/New_York', $fresh->recurrence['tz']);
        $this->assertSame(['09:00'], $fresh->recurrence['time']['at']);
    }

    /**
     * THE SPLIT IS A CUT, NOT A RE-AUTHORING — the same lock-out, one door further along.
     *
     * A `following` split starts a NEW row, so "the anchor did not move" has to be read against the
     * right subject: the occurrence the split is made at, which is a point the existing series already
     * fires on. Judged against the row's own start instead, every split after a timezone change would
     * re-stamp and then fail the anchor check for exactly the reason above.
     *
     * Both rows are asserted, because the outgoing half is where the clock actually matters: its end
     * date is computed on the stamped clock, and a split that closed it on the wrong day would leave
     * either a gap or an overlap that nothing else reports.
     */
    public function test_a_following_split_after_a_timezone_change_keeps_the_series_clock(): void
    {
        $this->useTimezone('Europe/Warsaw');

        $event = $this->weeklyFrom(
            ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]],
            ['all_day' => false, 'starts_at' => self::MONDAY . 'T00:00:00Z'],
        );

        $this->useTimezone('America/New_York');

        $response = $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Standup, from here on',
                'all_day' => false,
                'starts_at' => '2026-09-21T00:00:00Z',
                'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]],
                'scope' => 'following',
                'occurrence_date' => '2026-09-21',
            ])
            ->assertCreated()
            ->assertJsonPath('data.recurrence_timezone', 'Europe/Warsaw');

        $this->assertNotSame($event->id, $response->json('data.id'), 'a split that leaves a past behind mints a new row');

        // The outgoing half closed the day before the split, on its own clock.
        $this->assertSame('2026-09-20', $event->fresh()->recurrenceUntilString());
        $this->assertSame('Europe/Warsaw', $event->fresh()->recurrence['tz']);
    }

    /**
     * AN ALL-DAY SERIES CARRIES ITS CLOCK THE SAME WAY, and it is the case where the old behaviour was
     * SILENT rather than loud: a zone-free day is a day on any clock, so the anchor check passed and
     * the re-stamp went through with a 200. A workspace that had moved sixteen hours east came back
     * with its whole series' rule reading on a clock it was never written on.
     */
    public function test_an_all_day_series_keeps_its_clock_through_an_unrelated_edit(): void
    {
        $this->useTimezone('Europe/Warsaw');

        $event = $this->allDayWeekly();

        $this->useTimezone('Pacific/Kiritimati');

        $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Sprint day, renamed',
                'all_day' => true,
                'start_date' => self::MONDAY,
                'recurrence' => $this->show($event)->json('data.recurrence'),
            ])
            ->assertOk()
            ->assertJsonPath('data.recurrence_timezone', 'Europe/Warsaw');

        $this->assertSame('Europe/Warsaw', $event->fresh()->recurrence['tz']);
    }

    // ---- the end of the series --------------------------------------------------

    /** "Repeat N times" is resolved to a DAY once, at write time. Eight Mondays from the 7th is the 26th of October. */
    public function test_a_count_becomes_an_end_day(): void
    {
        $event = $this->weekly(['count' => 8]);

        $this->assertSame('2026-10-26', $event->recurrenceUntilString());
        // The count itself is NOT kept: "the series runs until day D" is the rule the data expresses,
        // and it is the one that stays true after somebody deletes an occurrence.
        $this->assertArrayNotHasKey('count', $event->recurrence);
    }

    /**
     * "AFTER N TIMES", END TO END — the exact body the repeat control puts on the wire, and the exact
     * shape it reads back afterwards.
     *
     * The two halves of this were each pinned on their own side and NEITHER pinned the agreement:
     * `recurrenceStateToWire` has a spec proving it emits `count` alone, and
     * {@see test_a_count_becomes_an_end_day} proves a count becomes a day. Between them sits the thing
     * that actually breaks — whether the body one produces is the body the other accepts, and whether
     * the answer is one `recurrenceStateFrom` can seed a control from. A mismatch there is a Save that
     * 422s on a field the user cannot see, or a reopened drawer showing "does not repeat".
     *
     * THREE PARTS OF THIS BODY ARE THE CLIENT'S AND NOT THIS SUITE'S HABITS, so they are spelled out
     * rather than reached through the `submit()` helper:
     *   `month: null`  the control emits both axes always; a rule with no month axis sends an explicit
     *                  null rather than omitting the key.
     *   no `until`     `until` and `count` are mutually exclusive on the wire (422 `end_is_one_thing`),
     *                  so the count branch omits the other key entirely rather than sending null.
     *   `all_day`      false with a bare wall clock carrying an explicit offset, as the drawer composes.
     *
     * The RESPONSE is asserted as a whole recurrence block, because what the control seeds from is the
     * block — `day`, `month`, `exclusions` and `until`, with no `count` anywhere to read back.
     */
    public function test_the_repeat_controls_count_body_round_trips_as_the_resolved_date(): void
    {
        $response = $this->actingAsOwner()
            ->postJson('/api/calendar/events', [
                'title' => 'Standup',
                'description' => null,
                'all_day' => false,
                'starts_at' => self::MONDAY . 'T09:00:00+00:00',
                'recurrence' => [
                    'day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]],
                    'month' => null,
                    'count' => 10,
                ],
            ])
            ->assertCreated();

        // Ten Mondays from the 7th of September is the 9th of November.
        $response->assertJsonPath('data.recurrence', [
            'day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]],
            'month' => null,
            'exclusions' => null,
            'until' => '2026-11-09',
        ]);

        // The block a client seeds an END CONTROL from has one end and it is a DATE. A `count` echoed
        // back anywhere would give the control two ends to choose between, and it keeps no counter.
        $this->assertStringNotContainsString('"count"', (string) $response->json('data.recurrence.until'));
        $this->assertArrayNotHasKey('count', (array) $response->json('data.recurrence'));

        // …and it reads the same on the way back in, which is the request a reopened drawer makes.
        $event = CalendarEvent::query()->findOrFail($response->json('data.id'));

        $this->show($event)->assertJsonPath('data.recurrence.until', '2026-11-09');
        $this->assertArrayNotHasKey('count', (array) $this->show($event)->json('data.recurrence'));

        // The resolved DATE is what a second save carries — the count is gone from the row, so nothing
        // can re-resolve a stale counter against a cadence somebody has since changed.
        $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Standup, renamed',
                'all_day' => false,
                'starts_at' => self::MONDAY . 'T09:00:00+00:00',
                'scope' => 'series',
                'recurrence' => [
                    'day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]],
                    'month' => null,
                    'until' => '2026-11-09',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.recurrence.until', '2026-11-09');
    }

    /**
     * A cadence can be anchored on a real occurrence and still have its next one decades away — the
     * fifth Monday of February happens in 2016 and then not until 2044, well past the engine's horizon.
     * A count it cannot reach is refused rather than silently stored short.
     */
    public function test_a_count_the_cadence_cannot_reach_is_refused(): void
    {
        $this->actingAsOwner()
            ->postJson('/api/calendar/events', [
                'title' => 'The fifth Monday of February',
                'all_day' => false,
                'starts_at' => '2016-02-29T09:00:00Z',
                'recurrence' => [
                    'day' => [
                        'mode' => ScheduleDayMode::SPECIAL->value,
                        'special' => ScheduleDaySpecial::NTH_WEEKDAY->value,
                        'ordinal' => 5,
                        'weekday' => 1,
                    ],
                    'month' => ['mode' => ScheduleMonthMode::MONTHS->value, 'months' => [2]],
                    'count' => 2,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recurrence.count');
    }

    /** An end and a count are two answers to one question; picking one silently would be picking for the caller. */
    public function test_an_end_and_a_count_together_are_refused(): void
    {
        $this->submit(['until' => '2026-12-31', 'count' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recurrence.count');
    }

    /** A series that ends before it begins has no occurrences at all, and says so on the end. */
    public function test_an_end_before_the_start_is_refused(): void
    {
        $this->submit(['until' => '2026-08-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recurrence.until');
    }

    /**
     * A SERIES THAT FALLS ON NO DAY AT ALL IS REFUSED — the check the anchor check is not.
     *
     * The anchor check strips exclusions, so weekly-on-Mondays anchored on the 7th, excluding the 7th
     * and ending on the 10th satisfies it perfectly and lands on nothing. Before this was closed the
     * payload answered 201 and the event would simply never appear once the grid projects series.
     */
    public function test_a_series_that_falls_on_no_day_is_refused(): void
    {
        $this->submit([
            'exclusions' => ['dates' => [self::MONDAY]],
            'until' => '2026-09-10',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recurrence.until');
    }

    /** The same emptiness, with no end date: reported on the key the caller can actually relax. */
    public function test_an_open_ended_series_that_can_never_fall_on_a_day_is_refused(): void
    {
        // The fifth Monday of February, with the one occurrence inside the engine's reach excluded.
        $this->actingAsOwner()
            ->postJson('/api/calendar/events', [
                'title' => 'Never',
                'all_day' => false,
                'starts_at' => '2016-02-29T09:00:00Z',
                'recurrence' => [
                    'day' => [
                        'mode' => ScheduleDayMode::SPECIAL->value,
                        'special' => ScheduleDaySpecial::NTH_WEEKDAY->value,
                        'ordinal' => 5,
                        'weekday' => 1,
                    ],
                    'month' => ['mode' => ScheduleMonthMode::MONTHS->value, 'months' => [2]],
                    'exclusions' => ['dates' => ['2016-02-29']],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recurrence.exclusions.dates');
    }

    /**
     * A KEY NOBODY READS IS REFUSED, NOT DROPPED. The block is rebuilt rather than filtered, so a key
     * the module does not author reaches nothing — an RFC 5545 payload was accepted and stored as
     * "every day, forever", and a singular `date` typo silently kept drawing the occurrence the caller
     * meant to remove. Both answered 201.
     */
    public function test_a_key_the_rule_does_not_have_is_refused(): void
    {
        $this->actingAsOwner()
            ->postJson('/api/calendar/events', [
                'title' => 'Standup',
                'all_day' => false,
                'starts_at' => self::MONDAY . 'T09:00:00Z',
                'recurrence' => ['freq' => 'WEEKLY', 'interval' => 2],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recurrence.freq', 'recurrence.interval']);

        $this->submit(['exclusions' => ['date' => ['2026-09-14']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recurrence.exclusions.date');
    }

    /**
     * The stored rule holds INTEGERS whatever the wire spelled, so a client comparing the round-tripped
     * block with `===` renders the days it sent. Laravel's `integer` rule accepts `"1"` as readily as
     * `1`, and the engine coerces either — so this drifts silently and only the UI notices.
     */
    public function test_a_numeric_string_is_stored_as_an_integer(): void
    {
        $response = $this->actingAsOwner()
            ->postJson('/api/calendar/events', [
                'title' => 'Standup',
                'all_day' => false,
                'starts_at' => '2026-09-08T09:00:00Z',
                'recurrence' => ['day' => [
                    'mode' => ScheduleDayMode::SPECIAL->value,
                    'special' => ScheduleDaySpecial::NTH_WEEKDAY->value,
                    'ordinal' => '2',
                    'weekday' => '2',
                ]],
            ])
            ->assertCreated();

        $event = CalendarEvent::query()->findOrFail($response->json('data.id'));

        $this->assertSame(2, $event->recurrence['day']['ordinal']);
        $this->assertSame(2, $event->recurrence['day']['weekday']);

        $weekly = $this->weeklyFrom(['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => ['1', '3']]]);
        $this->assertSame([1, 3], $weekly->recurrence['day']['weekdays']);
    }

    // ---- scope: one occurrence --------------------------------------------------

    /** Removing one occurrence is an EXCLUSION. The row survives; the series simply stops falling on that day. */
    public function test_deleting_one_occurrence_excludes_its_day(): void
    {
        $event = $this->weekly();

        $this->actingAsOwner()
            ->deleteJson('/api/calendar/events/' . $event->id, [
                'scope' => 'occurrence',
                'occurrence_date' => '2026-09-14',
            ])
            ->assertNoContent();

        $fresh = $event->fresh();

        $this->assertNotSoftDeleted('calendar_events', ['id' => $event->id]);
        $this->assertSame(['2026-09-14'], $fresh->recurrence['exclusions']['dates']);
        $this->assertNull($fresh->recurrence_until, 'excluding a day must not end the series');
    }

    /**
     * A day the series does not fall on is not an occurrence — including one that has already been
     * excluded. The second delete is a 422, not a duplicate entry, because the engine's own day
     * projection honours exclusions.
     */
    public function test_a_day_that_is_not_an_occurrence_is_refused(): void
    {
        $event = $this->weekly();

        // A Tuesday.
        $this->deleteScoped($event, 'occurrence', '2026-09-15')
            ->assertStatus(422)
            ->assertJsonValidationErrors('occurrence_date');

        $this->deleteScoped($event, 'occurrence', '2026-09-14')->assertNoContent();

        $this->deleteScoped($event->fresh(), 'occurrence', '2026-09-14')
            ->assertStatus(422)
            ->assertJsonValidationErrors('occurrence_date');
    }

    /**
     * Editing one occurrence DETACHES it: excluded from the rule, and re-created as an ordinary
     * non-repeating event. The response is the NEW row, which is the thing the user is now looking at.
     */
    public function test_editing_one_occurrence_detaches_it(): void
    {
        $event = $this->weekly();

        $response = $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Standup, moved',
                'all_day' => false,
                'starts_at' => '2026-09-14T15:00:00Z',
                'scope' => 'occurrence',
                'occurrence_date' => '2026-09-14',
            ])
            // 201, not 200: the response names a resource that did not exist before, and the status is
            // how a client learns that the id it was holding is no longer the one to edit.
            ->assertCreated()
            ->assertJsonPath('data.recurrence', null);

        $this->assertNotSame($event->id, $response->json('data.id'), 'a detached occurrence is a NEW row');

        $series = $event->fresh();
        $this->assertSame(['2026-09-14'], $series->recurrence['exclusions']['dates']);
        $this->assertSame('Standup', $series->title, 'the series itself must be untouched by an occurrence edit');

        $detached = CalendarEvent::query()->findOrFail($response->json('data.id'));
        $this->assertFalse($detached->repeats());
        $this->assertSame('Standup, moved', $detached->title);
    }

    /** One occurrence of a series is not itself a series, so a payload for one may not carry a rule. */
    public function test_editing_one_occurrence_refuses_a_rule_of_its_own(): void
    {
        $event = $this->weekly();

        $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Standup, moved',
                'all_day' => false,
                'starts_at' => '2026-09-14T15:00:00Z',
                'scope' => 'occurrence',
                'occurrence_date' => '2026-09-14',
                'recurrence' => ['day' => ['mode' => ScheduleDayMode::EVERY_DAY->value]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recurrence');
    }

    /**
     * The exclusion list has a CAP, and the message says what to do about it: a series with fifty holes
     * in it is two series, so the remedy named is a split rather than a bigger list.
     */
    public function test_the_exclusion_list_is_capped_and_the_message_says_to_split(): void
    {
        $dates = [];
        $day = new \DateTimeImmutable(self::MONDAY);

        for ($i = 1; $i <= ScheduleLimits::EXCLUSIONS_DATES_MAX; $i++) {
            $dates[] = $day->modify('+' . (7 * $i) . ' days')->format('Y-m-d');
        }

        $event = $this->weekly(['exclusions' => ['dates' => $dates]]);

        $this->assertCount(ScheduleLimits::EXCLUSIONS_DATES_MAX, $event->recurrence['exclusions']['dates']);

        // The next Monday after the last excluded one is still a real occurrence — it is the LIST that
        // is full, which is exactly what the error has to say.
        $next = $day->modify('+' . (7 * (ScheduleLimits::EXCLUSIONS_DATES_MAX + 1)) . ' days')->format('Y-m-d');

        $this->deleteScoped($event, 'occurrence', $next)
            ->assertStatus(422)
            ->assertJsonValidationErrors('occurrence_date');
    }

    // ---- scope: this and following ---------------------------------------------

    /** Removing this-and-following closes the series the day BEFORE the named occurrence. */
    public function test_deleting_this_and_following_closes_the_series(): void
    {
        $event = $this->weekly();

        $this->deleteScoped($event, 'following', '2026-09-21')->assertNoContent();

        $fresh = $event->fresh();

        $this->assertNotSoftDeleted('calendar_events', ['id' => $event->id]);
        $this->assertSame('2026-09-20', $fresh->recurrenceUntilString());
    }

    /**
     * At the series' FIRST occurrence, "this and all following" is the whole series — so the row is
     * soft-deleted rather than closed to an empty span that draws nothing and can never be reached.
     */
    public function test_deleting_this_and_following_at_the_first_occurrence_deletes_the_event(): void
    {
        $event = $this->weekly();

        $this->deleteScoped($event, 'following', self::MONDAY)->assertNoContent();

        $this->assertSoftDeleted('calendar_events', ['id' => $event->id]);
    }

    /**
     * THE SPLIT. The old series is closed the day before and a NEW event carries the payload forward —
     * so last year's Mondays stay Mondays instead of being rewritten into Wednesdays by an edit nobody
     * would describe that way.
     */
    public function test_editing_this_and_following_splits_the_series(): void
    {
        $event = $this->weekly();

        $response = $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Standup, now on Wednesdays',
                'all_day' => false,
                'starts_at' => '2026-09-23T09:00:00Z',
                'scope' => 'following',
                'occurrence_date' => '2026-09-21',
                'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [3]]],
            ])
            ->assertCreated();

        $this->assertNotSame($event->id, $response->json('data.id'), 'a split produces a NEW row');

        $old = $event->fresh();
        $this->assertSame('2026-09-20', $old->recurrenceUntilString(), 'the outgoing series must be closed the day before');
        $this->assertSame('Standup', $old->title, 'the past must not be rewritten');
        $this->assertSame([1], $old->recurrence['day']['weekdays']);

        $new = CalendarEvent::query()->findOrFail($response->json('data.id'));
        $this->assertSame([3], $new->recurrence['day']['weekdays']);
        $this->assertNull($new->recurrence_until);
    }

    /**
     * A split at the FIRST occurrence has no past to preserve, so it is an in-place whole-event edit —
     * and the id that comes back is the id in the URL. This is the one case where the resource this
     * endpoint returns is the resource that was addressed.
     */
    public function test_editing_this_and_following_at_the_first_occurrence_edits_in_place(): void
    {
        $event = $this->weekly();

        $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Standup, now on Wednesdays',
                'all_day' => false,
                'starts_at' => '2026-09-09T09:00:00Z',
                'scope' => 'following',
                'occurrence_date' => self::MONDAY,
                'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [3]]],
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $event->id);

        $this->assertSame(1, CalendarEvent::query()->count(), 'no second row may be created when there is no past to keep');
        $this->assertSame([3], $event->fresh()->recurrence['day']['weekdays']);
    }

    /** A split may not reach back behind itself: the two series would draw the same days. */
    public function test_a_split_that_starts_before_the_split_point_is_refused(): void
    {
        $event = $this->weekly();

        $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Standup, earlier',
                'all_day' => false,
                'starts_at' => '2026-09-14T09:00:00Z',
                'scope' => 'following',
                'occurrence_date' => '2026-09-21',
                'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    /**
     * THE TRANSACTION, PROVEN RATHER THAN CLAIMED.
     *
     * A detach is two writes that mean one thing: the day is excluded from the rule, and the edited
     * occurrence is re-created as its own event. If the second fails and the first stands, the user's
     * edit is gone and their occurrence is gone with it — a DELETE performed by a request that asked
     * for an EDIT. The insert is made to fail from a model event, which is the only failure a test can
     * inject without reaching past the service.
     *
     * TWO THINGS ARE ASSERTED AND THE FIRST USED TO BE A DEAD LETTER. This test used to call
     * `$this->fail()` INSIDE a `try` whose `catch (\RuntimeException)` swallowed it — PHPUnit's
     * `AssertionFailedError` extends `PHPUnit\Framework\Exception`, which extends `RuntimeException` —
     * so the guard could never fire. And it was reached on every run: the injected throw does NOT
     * propagate out of a `putJson()`, the framework renders it as a server error, so the `fail()` line
     * executed and was eaten every single time. A guard that cannot light is worse than no guard,
     * because the next reader counts it as coverage.
     *
     * {@see withFailingInsert()} decides the same question OUTSIDE the catch, and accepts BOTH real
     * shapes the failure can take (a propagated throwable, or a 5xx response). Without that assertion
     * the rollback check below is vacuous whenever the injection stops landing: a request that answered
     * `201` never wrote an exclusion either.
     */
    public function test_a_failed_detach_leaves_the_series_untouched(): void
    {
        $event = $this->weekly();

        $reachedTheCaller = !$this->withFailingInsert(fn (): TestResponse => $this->actingAsOwner()
            ->putJson('/api/calendar/events/' . $event->id, [
                'title' => 'Standup, moved',
                'all_day' => false,
                'starts_at' => '2026-09-14T15:00:00Z',
                'scope' => 'occurrence',
                'occurrence_date' => '2026-09-14',
            ]));

        $this->assertTrue(
            $reachedTheCaller,
            'the detach answered success although its second half was made to fail — the injection did '
            . 'not land, so the rollback assertion below would prove nothing',
        );

        $this->assertNull(
            $event->fresh()->recurrence['exclusions'] ?? null,
            'the exclusion survived a detach whose second half failed — the occurrence was deleted instead of edited'
        );
    }

    // ---- scoped writes on an ALL-DAY series -------------------------------------

    /**
     * The three scopes on an ALL-DAY series. Timed series had every one of these covered and all-day
     * ones had none, which is precisely where a defect could sit unseen: an all-day event's anchor is a
     * zone-free DATE, and everything about occurrence identity here is a date.
     */
    public function test_the_scopes_work_on_an_all_day_series(): void
    {
        $event = $this->allDayWeekly();

        $this->assertSame(['12:00'], $event->recurrence['time']['at'], 'an all-day series is projected at the shared day anchor');

        $this->deleteScoped($event, 'occurrence', '2026-09-14')->assertNoContent();
        $this->assertSame(['2026-09-14'], $event->fresh()->recurrence['exclusions']['dates']);

        $this->deleteScoped($event->fresh(), 'following', '2026-09-28')->assertNoContent();
        $this->assertSame('2026-09-27', $event->fresh()->recurrenceUntilString());

        $this->deleteScoped($event->fresh(), 'following', self::MONDAY)->assertNoContent();
        $this->assertSoftDeleted('calendar_events', ['id' => $event->id]);
    }

    /**
     * A STAMPED ALL-DAY SERIES KEEPS ITS ANCHOR DAY WHEN THE WORKSPACE CROSSES THE DATE LINE.
     *
     * An all-day anchor is a zone-free DATE. Deriving it by building noon on the workspace's CURRENT
     * clock and then reading that instant back on the series' STAMPED clock mixes two clocks, and with
     * sixteen hours between Tokyo and US Pacific the printed day FLIPS.
     *
     * The consequence was destructive rather than cosmetic: with the anchor reading a day late, nothing
     * of the series appeared to precede the second occurrence, so "delete this and all following" from
     * the SECOND Monday collapsed to "delete the whole event" — destroying the occurrence the caller had
     * explicitly asked to keep, on a module with no restore endpoint. The genuine first occurrence
     * became unreachable at the same time.
     */
    public function test_an_all_day_series_keeps_its_anchor_when_the_workspace_crosses_the_date_line(): void
    {
        $this->useTimezone('Asia/Tokyo');

        $event = $this->allDayWeekly();

        $this->assertSame('Asia/Tokyo', $event->recurrence['tz']);

        // Sixteen hours west in September. Noon on this clock, read back on the stamped one, lands on
        // the following day — which is the whole defect.
        $this->useTimezone('America/Los_Angeles');

        $this->deleteScoped($event, 'following', '2026-09-14')->assertNoContent();

        // If this fails, the whole series was destroyed by a split at its SECOND occurrence — the
        // anchor day was misread and the split collapsed to "delete everything".
        $this->assertNotSoftDeleted('calendar_events', ['id' => $event->id]);
        $this->assertSame('2026-09-13', $event->fresh()->recurrenceUntilString());
    }

    /** And the genuine first occurrence stays operable across the same move. */
    public function test_the_first_occurrence_of_a_moved_workspace_series_is_still_reachable(): void
    {
        $this->useTimezone('Asia/Tokyo');

        $event = $this->allDayWeekly();

        $this->useTimezone('America/Los_Angeles');

        $this->deleteScoped($event, 'occurrence', self::MONDAY)->assertNoContent();

        $this->assertSame([self::MONDAY], $event->fresh()->recurrence['exclusions']['dates']);
    }

    // ---- the scope itself -------------------------------------------------------

    /** A single event has no occurrences to name. */
    public function test_a_scoped_write_needs_a_repeating_event(): void
    {
        $event = CalendarEvent::factory()->timed('2026-09-07 09:00:00')->create(['creator_id' => $this->owner->id]);

        $this->deleteScoped($event, 'occurrence', self::MONDAY)
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');
    }

    /** There is nothing to scope when creating: the key is refused rather than ignored. */
    public function test_a_scope_on_create_is_refused(): void
    {
        $this->actingAsOwner()
            ->postJson('/api/calendar/events', [
                'title' => 'Standup',
                'all_day' => false,
                'starts_at' => self::MONDAY . 'T09:00:00Z',
                'scope' => 'occurrence',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');
    }

    /**
     * An occurrence date with the DEFAULT scope is refused rather than dropped. Ignoring it would let a
     * client believe it had edited one occurrence while the whole series was rewritten.
     */
    public function test_an_occurrence_date_without_a_scope_is_refused(): void
    {
        $event = $this->weekly();

        $this->actingAsOwner()
            ->deleteJson('/api/calendar/events/' . $event->id, ['occurrence_date' => '2026-09-14'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('occurrence_date');
    }

    /** A scoped delete is still a delete: the same authorization gate, unchanged. */
    public function test_a_scoped_delete_obeys_the_same_authorization(): void
    {
        $stranger = User::factory()->create();
        $this->workspace->users()->attach($stranger->id);

        $event = $this->weekly();

        parent::actingAs($stranger)
            ->withHeader('X-Workspace-Id', $this->workspace->id)
            ->deleteJson('/api/calendar/events/' . $event->id, [
                'scope' => 'occurrence',
                'occurrence_date' => '2026-09-14',
            ])
            ->assertForbidden();

        $this->assertNull($event->fresh()->recurrence['exclusions'] ?? null);
    }

    // ---- fixtures ---------------------------------------------------------------

    private function actingAsOwner(): self
    {
        parent::actingAs($this->owner)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    private function useTimezone(string $timezone): void
    {
        $this->workspace->forceFill(['timezone' => $timezone])->save();

        app(TenantContext::class)->set($this->workspace->fresh());
    }

    /**
     * Run a request with the NEXT insert into `calendar_events` made to fail, and report whether the
     * caller was told.
     *
     * Returns FALSE when the failure reached the caller — as a propagated throwable OR as a 5xx — and
     * TRUE when the request answered success anyway, which would mean the injection missed and every
     * rollback assertion after it is vacuous.
     *
     * BOTH SHAPES ARE ACCEPTED BECAUSE BOTH ARE REAL. An exception raised inside a model event during a
     * `putJson()` is RENDERED by the framework as a server error rather than escaping the call; the
     * same injection made against a service directly would escape. Pinning one of the two would make
     * this helper depend on which entry point a test happens to use, which is nobody's subject.
     *
     * The verdict is RETURNED rather than asserted in here, so the caller asserts it OUTSIDE any catch
     * — which is the mistake this helper exists to make unrepeatable. Deliberately identical in
     * contract to {@see CalendarRecurrenceTenantDatabaseTest::withFailingInsert()}, so the shared-mode
     * and own-database halves of this chapter do not grow two conventions for one question.
     *
     * @param  \Closure(): TestResponse  $request
     */
    private function withFailingInsert(\Closure $request): bool
    {
        $injected = 'the second half of this write fails, on purpose';

        CalendarEvent::creating(function () use ($injected): void {
            throw new \RuntimeException($injected);
        });

        try {
            $response = $request();
        } catch (\RuntimeException $exception) {
            // A REAL RuntimeException from somewhere else must not be mistaken for the injected one:
            // that is exactly how a broken mechanism starts reading as a passing test.
            if ($exception->getMessage() !== $injected) {
                throw $exception;
            }

            return false;
        } finally {
            CalendarEvent::flushEventListeners();
        }

        return $response->status() < 500;
    }

    /** @param  array<string, mixed>  $extra */
    private function submit(array $extra = []): TestResponse
    {
        return $this->actingAsOwner()->postJson('/api/calendar/events', [
            'title' => 'Standup',
            'all_day' => false,
            'starts_at' => self::MONDAY . 'T09:00:00Z',
            'recurrence' => ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]] + $extra,
        ]);
    }

    /**
     * An event with an arbitrary recurrence block, through the real write path.
     *
     * @param  array<string, mixed>  $recurrence
     * @param  array<string, mixed>  $shape
     */
    private function weeklyFrom(array $recurrence, array $shape = []): CalendarEvent
    {
        $response = $this->actingAsOwner()->postJson('/api/calendar/events', [
            'title' => 'Standup',
            ...($shape === [] ? ['all_day' => false, 'starts_at' => self::MONDAY . 'T09:00:00Z'] : $shape),
            'recurrence' => $recurrence,
        ])->assertCreated();

        return CalendarEvent::query()->findOrFail($response->json('data.id'));
    }

    /** An ALL-DAY weekly-on-Mondays series anchored on {@see MONDAY}, through the real write path. */
    private function allDayWeekly(): CalendarEvent
    {
        return $this->weeklyFrom(
            ['day' => ['mode' => ScheduleDayMode::WEEKDAYS->value, 'weekdays' => [1]]],
            ['all_day' => true, 'start_date' => self::MONDAY],
        );
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
        $response = $this->submit($extra)->assertCreated();

        return CalendarEvent::query()->findOrFail($response->json('data.id'));
    }

    private function show(CalendarEvent $event): TestResponse
    {
        return $this->actingAsOwner()->getJson('/api/calendar/events/' . $event->id)->assertOk();
    }

    private function deleteScoped(CalendarEvent $event, string $scope, string $occurrenceDate): TestResponse
    {
        return $this->actingAsOwner()->deleteJson('/api/calendar/events/' . $event->id, [
            'scope' => $scope,
            'occurrence_date' => $occurrenceDate,
        ]);
    }
}
