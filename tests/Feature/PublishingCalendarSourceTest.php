<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Publishing\Calendar\PublicationCalendarSource;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R4 B1 — PUBLICATIONS ON THE GRID, through the real endpoint.
 *
 * The point of this file is not that a square appears. It is that a square appears with NO CHANGE TO
 * THE CALENDAR MODULE AND NO CHANGE TO THE FRONTEND — the property R3 was arranged around and the one
 * `CalendarModuleBoundaryTest` states in words. Everything asserted below travels in the vocabulary the
 * calendar already publishes: a source id, a colour meaning, a prose badge, a morph alias. A client that
 * has never heard of publishing renders all of it.
 */
class PublishingCalendarSourceTest extends TestCase
{
    use RefreshDatabase;

    private const WINDOW_FROM = '2026-09-01';

    private const WINDOW_TO = '2026-09-30';

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

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
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function asUser(): self
    {
        parent::actingAs($this->owner)->withHeader('X-Workspace-Id', $this->workspace->id);

        return $this;
    }

    /** THE HEADLINE: a scheduled publication is a square, in the shape every other source produces. */
    public function test_a_scheduled_publication_appears_on_the_grid(): void
    {
        $publication = Publication::factory()
            ->scheduled('2026-09-10 07:00:00')
            ->on(PublishingPlatform::INSTAGRAM)
            ->create(['creator_id' => $this->owner->id, 'title' => 'Premiera odcinka']);

        $response = $this->asUser()
            ->getJson('/api/calendar/occurrences?from=' . self::WINDOW_FROM . '&to=' . self::WINDOW_TO . '&sources[]=publication')
            ->assertOk();

        $response
            ->assertJsonPath('data.0.source', 'publication')
            ->assertJsonPath('data.0.id', 'publication:' . $publication->id)
            ->assertJsonPath('data.0.title', 'Premiera odcinka')
            // AN INSTANT, not a day. A publication has an hour and the hour is the whole content of it.
            ->assertJsonPath('data.0.all_day', false)
            ->assertJsonPath('data.0.ends_at', null)
            // The morph ALIAS, never a class name — a leaked FQCN would put an internal namespace in a
            // public payload.
            ->assertJsonPath('data.0.subject.type', 'publication')
            ->assertJsonPath('data.0.subject.id', $publication->id)
            // Colour carries the STATE: scheduled is `info`.
            ->assertJsonPath('data.0.color', 'info')
            // The badge carries the DESTINATION, as server-translated prose — which is why no frontend
            // change is needed for a platform the client has never heard of.
            ->assertJsonPath('data.0.badge.label', 'Instagram')
            // Nothing on this grid is draggable: moving a square would be an ARMING.
            ->assertJsonPath('data.0.editable', false)
            // One row, one square. No projection, no series.
            ->assertJsonPath('data.0.recurring', false);

        $this->assertSame([], $response->json('meta.unavailable_sources'));
    }

    /**
     * The source joins the filter CATALOGUE, carrying its own translated name.
     *
     * That is the half of "no frontend change per source" a contract alone would not deliver: the
     * calendar screen renders a filter chip for a source it has never heard of, because the label
     * arrives as prose the server already translated. Compared against the translation rather than
     * against a literal, since the app is PL+EN switchable and server prose follows the reader.
     */
    public function test_the_source_appears_in_the_filter_catalogue(): void
    {
        $sources = collect(
            $this->asUser()
                ->getJson('/api/calendar/occurrences?from=' . self::WINDOW_FROM . '&to=' . self::WINDOW_TO)
                ->assertOk()
                ->json('meta.sources')
        )->pluck('label', 'id')->all();

        $this->assertArrayHasKey('publication', $sources);
        $this->assertSame(__('publishing.calendar.source'), $sources['publication']);
        $this->assertNotSame('publishing.calendar.source', $sources['publication'], 'the label must be translated, not a missing key echoed back');
    }

    /**
     * A DRAFT IS NOT ON THE GRID, and neither is a soft-deleted publication.
     *
     * A draft has no `scheduled_at` — no instant, so no place on an axis of time. Putting it somewhere
     * would mean inventing a moment, and an invented moment is one somebody plans around.
     */
    public function test_a_draft_never_reaches_the_grid_even_when_it_carries_a_time(): void
    {
        // The trap: a DRAFT that already carries a moment, which is a legitimate state (you may pick a
        // time while still writing). The status is what decides, not the presence of the column.
        Publication::factory()
            ->withScheduledAt('2026-09-11 09:00:00')
            ->create(['creator_id' => $this->owner->id, 'title' => 'Still writing this']);

        $trashed = Publication::factory()
            ->scheduled('2026-09-12 09:00:00')
            ->create(['creator_id' => $this->owner->id, 'title' => 'Cancelled']);
        $trashed->delete();

        $titles = collect(
            $this->asUser()
                ->getJson('/api/calendar/occurrences?from=' . self::WINDOW_FROM . '&to=' . self::WINDOW_TO . '&sources[]=publication')
                ->assertOk()
                ->json('data')
        )->pluck('title')->all();

        $this->assertSame([], $titles);
    }

