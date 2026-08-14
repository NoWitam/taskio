<?php

namespace App\Modules\Calendar\Sources;

use App\Modules\Calendar\Contracts\CalendarSource;
use App\Modules\Calendar\DTOs\CalendarOccurrence;
use App\Modules\Calendar\DTOs\CalendarSourceResult;
use App\Modules\Calendar\DTOs\CalendarTruncation;
use App\Modules\Calendar\DTOs\CalendarWindow;
use App\Modules\Calendar\Enums\CalendarColor;
use App\Modules\Calendar\Enums\CalendarTruncationKind;
use App\Modules\Calendar\Models\CalendarEvent;
use Illuminate\Support\Facades\Gate;

/**
 * The Calendar's OWN events on the grid.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS ONE LIVES INSIDE THE CALENDAR MODULE AND THE OTHERS DO NOT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Every other source lives in the module that OWNS its subject — task deadlines under Tasks, schedules
 * and runs under Workflows — because the Calendar knows nobody. This one is not an exception to that
 * rule, it is the rule applied: the Calendar owns `calendar_events`, so the Calendar owns the mapping.
 * Nothing here names a foreign module, and the property CalendarModuleBoundaryTest pins (a FOURTH
 * source joins from outside without touching a Calendar file) is untouched by an event source that was
 * never outside.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * TWO QUERIES, BECAUSE THERE ARE TWO SHAPES
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * An all-day event lives in a `date` column and is selected by comparing DAY STRINGS; a timed event
 * lives in a timestamp and is selected by comparing INSTANTS. The window offers both readings and each
 * is exact for its own kind, so the discriminator that splits the columns splits the read too. One
 * clever query over a coalesced expression would have to convert one of the two — which is precisely
 * how a zone-free day lands on the wrong square — and would index neither.
 *
 * Each half is ordered ASCENDING and bounded, and the merged result is bounded again on the shared
 * ordering key. Ascending matters beyond tidiness: the query service trims the MERGED set from the
 * late end, so a source slicing from the newest end would hand over exactly the rows the trim discards
 * first and silently contribute nothing under overflow.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE COLOUR IS A CONSTANT, AND WHICH CONSTANT IS A DECISION
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Every other source colours by a FACT it knows about its subject: a deadline by priority, a run by how
 * it ended, a schedule projection by being a projection. An event knows no such fact — it is an
 * annotation, and the thing it annotates is already on the grid as its own square if it is on the grid
 * at all. So this source emits one value for every event it will ever return, and the grid reads
 * "event" from it rather than a meaning that does not exist.
 *
 * PRIMARY, and neither of the two obvious alternatives:
 *   INFO belongs to the schedule projection — the "this has not happened yet" reading — and reusing it
 *        would say two different things in the same grid.
 *   NEUTRAL is the DEGRADATION value: {@see CalendarColor::fromTone()} falls back to it when a source
 *        hands over a tone nobody recognises. Colouring events with it would make "this is an event"
 *        indistinguishable from "something could not be interpreted".
 *
 * `editable` is answered PER EVENT through the policy, and this is the first source able to say true:
 * events are the only calendar subject with a write path. Answering a blanket true would put a drag
 * handle on squares whose save 403s, and a blanket false would hide the one thing this chapter built.
 * The check costs no query — ownership reads the creator columns already loaded, and the workspace
 * owner check reads the memoized active workspace.
 */
class EventCalendarSource implements CalendarSource
{
    public const ID = 'event';

    /**
     * The one colour every event occurrence carries. See the class docblock for why it is a constant at
     * all, and why this constant.
     */
    private const COLOR = CalendarColor::PRIMARY;

