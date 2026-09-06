<?php

namespace App\Modules\Publishing\Calendar;

use App\Modules\Calendar\Contracts\CalendarSource;
use App\Modules\Calendar\DTOs\CalendarBadge;
use App\Modules\Calendar\DTOs\CalendarOccurrence;
use App\Modules\Calendar\DTOs\CalendarSourceResult;
use App\Modules\Calendar\DTOs\CalendarTruncation;
use App\Modules\Calendar\DTOs\CalendarWindow;
use App\Modules\Calendar\Enums\CalendarColor;
use App\Modules\Calendar\Enums\CalendarTruncationKind;
use App\Modules\Publishing\Models\Publication;

/**
 * Scheduled publications on the calendar — THE FIFTH SOURCE, and the first real test of what R3
 * promised.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE BAR, AND WHY THIS FILE IS WHERE IT IS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `CalendarModuleBoundaryTest` states the acceptance criterion in words: adding R4 Publishing as a new
 * source must not change a single line under app/modules/Calendar. This class is the cash-out of that
 * sentence. It lives in the Publishing module because the Calendar knows nobody and the module that
 * owns the subject owns the mapping; it is registered from Publishing's own provider, lazily, through
 * the same public registry method every other source goes through.
 *
 * The only thing outside this module that had to change is the boundary test's own INVENTORY of shipped
 * source ids — a list of what exists, not a line of Calendar code.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * ONE ROW, ONE SQUARE. NO PROJECTION, NO SERIES.
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A publication has exactly one `scheduled_at`, so this source can never densify an item or drop one,
 * and it never touches the recurrence engine. A repeating publication is not a column here and will not
 * be: that is a Campaign (R5), which compiles to a workflow and produces publications — one row each,
 * each still one square. `recurring: false` and a null cadence label are therefore the truth about
 * every occurrence this source will ever emit, not a placeholder.
 *
 * The occurrence id is `publication:{uuid}` — deterministic, because the row IS the occurrence and
 * there is nothing to disambiguate. It survives a refresh unchanged, which is what selection, focus and
 * keying on the grid depend on.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A DRAFT IS NOT ON THE GRID
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Filtered out IN THE QUERY (through `Publication::scopeProjectable`, so the model states the rule
 * once), not by returning an occurrence with nothing in it. A draft has no `scheduled_at`: no instant,
 * so no place on an axis of time. Putting it somewhere would mean inventing a moment, and the moment
 * a calendar invents is the moment somebody plans around.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * COLOUR CARRIES THE STATE, THE BADGE CARRIES THE DESTINATION
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Note that this is the INVERSE of the task source, which colours by priority and badges the status —
 * and it is the same principle, not a departure from it. Colour answers "how much does this square
 * matter at a glance", and for a publication that is unambiguously its state: `failed` and
 * `needs_reconcile` are the two squares on the month a person must act on today, and a grid that
 * coloured them by destination would bury both among the ones that went out fine.
 *
 * The badge then carries what colour cannot — WHERE it is going — as translated PROSE. Prose, like
 * every other source's badge, because a coded platform would need a new client-side vocabulary for
 * every destination added, and "a new platform needs no frontend change" would quietly stop being true
 * on the first one.
 *
 * The badge takes the SAME tone as the square. A second colour there would be inventing a meaning for a
 * fact (the destination) that has none, and would put two different colour vocabularies inside one
 * square.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * NOTHING IS EDITABLE FROM THE GRID
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `editable: false`, flatly, and not per-row through the policy. Dragging a square would MOVE A
 * PUBLICATION IN TIME — an arming, the one consequential act in this module, done by a gesture that is
 * easy to make by accident and has no confirmation. The policy would happily allow it for most rows;
 * the affordance is refused anyway, which is a product decision this source is the right place to make
 * because it is the thing that would offer the handle.
 */
class PublicationCalendarSource implements CalendarSource
{
    public const ID = 'publication';

    /** Only the columns an occurrence and its badge need. */
    private const COLUMNS = ['id', 'title', 'platform', 'status', 'scheduled_at'];

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('publishing.calendar.source');
    }

    public function occurrences(CalendarWindow $window): CalendarSourceResult
    {
        $publications = Publication::query()
            // Trashed rows are excluded by SoftDeletes and everything outside the active workspace by
            // TenantAware. Named here because a calendar quietly showing a cancelled publication is the
            // kind of defect only the person who cancelled it ever notices — and the thing they would
            // conclude is that it is still going out.
            ->projectable()
            ->whereBetween('scheduled_at', [$window->startsAt(), $window->endsAt()])
            ->when(
                $window->hasSearch(),
                fn ($query) => $query->search(['title'], $window->search),
            )
            // ASCENDING, and not merely for tidiness: the query service trims the MERGED set from the
            // late end, so a source slicing from the newest end would hand over exactly the rows the
            // trim discards first and silently contribute nothing under overflow.
            ->orderBy('scheduled_at')
            ->orderBy('id')
            // One more than the whole response may carry, so an overflow is DETECTABLE rather than
            // landing exactly on the ceiling and looking like a complete answer.
            ->limit($window->maxOccurrences + 1)
            ->get(self::COLUMNS);

        $truncations = [];

        if ($publications->count() > $window->maxOccurrences) {
            $publications = $publications->take($window->maxOccurrences);

            $truncations[] = new CalendarTruncation(
                source: self::ID,
                kind: CalendarTruncationKind::WINDOW_TRIMMED,
                // THE COUNT IS DELIBERATELY UNKNOWN. We asked for one row more than we can carry purely
                // to learn that there ARE more; HOW MANY more would be a second COUNT query for a number
                // nobody can act on. Null means "genuinely not known", never zero — and it is what stops
                // the query service's own exact figure from passing for the whole loss, since reports
                // merge per (source, kind) and a known count plus an unknown one is unknown.
            );
        }

        return new CalendarSourceResult(
            $publications
                ->map(fn (Publication $publication): CalendarOccurrence => $this->toOccurrence($publication))
                ->values()
                ->all(),
            $truncations,
        );
    }

    /**
     * One publication as one square.
     *
     * `endsAt` is null: a publication is an INSTANT, not a span. It is the moment something goes out,
     * and inventing a duration for it would draw a block across a grid to represent an event with no
     * width.
     *
     * `subjectType` comes from `getMorphClass()` — the registered morph ALIAS, never the class name. A
     * leaked FQCN would put an internal namespace in a public payload and freeze a refactor out of the
     * codebase.
     */
    private function toOccurrence(Publication $publication): CalendarOccurrence
    {
        return CalendarOccurrence::timed(
            id: self::ID . ':' . $publication->id,
            source: self::ID,
            subjectType: $publication->getMorphClass(),
            subjectId: $publication->id,
            startsAt: $publication->scheduled_at,
            endsAt: null,
            title: $publication->title,
            // The STATE, in the app-wide tone vocabulary — see the class docblock. A draft never
            // reaches here (the query excludes it), so the null tone it would answer with is
            // unreachable; `fromTone` degrades an unknown tone to neutral rather than throwing a whole
            // calendar screen away for one chip.
            color: CalendarColor::fromTone($publication->status->tone()),
            // The DESTINATION, as translated prose, in the square's own colour.
            badge: new CalendarBadge(
                label: $publication->platform->label(),
                color: CalendarColor::fromTone($publication->status->tone()),
            ),
            // See the class docblock: dragging a square would be an arming.
            editable: false,
        );
    }
}
