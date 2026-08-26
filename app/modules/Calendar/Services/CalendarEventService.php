<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\DTOs\CalendarEventDTO;
use App\Modules\Calendar\DTOs\CalendarRecurrence;
use App\Modules\Calendar\Models\CalendarEvent;
use RuntimeException;

/**
 * The ONLY writer of `calendar_events`.
 *
 * "Only" is the load-bearing word and the reason this class exists at all for what began as three
 * one-line Eloquent calls. The all-day discriminator is an invariant across two column groups, and it
 * holds because every write goes DTO → here → row, with the DTO structurally unable to describe an
 * incoherent event ({@see CalendarEventDTO}) and this class writing both groups on every write. The
 * moment a second writer touches the table directly — a step, a seeder, a "quick fix" in a
 * controller — the invariant becomes a convention, and conventions are not what the read path branches
 * on.
 *
 * {@see attributes()} is why: it always emits ALL the shape columns, filling one time group and
 * NULLING the other, and writing the recurrence pair whether or not there is one. An update that
 * changed an event from timed to all-day by writing only `start_date` would leave the old `starts_at`
 * behind, and the row would then answer two different questions depending on which reader asked.
 * Blanking the unused side on every write makes that unrepresentable rather than merely unlikely.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE SERIES OPERATIONS, AND WHY THIS FILE NOW OPENS TRANSACTIONS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * This class used to say, correctly, that it took no transactions because every method was one
 * statement against one row. That is no longer true, and the reason is worth stating rather than
 * inferring from a `use` line: two of the four series operations are TWO writes that mean one thing.
 *
 *   {@see updateFollowing()}    closes the outgoing series with an end date AND inserts the new one.
 *                               Half of that is a series that stops and is never replaced (an
 *                               appointment that silently ceases), or two overlapping series drawing
 *                               the same squares twice.
 *   {@see detachOccurrence()}   excludes a day from the rule AND inserts the detached event. Half of
 *                               that is an occurrence deleted instead of edited — the user's change
 *                               gone, with a success response.
 *
 * Neither is reversible by a later request, so both are wrapped. The other two operations remain
 * single statements and are deliberately NOT wrapped — ceremony around a single write teaches the next
 * reader the wrong thing about where the multi-step writes are.
 *
 * Both go through {@see transaction()}, which opens on the ROW'S connection rather than the default
 * one. An own-database workspace would otherwise get a transaction on the central database wrapping
 * two writes that land on the tenant one — atomicity that reads as present and is not.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT THESE METHODS DO NOT DO IS DECIDE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Every scoped operation here assumes its caller has already proved the row repeats and that the named
 * day is a real occurrence inside the series' bounds — the FormRequests do that, because a failure
 * there is a 422 with a field, not an exception. What is left here is arithmetic and persistence. The
 * one thing this class refuses on its own behalf is a scoped call against a row with no series at all,
 * because that is a programming error rather than a bad request and silently degrading it would write
 * a row nobody asked for.
 *
 * The creator is NOT set here. {@see \App\Traits\HasCreator} stamps it on save — the authenticated
 * user on the API path, and the executing run on the workflow path (`WorkflowRunContext` is published
 * around the step loop), so an event created by `create_event` is attributed to the run without this
 * service knowing that steps exist. A DETACHED occurrence and the second half of a SPLIT are new rows
 * and are therefore attributed to whoever performed the edit, not to the original series' creator —
 * which is the honest answer: they are new events, and somebody chose to make them.
 */
class CalendarEventService
{
    public function __construct(private CalendarRecurrenceService $recurrence) {}

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

    // ---- series operations -----------------------------------------------------

    /**
     * THIS OCCURRENCE AND EVERY LATER ONE — the split: the old series is closed the day before, and a
     * new event carries the payload forward.
     *
     * WHY A SPLIT RATHER THAN AN EDIT IN PLACE. A series stores one rule for its whole life, so
     * changing that rule in place rewrites the PAST: moving a weekly meeting to Wednesdays would turn
     * last year's Mondays into Wednesdays, everywhere, with nothing recording that they had ever been
     * Mondays. The split is a composition of two things the module already had — an end date, and an
     * event — and introduces no new concept and no new table.
     *
     * WHEN NOTHING WOULD BE LEFT BEHIND there is no past to preserve, and closing the old series would
     * leave an empty ghost row that draws nothing and can never be reached again. That case collapses to
     * a plain whole-event update — which is exactly what "this and all following" means when "this" is
     * the first one — and it is why the id this method returns is sometimes the id in the URL and
     * sometimes not.
     *
     * "NOTHING LEFT BEHIND" IS ASKED, NOT ASSUMED FROM THE DATE. Comparing the split point against the
     * anchor day is the obvious test and it is not the same question: a series whose first occurrence
     * has since been removed one-at-a-time still has an anchor day before the split, and closing it
     * there produces precisely the ghost this paragraph is about. One capped projection over the
     * outgoing span answers the real question, and it answers the anchor-day case for free — the span
     * is empty by construction when the split lands on or before the anchor.
     *
     * @param  string  $occurrenceDate  'Y-m-d' on the series' own stamped clock
     */
    public function updateFollowing(CalendarEvent $event, CalendarEventDTO $dto, string $occurrenceDate): CalendarEvent
    {
        $series = $this->series($event);

        if (!$this->recurrence->splitLeavesSomethingBehind($event, $series, $occurrenceDate)) {
            return $this->update($event, $dto);
        }

        return $this->transaction($event, function () use ($event, $dto, $occurrenceDate, $series): CalendarEvent {
            $this->applySeries($event, $series->endingOn($this->recurrence->dayBefore($occurrenceDate)));

            return $this->create($dto);
        });
    }

