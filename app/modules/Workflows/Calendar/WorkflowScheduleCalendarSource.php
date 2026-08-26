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
use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Services\WorkflowScheduleService;
use App\Modules\Workflows\Support\ScheduleCadenceLabel;
use Carbon\CarbonImmutable;

/**
 * What the schedules are GOING TO fire — the future half of the workflow calendar.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS FUTURE-ONLY, AND WHY THE PAST IS A DIFFERENT SOURCE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Projecting a schedule backwards answers a question nobody asked: "what WOULD have fired, if nothing
 * had changed". Everything might have changed. The workflow may have been deactivated or edited, the
 * schedule rewritten, a run may have failed — and the scheduler has an explicit SLOT-CONSUMED doctrine
 * in which a workflow over its run budget consumes its due slot and does NOT run
 * (RunScheduledWorkflowsCommand). A month grid always contains past days, so a single "schedule" source
 * would misstate history on every single screen it drew.
 *
 * So the past is served by WorkflowRunCalendarSource, from real `workflow_runs` rows. Two ids, two
 * filter chips, two caps, two sets of tests — and a user who can tell "this happened" from "this is
 * planned" without being told.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE PER-WORKFLOW CAP IS ARITHMETIC, NOT CAUTION
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The minimum cadence is one minute. One such workflow across a six-week grid is 60 480 occurrences,
 * and the schedule engine bounds projection BY COUNT ONLY — it has no end-date parameter. A workflow
 * that overruns its budget therefore keeps the occurrences it is allowed, has them flagged
 * `dense: true`, and the result SAYS an item was densified.
 *
 * THE RESPONSE CEILING IS THE SECOND WAY A SERIES GETS CUT, and it took longer to say so. This is the
 * only source that COUNTS its occurrences rather than reading rows, so it is the only one that can stop
 * in the MIDDLE of an item — and it used to do that silently: the occurrences already emitted kept
 * `dense: false`, the rest of the series was abandoned, and the loss was reported only if the outer loop
 * happened to reach another workflow afterwards. A workspace with one busy automation therefore got a
 * short series that every signal in the payload called complete. Both halves are now unconditional: the
 * emitted part is flagged as the sample it is, and the source ALSO files a `window_trimmed` with an
 * unknown count so the query service's exact figure cannot pass for the whole loss.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE `next_due_at` PRE-FILTER, AND WHY IT LOSES NOTHING
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Projection is the expensive thing here: ~0.3 ms per occurrence through the cron engine, paid whether
 * or not the workflow turns out to have anything in the window. Five hundred sparse automations cost
 * five hundred projections and contribute almost nothing — and no OCCURRENCE cap can bound that,
 * because the cost is paid before anyone knows how many occurrences there were.
 *
 * So a workflow whose next fire is already past the end of the window is not projected at all. That is
 * safe, and the argument is short enough to check:
 *
 *   1. `next_due_at` is always a real occurrence of the cadence, written by
 *      WorkflowScheduleService::arm() or advanced by claimDue(), and there is NO occurrence strictly
 *      between the instant it was computed from and `next_due_at` itself.
 *   2. This source never looks before `anchor = max(now, window start)`, and `next_due_at` was computed
 *      from an instant at or before now.
 *   3. So an occurrence inside [anchor, window end] would also lie in that gap — which (1) says is
 *      empty. If `next_due_at > window end`, the window holds nothing for this workflow.
 *
 * The trap this deliberately avoids: a DENSE cadence is never filtered out, because a minute-cadence
 * automation's `next_due_at` is a minute away — it can only be excluded if its next fire is genuinely
 * beyond the window. Pinned by test.
 *
 * NULL `next_due_at` is INCLUDED, never filtered. It means "not armed" (an unreachable cadence, or a
 * row the sweep's self-healing has not reached yet), and excluding it would trade a guaranteed silent
 * loss for one skipped projection. The invariant in (1) is maintained by the write path and pinned by
 * WorkflowScheduleSweepTest's arming tests; this filter is only ever as correct as that, so it leans on
 * the case where being wrong is cheap.
 */
class WorkflowScheduleCalendarSource implements CalendarSource
{
    public const ID = 'workflow_schedule';

