<?php

namespace App\Modules\Workflows\Services;

use App\Modules\Workflows\Enums\WorkflowScheduleFamily;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The ONE place the `trigger_config.schedule` block ({ family, params: {…}, tz? }) is validated.
 *
 * Extracted from StoreWorkflowRequest so the human write path AND the AI schedule-assist path
 * (WorkflowScheduleAssistService) enforce byte-identical rules against a model-proposed config:
 * the assist service never trusts the model's self-report, it re-runs THESE rules. The request
 * delegates here for both its rule array and its second-pass checks, so nothing is forked and its
 * error keys/messages are unchanged — its tests stay green.
 *
 * Everything is DERIVED from WorkflowScheduleFamily::paramDescriptors() (the family vocabulary,
 * per-param required/type/bounds and `lt` ordering relations), so the accepted params can never
 * drift from what the compiler understands or the /meta/schedule-families endpoint exposes.
 *
 * Two entry points:
 *   - baseRules($prefix) + secondPass($validator, $schedule, $prefix): the request composes these
 *     into its own Validator under the `trigger_config.schedule` prefix (behaviour unchanged).
 *   - validate($schedule): a STANDALONE check for a bare schedule block (prefix `schedule`) used by
 *     the assist re-validation; returns a flat list of error messages ([] when the block is valid).
 */
class WorkflowScheduleRulesValidator
{
    /** The prefix the standalone validate() emits errors under. */
    private const STANDALONE_PREFIX = 'schedule';

    public function __construct(
        private WorkflowScheduleService $schedule = new WorkflowScheduleService,
    ) {}

    /**
     * The base per-key rule set for a schedule block under $prefix: the constant family/tz/params
     * rules plus the per-param required/type/widest-bounds rules DERIVED from the descriptors.
     * Per-family bounds tighter than the widest (and ordering) are enforced in secondPass().
     *
     * @return array<string, array<int, mixed>>
     */
    public function baseRules(string $prefix): array
    {
        return array_merge([
            $prefix => ['required', 'array'],
            $prefix . '.family' => ['required', \Illuminate\Validation\Rule::enum(WorkflowScheduleFamily::class)],
            $prefix . '.params' => ['nullable', 'array'],
            $prefix . '.tz' => ['nullable', 'timezone'],

            // MULTIPLE FIRE TIMES (optional): 1..6 DISTINCT 'HH:mm' strings, replacing params.time.
            // Only allowed for wall-clock families and mutually exclusive with params.time — both
            // enforced in secondPass (a per-key rule can't see the family). The container caps the
            // count/shape; `distinct` rejects duplicate fire times (a duplicate execution mistake).
            $prefix . '.times' => ['nullable', 'array', 'max:6'],
            $prefix . '.times.*' => ['date_format:H:i', 'distinct'],

            // EXCLUSIONS (optional): a skip filter. Each list is DISTINCT and structurally bounded so
            // it can never exclude EVERY value (months max 11, weekdays max 6). Semantics (drop a
            // candidate whose month/weekday/date matches) live in WorkflowScheduleService.
            $prefix . '.exclusions' => ['nullable', 'array'],
            $prefix . '.exclusions.months' => ['nullable', 'array', 'max:11'],
            $prefix . '.exclusions.months.*' => ['integer', 'min:1', 'max:12', 'distinct'],
            $prefix . '.exclusions.weekdays' => ['nullable', 'array', 'max:6'],
            $prefix . '.exclusions.weekdays.*' => ['integer', 'min:0', 'max:6', 'distinct'],
            $prefix . '.exclusions.dates' => ['nullable', 'array', 'max:50'],
            $prefix . '.exclusions.dates.*' => ['date_format:Y-m-d', 'distinct'],
        ], $this->paramRules($prefix));
    }

