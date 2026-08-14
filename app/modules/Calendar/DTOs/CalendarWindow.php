<?php

namespace App\Modules\Calendar\DTOs;

use Carbon\CarbonImmutable;

/**
 * ONE calendar read: which span of days is on screen, in whose reckoning, and what budget a source
 * may spend answering it.
 *
 * THE SPAN IS A RANGE OF CALENDAR DAYS, NOT A RANGE OF INSTANTS. That distinction is the whole reason
 * this object exists rather than a pair of timestamps. The grid a user looks at is made of DAYS; some
 * subjects live on a day and nothing else (a task deadline is a `date` column — no hour, no zone),
 * while others are instants (a schedule fires at a moment in UTC). A window that carried only instants
 * would force every day-shaped subject through a conversion it has no information for, and a window
 * that carried only days would leave every instant-shaped subject to invent its own boundaries.
 *
 * So both readings are offered and each is exact for its own kind:
 *   - {@see $startDate} / {@see $endDate} — `Y-m-d`, INCLUSIVE, compared as text against date columns.
 *   - {@see startsAt()} / {@see endsAt()} — the same two days widened to their true UTC edges in
 *     {@see $timezone}, for comparing against timestamp columns and computed instants.
 *
 * The timezone is the WORKSPACE's (see CalendarTimezoneResolver), never the caller's: a team looking at
 * one grid must agree on where a day breaks, and a `tz` request parameter would make "whose midnight is
 * this" answerable two ways forever.
 */
final readonly class CalendarWindow
{
    public function __construct(
        /** Inclusive first day of the window, `Y-m-d`, as reckoned in {@see $timezone}. */
        public string $startDate,
        /** Inclusive last day of the window, `Y-m-d`, as reckoned in {@see $timezone}. */
        public string $endDate,
        /** IANA identifier the days above are reckoned in. */
        public string $timezone,
        /**
         * Source ids the caller asked for. EMPTY MEANS ALL — an absent filter is not an empty
         * selection, and treating it as one would render a blank calendar for every default request.
         *
         * @var array<int, string>
         */
        public array $sources = [],
        /** Free-text filter; each source decides what "matching" means for its own subjects. */
        public ?string $search = null,
        /**
         * How many occurrences ONE source item (one workflow, one recurring subject) may contribute.
         * Carried on the window rather than read from config by each source, so a source never has to
         * know a Calendar config key to stay inside its budget.
         */
        public int $maxOccurrencesPerItem = 64,
        /**
         * Ceiling on what a SINGLE source may return in total. The query service trims the merged set
         * to the same figure and reports the trim; this is what stops one source loading a hundred
         * thousand rows on the way there.
         */
        public int $maxOccurrences = 1000,
        /**
         * How many SOURCE ITEMS (automations, subjects) one source may examine. A separate budget from
         * the two above because it bounds a different thing: those cap the ANSWER, this caps the WORK.
         * A thousand sparse automations that each contribute one occurrence never trip an occurrence
         * ceiling, yet still cost a thousand projections on every month the user pages through.
         */
        public int $maxItems = 200,
    ) {}

    /** The instant the first day begins, in UTC. */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->startDate, $this->timezone)
            ->startOfDay()
            ->utc();
    }

    /** The instant the last day ends (inclusive, to the microsecond), in UTC. */
    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->endDate, $this->timezone)
            ->endOfDay()
            ->utc();
    }

    /** Whether this window asked for the given source. An empty filter asks for every source. */
    public function wants(string $sourceId): bool
    {
        return $this->sources === [] || in_array($sourceId, $this->sources, true);
    }

    public function hasSearch(): bool
    {
        return $this->search !== null && $this->search !== '';
    }
}
