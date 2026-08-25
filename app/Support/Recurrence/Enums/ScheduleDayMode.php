<?php

namespace App\Support\Recurrence\Enums;

/**
 * The DAY axis of the compositional schedule descriptor — the WHICH-day restriction. Optional;
 * absent means every_day. Exactly one mode constrains EITHER the day-of-month OR the day-of-week
 * field of the compiled cron (never both at once), so the classic cron dom/dow OR-trap cannot
 * arise:
 *
 *   - every_day:    no day restriction (dom = *, dow = *).
 *   - every_n_days: a day-of-month step ("dom = *​/n"), with an OPTIONAL 1..31 window.
 *   - weekdays:     a set of weekdays ("dow = d1,d2,…"; dom = *).
 *   - month_days:   a set of calendar days ("dom = d1,d2,…"; dow = *).
 *   - special:      a month-anchored rule (last day / nth or last weekday / last working day) —
 *                   see ScheduleDaySpecial.
 */
enum ScheduleDayMode: string
{
    case EVERY_DAY = 'every_day';
    case EVERY_N_DAYS = 'every_n_days';
    case WEEKDAYS = 'weekdays';
    case MONTH_DAYS = 'month_days';
    case SPECIAL = 'special';

    /**
     * The keys this mode OWNS. `special` is the one mode whose real vocabulary depends on the RULE
     * rather than on the mode (see ScheduleDaySpecial::allowedParams()), so the entry here is the
     * superset and the rule narrows it.
     *
     * @return array<int, string>
     */
    public function allowedKeys(): array
    {
        return match ($this) {
            self::EVERY_DAY => ['mode'],
            self::EVERY_N_DAYS => ['mode', 'n', 'from', 'to'],
            self::WEEKDAYS => ['mode', 'weekdays'],
            self::MONTH_DAYS => ['mode', 'days'],
            self::SPECIAL => ['mode', 'special', 'ordinal', 'weekday'],
        };
    }
}
