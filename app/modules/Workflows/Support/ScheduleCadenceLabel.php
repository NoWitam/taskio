<?php

namespace App\Modules\Workflows\Support;

use App\Modules\Workflows\Enums\ScheduleTimeMode;

/**
 * How often a schedule repeats, in the reader's language — "Every 5 min", "Every 2 h, 09:00–17:00".
 *
 * It exists to make the calendar's density marker sayable. `dense: true` on its own is half a
 * sentence: a grid can say "there is more of this than you are seeing" but not how much more, so the
 * best a client could write was "Series — showing 64", which tells a reader nothing they could not
 * have counted. The schedule descriptor knows the interval, so the module that owns the descriptor
 * says it — translated, because a coded cadence would need a new client-side vocabulary for every
 * source that grew one.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT NAMES THE INTERVAL, NOT THE SCHEDULE — AND ONLY WHEN THERE IS ONE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Only the two INTERVAL modes get a label. `at` (a list of fixed wall-clock times) returns null, and
 * that is a correctness decision rather than an omission: an honest sentence for `at` would have to
 * include the DAY and MONTH axes ("daily at 09:00" is a lie for a schedule whose day axis is
 * `month_days: [1]`), and humanizing all three axes is a full schedule-to-prose engine — a different
 * piece of work, and not one the density marker needs. An interval claim does not have that problem:
 * "every 5 minutes" is true of the series wherever the other axes let it run, because those axes bound
 * WHEN the series happens, never how far apart its firings are.
 *
 * The optional from/to window IS included, because it is part of the interval's own mode and materially
 * changes what the marker means — "every 5 min" reads very differently from "every 5 min, 09:00–17:00"
 * when you are looking at a day with 64 dots on it.
 *
 * Returning null is ordinary and expected. The calendar carries the field as optional; a subject with
 * no cadence simply has none.
 */
class ScheduleCadenceLabel
{
    /**
     * The cadence of one schedule descriptor's TIME axis, or null when it has no interval to state.
     *
     * @param  array<string, mixed>  $schedule  the v2 compositional descriptor (`trigger_config.schedule`)
     */
    public function for(array $schedule): ?string
    {
        $time = $schedule['time'] ?? null;

        if (!is_array($time)) {
            return null;
        }

        return match (ScheduleTimeMode::tryFrom((string) ($time['mode'] ?? ''))) {
            ScheduleTimeMode::EVERY_MINUTES => $this->interval('minutes', $time, 'minutes'),
            ScheduleTimeMode::EVERY_HOURS => $this->interval('hours', $time, 'hours'),
            // `at` and an unknown/absent mode: see the class docblock.
            default => null,
        };
    }

    /**
     * "Every :count min" plus the mode's optional window.
     *
     * A non-positive or absent count yields null rather than "Every 0 min" — the compiler would refuse
     * such a descriptor anyway, so this is about never emitting nonsense from a row that got past it.
     *
     * @param  array<string, mixed>  $time
     */
    private function interval(string $unit, array $time, string $countKey): ?string
    {
        $count = $time[$countKey] ?? null;

        if (!is_numeric($count) || (int) $count < 1) {
            return null;
        }

        $label = __('workflows.calendar.cadence.every_' . $unit, ['count' => (int) $count]);

        $window = $this->window($time, $unit);

        return $window === null
            ? $label
            : __('workflows.calendar.cadence.within', ['cadence' => $label, 'window' => $window]);
    }

    /**
     * The mode's optional active window as "09:00–17:00", or null when it is open-ended.
     *
     * The two interval modes spell their bounds differently — `every_minutes` uses HH:mm strings,
     * `every_hours` uses plain 0..23 hour numbers — so the hour form is padded back to a wall-clock
     * time rather than printed as a bare integer next to something that looks like one.
     *
     * @param  array<string, mixed>  $time
     */
    private function window(array $time, string $unit): ?string
    {
        $from = $time['from'] ?? null;
        $to = $time['to'] ?? null;

        if ($from === null || $to === null) {
            return null;
        }

        $format = fn (mixed $bound): ?string => $unit === 'hours'
            ? (is_numeric($bound) ? sprintf('%02d:00', (int) $bound) : null)
            : (is_string($bound) && $bound !== '' ? $bound : null);

        $from = $format($from);
        $to = $format($to);

        return $from === null || $to === null ? null : $from . '–' . $to;
    }
}
