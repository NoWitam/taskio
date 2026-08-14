<?php

namespace App\Modules\Calendar\Http\Requests;

/**
 * Validates a CALENDAR EVENT on update. Same rules as {@see StoreCalendarEventRequest} — deliberately,
 * because an update here is a WHOLE-EVENT write, not a patch.
 *
 * That is the honest shape for this payload rather than a shortcut. The all-day discriminator decides
 * which columns carry the event's place in time, so a partial update that changed `all_day` alone
 * would have to leave the row incoherent or guess at the other side; and a partial update that
 * changed `starts_at` alone on an all-day event would have to decide whether the caller meant to
 * convert it. Both are questions with no defensible default. Sending the whole event means the caller
 * has already answered them.
 *
 * The only difference is WHO may do it: creation asks the policy about the class, an update asks it
 * about this row (creator or workspace owner — see CalendarEventPolicy).
 */
class UpdateCalendarEventRequest extends StoreCalendarEventRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event !== null && ($this->user()?->can('update', $event) ?? false);
    }
}
