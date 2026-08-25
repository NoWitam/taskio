<?php

namespace Tests\Unit\Recurrence;

use App\Support\Recurrence\Enums\RecurrenceViolationCode;
use App\Support\Recurrence\RecurrenceDescriptorValidator;
use App\Support\Recurrence\RecurrenceViolation;
use Tests\TestCase;

/**
 * The SHARED definition of a well-formed descriptor — codes, paths, and nothing a user reads.
 *
 * These are the rules that used to live inside the automations module's validator, and the reason
 * they moved is that a second module accepting a recurrence descriptor may not name the first one:
 * leaving them there would have produced a second definition of "valid" the day that module was
 * written. What is asserted here is the grammar; what each module SAYS about a violation is asserted
 * next to that module's renderer.
 */
class RecurrenceDescriptorValidatorTest extends TestCase
{
    private RecurrenceDescriptorValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new RecurrenceDescriptorValidator;
    }

    /** @return array<int, string> `path:code` pairs, in the order the validator found them */
    private function found(array $descriptor): array
    {
        return array_map(
            fn (RecurrenceViolation $violation): string => $violation->path . ':' . $violation->code->value,
            $this->validator->violations($descriptor),
        );
    }

    public function test_a_sound_descriptor_has_no_violations(): void
    {
        $this->assertSame([], $this->found([
            'time' => ['mode' => 'at', 'at' => ['09:00']],
            'day' => ['mode' => 'weekdays', 'weekdays' => [1, 3]],
            'month' => ['mode' => 'months', 'months' => [1, 6]],
            'exclusions' => ['dates' => ['2026-08-12']],
            'tz' => 'Europe/Warsaw',
        ]));
    }

    public function test_a_mode_that_is_missing_its_required_field_is_reported_on_that_field(): void
    {
        $this->assertSame(
            ['time.minutes:mode_field_required'],
            $this->found(['time' => ['mode' => 'every_minutes']]),
        );

        $this->assertSame(
            ['time.at:mode_list_required'],
            $this->found(['time' => ['mode' => 'at']]),
        );
    }

    public function test_a_key_foreign_to_the_chosen_mode_is_rejected_rather_than_ignored(): void
    {
        $this->assertSame(
            ['time.minutes:field_not_allowed_for_mode'],
            $this->found(['time' => ['mode' => 'at', 'at' => ['09:00'], 'minutes' => 5]]),
        );
    }

    public function test_a_window_is_both_bounds_or_neither(): void
    {
        $this->assertSame(
            ['time.to:window_incomplete'],
            $this->found(['time' => ['mode' => 'every_minutes', 'minutes' => 5, 'from' => '09:00']]),
        );
    }

    public function test_a_window_must_ascend_and_each_axis_says_so_in_its_own_vocabulary(): void
    {
        $this->assertSame(
            ['time.to:window_times_not_ascending'],
            $this->found(['time' => ['mode' => 'every_minutes', 'minutes' => 5, 'from' => '17:00', 'to' => '09:00']]),
        );

        $this->assertSame(
            ['time.to:window_hours_not_ascending'],
            $this->found(['time' => ['mode' => 'every_hours', 'hours' => 2, 'from' => 17, 'to' => 9]]),
        );

        $this->assertSame(
            ['day.to:window_bounds_not_ascending'],
            $this->found([
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'every_n_days', 'n' => 2, 'from' => 10, 'to' => 5],
            ]),
        );
    }

    public function test_a_malformed_window_bound_is_reported_per_bound(): void
    {
        $this->assertSame(
            ['time.from:window_start_not_time', 'time.to:window_end_not_time'],
            $this->found(['time' => ['mode' => 'every_minutes', 'minutes' => 5, 'from' => 'noon', 'to' => '25:99']]),
        );
    }

    public function test_a_special_day_rule_reports_the_params_it_needs(): void
    {
        $this->assertSame(
            ['day.ordinal:special_needs_ordinal', 'day.weekday:special_needs_weekday'],
            $this->found([
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'special', 'special' => 'nth_weekday'],
            ]),
        );
    }

    /**
     * The bespoke last-working-day cadence exists only at explicit fire times, and the contradiction
     * is reported on the TIME axis — where it has to be resolved, not where it was noticed.
     */
    public function test_the_last_working_day_rule_is_reported_against_the_time_axis(): void
    {
        $violations = $this->validator->violations([
            'time' => ['mode' => 'every_minutes', 'minutes' => 5],
            'day' => ['mode' => 'special', 'special' => 'last_working_day'],
        ]);

        $this->assertSame(['time.mode'], array_map(fn ($v) => $v->path, $violations));
        $this->assertSame(RecurrenceViolationCode::SPECIAL_REQUIRES_AT_TIME, $violations[0]->code);
        $this->assertSame('last_working_day', $violations[0]->context('special'));
    }

    public function test_an_unknown_exclusion_key_is_named(): void
    {
        $violations = $this->validator->violations([
            'time' => ['mode' => 'at', 'at' => ['09:00']],
            'exclusions' => ['years' => [2026]],
        ]);

        $this->assertSame('exclusions.years', $violations[0]->path);
        $this->assertSame(RecurrenceViolationCode::EXCLUSION_KEY_NOT_ALLOWED, $violations[0]->code);
        $this->assertSame('years', $violations[0]->context('field'));
    }

    /**
     * An axis with no usable mode is SKIPPED rather than reported field by field: the consumer's own
     * enum rule has already said the mode is the problem, and a pile of "required for this mode" on
     * top of it describes a descriptor nobody wrote.
     */
    public function test_an_axis_without_a_usable_mode_is_left_to_the_consumers_enum_rule(): void
    {
        $this->assertSame([], $this->found(['time' => ['mode' => 'whenever', 'minutes' => 5]]));
        $this->assertSame([], $this->found(['time' => 'not-even-an-array']));

        // ...but an axis that is present and names NO mode at all has nothing else to report it.
        $this->assertSame(
            ['day.mode:mode_required'],
            $this->found([
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['weekdays' => [1]],
            ]),
        );
    }

    /** Absent day/month axes mean "every day of every month" and are not a violation. */
    public function test_the_optional_axes_may_be_absent_or_empty(): void
    {
        $this->assertSame([], $this->found([
            'time' => ['mode' => 'at', 'at' => ['09:00']],
            'day' => [],
            'month' => [],
        ]));
    }

    /** Violations come back axis by axis, in a stable order a consumer can render straight through. */
    public function test_violations_are_ordered_time_then_day_then_month_then_exclusions(): void
    {
        $this->assertSame(
            [
                'time.minutes:mode_field_required',
                'day.weekdays:mode_list_required',
                'month.months:mode_list_required',
                'exclusions.years:exclusion_key_not_allowed',
            ],
            $this->found([
                'time' => ['mode' => 'every_minutes'],
                'day' => ['mode' => 'weekdays'],
                'month' => ['mode' => 'months'],
                'exclusions' => ['years' => [2026]],
            ]),
        );
    }

    /**
     * Reachability is a separate question with a separate cost: a structurally perfect descriptor can
     * still rule out every occurrence it would ever have had.
     */
    public function test_an_over_constrained_cadence_is_unreachable(): void
    {
        $mondaysExceptMondays = [
            'time' => ['mode' => 'at', 'at' => ['09:00']],
            'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
            'exclusions' => ['weekdays' => [1]],
        ];

        $this->assertSame([], $this->validator->violations($mondaysExceptMondays), 'its SHAPE is sound');

        $unreachable = $this->validator->unreachable($mondaysExceptMondays);

        $this->assertCount(1, $unreachable);
        $this->assertSame('exclusions', $unreachable[0]->path);
        $this->assertSame(RecurrenceViolationCode::NO_OCCURRENCE, $unreachable[0]->code);
    }

    public function test_a_reachable_cadence_reports_nothing(): void
    {
        $this->assertSame([], $this->validator->unreachable([
            'time' => ['mode' => 'at', 'at' => ['09:00']],
            'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
        ]));
    }

    /** A descriptor the engine cannot even project is unusable for the same practical reason. */
    public function test_a_descriptor_the_engine_rejects_counts_as_unreachable(): void
    {
        $this->assertCount(1, $this->validator->unreachable(['time' => ['mode' => 'every_minutes', 'minutes' => 'five']]));
    }
}
