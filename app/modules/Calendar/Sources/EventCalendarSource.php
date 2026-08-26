<?php

namespace App\Modules\Calendar\Sources;

use App\Modules\Calendar\Contracts\CalendarSource;
use App\Modules\Calendar\DTOs\CalendarOccurrence;
use App\Modules\Calendar\DTOs\CalendarRecurrence;
use App\Modules\Calendar\DTOs\CalendarSourceResult;
use App\Modules\Calendar\DTOs\CalendarTruncation;
use App\Modules\Calendar\DTOs\CalendarWindow;
use App\Modules\Calendar\Enums\CalendarColor;
use App\Modules\Calendar\Enums\CalendarTruncationKind;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarRecurrenceService;
use App\Modules\Calendar\Support\CalendarCadenceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * The Calendar's OWN events on the grid — one square per event that happens once, and one square per
 * OCCURRENCE of every event that repeats.
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
 * FOUR QUERIES, AND THE NUMBER DOES NOT GROW WITH THE DATA
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The reads are split along TWO independent axes, and each split is forced by something that would
 * otherwise be wrong rather than merely untidy:
 *
 *   ONCE vs REPEATING (R3 B5). A row that happens once is ALREADY an occurrence: the columns are the
 *     answer and no arithmetic is involved. A row that repeats is a RULE, and turning it into squares
 *     costs a projection through the shared cadence engine. Selecting both in one query would mean
 *     either running the projection over rows that have no rule — paying cron-engine time to discover
 *     a NULL — or carrying the two shapes down one code path where half the rows have dead columns.
 *     The two also need DIFFERENT BUDGETS, which is the sharper reason: a one-shot row is bounded by
 *     the occurrence ceiling (one row, one square), while a rule is bounded by the ITEM budget,
 *     because one row can be sixty-two squares.
 *
 *   A DAY vs A MOMENT. An all-day event lives in a `date` column and is selected by comparing DAY
 *     STRINGS; a timed event lives in a timestamp and is selected by comparing INSTANTS. The window
 *     offers both readings and each is exact for its own kind, so the discriminator that splits the
 *     columns splits the read too. One clever query over a coalesced expression would have to convert
 *     one of the two — which is precisely how a zone-free day lands on the wrong square — and would
 *     index neither.
 *
 * Two axes, two values each: four queries, ALWAYS four, whether the workspace holds one series or
 * three hundred. The projection loop below issues none of its own — every row it needs is already in
 * memory, and the shared engine is a pure function of (descriptor, anchor) that touches no database.
 * That is pinned by test, because "project inside the loop" is the natural way to write this and
 * nobody notices the cost until somebody has thirty series.
 *
 * Each half is ordered ASCENDING and bounded. Ascending matters beyond tidiness: the query service
 * trims the MERGED set from the late end, so a query slicing from the newest end would hand over
 * exactly the rows the trim discards first and silently contribute nothing under overflow.
 *
 * WITHIN one query, therefore, what is dropped is the far end of the window. ACROSS the four it is
 * not: one-shot events are budgeted before any series, and all-day series before timed ones, so a
 * response that runs out of ceiling can drop an early-window series in favour of a late-window one-off.
 * That is a real weakening of "the earliest survives", and the reason it is acceptable is that the
 * alternative is worse: ordering the four against each other means comparing a zone-free day with an
 * instant. The loss is REPORTED as `items_dropped` — whole series missing from the whole window, which
 * is exactly what happened — rather than described as a trimmed window, which is what it is not.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE PRE-FILTER FOR A SERIES, AND WHY IT IS NOT THE ONE THE SCHEDULE SOURCE USES
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A row can contribute to the window only if it STARTS no later than the window ends AND does not END
 * before the window begins. Both halves are columns this table already has — the anchor (which is the
 * series' FIRST occurrence, guaranteed by the write path) and `recurrence_until`.
 *
 * The sibling schedule source pre-filters on a stored `next_due_at` instead, and its correctness rests
 * on an invariant maintained by a write path elsewhere (that the column is always a real occurrence
 * with nothing between it and the instant it was computed from). This filter rests on nothing but the
 * two columns in front of it, which makes it the better of the two — and it is available here only
 * because a calendar series, unlike an automation, has an end date on the row.
 *
 * THE ALL-DAY HALF IS EXACT, not merely safe, and for a reason worth stating: it makes the IDENTICAL
 * string comparison the projection then makes ({@see CalendarRecurrenceService::occurrenceDaysIn()}
 * intersects the same day strings), so the filter and the projection cannot disagree about a row. A
 * zone-free day has nothing to convert between, which is the whole point of storing it as one.
 *
 * ONE SUBTLETY, on the timed half only. `recurrence_until` is a DAY on the series' STAMPED clock,
 * while the window's start is an instant; the stamped clock is not necessarily the workspace's current
 * one (the zone is written at save time and never re-derived). A day label can differ from a UTC day
 * label by at most one on either side of the boundary that matters here, so the comparison is made
 * against the UTC day BEFORE the window opens. The proof is short: a series ending on day D places its
 * last occurrence no later than D 23:59 local, and no zone is more than 12 hours behind UTC, so that
 * instant is at or before D+1 12:00 UTC — which can only reach a window opening on UTC day d if
 * D >= d - 1. The extra day admits at most one row that turns out to contribute nothing (the
 * projection then returns an empty list for it); the alternative, an exact-looking comparison, would
 * DROP a series that does contribute, and a missing square reports nothing.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A SERIES DRAWS ITS PAST TOO
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Deliberately unlike the schedule source, which projects the future only. See
 * {@see CalendarRecurrenceService::occurrenceDaysIn()} for the full argument; in one line: a schedule
 * occurrence in the past is a claim about EXECUTION that may be false, and an event executes nothing,
 * so its rule is the entire truth about how many times it happened.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS CUT, AND WHY THE REPORT IS SHAPED THE WAY IT IS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Copied deliberately from the schedule source, which has already been through this exact defect and
 * been fixed: a series can be cut in TWO places, and only one of them used to be reported.
 *
 *   THE PER-ITEM CAP. A series with more occurrences in the window than one item may contribute keeps
 *     the first `maxOccurrencesPerItem` of them, EVERY ONE of which is flagged `dense`, and the result
 *     files an `item_densified`. The flag goes on the occurrences rather than on the report alone
 *     because that is what lets the grid mark the sample AS a sample.
 *
 *   THE RESPONSE CEILING, BITING MID-SERIES. This is the one that was silent. The slice is decided
 *     BEFORE anything is emitted, so every occurrence of that item carries `dense` as well — and the
 *     source ALSO files a `window_trimmed` with an UNKNOWN count. The unknown is the point: truncation
 *     reports merge per (source, kind) and a known count plus an unknown one is unknown, so this entry
 *     is what stops the query service's own exact figure from passing for the whole loss. An exact
 *     number that describes part of the damage is a worse lie than no number.
 *
 * ONE LOSS IS DELIBERATELY NOT IN THIS VOCABULARY: a row whose stored rule this module can no longer
 * READ. That is CORRUPTION, not a budget — the answer is not "there is more of this than you can see",
 * it is "this row is not what it says it is" — so it is degraded to the one-off it now effectively is
 * (see the loop below), logged by the read that could not parse it, and reported to the user as
 * nothing. A truncation entry would tell a reader their window was too small, which is false and
 * un-actionable.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE IDENTITY OF AN OCCURRENCE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A one-shot event is `event:{uuid}` — unchanged, because the row IS the occurrence.
 *
 * A series occurrence is `event:{uuid}:{Y-m-d}`, and the day is the day on the SERIES' OWN stamped
 * clock. Two properties are load-bearing. It is STABLE across refreshes (a projected occurrence has no
 * row of its own, and an id minted per response would make selection, focus and keying flicker on
 * every navigation), and it is DISTINCT per day, without which a client cannot tell two squares of one
 * series apart. The day rather than an instant, because a calendar series has exactly ONE hour by
 * construction, so the day is the whole of what distinguishes one occurrence from another — and it is
 * the same key the write surface names an occurrence by (`occurrence_date`), so the two halves of the
 * module identify an occurrence identically.
 *
 * `subject` keeps the ROW's id in both cases, so a client opens the drawer for the underlying event
 * without parsing anything out of the id.
 *
 * AND THE DAY IS PUBLISHED IN ITS OWN RIGHT, as `occurrence_date`, alongside a `recurring` flag. The
 * id is a KEY — for selection, focus and rendering — and the moment a client has to take it apart to
 * learn something, it is a payload with a parser in front of it, which is the exact thing `subject.id`
 * was introduced to avoid. A client acts on one occurrence the instant a square is clicked, before it
 * has fetched the event; the string it must send is the one this source already computed to build the
 * id, so both come from a single expression below and cannot drift.
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
 * owner check reads the memoized active workspace — and for a series it is asked ONCE per row rather
 * than once per square, because it is a property of the row.
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

    /** The same, plus the rule a repeating row is projected from. */
    private const SERIES_COLUMNS = [...self::COLUMNS, 'recurrence', 'recurrence_until'];

    public function __construct(
        private CalendarRecurrenceService $recurrences,
        private CalendarCadenceLabel $cadence,
    ) {}

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
        $budget = max(0, $window->maxOccurrences);
        $truncations = [];

        // ---- the events that happen ONCE ---------------------------------------------------------
        // Their shape is exactly what it was before series existed: one row, one square, no arithmetic.
        $occurrences = $this->singleEventsIn($window)
            ->map(fn (CalendarEvent $event): CalendarOccurrence => $this->toOccurrence($event))
            ->values()
            ->all();

        // Each half asked for one row more than the whole response may carry, so an overflow is
        // DETECTABLE rather than landing exactly on the ceiling and looking complete. What is cut is
        // decided on the SAME ordering key the query service will sort by, so the rows that survive
        // here are the rows that would have survived there — the source never discards the early part
        // of the window and then reports only that "something" was cut.
        if (count($occurrences) > $budget) {
            usort(
                $occurrences,
                fn (CalendarOccurrence $a, CalendarOccurrence $b): int => $a->sortKey($window->timezone) <=> $b->sortKey($window->timezone),
            );

            $occurrences = array_slice($occurrences, 0, $budget);

            $truncations[] = new CalendarTruncation(
                source: self::ID,
                kind: CalendarTruncationKind::WINDOW_TRIMMED,
            );
        }

        // ---- the events that REPEAT --------------------------------------------------------------
        [$series, $truncations] = $this->seriesRowsIn($window, $truncations);

        // At least one. A per-item budget of zero would compute `dense`, then slice the series to
        // nothing — leaving no occurrence to carry the flag, which is the one outcome this whole
        // mechanism exists to prevent. One flagged occurrence still says "there is more of this".
        $perItem = max(1, $window->maxOccurrencesPerItem);

        $densifiedItems = 0;
        $stoppedMidSeries = false;

        foreach ($series as $event) {
            if (count($occurrences) >= $budget) {
                // The occurrence ceiling stopped us before the item budget did. Whatever is left in the
                // list is absent from the whole window, which is the same KIND of loss as an item cap
                // rather than "the far end of the window was cut" — so it is reported as such.
                $truncations[] = new CalendarTruncation(
                    source: self::ID,
                    kind: CalendarTruncationKind::ITEMS_DROPPED,
                );

                break;
            }

            $rule = $this->recurrences->seriesOf($event);

            if ($rule === null) {
                // The row carries a rule this module can no longer read (a console fix, a restored
                // dump). `seriesOf` has already logged it and degraded it to "not a series", so the
                // event is drawn as the one-off it now effectively is — once, on its anchor, and only
                // if the window actually contains it. Dropping it instead would make an unreadable
                // column erase an event that is otherwise perfectly intact.
                if ($this->anchorIsInside($event, $window)) {
                    $occurrences[] = $this->toOccurrence($event);
                }

                continue;
            }

            // Asked for ONE more than the item may contribute, so an overrun is detectable instead of
            // landing exactly on the cap and looking like a series that happened to end there.
            $projected = $this->projectionOf($event, $rule, $window, $perItem + 1);

            $dense = count($projected) > $perItem;

            if ($dense) {
                $projected = array_slice($projected, 0, $perItem);
            }

            // THE RESPONSE CEILING CAN ALSO BITE IN THE MIDDLE OF A SERIES, and when it does what is
            // emitted for this item is every bit as much a SAMPLE as a per-item cap makes it. Deciding
            // it HERE, before anything is emitted, is what lets every occurrence of this item carry the
            // flag; the emit loop below then needs no ceiling check of its own, because the slice
            // already fits.
            $remaining = $budget - count($occurrences);

            if (count($projected) > $remaining) {
                $projected = array_slice($projected, 0, $remaining);
                $dense = true;
                $stoppedMidSeries = true;
            }

            if ($dense) {
                $densifiedItems++;
            }

            // Computed ONCE per row, never per square: all three are properties of the EVENT, and a
            // densified daily series is exactly the case that would otherwise pay for each of them
            // sixty-four times.
            $editable = Gate::allows('update', $event);
            $cadenceLabel = $this->cadence->for($rule);
            $duration = $this->durationSeconds($event);

            foreach ($projected as $moment) {
                $occurrences[] = $this->toSeriesOccurrence($event, $rule, $moment, $editable, $cadenceLabel, $duration, $dense);
            }
        }

        if ($densifiedItems > 0) {
            $truncations[] = new CalendarTruncation(
                source: self::ID,
                kind: CalendarTruncationKind::ITEM_DENSIFIED,
                affectedItems: $densifiedItems,
            );
        }

        if ($stoppedMidSeries) {
            // AND the response ceiling's own figure has to be told it is incomplete. See the class
            // docblock: reports merge per (source, kind), so only a same-kind entry with an UNKNOWN
            // count can stop an exact figure from passing for the whole loss.
            $truncations[] = new CalendarTruncation(
                source: self::ID,
                kind: CalendarTruncationKind::WINDOW_TRIMMED,
            );
        }

        return new CalendarSourceResult($occurrences, $truncations);
    }

    // ---- the reads ---------------------------------------------------------------------------------

    /**
     * Every NON-REPEATING event the window contains, both shapes, in ONE collection.
     *
     * @return \Illuminate\Support\Collection<int, CalendarEvent>
     */
    private function singleEventsIn(CalendarWindow $window)
    {
        return $this->allDayEventsIn($window)->concat($this->timedEventsIn($window));
    }

    /**
     * Events that occupy a DAY, selected by comparing the stored day against the window's day strings.
     * No parsing, no conversion — the value on disk IS the answer.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CalendarEvent>
     */
    private function allDayEventsIn(CalendarWindow $window)
    {
        return $this->baseQuery($window)
            // Trashed events are excluded by SoftDeletes, and everything outside the active workspace
            // by TenantAware — named here because a calendar quietly showing a deleted event is the
            // kind of defect only the person who deleted it ever notices.
            ->where('all_day', true)
            ->whereNotNull('start_date')
            ->whereNull('recurrence')
            ->whereBetween('start_date', [$window->startDate, $window->endDate])
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
        return $this->baseQuery($window)
            ->where('all_day', false)
            ->whereNotNull('starts_at')
            ->whereNull('recurrence')
            ->whereBetween('starts_at', [$window->startsAt(), $window->endsAt()])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit($window->maxOccurrences + 1)
            ->get(self::COLUMNS);
    }

    /**
     * Every REPEATING row that can reach the window, both shapes, with the item budget applied and
     * reported.
     *
     * THE BUDGET IS APPLIED PER SHAPE, and that is a considered trade rather than an oversight. The
     * two halves have no common ordering key: comparing an all-day row's `start_date` against a timed
     * row's `starts_at` means turning a zone-free day into an instant, which is the single conversion
     * this module is built to refuse. A combined `take()` over the concatenation would therefore order
     * by nothing at all and, when the budget bit, would silently keep whichever shape happened to be
     * concatenated first — hiding every timed series behind two hundred all-day ones.
     *
     * What the doubling actually buys is bounded, and the two costs come apart. ROWS EXAMINED can
     * reach 2 x maxItems; PROJECTIONS cannot, because the loop stops the moment the occurrence ceiling
     * is full, and a workspace with four hundred contributing series fills a thousand occurrences long
     * before it reaches the four hundredth row. A work cap doing its job twice, rather than a cap that
     * lets an unbounded number of rows through.
     *
     * @param  array<int, CalendarTruncation>  $truncations
     * @return array{0: \Illuminate\Support\Collection<int, CalendarEvent>, 1: array<int, CalendarTruncation>}
     */
    private function seriesRowsIn(CalendarWindow $window, array $truncations): array
    {
        $maxItems = max(1, $window->maxItems);

        $rows = collect();

        foreach ([$this->allDaySeriesIn($window, $maxItems), $this->timedSeriesIn($window, $maxItems)] as $half) {
            if ($half->count() > $maxItems) {
                // We asked for one more than the budget purely to learn that there ARE more. How many
                // more is not known — counting them is a second query for a number nobody can act on —
                // so the report says which kind of loss occurred and leaves the figure null rather than
                // inventing one.
                $half = $half->take($maxItems);

                $truncations[] = new CalendarTruncation(
                    source: self::ID,
                    kind: CalendarTruncationKind::ITEMS_DROPPED,
                );
            }

            $rows = $rows->concat($half);
        }

        return [$rows, $truncations];
    }

    /**
     * ALL-DAY series that can reach the window. Both bounds are zone-free day strings compared against
     * the window's own day strings — exact, and the same comparison the projection then makes.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CalendarEvent>
     */
    private function allDaySeriesIn(CalendarWindow $window, int $maxItems)
    {
        return $this->baseQuery($window)
            ->where('all_day', true)
            ->whereNotNull('start_date')
            ->whereNotNull('recurrence')
            ->where('start_date', '<=', $window->endDate)
            ->where(fn ($query) => $query
                ->whereNull('recurrence_until')
                ->orWhere('recurrence_until', '>=', $window->startDate))
            // Earliest-anchored first, so if the item budget bites it drops the series that begin
            // LATEST — the ones least likely to have anything in a window the user is already looking
            // at, and the ones whose absence the ITEMS_DROPPED report is about.
            ->orderBy('start_date')
            ->orderBy('id')
            ->limit($maxItems + 1)
            ->get(self::SERIES_COLUMNS);
    }

    /**
     * TIMED series that can reach the window. The anchor is compared as an INSTANT (exact: it is the
     * series' first occurrence), the end as a DAY with one day of slack — see the class docblock for
     * the proof that one day is enough and why the slack leans the way it does.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CalendarEvent>
     */
    private function timedSeriesIn(CalendarWindow $window, int $maxItems)
    {
        return $this->baseQuery($window)
            ->where('all_day', false)
            ->whereNotNull('starts_at')
            ->whereNotNull('recurrence')
            ->where('starts_at', '<=', $window->endsAt())
            ->where(fn ($query) => $query
                ->whereNull('recurrence_until')
                ->orWhere('recurrence_until', '>=', $window->startsAt()->subDay()->format('Y-m-d')))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit($maxItems + 1)
            ->get(self::SERIES_COLUMNS);
    }

    /** The predicates every one of the four reads shares. */
    private function baseQuery(CalendarWindow $window)
    {
        return CalendarEvent::query()
            ->when($window->hasSearch(), fn ($query) => $query->search(['title'], $window->search));
    }

    // ---- the projection ----------------------------------------------------------------------------

    /**
     * One series as the raw moments it falls on inside the window — day strings for an all-day series,
     * UTC instants for a timed one.
     *
     * Deliberately NOT occurrences yet: `dense` is decided by how many of these there are, and an
     * occurrence is immutable, so building them before the count is known would mean building them
     * twice or lying with the flag.
     *
     * @return array<int, string>|array<int, CarbonImmutable>
     */
    private function projectionOf(CalendarEvent $event, CalendarRecurrence $rule, CalendarWindow $window, int $cap): array
    {
        if ($event->all_day) {
            $anchorDay = $event->startDateString();

            return $anchorDay === null
                ? []
                : $this->recurrences->occurrenceDaysIn($rule, $anchorDay, $window->startDate, $window->endDate, $cap);
        }

        return $event->starts_at === null ? [] : $this->recurrences->occurrenceInstantsIn(
            $rule,
            CarbonImmutable::instance($event->starts_at),
            $window->startsAt(),
            $window->endsAt(),
            $cap,
        );
    }

    /**
     * How long every occurrence of a series lasts, in seconds, or null when the event has no end.
     *
     * A FIXED INTERVAL taken from the anchor, which is what keeps an hour-long meeting an hour long
     * across a daylight-saving transition. Re-deriving the end from a wall-clock hour would instead
     * make the meeting 60 or 120 minutes long depending on the week.
     */
    private function durationSeconds(CalendarEvent $event): ?int
    {
        if ($event->all_day || $event->starts_at === null || $event->ends_at === null) {
            return null;
        }

        return (int) $event->starts_at->diffInSeconds($event->ends_at);
    }

    /** Whether the row's own anchor falls inside the window, in the reckoning its shape demands. */
    private function anchorIsInside(CalendarEvent $event, CalendarWindow $window): bool
    {
        if ($event->all_day) {
            $day = $event->startDateString();

            return $day !== null && $day >= $window->startDate && $day <= $window->endDate;
        }

        return $event->starts_at !== null
            && !$event->starts_at->lessThan($window->startsAt())
            && !$event->starts_at->greaterThan($window->endsAt());
    }

    // ---- the mapping -------------------------------------------------------------------------------

    /**
     * One NON-REPEATING event as one occurrence, through whichever named constructor its own
     * discriminator selects.
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

    /**
     * ONE OCCURRENCE OF A SERIES — the same square a one-off event draws, with an id that distinguishes
     * this day from the rest of the series and a cadence sentence that says the square is one of many.
     *
     * The cadence label is what carries "this repeats" to a client that knows nothing about events: it
     * is PROSE the server translated, exactly like a source's label and an occurrence's badge, so a
     * client renders a series marker without learning a new vocabulary. That is the whole of why this
     * batch needs no frontend change.
     */
    private function toSeriesOccurrence(
        CalendarEvent $event,
        CalendarRecurrence $rule,
        string|CarbonImmutable $moment,
        bool $editable,
        ?string $cadenceLabel,
        ?int $duration,
        bool $dense,
    ): CalendarOccurrence {
        // THE DAY THIS OCCURRENCE IS NAMED BY, computed ONCE and used for both the id and the published
        // `occurrence_date`. One expression, so the name the client sends back and the name the id
        // carries cannot drift apart — for an all-day series it IS the projected day, and for a timed
        // one it is the instant read on the SERIES' STAMPED clock (never the workspace's current one,
        // which would give one occurrence two names the day a workspace changed its timezone).
        $day = is_string($moment) ? $moment : $moment->setTimezone($rule->timezone())->format('Y-m-d');

        if (is_string($moment)) {
            return CalendarOccurrence::allDay(
                id: self::ID . ':' . $event->id . ':' . $day,
                source: self::ID,
                subjectType: $event->getMorphClass(),
                subjectId: $event->id,
                date: $moment,
                title: $event->title,
                color: self::COLOR,
                badge: null,
                editable: $editable,
                dense: $dense,
                cadenceLabel: $cadenceLabel,
                recurring: true,
                occurrenceDate: $day,
            );
        }

        return CalendarOccurrence::timed(
            id: self::ID . ':' . $event->id . ':' . $day,
            source: self::ID,
            subjectType: $event->getMorphClass(),
            subjectId: $event->id,
            startsAt: $moment,
            endsAt: $duration === null ? null : $moment->addSeconds($duration),
            title: $event->title,
            color: self::COLOR,
            badge: null,
            editable: $editable,
            dense: $dense,
            cadenceLabel: $cadenceLabel,
            recurring: true,
            occurrenceDate: $day,
        );
    }
}
