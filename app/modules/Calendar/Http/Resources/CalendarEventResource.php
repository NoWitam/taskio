<?php

namespace App\Modules\Calendar\Http\Resources;

use App\Http\Resources\CreatorResource;
use App\Modules\Calendar\Models\CalendarEvent;
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
}
