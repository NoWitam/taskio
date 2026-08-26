<?php

namespace App\Modules\Calendar\Models;

use App\Models\AbstractModel;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A CALENDAR EVENT — the one subject the Calendar module owns rather than borrows.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT AN EVENT IS, STATED HERE SO IT CANNOT DRIFT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * An event is an ANNOTATION ON A TIMELINE: a meeting, a launch, a milestone, a deadline somebody wants
 * the team to see. It records that something is happening on a date.
 *
 * NOTHING EVER EXECUTES BECAUSE AN EVENT EXISTS. No trigger reads this table, no sweep scans it, no
 * job wakes on it — and none ever will. That is the whole fence, and it is written down because the
 * word "event" attracts exactly the opposite reading: the moment one thing fires from a row here, the
 * table is a second scheduler competing with `workflows.trigger_config`, with its own half of the
 * cadence vocabulary, its own timezone story and its own reasons a thing did not run. The product
 * already HAS a scheduler, it lives in the Workflows module, and the plan for this chapter says not to
 * duplicate it. Without this paragraph "event" becomes a drawer for anything time-shaped within two
 * chapters.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * AN EVENT MAY REPEAT — AND REPEATING STILL EXECUTES NOTHING
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * R3 B4 added `recurrence` + `recurrence_until`, and the fence above is UNCHANGED by them. A recurring
 * event is a repeating ANNOTATION: it draws more squares, it wakes nothing. Nothing sweeps this table
 * on a cadence, no job is armed from `recurrence_until`, and there is no `next_due_at` here — the
 * occurrences are COMPUTED when a grid is drawn and stored nowhere.
 *
 * This was the one column the earlier chapter refused outright, on the grounds that a repeating event
 * needs a projection engine and this codebase had exactly one, inside a module the Calendar may not
 * name. That objection was answered by moving the engine BELOW both modules rather than by copying it:
 * the cadence arithmetic now lives in the shared recurrence layer (ADR-0052), the Calendar drives it
 * through {@see \App\Modules\Calendar\Services\CalendarRecurrenceService}, and there is still exactly
 * one definition of "every other Tuesday" in the product. What the earlier text refused — a SECOND
 * engine — is still refused.
 *
 * The rule sits in COLUMNS on this row rather than in a series table because a series here is one
 * repeating description of one event. A table earns its keep only when a single occurrence can be
 * overridden, and it cannot: editing one occurrence DETACHES it into an ordinary non-repeating event,
 * and deleting one adds a date to the rule's own exclusions. Both are compositions of things that
 * already existed. See the migration for the full argument, and
 * {@see \App\Modules\Calendar\DTOs\CalendarRecurrence} for the narrow subset of the grammar this
 * module accepts and why narrowing later would not be possible.
 *
 * THE SERIES IS ANCHORED AT THIS ROW'S OWN START, and the write path refuses a rule the anchor does
 * not satisfy. So `starts_at`/`start_date` still means FIRST OCCURRENCE, never "the point we begin
 * counting from", and the series' duration is `ends_at - starts_at` applied to every occurrence — an
 * hour-long meeting stays an hour long across a daylight-saving transition.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE ALL-DAY DISCRIMINATOR HOLDS ON THE WRITE SIDE TOO
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `all_day` says which pair of columns carries this row's place in time, and the two are mutually
 * exclusive:
 *
 *   all_day = true   → `start_date` ('Y-m-d', zone-free). `starts_at`/`ends_at` are NULL.
 *   all_day = false  → `starts_at` (+ optional `ends_at`) as UTC instants. `start_date` is NULL.
 *
 * A row that carried both would be a row nobody can read: the reader branches on `all_day`, and the
 * branch it does not take is data that silently means nothing — or worse, gets converted (a zone-free
 * day read as an instant moves a day for half the world; the full argument lives on
 * {@see \App\Modules\Calendar\DTOs\CalendarOccurrence}). So the invariant is enforced STRUCTURALLY
 * rather than by validation alone: {@see \App\Modules\Calendar\DTOs\CalendarEventDTO} has a private
 * constructor and two named constructors that are incapable of holding each other's fields, and
 * {@see \App\Modules\Calendar\Services\CalendarEventService} is the only writer. The FormRequest
 * refuses the incoherent payload before either is reached, so the API answers 422 rather than saving
 * something unreadable.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * subject_type / subject_id — A POINTER, NEVER A DOCUMENT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Optional "this event is about that thing": a morph ALIAS plus an id, and NO `morphTo()` relation.
 * It is the same doctrine the knowledge-binding table already follows (a consumer addressed by
 * primitives, stored, and never resolved back to a class), adopted here deliberately rather than
 * reinvented — that module's own boundary test forbids this one from naming it, which is itself the
 * argument. Resolving the alias to a class would make the Calendar depend on every module
 * it can point at, and the Calendar knowing nobody is the property the whole module exists to protect
 * (pinned literally, over the file bytes, by CalendarModuleBoundaryTest). The Calendar hands the alias
 * and the id to the client and its knowledge ends there. A pointer whose target has been deleted is
 * inert, because nothing ever follows it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * AN EVENT HAS NO COLOUR, AND THERE IS NO COLUMN FOR ONE
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * {@see \App\Modules\Calendar\Enums\CalendarColor} is a vocabulary of MEANINGS, not a palette: a task's
 * deadline colours by priority, an automation run by how it ended, a schedule projection by being a
 * projection. Each is a fact about its subject that the grid can explain. An event states no such fact,
 * so a human picking from the same six values was decorating with the vocabulary the other three sources
 * speak — red meaning "urgent", "failed" and nothing at all, side by side in one grid.
 *
 * The event source therefore emits a CONSTANT ({@see \App\Modules\Calendar\Sources\EventCalendarSource}),
 * and this row stores nothing. If a future chapter wants events to be visually groupable, the honest
 * shape is a CATEGORY — a named thing whose colour is a property of the category — not a colour column
 * back on the row.
 *
 * Soft-deleted, and workspace-scoped through TenantAware — so the read source needs no workspace
 * predicate of its own, and a trashed event leaves the grid without leaving the database.
 */
