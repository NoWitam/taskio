<?php

namespace App\Modules\Workflows\Enums;

/**
 * The concrete rule of the DAY axis's `special` mode — a month-anchored day the plain dom/dow
 * fields cannot express:
 *
 *   - last_day:          the last calendar day of the month (cron dom = `L`).
 *   - nth_weekday:       the ordinal-th weekday of the month (cron dow = `{weekday}#{ordinal}`);
 *                        requires BOTH ordinal (1..5) and weekday (0..6).
 *   - last_weekday:      the last weekday of the month (cron dow = `{weekday}L`); requires weekday.
 *   - last_working_day:  the last Mon-Fri of the month — a BESPOKE cadence (the `LW` cron token is
 *                        broken in dragonmantank v3.6.0), computed in WorkflowScheduleService and
 *                        therefore RESTRICTED to time.mode=at (it fires at explicit HH:mm times,
 *                        never on a minute/hour grid).
 */
enum ScheduleDaySpecial: string
{
    case LAST_DAY = 'last_day';
    case LAST_WORKING_DAY = 'last_working_day';
    case NTH_WEEKDAY = 'nth_weekday';
    case LAST_WEEKDAY = 'last_weekday';

    /** Whether this rule needs a `weekday` param (0..6). */
    public function needsWeekday(): bool
    {
        return $this === self::NTH_WEEKDAY || $this === self::LAST_WEEKDAY;
    }

    /** Whether this rule needs an `ordinal` param (1..5). */
    public function needsOrdinal(): bool
    {
        return $this === self::NTH_WEEKDAY;
    }

    /**
     * Whether this rule is the bespoke last-working-day cadence, which fires at explicit HH:mm
     * times only and therefore requires time.mode=at (enforced by the validator).
     */
    public function requiresAtTime(): bool
    {
        return $this === self::LAST_WORKING_DAY;
    }

    /**
     * The params this special rule accepts (beyond `mode` + `special`), so a foreign key can be
     * rejected — mirrors the descriptor foreign-param guard the rest of the block uses.
     *
     * @return array<int, string>
     */
    public function allowedParams(): array
    {
        return match ($this) {
            self::NTH_WEEKDAY => ['ordinal', 'weekday'],
            self::LAST_WEEKDAY => ['weekday'],
            self::LAST_DAY, self::LAST_WORKING_DAY => [],
        };
    }
}
