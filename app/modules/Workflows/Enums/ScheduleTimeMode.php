<?php

namespace App\Modules\Workflows\Enums;

/**
 * The TIME axis of the compositional schedule descriptor — the WHEN-in-the-day. Exactly one mode
 * is chosen; each owns its own fields (validated by WorkflowScheduleRulesValidator, compiled by
 * WorkflowScheduleCompiler):
 *
 *   - at:            fires at each of a set of wall-clock times ("at": ["09:00","17:00"]).
 *   - every_minutes: fires on a wall-clock minute grid ("*​/n"), with an OPTIONAL HH:mm window.
 *   - every_hours:   fires on a wall-clock every-n-hours grid ("m" past every n hours), with an
 *                    OPTIONAL 0..23 hour window.
 *
 * The time axis is the ONLY required axis; day and month default to every_day / every_month.
 */
enum ScheduleTimeMode: string
{
    case AT = 'at';
    case EVERY_MINUTES = 'every_minutes';
    case EVERY_HOURS = 'every_hours';
}
