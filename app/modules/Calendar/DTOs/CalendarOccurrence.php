<?php

namespace App\Modules\Calendar\DTOs;

use App\Modules\Calendar\Enums\CalendarColor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * ONE thing on ONE calendar, from any source.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE ALL-DAY DISCRIMINATOR — the modelling hole this type exists to close
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The subjects a calendar shows are not the same KIND of thing in time, and the difference cannot be
 * papered over:
 *
 *   - `tasks.deadline` is a `date` column. A day. There is no hour in it and no zone attached to it.
 *     "2026-08-09" is the ninth of August wherever you read it from.
 *   - A schedule occurrence is an INSTANT — a moment in UTC that lands on different days depending on
 *     where you stand.
 *
 * Put both in one nullable `starts_at` and the first conversion silently moves a Polish deadline onto
 * the wrong day: `2026-08-09` read as an instant is `2026-08-09T00:00:00Z`, which in Europe/Warsaw is
 * two in the morning of the ninth — until the renderer converts it back the other way for a viewer
 * west of UTC and puts it on the eighth. No error, no test failure, a deadline on the wrong square.
 *
 * So the two kinds are constructed by two different named constructors and are structurally unable to
 * hold each other's data:
 *
 *   {@see allDay()}  → carries `startDate` only ('Y-m-d'). NEVER parsed to an instant, NEVER converted.
 *   {@see timed()}   → carries `startsAt`/`endsAt` as UTC instants, and no date string at all.
 *
 * `$allDay` is the discriminator a reader (and the frontend) branches on. The one place a timed
 * occurrence is projected onto a calendar day is {@see sortKey()}, purely to decide what comes before
 * what — never to produce a value anybody stores or renders.
 */