class CalendarEvent extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    protected $table = 'calendar_events';

    protected $fillable = [
        'title',
        'description',
        'all_day',
        'start_date',
        'starts_at',
        'ends_at',
        'recurrence',
        'recurrence_until',
        'subject_type',
        'subject_id',
        'creator_id',
    ];

    protected $casts = [
        'all_day' => 'boolean',
        // `date`, not `datetime`: the stored value has no time and no zone. Reading it back and
        // format('Y-m-d')ing it is a RE-PRINT of the stored day, never a conversion — the same
        // treatment `tasks.deadline` gets.
        'start_date' => 'date',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        // The v2 recurrence descriptor, exactly as the shared engine reads it. NULL means the event
        // happens once — the state every row was in before R3 B4 and the state a write that says
        // nothing about repeating leaves it in.
        'recurrence' => 'array',
        // A DAY, cast like `start_date` and for the identical reason: it has no time and no zone, so
        // reading it back and format('Y-m-d')ing it re-prints the stored day rather than converting it.
        'recurrence_until' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The stored day of an all-day event, as the plain 'Y-m-d' the occurrence DTO demands — or null
     * for a timed event.
     *
     * Exists so the ONE place that re-prints the date is named and shared. A caller that reached for
     * `->start_date->format(...)` itself is one refactor away from reaching for `->toISOString()`
     * instead, which is the conversion this whole model is arranged to prevent.
     */
    public function startDateString(): ?string
    {
        return $this->all_day ? $this->start_date?->format('Y-m-d') : null;
    }

    /** Whether this event repeats. The discriminator every scoped operation checks first. */
    public function repeats(): bool
    {
        return $this->recurrence !== null;
    }

    /**
     * The last day the series may place an occurrence on, as the plain 'Y-m-d' the recurrence value
     * object demands — or null for a series with no end, and for an event that does not repeat.
     *
     * Exists for the same reason {@see startDateString()} does: the ONE place a stored day is
     * re-printed is named and shared, so nobody reaches for `->toISOString()` on a value that has no
     * zone to convert to.
     */
    public function recurrenceUntilString(): ?string
    {
        return $this->repeats() ? $this->recurrence_until?->format('Y-m-d') : null;
    }

    protected static function newFactory()
    {
        return \Database\Factories\CalendarEventFactory::new();
    }
}
