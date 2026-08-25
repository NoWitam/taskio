<?php

namespace App\Support\Recurrence\Enums;

/**
 * WHAT IS WRONG WITH A DESCRIPTOR — as a CODE, never as a sentence.
 *
 * The shared layer decides whether a descriptor is well-formed; it does NOT decide what to tell a
 * human about it. Those are different jobs with different owners: the grammar is one thing and must
 * stay one thing, while the wording belongs to whichever screen the descriptor was typed on, in
 * whichever language that user reads. A shared layer that returned prose would have to pick a
 * language (this repo is PL+EN switchable) and a vocabulary (an automation's "fire time" is an
 * event's "start"), and every consumer after the first would either accept a message written for
 * somebody else's screen or re-derive the rule to write its own — which is the duplication the whole
 * shared layer exists to prevent.
 *
 * So: one grammar, N presentations. Each case below is a fact about the descriptor; the accompanying
 * RecurrenceViolation carries WHERE it was found and the parameters a message might interpolate.
 *
 * ADDING A CASE is a grammar change and costs every renderer a line. That is deliberate — a code no
 * module renders is an error a user never sees, so the renderers are asserted exhaustive by test
 * rather than left to fall through to a generic string.
 */
enum RecurrenceViolationCode: string
{
    /** A present day/month axis carries no `mode`. */
    case MODE_REQUIRED = 'mode_required';

    /** The chosen mode requires a scalar field that is missing or not numeric (context: `field`). */
    case MODE_FIELD_REQUIRED = 'mode_field_required';

    /** The chosen mode requires a non-empty list that is missing or empty (context: `field`). */
    case MODE_LIST_REQUIRED = 'mode_list_required';

    /** A key foreign to the chosen mode is present (context: `field`). */
    case FIELD_NOT_ALLOWED_FOR_MODE = 'field_not_allowed_for_mode';

    /** A window carries one bound but not the other — from/to are both-or-neither. */
    case WINDOW_INCOMPLETE = 'window_incomplete';

    /** An HH:mm window's start is not a well-formed wall-clock time. */
    case WINDOW_START_NOT_TIME = 'window_start_not_time';

    /** An HH:mm window's end is not a well-formed wall-clock time. */
    case WINDOW_END_NOT_TIME = 'window_end_not_time';

    /** An HH:mm window does not ascend — a window may not wrap midnight. */
    case WINDOW_TIMES_NOT_ASCENDING = 'window_times_not_ascending';

    /** An hour window's start is not an integer hour 0..23. */
    case WINDOW_START_NOT_HOUR = 'window_start_not_hour';

    /** An hour window's end is not an integer hour 0..23. */
    case WINDOW_END_NOT_HOUR = 'window_end_not_hour';

    /** An hour window does not ascend. */
    case WINDOW_HOURS_NOT_ASCENDING = 'window_hours_not_ascending';

    /** A numeric (day/month) window does not ascend. */
    case WINDOW_BOUNDS_NOT_ASCENDING = 'window_bounds_not_ascending';

    /** The `special` day mode names no rule. */
    case SPECIAL_REQUIRED = 'special_required';

    /** The chosen special rule needs an `ordinal` (context: `special`). */
    case SPECIAL_NEEDS_ORDINAL = 'special_needs_ordinal';

    /** The chosen special rule needs a `weekday` (context: `special`). */
    case SPECIAL_NEEDS_WEEKDAY = 'special_needs_weekday';

    /** The chosen special rule only exists at explicit fire times (context: `special`). */
    case SPECIAL_REQUIRES_AT_TIME = 'special_requires_at_time';

    /** An `exclusions` key outside { months, weekdays, dates } (context: `field`). */
    case EXCLUSION_KEY_NOT_ALLOWED = 'exclusion_key_not_allowed';

    /** The cadence and its exclusions rule out every occurrence — nothing would ever fire. */
    case NO_OCCURRENCE = 'no_occurrence';
}