    /** Every status that IS projected reaches the grid, each in its own colour. */
    public function test_each_projected_status_carries_its_own_colour(): void
    {
        Publication::factory()->scheduled('2026-09-02 09:00:00')->create(['creator_id' => $this->owner->id, 'title' => 'S']);
        Publication::factory()->publishing('2026-09-03 09:00:00')->create(['creator_id' => $this->owner->id, 'title' => 'P']);
        Publication::factory()->published('2026-09-04 09:00:00')->create(['creator_id' => $this->owner->id, 'title' => 'D']);
        Publication::factory()->failed()->create(['creator_id' => $this->owner->id, 'title' => 'F', 'scheduled_at' => CarbonImmutable::parse('2026-09-05 09:00:00', 'UTC')]);
        Publication::factory()->needsReconcile()->create(['creator_id' => $this->owner->id, 'title' => 'R', 'scheduled_at' => CarbonImmutable::parse('2026-09-06 09:00:00', 'UTC')]);
        Publication::factory()->blocked()->create(['creator_id' => $this->owner->id, 'title' => 'B', 'scheduled_at' => CarbonImmutable::parse('2026-09-07 09:00:00', 'UTC')]);

        $byTitle = collect(
            $this->asUser()
                ->getJson('/api/calendar/occurrences?from=' . self::WINDOW_FROM . '&to=' . self::WINDOW_TO . '&sources[]=publication')
                ->assertOk()
                ->json('data')
        )->pluck('color', 'title')->all();

        $this->assertSame([
            'S' => 'info',
            'P' => 'warning',
            'D' => 'success',
            'F' => 'danger',
            'R' => 'danger',
            'B' => 'warning',
        ], $byTitle);
    }

    /**
     * The window is compared as INSTANTS in the WORKSPACE's zone, which is what the calendar's own
     * window object already resolves.
     *
     * The probe is deliberately on a boundary: 2026-09-30 22:30 UTC is 2026-10-01 00:30 in Warsaw, so a
     * September window must NOT contain it — and would, if the source compared the raw UTC day instead
     * of the window's true edges.
     */
    public function test_the_window_edges_are_the_workspace_days_not_utc_days(): void
    {
        Publication::factory()->scheduled('2026-09-30 22:30:00')->create([
            'creator_id' => $this->owner->id,
            'title' => 'Already October in Warsaw',
        ]);

        Publication::factory()->scheduled('2026-09-30 20:30:00')->create([
            'creator_id' => $this->owner->id,
            'title' => 'Still September in Warsaw',
        ]);

        $titles = collect(
            $this->asUser()
                ->getJson('/api/calendar/occurrences?from=' . self::WINDOW_FROM . '&to=' . self::WINDOW_TO . '&sources[]=publication')
                ->assertOk()
                ->json('data')
        )->pluck('title')->all();

        $this->assertSame(['Still September in Warsaw'], $titles);
    }

    /**
     * The window's search reaches this source like any other.
     *
     * The parameter is `q` — the calendar's own wire name, which `CalendarWindowRequest` maps onto
     * `CalendarWindow::$search`. Named here because getting it wrong is invisible: an unknown parameter
     * is simply not a filter, so the assertion would fail with "everything came back" rather than with
     * anything pointing at the typo.
     */
    public function test_the_window_search_filters_publications(): void
    {
        Publication::factory()->scheduled('2026-09-08 09:00:00')->create(['creator_id' => $this->owner->id, 'title' => 'Wywiad z gościem']);
        Publication::factory()->scheduled('2026-09-09 09:00:00')->create(['creator_id' => $this->owner->id, 'title' => 'Zapowiedź sezonu']);

        $titles = collect(
            $this->asUser()
                ->getJson('/api/calendar/occurrences?from=' . self::WINDOW_FROM . '&to=' . self::WINDOW_TO . '&sources[]=publication&q=Wywiad')
                ->assertOk()
                ->json('data')
        )->pluck('title')->all();

        $this->assertSame(['Wywiad z gościem'], $titles);
    }

    /**
     * OVERFLOW IS REPORTED, AND THE COUNT IS UNKNOWN.
     *
     * The source asks for one row more than the response may carry, purely to learn that there ARE more.
     * How many more is not known — counting them is a second query for a number nobody can act on — so
     * the report says which kind of loss occurred and leaves the figure null rather than inventing one.
     * That null is also what stops the query service's own exact figure from passing for the whole loss.
     */
    public function test_hitting_the_response_ceiling_is_reported_with_an_unknown_count(): void
    {
        config(['calendar.max_occurrences' => 2]);

        for ($i = 1; $i <= 4; $i++) {
            Publication::factory()
                ->scheduled('2026-09-0' . $i . ' 09:00:00')
                ->create(['creator_id' => $this->owner->id, 'title' => 'P' . $i]);
        }

        $response = $this->asUser()
            ->getJson('/api/calendar/occurrences?from=' . self::WINDOW_FROM . '&to=' . self::WINDOW_TO . '&sources[]=publication')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));

        // ASCENDING ORDER MATTERS HERE: the merged set is trimmed from the LATE end, so a source that
        // sliced from the newest end would hand over exactly the rows the trim discards first.
        $this->assertSame(['P1', 'P2'], collect($response->json('data'))->pluck('title')->all());

        $truncations = collect($response->json('meta.truncations'))
            ->where('source', 'publication')
            ->all();

        $this->assertNotEmpty($truncations, 'a source that stopped loading must say so');
    }

    /** The source is registered lazily and never constructed for a request that opens no calendar. */
    public function test_the_source_is_registered_but_not_constructed_eagerly(): void
    {
        $registry = app(\App\Modules\Calendar\Services\CalendarSourceRegistry::class);

        $this->assertTrue($registry->has(PublicationCalendarSource::ID));
        $this->assertSame(
            PublicationCalendarSource::ID,
            $registry->resolve(PublicationCalendarSource::ID)?->id(),
        );
    }
}