    /**
     * Second-pass checks the per-key rules cannot express, emitted under $prefix:
     *   - FOREIGN params: a key not in the submitted family's OWN descriptor set is rejected.
     *   - EXACT per-family bounds (a family with tighter bounds than the shared widest).
     *   - ordering invariants from the descriptors' `lt` relations.
     *   - TIMES rules: allowed only for wall-clock families and mutually exclusive with params.time.
     *   - EXCLUSIONS foreign keys: only months/weekdays/dates are accepted.
     *   - EMPTY SCHEDULE: after all structural checks pass, the cadence must have at least one
     *     occurrence within the horizon (an over-constrained exclusion set is rejected). This ONE
     *     check is OPTIONAL, gated by $checkEmpty: the write and assist paths keep it on (an
     *     unfireable schedule must never persist), but the live SCHEDULE-PREVIEW turns it OFF so an
     *     over-constrained config returns as data (`empty: true`) for a pre-save warning instead of a
     *     422. Every STRUCTURAL check (bad family, bounds, times/exclusions shape) still runs.
     * No-ops when the family is unknown or params is not an array (base rules report those first).
     *
     * @param  array<string, mixed>  $schedule  the resolved schedule block ({ family, params, tz?, times?, exclusions? })
     * @param  bool  $checkEmpty  whether to reject a cadence with no reachable occurrence (default true)
     */
    public function secondPass(ValidatorContract $validator, array $schedule, string $prefix, bool $checkEmpty = true): void
    {
        $family = WorkflowScheduleFamily::tryFrom((string) ($schedule['family'] ?? ''));
        $params = $schedule['params'] ?? [];

        if ($family === null || !is_array($params)) {
            return;
        }

        $this->rejectForeignParams($validator, $family, $params, $prefix);

        foreach ($family->paramDescriptors() as $descriptor) {
            $this->validateParamBounds($validator, $descriptor, $params, $prefix);
        }

        $this->validateOrdering($validator, $family, $params, $prefix);
        $this->validateTimeRequirement($validator, $family, $schedule, $params, $prefix);
        $this->validateTimes($validator, $family, $schedule, $params, $prefix);
        $this->rejectForeignExclusionKeys($validator, $schedule, $prefix);

        // Only worth checking emptiness once the block is otherwise structurally sound — a config
        // that already failed above would emit a confusing second "no occurrences" error. The
        // preview path opts out entirely so emptiness surfaces as data, not a validation error.
        if ($checkEmpty && $validator->errors()->isEmpty()) {
            $this->validateHasOccurrence($validator, $schedule, $prefix);
        }
    }

