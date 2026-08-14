<?php

namespace App\Modules\Calendar\Enums;

/**
 * WHAT KIND of thing is missing from a calendar answer.
 *
 * A single `truncated: true` was the previous contract, and it was not good enough: the three losses
 * below are structurally different, they need different words on screen, and two of them are not even
 * about the window the user is looking at. Whatever a client rendered from one boolean would have been
 * wrong in two cases out of three — "showing the first 1000" is a lie about a workspace whose problem
 * is that twelve automations are absent entirely.
 *
 * This vocabulary is CLOSED and owned by the Calendar module. A new source cannot add a kind — it
 * describes its loss in these terms or it has no loss to describe — so a client can word three fixed
 * cases without ever learning anything per-source.
 */
enum CalendarTruncationKind: string
{
    /**
     * The response-wide occurrence ceiling cut the LATE END of the window. Everything shown is
     * complete up to a point in time; past that point the grid is empty because it was cut, not
     * because nothing is there. Countable exactly, and always reported with its count.
     */
    case WINDOW_TRIMMED = 'window_trimmed';

    /**
     * ONE item repeats more often than the occurrences shown for it — a minute-cadence automation
     * against a per-item budget of 64. What is on screen is a SAMPLE of that item's series, and the
     * occurrences themselves also carry `dense: true` so the grid can mark them individually.
     */
    case ITEM_DENSIFIED = 'item_densified';

    /**
     * Whole ITEMS are missing from the ENTIRE window — not the far end of it. The user is not looking
     * at a partial view of everything; they are looking at a complete view of some things and no view
     * at all of others. This is the loss a single global boolean described worst.
     */
    case ITEMS_DROPPED = 'items_dropped';
}
