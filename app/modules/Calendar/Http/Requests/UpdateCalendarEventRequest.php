<?php

namespace App\Modules\Calendar\Http\Requests;

use App\Modules\Calendar\Enums\CalendarEventScope;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarRecurrenceService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

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
 * THE RULE IS PART OF THE WHOLE EVENT, and this is the one consequence worth stating out loud: an
 * update that omits `recurrence` REMOVES the recurrence, exactly as an update that omits `description`
 * clears the description. A client editing a series must send the rule back — INCLUDING its
 * `exclusions.dates`, or the occurrences somebody deleted one by one will reappear. The resource emits
 * that block in the shape this request accepts, so echoing it back is the whole of the obligation.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `scope` — HOW MUCH OF A SERIES THIS EDIT IS ABOUT
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Absent means {@see CalendarEventScope::SERIES}, and that path is byte-for-byte the behaviour that
 * existed before series did. Nothing about an existing client's payload changes meaning.
 *
 *   `series` (default)  the whole event, as above.
 *   `occurrence`        ONE occurrence, named by `occurrence_date`. It is excluded from the rule and
 *                       re-created as a plain, non-repeating event carrying this payload — a DETACH.
 *                       A payload for one occurrence may not carry a `recurrence` of its own: one
 *                       occurrence of a series is not itself a series, and accepting the key would
 *                       have to either ignore it or invent a nested series nobody asked for.
 *   `following`         this occurrence and every later one. The old series is closed the day before
 *                       and this payload becomes a NEW event from `occurrence_date` onward. It MAY
 *                       carry a new rule (that is usually the point) and may carry none, which turns
 *                       the remainder into a single event.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE RESOURCE THIS ENDPOINT RETURNS IS "WHAT YOU ARE LOOKING AT AFTERWARDS", NOT ALWAYS THE URL
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Under `occurrence` and (usually) `following`, the event this request produces is a NEW ROW with a NEW
 * ID, and that row is what comes back — the URL named the series being edited, the response names the
 * thing the user is now looking at. The one exception is a `following` edit at the series' FIRST
 * occurrence, which has no past to preserve and is therefore an in-place whole-event write returning
 * the same id.
 *
 * THE STATUS CODE CARRIES THIS, so it is not only prose: **200** means the event named in the URL came
 * back, **201** means a new one did. A client that holds an id can tell, from the status alone, that
 * the id it holds has stopped being the one to edit next.
 *
 * This is stated rather than left to be discovered because a client that assumes the id is stable will
 * silently keep editing the OLD series. A separate verb was considered and rejected: it would have
 * duplicated the entire event payload and its validation for the sake of one field, and the ambiguity
 * is in the CONTRACT — which a status code and documentation can settle — not in the shape, which they
 * cannot.
 *
 * The only difference in WHO may do it: creation asks the policy about the class, an update asks it
 * about this row (creator or workspace owner — see CalendarEventPolicy). A scoped update is judged on
 * the SERIES' row, which is the right subject: splitting or detaching an occurrence is an edit of that
 * series, whoever ends up owning the row it produces.
 */
