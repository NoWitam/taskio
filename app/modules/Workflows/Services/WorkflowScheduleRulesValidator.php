<?php

namespace App\Modules\Workflows\Services;

use App\Support\Recurrence\Enums\RecurrenceViolationCode;
use App\Support\Recurrence\Enums\ScheduleDayMode;
use App\Support\Recurrence\Enums\ScheduleDaySpecial;
use App\Support\Recurrence\Enums\ScheduleLimits;
use App\Support\Recurrence\Enums\ScheduleMonthMode;
use App\Support\Recurrence\Enums\ScheduleTimeMode;
use App\Support\Recurrence\LegacyScheduleUpgrader;
use App\Support\Recurrence\RecurrenceDescriptorValidator;
use App\Support\Recurrence\RecurrenceViolation;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The WORKFLOWS-FACING face of schedule validation: the Laravel rule array a request composes, and
 * the English sentences an automation's author reads.
 *
 * Extracted from StoreWorkflowRequest so the human write path AND the AI schedule-assist path
 * (WorkflowScheduleAssistService) enforce byte-identical rules: the assist service never trusts the
 * model's self-report, it re-runs THESE rules. The request delegates here for both its rule array
 * and its second-pass checks, so nothing is forked and its error keys stay under
 * `trigger_config.schedule.*` (the FE maps validation errors by that path prefix).
 *
 * WHAT IS NO LONGER DECIDED HERE — read this before adding a rule.
 * The SHAPE grammar (which mode owns which keys, what each mode requires, how a window pairs and
 * orders, which combinations contradict, whether the cadence can ever fire) lives in the shared
 * recurrence layer, on RecurrenceDescriptorValidator, and answers in CODES. It had to move for the
 * same reason the engine did: a second module accepting a recurrence descriptor may not name this
 * one, so leaving the rules here would have produced a second definition of "valid" the day that
 * module was written. What stayed is everything that is PRESENTATION rather than grammar:
 *
 *   - the per-key TYPE and RANGE rules below, expressed in Laravel's vocabulary because that is what
 *     yields the per-field error paths the schedule form attaches to. Every bound is read from the
 *     shared ScheduleLimits — including the exclusion lists' own bounds, which are the SAME facts as
 *     the month and weekday axes' and must not be spelled twice — so the facts are still stated once;
 *   - three checks that are neither type nor range and that the shared layer deliberately leaves to
 *     each consumer: `time` is REQUIRED, `tz` must be a real zone, and the exclusion lists are capped
 *     so they cannot exclude every month or every weekday. All three are in baseRules() below, and
 *     RecurrenceDescriptorValidator's docblock names them as the consumer's job — a module that drops
 *     one is still fail-closed, but it reports a missing time axis as "this schedule never fires";
 *   - the MESSAGES, written for an automation author, in this module's words. The shared layer
 *     deliberately returns no prose: the app is PL+EN switchable and the next consumer of the same
 *     grammar has a different subject and a narrower subset to talk about.
 *
 * So a new WORDING belongs here; a new RULE belongs in the shared validator, and then here as one
 * more line of {@see message()} — which is asserted exhaustive by test, so an unrendered code fails
 * the suite instead of reaching a user as a missing error.
 *
 * Two entry points:
 *   - baseRules($prefix) + secondPass($validator, $schedule, $prefix): the request composes these
 *     into its own Validator under the `trigger_config.schedule` prefix.
 *   - validate($schedule): a STANDALONE check for a bare block (prefix `schedule`) used by the assist
 *     re-validation; returns a flat list of error messages ([] when valid). It upgrades a legacy
 *     `{ family, params }` block to v2 first (the read-shim), so a model that still speaks the old
 *     vocabulary is validated against the same v2 rules.
 *
 * VALIDATION SHAPE (per-key rules in baseRules, grammar in the shared validator, rendered here):
 *   - time (REQUIRED): mode ∈ {at, every_minutes, every_hours}; each mode owns its fields; a field
 *     foreign to the chosen mode is a 422 (like the day/month axes).
 *   - day (OPTIONAL, default every_day) / month (OPTIONAL, default every_month): same mode-owns-its-
 *     fields discipline.
 *   - WINDOWS (time.every_minutes / time.every_hours / day.every_n_days / month.every_n_months): the
 *     from/to pair is BOTH-OR-NEITHER and from < to (a window never wraps midnight / the year).
 *   - LISTS (time.at, day.weekdays, day.days, month.months): distinct, in-range, structurally bounded.
 *   - RESTRICTION: day.special = last_working_day requires time.mode = at (it is a bespoke HH:mm
 *     cadence) — a dedicated 422 on time.mode otherwise.
 *   - EMPTY SCHEDULE (gated by $checkEmpty): the cadence must yield ≥1 occurrence within the horizon;
 *     an over-constrained exclusion set is rejected on the exclusions key. The write and assist paths
 *     keep this ON; the live SCHEDULE-PREVIEW turns it OFF so emptiness returns as data, not a 422.
 */