    /**
     * ONE OCCURRENCE, EDITED — excluded from the rule and re-created as an ordinary, non-repeating
     * event.
     *
     * A DETACH, not an override. There is no overrides table (see the migration for why), so the edited
     * occurrence becomes a plain row that nothing has to know is special: it renders, edits and deletes
     * like any other event, and the series it came from is simply one day shorter. The exclusion and
     * the new row are one act, hence the transaction.
     *
     * The DTO's own recurrence — if the caller somehow supplied one — is dropped rather than trusted:
     * one occurrence of a series is by definition not itself a series. The request refuses that payload
     * first; this is the type-level half of the same statement.
     */
    public function detachOccurrence(CalendarEvent $event, CalendarEventDTO $dto, string $occurrenceDate): CalendarEvent
    {
        $series = $this->series($event);

        return $this->transaction($event, function () use ($event, $dto, $occurrenceDate, $series): CalendarEvent {
            $this->applySeries($event, $series->excluding($occurrenceDate));

            return $this->create($dto->withoutRecurrence());
        });
    }

    /**
     * ONE OCCURRENCE, REMOVED — a date added to the rule's own exclusions.
     *
     * The mechanism is the shared engine's, already built and already tested, and it removes a whole
     * DAY rather than an instant. That is the same thing only because a Calendar series has exactly one
     * hour a day, which is an independent argument for keeping the accepted cadence subset narrow.
     *
     * The row is MODIFIED, not deleted, even though this is what a DELETE request performs.
     *
     * A KNOWN, ACCEPTED EDGE: excluding every remaining occurrence one at a time leaves a row that draws
     * nothing. It is inert rather than wrong — nothing executes because a calendar row exists, so an
     * empty series costs a row and no behaviour. Detecting it would mean projecting the series' whole
     * remaining span on every single-occurrence delete, which is real work on a hot path to catch a user
     * who should have deleted the series. If that ever becomes worth doing, it belongs here, not spread
     * across the callers.
     */
    public function deleteOccurrence(CalendarEvent $event, string $occurrenceDate): void
    {
        $this->applySeries($event, $this->series($event)->excluding($occurrenceDate));
    }

    /**
     * THIS OCCURRENCE AND EVERY LATER ONE, REMOVED — the series is closed the day before.
     *
     * When nothing would be left behind, "this and all following" is the whole series, so the row is
     * soft-deleted instead of being closed to an empty span. Same reasoning as {@see updateFollowing()},
     * same question asked the same way, opposite verb.
     */
    public function deleteFollowing(CalendarEvent $event, string $occurrenceDate): void
    {
        $series = $this->series($event);

        if (!$this->recurrence->splitLeavesSomethingBehind($event, $series, $occurrenceDate)) {
            $this->delete($event);

            return;
        }

        $this->applySeries($event, $series->endingOn($this->recurrence->dayBefore($occurrenceDate)));
    }

    // ---- persistence -----------------------------------------------------------

    /**
     * The row's series, or a refusal. See the class docblock: a scoped write against a row that does
     * not repeat is a programming error, and degrading it silently would write something nobody asked
     * for. The FormRequests answer the same case with a 422 long before it reaches here.
     */
    private function series(CalendarEvent $event): CalendarRecurrence
    {
        $series = $this->recurrence->seriesOf($event);

        if ($series === null) {
            throw new RuntimeException(
                'A scoped calendar write needs a series, and this event does not repeat. The request '
                . 'layer refuses this case; reaching here means it was bypassed.'
            );
        }

        return $series;
    }

    /**
     * A transaction ON THE ROW'S OWN CONNECTION.
     *
     * `DB::transaction()` opens on the DEFAULT connection. A calendar event resolves its connection
     * through the tenancy concern, so on an own-database workspace the writes land on the TENANT
     * database while a transaction wrapping them would have been opened on the central one — atomicity
     * that reads as present and is not, for exactly the customers no one is testing on. Asking the
     * model which connection it is on removes the possibility rather than documenting it.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function transaction(CalendarEvent $event, \Closure $callback): mixed
    {
        return $event->getConnection()->transaction($callback);
    }

    /**
     * The RULE columns alone, written onto an existing row.
     *
     * A partial write, and legitimately so: the invariant {@see attributes()} protects is between the
     * two TIME groups, and neither is touched here. Every caller of this method is changing which days
     * an existing event falls on, not what kind of event it is.
     */
    private function applySeries(CalendarEvent $event, CalendarRecurrence $series): void
    {
        $event->update([
            'recurrence' => $series->descriptor,
            'recurrence_until' => $series->until,
        ]);
    }

    /**
     * The full column set for one event — ALL of it, every time.
     *
     * The two time groups are mutually exclusive and both are written on every save: the group the DTO
     * carries gets its values, the other is explicitly nulled. See the class docblock for why that is
     * not defensive noise but the mechanism the invariant rests on. The recurrence pair follows the
     * same discipline for the same reason — a whole-event write that left an old rule behind would
     * quietly keep drawing squares the caller believed it had removed.
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

            // Both halves of the rule, or both null. The descriptor already carries its own stamped
            // hour and timezone — nothing is added to it here.
            'recurrence' => $dto->recurrence?->descriptor,
            'recurrence_until' => $dto->recurrence?->until,

            // Stored verbatim, never resolved — the pointer doctrine (see the model).
            'subject_type' => $dto->subjectType,
            'subject_id' => $dto->subjectId,
        ];
    }
}
