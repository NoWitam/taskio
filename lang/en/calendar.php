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

        // RECURRENCE (R3 B4). The cadence grammar lives in the shared layer and answers in CODES, with
        // no sentence in it — the app is PL+EN switchable, and every consumer of that grammar is talking
        // about a different subject (an automation's "fire time" is an event's "start"). What follows is
        // the Calendar's rendering of those codes, in the vocabulary of the NARROW subset this module
        // accepts.
        'recurrence' => [
            // Grammar codes the Calendar's subset can actually produce.
            'mode_required' => 'Choose how this part of the repeat rule works.',
            'list_required' => 'The chosen repeat rule needs a value in ":field".',
            'field_not_allowed' => 'The ":field" field does not belong to the chosen repeat rule. Remove it, or change the rule.',
            'special_required' => 'Choose which day of the month this rule means.',
            'special_needs_ordinal' => 'This rule needs to know which one in the month it is.',
            'special_needs_weekday' => 'This rule needs a day of the week.',

            // Codes the Calendar's subset cannot reach (the every-N step windows, the last-working-day
            // rule, exclusion keys this module never writes). Rendered rather than defaulted — having
            // no default arm is what makes a NEW code break here, instead of reaching a user as a blank
            // message beside a control that simply refuses to save.
            'unsupported' => 'The calendar does not support this kind of repeat rule.',

            // The subset: what is refused, and none of it is an economy. An "every N days" mode
            // compiles to a grid that RESETS every month, so it means something other than it says.
            'day_mode_not_supported' => 'The calendar does not support this way of repeating days.',
            'day_special_not_supported' => 'The calendar does not support this day-of-the-month rule.',
            'month_mode_not_supported' => 'The calendar does not support this way of repeating months.',
            'exclusion_key_not_accepted' => 'A repeating event skips chosen DATES. To skip weekdays or months, say so in the repeat rule itself.',

            // The series' hour is the event's own, and its timezone is stamped at save time — a caller
            // sets neither. Refused rather than dropped: whoever sends one believes they are setting it.
            'time_not_accepted' => 'A repeating event happens at the event\'s own time; there is no separate time to set.',
            'timezone_not_accepted' => 'A repeating event uses the workspace\'s timezone; it is not sent with the request.',

            // The anchor: the event's start MUST be the rule's first occurrence. Otherwise "start" stops
            // meaning start, and splitting a series stops being correct by construction.
            'anchor_not_an_occurrence' => 'The event must start on the first occurrence of its repeat rule. Change the start, or change the rule.',
            'whole_minute' => 'A repeating event has to start on a whole minute.',

            // The end of a series: a date or a number of repeats, never both.
            'end_is_one_thing' => 'Give either an end date or a number of repeats — not both.',
            'end_before_start' => 'The repeat cannot end before the event begins.',
            'count_unreachable' => 'This rule does not have that many occurrences within a reasonable horizon. Give an end date instead of a number of repeats.',

            // The anchor proves the CADENCE lands on the event's start. A series is that cadence MINUS
            // its skipped days, bounded by its own end date — and such a series can fall on no day at
            // all. Without this check the event saves with a 201 and simply never appears.
            'series_has_no_occurrences' => 'This series has no occurrences at all — the skipped days and the end date rule out every one. Change the end date, or the skipped days.',

            // The scope of an operation on a series.
            'scope_on_create' => 'A series scope applies to an existing event; there is nothing to narrow when creating one.',
            'occurrence_date_without_scope' => 'An occurrence date only means something when the operation is about a single occurrence, or about this one and the ones after it.',
            'occurrence_date_required' => 'Say which occurrence this is about.',
            'event_does_not_repeat' => 'This event does not repeat, so it has no single occurrences.',
            'not_an_occurrence' => 'This series has no occurrence on that day.',
            'occurrence_has_no_rule' => 'A single occurrence has no repeat rule of its own — editing one turns it into a separate, one-off event.',
            'split_starts_before_the_split' => 'The new series cannot start before the occurrence you split at — the two would draw the same days.',
            'exclusions_full' => 'This series already skips the most days it can (:max). Split the series instead of skipping more occurrences.',
        ],
    ],

    // The Calendar's own source, named here because the Calendar owns what it shows.
    'sources' => [
        'event' => 'Events',
    ],

    // R3 B5 — HOW OFTEN A SERIES REPEATS, as one sentence per occurrence.
    //
    // It is translated HERE, on the server, for the same reason a source's label and an occurrence's
    // badge are: the wire vocabulary of an occurrence is PROSE, so a client can render a series marker
    // for a source it has never heard of. A coded cadence would need a new client-side dictionary for
    // every source that grew one, and "a fifth source needs no frontend change" would quietly stop
    // being true.
    //
    // The Workflows module has its own cadence sentence and it is NOT reused: that one names the
    // INTERVAL of the two sub-daily modes ("Every 5 min"), which the Calendar's subset cannot express
    // at all, and it addresses somebody reading an automation rather than somebody planning a meeting.
    //
    // A NOTE FOR TRANSLATORS. The month suffix and the "last <weekday>" phrases are written out per
    // language rather than composed from a name plus an adjective, because languages that inflect
    // (Polish among them) cannot compose them: "ostatni poniedziałek" and "ostatnia środa" differ in
    // the ADJECTIVE, which no placeholder can supply.
    'cadence' => [
        'daily' => 'Daily',
        'weekly' => 'Weekly on :days',
        'monthly_days' => 'Monthly on day :days',
        'monthly_last_day' => 'Monthly on the last day',
        'monthly_nth_weekday' => 'Monthly on the :ordinal :weekday',
        'monthly_last_weekday' => 'Monthly on :weekday',

        // A MONTHLY RULE CONFINED TO ONE MONTH IS A YEARLY RULE, and has to say so. "Monthly on day 25,
        // in August" is true word by word and reads as "again next month" — on the most human preset
        // there is, a birthday or an anniversary. Someone plans a wedding anniversary and the grid tells
        // them it repeats in four weeks.
        //
        // Only the MONTHLY family collapses this way. "Daily, in August" and "Weekly on Mon, in August"
        // stay as they are: their cadence word is still true inside the month it names, so nothing about
        // them misleads.
        'yearly_days' => 'Every year on :month :days',
        'yearly_last_day' => 'Every year on the last day of :month',
        'yearly_nth_weekday' => 'Every year on the :ordinal :weekday of :month',
        'yearly_last_weekday' => 'Every year on :weekday of :month',

        // The month axis, when the series does NOT run every month. Never omitted when it bites:
        // "Weekly on Mon" is a lie about a rule that only fires in January.
        'in_months' => ':cadence, in :months',

        'separator' => ', ',

        // 0 = Sunday .. 6 = Saturday — the shared layer's convention, which is also Carbon's and cron's.
        'weekdays' => [
            '0' => 'Sun',
            '1' => 'Mon',
            '2' => 'Tue',
            '3' => 'Wed',
            '4' => 'Thu',
            '5' => 'Fri',
            '6' => 'Sat',
        ],

        'last_weekdays' => [
            '0' => 'the last Sunday',
            '1' => 'the last Monday',
            '2' => 'the last Tuesday',
            '3' => 'the last Wednesday',
            '4' => 'the last Thursday',
            '5' => 'the last Friday',
            '6' => 'the last Saturday',
        ],

        'ordinals' => [
            '1' => 'first',
            '2' => 'second',
            '3' => 'third',
            '4' => 'fourth',
            '5' => 'fifth',
        ],

        // Months as a STANDALONE NAME — for the list in `in_months`.
        'months' => [
            '1' => 'January',
            '2' => 'February',
            '3' => 'March',
            '4' => 'April',
            '5' => 'May',
            '6' => 'June',
            '7' => 'July',
            '8' => 'August',
            '9' => 'September',
            '10' => 'October',
            '11' => 'November',
            '12' => 'December',
        ],

        // Months as they appear INSIDE A DATE — "August 25", "the last day of August". Identical to the
        // list above in English and NOT in Polish, which puts a date's month in the genitive
        // ("25 sierpnia", never "25 sierpień"). Two catalogues rather than one, for the same reason
        // `last_weekdays` is written out: a language that inflects cannot compose the form from a name
        // plus a placeholder, and English paying twelve duplicated lines is the cost of the language
        // that cannot.
        'months_in_date' => [
            '1' => 'January',
            '2' => 'February',
            '3' => 'March',
            '4' => 'April',
            '5' => 'May',
            '6' => 'June',
            '7' => 'July',
            '8' => 'August',
            '9' => 'September',
            '10' => 'October',
            '11' => 'November',
            '12' => 'December',
        ],
    ],

];
