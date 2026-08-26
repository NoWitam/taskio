<?php

namespace App\Modules\Calendar\Http\Resources;

use App\Modules\Calendar\DTOs\CalendarOccurrence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wire shape of one occurrence.
 *
 * @property-read CalendarOccurrence $resource
 */
class CalendarOccurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CalendarOccurrence $occurrence */
        $occurrence = $this->resource;

        return [
            'id' => $occurrence->id,
            'source' => $occurrence->source,
            'editable' => $occurrence->editable,

            // THE DISCRIMINATOR. Read this first; it says which of the three time fields below carries
            // the answer. All three keys are always present (a shape-stable response is the house
            // convention and keeps the frontend's types honest), but exactly one shape is populated:
            //
            //   all_day = true   → start_date is a plain calendar day, 'Y-m-d'. It has NO zone and must
            //                      never be parsed as an instant or converted — that is precisely how a
            //                      deadline lands on the wrong square. starts_at/ends_at are null.
            //   all_day = false  → starts_at (and possibly ends_at) are ISO-8601 UTC instants, to be
            //                      rendered in the timezone named in meta. start_date is null.
            'all_day' => $occurrence->allDay,
            'start_date' => $occurrence->startDate,
            'starts_at' => $occurrence->startsAt?->toISOString(),
            'ends_at' => $occurrence->endsAt?->toISOString(),

            'title' => $occurrence->title,
            'color' => $occurrence->color->value,
            'badge' => $occurrence->badge === null ? null : [
                // Already translated by the source that owns the vocabulary — see CalendarBadge.
                'label' => $occurrence->badge->label,
                'color' => $occurrence->badge->color->value,
            ],

            // "There is more of this than you can see here." Never silently true and invisible.
            'dense' => $occurrence->dense,

            // How often the subject repeats, as PROSE the source already translated — "Every 5 min" —
            // or null when the source has no cadence. It is what makes `dense` worth rendering: without
            // it a client can only say "showing 64 of a series", which a reader could have counted.
            // Prose rather than a code for the same reason the badge is (see CalendarBadge): a coded
            // cadence would need a client-side vocabulary per source, and a new source would need a
            // frontend change. Always PRESENT, often null — an absent key and a null one must not be
            // two different things a client has to handle.
            'cadence_label' => $occurrence->cadenceLabel,

            // WHETHER THIS SQUARE WAS COMPUTED FROM A RULE, stated outright rather than left to be
            // inferred from `cadence_label` being non-null. That inference is sound today and only
            // today: a repeating subject is ALLOWED to have no sentence (a cadence nobody can render
            // leaves the label null), so a client branching on the prose would be right by accident.
            'recurring' => $occurrence->recurring,

            // WHICH occurrence of its series this is, as the plain 'Y-m-d' its own write surface names
            // it by — reckoned on the SERIES' clock, not the window's. Null for a square that is not
            // one of a series. Present so a client can act on a single occurrence the moment it is
            // clicked, without fetching the subject first and without parsing `id` — which is exactly
            // what `subject.id` exists to make unnecessary.
            'occurrence_date' => $occurrence->occurrenceDate,

            // Morph ALIAS + id, so a client can deep-link to the underlying thing without the calendar
            // having to know a route for every module.
            'subject' => [
                'type' => $occurrence->subjectType,
                'id' => $occurrence->subjectId,
            ],
        ];
    }
}
