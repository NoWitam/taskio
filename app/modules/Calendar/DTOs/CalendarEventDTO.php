<?php

namespace App\Modules\Calendar\DTOs;

use App\Modules\Calendar\Http\Requests\StoreCalendarEventRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A validated CALENDAR EVENT on its way into {@see \App\Modules\Calendar\Services\CalendarEventService}.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE WRITE-SIDE TWIN OF {@see CalendarOccurrence}
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The read DTO has a private constructor and two named constructors that are structurally incapable of
 * holding each other's data. This one is built the same way, on purpose, because the invariant is the
 * same invariant and it has to hold at BOTH ends of the pipe:
 *
 *   {@see allDay()}  → carries `startDate` ('Y-m-d') only. No instants, and none can be passed.
 *   {@see timed()}   → carries `startsAt`/`endsAt` as UTC instants only. No day string exists on it.
 *
 * A row that carried both shapes is a row no reader can interpret — the read path branches on
 * `all_day`, so the other half is either dead data or, worse, gets converted, and a zone-free day read
 * as an instant lands on the wrong square for every viewer west of the workspace. Making the two
 * shapes unrepresentable in the same object means the only remaining way to write such a row would be
 * to bypass this DTO, and the service takes nothing else.
 *
 * The 'Y-m-d' assertion in {@see allDay()} is the same guard the read DTO carries, and it is not
 * redundant with the FormRequest: the request guards ONE caller. The workflow `create_event` step
 * resolves its date from a run-time variable and reaches this constructor with whatever that produced,
 * with no request anywhere in the process.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE RECURRENCE IS OPTIONAL AND ALREADY STAMPED
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `$recurrence` is null for the overwhelming majority of events, and null is what every existing
 * caller — including the `create_event` workflow step, which does not know series exist — continues to
 * produce. When it IS set it is a {@see CalendarRecurrence}, which is impossible to construct without
 * a server-stamped hour and a real timezone, so this DTO carries no rule it would have to re-check.
 *
 * WHAT IT DOES NOT CARRY is the proof that the ANCHOR satisfies that rule. That check costs a
 * projection and needs the anchor and the rule together, so it belongs to whoever is accepting the
 * write ({@see \App\Modules\Calendar\Services\CalendarRecurrenceService::anchorIsFirstOccurrence()}),
 * not to a value object. Stated here so the gap is deliberate rather than assumed shut.
 *
 * THERE IS NO COLOUR HERE, and its absence is the point. `CalendarColor` is a vocabulary of MEANINGS
 * the grid explains (a deadline's priority, a run's outcome, a projection being a projection); an event
 * has no such fact to state, so it emits a constant at the READ end
 * ({@see \App\Modules\Calendar\Sources\EventCalendarSource}) and carries nothing at the write end. A
 * colour parameter here would be a decoration travelling through the same pipe as the invariant, and
 * both doors into this table — the endpoint and the `create_event` step — would have to keep offering
 * it.
 */
final readonly class CalendarEventDTO
{
    private function __construct(
        public string $title,
        public ?string $description,
        public bool $allDay,
        /** 'Y-m-d'. Set iff {@see $allDay}. Zone-free by construction — never parsed, never converted. */
        public ?string $startDate,
        /** UTC instant. Set iff NOT {@see $allDay}. */
        public ?CarbonImmutable $startsAt,
        /** UTC instant, or null for an event with no stated end. Only ever set when NOT {@see $allDay}. */
        public ?CarbonImmutable $endsAt,
        /**
         * The optional POINTER — a morph alias and an id, both or neither. Stored verbatim and never
         * dereferenced; see {@see \App\Modules\Calendar\Models\CalendarEvent}.
         */
        public ?string $subjectType,
        public ?string $subjectId,
        /**
         * The rule this event repeats by, or null for an event that happens once. Already stamped and
         * already narrowed — see the class docblock for what it does NOT prove.
         */
        public ?CalendarRecurrence $recurrence = null,
    ) {}

    /**
     * An event that occupies a DAY and has no time of day: a launch date, a deadline, a holiday.
     *
     * @param  string  $date  'Y-m-d' exactly as it will be stored. Passing an instant here is the bug
     *                        this constructor exists to prevent, so the format is asserted.
     */
    public static function allDay(
        string $title,
        string $date,
        ?string $description = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?CalendarRecurrence $recurrence = null,
    ): self {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException(
                "An all-day calendar event needs a plain Y-m-d date, got [{$date}]. "
                . 'If the event happens at a moment, use CalendarEventDTO::timed() instead.'
            );
        }

        return new self(
            title: $title,
            description: $description,
            allDay: true,
            startDate: $date,
            startsAt: null,
            endsAt: null,
            subjectType: $subjectType,
            subjectId: $subjectId,
            recurrence: $recurrence,
        );
    }

    /** An event that happens at a MOMENT: a meeting, a call, a recording session. */
    public static function timed(
        string $title,
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt = null,
        ?string $description = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?CalendarRecurrence $recurrence = null,
    ): self {
        return new self(
            title: $title,
            description: $description,
            allDay: false,
            startDate: null,
            startsAt: CarbonImmutable::instance($startsAt)->utc(),
            endsAt: $endsAt !== null ? CarbonImmutable::instance($endsAt)->utc() : null,
            subjectType: $subjectType,
            subjectId: $subjectId,
            recurrence: $recurrence,
        );
    }

    /**
     * The same event with its rule removed — how a DETACHED occurrence is built.
     *
     * Editing one occurrence of a series produces an ordinary, non-repeating event; going through the
     * named constructors again would mean re-branching on the discriminator at the call site, which is
     * the branch this class exists to remove.
     */
    public function withoutRecurrence(): self
    {
        return new self(
            title: $this->title,
            description: $this->description,
            allDay: $this->allDay,
            startDate: $this->startDate,
            startsAt: $this->startsAt,
            endsAt: $this->endsAt,
            subjectType: $this->subjectType,
            subjectId: $this->subjectId,
            recurrence: null,
        );
    }

    /**
     * The validated payload, dispatched on the discriminator the request has already proved coherent
     * (see {@see StoreCalendarEventRequest}: an all-day payload carrying instants, or a timed one
     * carrying a day, is a 422 and never reaches here).
     */
    public static function fromRequest(StoreCalendarEventRequest $request): self
    {
        $title = trim($request->string('title')->value());
        $description = $request->resolvedDescription();
        [$subjectType, $subjectId] = $request->resolvedSubject();
        $recurrence = $request->resolvedRecurrence();

        if ($request->boolean('all_day')) {
            return self::allDay(
                title: $title,
                date: $request->string('start_date')->value(),
                description: $description,
                subjectType: $subjectType,
                subjectId: $subjectId,
                recurrence: $recurrence,
            );
        }

        return self::timed(
            title: $title,
            // Read through the request's own accessors, NOT `$request->date()`: a zone-less instant has
            // to be interpreted in the WORKSPACE's timezone so the write agrees with the grid that will
            // draw it, and only the request still sees the raw string well enough to know whether the
            // caller named a zone at all. See StoreCalendarEventRequest.
            startsAt: $request->resolvedStartsAt(),
            endsAt: $request->resolvedEndsAt(),
            description: $description,
            subjectType: $subjectType,
            subjectId: $subjectId,
            recurrence: $recurrence,
        );
    }
}
