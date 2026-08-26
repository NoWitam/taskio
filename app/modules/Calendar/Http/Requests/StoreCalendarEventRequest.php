<?php

namespace App\Modules\Calendar\Http\Requests;

use App\Modules\Calendar\DTOs\CalendarRecurrence;
use App\Modules\Calendar\Enums\CalendarColor;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarInstantResolver;
use App\Modules\Calendar\Services\CalendarRecurrenceService;
use App\Modules\Calendar\Services\CalendarTimezoneResolver;
use App\Support\Recurrence\Enums\ScheduleLimits;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a CALENDAR EVENT on create, and refuses — flatly — any payload that does not commit to one
 * shape in time.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE DISCRIMINATOR IS ENFORCED BOTH WAYS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   all_day = true   → `start_date` is REQUIRED and `starts_at`/`ends_at` are FORBIDDEN.
 *   all_day = false  → `starts_at` is REQUIRED and `start_date` is FORBIDDEN.
 *
 * Both halves matter, and the forbidding half is the one that would be tempting to drop. Accepting a
 * stray `starts_at` on an all-day payload and quietly ignoring it produces exactly the failure this
 * module is arranged against: the caller believes it stored a time, the grid renders a day, and
 * nothing anywhere disagrees out loud. So an over-complete payload is a 422 naming the offending
 * field, not a silently narrowed save. (The DTO makes the incoherent row unrepresentable; this makes
 * the incoherent REQUEST answerable.)
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHOSE CLOCK A ZONE-LESS INSTANT IS ON
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The read side draws the grid in the WORKSPACE's timezone (`meta.timezone`,
 * {@see CalendarTimezoneResolver}). The write side has to agree with it, and by default it did not:
 * `$request->date()` parses through `config('app.timezone')`, which is a hard `'UTC'`. A Warsaw team
 * booking 14:30 stored 14:30Z and read it back as 16:30 — silently, off by the offset. That is the
 * same family of defect the all-day discriminator exists to prevent, arriving through the other door.
 *
 * So the rule is explicit, and both halves of it are deliberate:
 *
 *   input WITHOUT a zone  → interpreted in the WORKSPACE's timezone. It is the only reading that makes
 *                           the write agree with the read: a bare "14:30" means the same wall time the
 *                           grid will show.
 *   input WITH a zone     → taken EXACTLY as given, workspace timezone ignored. A client that wrote
 *                           `+02:00` or `Z` has already said what it means; the workspace zone is an
 *                           interpretation of SILENCE, never a correction of speech.
 *
 * IT IS NOT SPELLED OUT HERE. It lives in {@see CalendarInstantResolver}, because this request is not
 * the only writer of this table: the `create_event` workflow step reaches the same DTO with no request
 * anywhere in the process, and while the rule was stated only here that step read a zone-less instant on
 * `config('app.timezone')` instead — the same text through the two doors, two hours apart, with nothing
 * disagreeing out loud. A rule this quiet cannot survive being written twice.
 *
 * `ends_at` is optional — an event with no stated end is ordinary — but when present it must not
 * precede `starts_at`, because a negative span has no rendering and no meaning. That comparison runs
 * on the RESOLVED INSTANTS, not through `after_or_equal`, and the difference is not cosmetic: the rule
 * parses both strings in the app timezone, so a MIXED payload (`10:00Z` start, zone-less `11:00` end)
 * passed validation while actually ending an hour before it began. The thing that validates has to be
 * the thing that stores.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THERE IS NO `color`, AND SENDING ONE IS A 422
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * An event has no colour of its own, and a caller cannot give it one. {@see CalendarColor} is a
 * vocabulary of MEANINGS the grid explains — a task's deadline colours by priority, a run by how it
 * ended, a schedule projection by being a projection — and every source that emits one is answering a
 * question about its subject. A human-picked colour on an event answered nothing while looking exactly
 * like the three that do: red meant "urgent", red meant "failed", and red meant nothing, in one grid.
 * So the event source now emits a CONSTANT ({@see \App\Modules\Calendar\Sources\EventCalendarSource}),
 * and this field is gone from the wire.
 *
 * REFUSED rather than ignored, deliberately. A client still sending `color` believes it is setting one;
 * dropping it quietly would leave that client correct-looking and wrong, which is the same silence the
 * all-day discriminator is refused for above. The 422 names the field and says why.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * `recurrence` — A NARROW SUBSET, AND TWO KEYS THE CALLER MAY NOT SEND
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Optional. Absent means the event happens once, which is what every payload written before R3 B4 says
 * and what every one of them keeps meaning.
 *
 * The wire shape is `{ day?, month?, exclusions?: { dates }, until? | count? }` and it is the shared
 * recurrence grammar with pieces removed — the subset, and the argument for each removal, lives on
 * {@see \App\Modules\Calendar\DTOs\CalendarRecurrence}. Two keys of that grammar are REFUSED here
 * rather than accepted:
 *
 *   `time`  the series' hour is the EVENT's hour, read off its own start. A caller that could send one
 *           could send one that disagrees with the anchor, and then "this event starts at 9" and "this
 *           event repeats at 10" would both be true of one row.
 *   `tz`    the calendar has never had a timezone parameter and does not grow one here
 *           ({@see CalendarTimezoneResolver} states why the client does not get a vote). The zone is
 *           stamped from the workspace at save time.
 *
 * Refused rather than ignored, for the same reason `color` is: a client that sends one believes it is
 * setting something.
 *
 * THE THREE CHECKS THE PER-KEY RULES BELOW CANNOT EXPRESS, and where each lives:
 *
 *   1. THE GRAMMAR — which mode owns which keys, what each mode requires — is the shared layer's, read
 *      through {@see CalendarRecurrenceService::grammarViolations()} and rendered into this module's
 *      own translated prose. It is not re-derived here, because two definitions of "valid" is exactly
 *      what the shared layer exists to prevent.
 *   2. THE ANCHOR MUST SATISFY THE RULE. A Monday-anchored event with a fires-on-Tuesdays rule is a
 *      422 on the START field, not a series whose first occurrence is a week after its own start. One
 *      engine call; see the recurrence service for why it also replaces the shared layer's emptiness
 *      check rather than accompanying it.
 *   3. THE END IS ONE THING. `until` and `count` are alternatives, never both, and a count is resolved
 *      to a DAY here and now — see the migration for why, and for the consequence that gets named
 *      rather than discovered.
 *
 * AND THE ORDER IS DELIBERATE: if the event's own start did not validate, the recurrence pass does not
 * run at all. Running it anyway would hand the caller "this rule never fires" pointed at `exclusions`,
 * when what actually happened is that they mistyped the date — the exact misleading error ADR-0052 §D2
 * warns every consumer of the shared layer about.
 *
 * `subject_type`/`subject_id` is the optional POINTER, accepted BOTH-OR-NEITHER and stored verbatim.
 * It is deliberately not checked for existence: validating it would mean resolving a morph alias to a
 * class, which is the one dependency the Calendar refuses to take (see the model). A pointer at
 * something that is not there is inert — nothing ever follows it — so a bad one costs a dangling
 * deep-link, not a broken calendar.
 */