    public function __construct(
        private WorkflowScheduleService $schedules,
        private ScheduleCadenceLabel $cadence,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('workflows.calendar.schedule_source');
    }

    public function occurrences(CalendarWindow $window): CalendarSourceResult
    {
        $windowEnd = $window->endsAt();

        // NOW, or the window's start if the window is entirely in the future. A schedule says nothing
        // trustworthy about a moment that has already passed, so the projection never starts before it.
        $anchor = CarbonImmutable::now('UTC')->max($window->startsAt());

        if ($anchor->greaterThan($windowEnd)) {
            return CalendarSourceResult::complete([]);
        }

        // At least one. A per-item budget of zero would compute `dense`, then slice the series to
        // nothing — leaving no occurrence to carry the flag, which is the one outcome this whole
        // mechanism exists to prevent. One flagged occurrence still says "there is more of this".
        $perItem = max(1, $window->maxOccurrencesPerItem);
        $maxItems = max(1, $window->maxItems);

        $workflows = Workflow::query()
            // Only ACTIVE schedule-triggered workflows: an inactive one fires nothing, so drawing its
            // future would be drawing a plan that does not exist. Soft-deleted workflows are excluded by
            // the model, and other workspaces by TenantAware.
            ->where('status', WorkflowStatus::ACTIVE->value)
            ->where('trigger_type', WorkflowTriggerType::SCHEDULE->value)
            // The pre-filter argued for above. NULL is kept deliberately.
            ->where(fn ($query) => $query
                ->whereNull('next_due_at')
                ->orWhere('next_due_at', '<=', $windowEnd))
            ->when(
                $window->hasSearch(),
                fn ($query) => $query->search(['name'], $window->search),
            )
            // Soonest-firing first, so if the item budget bites it drops the automations whose next fire
            // is FURTHEST away — the ones least likely to be what the user opened the month to see.
            // Unarmed rows sort last for the same reason: their next fire is unknown, not imminent.
            ->orderByRaw('next_due_at asc nulls last')
            ->orderBy('name')
            ->orderBy('id')
            ->limit($maxItems + 1)
            ->get(['id', 'name', 'trigger_config', 'next_due_at']);

        $truncations = [];

        if ($workflows->count() > $maxItems) {
            // We asked for one more than the budget purely to learn that there ARE more. How many more
            // is not known — counting them is a second query for a number nobody can act on — so the
            // report says which kind of loss occurred and leaves the figure null rather than inventing
            // one. The count of dropped items IS known when it equals what we fetched beyond the cap,
            // but that is 1 by construction and would be misleading.
            $workflows = $workflows->take($maxItems);

            $truncations[] = new CalendarTruncation(
                source: self::ID,
                kind: CalendarTruncationKind::ITEMS_DROPPED,
            );
        }

        $budget = $window->maxOccurrences + 1;
        $occurrences = [];
        $densifiedItems = 0;
        $stoppedMidSeries = false;

        foreach ($workflows as $workflow) {
            if (count($occurrences) >= $budget) {
                // The occurrence ceiling stopped us before the item budget did. Whatever is left in the
                // list is absent from the whole window, which is the same KIND of loss as the item cap
                // above — so it is reported as such rather than left for the global trim to describe as
                // "the far end of the window".
                $truncations[] = new CalendarTruncation(
                    source: self::ID,
                    kind: CalendarTruncationKind::ITEMS_DROPPED,
                );

                break;
            }

            $schedule = $workflow->trigger_config['schedule'] ?? null;

            if (!is_array($schedule) || $schedule === []) {
                continue;
            }

            // +2, not +1: occurrencesFrom() may open with previousOrAtOccurrence — an occurrence AT or
            // BEFORE the anchor — so one slot can be spent on a value the window filter then drops. The
            // remaining +1 is what makes an overrun detectable rather than a silent exact fit.
            $projected = $this->schedules->occurrencesFrom($schedule, $anchor, $perItem + 2);

            $inWindow = [];

            foreach ($projected as $moment) {
                $utc = CarbonImmutable::instance($moment)->utc();

                if ($utc->lessThan($anchor) || $utc->greaterThan($windowEnd)) {
                    continue;
                }

                $inWindow[] = $utc;
            }

            $dense = count($inWindow) > $perItem;

            if ($dense) {
                $inWindow = array_slice($inWindow, 0, $perItem);
            }

            // THE RESPONSE CEILING CAN ALSO BITE IN THE MIDDLE OF A SERIES, and when it does what is
            // emitted for this item is every bit as much a SAMPLE as a per-item cap makes it. This used
            // to be handled by breaking out of the emit loop below and saying nothing: the occurrences
            // already appended kept `dense: false`, and the loss was reported only if the outer loop
            // happened to reach another workflow afterwards. One busy automation in a workspace
            // therefore came back looking like a complete, short series — the single failure mode the
            // whole truncation vocabulary exists to make impossible.
            //
            // Deciding it HERE, before anything is emitted, is what lets every occurrence of this item
            // carry the flag; the emit loop below then needs no ceiling check of its own, because the
            // slice already fits.
            $remaining = $budget - count($occurrences);

            if (count($inWindow) > $remaining) {
                $inWindow = array_slice($inWindow, 0, $remaining);
                $dense = true;
                $stoppedMidSeries = true;
            }

            if ($dense) {
                $densifiedItems++;
            }

            // Computed ONCE per workflow, not per occurrence: the cadence is a property of the
            // descriptor, and a densified minute-cadence item is exactly the case that would otherwise
            // pay for it 64 times. Null for a fixed-times (`at`) schedule — see ScheduleCadenceLabel
            // for why an honest sentence for that mode needs the day/month axes too.
            $cadenceLabel = $this->cadence->for($schedule);

            foreach ($inWindow as $utc) {
                $occurrences[] = CalendarOccurrence::timed(
                    // Deterministic and stable across refreshes: a projected occurrence has no row of
                    // its own to be identified by, so its identity is (workflow, instant).
                    id: self::ID . ':' . $workflow->id . ':' . $utc->toISOString(),
                    source: self::ID,
                    subjectType: 'workflow',
                    subjectId: $workflow->id,
                    startsAt: $utc,
                    // A schedule says when something STARTS. How long the run will take is not knowable
                    // in advance, and inventing a duration would put a made-up block on the grid.
                    endsAt: null,
                    title: $workflow->name,
                    color: CalendarColor::INFO,
                    badge: new CalendarBadge(
                        label: __('workflows.calendar.scheduled_badge'),
                        color: CalendarColor::INFO,
                    ),
                    editable: false,
                    dense: $dense,
                    // What makes `dense` worth rendering: "showing 64 of a series" says nothing a
                    // reader could not count, "every 5 min" says why the series is longer than the day.
                    cadenceLabel: $cadenceLabel,
                    // Every square this source draws is computed from a cadence and has no row behind
                    // it, which is exactly what the flag means. Said outright rather than left to be
                    // inferred from `cadenceLabel`, which is legitimately NULL for a fixed-times
                    // schedule ({@see ScheduleCadenceLabel}) — a client reading the prose as the marker
                    // would call those projections one-offs.
                    recurring: true,
                    // NO `occurrenceDate`, deliberately. That field is the name a subject's own write
                    // surface addresses ONE occurrence by, and a schedule firing has none: nothing here
                    // is editable, and the day is not a key to anything. Filling it with the day this
                    // instant happens to fall on would publish an identifier that addresses nothing.
                );
            }
        }

        if ($densifiedItems > 0) {
            $truncations[] = new CalendarTruncation(
                source: self::ID,
                kind: CalendarTruncationKind::ITEM_DENSIFIED,
                affectedItems: $densifiedItems,
            );
        }

        if ($stoppedMidSeries) {
            // AND the response ceiling's own figure has to be told it is incomplete. The query service
            // trims the merged set and reports an EXACT count, which is honest about what reached the
            // trim and silent about everything this source dropped before it. Truncations merge per
            // (source, KIND), so the `item_densified` entry above cannot correct that number — only a
            // same-kind report with an UNKNOWN count can, and the merge rule turns known + unknown back
            // into unknown. This is the identical move TaskDeadlineCalendarSource makes when its query
            // stops at its own bound; the two now describe the same loss the same way.
            $truncations[] = new CalendarTruncation(
                source: self::ID,
                kind: CalendarTruncationKind::WINDOW_TRIMMED,
            );
        }

        return new CalendarSourceResult($occurrences, $truncations);
    }
}
