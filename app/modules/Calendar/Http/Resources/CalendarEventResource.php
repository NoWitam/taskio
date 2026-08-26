<?php

namespace App\Modules\Calendar\Http\Resources;

use App\Http\Resources\CreatorResource;
use App\Modules\Calendar\DTOs\CalendarRecurrence;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarRecurrenceService;
use App\Modules\Calendar\Support\CalendarCadenceLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ONE calendar event, in full — the shape the editor reads and writes back.
 *
 * Distinct from {@see CalendarOccurrenceResource}, which is what an event looks like ON THE GRID
 * (alongside tasks and automation runs, in a vocabulary none of those modules had to agree on). This is
 * the event ITSELF, and it carries two things an occurrence deliberately does not: the event's own
 * `subject` pointer, and the capability flags.
 *
 * THE TIME DISCRIMINATOR IS THE SAME ONE, worded identically on purpose. Read `all_day` first; all
 * three time keys are always present (shape-stable responses are the house convention) but exactly one
 * group is populated:
 *
 *   all_day = true   → `start_date` is a plain calendar day, 'Y-m-d'. It has NO zone: never parse it as
 *                      an instant and never convert it. `starts_at`/`ends_at` are null.
 *   all_day = false  → `starts_at` (and possibly `ends_at`) are ISO-8601 UTC instants, rendered in the
 *                      workspace timezone the occurrence endpoint names in its `meta`. `start_date` is
 *                      null.
 *
 * THERE IS NO `color` KEY, on this resource or in the payload that writes it. Colour is a vocabulary of
 * MEANINGS the grid explains, and an event states no fact for it to explain — so the grid gives every
 * event the same constant ({@see \App\Modules\Calendar\Sources\EventCalendarSource}) and the editor has
 * nothing to read or send. A client that posts `color` gets a 422 rather than a silent drop
 * ({@see \App\Modules\Calendar\Http\Requests\StoreCalendarEventRequest}).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `recurrence` ROUND-TRIPS; `recurrence_timezone` DOES NOT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `recurrence` is null for an event that happens once. When present it is EXACTLY the block the write
 * endpoint accepts — `{ day?, month?, exclusions?, until? }` — so a client that reads an event, edits a
 * field and PUTs the whole thing back preserves the rule without knowing anything about it. That is
 * not a convenience: an update is a WHOLE-EVENT write, so a client that dropped this block would erase
 * the series, and a client that dropped only `exclusions.dates` would resurrect every occurrence
 * somebody had deleted one at a time.
 *
 * Two keys the stored rule carries are deliberately NOT in it:
 *
 *   `time`  the series' hour is the event's own hour and is already on this payload as `starts_at`.
 *           Stating it twice would create two places for one fact to be true.
 *   `tz`    it is not the caller's to send (the write refuses it), so echoing it inside a block meant
 *           to be sent back would make the round trip fail. It is published beside the block instead,
 *           as `recurrence_timezone`, READ-ONLY.
 *
 * `recurrence_timezone` is the clock the rule was STAMPED on at save time, which is not necessarily
 * the workspace's clock today. That gap is the whole point of stamping (see the migration), and a
 * client rendering "every Monday at 09:00" needs to know which 09:00 it is naming. Null for an event
 * that does not repeat.
 *
 * `recurrence_label` is the SAME translated sentence every square of the series carries on the grid,
 * published here because the grid is not always there — a deep link, or a series with no occurrence
 * inside the window on screen, leaves a reader holding a rule and no way to say it. The alternative is
 * the frontend composing the prose itself, which ends the promise that a new source needs no frontend
 * change. Read-only and derived; see {@see recurrenceLabel()}.
 *
 * THERE IS NO OCCURRENCE LIST HERE, and there will not be one. This resource is the event ITSELF; the
 * days a series falls on are drawn on the GRID, through the occurrences endpoint, merged with every
 * other source. A projection on this payload would be a second answer to "when does this happen",
 * computed over a window nobody named.
 *
 * `subject` is the event's optional POINTER — "this event is about that thing" — as a morph alias plus
 * an id, or null. It is NOT dereferenced and carries no name or preview, because resolving it would
 * make the Calendar depend on every module it can point at. A client that wants a label deep-links to
 * the subject's own module and asks there.
 *
 * Note the asymmetry with the occurrence, which is intentional and worth stating so it is not
 * "corrected": on the GRID an event's `subject` is the EVENT ITSELF (that is what the square is, and
 * what a deep-link from it should open). Here, `subject` is what the event REFERS to. Two different
 * questions, two different payloads, neither renamed to look like the other.
 */
class CalendarEventResource extends JsonResource
{
    /**
     * The parsed rule, memoized for the life of this resource. THREE keys below ask for it —
     * `recurrence`, `recurrence_timezone`, `recurrence_label` — and each was paying for its own parse
     * of the same column, so a repeating event cost three where one does.
     *
     * NO CLAIM ABOUT A LIST IS MADE HERE, and an earlier draft of this comment made one: it justified
     * the memo by "a collection endpoint's cost multiplied by its page size". There is no such
     * endpoint — the module exposes `POST`, `GET /{event}`, `PUT` and `DELETE`, and this resource is
     * only ever built through `::make()` on a single row. The saving is real and small, three parses
     * down to one on every single-event response; the reason given for it has to be the real one,
     * because a comment that argues from a fact nobody checked is worse than no comment.
     *
     * The resolved FLAG is separate from the value because null is a real answer here (the row does
     * not repeat, or its descriptor is unreadable), and `??=` cannot tell that from "not asked yet".
     */
    private ?CalendarRecurrence $series = null;