    /** Only the columns the occurrence and its capability check need. */
    private const COLUMNS = [
        'id', 'title', 'all_day', 'start_date', 'starts_at', 'ends_at',
        'creator_id', 'creator_type',
    ];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('calendar.sources.event');
    }

    public function occurrences(CalendarWindow $window): CalendarSourceResult
    {
        $events = $this->allDayEventsIn($window)->concat($this->timedEventsIn($window));

        $occurrences = $events
            ->map(fn (CalendarEvent $event): CalendarOccurrence => $this->toOccurrence($event))
            ->values()
            ->all();

        $truncations = [];

        // Each half asked for one row more than the whole response may carry, so an overflow is
        // DETECTABLE rather than landing exactly on the ceiling and looking complete. What is cut is
        // decided on the SAME ordering key the query service will sort by, so the rows that survive
        // here are the rows that would have survived there — the source never discards the early part
        // of the window and then reports only that "something" was cut.
        if (count($occurrences) > $window->maxOccurrences) {
            usort(
                $occurrences,
                fn (CalendarOccurrence $a, CalendarOccurrence $b): int => $a->sortKey($window->timezone) <=> $b->sortKey($window->timezone),
            );

            $occurrences = array_slice($occurrences, 0, $window->maxOccurrences);

            $truncations[] = new CalendarTruncation(
                source: self::ID,
                kind: CalendarTruncationKind::WINDOW_TRIMMED,
            );
        }

        return new CalendarSourceResult($occurrences, $truncations);
    }

    /**
     * Events that occupy a DAY, selected by comparing the stored day against the window's day strings.
     * No parsing, no conversion — the value on disk IS the answer.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CalendarEvent>
     */
    private function allDayEventsIn(CalendarWindow $window)
    {
        return CalendarEvent::query()
            // Trashed events are excluded by SoftDeletes, and everything outside the active workspace
            // by TenantAware — named here because a calendar quietly showing a deleted event is the
            // kind of defect only the person who deleted it ever notices.
            ->where('all_day', true)
            ->whereNotNull('start_date')
            ->whereBetween('start_date', [$window->startDate, $window->endDate])
            ->when($window->hasSearch(), fn ($query) => $query->search(['title'], $window->search))
            ->orderBy('start_date')
            ->orderBy('id')
            ->limit($window->maxOccurrences + 1)
            ->get(self::COLUMNS);
    }

    /**
     * Events that happen at a MOMENT, selected against the window's true UTC edges.
     *
     * Selected on `starts_at` alone: an event is on the grid on the day it BEGINS. A long event that
     * started before the window and runs into it is not returned, which is a real (and accepted)
     * limitation of the single-square model the occurrence DTO describes — a span that has to be drawn
     * across days is a rendering feature this batch does not have, and pretending otherwise here would
     * put an occurrence on a day whose `starts_at` is outside the window the client asked for.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CalendarEvent>
     */
    private function timedEventsIn(CalendarWindow $window)
    {
        return CalendarEvent::query()
            ->where('all_day', false)
            ->whereNotNull('starts_at')
            ->whereBetween('starts_at', [$window->startsAt(), $window->endsAt()])
            ->when($window->hasSearch(), fn ($query) => $query->search(['title'], $window->search))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit($window->maxOccurrences + 1)
            ->get(self::COLUMNS);
    }

    /**
     * One event as one occurrence, through whichever named constructor its own discriminator selects.
     *
     * `subject` is the EVENT ITSELF, not whatever the event points at. The occurrence's subject is what
     * the square IS — what a click on it should open — and an event's own pointer is an optional aside
     * that only the detail payload carries. Repointing this at `subject_type`/`subject_id` would break
     * every deep-link from the grid and silently orphan every event that points at nothing.
     */
    private function toOccurrence(CalendarEvent $event): CalendarOccurrence
    {
        $editable = Gate::allows('update', $event);

        if ($event->all_day) {
            return CalendarOccurrence::allDay(
                id: self::ID . ':' . $event->id,
                source: self::ID,
                subjectType: $event->getMorphClass(),
                subjectId: $event->id,
                date: $event->startDateString(),
                title: $event->title,
                color: self::COLOR,
                // An event carries no state, so it has nothing to badge. A chip repeating the colour
                // would be decoration standing where a fact goes on every other source.
                badge: null,
                editable: $editable,
            );
        }

        return CalendarOccurrence::timed(
            id: self::ID . ':' . $event->id,
            source: self::ID,
            subjectType: $event->getMorphClass(),
            subjectId: $event->id,
            startsAt: $event->starts_at,
            endsAt: $event->ends_at,
            title: $event->title,
            color: self::COLOR,
            badge: null,
            editable: $editable,
        );
    }
}
