<?php

namespace App\Modules\Workflows\Steps;

use App\Modules\Calendar\DTOs\CalendarEventDTO;
use App\Modules\Calendar\Services\CalendarEventService;
use App\Modules\Calendar\Services\CalendarInstantResolver;
use App\Modules\Variables\Enums\VariableType;
use App\Modules\Variables\Services\VariableResolver;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Models\WorkflowRun;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Puts a CALENDAR EVENT on the workspace's grid, THROUGH the Calendar's own DTO and service — never a
 * raw model write. That is the house rule for every step ({@see WorkflowStep}), and here it is also
 * what carries the all-day invariant into the engine: the DTO's two named constructors cannot hold
 * each other's data, so a run cannot produce a row a reader would have to guess at.
 *
 * The direction of the dependency is the one that is allowed. Workflows names the Calendar; the
 * Calendar names nobody (pinned over the file bytes by CalendarModuleBoundaryTest). An event step
 * living in the Calendar module, or a Calendar service reaching into a run, would invert that.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * FIELDS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   title        resolveString (directives + flat tokens + literals); REQUIRED non-blank after
 *                resolution — a blank title HARD-FAILS the step, exactly as it does for create_task.
 *   description  resolveString over the body; null when blank.
 *   all_day      a LITERAL boolean, and deliberately not a variable — see below.
 *   start_date   literal|variable union (DATE), used iff all_day. Required: hard-fails when it
 *                resolves to nothing.
 *   starts_at    literal|variable union (DATE), used iff NOT all_day. Required, same hard failure.
 *   ends_at      literal|variable union (DATE), optional and SOFT: unresolvable, unparseable or
 *                earlier than the start simply yields an event with no stated end, which is an
 *                ordinary event.
 *
 * THERE IS NO `color`, and a definition carrying one is a 422 at save (the step's key allow-list in
 * StoreWorkflowRequest refuses it like any other foreign key). Colour on the calendar is a vocabulary of
 * MEANINGS — priority, run outcome, "this is only a projection" — and an event states none of them, so
 * the grid gives every event one constant. A colour field here would have let a workflow author paint a
 * run-created event red next to a genuinely failed run.
 *
 * WHY `all_day` IS LITERAL-ONLY. It is the discriminator that decides which OTHER fields are required
 * — so a run-time value would make the step unvalidatable when the workflow is saved, and a definition
 * that passed validation could still arrive at a branch with no date in it. A literal lets the write
 * validator demand exactly the fields the chosen branch needs, which is where an author can still fix
 * the mistake.
 *
 * WHY A MISSING DATE IS A HARD FAILURE while create_task's deadline is not. A task with no deadline is
 * a perfectly good task; an event with no position in time is not an event at all — there is no square
 * to draw it on. Writing one anyway would put a row in the table that the read path cannot select and
 * nobody will ever see, which is worse than a run that stops and says why.
 *
 * WHOSE CLOCK A ZONE-LESS DATE IS ON. The workspace's — read through {@see CalendarInstantResolver},
 * the Calendar's single statement of that rule, which POST /calendar/events uses too. This step is the
 * third door into `calendar_events` and for a while it was the one that disagreed: a bare
 * `Carbon::parse()` reads `config('app.timezone')`, so a Warsaw workspace automating "14:00" stored
 * 14:00Z through a run and 12:00Z through the API, for the same text, with nothing anywhere complaining.
 * The equivalence of the two doors is now pinned by test, because it is not visible from either side.
 *
 * The rule is applied to the TEXT THE AUTHOR WROTE, not to what the variable resolver hands back — see
 * {@see resolveText()}. That distinction is load-bearing, not pedantry.
 *
 * ATTRIBUTION IS AUTOMATIC, WHICH IS EXACTLY WHY IT IS TESTED. Nothing here sets a creator:
 * {@see \App\Traits\HasCreator} stamps `creator_type = 'workflow_run'` plus the run's id because
 * WorkflowStepRunner publishes the executing run to WorkflowRunContext around the whole step loop.
 * Machinery that works by itself is machinery that breaks without anyone noticing, so
 * CalendarEventWorkflowStepTest pins it rather than trusting it.
 *
 * Output: event_id, title.
 */
class CreateEventStep implements WorkflowStep
{
    /** The `calendar_events.title` column width — resolved titles are clamped to it. */
    private const TITLE_MAX = 255;

    public function __construct(
        private CalendarEventService $events,
        private VariableResolver $resolver,
        private CalendarInstantResolver $instants,
    ) {}

    public function type(): WorkflowStepType
    {
        return WorkflowStepType::CREATE_EVENT;
    }

    public static function outputDescriptors(): array
    {
        return [
            ['name' => 'event_id', 'type' => VariableType::TEXT],
            ['name' => 'title', 'type' => VariableType::TEXT],
        ];
    }

    public function run(array $config, WorkflowRun $run, array $context): array
    {
        $event = $this->events->create($this->toDto($config, $context));

        return [
            'event_id' => $event->id,
            'title' => $event->title,
        ];
    }

