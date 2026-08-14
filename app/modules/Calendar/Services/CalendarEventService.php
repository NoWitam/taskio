<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\DTOs\CalendarEventDTO;
use App\Modules\Calendar\Models\CalendarEvent;

/**
 * The ONLY writer of `calendar_events`.
 *
 * "Only" is the load-bearing word and the reason this class exists at all for what is, on the face of
 * it, three one-line Eloquent calls. The all-day discriminator is an invariant across two column
 * groups, and it holds because every write goes DTO → here → row, with the DTO structurally unable to
 * describe an incoherent event ({@see CalendarEventDTO}) and this class writing both groups on every
 * write. The moment a second writer touches the table directly — a step, a seeder, a "quick fix" in a
 * controller — the invariant becomes a convention, and conventions are not what the read path branches
 * on.
 *
 * {@see attributes()} is why: it always emits ALL FIVE time columns, filling one group and NULLING the
 * other. An update that changed an event from timed to all-day by writing only `start_date` would
 * leave the old `starts_at` behind, and the row would then answer two different questions depending on
 * which reader asked. Blanking the unused side on every write makes that unrepresentable rather than
 * merely unlikely.
 *
 * No transaction, deliberately: every method here is ONE statement against ONE row. Wrapping a single
 * insert in a transaction is ceremony that teaches the next reader the wrong thing about where the
 * multi-step writes are.
 *
 * The creator is NOT set here. {@see \App\Traits\HasCreator} stamps it on save — the authenticated
 * user on the API path, and the executing run on the workflow path (`WorkflowRunContext` is published
 * around the step loop), so an event created by `create_event` is attributed to the run without this
 * service knowing that steps exist.
 */
class CalendarEventService
{
    public function create(CalendarEventDTO $dto): CalendarEvent
    {
        return CalendarEvent::create($this->attributes($dto));
    }

    public function update(CalendarEvent $event, CalendarEventDTO $dto): CalendarEvent
    {
        $event->update($this->attributes($dto));

        return $event;
    }

    /** Reversible: the event leaves the grid without leaving the database. */
    public function delete(CalendarEvent $event): void
    {
        $event->delete();
    }

    /**
     * The full column set for one event — ALL of it, every time.
     *
     * The two time groups are mutually exclusive and both are written on every save: the group the DTO
     * carries gets its values, the other is explicitly nulled. See the class docblock for why that is
     * not defensive noise but the mechanism the invariant rests on.
     *
     * @return array<string, mixed>
     */
    private function attributes(CalendarEventDTO $dto): array
    {
        return [
            'title' => $dto->title,
            'description' => $dto->description,

            'all_day' => $dto->allDay,
            // Exactly one of the next three is non-null, decided by the DTO's shape, not by a branch here.
            'start_date' => $dto->startDate,
            'starts_at' => $dto->startsAt,
            'ends_at' => $dto->endsAt,

            // Stored verbatim, never resolved — the pointer doctrine (see the model).
            'subject_type' => $dto->subjectType,
            'subject_id' => $dto->subjectId,
        ];
    }
}