class WorkflowScheduleRulesValidator
{
    /** The prefix the standalone validate() emits errors under. */
    private const STANDALONE_PREFIX = 'schedule';

    public function __construct(
        private RecurrenceDescriptorValidator $descriptor = new RecurrenceDescriptorValidator,
        private LegacyScheduleUpgrader $upgrader = new LegacyScheduleUpgrader,
    ) {}

    /**
     * The base per-key structural rules for a schedule block under $prefix. Presence requirements
     * that depend on the chosen mode, the from/to window pairing, the last-working-day restriction
     * and the emptiness guard are enforced in secondPass() where the whole block is visible.
     *
     * @return array<string, array<int, mixed>>
     */
    public function baseRules(string $prefix): array
    {
        $p = $prefix;

        return [
            $p => ['required', 'array'],
            $p . '.tz' => ['nullable', 'timezone'],

            // TIME axis (the only required axis).
            $p . '.time' => ['required', 'array'],
            $p . '.time.mode' => ['required', Rule::enum(ScheduleTimeMode::class)],
            $p . '.time.at' => ['nullable', 'array', 'min:1', 'max:' . ScheduleLimits::MAX_AT_TIMES],
            $p . '.time.at.*' => ['date_format:H:i', 'distinct'],
            $p . '.time.minutes' => ['nullable', 'integer', 'min:' . ScheduleLimits::EVERY_MINUTES_MIN, 'max:' . ScheduleLimits::EVERY_MINUTES_MAX],
            $p . '.time.hours' => ['nullable', 'integer', 'min:' . ScheduleLimits::EVERY_HOURS_MIN, 'max:' . ScheduleLimits::EVERY_HOURS_MAX],
            $p . '.time.minute' => ['nullable', 'integer', 'min:' . ScheduleLimits::MINUTE_MIN, 'max:' . ScheduleLimits::MINUTE_MAX],
            // time.from/time.to are POLYMORPHIC (HH:mm for every_minutes, int hour for every_hours),
            // so their exact type/bounds are checked in secondPass per time.mode.
            $p . '.time.from' => ['nullable'],
            $p . '.time.to' => ['nullable'],

            // DAY axis (optional; default every_day).
            $p . '.day' => ['nullable', 'array'],
            $p . '.day.mode' => ['nullable', Rule::enum(ScheduleDayMode::class)],
            $p . '.day.n' => ['nullable', 'integer', 'min:' . ScheduleLimits::EVERY_N_DAYS_MIN, 'max:' . ScheduleLimits::EVERY_N_DAYS_MAX],
            $p . '.day.from' => ['nullable', 'integer', 'min:' . ScheduleLimits::EVERY_N_DAYS_MIN, 'max:' . ScheduleLimits::EVERY_N_DAYS_MAX],
            $p . '.day.to' => ['nullable', 'integer', 'min:' . ScheduleLimits::EVERY_N_DAYS_MIN, 'max:' . ScheduleLimits::EVERY_N_DAYS_MAX],
            $p . '.day.weekdays' => ['nullable', 'array', 'min:1', 'max:' . ScheduleLimits::WEEKDAYS_LIST_MAX],
            $p . '.day.weekdays.*' => ['integer', 'min:' . ScheduleLimits::WEEKDAY_MIN, 'max:' . ScheduleLimits::WEEKDAY_MAX, 'distinct'],
            $p . '.day.days' => ['nullable', 'array', 'min:1', 'max:' . ScheduleLimits::MONTH_DAYS_LIST_MAX],
            $p . '.day.days.*' => ['integer', 'min:' . ScheduleLimits::MONTH_DAY_MIN, 'max:' . ScheduleLimits::MONTH_DAY_MAX, 'distinct'],
            $p . '.day.special' => ['nullable', Rule::enum(ScheduleDaySpecial::class)],
            $p . '.day.ordinal' => ['nullable', 'integer', 'min:' . ScheduleLimits::ORDINAL_MIN, 'max:' . ScheduleLimits::ORDINAL_MAX],
            $p . '.day.weekday' => ['nullable', 'integer', 'min:' . ScheduleLimits::WEEKDAY_MIN, 'max:' . ScheduleLimits::WEEKDAY_MAX],

            // MONTH axis (optional; default every_month).
            $p . '.month' => ['nullable', 'array'],
            $p . '.month.mode' => ['nullable', Rule::enum(ScheduleMonthMode::class)],
            $p . '.month.n' => ['nullable', 'integer', 'min:' . ScheduleLimits::EVERY_N_MONTHS_MIN, 'max:' . ScheduleLimits::EVERY_N_MONTHS_MAX],
            $p . '.month.from' => ['nullable', 'integer', 'min:' . ScheduleLimits::MONTH_MIN, 'max:' . ScheduleLimits::MONTH_MAX],
            $p . '.month.to' => ['nullable', 'integer', 'min:' . ScheduleLimits::MONTH_MIN, 'max:' . ScheduleLimits::MONTH_MAX],
            $p . '.month.months' => ['nullable', 'array', 'min:1', 'max:' . ScheduleLimits::MONTHS_LIST_MAX],
            $p . '.month.months.*' => ['integer', 'min:' . ScheduleLimits::MONTH_MIN, 'max:' . ScheduleLimits::MONTH_MAX, 'distinct'],

            // EXCLUSIONS (unchanged from v1): a skip filter, each list distinct and structurally
            // bounded so it can never exclude EVERY value (months max 11, weekdays max 6).
            $p . '.exclusions' => ['nullable', 'array'],
            $p . '.exclusions.months' => ['nullable', 'array', 'max:' . ScheduleLimits::EXCLUSIONS_MONTHS_MAX],
            $p . '.exclusions.months.*' => ['integer', 'min:' . ScheduleLimits::MONTH_MIN, 'max:' . ScheduleLimits::MONTH_MAX, 'distinct'],
            $p . '.exclusions.weekdays' => ['nullable', 'array', 'max:' . ScheduleLimits::EXCLUSIONS_WEEKDAYS_MAX],
            $p . '.exclusions.weekdays.*' => ['integer', 'min:' . ScheduleLimits::WEEKDAY_MIN, 'max:' . ScheduleLimits::WEEKDAY_MAX, 'distinct'],
            $p . '.exclusions.dates' => ['nullable', 'array', 'max:' . ScheduleLimits::EXCLUSIONS_DATES_MAX],
            $p . '.exclusions.dates.*' => ['date_format:Y-m-d', 'distinct'],
        ];
    }

