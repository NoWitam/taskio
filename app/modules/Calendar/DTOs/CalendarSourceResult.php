<?php

namespace App\Modules\Calendar\DTOs;

/**
 * What ONE source returns: its occurrences, and an honest account of anything it could not show.
 *
 * The account travels WITH the answer rather than being inferred from it, because a source is the only
 * thing that knows what it left out. Nobody downstream can look at 64 occurrences and tell whether that
 * is the whole series or the first slice of sixty thousand — and a caller that has to guess will guess
 * "complete", which is the one answer that is never safely wrong.
 *
 * A source with nothing to declare uses {@see complete()} and never thinks about truncation again.
 */
final readonly class CalendarSourceResult
{
    public function __construct(
        /** @var array<int, CalendarOccurrence> */
        public array $occurrences,
        /** @var array<int, CalendarTruncation> */
        public array $truncations = [],
    ) {}

    /**
     * Everything this source has in the window, with nothing left out.
     *
     * @param  array<int, CalendarOccurrence>  $occurrences
     */
    public static function complete(array $occurrences): self
    {
        return new self($occurrences);
    }
}