class StoreCalendarEventRequest extends FormRequest
{
    /** Aliases + ids are handed over as opaque primitives; only the WIDTH is this layer's business. */
    private const SUBJECT_TYPE_MAX = 255;

    /**
     * The memoized result of {@see compiledRecurrence()}. Null means "not computed yet", never "no
     * recurrence" — an absent rule is a computed result with a null `recurrence` inside it.
     *
     * @var array{recurrence: ?CalendarRecurrence, errors: array<int, array{0: string, 1: string}>}|null
     */
    private ?array $compiledRecurrence = null;

    public function authorize(): bool
    {
        return $this->user()?->can('create', CalendarEvent::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],

            // THE DISCRIMINATOR. Required, and never inferred from which date fields happen to be
            // present: inferring it would make an ambiguous payload silently pick a branch.
            'all_day' => ['required', 'boolean'],

            // A calendar DAY. `date_format` rather than `date` on purpose — `date` would happily accept
            // an ISO instant and hand it on for the conversion that puts an event on the wrong square.
            'start_date' => ['nullable', 'date_format:Y-m-d'],

            // INSTANTS. Any parseable form is accepted; a zone-less one is read in the workspace's
            // timezone and the pair is normalized to UTC — see the docblock and {@see instant()}.
            //
            // NOTE the absence of `after_or_equal:starts_at`. That rule parses both sides in the APP
            // timezone, which is not necessarily how either side is actually being read, so it could
            // pass a genuinely inverted pair. The ordering is checked on the resolved instants instead
            // ({@see validateOrdering}).
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],