    /**
     * Validate a STANDALONE schedule block against the exact same rules the write path uses and
     * return a flat list of human-readable error messages ([] when valid). This is the assist
     * re-validation seam: a model-proposed config is only ever trusted after passing here.
     *
     * @param  array<string, mixed>  $schedule
     * @return array<int, string>
     */
    public function validate(array $schedule, bool $checkEmpty = true): array
    {
        $prefix = self::STANDALONE_PREFIX;

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

    // ---- rule building (derived from the descriptor map) ----------------------

    /**
     * Per-param rules built from the descriptor map, keyed under $prefix.params.<name>. For each
     * distinct param name across all families we emit ONE container rule set: `required_if` scoped
     * to the families that require it, plus the type rule (int->integer, time->date_format:H:i,
     * weekday->integer, weekday_list->array+min:1) and the WIDEST min/max across the families using
     * it. A `weekday_list` param ALSO emits an element rule set under $prefix.params.<name>.* so
     * each array member is a distinct in-range weekday int.
     *
     * TIME EXCEPTION: a `time` param is NEVER `required_if` at the base — the wall-clock families
     * accept EITHER a single `params.time` OR the `times[]` list, and a per-key `required_if` cannot
     * see `times`. Its presence requirement (family requires a time AND no times[] was given) is
     * enforced in secondPass under the same `params.time` error key, so the messages/keys the write
     * tests assert on are unchanged.
     *
     * @return array<string, array<int, mixed>>
     */
    private function paramRules(string $prefix): array
    {
        $rules = [];

        foreach ($this->descriptorsByName() as $name => $descriptors) {
            $key = $prefix . '.params.' . $name;
            $type = $descriptors[0]['type'] ?? null;
            $requiredFamilies = $this->familiesRequiring($name);

            $rules[$key] = array_merge(
                // `time` is presence-checked in secondPass (times[] may satisfy it); every other
                // param keeps the descriptor-derived required_if.
                $type === 'time' || $requiredFamilies === []
                    ? ['nullable']
                    : ['required_if:' . $prefix . '.family,' . implode(',', $requiredFamilies)],
                $this->paramTypeRules($descriptors),
            );

            if ($type === 'weekday_list') {
                $rules[$key . '.*'] = $this->weekdayListElementRules($descriptors);
            }
        }

        return $rules;
    }

    /**
     * The CONTAINER type + widest-bounds rules for a param, from its descriptors. A `time` param is
     * an HH:mm string; a `weekday_list` param is a non-empty array (the ELEMENT rules live under the
     * `.*` key, built by weekdayListElementRules); every other type is an integer bounded by the
     * widest min/max across the families using it (exact per-family bounds are re-checked in
     * secondPass()).
     *
     * @param  array<int, array{name: string, type: string, required: bool, min?: int, max?: int}>  $descriptors
     * @return array<int, string>
     */
    private function paramTypeRules(array $descriptors): array
    {
        $type = $descriptors[0]['type'] ?? null;

        if ($type === 'time') {
            return ['date_format:H:i'];
        }

        if ($type === 'weekday_list') {
            return ['array', 'min:1'];
        }

        $mins = array_filter(array_column($descriptors, 'min'), fn ($v) => $v !== null);
        $maxs = array_filter(array_column($descriptors, 'max'), fn ($v) => $v !== null);

        $rules = ['integer'];

        if ($mins !== []) {
            $rules[] = 'min:' . min($mins);
        }

        if ($maxs !== []) {
            $rules[] = 'max:' . max($maxs);
        }

        return $rules;
    }

    /**
     * The ELEMENT rules for a `weekday_list` param (keyed under $prefix.params.<name>.*): each member
     * is a DISTINCT integer within the widest element min/max across the families using it (each
     * family's exact element bounds match today — all weekdays are 0..6). `distinct` rejects a
     * duplicate weekday in the list.
     *
     * @param  array<int, array{name: string, type: string, required: bool, min?: int, max?: int}>  $descriptors
     * @return array<int, string>
     */
    private function weekdayListElementRules(array $descriptors): array
    {
        $mins = array_filter(array_column($descriptors, 'min'), fn ($v) => $v !== null);
        $maxs = array_filter(array_column($descriptors, 'max'), fn ($v) => $v !== null);

        $rules = ['integer', 'distinct'];

        if ($mins !== []) {
            $rules[] = 'min:' . min($mins);
        }

        if ($maxs !== []) {
            $rules[] = 'max:' . max($maxs);
        }

        return $rules;
    }

    /**
     * Group every family's param descriptors by param name.
     *
     * @return array<string, array<int, array{name: string, type: string, required: bool, min?: int, max?: int}>>
     */
    private function descriptorsByName(): array
    {
        $byName = [];

        foreach (WorkflowScheduleFamily::cases() as $family) {
            foreach ($family->paramDescriptors() as $descriptor) {
                $byName[$descriptor['name']][] = $descriptor;
            }
        }

        return $byName;
    }

    /**
     * The family values that mark a given param REQUIRED — feeds required_if.
     *
     * @return array<int, string>
     */
    private function familiesRequiring(string $name): array
    {
        $families = [];

        foreach (WorkflowScheduleFamily::cases() as $family) {
            foreach ($family->paramDescriptors() as $descriptor) {
                if ($descriptor['name'] === $name && $descriptor['required']) {
                    $families[] = $family->value;
                }
            }
        }

        return $families;
    }

    // ---- second-pass checks ---------------------------------------------------

    /**
     * Reject a params key not in the submitted family's OWN descriptor set (e.g. a `weekday` on a
     * `daily` schedule) — descriptor-driven consumers get explicit feedback, never a silent drop.
     *
     * @param  array<string, mixed>  $params
     */
    private function rejectForeignParams(ValidatorContract $validator, WorkflowScheduleFamily $family, array $params, string $prefix): void
    {
        $ownNames = array_column($family->paramDescriptors(), 'name');

        foreach (array_keys($params) as $name) {
            if (!in_array((string) $name, $ownNames, true)) {
                $validator->errors()->add(
                    $prefix . '.params.' . $name,
                    'The ' . $name . ' param is not allowed for the ' . $family->value . ' schedule.',
                );
            }
        }
    }

    /**
     * Enforce a single param's exact per-family min/max when present and numeric (presence/required
     * is handled by required_if; an absent optional param is fine). A `weekday_list` param checks
     * EACH array element against the descriptor bounds instead of the (absent) scalar value.
     *
     * @param  array{name: string, type: string, required: bool, min?: int, max?: int}  $descriptor
     * @param  array<string, mixed>  $params
     */
    private function validateParamBounds(ValidatorContract $validator, array $descriptor, array $params, string $prefix): void
    {
        if ($descriptor['type'] === 'time') {
            return;
        }

        if ($descriptor['type'] === 'weekday_list') {
            $this->validateWeekdayListBounds($validator, $descriptor, $params, $prefix);

            return;
        }

        $value = $params[$descriptor['name']] ?? null;

        if ($value === null || !is_numeric($value)) {
            return;
        }

        $value = (int) $value;
        $key = $prefix . '.params.' . $descriptor['name'];

        if (isset($descriptor['min']) && $value < $descriptor['min']) {
            $validator->errors()->add($key, 'The ' . $descriptor['name'] . ' must be at least ' . $descriptor['min'] . ' for this schedule.');
        }

        if (isset($descriptor['max']) && $value > $descriptor['max']) {
            $validator->errors()->add($key, 'The ' . $descriptor['name'] . ' must not be greater than ' . $descriptor['max'] . ' for this schedule.');
        }
    }

    /**
     * Exact per-family element bounds for a `weekday_list` param: every numeric member must sit
     * within the descriptor's [min, max] (0..6 today). Presence (non-empty array) is enforced by the
     * container rules; this pass guards the exact per-family element range like the scalar path does.
     *
     * @param  array{name: string, type: string, required: bool, min?: int, max?: int}  $descriptor
     * @param  array<string, mixed>  $params
     */
    private function validateWeekdayListBounds(ValidatorContract $validator, array $descriptor, array $params, string $prefix): void
    {
        $values = $params[$descriptor['name']] ?? null;

        if (!is_array($values)) {
            return;
        }

        $key = $prefix . '.params.' . $descriptor['name'];

        foreach ($values as $value) {
            if (!is_numeric($value)) {
                continue;
            }

            $value = (int) $value;

            if (isset($descriptor['min']) && $value < $descriptor['min']) {
                $validator->errors()->add($key, 'Each ' . $descriptor['name'] . ' value must be at least ' . $descriptor['min'] . ' for this schedule.');
            }

            if (isset($descriptor['max']) && $value > $descriptor['max']) {
                $validator->errors()->add($key, 'Each ' . $descriptor['name'] . ' value must not be greater than ' . $descriptor['max'] . ' for this schedule.');
            }
        }
    }

    /**
     * Ordering invariants driven by the descriptors' `lt` relations (a param naming another it must
     * be strictly LESS THAN — twice_daily hours, twice_monthly days).
     *
     * @param  array<string, mixed>  $params
     */
    private function validateOrdering(ValidatorContract $validator, WorkflowScheduleFamily $family, array $params, string $prefix): void
    {
        foreach ($family->paramDescriptors() as $descriptor) {
            $ltTarget = $descriptor['lt'] ?? null;

            if ($ltTarget === null) {
                continue;
            }

            $first = $params[$descriptor['name']] ?? null;
            $second = $params[$ltTarget] ?? null;

            if (is_numeric($first) && is_numeric($second) && (int) $first >= (int) $second) {
                $validator->errors()->add(
                    $prefix . '.params.' . $ltTarget,
                    'The ' . $ltTarget . ' must be greater than ' . $descriptor['name'] . '.',
                );
            }
        }
    }

    /**
     * A wall-clock family needs a fire time: EITHER a single `params.time` OR a non-empty `times[]`
     * list. When the family has a REQUIRED `time` descriptor and NEITHER is supplied, emit the
     * missing-time error under the same `params.time` key the descriptor-derived required_if used to
     * (so the write tests' assertions are unchanged). No-op for families without a time descriptor.
     *
     * @param  array<string, mixed>  $schedule
     * @param  array<string, mixed>  $params
     */
    private function validateTimeRequirement(ValidatorContract $validator, WorkflowScheduleFamily $family, array $schedule, array $params, string $prefix): void
    {
        $requiresTime = false;

        foreach ($family->paramDescriptors() as $descriptor) {
            if ($descriptor['type'] === 'time' && $descriptor['required']) {
                $requiresTime = true;
            }
        }

        if (!$requiresTime) {
            return;
        }

        $times = $schedule['times'] ?? null;
        $hasTimes = is_array($times) && $times !== [];
        $hasTime = array_key_exists('time', $params) && $params['time'] !== null && $params['time'] !== '';

        if (!$hasTimes && !$hasTime) {
            $validator->errors()->add(
                $prefix . '.params.time',
                'A time (or a times list) is required for the ' . $family->value . ' schedule.',
            );
        }
    }

    /**
     * Rules the base per-key rules can't express for the optional `times[]`:
     *   - ALLOWED FAMILY: `times` may only appear on a WALL-CLOCK family (one whose descriptors carry
     *     a `time` param). On an interval family (every_n_minutes/hourly/…) it is a 422.
     *   - MUTUAL EXCLUSION: when `times` is present, the scalar `params.time` must be ABSENT — the
     *     two express the same slot two ways and combining them is a mistake.
     * (Count 1..6, HH:mm shape and DISTINCT fire times are enforced by the base container/element
     * rules.) No-op when `times` is absent.
     *
     * @param  array<string, mixed>  $schedule
     * @param  array<string, mixed>  $params
     */
    private function validateTimes(ValidatorContract $validator, WorkflowScheduleFamily $family, array $schedule, array $params, string $prefix): void
    {
        $times = $schedule['times'] ?? null;

        if (!is_array($times) || $times === []) {
            return;
        }

        if (!$family->supportsTimes()) {
            $validator->errors()->add(
                $prefix . '.times',
                'The times list is not allowed for the ' . $family->value . ' schedule.',
            );

            return;
        }

        if (array_key_exists('time', $params) && $params['time'] !== null && $params['time'] !== '') {
            $validator->errors()->add(
                $prefix . '.times',
                'Provide either a single time or a times list, not both.',
            );
        }
    }

    /**
     * Reject an `exclusions` key outside the allowed set (months/weekdays/dates) — mirrors the
     * foreign-param guard so a descriptor-driven consumer gets explicit feedback, never a silent
     * drop. No-op when `exclusions` is absent or not an array.
     *
     * @param  array<string, mixed>  $schedule
     */
    private function rejectForeignExclusionKeys(ValidatorContract $validator, array $schedule, string $prefix): void
    {
        $exclusions = $schedule['exclusions'] ?? null;

        if (!is_array($exclusions)) {
            return;
        }

        $allowed = ['months', 'weekdays', 'dates'];

        foreach (array_keys($exclusions) as $name) {
            if (!in_array((string) $name, $allowed, true)) {
                $validator->errors()->add(
                    $prefix . '.exclusions.' . $name,
                    'The ' . $name . ' exclusion key is not allowed.',
                );
            }
        }
    }

    /**
     * EMPTY-SCHEDULE guard: after every structural rule passes, the cadence must yield at least one
     * concrete occurrence from now() within the service's horizon. An over-constrained config (e.g.
     * weekly-on-Monday that also excludes Mondays) produces NO occurrence — the service returns null
     * — so we reject it up front on the `exclusions` key rather than silently persist a schedule that
     * can never fire. Runs the SAME nextDueAt the write/sweep paths use, so the gate is exact; a
     * compile/throw is treated as no-occurrence (the compiler gate elsewhere reports the real cause).
     * Reuses nextOccurrences(…, 1) — the SAME seam the preview endpoint renders — so "does the
     * cadence have a first occurrence" is answered in exactly one place.
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
                'The schedule has no occurrences — its exclusions rule out every fire time.',
            );
        }
    }
}
