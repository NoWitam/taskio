<?php

namespace App\Modules\Calendar\DTOs;

use App\Modules\Calendar\Enums\CalendarUnavailableReason;

/**
 * One answered calendar read: what is on the grid, plus everything the caller needs in order to know
 * whether it is the WHOLE answer.
 *
 * The last part is the point. A calendar that quietly returns the first thousand of four thousand
 * occurrences looks exactly like a calendar with a thousand occurrences, and the difference only
 * surfaces when somebody misses the event that was cut. Every way this answer can be partial is
 * therefore stated in the payload — {@see $truncations} per source and per kind,
 * {@see $unavailableSources} for a source that failed, and `dense` on the occurrences themselves so a
 * capped series is markable where it is drawn.
 */
final readonly class CalendarResult
{
    public function __construct(
        /** @var array<int, CalendarOccurrence> Sorted: by day, all-day first, then by time. */
        public array $occurrences,
        /** IANA identifier every day boundary in this answer was reckoned in. */
        public string $timezone,
        /**
         * Every loss in this answer, attributed to the source it belongs to and labelled with its
         * KIND. A source may contribute more than one (a densified item AND dropped items are
         * different facts about the same source), and the list is empty when the answer is complete.
         *
         * @var array<int, CalendarTruncation>
         */
        public array $truncations,
        /** @var array<string, string> Queried source id => translated label. */
        public array $sources,
        /**
         * Sources that were asked and could not answer, each with the REASON — reported rather than
         * hidden, because "nothing scheduled" and "the schedule source is broken" are different facts
         * and a user is entitled to tell them apart.
         *
         * The reason is carried, not just the id, for the same kind of argument that gave truncations a
         * `kind`: a bare list told the reader something was wrong and left them with no way to decide
         * whether to try again. {@see CalendarUnavailableReason} is a closed vocabulary a client can
         * branch on.
         *
         * @var array<string, CalendarUnavailableReason> source id => why it could not answer
         */
        public array $unavailableSources,
    ) {}

    /**
     * Whether ANYTHING was left out. A convenience roll-up for a client that only wants to decide
     * "show a banner or not" — deliberately DERIVED rather than stored, so it can never drift from the
     * list it summarizes, and never becomes the thing a caller reasons about instead of the detail.
     */
    public function isTruncated(): bool
    {
        return $this->truncations !== [];
    }
}