class UpdateCalendarEventRequest extends StoreCalendarEventRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event !== null && ($this->user()?->can('update', $event) ?? false);
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // Un-prohibited: on an existing event these are the only two keys that mean anything about
            // a series, and the parent refuses them precisely because on CREATE they do not.
            'scope' => ['nullable', Rule::in(CalendarEventScope::values())],
            'occurrence_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $this->validateScope($validator);
        });
    }

    /**
     * The scope, and everything that must be true of the occurrence it names — delegated whole to
     * {@see CalendarRecurrenceService::scopeViolations()}, which `DELETE` asks the identical question
     * of. Plus the one rule only an EDIT has: a single occurrence carries no rule of its own.
     */
    private function validateScope(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['scope', 'occurrence_date'])) {
            return;
        }

        /** @var CalendarEvent|null $event */
        $event = $this->route('event');

        if ($event === null) {
            return;
        }

        $scope = $this->resolvedScope();
        $service = app(CalendarRecurrenceService::class);
        $occurrenceDate = $this->resolvedOccurrenceDate();

        $violations = $service->scopeViolations($event, $scope, $occurrenceDate);

        foreach ($violations as [$path, $message]) {
            $validator->errors()->add($path, $message);
        }

        if ($violations !== []) {
            return;
        }

        if ($scope === CalendarEventScope::OCCURRENCE && $this->filled('recurrence')) {
            $validator->errors()->add('recurrence', __('calendar.validation.recurrence.occurrence_has_no_rule'));
        }

        if ($scope === CalendarEventScope::FOLLOWING) {
            $this->validateSplitStart($validator, $service, $event, (string) $occurrenceDate);
        }
    }

    /**
     * A SPLIT MAY NOT REACH BACK BEHIND ITSELF: the new event has to start on or after the occurrence
     * the split was made at.
     *
     * Without this, "this and all following, but starting a week earlier" would leave the old series
     * closed on the day before the split AND a new one drawing squares inside the range the old one
     * still covers — two rows answering for the same days, from one edit nobody would describe that
     * way.
     *
     * The comparison happens on the OLD series' stamped clock, because that is the clock
     * `occurrence_date` is expressed on. An all-day payload is compared on its own zone-free day, which
     * is not on any clock at all and needs no conversion.
     *
     * NOT APPLIED when the split would leave nothing behind: that case is not a split at all, it is a
     * whole-event edit ({@see \App\Modules\Calendar\Services\CalendarEventService::updateFollowing()}),
     * and moving the whole series earlier is exactly what a whole-event edit is allowed to do.
     *
     * That condition is asked THROUGH THE SAME METHOD the writer branches on, not restated as a date
     * comparison. The two must agree: a guard that refused a payload the writer would then have handled
     * as an ordinary edit is a 422 with no defect behind it, and nobody reading either side alone would
     * see the disagreement.
     */
    private function validateSplitStart(
        Validator $validator,
        CalendarRecurrenceService $service,
        CalendarEvent $event,
        string $occurrenceDate,
    ): void {
        $series = $service->seriesOf($event);

        if ($series === null || !$service->splitLeavesSomethingBehind($event, $series, $occurrenceDate)) {
            return;
        }

        $allDay = $this->boolean('all_day');

        $newAnchorDay = $allDay
            ? ($this->filled('start_date') ? $this->string('start_date')->value() : null)
            : $this->resolvedStartsAt()?->setTimezone($series->timezone())->format('Y-m-d');

        if ($newAnchorDay !== null && $newAnchorDay < $occurrenceDate) {
            $validator->errors()->add(
                $allDay ? 'start_date' : 'starts_at',
                __('calendar.validation.recurrence.split_starts_before_the_split'),
            );
        }
    }

    /**
     * THE CLOCK THIS EDIT MUST KEEP — the update half of the seam the create request declares.
     *
     * A series is re-stamped only when the edit MOVES its anchor; an edit that leaves the anchor where
     * it is keeps the clock the series was laid out on. Without that, a workspace timezone change made
     * an existing series unsaveable: the stamp ruled how it was READ while the current zone ruled how it
     * was RE-VALIDATED, and a title-only save came back 422 on a start nobody had touched. The whole
     * rule, and the argument for it, is on {@see CalendarRecurrenceService::timezoneCarriedBy()}.
     *
     * TWO SCOPES ANSWER DIFFERENTLY, and the difference is which anchor the write is about:
     *   `series`     the row's own start.
     *   `following`  the OCCURRENCE the split is made at. The new row continues a series from a point
     *                that series already fires on, so the split is a cut rather than a re-authoring —
     *                and without this it is the identical lock-out one door further along.
     *   `occurrence` nothing: the payload may not carry a rule at all, and what it produces is a plain
     *                non-repeating row. Asking would be asking about a series that is not being written.
     *
     * The scope and the date are read RAW rather than trusted: this runs inside validation, possibly
     * before their own rules have reported. A garbage date simply matches nothing, which lands on the
     * workspace's current zone — the behaviour that shipped — and the payload is refused anyway.
     */
    protected function carriedRecurrenceTimezone(): ?string
    {
        $event = $this->route('event');

        if (!$event instanceof CalendarEvent) {
            return null;
        }

        $scope = $this->resolvedScope();

        if ($scope === CalendarEventScope::OCCURRENCE) {
            return null;
        }

        return app(CalendarRecurrenceService::class)->timezoneCarriedBy(
            $event,
            $this->boolean('all_day'),
            $this->filled('start_date') ? $this->string('start_date')->value() : null,
            $this->resolvedStartsAt(),
            $scope === CalendarEventScope::FOLLOWING ? $this->resolvedOccurrenceDate() : null,
        );
    }

    /** The scope this write is about. Absent means the whole event — today's contract, unchanged. */
    public function resolvedScope(): CalendarEventScope
    {
        return CalendarEventScope::tryFrom((string) $this->input('scope', '')) ?? CalendarEventScope::SERIES;
    }

    /** The occurrence a scoped write names, on the series' own stamped clock. */
    public function resolvedOccurrenceDate(): ?string
    {
        return $this->filled('occurrence_date') ? $this->string('occurrence_date')->value() : null;
    }
}