    /**
     * The grammar checks the per-key rules cannot express, emitted under $prefix: every violation the
     * shared descriptor validator finds, rendered into this module's wording at this request's paths.
     *
     * The shared validator no-ops for a part whose base rule already failed (a non-array time, an
     * unknown mode), so a second confusing error is never stacked on top of the first.
     *
     * @param  array<string, mixed>  $schedule  the resolved v2 block
     * @param  bool  $checkEmpty  whether to reject a cadence with no reachable occurrence (default true)
     */
    public function secondPass(ValidatorContract $validator, array $schedule, string $prefix, bool $checkEmpty = true): void
    {
        $this->report($validator, $this->descriptor->violations($schedule), $prefix);

        // Only worth checking emptiness once the block is otherwise structurally sound — a config that
        // already failed above would emit a confusing second "no occurrences" error, and the check
        // costs a real projection. The preview path opts out (checkEmpty:false) so emptiness surfaces
        // as data instead of a 422.
        if ($checkEmpty && $validator->errors()->isEmpty()) {
            $this->report($validator, $this->descriptor->unreachable($schedule), $prefix);
        }
    }

    /**
     * Validate a STANDALONE block against the exact rules the write path uses, returning a flat list
     * of messages ([] when valid). A legacy block is upgraded to v2 first, so an assist model that
     * still proposes `{ family, params }` is judged by the v2 rules.
     *
     * @param  array<string, mixed>  $schedule
     * @return array<int, string>
     */
    public function validate(array $schedule, bool $checkEmpty = true): array
    {
        $prefix = self::STANDALONE_PREFIX;
        $schedule = $this->upgrader->toV2($schedule);

        $validator = Validator::make(
            [$prefix => $schedule],
            $this->baseRules($prefix),
        );

        $validator->after(function (ValidatorContract $validator) use ($schedule, $prefix, $checkEmpty) {
            $this->secondPass($validator, $schedule, $prefix, $checkEmpty);
        });

        return $validator->fails()
            ? array_values($validator->errors()->all())
            : [];
    }

