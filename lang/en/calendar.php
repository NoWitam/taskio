<?php

return [

    // The Calendar module owns a contract, a registry — and, since R3 B3, ONE subject of its own: the
    // EVENT. Every user-facing string about a BORROWED subject (another source's name, an occurrence's
    // badge) is still translated by the module that owns that subject, which is what keeps a new source
    // from needing an entry here.
    'validation' => [
        'window_too_large' => 'A calendar view can cover at most :max days at a time.',

        // The all-day discriminator, enforced BOTH ways. An over-complete payload is refused rather
        // than silently narrowed: a caller that believes it stored a time, on an event the grid renders
        // as a day, has no way to discover the disagreement.
        'start_date_required' => 'An all-day event needs a date.',
        'starts_at_required' => 'An event that is not all-day needs a start time.',
        'instant_on_all_day' => 'An all-day event has no time of day. Remove it, or turn off all-day.',
        'day_on_timed' => 'An event with a start time has no separate date. Remove it, or turn on all-day.',
        'end_before_start' => 'An event cannot end before it begins.',

        // Colour on the grid states a MEANING another source can explain (a task's priority, how a run
        // ended). An event states none, so it has no colour to set — refused rather than dropped, so a
        // client that still sends one finds out instead of looking right and being wrong.
        'color_not_accepted' => 'An event has no colour of its own; the calendar shows every event the same.',

        // The optional pointer is both halves or neither: half a pointer addresses nothing while
        // looking like a reference somebody could follow.
        'subject_incomplete' => 'A linked item needs both its type and its id.',
    ],

    // The Calendar's own source, named here because the Calendar owns what it shows.
    'sources' => [
        'event' => 'Events',
    ],

];
