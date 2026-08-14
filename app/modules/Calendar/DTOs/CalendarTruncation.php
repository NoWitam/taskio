<?php

namespace App\Modules\Calendar\DTOs;

use App\Modules\Calendar\Enums\CalendarTruncationKind;

/**
 * ONE statement of the form "this source is not showing you everything, and here is what kind of
 * everything".
 *
 * The two counts are separate fields rather than one, because they count DIFFERENT THINGS and a single
 * `omitted` would silently change unit between kinds — occurrences for a window trim, automations for a
 * dropped-item report. A number whose meaning depends on a sibling field is a number that gets rendered
 * wrong. Either may be null, and null means "genuinely not known", never zero: a densified series is
 * capped precisely so its true length is never computed, and claiming a figure there would be inventing
 * one.
 */
final readonly class CalendarTruncation
{
    public function __construct(
        /** Id of the source this loss belongs to. Every loss belongs to exactly one. */
        public string $source,
        public CalendarTruncationKind $kind,
        /** How many OCCURRENCES are not shown, when that is countable. */
        public ?int $omittedOccurrences = null,
        /** How many SOURCE ITEMS (an automation, a subject) this loss concerns, when meaningful. */
        public ?int $affectedItems = null,
    ) {}
}
