<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Calendar\DTOs\CalendarEventDTO;
use App\Modules\Calendar\Enums\CalendarColor;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarEventService;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * R3 B3 — the calendar's WRITE surface: the events the module owns.
 *
 * The theme running through most of these is the ALL-DAY DISCRIMINATOR. It is the one thing that can
 * go wrong here quietly: a row carrying both a day and an instant reads as whichever the reader asked
 * for, so the failure surfaces as an event on the wrong square long after the write that caused it.
 * It is therefore defended at three layers, and all three are asserted rather than described — the
 * request (422), the DTO (unrepresentable), and the service (the unused group is blanked on every
 * write, so an UPDATE cannot leave the old shape behind).
 */
class CalendarEventTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach([$this->owner->id, $this->member->id]);

        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /** Act as a workspace MEMBER (the default) or a named user, with the tenant header set. */
    private function asUser(?User $user = null): self
    {
        parent::actingAs($user ?? $this->member)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    /** @return array<string, mixed> */
    private function timedPayload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Kickoff call',
            'all_day' => false,
            'starts_at' => '2026-08-10T09:00:00Z',
            'ends_at' => '2026-08-10T10:00:00Z',
        ];
    }

    /** @return array<string, mixed> */
    private function allDayPayload(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Launch day',
            'all_day' => true,
            'start_date' => '2026-08-12',
        ];
    }

    // ---- tenancy + authorization ----------------------------------------------

    public function test_it_refuses_a_write_without_an_active_workspace(): void
    {
        parent::actingAs($this->member)
            ->postJson('/api/calendar/events', $this->timedPayload())
            ->assertStatus(400);
    }

    public function test_it_refuses_an_unauthenticated_write(): void
    {
        $this->postJson('/api/calendar/events', $this->timedPayload())->assertUnauthorized();
    }

    public function test_a_non_member_cannot_write_to_a_workspaces_calendar(): void
    {
        $stranger = User::factory()->create();

        parent::actingAs($stranger)
            ->withHeader('X-Workspace-Id', $this->workspace->id)
            ->postJson('/api/calendar/events', $this->timedPayload())
            ->assertForbidden();
    }

    /**
     * A foreign event 404s at BIND (WorkspaceScope + the middleware order pinned app-wide), never in a
     * policy — so another workspace's calendar is not merely unreadable, it is unaddressable.
     */
    public function test_an_event_from_another_workspace_is_not_addressable(): void
    {
        $otherOwner = User::factory()->create();
        $other = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $other->users()->attach($otherOwner->id);

        app(TenantContext::class)->set($other);
        $foreign = CalendarEvent::factory()->create();
        app(TenantContext::class)->set($this->workspace);

        $this->asUser()->getJson('/api/calendar/events/' . $foreign->id)->assertNotFound();
    }

    // ---- the all-day discriminator, at the REQUEST -----------------------------

    public function test_an_all_day_payload_carrying_an_instant_is_refused(): void
    {
        // The half that would be tempting to drop. Ignoring the stray instant would save an event the
        // caller believes has a time, which nothing downstream could ever report.
        $this->asUser()
            ->postJson('/api/calendar/events', $this->allDayPayload(['starts_at' => '2026-08-12T09:00:00Z']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_a_timed_payload_carrying_a_day_is_refused(): void
    {
        $this->asUser()
            ->postJson('/api/calendar/events', $this->timedPayload(['start_date' => '2026-08-10']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_date');
    }

    public function test_each_shape_requires_its_own_date(): void
    {
        $this->asUser()
            ->postJson('/api/calendar/events', ['title' => 'x', 'all_day' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_date');

        $this->asUser()
            ->postJson('/api/calendar/events', ['title' => 'x', 'all_day' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at');
    }

    /**
     * An INSTANT where a calendar day belongs. `date` would accept it and hand it on for the conversion
     * that moves an event a day; `date_format:Y-m-d` refuses it at the door.
     */
    public function test_an_all_day_date_must_be_a_plain_day(): void
    {
        $this->asUser()
            ->postJson('/api/calendar/events', $this->allDayPayload(['start_date' => '2026-08-12T00:00:00Z']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_date');
    }

    public function test_an_end_before_the_start_is_refused(): void
    {
        $this->asUser()
            ->postJson('/api/calendar/events', $this->timedPayload(['ends_at' => '2026-08-10T08:00:00Z']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at');
    }

    // ---- whose clock a zone-less instant is on ---------------------------------

    /**
     * THE ROUND TRIP, and it has to be a round trip rather than a column assertion: the property at
     * stake is that the WRITE agrees with the READ, not that the write picked some particular storage
     * format. A workspace on a non-zero offset books 14:30 with no zone named; the grid must draw
     * 14:30, not 16:30.
     *
     * Before this was fixed the request parsed through `config('app.timezone')` — a hard 'UTC' — while
     * the grid rendered in the workspace zone, so every zone-less booking landed an offset away,
     * silently.
     */
    public function test_a_zone_less_instant_is_read_in_the_workspace_timezone(): void
    {
        $this->workspace->update(['timezone' => 'Europe/Warsaw']);

        $id = $this->asUser()
            ->postJson('/api/calendar/events', [
                'title' => 'Standup',
                'all_day' => false,
                // No zone named. The caller means the wall clock of the team whose calendar this is.
                'starts_at' => '2026-08-10T14:30:00',
            ])
            ->assertCreated()
            ->json('data.id');

        // Warsaw is UTC+2 in August, so the stored instant is 12:30Z…
        $this->assertSame(
            '2026-08-10T12:30:00+00:00',
            CalendarEvent::findOrFail($id)->starts_at->utc()->toIso8601String()
        );

        // …and the grid, drawn in the workspace's zone, puts it back at 14:30 wall time.
        $response = $this->asUser()
            ->getJson('/api/calendar/occurrences?from=2026-08-01&to=2026-08-31&sources[]=event')
            ->assertOk();

        $this->assertSame('Europe/Warsaw', $response->json('meta.timezone'));

        $rendered = CarbonImmutable::parse($response->json('data.0.starts_at'))
            ->setTimezone($response->json('meta.timezone'));

        $this->assertSame('14:30', $rendered->format('H:i'), 'the write must agree with the read');
    }

    /**
     * The other half, and the one that must NOT be "helpfully" corrected: a caller that wrote an offset
     * has already said what it means. The workspace timezone interprets SILENCE; it never overrides
     * speech.
     */
    public function test_an_explicit_offset_is_taken_exactly_as_given(): void
    {
        $this->workspace->update(['timezone' => 'Europe/Warsaw']);

        foreach ([
            // Same instant, three ways of saying it — none of them the workspace's clock.
            '2026-08-10T14:30:00Z' => '2026-08-10T14:30:00+00:00',
            '2026-08-10T16:30:00+02:00' => '2026-08-10T14:30:00+00:00',
            '2026-08-10T09:30:00-05:00' => '2026-08-10T14:30:00+00:00',
        ] as $sent => $expected) {
            $id = $this->asUser()
                ->postJson('/api/calendar/events', [
                    'title' => 'Cross-zone call',
                    'all_day' => false,
                    'starts_at' => $sent,
                ])
                ->assertCreated()
                ->json('data.id');

            $this->assertSame(
                $expected,
                CalendarEvent::findOrFail($id)->starts_at->utc()->toIso8601String(),
                "[{$sent}] named its own zone and must survive untouched"
            );
        }
    }

    /**
     * A workspace that never set a timezone inherits `app.timezone`, so a zone-less instant behaves
     * exactly as it did before this field existed. No backfill, no default to get wrong.
     */
    public function test_a_workspace_without_a_timezone_still_reads_zone_less_input_as_utc(): void
    {
        $this->assertNull($this->workspace->timezone);

        $id = $this->asUser()
            ->postJson('/api/calendar/events', $this->timedPayload([
                'starts_at' => '2026-08-10T14:30:00',
                'ends_at' => null,
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            '2026-08-10T14:30:00+00:00',
            CalendarEvent::findOrFail($id)->starts_at->utc()->toIso8601String()
        );
    }

    /**
     * The hole `after_or_equal:starts_at` left open, and the reason the ordering check moved onto the
     * resolved instants: that rule parses BOTH sides in the app timezone, so a MIXED payload — one side
     * zoned, one side not — could pass while genuinely ending before it began.
     *
     * 10:00Z start, zone-less 11:00 end in a UTC+2 workspace = 09:00Z. The rule sees 10:00 < 11:00 and
     * waves it through; the instants that would actually be stored are inverted.
     */
    public function test_a_mixed_zone_pair_that_is_actually_inverted_is_refused(): void
    {
        $this->workspace->update(['timezone' => 'Europe/Warsaw']);

        $this->asUser()
            ->postJson('/api/calendar/events', [
                'title' => 'Backwards across zones',
                'all_day' => false,
                'starts_at' => '2026-08-10T10:00:00Z',
                'ends_at' => '2026-08-10T11:00:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ends_at');
    }

    /** The same pair the other way round is legitimate and must still pass. */
    public function test_a_mixed_zone_pair_that_is_genuinely_ordered_is_accepted(): void
    {
        $this->workspace->update(['timezone' => 'Europe/Warsaw']);

        $this->asUser()
            ->postJson('/api/calendar/events', [
                'title' => 'Ordered across zones',
                'all_day' => false,
                'starts_at' => '2026-08-10T10:00:00Z',   // 10:00Z
                'ends_at' => '2026-08-10T14:00:00',      // Warsaw → 12:00Z
            ])
            ->assertCreated();
    }

    /** An all-day date is zone-free by construction and no timezone may touch it. */
    public function test_a_workspace_timezone_never_shifts_an_all_day_date(): void
    {
        $this->workspace->update(['timezone' => 'Pacific/Kiritimati']); // UTC+14, the widest shift there is

        $this->asUser()
            ->postJson('/api/calendar/events', $this->allDayPayload())
            ->assertCreated()
            ->assertJsonPath('data.start_date', '2026-08-12');

        $this->asUser()
            ->getJson('/api/calendar/occurrences?from=2026-08-01&to=2026-08-31&sources[]=event')
            ->assertOk()
            ->assertJsonPath('data.0.start_date', '2026-08-12');
    }

    public function test_half_a_subject_pointer_is_refused(): void
    {
        $this->asUser()
            ->postJson('/api/calendar/events', $this->timedPayload(['subject_type' => 'task']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject_id');
    }

    // ---- an event has no colour ------------------------------------------------

    /**
     * The value asserted here is a PERFECTLY VALID CalendarColor, and that is the whole point. The field
     * is not gone because the vocabulary got stricter — it is gone because an event has no meaning to
     * colour by, so the grid gives every event the same constant. A caller that still names one is told,
     * rather than having the field dropped on the way to a save that looks like it worked.
     */
    public function test_a_colour_on_the_write_surface_is_refused_rather_than_ignored(): void
    {
        $this->asUser()
            ->postJson('/api/calendar/events', $this->timedPayload(['color' => 'primary']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('color');

        $this->assertSame(0, CalendarEvent::query()->count(), 'a refused write must not have saved anything');
    }

    /**
     * The column is gone from the table, not merely from the layers above it. Asserted because the R3
     * migration was EDITED rather than superseded by a drop: a database that still has the column would
     * keep working silently, and the next reader would not know which of the two shapes is the truth.
     */
    public function test_the_events_table_has_no_colour_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('calendar_events', 'color'),
            'calendar_events must not carry a colour column',
        );
    }

    /** The update request inherits the rule; a PUT is a whole-event write, so it is the same surface. */
    public function test_a_colour_is_refused_on_update_too(): void
    {
        $id = $this->asUser()
            ->postJson('/api/calendar/events', $this->timedPayload())
            ->json('data.id');

        $this->asUser()
            ->putJson('/api/calendar/events/' . $id, $this->timedPayload(['color' => 'danger']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('color');
    }

    // ---- the all-day discriminator, at the DTO ---------------------------------

    /**
     * The structural half of the guarantee: the write DTO's two named constructors cannot hold each
     * other's data, so a row with both shapes has no way to be described in the first place.
     */
    public function test_the_dto_refuses_an_instant_where_a_day_belongs(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarEventDTO::allDay(title: 'Launch', date: '2026-08-12T00:00:00Z');
    }

    public function test_the_dto_shapes_are_mutually_exclusive(): void
    {
        $allDay = CalendarEventDTO::allDay(title: 'Launch', date: '2026-08-12');
        $this->assertTrue($allDay->allDay);
        $this->assertSame('2026-08-12', $allDay->startDate);
        $this->assertNull($allDay->startsAt);
        $this->assertNull($allDay->endsAt);

        $timed = CalendarEventDTO::timed(title: 'Call', startsAt: now());
        $this->assertFalse($timed->allDay);
        $this->assertNull($timed->startDate);
        $this->assertNotNull($timed->startsAt);
    }

    // ---- the all-day discriminator, at the SERVICE -----------------------------

    /**
     * The failure a schema cannot prevent and a create-time check would miss: changing an event from
     * TIMED to ALL-DAY has to blank the instants, or the row keeps answering the old question for any
     * reader that looks at the other column group.
     */
    public function test_changing_an_events_shape_blanks_the_columns_it_left_behind(): void
    {
        $service = app(CalendarEventService::class);

        $event = $service->create(CalendarEventDTO::timed(
            title: 'Call',
            startsAt: now(),
            endsAt: now()->addHour(),
        ));

        $this->assertNotNull($event->starts_at);

        $service->update($event, CalendarEventDTO::allDay(title: 'Call', date: '2026-08-12'));

        $event->refresh();

        $this->assertTrue($event->all_day);
        $this->assertSame('2026-08-12', $event->startDateString());
        $this->assertNull($event->starts_at, 'a timed event turned all-day must not keep its instants');
        $this->assertNull($event->ends_at);

        // …and back the other way.
        $service->update($event, CalendarEventDTO::timed(title: 'Call', startsAt: now()));
        $event->refresh();

        $this->assertFalse($event->all_day);
        $this->assertNull($event->start_date, 'an all-day event turned timed must not keep its day');
    }

    // ---- the happy paths + the response contract -------------------------------

    public function test_it_creates_a_timed_event(): void
    {
        $response = $this->asUser()
            ->postJson('/api/calendar/events', $this->timedPayload())
            ->assertCreated();

        $response->assertJsonPath('data.all_day', false);
        $response->assertJsonPath('data.start_date', null);
        // The event payload carries no colour at all — not a null one. The editor has nothing to read.
        $response->assertJsonMissingPath('data.color');
        $response->assertJsonPath('data.subject', null);
        $response->assertJsonPath('data.creator.type', 'user');
        $response->assertJsonPath('data.is_owner', true);
        $response->assertJsonPath('data.can_be_edited', true);

        $this->assertNotNull($response->json('data.starts_at'));
    }

    public function test_it_creates_an_all_day_event_and_never_converts_its_day(): void
    {
        $response = $this->asUser()
            ->postJson('/api/calendar/events', $this->allDayPayload())
            ->assertCreated();

        $response->assertJsonPath('data.all_day', true);
        // The stored day, re-printed. Not an instant, and not shifted by any zone.
        $response->assertJsonPath('data.start_date', '2026-08-12');
        $response->assertJsonPath('data.starts_at', null);
        $response->assertJsonPath('data.ends_at', null);
    }

    /**
     * The pointer is stored VERBATIM and never resolved. It is deliberately not checked for existence:
     * validating it would mean turning an alias into a class, which is the dependency the Calendar
     * refuses to take. A pointer at nothing is inert, because nothing follows it.
     */
    public function test_a_subject_pointer_is_stored_without_being_resolved(): void
    {
        $response = $this->asUser()
            ->postJson('/api/calendar/events', $this->timedPayload([
                'subject_type' => 'task',
                'subject_id' => '00000000-0000-4000-8000-000000000000',
            ]))
            ->assertCreated();

        $response->assertJsonPath('data.subject.type', 'task');
        $response->assertJsonPath('data.subject.id', '00000000-0000-4000-8000-000000000000');
    }

    public function test_it_updates_and_deletes_an_event(): void
    {
        $id = $this->asUser()
            ->postJson('/api/calendar/events', $this->timedPayload())
            ->json('data.id');

        $this->asUser()
            ->putJson('/api/calendar/events/' . $id, $this->timedPayload(['title' => 'Renamed']))
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');

        $this->asUser()->deleteJson('/api/calendar/events/' . $id)->assertNoContent();

        $this->assertSoftDeleted('calendar_events', ['id' => $id]);
    }

    // ---- who may write on a shared grid ----------------------------------------

    public function test_another_member_cannot_edit_an_event_they_did_not_create(): void
    {
        $id = $this->asUser($this->member)
            ->postJson('/api/calendar/events', $this->timedPayload())
            ->json('data.id');

        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id);

        $this->asUser($other)
            ->putJson('/api/calendar/events/' . $id, $this->timedPayload(['title' => 'Hijacked']))
            ->assertForbidden();

        // …but they can still SEE it. A calendar that hid squares from the team whose calendar it is
        // would not be a coordination surface.
        $this->asUser($other)->getJson('/api/calendar/events/' . $id)->assertOk();
    }

    /**
     * The deliberate widening over ChecksRecordOwnership: the workspace owner may correct ANY event,
     * not merely one nobody owns. A mark left by somebody who has since left the company must not be
     * un-removable on a calendar the whole team reads.
     */
    public function test_the_workspace_owner_can_correct_another_members_event(): void
    {
        $id = $this->asUser($this->member)
            ->postJson('/api/calendar/events', $this->timedPayload())
            ->json('data.id');

        $this->asUser($this->owner)
            ->putJson('/api/calendar/events/' . $id, $this->timedPayload(['title' => 'Corrected']))
            ->assertOk();

        $this->asUser($this->owner)->deleteJson('/api/calendar/events/' . $id)->assertNoContent();
    }

    // ---- events ON the grid ----------------------------------------------------

    public function test_events_appear_on_the_calendar_through_their_own_source(): void
    {
        CalendarEvent::factory()->allDay('2026-08-12')->create(['title' => 'Launch day']);
        CalendarEvent::factory()->timed('2026-08-10 09:00:00', '2026-08-10 10:00:00')->create(['title' => 'Kickoff call']);
        // Outside the window: present in the table, absent from the answer.
        CalendarEvent::factory()->allDay('2026-09-20')->create(['title' => 'Later']);

        $response = $this->asUser()
            ->getJson('/api/calendar/occurrences?from=2026-08-01&to=2026-08-31&sources[]=event')
            ->assertOk();

        $titles = array_column($response->json('data'), 'title');
        sort($titles);
        $this->assertSame(['Kickoff call', 'Launch day'], $titles);

        $byTitle = collect($response->json('data'))->keyBy('title');

        // Each shape crosses onto the grid as itself.
        $this->assertTrue($byTitle['Launch day']['all_day']);
        $this->assertSame('2026-08-12', $byTitle['Launch day']['start_date']);
        $this->assertNull($byTitle['Launch day']['starts_at']);

        $this->assertFalse($byTitle['Kickoff call']['all_day']);
        $this->assertNull($byTitle['Kickoff call']['start_date']);
        $this->assertNotNull($byTitle['Kickoff call']['ends_at']);

        // The occurrence's subject is the EVENT ITSELF — what a click on the square should open — not
        // whatever the event happens to point at.
        $this->assertSame('calendar_event', $byTitle['Launch day']['subject']['type']);
    }

    /**
     * The first source able to answer `editable: true`, and it answers PER EVENT: events are the only
     * calendar subject with a write path, so a blanket true would put a drag handle on squares whose
     * save 403s.
     */
    public function test_editability_on_the_grid_follows_the_policy(): void
    {
        $mine = $this->asUser($this->member)
            ->postJson('/api/calendar/events', $this->timedPayload(['title' => 'Mine']))
            ->json('data.id');

        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id);

        $theirs = $this->asUser($other)
            ->postJson('/api/calendar/events', $this->timedPayload(['title' => 'Theirs']))
            ->json('data.id');

        $occurrences = collect(
            $this->asUser($this->member)
                ->getJson('/api/calendar/occurrences?from=2026-08-01&to=2026-08-31&sources[]=event')
                ->assertOk()
                ->json('data')
        )->keyBy('id');

        $this->assertTrue($occurrences['event:' . $mine]['editable']);
        $this->assertFalse($occurrences['event:' . $theirs]['editable']);
    }

    public function test_a_trashed_event_leaves_the_grid(): void
    {
        $event = CalendarEvent::factory()->allDay('2026-08-12')->create(['title' => 'Cancelled']);

        $event->delete();

        $this->asUser()
            ->getJson('/api/calendar/occurrences?from=2026-08-01&to=2026-08-31&sources[]=event')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    /**
     * Every event, whatever its shape, carries ONE colour — and it is neither of the two the vocabulary
     * would let it drift to. INFO is the schedule projection's ("not a fact yet"), NEUTRAL is what
     * {@see CalendarColor::fromTone()} degrades to when a source hands over a tone nobody recognises;
     * an event wearing either would be claiming something it does not mean.
     */
    public function test_every_event_on_the_grid_carries_the_same_constant_colour(): void
    {
        CalendarEvent::factory()->allDay('2026-08-12')->create(['title' => 'Launch day']);
        CalendarEvent::factory()->timed('2026-08-10 09:00:00')->create(['title' => 'Kickoff call']);

        $colors = collect(
            $this->asUser()
                ->getJson('/api/calendar/occurrences?from=2026-08-01&to=2026-08-31&sources[]=event')
                ->assertOk()
                ->json('data')
        )->pluck('color')->all();

        $this->assertCount(2, $colors);
        $this->assertSame(
            [CalendarColor::PRIMARY->value, CalendarColor::PRIMARY->value],
            $colors,
            'both event shapes must carry the one constant colour the event source emits',
        );
    }
}
