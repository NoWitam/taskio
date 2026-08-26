<?php

namespace App\Modules\Calendar\Http\Requests;

use App\Modules\Calendar\Enums\CalendarEventScope;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarRecurrenceService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and authorizes a CALENDAR EVENT deletion.
 *
 * A FormRequest exists here where a bare `authorize()` call used to do, because a delete now has
 * something to validate: a series can be removed whole, one occurrence at a time, or from a chosen
 * occurrence onward, and each of those has to be checked against the row before anything is written.
 * Authorization moves with it — that is the house rule, and it keeps the controller a single line.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE DEFAULT IS THE OLD BEHAVIOUR, EXACTLY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * No `scope` means {@see CalendarEventScope::SERIES}: the row is soft-deleted, the response is 204,
 * and every existing client keeps working without knowing series exist. Pinned by test, not by
 * intention.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE SCOPED DELETES MODIFY THE ROW, AND STILL ANSWER 204
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   `occurrence`  the named day is added to the rule's own EXCLUSIONS. Nothing is deleted; the series
 *                 simply stops falling on that day. The exclusion removes a whole DAY rather than an
 *                 instant, which is the same thing only because a Calendar series has exactly one hour
 *                 — one more reason the accepted cadence subset is narrow.
 *   `following`   the series is closed the day BEFORE the named occurrence. When that occurrence is
 *                 the series' first, "this and all following" is the whole series and the row is
 *                 soft-deleted after all.
 *
 * Both answer 204 like any other delete: the caller asked for something to stop existing and it has.
 * What survives is the rest of the series, which the caller re-reads from the grid.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHERE THE TWO FIELDS ARE READ FROM
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `input()`, so a client may put them in a DELETE body or in the query string. Not every HTTP client
 * will send a body on a DELETE, and refusing the query string would make the feature unreachable from
 * some of them for no reason anybody could act on.
 */
class DestroyCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event !== null && ($this->user()?->can('delete', $event) ?? false);
    }

    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(CalendarEventScope::values())],
            'occurrence_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['scope', 'occurrence_date'])) {
                return;
            }

            /** @var CalendarEvent|null $event */
            $event = $this->route('event');

            if ($event === null) {
                return;
            }

            // The identical question `PUT` asks, answered in the identical place. Two copies of "is
            // this a real occurrence of this series" would disagree the first time one of them was
            // corrected.
            foreach (app(CalendarRecurrenceService::class)->scopeViolations($event, $this->resolvedScope(), $this->resolvedOccurrenceDate()) as [$path, $message]) {
                $validator->errors()->add($path, $message);
            }
        });
    }

    /** The scope this delete is about. Absent means the whole event — today's contract, unchanged. */
    public function resolvedScope(): CalendarEventScope
    {
        return CalendarEventScope::tryFrom((string) $this->input('scope', '')) ?? CalendarEventScope::SERIES;
    }

    /** The occurrence a scoped delete names, on the series' own stamped clock. */
    public function resolvedOccurrenceDate(): ?string
    {
        return $this->filled('occurrence_date') ? (string) $this->input('occurrence_date') : null;
    }
}