    /**
     * Add each violation to the error bag under this request's prefix, in the order the shared
     * validator found them (time axis, day axis, month axis, exclusions) — the order the FE's
     * progressive-disclosure mapping walks.
     *
     * @param  array<int, RecurrenceViolation>  $violations
     */
    private function report(ValidatorContract $validator, array $violations, string $prefix): void
    {
        foreach ($violations as $violation) {
            $validator->errors()->add($prefix . '.' . $violation->path, $this->message($violation));
        }
    }

    /**
     * ONE violation as one sentence for an automation author.
     *
     * A `match` over the enum with no default arm ON PURPOSE: a code added to the grammar makes this
     * expression throw rather than fall through to something vague, and the exhaustiveness test turns
     * that into a red suite at the moment the code is added instead of a blank error later.
     */
    private function message(RecurrenceViolation $violation): string
    {
        $field = $violation->context('field');
        $special = $violation->context('special');

        return match ($violation->code) {
            RecurrenceViolationCode::MODE_REQUIRED => 'A mode is required for this axis.',
            RecurrenceViolationCode::MODE_FIELD_REQUIRED => 'The ' . $field . ' field is required for this mode.',
            RecurrenceViolationCode::MODE_LIST_REQUIRED => 'The ' . $field . ' list is required for this mode.',
            RecurrenceViolationCode::FIELD_NOT_ALLOWED_FOR_MODE => 'The ' . $field . ' field is not allowed for this mode.',
            RecurrenceViolationCode::WINDOW_INCOMPLETE => 'A window needs both a start and an end, or neither.',
            RecurrenceViolationCode::WINDOW_START_NOT_TIME => 'The window start must be a HH:mm time.',
            RecurrenceViolationCode::WINDOW_END_NOT_TIME => 'The window end must be a HH:mm time.',
            RecurrenceViolationCode::WINDOW_TIMES_NOT_ASCENDING => 'The window end must be after its start (a window cannot wrap midnight).',
            RecurrenceViolationCode::WINDOW_START_NOT_HOUR => 'The window start hour must be an integer 0..23.',
            RecurrenceViolationCode::WINDOW_END_NOT_HOUR => 'The window end hour must be an integer 0..23.',
            RecurrenceViolationCode::WINDOW_HOURS_NOT_ASCENDING => 'The window end hour must be after its start.',
            RecurrenceViolationCode::WINDOW_BOUNDS_NOT_ASCENDING => 'The window end must be greater than its start.',
            RecurrenceViolationCode::SPECIAL_REQUIRED => 'A special rule is required for the special day mode.',
            RecurrenceViolationCode::SPECIAL_NEEDS_ORDINAL => 'An ordinal is required for the ' . $special . ' rule.',
            RecurrenceViolationCode::SPECIAL_NEEDS_WEEKDAY => 'A weekday is required for the ' . $special . ' rule.',
            RecurrenceViolationCode::SPECIAL_REQUIRES_AT_TIME => 'The ' . $special . ' rule requires explicit fire times (time.mode must be at).',
            RecurrenceViolationCode::EXCLUSION_KEY_NOT_ALLOWED => 'The ' . $field . ' exclusion key is not allowed.',
            RecurrenceViolationCode::NO_OCCURRENCE => 'The schedule has no occurrences — its rules and exclusions rule out every fire time.',
        };
    }
}