final readonly class CalendarOccurrence
{
    private function __construct(
        /**
         * Stable, deterministic identity — `task:{uuid}`, `workflow_schedule:{uuid}:{iso}`. It must
         * survive a refresh unchanged: the grid re-fetches on every navigation, and an id minted per
         * response would make selection, focus and keying flicker for computed occurrences that have
         * no row of their own to be identified by.
         */
        public string $id,
        /** Id of the {@see \App\Modules\Calendar\Contracts\CalendarSource} that produced this. */
        public string $source,
        public bool $editable,
        public bool $allDay,
        /** `Y-m-d`. Set iff {@see $allDay}. Zone-free by construction. */
        public ?string $startDate,
        /** UTC instant. Set iff NOT {@see $allDay}. */
        public ?CarbonImmutable $startsAt,
        /** UTC instant, or null when the subject has no known end (a run still going). */
        public ?CarbonImmutable $endsAt,
        public string $title,
        public CalendarColor $color,
        public ?CalendarBadge $badge,
        /**
         * "There is MORE of this than you are seeing." Set when one source item overran its per-item
         * budget, so the grid can render a density marker instead of implying the series is complete.
         */
        public bool $dense,
        /**
         * MORPH ALIAS — 'task', 'workflow', 'workflow_run' — never a class name. The alias is the app's
         * public, enforced vocabulary (`Relation::enforceMorphMap`); a leaked FQCN would put an
         * internal namespace in a public payload and freeze a refactor out of the codebase.
         */
        public string $subjectType,
        public string $subjectId,
        /**
         * How often this subject repeats, AS TRANSLATED PROSE — "Every 5 min" — or null when the
         * source has no notion of a cadence.
         *
         * It exists to make {@see $dense} sayable. "There is more of this than you are seeing" is only
         * half a sentence: a grid that can render the marker but not the interval can say no more than
         * "Series — showing 64", which tells a reader nothing they could not already count. The source
         * knows the interval, so the source says it.
         *
         * PROSE, not a code, and for the same reason {@see CalendarBadge::$label} is prose: a coded
         * cadence would need a new client-side vocabulary for every source that has one, and "a fifth
         * source needs no frontend change" would quietly stop being true. OPTIONAL and additive — a
         * source with no cadence leaves it null, and a client treats absent as absent.
         */
        public ?string $cadenceLabel,
        /**
         * Whether this square was COMPUTED FROM A REPEATING RULE rather than read from a row of its
         * own — "there are more squares like this one, and this one has no record behind it".
         *
         * It exists because the only signal a client previously had was a non-null {@see $cadenceLabel},
         * which is SUFFICIENT but not NECESSARY: a source may repeat and have no sentence to say about
         * how often (the label is explicitly allowed to be null for a shape nobody can render). A
         * client branching on the prose would therefore be right by accident, and would silently start
         * being wrong the day a cadence stopped being expressible.
         *
         * NOT a capability and not a permission. It says what the square IS. What may be done to one
         * occurrence is a question for the module that owns the subject, and the Calendar answers it
         * through {@see $occurrenceDate} — the name to send — rather than through a flag.
         */
        public bool $recurring,
        /**
         * WHICH occurrence of its series this is, as the plain 'Y-m-d' the subject's own write surface
         * addresses it by — or null when the square is not one of a series, or its source has no name
         * for a single occurrence.
         *
         * THE DAY IS RECKONED ON THE SERIES' OWN CLOCK, not on the window's. For a calendar event that
         * is the timezone stamped into the rule at save time, which is the clock `occurrence_date` is
         * validated on; handing over the window's reading instead would give one occurrence two names
         * the moment a workspace changed its timezone, and a scoped write would refuse the very day the
         * client read off the grid.
         *
         * It is stated EXPLICITLY rather than left to be recovered, and that is the whole point. A
         * client needs this the instant a square is clicked — before it has fetched the event — and the
         * only other routes to it are parsing the {@see $id} (the exact thing {@see $subjectId} exists
         * to make unnecessary) or re-deriving the day from an instant in a timezone it would have to
         * guess. The source already computes this string to build the id; publishing it costs nothing
         * and closes both holes.
         */
        public ?string $occurrenceDate,
    ) {}

    /**
     * A subject that occupies a DAY and has no time of day: a deadline, a due date, a holiday.
     *
     * @param  string  $date  'Y-m-d' exactly as stored. Passing an instant here is the bug this
     *                        constructor exists to prevent, so the format is asserted.
     */
    public static function allDay(
        string $id,
        string $source,
        string $subjectType,
        string $subjectId,
        string $date,
        string $title,
        CalendarColor $color = CalendarColor::NEUTRAL,
        ?CalendarBadge $badge = null,
        bool $editable = false,
        bool $dense = false,
        ?string $cadenceLabel = null,
        bool $recurring = false,
        ?string $occurrenceDate = null,
    ): self {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException(
                "An all-day calendar occurrence needs a plain Y-m-d date, got [{$date}]. "
                . 'If the subject is an instant, use CalendarOccurrence::timed() instead.'
            );
        }

        return new self(
            id: $id,
            source: $source,
            editable: $editable,
            allDay: true,
            startDate: $date,
            startsAt: null,
            endsAt: null,
            title: $title,
            color: $color,
            badge: $badge,
            dense: $dense,
            subjectType: $subjectType,
            subjectId: $subjectId,
            cadenceLabel: $cadenceLabel,
            recurring: $recurring,
            occurrenceDate: $occurrenceDate,
        );
    }

    /** A subject that happens at a MOMENT: a scheduled firing, a run that started. */
    public static function timed(
        string $id,
        string $source,
        string $subjectType,
        string $subjectId,
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt,
        string $title,
        CalendarColor $color = CalendarColor::NEUTRAL,
        ?CalendarBadge $badge = null,
        bool $editable = false,
        bool $dense = false,
        ?string $cadenceLabel = null,
        bool $recurring = false,
        ?string $occurrenceDate = null,
    ): self {
        return new self(
            id: $id,
            source: $source,
            editable: $editable,
            allDay: false,
            startDate: null,
            startsAt: CarbonImmutable::instance($startsAt)->utc(),
            endsAt: $endsAt !== null ? CarbonImmutable::instance($endsAt)->utc() : null,
            title: $title,
            color: $color,
            badge: $badge,
            dense: $dense,
            subjectType: $subjectType,
            subjectId: $subjectId,
            cadenceLabel: $cadenceLabel,
            recurring: $recurring,
            occurrenceDate: $occurrenceDate,
        );
    }

    /**
     * Ordering key for merging heterogeneous sources into one list: by day, then all-day first, then by
     * time, then by id so the order is total and stable across identical instants.
     *
     * This is the ONLY place a timed occurrence is projected onto a calendar day, and the projection
     * never leaves this method — it decides sequence, it does not produce a rendered or stored value.
     */
    public function sortKey(string $timezone): string
    {
        // The '0'/'1' segment settles all-day-before-timed before the comparison ever reaches the
        // time segment, so the two shapes never have to be padded to a common width.
        if ($this->allDay) {
            return $this->startDate . '|0||' . $this->id;
        }

        $local = $this->startsAt->setTimezone($timezone);

        return $local->format('Y-m-d') . '|1|' . $local->format('H:i:s') . '|' . $this->id;
    }
}