    /** The event this config describes, through whichever named constructor its discriminator picks. */
    private function toDto(array $config, array $context): CalendarEventDTO
    {
        $title = $this->requireString($config, 'title');
        $description = $this->resolveDescription($config);

        if ((bool) ($config['all_day'] ?? false)) {
            return CalendarEventDTO::allDay(
                title: $title,
                date: $this->requireDay($config, 'start_date', $context),
                description: $description,
            );
        }

        $startsAt = $this->requireInstant($config, 'starts_at', $context);

        return CalendarEventDTO::timed(
            title: $title,
            startsAt: $startsAt,
            endsAt: $this->resolveEnd($config, $context, $startsAt),
            description: $description,
        );
    }

    /**
     * The title is already reference-resolved by the runner, so a variable that produced nothing
     * arrives as ''. Clamped to the destination column, because the length of a RESOLVED value is
     * unknowable when the workflow is written and a raw SQL overflow is a worse failure than a
     * truncated title.
     */
    private function requireString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException("create_event step requires a non-empty `{$key}`.");
        }

        return mb_substr($value, 0, self::TITLE_MAX);
    }

    private function resolveDescription(array $config): ?string
    {
        $value = $config['description'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The DAY an all-day event falls on, as the plain 'Y-m-d' the DTO demands, reckoned on the
     * WORKSPACE's clock.
     *
     * The docblock here used to claim there was "no third answer" — that an all-day event has no zone to
     * convert into, so the day could only be re-printed in the value's own offset. That was wrong, and
     * the rest of the module already used the third answer: the grid is drawn in the workspace's zone. A
     * variable carrying a real 23:30Z instant is drawn on the 13th in Warsaw, so an all-day event derived
     * from it that printed the 12th sat one square behind the very run that produced it.
     *
     * A bare `2026-02-01` still round-trips, on any workspace including one west of UTC, because the rule
     * is applied to the author's own text — which names no zone, so it is read as midnight on the
     * workspace's clock and prints as the day it says.
     */
    private function requireDay(array $config, string $key, array $context): string
    {
        $day = $this->instants->toDay($this->requireText($config, $key, $context));

        if ($day === null) {
            throw new RuntimeException("create_event step requires a resolvable `{$key}`.");
        }

        return $day;
    }

    private function requireInstant(array $config, string $key, array $context): CarbonImmutable
    {
        $instant = $this->instants->toUtc($this->requireText($config, $key, $context));

        if ($instant === null) {
            throw new RuntimeException("create_event step requires a resolvable `{$key}`.");
        }

        return $instant;
    }

    /**
     * The optional end. SOFT throughout: absent, unresolvable, unparseable — or BEFORE the start, which
     * a run-time variable can perfectly well produce — all yield null. An event with no stated end is
     * ordinary; an event that ends before it begins has no rendering, so dropping the end keeps the
     * event and loses only the part that made no sense.
     */
    private function resolveEnd(array $config, array $context, CarbonImmutable $startsAt): ?CarbonImmutable
    {
        $text = $this->resolveText($config, 'ends_at', $context);
        $endsAt = $text === null ? null : $this->instants->toUtc($text);

        return $endsAt !== null && $endsAt->greaterThanOrEqualTo($startsAt) ? $endsAt : null;
    }

    private function requireText(array $config, string $key, array $context): string
    {
        return $this->resolveText($config, $key, $context)
            ?? throw new RuntimeException("create_event step requires a resolvable `{$key}`.");
    }

    /**
     * The TEXT one literal|variable DATE field names a moment with — which is NOT always what the
     * variable resolver returns, and the difference is the whole reason this method exists.
     *
     * The resolver is still the gate: it runs the reference lookup and any pipeline, and its DATE
     * coercion is what proves the value is a time at all. But that coercion is `Carbon::parse(...)
     * ->toIso8601String()`, and a bare `Carbon::parse()` reads `config('app.timezone')` — so by the time
     * a zone-less literal comes back it has been STAMPED `+00:00` and looks exactly like a caller who
     * said `Z`. Handing that to the shared rule would ask "did the author name a zone?" of a string the
     * author never wrote, and the answer would always be yes.
     *
     * So when the field is a LITERAL — the author typing a date into the step editor, and the case the
     * whole defect was about — the author's own bytes are what the rule reads. A VARIABLE has no such
     * bytes: whatever it referenced was normalized to an absolute instant before this step could see it,
     * and taking that instant as given is the SAME rule ("an explicit zone wins"), applied to what
     * actually arrives. That is also the right answer for the values variables usually carry — a step
     * output, a run timestamp — which are genuine moments and not wall times.
     *
     * Null means "nothing usable here": absent, unresolvable, or not a time.
     */
    private function resolveText(array $config, string $key, array $context): ?string
    {
        $field = $config[$key] ?? null;

        $resolved = $this->resolver->resolveValueOrVariable($field, $context, VariableType::DATE);

        if (!is_string($resolved) || $resolved === '') {
            return null;
        }

        return $this->literalText($field) ?? $resolved;
    }

    /** The author's own text for a field, when the field is a literal one. Null for anything else. */
    private function literalText(mixed $field): ?string
    {
        if (is_string($field)) {
            return $field !== '' ? $field : null;
        }

        if (!is_array($field) || ($field['kind'] ?? 'literal') !== 'literal') {
            return null;
        }

        $value = $field['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
