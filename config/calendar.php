<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Window
    |--------------------------------------------------------------------------
    |
    | max_window_days  Largest span ONE calendar read may ask for, in inclusive days. Above it the
    |                  request is refused with a 422 rather than served slowly.
    |
    |                  62 is not a round number, it is the smallest one that covers every view the
    |                  product has: a month GRID is six weeks (42 days, because a 31-day month starting
    |                  on a Sunday spills into a sixth row), and an AGENDA is a calendar month (31).
    |                  62 clears both with room for a client that pre-fetches a neighbouring week, and
    |                  is far short of the span at which computed occurrences stop being affordable.
    |
    |                  It is a REFUSAL, not a clamp. Silently narrowing a 90-day request would return a
    |                  calendar that looks complete and is not — the exact failure this whole module
    |                  reports rather than commits.
    |
    */

    'max_window_days' => (int) env('CALENDAR_MAX_WINDOW_DAYS', 62),

    /*
    |--------------------------------------------------------------------------
    | Occurrence caps
    |--------------------------------------------------------------------------
    |
    | The numbers here exist because of arithmetic, not caution. The workflow scheduler's minimum
    | cadence is ONE MINUTE. A single such workflow projected across a six-week grid is 42 × 24 × 60 =
    | 60 480 occurrences; ten of them is six hundred thousand, and the browser stops responding on every
    | month the user pages through. Nothing in the schedule engine bounds this — it is bounded by count
    | at the call site or not at all.
    |
    | max_occurrences_per_source_item  How many occurrences ONE source item (one workflow, one recurring
    |                  subject) may contribute. 64 is a little over two per day on a month grid: enough
    |                  that an ordinary daily or twice-daily schedule is shown in FULL, and far below the
    |                  point where one automation's dots are the only thing on screen. An item that
    |                  overruns has its occurrences flagged `dense: true`, so the grid can say "this
    |                  repeats more often than this" instead of implying the sample is the whole series.
    |
    | max_occurrences  Ceiling on ONE response, across all sources. Applied AFTER the merged set is
    |                  ordered, so what survives is the EARLIEST part of the window rather than whichever
    |                  source registered first, and the response carries `meta.truncated` when it bites.
    |                  1000 is roughly 24 per day on a six-week grid — past that a month view is not a
    |                  thing a person reads anyway, and the payload is still small.
    |
    |                  One caveat this figure does not express: a source may ALSO stop early on the same
    |                  ceiling while building, and what it drops then is whatever it had not reached yet
    |                  — for the schedule source, whole automations from the end of its name-ordered
    |                  list, rather than the far end of the window. `meta.truncated` reports THAT
    |                  something was cut and cannot report which of the two shapes it was.
    |
    | Both caps are VISIBLE in the response by design. A cap that pretends to be a complete answer is
    | the same defect in a different costume: the reader has no way to know what they were not shown.
    |
    */

    'max_occurrences_per_source_item' => (int) env('CALENDAR_MAX_OCCURRENCES_PER_SOURCE_ITEM', 64),

    'max_occurrences' => (int) env('CALENDAR_MAX_OCCURRENCES', 1000),

    /*
    |--------------------------------------------------------------------------
    | Work cap
    |--------------------------------------------------------------------------
    |
    | max_source_items  How many SOURCE ITEMS — automations, subjects — one source may examine for one
    |                   read. It bounds a different thing from the occurrence caps above: those cap the
    |                   ANSWER, this caps the WORK.
    |
    |                   The two come apart precisely where it hurts. A thousand SPARSE automations each
    |                   contributing one occurrence never trip an occurrence ceiling, so nothing above
    |                   would ever bite — yet each one still costs a full projection through the cron
    |                   engine (~0.3 ms measured), on a screen the client re-fetches on every month
    |                   navigation. An occurrence cap cannot bound that, because the cost is paid
    |                   BEFORE anyone knows how many occurrences there were.
    |
    |                   200 is far above any plausible real workspace (a workspace with two hundred
    |                   scheduled automations firing inside one 62-day window is not a workspace anyone
    |                   is reading a calendar for) and low enough to keep the worst case bounded. When
    |                   it bites, the loss is reported as `items_dropped` — whole automations missing
    |                   from the whole grid, which is a different statement from "the far end of the
    |                   window was cut" and is reported as one.
    |
    */

    'max_source_items' => (int) env('CALENDAR_MAX_SOURCE_ITEMS', 200),

    /*
    |--------------------------------------------------------------------------
    | Recurrence
    |--------------------------------------------------------------------------
    |
    | recurrence_count_max  The largest "repeat N times" a recurring event may ask for. A WORK budget,
    |                   like max_source_items above, and paid at WRITE time rather than at read: a
    |                   count is resolved into an end DATE once, at save, by walking the cadence N
    |                   times — one real projection through the cron engine each (~0.3 ms measured).
    |                   Storing the count instead would move that cost to every read, and multiply it
    |                   by every window the user pages through.
    |
    |                   366 is one year of a DAILY series, which is the densest thing a person expresses
    |                   as a count; anything longer is expressed as an end date, which costs nothing to
    |                   store and nothing to resolve. At the measured rate the worst case is roughly a
    |                   tenth of a second on one write, which is a save, not a screen.
    |
    |                   It is a REFUSAL, not a clamp — the same rule the window follows. Silently
    |                   shortening "repeat 500 times" would produce a series that ends where nobody
    |                   said, and the row would carry no trace of the number that was asked for.
    |
    */

    'recurrence_count_max' => (int) env('CALENDAR_RECURRENCE_COUNT_MAX', 366),

];