            // An event carries no colour of its own — see the docblock. `prohibited` is the whole
            // enforcement: present-and-non-empty is a 422, absent is fine, and the rule sits here where
            // a reader looks for the field rather than in an after-hook.
            'color' => ['prohibited'],

            'subject_type' => ['nullable', 'string', 'max:' . self::SUBJECT_TYPE_MAX],
            'subject_id' => ['nullable', 'uuid'],

            // A scope belongs to an operation on an EXISTING series; there is nothing to scope on
            // create. Refused rather than ignored — see UpdateCalendarEventRequest, which un-prohibits
            // both keys because that is where they mean something.
            'scope' => ['prohibited'],
            'occurrence_date' => ['prohibited'],

            ...$this->recurrenceRules(),
        ];
    }

    /**
     * The per-key TYPES and RANGES of a recurrence — this module's half of the split ADR-0052 D2
     * describes, expressed in Laravel's own vocabulary because that is what produces the per-field
     * error paths a form control attaches to.
     *
     * EVERY BOUND IS READ FROM THE SHARED CONSTANTS, never spelled as a literal. The bound is a FACT
     * about the grammar and the grammar has one home; only the spelling of the rule is this module's.
     * The two mode lists and the special-rule list come from {@see CalendarRecurrence} for the same
     * reason — the Calendar's subset is stated once, as enum cases, and read here.
     *
     * The MODES are `nullable` rather than `required_with`, deliberately: an axis present without a
     * mode is reported by the shared grammar as `mode_required` on the exact key, and a second rule
     * saying the same thing in different words would produce two errors for one mistake.
     *
     * @return array<string, array<int, mixed>>
     */
    private function recurrenceRules(): array
    {
        return [
            'recurrence' => ['nullable', 'array'],

            // Server-authored. See the class docblock.
            'recurrence.time' => ['prohibited'],
            'recurrence.tz' => ['prohibited'],

            'recurrence.day' => ['nullable', 'array'],
            'recurrence.day.mode' => ['nullable', Rule::in(CalendarRecurrence::dayModes())],
            'recurrence.day.weekdays' => ['nullable', 'array', 'min:1', 'max:' . ScheduleLimits::WEEKDAYS_LIST_MAX],
            'recurrence.day.weekdays.*' => ['integer', 'min:' . ScheduleLimits::WEEKDAY_MIN, 'max:' . ScheduleLimits::WEEKDAY_MAX, 'distinct'],
            'recurrence.day.days' => ['nullable', 'array', 'min:1', 'max:' . ScheduleLimits::MONTH_DAYS_LIST_MAX],
            'recurrence.day.days.*' => ['integer', 'min:' . ScheduleLimits::MONTH_DAY_MIN, 'max:' . ScheduleLimits::MONTH_DAY_MAX, 'distinct'],
            'recurrence.day.special' => ['nullable', Rule::in(CalendarRecurrence::daySpecials())],
            'recurrence.day.ordinal' => ['nullable', 'integer', 'min:' . ScheduleLimits::ORDINAL_MIN, 'max:' . ScheduleLimits::ORDINAL_MAX],
            'recurrence.day.weekday' => ['nullable', 'integer', 'min:' . ScheduleLimits::WEEKDAY_MIN, 'max:' . ScheduleLimits::WEEKDAY_MAX],

            'recurrence.month' => ['nullable', 'array'],
            'recurrence.month.mode' => ['nullable', Rule::in(CalendarRecurrence::monthModes())],
            'recurrence.month.months' => ['nullable', 'array', 'min:1', 'max:' . ScheduleLimits::MONTHS_LIST_MAX],
            'recurrence.month.months.*' => ['integer', 'min:' . ScheduleLimits::MONTH_MIN, 'max:' . ScheduleLimits::MONTH_MAX, 'distinct'],

            'recurrence.exclusions' => ['nullable', 'array'],
            // FACT 3 of ADR-0052 D2, at its limit: a dimension the Calendar does not accept cannot be
            // excluded at all. Every month or weekday exclusion is already expressible as the
            // complementary set on the corresponding axis, so nothing is lost and the "exclusions must
            // not rule out an entire dimension" cap has nothing left to guard.
            'recurrence.exclusions.months' => ['prohibited'],
            'recurrence.exclusions.weekdays' => ['prohibited'],
            'recurrence.exclusions.dates' => ['nullable', 'array', 'max:' . ScheduleLimits::EXCLUSIONS_DATES_MAX],
            'recurrence.exclusions.dates.*' => ['date_format:Y-m-d', 'distinct'],

            // The END of the series: a day, or a number of occurrences that BECOMES a day. Both
            // optional, never both present ({@see validateRecurrence}).
            'recurrence.until' => ['nullable', 'date_format:Y-m-d'],
            'recurrence.count' => ['nullable', 'integer', 'min:1', 'max:' . self::maxRecurrenceCount()],
        ];
    }

    /**
     * How many occurrences a "repeat N times" may name. A WORK budget, not a taste: resolving a count
     * to an end date walks the cadence N times, one real projection each.
     */
    protected static function maxRecurrenceCount(): int
    {
        return (int) config('calendar.recurrence_count_max', 366);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'color.prohibited' => __('calendar.validation.color_not_accepted'),
            'scope.prohibited' => __('calendar.validation.recurrence.scope_on_create'),
            'occurrence_date.prohibited' => __('calendar.validation.recurrence.scope_on_create'),
            'recurrence.time.prohibited' => __('calendar.validation.recurrence.time_not_accepted'),
            'recurrence.tz.prohibited' => __('calendar.validation.recurrence.timezone_not_accepted'),
            'recurrence.exclusions.months.prohibited' => __('calendar.validation.recurrence.exclusion_key_not_accepted'),
            'recurrence.exclusions.weekdays.prohibited' => __('calendar.validation.recurrence.exclusion_key_not_accepted'),
            'recurrence.day.mode.in' => __('calendar.validation.recurrence.day_mode_not_supported'),
            'recurrence.day.special.in' => __('calendar.validation.recurrence.day_special_not_supported'),
            'recurrence.month.mode.in' => __('calendar.validation.recurrence.month_mode_not_supported'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateShapeInTime($validator);
            $this->validateOrdering($validator);
            $this->validateSubjectPair($validator);
            $this->validateRecurrence($validator);
        });
    }

    /**
     * The recurrence pass: the shared grammar, the anchor check, and the end of the series.
     *
     * RUNS LAST AND ONLY WHEN THE EVENT ITSELF IS SOUND. Both guards below are the Calendar's answer to
     * the failure ADR-0052 §D2 warns every consumer about — a descriptor whose real defect is a missing
     * or unparseable START being reported as "this rule never fires", pointed at `exclusions`. Here a
     * bad start is reported as a bad start, and the rule is not judged at all until there is something
     * to anchor it to.
     */
    private function validateRecurrence(Validator $validator): void
    {
        if (!$this->filled('recurrence')) {
            return;
        }

        // The ANCHOR first: no start, no rule to judge. Its own rules have already reported the cause.
        if ($validator->errors()->hasAny(['all_day', 'start_date', 'starts_at'])) {
            return;
        }

        // A per-key rule inside the block already failed; the grammar pass would only add noise on top
        // of a value that was never the right type.
        foreach ($validator->errors()->keys() as $key) {
            if ($key === 'recurrence' || str_starts_with($key, 'recurrence.')) {
                return;
            }
        }

        foreach ($this->compiledRecurrence()['errors'] as [$path, $message]) {
            $validator->errors()->add($path, $message);
        }
    }

    /**
     * The recurrence this payload asks for, or null — MEMOIZED, because building it costs real engine
     * projections (the anchor check, and the walk that turns a count into a day) and both the validator
     * and {@see CalendarEventDTO::fromRequest()} ask for it.
     *
     * Returns errors alongside the value rather than adding them to a bag, so exactly one method knows
     * the sequence and the validator merely reports what it produced.
     *
     * @return array{recurrence: ?CalendarRecurrence, errors: array<int, array{0: string, 1: string}>}
     */
    private function compiledRecurrence(): array
    {
        if ($this->compiledRecurrence !== null) {
            return $this->compiledRecurrence;
        }

        $result = ['recurrence' => null, 'errors' => []];
        $input = $this->input('recurrence');

        // The SAME emptiness test {@see validateRecurrence()} uses, and it has to be: an empty block
        // says nothing, and a block that validated as "nothing" while resolving to "every day forever"
        // would store a series nobody asked for and report no error doing it.
        if (!$this->filled('recurrence') || !is_array($input)) {
            return $this->compiledRecurrence = $result;
        }

        $service = app(CalendarRecurrenceService::class);
        $allDay = $this->boolean('all_day');

        // The clock this write stamps: the series' EXISTING one when the write leaves its anchor where
        // it is, the workspace's current one otherwise. Resolved BEFORE the anchor, because an all-day
        // anchor is noon on the clock being stamped and has to be built on that one.
        $timezone = $this->carriedRecurrenceTimezone();

        $anchor = $service->anchorInstant(
            $allDay,
            $allDay && $this->filled('start_date') ? $this->string('start_date')->value() : null,
            $allDay ? null : $this->resolvedStartsAt(),
            $timezone,
        );

        // FACT 1 (the time axis is required), in the Calendar's own vocabulary: a series' hour IS its
        // event's hour, so no anchor means no rule. Nothing is reported — the anchor's own rules
        // already named the cause, and a second error about a cadence nobody could evaluate is the
        // misleading one.
        if ($anchor === null) {
            return $this->compiledRecurrence = $result;
        }

        // The grid the series is projected onto is minute-granular, so an anchor carrying seconds could
        // never be one of its own occurrences. Refused with a sentence about the START, rather than
        // silently rounded — rounding would move an instant the caller stated.
        if (!$allDay && $anchor->second !== 0) {
            $result['errors'][] = ['starts_at', __('calendar.validation.recurrence.whole_minute')];

            return $this->compiledRecurrence = $result;
        }

        $unknown = $this->unknownRecurrenceKeys($input);

        if ($unknown !== []) {
            $result['errors'] = $unknown;

            return $this->compiledRecurrence = $result;
        }

        $descriptor = $service->descriptor(
            day: is_array($input['day'] ?? null) ? $input['day'] : null,
            month: is_array($input['month'] ?? null) ? $input['month'] : null,
            exclusionDates: array_values(array_filter(
                is_array($input['exclusions']['dates'] ?? null) ? $input['exclusions']['dates'] : [],
                'is_string',
            )),
            anchor: $anchor,
            timezone: $timezone,
        );

        $violations = $service->grammarViolations($descriptor);

        if ($violations !== []) {
            foreach ($violations as $violation) {
                $result['errors'][] = ['recurrence.' . $violation->path, $service->message($violation)];
            }

            return $this->compiledRecurrence = $result;
        }

        $recurrence = $service->stamp($descriptor);

        // Unreachable in practice — the rules above and the grammar pass together cover every shape the
        // value object refuses. Kept because the alternative to a truthful 422 here is a 500 on a
        // validation path, which is never the better answer.
        if ($recurrence === null) {
            $result['errors'][] = ['recurrence', __('calendar.validation.recurrence.unsupported')];

            return $this->compiledRecurrence = $result;
        }

        if (!$service->anchorIsFirstOccurrence($recurrence, $anchor)) {
            $result['errors'][] = [
                $allDay ? 'start_date' : 'starts_at',
                __('calendar.validation.recurrence.anchor_not_an_occurrence'),
            ];

            return $this->compiledRecurrence = $result;
        }

        $result['recurrence'] = $recurrence;
        $result = $this->applySeriesEnd($result, $service, $recurrence, $anchor, $input);

        return $this->compiledRecurrence = $this->rejectAnEmptySeries($result, $service, $service->anchorDay($recurrence, $anchor));
    }

    /**
     * THE CLOCK THIS WRITE MUST KEEP, or null to stamp the workspace's current one.
     *
     * ON CREATE THERE IS NOTHING TO KEEP: no row exists, so the series is being laid out for the first
     * time and the workspace's clock today is the honest thing to stamp. The seam is declared here
     * rather than in the update request because the compilation that uses it lives here, and a base
     * class that computed a value only its subclass could supply would have to reach for a route
     * parameter it has no business knowing about.
     *
     * {@see UpdateCalendarEventRequest::carriedRecurrenceTimezone()} is where the real answer lives, and
     * {@see CalendarRecurrenceService::timezoneCarriedBy()} is where the rule does.
     */
    protected function carriedRecurrenceTimezone(): ?string
    {
        return null;
    }

    /**
     * THE SERIES MUST FALL ON AT LEAST ONE DAY — the check the anchor check is not, and the last thing
     * this pass does.
     *
     * The anchor check strips exclusions, so it proves the CADENCE fires at the anchor and nothing
     * more. A series is that cadence MINUS its exclusions, bounded by its own end: weekly-on-Mondays
     * anchored on the 7th, excluding the 7th, ending on the 10th satisfies the anchor and falls on no
     * day whatsoever. Nothing else would have refused it — and once the grid projects series (B5) the
     * event would simply not be there, with a 201 in the caller's log and no error ever shown.
     *
     * Runs LAST because it needs the resolved end, and only when everything else passed: it costs a
     * real projection, and stacking "this series is empty" on top of a structural error describes a rule
     * nobody wrote.
     *
     * Reported on whichever key the caller can actually relax — the end when they named one, the
     * exclusions otherwise.
     *
     * @param  array{recurrence: ?CalendarRecurrence, errors: array<int, array{0: string, 1: string}>}  $result
     * @return array{recurrence: ?CalendarRecurrence, errors: array<int, array{0: string, 1: string}>}
     */
    private function rejectAnEmptySeries(array $result, CalendarRecurrenceService $service, string $anchorDay): array
    {
        $recurrence = $result['recurrence'];

        if ($recurrence === null || $result['errors'] !== []) {
            return $result;
        }

        if ($service->hasOccurrenceBetween($recurrence, $anchorDay, $recurrence->until)) {
            return $result;
        }

        $result['recurrence'] = null;
        $result['errors'][] = [
            $recurrence->until !== null ? 'recurrence.until' : 'recurrence.exclusions.dates',
            __('calendar.validation.recurrence.series_has_no_occurrences'),
        ];

        return $result;
    }

    /**
     * A KEY INSIDE `recurrence` THAT NOBODY READS — refused, never dropped.
     *
     * This block is REBUILT rather than filtered ({@see CalendarRecurrenceService::descriptor()} copies
     * only the axes it knows), which is what keeps the stored descriptor free of anything the module did
     * not author. The cost of rebuilding is that a key nothing reads is a key nothing complains about:
     * `{"freq": "WEEKLY", "interval": 2}` was accepted and stored as EVERY DAY, FOREVER, and
     * `{"exclusions": {"date": [...]}}` — one letter short — silently kept drawing the occurrence the
     * caller meant to remove. Both looked like success.
     *
     * That is the same silence `color`, `recurrence.time` and the two prohibited exclusion lists are all
     * refused for. The shared grammar cannot cover it: it only ever sees keys the rebuild chose to copy,
     * which is why its own `exclusion_key_not_allowed` code is unreachable from this module.
     *
     * `time` and `tz` are listed as KNOWN so this does not double-report them — they have their own
     * rules, with messages that say why they are the server's to write.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, array{0: string, 1: string}>
     */
    private function unknownRecurrenceKeys(array $input): array
    {
        $errors = [];

        foreach (['recurrence' => ['day', 'month', 'exclusions', 'until', 'count', 'time', 'tz'],
            'recurrence.exclusions' => [CalendarRecurrence::EXCLUSION_KEY, 'months', 'weekdays'],
        ] as $path => $known) {
            $block = $path === 'recurrence' ? $input : ($input['exclusions'] ?? null);

            if (!is_array($block)) {
                continue;
            }

            foreach (array_keys($block) as $key) {
                if (!in_array((string) $key, $known, true)) {
                    $errors[] = [
                        $path . '.' . $key,
                        __('calendar.validation.recurrence.field_not_allowed', ['field' => (string) $key]),
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * The end of the series: a stated day, or a count resolved into one. Never both — "until December"
     * and "twelve times" are two answers to one question, and picking one silently would be picking for
     * the caller.
     *
     * @param  array{recurrence: ?CalendarRecurrence, errors: array<int, array{0: string, 1: string}>}  $result
     * @param  array<string, mixed>  $input
     * @return array{recurrence: ?CalendarRecurrence, errors: array<int, array{0: string, 1: string}>}
     */
    private function applySeriesEnd(
        array $result,
        CalendarRecurrenceService $service,
        CalendarRecurrence $recurrence,
        CarbonImmutable $anchor,
        array $input,
    ): array {
        $until = is_string($input['until'] ?? null) && $input['until'] !== '' ? $input['until'] : null;
        $count = is_numeric($input['count'] ?? null) ? (int) $input['count'] : null;

        if ($until !== null && $count !== null) {
            $result['recurrence'] = null;
            $result['errors'][] = ['recurrence.count', __('calendar.validation.recurrence.end_is_one_thing')];

            return $result;
        }

        if ($until !== null) {
            if ($until < $service->anchorDay($recurrence, $anchor)) {
                $result['recurrence'] = null;
                $result['errors'][] = ['recurrence.until', __('calendar.validation.recurrence.end_before_start')];

                return $result;
            }

            $result['recurrence'] = $recurrence->endingOn($until);

            return $result;
        }

        if ($count === null) {
            return $result;
        }

        $endDay = $service->endDayForCount($recurrence, $anchor, $count);

        // A cadence can be anchored on a real occurrence and still have its next one decades away —
        // the fifth Monday of February happens in 2016 and then not until 2044 — and the engine gives
        // up at ten years. Storing a quietly shorter series would answer a question nobody asked.
        if ($endDay === null) {
            $result['recurrence'] = null;
            $result['errors'][] = ['recurrence.count', __('calendar.validation.recurrence.count_unreachable')];

            return $result;
        }

        $result['recurrence'] = $recurrence->endingOn($endDay);

        return $result;
    }

    /**
     * An event may not end before it begins — compared on the instants that will actually be STORED,
     * each read in whichever zone it named (or the workspace's, when it named none).
     *
     * Skipped when either field already failed the `date` rule, so a caller sees the cause rather than
     * a second complaint about a value that was never parseable.
     */
    private function validateOrdering(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['starts_at', 'ends_at'])) {
            return;
        }

        $startsAt = $this->resolvedStartsAt();
        $endsAt = $this->resolvedEndsAt();

        if ($startsAt === null || $endsAt === null) {
            return;
        }

        if ($endsAt->lessThan($startsAt)) {
            $validator->errors()->add('ends_at', __('calendar.validation.end_before_start'));
        }
    }

    /**
     * The all-day/timed split: exactly one column group, required in full and empty on the other side.
     * Skipped when `all_day` itself failed, so a caller sees the cause rather than three consequences.
     */
    private function validateShapeInTime(Validator $validator): void
    {
        if ($validator->errors()->has('all_day') || !$this->has('all_day')) {
            return;
        }

        if ($this->boolean('all_day')) {
            if (!$this->filled('start_date')) {
                $validator->errors()->add('start_date', __('calendar.validation.start_date_required'));
            }

            foreach (['starts_at', 'ends_at'] as $field) {
                if ($this->filled($field)) {
                    $validator->errors()->add($field, __('calendar.validation.instant_on_all_day'));
                }
            }

            return;
        }

        if (!$this->filled('starts_at')) {
            $validator->errors()->add('starts_at', __('calendar.validation.starts_at_required'));
        }

        if ($this->filled('start_date')) {
            $validator->errors()->add('start_date', __('calendar.validation.day_on_timed'));
        }
    }

    /**
     * The pointer is BOTH-OR-NEITHER. Half a pointer addresses nothing and would sit in the row
     * looking like a reference somebody could follow.
     */
    private function validateSubjectPair(Validator $validator): void
    {
        $hasType = $this->filled('subject_type');
        $hasId = $this->filled('subject_id');

        if ($hasType !== $hasId) {
            $validator->errors()->add(
                $hasType ? 'subject_id' : 'subject_type',
                __('calendar.validation.subject_incomplete'),
            );
        }
    }

    public function resolvedDescription(): ?string
    {
        return $this->filled('description') ? $this->string('description')->value() : null;
    }

    /** The event's start as a UTC instant, read in whichever zone the caller named — or the workspace's. */
    public function resolvedStartsAt(): ?CarbonImmutable
    {
        return $this->instant('starts_at');
    }

    public function resolvedEndsAt(): ?CarbonImmutable
    {
        return $this->instant('ends_at');
    }

    /**
     * One wire instant, normalized to UTC through {@see CalendarInstantResolver} — the module's single
     * statement of the rule, shared with the `create_event` workflow step so the two doors into this
     * table cannot disagree about what a zone-less string means.
     *
     * Fail-soft on an unparseable value: the `date` rule has already reported it, and throwing here
     * would turn a reported 422 into a 500 while the validator is still collecting errors. The resolver
     * returns null for exactly that case.
     */
    private function instant(string $key): ?CarbonImmutable
    {
        if (!$this->filled($key)) {
            return null;
        }

        return app(CalendarInstantResolver::class)->toUtc($this->string($key)->value());
    }

    /**
     * The pointer, both halves or neither — already proved complete-or-absent above.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function resolvedSubject(): array
    {
        if (!$this->filled('subject_type') || !$this->filled('subject_id')) {
            return [null, null];
        }

        return [$this->string('subject_type')->value(), $this->string('subject_id')->value()];
    }

    /**
     * The validated, stamped rule this event repeats by — or null for an event that happens once.
     *
     * Safe to call only after validation has passed: before that, a payload with a bad rule yields null
     * here for the same reason it yields a 422 there.
     */
    public function resolvedRecurrence(): ?CalendarRecurrence
    {
        return $this->compiledRecurrence()['recurrence'];
    }
}
