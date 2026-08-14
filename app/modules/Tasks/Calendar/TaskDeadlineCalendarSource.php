<?php

namespace App\Modules\Tasks\Calendar;

use App\Modules\Calendar\Contracts\CalendarSource;
use App\Modules\Calendar\DTOs\CalendarBadge;
use App\Modules\Calendar\DTOs\CalendarOccurrence;
use App\Modules\Calendar\DTOs\CalendarSourceResult;
use App\Modules\Calendar\DTOs\CalendarTruncation;
use App\Modules\Calendar\DTOs\CalendarWindow;
use App\Modules\Calendar\Enums\CalendarColor;
use App\Modules\Calendar\Enums\CalendarTruncationKind;
use App\Modules\Tasks\Models\Task;

/**
 * Task deadlines on the calendar.
 *
 * IT LIVES IN THE TASKS MODULE, NOT THE CALENDAR. The Calendar owns the contract; the module that owns
 * the subject owns the mapping. That is what makes a fourth source a purely additive change.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A DEADLINE IS A DAY, AND IT IS NEVER CONVERTED
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `tasks.deadline` is a `date` column: no hour, no zone. The value on disk IS the answer, so it is
 * compared as text against the window's day strings and handed to the calendar as an all-day
 * occurrence, untouched. It is never parsed into an instant and never shifted by a timezone — do either
 * and a deadline moves a day whenever the workspace zone sits west of UTC, silently and only for some
 * users.
 */
class TaskDeadlineCalendarSource implements CalendarSource
{
    public const ID = 'task';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('tasks.calendar.source');
    }

    public function occurrences(CalendarWindow $window): CalendarSourceResult
    {
        $tasks = Task::query()
            // Trashed and ARCHIVED tasks are already excluded by the model's global scopes
            // (SoftDeletes + ArchivingScope), as is anything outside the active workspace (TenantAware).
            // Named here because a calendar quietly showing last quarter's archive is the kind of defect
            // that is only noticed by the person who archived it.
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [$window->startDate, $window->endDate])
            ->when(
                $window->hasSearch(),
                fn ($query) => $query->search(['title'], $window->search),
            )
            ->orderBy('deadline')
            ->orderBy('id')
            // The window's global ceiling, +1 so an overflow is DETECTABLE by the query service rather
            // than landing exactly on the ceiling and looking like a complete answer.
            ->limit($window->maxOccurrences + 1)
            ->get(['id', 'title', 'deadline', 'status', 'priority']);

        $truncations = [];

        // A task has exactly ONE deadline, so this source can never densify an item or drop one. What it
        // CAN do is stop loading: the query above asks for one more row than the whole response may
        // carry, and if that row exists there are deadlines here that nothing downstream will ever see —
        // not even the global trim, which can only count what reached it. Saying so is what keeps that
        // trim's own figure honest (the query service turns a known count plus an unknown one back into
        // "unknown" rather than reporting the known part as the total).
        if ($tasks->count() > $window->maxOccurrences) {
            $tasks = $tasks->take($window->maxOccurrences);

            $truncations[] = new CalendarTruncation(
                source: self::ID,
                kind: CalendarTruncationKind::WINDOW_TRIMMED,
            );
        }

        return new CalendarSourceResult($tasks
            ->map(fn (Task $task): CalendarOccurrence => CalendarOccurrence::allDay(
                id: self::ID . ':' . $task->id,
                source: self::ID,
                subjectType: 'task',
                subjectId: $task->id,
                // Formatted from the `date` cast, which carries no time — this is a re-print of the
                // stored day, not a conversion of it.
                date: $task->deadline->format('Y-m-d'),
                title: $task->title,
                // COLOUR CARRIES URGENCY, the badge carries stage. On a calendar the question a colour
                // answers at a glance is "how much does this day matter", and that is priority; reusing
                // TaskPriority::tone() means the calendar and the task list cannot drift apart.
                color: CalendarColor::fromTone($task->priority?->tone()),
                badge: $task->status === null ? null : new CalendarBadge(
                    label: __('tasks.status.' . $task->status->value),
                    color: CalendarColor::fromTone($task->status->tone()),
                ),
                // Nothing on the calendar is editable yet — moving a deadline by drag is a write path
                // that does not exist in this batch, and claiming otherwise would put an affordance on
                // screen that fails when used.
                editable: false,
            ))
            ->values()
            ->all(), $truncations);
    }
}
