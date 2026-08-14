<?php

namespace App\Modules\Calendar\Http\Requests;

use App\Modules\Calendar\DTOs\CalendarWindow;
use App\Modules\Calendar\Services\CalendarSourceRegistry;
use App\Modules\Calendar\Services\CalendarTimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates one calendar read and turns it into a {@see CalendarWindow}.
 *
 * AUTHORIZATION IS THE ROUTE'S, NOT A POLICY'S — deliberately, and worth stating so nobody "fixes" it
 * later. There is no calendar model to authorize against: the endpoint returns whatever the ACTIVE
 * WORKSPACE contains, and membership of that workspace is already proven upstream by ResolveWorkspace
 * (403 for a non-member) with RequireWorkspace refusing the request outright when no workspace is named
 * (400). Per-subject visibility stays where it belongs — inside each source, which queries through its
 * own module's scopes. Inventing a CalendarPolicy here would add a second, weaker answer to a question
 * that is already answered.
 *
 * `from`/`to` are CALENDAR DAYS in the workspace's timezone, not instants, and there is no `tz`
 * parameter — see CalendarTimezoneResolver for why the client does not get a vote.
 */
class CalendarWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],

            // An unknown source id is a 422 rather than a silent drop. A filter that names something
            // the server does not have is a stale saved view or a typo, and answering it with a
            // cheerful subset of the calendar is how a user comes to trust a grid that is lying.
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['string', Rule::in(app(CalendarSourceRegistry::class)->ids())],

            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            $max = $this->maxWindowDays();

            if ($this->requestedDays() > $max) {
                $validator->errors()->add('to', __('calendar.validation.window_too_large', ['max' => $max]));
            }
        });
    }

    public function toWindow(): CalendarWindow
    {
        return new CalendarWindow(
            startDate: $this->string('from')->toString(),
            endDate: $this->string('to')->toString(),
            timezone: app(CalendarTimezoneResolver::class)->resolve(),
            sources: array_values(array_unique(array_map(
                'strval',
                (array) $this->input('sources', []),
            ))),
            search: $this->filled('q') ? trim($this->string('q')->toString()) : null,
            maxOccurrencesPerItem: (int) config('calendar.max_occurrences_per_source_item', 64),
            maxOccurrences: (int) config('calendar.max_occurrences', 1000),
            maxItems: (int) config('calendar.max_source_items', 200),
        );
    }

    /**
     * Inclusive day count. Computed in UTC on purpose: this measures the SIZE of the request (how much
     * work is being asked for), and a DST boundary inside the span must not make one window legal and
     * an identical one a day later illegal.
     */
    private function requestedDays(): int
    {
        $from = CarbonImmutable::parse($this->string('from')->toString(), 'UTC')->startOfDay();
        $to = CarbonImmutable::parse($this->string('to')->toString(), 'UTC')->startOfDay();

        return (int) $from->diffInDays($to) + 1;
    }

    private function maxWindowDays(): int
    {
        return (int) config('calendar.max_window_days', 62);
    }
}
