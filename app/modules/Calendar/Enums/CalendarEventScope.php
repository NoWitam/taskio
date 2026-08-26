<?php

namespace App\Modules\Calendar\Enums;

/**
 * HOW MUCH OF A SERIES AN EDIT OR A DELETE APPLIES TO.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE DEFAULT IS THE WHOLE SERIES, AND THE DEFAULT IS TODAY'S CONTRACT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A `PUT` or `DELETE` that names no scope behaves EXACTLY as it did before series existed — a
 * whole-event write, a whole-row soft delete. That is not a convenience default, it is a compatibility
 * guarantee, and it is pinned by test rather than left to reading. Every existing client keeps working
 * because the shape it sends is still the shape it sent.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY EDITING A RULE HAS TO BE ABLE TO SPLIT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A series stores ONE rule for its whole life, so changing that rule in place REWRITES THE PAST:
 * moving a weekly meeting from Mondays to Wednesdays would turn last year's Mondays into Wednesdays,
 * in the grid, in every screenshot of it, with nothing anywhere recording that they had ever been
 * Mondays. {@see FOLLOWING} is the answer, and it is a composition of things this module already has —
 * an end date on the old series, plus a new event — rather than a new concept.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A DAY IDENTIFIES AN OCCURRENCE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Both scoped operations name the occurrence they are about with a plain `Y-m-d` day, because a
 * Calendar series has exactly one hour and therefore at most one occurrence per day. The mechanism
 * {@see OCCURRENCE} uses — the shared engine's date exclusion — removes a whole DAY rather than an
 * instant, which is the same thing only while that stays true. It is one more reason the accepted
 * cadence subset is narrow ({@see \App\Modules\Calendar\DTOs\CalendarRecurrence}).
 */
enum CalendarEventScope: string
{
    /**
     * The whole event. The default, and byte-for-byte the behaviour that existed before recurrence:
     * `PUT` rewrites the row, `DELETE` soft-deletes it, and the series goes with it.
     */
    case SERIES = 'series';

    /**
     * ONE occurrence, named by its day.
     *
     * DELETE excludes that day from the rule. PUT excludes it AND creates a plain, non-repeating event
     * carrying the edited payload — a DETACH, not an override: there is no overrides table, and the
     * detached event is an ordinary row nothing has to know is special.
     */
    case OCCURRENCE = 'occurrence';

    /**
     * This occurrence and every later one — the SPLIT. The old series is closed the day before, and a
     * new event carries the payload from this occurrence onward.
     *
     * When the split would leave NOTHING behind — typically because the named occurrence is the
     * series' first — there is no past to preserve and this collapses to {@see SERIES}: the row is
     * edited in place rather than closed to nothing and replaced. "Nothing behind" is asked of the
     * series, not inferred from the date, because a series whose first occurrence has since been
     * removed still has an anchor earlier than the split and would otherwise be closed to an empty span.
     */
    case FOLLOWING = 'following';

    /** Whether this scope is about ONE named occurrence rather than the whole event. */
    public function needsOccurrenceDate(): bool
    {
        return $this !== self::SERIES;
    }

    /**
     * The wire values, for a validation rule that must not spell them itself.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $scope): string => $scope->value, self::cases());
    }
}
