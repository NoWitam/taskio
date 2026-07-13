<?php

namespace App\Modules\Workflows\Enums;

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
}
