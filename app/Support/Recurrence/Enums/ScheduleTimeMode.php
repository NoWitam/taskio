<?php

namespace App\Support\Recurrence\Enums;

/**
 * The TIME axis of the compositional schedule descriptor — the WHEN-in-the-day. Exactly one mode
 * is chosen; each owns its own fields (grammar in RecurrenceDescriptorValidator, compiled by
 * ScheduleCompiler):
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

    /**
     * The keys this mode OWNS — anything else in the block is foreign and is a validation error, not
     * a silent no-op. Stated on the enum, next to the mode itself and next to the special rule's
     * allowedParams(), so a mode gaining a field cannot gain it in one consumer's validator and not
     * another's.
     *
     * @return array<int, string>
     */
    public function allowedKeys(): array
    {
        return match ($this) {
            self::AT => ['mode', 'at'],
            self::EVERY_MINUTES => ['mode', 'minutes', 'from', 'to'],
            self::EVERY_HOURS => ['mode', 'hours', 'minute', 'from', 'to'],
        };
    }
}
