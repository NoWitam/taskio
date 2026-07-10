<?php

namespace App\Modules\Workflows\Enums;

/**
 * Frequency FAMILIES for a schedule-triggered workflow, mirroring Laravel's scheduler
 * cadences (everyNMinutes / hourlyAt / dailyAt / twiceDaily / weeklyOn / monthlyOn / …).
 * Each family owns a validated `params` shape; the concrete `next_due_at` a family implies
 * is computed by WorkflowScheduleService via WorkflowScheduleCompiler.
 *
 * CONFIG SHAPE (validated by StoreWorkflowRequest::scheduleRules):
 *   trigger_config.schedule = { family, params: {…per family}, tz? }
 *
 * The enum is the SINGLE source of truth for the family vocabulary and its per-family
 * param descriptors. Both the validation rules and the /meta/schedule-families discovery
 * endpoint derive from paramDescriptors(), so the FE tiers and the future AI-assist batch
 * consume one contract that cannot drift from what the backend accepts.
 *
 * Descriptor param type vocabulary (consumed by the FE/AI):
 *   - int:          an integer bounded by min/max.
 *   - time:         an 'HH:mm' wall-clock string (date_format:H:i).
 *   - weekday:      an int 0..6, 0=Sunday (Carbon convention, consistent across the module).
 *   - weekday_list: a NON-EMPTY array of DISTINCT weekday ints (each 0..6, 0=Sunday). The
 *                   descriptor's min/max bound the ELEMENTS (0..6), so a descriptor-driven
 *                   consumer knows which element values are legal, not the array length.
 */
enum WorkflowScheduleFamily: string
{
    case EVERY_N_MINUTES = 'every_n_minutes';
    case HOURLY = 'hourly';
    case HOURLY_AT = 'hourly_at';
    case EVERY_N_HOURS = 'every_n_hours';
    case DAILY = 'daily';
    case TWICE_DAILY = 'twice_daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case TWICE_MONTHLY = 'twice_monthly';
    case LAST_DAY_OF_MONTH = 'last_day_of_month';
    case QUARTERLY = 'quarterly';
    case YEARLY = 'yearly';
    case EVERY_N_MONTHS = 'every_n_months';
    case NTH_WEEKDAY_OF_MONTH = 'nth_weekday_of_month';
    case LAST_WEEKDAY_OF_MONTH = 'last_weekday_of_month';
    case LAST_WORKING_DAY_OF_MONTH = 'last_working_day_of_month';

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether this family accepts the optional `times[]` extension (a set of wall-clock HH:mm
     * fire times replacing the single `time` param). Allowed EXACTLY for the families whose
     * descriptors carry a `time` param — the wall-clock families. When `times` is present the
     * scalar `time` param must be absent (they are mutually exclusive; enforced by the validator).
     *
     * The interval families (every_n_minutes/hourly/hourly_at/every_n_hours/twice_daily) have no
     * `time` param and therefore forbid `times`.
     */
    public function supportsTimes(): bool
    {
        foreach ($this->paramDescriptors() as $descriptor) {
            if ($descriptor['type'] === 'time') {
                return true;
            }
        }

        return false;
    }

    /**
     * Per-family param descriptors — the ONE source the validation rules and the meta
     * endpoint both read. Each descriptor is `{name, type, required, min?, max?, lt?}`.
     * Keeping bounds here (not just in the FormRequest) means the FE can render a correct
     * control and the AI-assist can propose a valid params object without guessing.
     *
     * `lt` names ANOTHER param of the same family this one must be strictly LESS THAN
     * (the twice_daily/twice_monthly ordering invariants) — surfaced in the descriptors so
     * descriptor-driven consumers (FE builder, AI assist) can enforce it client-side
     * instead of discovering it via a 422.
     *
     * @return array<int, array{name: string, type: string, required: bool, min?: int, max?: int, lt?: string}>
     */
    public function paramDescriptors(): array
    {
        return match ($this) {
            self::EVERY_N_MINUTES => [
                ['name' => 'n', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 59],
            ],
            self::HOURLY => [],
            self::HOURLY_AT => [
                ['name' => 'minute', 'type' => 'int', 'required' => true, 'min' => 0, 'max' => 59],
            ],
            self::EVERY_N_HOURS => [
                ['name' => 'n', 'type' => 'int', 'required' => true, 'min' => 2, 'max' => 12],
                ['name' => 'minute', 'type' => 'int', 'required' => false, 'min' => 0, 'max' => 59],
            ],
            self::DAILY => [
                ['name' => 'time', 'type' => 'time', 'required' => true],
            ],
            self::TWICE_DAILY => [
                ['name' => 'first_hour', 'type' => 'int', 'required' => true, 'min' => 0, 'max' => 23, 'lt' => 'second_hour'],
                ['name' => 'second_hour', 'type' => 'int', 'required' => true, 'min' => 0, 'max' => 23],
                ['name' => 'minute', 'type' => 'int', 'required' => false, 'min' => 0, 'max' => 59],
            ],
            self::WEEKLY => [
                ['name' => 'weekdays', 'type' => 'weekday_list', 'required' => true, 'min' => 0, 'max' => 6],
                ['name' => 'time', 'type' => 'time', 'required' => true],
            ],
            self::MONTHLY => [
                ['name' => 'day', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 31],
                ['name' => 'time', 'type' => 'time', 'required' => true],
            ],
            self::TWICE_MONTHLY => [
                ['name' => 'first_day', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 31, 'lt' => 'second_day'],
                ['name' => 'second_day', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 31],
                ['name' => 'time', 'type' => 'time', 'required' => true],
            ],
            self::LAST_DAY_OF_MONTH => [
                ['name' => 'time', 'type' => 'time', 'required' => true],
            ],
            self::QUARTERLY => [
                ['name' => 'day', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 31],
                ['name' => 'time', 'type' => 'time', 'required' => true],
            ],
            self::YEARLY => [
                ['name' => 'month', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 12],
                ['name' => 'day', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 31],
                ['name' => 'time', 'type' => 'time', 'required' => true],
            ],
            self::EVERY_N_MONTHS => [
                ['name' => 'n', 'type' => 'int', 'required' => true, 'min' => 2, 'max' => 6],
                ['name' => 'day', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 31],
                ['name' => 'time', 'type' => 'time', 'required' => true],
            ],
            self::NTH_WEEKDAY_OF_MONTH => [
                ['name' => 'ordinal', 'type' => 'int', 'required' => true, 'min' => 1, 'max' => 5],
                ['name' => 'weekday', 'type' => 'weekday', 'required' => true, 'min' => 0, 'max' => 6],
                ['name' => 'time', 'type' => 'time', 'required' => true],
            ],
            self::LAST_WEEKDAY_OF_MONTH => [
                ['name' => 'weekday', 'type' => 'weekday', 'required' => true, 'min' => 0, 'max' => 6],
                ['name' => 'time', 'type' => 'time', 'required' => true],
            ],
            self::LAST_WORKING_DAY_OF_MONTH => [
                ['name' => 'time', 'type' => 'time', 'required' => true],
            ],
        };
    }
}
