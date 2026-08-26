<?php

namespace App\Modules\Calendar\Support;

use App\Modules\Calendar\DTOs\CalendarRecurrence;
use App\Support\Recurrence\Enums\ScheduleDayMode;
use App\Support\Recurrence\Enums\ScheduleDaySpecial;
use App\Support\Recurrence\Enums\ScheduleLimits;
use App\Support\Recurrence\Enums\ScheduleMonthMode;

/**
 * HOW OFTEN AN EVENT REPEATS, as one translated sentence — "Weekly on Mon, Wed", "Monthly on the third
 * Tuesday, in January, July".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS INSTEAD OF THE ONE THE WORKFLOWS MODULE ALREADY HAS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * There is a cadence sentence in this codebase already, and reusing it was the obvious move. It is the
 * wrong one twice over, and the Calendar could not name it anyway (the module knows nobody):
 *
 *   IT NAMES THE WRONG AXIS. That renderer describes the INTERVAL of the two sub-daily modes — "Every
 *     5 min", "Every 2 h, 09:00–17:00" — and returns NULL for everything else, on the explicit ground
 *     that an honest sentence for a fixed-time schedule would have to humanize the day and month axes
 *     too. The Calendar's subset has NO sub-daily modes at all ({@see CalendarRecurrence}): its time
 *     axis is exactly one wall-clock hour by construction. So that renderer would return null for
 *     every calendar series that exists, forever.
 *   IT ADDRESSES THE WRONG READER. It is written for somebody reading an automation. This one is read
 *     by somebody who put a meeting on a grid.
 *
 * What the other renderer declined to build is precisely what is needed here, and it is affordable
 * here for a reason it stated itself: the Calendar's day axis is a NARROW, closed subset (every day,
 * named weekdays, named month-days, three month-anchored specials) and its month axis has two modes.
 * That is a bounded `match`, not a schedule-to-prose engine.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE MONTH AXIS IS PART OF THE SENTENCE, NOT AN ORNAMENT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The same warning the other renderer wrote down applies here with full force: "Weekly on Mon" is
 * FALSE about a rule whose month axis is `months: [1]`, and a marker that lies is worse than a marker
 * that is missing. So a restrictive month axis is always appended, and only `every_month` (or an
 * absent axis) is allowed to be silent.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT TAKES THE VALUE OBJECT, NOT A DESCRIPTOR ARRAY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A {@see CalendarRecurrence} has already been through the Calendar's own assertions, so this class
 * never has to ask whether it is looking at a rule this module can mean. It still refuses to invent a
 * sentence for a shape it does not recognise — every private helper returns null rather than a
 * plausible-looking half-truth, and a null label is an ordinary, supported outcome the wire already
 * carries (`cadence_label` is optional and often null).
 *
 * THE `match` OVER THE DAY MODE HAS NO DEFAULT ARM, deliberately, and this is the same discipline
 * CalendarRecurrenceService::message() applies to violation codes. A mode added to the shared grammar
 * AND admitted into the Calendar's subset without a sentence being written for it makes this throw at
 * the point of use — where the source registry catches it, marks the events source unavailable and
 * leaves the rest of the grid standing. A default arm would instead hand every series of the new kind
 * a silently missing marker, which nothing would ever report.
 *
 * The one thing it does NOT say is the series' END. "Weekly on Mon" is true of a series that stops in
 * March; when it stopped is a property of the SERIES, not of its cadence, and the grid already answers
 * it by having no squares after that day.
 */
class CalendarCadenceLabel
{
    /** The sentence for one series, or null when its shape has no sentence to give. */
    public function for(CalendarRecurrence $recurrence): ?string
    {
        $day = $recurrence->descriptor['day'] ?? null;
        $months = $this->namedMonths($recurrence->descriptor['month'] ?? null);

        // A MONTHLY RULE PINNED TO ONE MONTH IS A YEARLY RULE. Tried first, because the sentence it
        // replaces is TRUE and misleading — the failure a suffix cannot fix. Returns null for the
        // cadences that are not misleading, and those fall through to the ordinary path below.
        if ($months !== null && count($months) === 1) {
            $yearly = $this->yearlyCadence($day, $months[0]);

            if ($yearly !== null) {
                return $yearly;
            }
        }

        $cadence = $this->dayCadence($day);

        if ($cadence === null) {
            return null;
        }

        return $months === null ? $cadence : __('calendar.cadence.in_months', [
            'cadence' => $cadence,
            'months' => $this->join(array_map(
                fn (int $number): string => (string) __('calendar.cadence.months.' . $number),
                $months,
            )),
        ]);
    }

