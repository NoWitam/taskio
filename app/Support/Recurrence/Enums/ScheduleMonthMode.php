<?php

namespace App\Support\Recurrence\Enums;

/**
 * The MONTH axis of the compositional schedule descriptor — the WHICH-month restriction. Optional;
 * absent means every_month. Exactly one mode constrains the month field of the compiled cron (and,
 * for the bespoke last-working-day cadence, filters the months it may fire in):
 *
 *   - every_month:    no month restriction (month = *).
 *   - every_n_months: a month step ("month = *​/n"), with an OPTIONAL 1..12 window
 *                     ("month = from-to/n"). NOTE: "*​/n" resets every January (modulo-year),
 *                     so the gap across the year boundary can be shorter than n months.
 *   - months:         a set of months ("month = m1,m2,…").
 */
enum ScheduleMonthMode: string
{
    case EVERY_MONTH = 'every_month';
    case EVERY_N_MONTHS = 'every_n_months';
    case MONTHS = 'months';
}
