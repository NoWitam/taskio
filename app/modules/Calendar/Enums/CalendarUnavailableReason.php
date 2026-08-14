<?php

namespace App\Modules\Calendar\Enums;

/**
 * WHY a source could not answer.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS A CODE AND NOT A SENTENCE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `unavailable_sources` used to be a bare list of ids: the user learned that the schedules had not
 * loaded and nothing else. That is a shrug in API form — the one thing a person actually wants to know
 * at that moment is whether to try again, and the list could not say.
 *
 * The registry has always DISTINGUISHED the three ways a source fails; it simply threw the distinction
 * away at the boundary. This carries it out. Prose would not do: a client has to branch on the answer
 * (offer "retry" or not), and prose can only be displayed. The vocabulary is CLOSED and owned by the
 * Calendar for the same reason {@see CalendarTruncationKind} is — a new source describes its failure in
 * these terms or has none to describe, so a client words a fixed set of cases and never learns anything
 * per-source.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE AXIS THAT MATTERS IS "IS TRYING AGAIN WORTH ANYTHING"
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   {@see FAILED}          — yes. Ask again; it may have been a bad minute.
 *   {@see NOT_CONSTRUCTED} — no. Nothing about this request will change the answer.
 *   {@see MALFORMED}       — no. The source is answering, wrongly; only a deploy fixes it.
 */
enum CalendarUnavailableReason: string
{
    /**
     * The source could not be brought up AT ALL: its factory threw, or it was registered under an id it
     * does not claim (which the registry refuses, because occurrences no filter can select are worse
     * than none).
     *
     * An id that is not registered lands here too. Structurally it cannot reach a calendar read — the
     * query service asks the registry which sources exist and then asks only those — but "it never came
     * up" is the honest description of that case as well, and leaving a hole for an unreachable branch
     * is how unreachable branches become reachable.
     *
     * Retrying is pointless: this is configuration or a broken boot, identical on the next request.
     */
    case NOT_CONSTRUCTED = 'not_constructed';

    /**
     * The source was asked and THREW. A failed query, a timeout, a module having a bad minute.
     *
     * The only reason of the three where trying again is a reasonable thing for a user to do, which is
     * exactly why the three had to stop being one.
     */
    case FAILED = 'failed';

    /**
     * The source ANSWERED, with something that is not a calendar answer — a well-typed result full of
     * the wrong things. Caught at the registry boundary rather than in the merge, where it would have
     * taken down every other module's data with a 500.
     *
     * A defect in that source's code. Retrying reproduces it exactly.
     */
    case MALFORMED = 'malformed';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }
}