    /**
     * A MONTHLY cadence confined to ONE month, said as the yearly thing it is — or null when the day
     * axis is not one that would mislead.
     *
     * The distinction is exactly which word the cadence leads with. "Monthly on day 25, in August"
     * claims a period of a month and has one of a year; that is the sentence a person reads on a
     * birthday or an anniversary, which is the single most human thing this whole grammar is used for.
     * "Daily, in August" and "Weekly on Mon, in August" claim a period that is still true inside the
     * month they name, so they are left exactly as they are — rewriting them as yearly would trade a
     * misleading sentence for a false one.
     */
    private function yearlyCadence(mixed $day, int $month): ?string
    {
        if (!is_array($day)) {
            // An absent day axis means EVERY DAY, which is the un-misleading case: see above.
            return null;
        }

        // The month as it stands inside a DATE, which in an inflecting language is not the name in a
        // list — "25 sierpnia", never "25 sierpień".
        $inDate = (string) __('calendar.cadence.months_in_date.' . $month);

        return match (ScheduleDayMode::tryFrom((string) ($day['mode'] ?? ''))) {
            ScheduleDayMode::MONTH_DAYS => $this->yearlyDays($day['days'] ?? null, $inDate),
            ScheduleDayMode::SPECIAL => $this->special($day, $inDate),

            // The cadences whose own word survives the confinement, plus the ones outside this
            // module's subset. Named rather than defaulted, for the reason in the class docblock.
            ScheduleDayMode::EVERY_DAY, ScheduleDayMode::WEEKDAYS, ScheduleDayMode::EVERY_N_DAYS, null => null,
        };
    }

    /** "Every year on August 25". */
    private function yearlyDays(mixed $days, string $month): ?string
    {
        $days = $this->boundedList($days, ScheduleLimits::MONTH_DAY_MIN, ScheduleLimits::MONTH_DAY_MAX);

        if ($days === []) {
            return null;
        }

        return __('calendar.cadence.yearly_days', [
            'days' => $this->join(array_map(fn (int $day): string => (string) $day, $days)),
            'month' => $month,
        ]);
    }

    /**
     * The day axis as prose. An ABSENT axis means every day — the shared layer's own default, which is
     * why a descriptor is allowed to omit the key entirely.
     */
    private function dayCadence(mixed $day): ?string
    {
        if ($day === null) {
            return __('calendar.cadence.daily');
        }

        if (!is_array($day)) {
            return null;
        }

        return match (ScheduleDayMode::tryFrom((string) ($day['mode'] ?? ''))) {
            ScheduleDayMode::EVERY_DAY => __('calendar.cadence.daily'),
            ScheduleDayMode::WEEKDAYS => $this->weekly($day['weekdays'] ?? null),
            ScheduleDayMode::MONTH_DAYS => $this->monthlyDays($day['days'] ?? null),
            ScheduleDayMode::SPECIAL => $this->special($day),
            // The modulo cadence is outside the Calendar's subset (it compiles to a grid that RESETS
            // every month, so it means something other than it says), and a null is an unreadable
            // descriptor. Neither can be reached through a value object — named rather than defaulted,
            // so a mode added to the grammar cannot hide behind them.
            ScheduleDayMode::EVERY_N_DAYS, null => null,
        };
    }

    /** "Weekly on Mon, Wed". Null when the list is not one this module could have written. */
    private function weekly(mixed $weekdays): ?string
    {
        $days = $this->boundedList($weekdays, ScheduleLimits::WEEKDAY_MIN, ScheduleLimits::WEEKDAY_MAX);

        if ($days === []) {
            return null;
        }

        return __('calendar.cadence.weekly', [
            'days' => $this->join(array_map(
                fn (int $weekday): string => (string) __('calendar.cadence.weekdays.' . $weekday),
                $days,
            )),
        ]);
    }

    /** "Monthly on day 1, 15". */
    private function monthlyDays(mixed $days): ?string
    {
        $days = $this->boundedList($days, ScheduleLimits::MONTH_DAY_MIN, ScheduleLimits::MONTH_DAY_MAX);

        if ($days === []) {
            return null;
        }

        return __('calendar.cadence.monthly_days', [
            'days' => $this->join(array_map(fn (int $day): string => (string) $day, $days)),
        ]);
    }

