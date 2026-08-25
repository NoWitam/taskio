<?php

namespace App\Modules\Workflows\Services;

use App\Support\Recurrence\Enums\ScheduleDayMode;
use App\Support\Recurrence\Enums\ScheduleDaySpecial;
use App\Support\Recurrence\Enums\ScheduleLimits;
use App\Support\Recurrence\Enums\ScheduleMonthMode;
use App\Support\Recurrence\Enums\ScheduleTimeMode;
use App\Support\Recurrence\LegacyScheduleUpgrader;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The ONE place the v2 compositional `trigger_config.schedule` descriptor
 * ({ time, day?, month?, exclusions?, tz? }) is validated.
 *
 * Extracted from StoreWorkflowRequest so the human write path AND the AI schedule-assist path
 * (WorkflowScheduleAssistService) enforce byte-identical rules: the assist service never trusts the
 * model's self-report, it re-runs THESE rules. The request delegates here for both its rule array
 * and its second-pass checks, so nothing is forked and its error keys stay under
 * `trigger_config.schedule.*` (the FE maps validation errors by that path prefix).
 *
 * Two entry points:
 *   - baseRules($prefix) + secondPass($validator, $schedule, $prefix): the request composes these
 *     into its own Validator under the `trigger_config.schedule` prefix.
 *   - validate($schedule): a STANDALONE check for a bare block (prefix `schedule`) used by the assist
 *     re-validation; returns a flat list of error messages ([] when valid). It upgrades a legacy
 *     `{ family, params }` block to v2 first (the read-shim), so a model that still speaks the old
 *     vocabulary is validated against the same v2 rules.
 *
 * VALIDATION SHAPE (structural rules in baseRules, cross-field rules in secondPass):
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
        private WorkflowScheduleService $schedule = new WorkflowScheduleService,
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
            $p . '.exclusions.months.*' => ['integer', 'min:1', 'max:12', 'distinct'],
            $p . '.exclusions.weekdays' => ['nullable', 'array', 'max:' . ScheduleLimits::EXCLUSIONS_WEEKDAYS_MAX],
            $p . '.exclusions.weekdays.*' => ['integer', 'min:0', 'max:6', 'distinct'],
            $p . '.exclusions.dates' => ['nullable', 'array', 'max:' . ScheduleLimits::EXCLUSIONS_DATES_MAX],
            $p . '.exclusions.dates.*' => ['date_format:Y-m-d', 'distinct'],
        ];
    }

    /**
     * Cross-field checks the per-key rules cannot express, emitted under $prefix. No-ops for a part
     * whose base rule already failed (a non-array time, an unknown mode) so a second confusing error
     * is not stacked on top.
     *
     * @param  array<string, mixed>  $schedule  the resolved v2 block
     * @param  bool  $checkEmpty  whether to reject a cadence with no reachable occurrence (default true)
     */
    public function secondPass(ValidatorContract $validator, array $schedule, string $prefix, bool $checkEmpty = true): void
    {
        $this->validateTimeAxis($validator, $schedule, $prefix);
        $this->validateDayAxis($validator, $schedule, $prefix);
        $this->validateMonthAxis($validator, $schedule, $prefix);
        $this->rejectForeignExclusionKeys($validator, $schedule, $prefix);

        // Only worth checking emptiness once the block is otherwise structurally sound — a config that
        // already failed above would emit a confusing second "no occurrences" error. The preview path
        // opts out (checkEmpty:false) so emptiness surfaces as data instead of a 422.
        if ($checkEmpty && $validator->errors()->isEmpty()) {
            $this->validateHasOccurrence($validator, $schedule, $prefix);
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

    // ---- TIME axis ------------------------------------------------------------

    /**
     * time mode-dependent checks: the required field for the mode, the from/to window, and rejection
     * of any field foreign to the chosen mode.
     *
     * @param  array<string, mixed>  $schedule
     */
    private function validateTimeAxis(ValidatorContract $validator, array $schedule, string $prefix): void
    {
        $time = $schedule['time'] ?? null;

        if (!is_array($time)) {
            return; // base 'time' required/array rule reported it
        }

        $tp = $prefix . '.time';
        $mode = ScheduleTimeMode::tryFrom((string) ($time['mode'] ?? ''));

        if ($mode === null) {
            return; // base enum rule reported the bad/missing mode
        }

        match ($mode) {
            ScheduleTimeMode::AT => $this->requireList($validator, $time, 'at', $tp),
            ScheduleTimeMode::EVERY_MINUTES => $this->validateEveryMinutes($validator, $time, $tp),
            ScheduleTimeMode::EVERY_HOURS => $this->validateEveryHours($validator, $time, $tp),
        };

        $this->rejectForeignKeys($validator, $time, $this->timeAllowedKeys($mode), $tp);
    }

    /** every_minutes: a required `minutes` step plus an OPTIONAL HH:mm window (both-or-neither, from<to). */
    private function validateEveryMinutes(ValidatorContract $validator, array $time, string $tp): void
    {
        $this->requireNumeric($validator, $time, 'minutes', $tp);
        $this->validateHhmmWindow($validator, $time, $tp);
    }

    /** every_hours: a required `hours` step (minute optional) plus an OPTIONAL 0..23 hour window. */
    private function validateEveryHours(ValidatorContract $validator, array $time, string $tp): void
    {
        $this->requireNumeric($validator, $time, 'hours', $tp);
        $this->validateHourWindow($validator, $time, $tp);
    }

    /**
     * The optional HH:mm window of an every_minutes time. from/to are BOTH-OR-NEITHER, each a valid
     * HH:mm, and from < to (a minute window never wraps across midnight).
     */
    private function validateHhmmWindow(ValidatorContract $validator, array $time, string $tp): void
    {
        if (!$this->windowPresent($validator, $time, $tp)) {
            return;
        }

        $fromOk = $this->isHhmm($time['from'] ?? null);
        $toOk = $this->isHhmm($time['to'] ?? null);

        if (!$fromOk) {
            $validator->errors()->add($tp . '.from', 'The window start must be a HH:mm time.');
        }

        if (!$toOk) {
            $validator->errors()->add($tp . '.to', 'The window end must be a HH:mm time.');
        }

        if ($fromOk && $toOk && $this->minutesOfDay($time['from']) >= $this->minutesOfDay($time['to'])) {
            $validator->errors()->add($tp . '.to', 'The window end must be after its start (a window cannot wrap midnight).');
        }
    }

    /**
     * The optional 0..23 hour window of an every_hours time. from/to are BOTH-OR-NEITHER integers in
     * 0..23 with from < to.
     */
    private function validateHourWindow(ValidatorContract $validator, array $time, string $tp): void
    {
        if (!$this->windowPresent($validator, $time, $tp)) {
            return;
        }

        $fromOk = $this->isHour($time['from'] ?? null);
        $toOk = $this->isHour($time['to'] ?? null);

        if (!$fromOk) {
            $validator->errors()->add($tp . '.from', 'The window start hour must be an integer 0..23.');
        }

        if (!$toOk) {
            $validator->errors()->add($tp . '.to', 'The window end hour must be an integer 0..23.');
        }

        if ($fromOk && $toOk && (int) $time['from'] >= (int) $time['to']) {
            $validator->errors()->add($tp . '.to', 'The window end hour must be after its start.');
        }
    }

    /**
     * The keys a time mode accepts — a foreign one (e.g. `minutes` on an `at` time) is a 422.
     *
     * @return array<int, string>
     */
    private function timeAllowedKeys(ScheduleTimeMode $mode): array
    {
        return match ($mode) {
            ScheduleTimeMode::AT => ['mode', 'at'],
            ScheduleTimeMode::EVERY_MINUTES => ['mode', 'minutes', 'from', 'to'],
            ScheduleTimeMode::EVERY_HOURS => ['mode', 'hours', 'minute', 'from', 'to'],
        };
    }

    // ---- DAY axis -------------------------------------------------------------

    /**
     * day mode-dependent checks. The day axis is optional (absent/empty => every_day); when present
     * it must name a mode and satisfy that mode's required fields, with foreign fields rejected.
     *
     * @param  array<string, mixed>  $schedule
     */
    private function validateDayAxis(ValidatorContract $validator, array $schedule, string $prefix): void
    {
        $day = $schedule['day'] ?? null;

        if (!is_array($day) || $day === []) {
            return; // default every_day
        }

        $dp = $prefix . '.day';
        $mode = ScheduleDayMode::tryFrom((string) ($day['mode'] ?? ''));

        if ($mode === null) {
            $this->requireMode($validator, $day, $dp);

            return;
        }

        match ($mode) {
            ScheduleDayMode::EVERY_DAY => null,
            ScheduleDayMode::EVERY_N_DAYS => $this->validateEveryN($validator, $day, $dp, ScheduleLimits::EVERY_N_DAYS_MIN, ScheduleLimits::EVERY_N_DAYS_MAX),
            ScheduleDayMode::WEEKDAYS => $this->requireList($validator, $day, 'weekdays', $dp),
            ScheduleDayMode::MONTH_DAYS => $this->requireList($validator, $day, 'days', $dp),
            ScheduleDayMode::SPECIAL => $this->validateSpecialDay($validator, $day, $schedule, $prefix),
        };

        if ($mode !== ScheduleDayMode::SPECIAL) {
            $this->rejectForeignKeys($validator, $day, $this->dayAllowedKeys($mode), $dp);
        }
    }

    /**
     * The `special` day rule: the required rule value, its required sub-params (ordinal/weekday), the
     * last_working_day time.mode=at restriction, and rejection of fields foreign to the chosen rule.
     *
     * @param  array<string, mixed>  $day
     * @param  array<string, mixed>  $schedule
     */
    private function validateSpecialDay(ValidatorContract $validator, array $day, array $schedule, string $prefix): void
    {
        $dp = $prefix . '.day';
        $special = ScheduleDaySpecial::tryFrom((string) ($day['special'] ?? ''));

        if ($special === null) {
            if (!array_key_exists('special', $day)) {
                $validator->errors()->add($dp . '.special', 'A special rule is required for the special day mode.');
            }

            return; // a present-but-invalid special was reported by the base enum rule
        }

        if ($special->needsOrdinal() && !is_numeric($day['ordinal'] ?? null)) {
            $validator->errors()->add($dp . '.ordinal', 'An ordinal is required for the ' . $special->value . ' rule.');
        }

        if ($special->needsWeekday() && !is_numeric($day['weekday'] ?? null)) {
            $validator->errors()->add($dp . '.weekday', 'A weekday is required for the ' . $special->value . ' rule.');
        }

        // last_working_day is a bespoke HH:mm cadence — it only makes sense with explicit fire times.
        if ($special->requiresAtTime() && ($schedule['time']['mode'] ?? null) !== ScheduleTimeMode::AT->value) {
            $validator->errors()->add(
                $prefix . '.time.mode',
                'The last_working_day rule requires explicit fire times (time.mode must be at).',
            );
        }

        $this->rejectForeignKeys($validator, $day, array_merge(['mode', 'special'], $special->allowedParams()), $dp);
    }

    /**
     * The keys a (non-special) day mode accepts.
     *
     * @return array<int, string>
     */
    private function dayAllowedKeys(ScheduleDayMode $mode): array
    {
        return match ($mode) {
            ScheduleDayMode::EVERY_DAY => ['mode'],
            ScheduleDayMode::EVERY_N_DAYS => ['mode', 'n', 'from', 'to'],
            ScheduleDayMode::WEEKDAYS => ['mode', 'weekdays'],
            ScheduleDayMode::MONTH_DAYS => ['mode', 'days'],
            ScheduleDayMode::SPECIAL => ['mode', 'special', 'ordinal', 'weekday'],
        };
    }

    // ---- MONTH axis -----------------------------------------------------------

    /**
     * month mode-dependent checks. Optional (absent/empty => every_month); when present it must name a
     * mode and satisfy it, with foreign fields rejected.
     *
     * @param  array<string, mixed>  $schedule
     */
    private function validateMonthAxis(ValidatorContract $validator, array $schedule, string $prefix): void
    {
        $month = $schedule['month'] ?? null;

        if (!is_array($month) || $month === []) {
            return; // default every_month
        }

        $mp = $prefix . '.month';
        $mode = ScheduleMonthMode::tryFrom((string) ($month['mode'] ?? ''));

        if ($mode === null) {
            $this->requireMode($validator, $month, $mp);

            return;
        }

        match ($mode) {
            ScheduleMonthMode::EVERY_MONTH => null,
            ScheduleMonthMode::EVERY_N_MONTHS => $this->validateEveryN($validator, $month, $mp, ScheduleLimits::MONTH_MIN, ScheduleLimits::MONTH_MAX),
            ScheduleMonthMode::MONTHS => $this->requireList($validator, $month, 'months', $mp),
        };

        $this->rejectForeignKeys($validator, $month, $this->monthAllowedKeys($mode), $mp);
    }

    /**
     * The keys a month mode accepts.
     *
     * @return array<int, string>
     */
    private function monthAllowedKeys(ScheduleMonthMode $mode): array
    {
        return match ($mode) {
            ScheduleMonthMode::EVERY_MONTH => ['mode'],
            ScheduleMonthMode::EVERY_N_MONTHS => ['mode', 'n', 'from', 'to'],
            ScheduleMonthMode::MONTHS => ['mode', 'months'],
        };
    }

    // ---- shared field checks --------------------------------------------------

    /**
     * An every_n_* axis: a required numeric `n` and an OPTIONAL integer window (both-or-neither,
     * from<to). The from/to bounds are enforced by the base rules; here we pin the pairing + order.
     *
     * @param  array<string, mixed>  $block
     */
    private function validateEveryN(ValidatorContract $validator, array $block, string $bp, int $min, int $max): void
    {
        $this->requireNumeric($validator, $block, 'n', $bp);

        if (!$this->windowPresent($validator, $block, $bp)) {
            return;
        }

        $from = $block['from'] ?? null;
        $to = $block['to'] ?? null;

        if (is_numeric($from) && is_numeric($to) && (int) $from >= (int) $to) {
            $validator->errors()->add($bp . '.to', 'The window end must be greater than its start.');
        }
    }

    /**
     * Whether an axis carries a window at all, reporting the both-or-neither violation. Returns true
     * only when BOTH bounds are present (so the caller can validate their values/order).
     *
     * @param  array<string, mixed>  $block
     */
    private function windowPresent(ValidatorContract $validator, array $block, string $bp): bool
    {
        $hasFrom = $this->present($block, 'from');
        $hasTo = $this->present($block, 'to');

        if ($hasFrom !== $hasTo) {
            $validator->errors()->add($bp . '.to', 'A window needs both a start and an end, or neither.');

            return false;
        }

        return $hasFrom;
    }

    /**
     * Require a numeric scalar field for the chosen mode (e.g. minutes/hours/n), reporting on its key.
     *
     * @param  array<string, mixed>  $block
     */
    private function requireNumeric(ValidatorContract $validator, array $block, string $key, string $bp): void
    {
        if (!is_numeric($block[$key] ?? null)) {
            $validator->errors()->add($bp . '.' . $key, 'The ' . $key . ' field is required for this mode.');
        }
    }

    /**
     * Require a non-empty list field for the chosen mode (time.at, day.weekdays, day.days,
     * month.months), reporting on its key. The element rules/bounds live in the base rules.
     *
     * @param  array<string, mixed>  $block
     */
    private function requireList(ValidatorContract $validator, array $block, string $key, string $bp): void
    {
        $value = $block[$key] ?? null;

        if (!is_array($value) || $value === []) {
            $validator->errors()->add($bp . '.' . $key, 'The ' . $key . ' list is required for this mode.');
        }
    }

    /** Report a missing mode on a present day/month axis. */
    private function requireMode(ValidatorContract $validator, array $block, string $bp): void
    {
        if (!array_key_exists('mode', $block)) {
            $validator->errors()->add($bp . '.mode', 'A mode is required for this axis.');
        }
        // A present-but-invalid mode was already reported by the base enum rule.
    }

    /**
     * Reject a key not in $allowed for the chosen mode — descriptor-driven consumers get explicit
     * feedback, never a silent drop (mirrors the v1 foreign-param guard).
     *
     * @param  array<string, mixed>  $block
     * @param  array<int, string>  $allowed
     */
    private function rejectForeignKeys(ValidatorContract $validator, array $block, array $allowed, string $bp): void
    {
        foreach (array_keys($block) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $validator->errors()->add($bp . '.' . $key, 'The ' . $key . ' field is not allowed for this mode.');
            }
        }
    }

    /**
     * Reject an `exclusions` key outside {months, weekdays, dates}. No-op when absent/not an array.
     *
     * @param  array<string, mixed>  $schedule
     */
    private function rejectForeignExclusionKeys(ValidatorContract $validator, array $schedule, string $prefix): void
    {
        $exclusions = $schedule['exclusions'] ?? null;

        if (!is_array($exclusions)) {
            return;
        }

        foreach (array_keys($exclusions) as $name) {
            if (!in_array((string) $name, ['months', 'weekdays', 'dates'], true)) {
                $validator->errors()->add($prefix . '.exclusions.' . $name, 'The ' . $name . ' exclusion key is not allowed.');
            }
        }
    }

    /**
     * EMPTY-SCHEDULE guard: after every structural rule passes, the cadence must yield at least one
     * concrete occurrence within the service's horizon. An over-constrained config (e.g. weekly-on-
     * Monday that also excludes Mondays) produces NO occurrence, so we reject it on the exclusions key
     * rather than persist an unfireable schedule. Reuses nextOccurrences(…, 1) — the SAME seam the
     * preview endpoint renders — so "does the cadence have a first occurrence" is answered in one place.
     *
     * @param  array<string, mixed>  $schedule
     */
    private function validateHasOccurrence(ValidatorContract $validator, array $schedule, string $prefix): void
    {
        try {
            $occurrences = $this->schedule->nextOccurrences($schedule, 1);
        } catch (Throwable) {
            $occurrences = [];
        }

        if ($occurrences === []) {
            $validator->errors()->add(
                $prefix . '.exclusions',
                'The schedule has no occurrences — its rules and exclusions rule out every fire time.',
            );
        }
    }

    // ---- primitive predicates -------------------------------------------------

    /** Whether a block carries a non-null value for $key. */
    private function present(array $block, string $key): bool
    {
        return array_key_exists($key, $block) && $block[$key] !== null && $block[$key] !== '';
    }

    /** Whether a value is a well-formed 'HH:mm' string. */
    private function isHhmm(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /** Whether a value is an integer hour 0..23. */
    private function isHour(mixed $value): bool
    {
        return is_numeric($value) && (int) $value >= ScheduleLimits::HOUR_MIN && (int) $value <= ScheduleLimits::HOUR_MAX;
    }

    /** Minutes-of-day for an 'HH:mm' string (for window ordering). */
    private function minutesOfDay(string $time): int
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return (int) $hour * 60 + (int) $minute;
    }
}
