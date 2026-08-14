<?php

namespace App\Modules\Calendar\DTOs;

use App\Modules\Calendar\Enums\CalendarColor;

/**
 * The small chip a source may hang on one occurrence — a task's status, a run's state.
 *
 * THE LABEL IS ALREADY TRANSLATED PROSE, not a machine code. That is a deliberate inversion of the
 * house default (the frontend usually words stable server codes itself), and it is what makes the
 * calendar's central promise hold: a FOURTH source must be addable without touching the Calendar
 * module OR the calendar screen. If badges were codes, every new source would ship a new vocabulary
 * the frontend had to learn — and "no frontend change" would quietly become false on the first one.
 * A source that knows its own domain also knows its own words; it translates them and hands over
 * something renderable.
 */
final readonly class CalendarBadge
{
    public function __construct(
        public string $label,
        public CalendarColor $color = CalendarColor::NEUTRAL,
    ) {}
}