    /**
     * The three month-anchored rules the Calendar accepts, in whichever of their two periods applies:
     * MONTHLY when the series runs every month, YEARLY when $month names the single month it is pinned
     * to. Every arm carries both keys in full rather than composing one from a prefix, so each sentence
     * this class can produce is findable by searching for it.
     *
     * The "last <weekday>" phrase is taken WHOLE from the lang file rather than composed from a
     * weekday name and a word for "last" — see the translators' note in lang/*\/calendar.php: the
     * adjective inflects with the weekday's gender in Polish, and no placeholder can supply that.
     *
     * @param  array<string, mixed>  $day
     * @param  string|null  $month  the month AS IT STANDS IN A DATE, or null for a monthly rule
     */
    private function special(array $day, ?string $month = null): ?string
    {
        $weekday = $this->bounded($day['weekday'] ?? null, ScheduleLimits::WEEKDAY_MIN, ScheduleLimits::WEEKDAY_MAX);
        $ordinal = $this->bounded($day['ordinal'] ?? null, ScheduleLimits::ORDINAL_MIN, ScheduleLimits::ORDINAL_MAX);

        return match (ScheduleDaySpecial::tryFrom((string) ($day['special'] ?? ''))) {
            ScheduleDaySpecial::LAST_DAY => $month === null
                ? __('calendar.cadence.monthly_last_day')
                : __('calendar.cadence.yearly_last_day', ['month' => $month]),

            ScheduleDaySpecial::NTH_WEEKDAY => $weekday === null || $ordinal === null ? null : ($month === null
                ? __('calendar.cadence.monthly_nth_weekday', [
                    'ordinal' => __('calendar.cadence.ordinals.' . $ordinal),
                    'weekday' => __('calendar.cadence.weekdays.' . $weekday),
                ])
                : __('calendar.cadence.yearly_nth_weekday', [
                    'ordinal' => __('calendar.cadence.ordinals.' . $ordinal),
                    'weekday' => __('calendar.cadence.weekdays.' . $weekday),
                    'month' => $month,
                ])),

            ScheduleDaySpecial::LAST_WEEKDAY => $weekday === null ? null : ($month === null
                ? __('calendar.cadence.monthly_last_weekday', [
                    'weekday' => __('calendar.cadence.last_weekdays.' . $weekday),
                ])
                : __('calendar.cadence.yearly_last_weekday', [
                    'weekday' => __('calendar.cadence.last_weekdays.' . $weekday),
                    'month' => $month,
                ])),

            // The bespoke last-working-day cadence is not in the Calendar's subset, and a null special
            // is an unreadable descriptor. Named, not defaulted, for the reason in the class docblock.
            ScheduleDaySpecial::LAST_WORKING_DAY, null => null,
        };
    }

    /**
     * The months the series is CONFINED TO, ascending — or NULL when it runs every month and the
     * sentence needs neither a suffix nor a yearly reading.
     *
     * Numbers rather than prose, because the caller has two uses for them and they are not the same
     * shape: a list to append, and a COUNT — one month is what turns a monthly cadence into a yearly
     * one, and a renderer handed a finished string could not tell one month from three.
     *
     * Null is "say nothing", never "unreadable" — an unreadable month axis also says nothing, because
     * the alternative would be to drop a cadence sentence that is correct about the day axis over a
     * suffix nobody can render.
     *
     * @return array<int, int>|null
     */
    private function namedMonths(mixed $month): ?array
    {
        if (!is_array($month) || ScheduleMonthMode::tryFrom((string) ($month['mode'] ?? '')) !== ScheduleMonthMode::MONTHS) {
            return null;
        }

        $months = $this->boundedList($month['months'] ?? null, ScheduleLimits::MONTH_MIN, ScheduleLimits::MONTH_MAX);

        return $months === [] ? null : $months;
    }

    /**
     * A stored list as ASCENDING, DISTINCT integers inside their axis bounds — and EMPTY the moment
     * anything in it is not.
     *
     * Ascending rather than as-stored so one rule reads the same sentence however the client happened
     * to order its checkboxes. Empty-on-anything-odd rather than filtered, because a partial list
     * renders a sentence about a cadence that is not the stored one, which is the single failure this
     * whole class is arranged to avoid.
     *
     * @return array<int, int>
     */
    private function boundedList(mixed $values, int $min, int $max): array
    {
        if (!is_array($values) || $values === []) {
            return [];
        }

        $bounded = [];

        foreach ($values as $value) {
            $number = $this->bounded($value, $min, $max);

            if ($number === null) {
                return [];
            }

            $bounded[$number] = $number;
        }

        ksort($bounded);

        return array_values($bounded);
    }

    /** One stored scalar as an integer inside its bounds, or null. */
    private function bounded(mixed $value, int $min, int $max): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number >= $min && $number <= $max ? $number : null;
    }

    /**
     * A list as one string, joined by the SEPARATOR THE LANGUAGE NAMES rather than a hard-coded comma —
     * the same reason every other user-facing fragment in this module comes out of the lang file.
     *
     * @param  array<int, string>  $parts
     */
    private function join(array $parts): string
    {
        return implode((string) __('calendar.cadence.separator'), $parts);
    }
}
