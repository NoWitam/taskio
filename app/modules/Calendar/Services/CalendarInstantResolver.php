<?php

namespace App\Modules\Calendar\Services;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * WHICH MOMENT A PIECE OF TEXT NAMES — the one rule, in the one place every writer uses.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE RULE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   input WITH a zone     → taken EXACTLY as given. A caller that wrote `+02:00`, `Z` or a full
 *                           identifier has already said what it means, and the workspace's clock is an
 *                           interpretation of SILENCE, never a correction of speech.
 *   input WITHOUT a zone  → read on the WORKSPACE's clock ({@see CalendarTimezoneResolver}). It is the
 *                           only reading that makes a write agree with the grid that will draw it: a
 *                           bare "14:30" means the same wall time the reader will see.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT LIVES HERE AND NOT IN THE REQUEST THAT FIRST NEEDED IT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * This rule was written once, inside StoreCalendarEventRequest, to close a defect where a Warsaw team
 * booking 14:30 stored 14:30Z and read it back as 16:30. It closed the HTTP door. It did not close the
 * other one: the `create_event` workflow step writes the same table through the same DTO and parsed its
 * own dates with a bare `Carbon::parse()`, which reads `config('app.timezone')` — so the same text put
 * through the two doors produced two different instants, two hours apart, with nothing disagreeing out
 * loud. Two copies of a rule this quiet do not stay in step; one of them just stops being maintained.
 *
 * So the rule sits in the Calendar module, next to the resolver that answers the other half of the same
 * question, and both writers call it. Workflows may name the Calendar (that direction is sanctioned and
 * pinned by CalendarModuleBoundaryTest); the Calendar names nobody.
 *
 * The explicit-zone branch is spelled out ({@see carriesExplicitZone}) rather than left to Carbon's rule
 * that a `$tz` argument is ignored when the string carries its own. That rule is real and currently
 * gives the right answer — but it is invisible at the call site, and "an explicit offset wins" must not
 * depend on a reader knowing a library subtlety.
 *
 * FAIL-SOFT throughout: an unparseable value yields null rather than throwing. Every caller has a better
 * answer for that than a 500 — a 422 the validator is already collecting, or a step failure naming the
 * field.
 */
class CalendarInstantResolver
{
    public function __construct(private CalendarTimezoneResolver $timezone) {}

    /**
     * The absolute moment the text names, normalized to UTC.
     *
     * After this returns, the fact that the caller may never have said which clock it meant is gone for
     * good — which is why the ambiguity has to be resolved here and cannot be deferred to a DTO or a
     * service downstream.
     */
    public function toUtc(string $value): ?CarbonImmutable
    {
        return $this->parse($value)?->utc();
    }

    /**
     * The calendar DAY the text falls on, as the plain 'Y-m-d' an all-day row stores, reckoned on the
     * WORKSPACE's clock.
     *
     * That last part is the whole content of this method, and it is the same answer the rest of the
     * module gives. A grid is drawn in the workspace's zone; a run that fires at 23:30Z is drawn on the
     * 13th in Warsaw. An all-day event derived from that instant that printed the 12th — because it was
     * re-printed in the value's own offset — sat one square behind everything else the same moment
     * produced, and nothing said why.
     *
     * A bare day still round-trips, on ANY workspace including one west of UTC: it names no zone, so it
     * is read as midnight on the workspace's own clock and prints as the day it says.
     */
    public function toDay(string $value): ?string
    {
        return $this->parse($value)
            ?->setTimezone($this->timezone->resolve())
            ->format('Y-m-d');
    }

    /**
     * Whether the caller SAID which clock it meant — a trailing offset (`+02:00`), an abbreviation
     * (`Z`, `UTC`) or a full identifier (`Europe/Warsaw`).
     *
     * Read from `date_parse()`'s `zone_type`, which is the parser's own account of what it found, so
     * this cannot drift from what the subsequent parse actually does. A null `zone_type` means the
     * string carried no zone at all — and only then does the workspace's clock get a say. (An
     * unparseable string reports a `zone_type` of 0, which lands here as "explicit"; the parse below
     * then fails and the caller gets null either way.)
     */
    public function carriesExplicitZone(string $value): bool
    {
        return (date_parse($value)['zone_type'] ?? null) !== null;
    }

    /** The parsed value in whichever zone the rule says it belongs to, or null when it is not a time. */
    private function parse(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $value,
                $this->carriesExplicitZone($value) ? null : $this->timezone->resolve(),
            );
        } catch (Throwable) {
            return null;
        }
    }
}
