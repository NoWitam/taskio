<?php

namespace App\Modules\Workflows\Calendar;

use App\Modules\Calendar\Contracts\CalendarSource;
use App\Modules\Calendar\DTOs\CalendarBadge;
use App\Modules\Calendar\DTOs\CalendarOccurrence;
use App\Modules\Calendar\DTOs\CalendarSourceResult;
use App\Modules\Calendar\DTOs\CalendarTruncation;
use App\Modules\Calendar\DTOs\CalendarWindow;
use App\Modules\Calendar\Enums\CalendarColor;
use App\Modules\Calendar\Enums\CalendarTruncationKind;
use App\Modules\Workflows\Models\WorkflowRun;

/**
 * What actually RAN — the past half of the workflow calendar, read from real `workflow_runs` rows.
 *
 * The counterpart to WorkflowScheduleCalendarSource, and separate from it on purpose: a projected
 * schedule can only say what WOULD have fired, which is a different (and frequently false) claim from
 * what did. See that class for the full argument.
 *
 * Ordered ASCENDING, like every other source, and that matters more than it looks. The calendar merges
 * sources and trims the merged set from the LATE end, so a source that took its own slice from the
 * newest end would hand over exactly the rows the global trim discards first — under overflow this
 * source would contribute nothing at all, while the response said only "some things were cut". Both
 * ends have to agree on which end of the window is expendable.
 */
class WorkflowRunCalendarSource implements CalendarSource
{
    public const ID = 'workflow_run';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('workflows.calendar.run_source');
    }

    public function occurrences(CalendarWindow $window): CalendarSourceResult
    {
        $runs = WorkflowRun::query()
            // `started_at` is stamped when the run is CLAIMED, not when it is created, so a pending run
            // has none — and a run that has not started has not happened. Excluding them keeps this
            // source's promise ("this ran") literally true.
            ->whereNotNull('started_at')
            ->whereBetween('started_at', [$window->startsAt(), $window->endsAt()])
            ->when(
                $window->hasSearch(),
                // A run has no name of its own; what a user searches for is the WORKFLOW's name, which
                // is also what the occurrence is titled with. Searching anything else here would make
                // the filter disagree with the labels on screen.
                fn ($query) => $query->whereHas(
                    'workflow',
                    fn ($workflow) => $workflow->search(['name'], $window->search),
                ),
            )
            ->with('workflow:id,name')
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit($window->maxOccurrences + 1)
            ->get(['id', 'workflow_id', 'state', 'started_at', 'finished_at']);

        $truncations = [];

        // A run is one row and one occurrence, so this source can neither densify an item nor drop one.
        // It can, however, stop loading — and history that never left the database is history the global
        // trim cannot count. Declaring it is what keeps that trim's figure from quietly under-reporting.
        if ($runs->count() > $window->maxOccurrences) {
            $runs = $runs->take($window->maxOccurrences);

            $truncations[] = new CalendarTruncation(
                source: self::ID,
                kind: CalendarTruncationKind::WINDOW_TRIMMED,
            );
        }

        return new CalendarSourceResult($runs
            ->map(function (WorkflowRun $run): CalendarOccurrence {
                $state = $run->state;

                return CalendarOccurrence::timed(
                    // A run is a real row, so its identity is its own id — no instant needed.
                    id: self::ID . ':' . $run->id,
                    source: self::ID,
                    subjectType: 'workflow_run',
                    subjectId: $run->id,
                    startsAt: $run->started_at,
                    // Null while the run is still going: an open-ended block is the truth, and stamping
                    // "now" as the end would make a live run look finished.
                    endsAt: $run->finished_at,
                    title: $run->workflow?->name ?? __('workflows.calendar.run_untitled'),
                    // The run's own state carries the colour: green for completed, red for failed. That
                    // is the whole point of showing history on a grid — the failures are what you scan
                    // for — and reusing WorkflowRunState::tone() keeps it identical to the runs list.
                    color: CalendarColor::fromTone($state?->tone()),
                    badge: $state === null ? null : new CalendarBadge(
                        // PROSE, already translated — a badge label is rendered as it arrives (see
                        // CalendarBadge), never re-worded downstream, because a client cannot own a
                        // vocabulary per source without needing a change for every new one.
                        label: $state->label(),
                        color: CalendarColor::fromTone($state->tone()),
                    ),
                    editable: false,
                );
            })
            ->values()
            ->all(), $truncations);
    }
}
