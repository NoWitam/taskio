<?php

namespace App\Support\Recurrence\Enums;

/**
 * The ONE place the compositional schedule descriptor's numeric bounds live. Every limit the
 * validator enforces and the compiler assumes is a constant here, so the FE builder can mirror
 * them verbatim (a single contract) and a bound can never drift between the two ends.
 *
 * The descriptor is three independent axes — { time, day, month } — each with its own modes. A
 * bound named for an axis field (e.g. EVERY_MINUTES_*, MONTH_DAY_*, WEEKDAY_*) applies wherever
 * that field appears. Ranges are inclusive.
 *
 * WEEKDAY convention: 0 = Sunday .. 6 = Saturday — matches Carbon::dayOfWeek AND cron dow 0=Sunday,
 * consistent across the whole module. The COUNT limits (…_LIST_MAX) cap how many distinct entries a
 * set may carry so a set can never name every value (which would make the schedule unfireable).
 */
final class ScheduleLimits
{
    // time.mode = at: a set of 1..6 distinct wall-clock fire times.
    public const MAX_AT_TIMES = 6;

    // time.mode = every_minutes: a minute step, with an OPTIONAL HH:mm window.
    public const EVERY_MINUTES_MIN = 1;

    public const EVERY_MINUTES_MAX = 59;

    // time.mode = every_hours: an hour step (0..23 window is expressed in whole hours).
    public const EVERY_HOURS_MIN = 1;

    public const EVERY_HOURS_MAX = 23;

    public const MINUTE_MIN = 0;

    public const MINUTE_MAX = 59;

    public const HOUR_MIN = 0;

    public const HOUR_MAX = 23;

    // day.mode = every_n_days: a day-of-month step (1..31), with an OPTIONAL 1..31 window.
    public const EVERY_N_DAYS_MIN = 1;

    public const EVERY_N_DAYS_MAX = 31;

    // day.mode = month_days: a set of 1..31 distinct calendar days.
    public const MONTH_DAY_MIN = 1;

    public const MONTH_DAY_MAX = 31;

    public const MONTH_DAYS_LIST_MAX = 31;

    // day.mode = weekdays: a set of 1..7 distinct weekdays (0..6).
    public const WEEKDAY_MIN = 0;

    public const WEEKDAY_MAX = 6;

    public const WEEKDAYS_LIST_MAX = 7;

    // day.mode = special + nth_weekday: the ordinal occurrence (1st..5th) of a weekday.
    public const ORDINAL_MIN = 1;

    public const ORDINAL_MAX = 5;

    // month.mode = every_n_months: a month step (1..12), with an OPTIONAL 1..12 window.
    public const EVERY_N_MONTHS_MIN = 1;

    public const EVERY_N_MONTHS_MAX = 12;

    // month.mode = months: a set of 1..12 distinct months.
    public const MONTH_MIN = 1;

    public const MONTH_MAX = 12;

    public const MONTHS_LIST_MAX = 12;

    // exclusions: a skip filter, structurally bounded so it can never exclude EVERY value
    // (months capped at 11, weekdays at 6). Dates are an absolute cap.
    public const EXCLUSIONS_MONTHS_MAX = 11;

    public const EXCLUSIONS_WEEKDAYS_MAX = 6;

    public const EXCLUSIONS_DATES_MAX = 50;
}