    private bool $seriesResolved = false;

    public function toArray(Request $request): array
    {
        /** @var CalendarEvent $event */
        $event = $this->resource;

        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,

            'all_day' => $event->all_day,
            // A re-print of the stored day through the model's one accessor, never a conversion.
            'start_date' => $event->startDateString(),
            'starts_at' => $event->starts_at?->toISOString(),
            'ends_at' => $event->ends_at?->toISOString(),

            'recurrence' => $this->recurrence($event),
            'recurrence_timezone' => $this->series($event)?->timezone(),
            'recurrence_label' => $this->recurrenceLabel($event),

            'subject' => $event->subject_type === null ? null : [
                'type' => $event->subject_type,
                'id' => $event->subject_id,
            ],

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            // `is_owner` is HUMAN authorship only; the `can_*` flags route through the policy and so
            // include the workspace-owner fallback. For an event created by a workflow run the two
            // legitimately disagree (ADR-0015) — gate UI actions on `can_*`, never on `is_owner`.
            'is_owner' => $event->isOwnedBy($request->user()),
            'can_be_edited' => $request->user()?->can('update', $event) ?? false,
            'can_be_deleted' => $request->user()?->can('delete', $event) ?? false,

            'created_at' => $event->created_at?->toISOString(),
            'updated_at' => $event->updated_at?->toISOString(),
        ];
    }

    /**
     * The rule, in the SHAPE THE WRITE ENDPOINT ACCEPTS — the stored descriptor minus the two keys a
     * caller may not send, plus the end date that lives in its own column.
     *
     * Built by naming the keys that go OUT rather than by unsetting the ones that stay in. A future
     * key added to the stored descriptor is then absent from this payload until somebody decides it
     * belongs, instead of leaking into a round trip that the write endpoint would then refuse.
     *
     * ALL FOUR KEYS ARE ALWAYS PRESENT, null where the rule says nothing — the same shape-stability the
     * three time keys above have, and for the same reason: a client should branch on a value, never on
     * whether a key exists. A null `day` means every day and a null `month` means every month, which is
     * exactly what the write endpoint reads them as.
     *
     * READ THROUGH THE SAME DEGRADING PATH EVERY OTHER READER USES, not straight off the column. A
     * descriptor this module can no longer mean — a console fix, a restored dump — is "not a series"
     * everywhere else; emitting it here as though it round-trips would hand a client a block the write
     * endpoint then refuses, and the client would have no way to tell that from its own mistake.
     *
     * @return array<string, mixed>|null
     */
    private function recurrence(CalendarEvent $event): ?array
    {
        $series = $this->series($event);

        if ($series === null) {
            return null;
        }

        return [
            'day' => $series->descriptor['day'] ?? null,
            'month' => $series->descriptor['month'] ?? null,
            'exclusions' => $series->descriptor['exclusions'] ?? null,
            'until' => $series->until,
        ];
    }

    /**
     * HOW OFTEN THIS EVENT REPEATS, as the same translated sentence the grid puts on every square of
     * the series — or null for an event that happens once.
     *
     * IT IS HERE BECAUSE THE GRID IS NOT ALWAYS THERE. An occurrence carries the sentence, so a reader
     * who arrived by clicking a square already has it; a reader who arrived by DEEP LINK has not, and
     * neither has one looking at a series with no occurrence in the window currently on screen (a rule
     * that ran last spring, opened from a search result). Without this key the editor would have to
     * say nothing about the cadence, or the frontend would have to compose the sentence itself — and
     * the moment a client can build this prose, "a new source needs no frontend change" is over,
     * because the client now owns a vocabulary the server was supposed to own.
     *
     * READ-ONLY and derived, like `recurrence_timezone` beside it: a caller sends `recurrence`, never
     * a sentence about it. It is deliberately NOT inside the `recurrence` block, which round-trips
     * verbatim to the write endpoint and would be refused if it grew a key the endpoint does not
     * accept.
     *
     * Null covers two cases a client treats identically — the event does not repeat, and the rule is
     * one this module cannot render a sentence for. Both mean "there is no cadence to show here".
     */
    private function recurrenceLabel(CalendarEvent $event): ?string
    {
        $series = $this->series($event);

        return $series === null ? null : app(CalendarCadenceLabel::class)->for($series);
    }

    /** The event's rule, through the ONE degrading reader every other reader of a row uses. */
    private function series(CalendarEvent $event): ?CalendarRecurrence
    {
        if (!$this->seriesResolved) {
            $this->series = app(CalendarRecurrenceService::class)->seriesOf($event);
            $this->seriesResolved = true;
        }

        return $this->series;
    }
}
