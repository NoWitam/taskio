<?php

namespace Tests\Unit\Workflows;

use App\Modules\Workflows\Services\WorkflowScheduleRulesValidator;
use App\Support\Recurrence\Enums\RecurrenceViolationCode;
use App\Support\Recurrence\RecurrenceViolation;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The module's half of the validation split: the SENTENCES an automation author reads, and the
 * guarantee that every code the shared grammar can produce has one.
 *
 * The grammar itself is asserted in the shared layer's own test. What is asserted here is the seam:
 * a code with no rendering would reach a user as a missing error — a field that refuses to save with
 * nothing said about why — and nothing else in the suite would notice.
 */
class WorkflowScheduleRulesRendererTest extends TestCase
{
    private WorkflowScheduleRulesValidator $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new WorkflowScheduleRulesValidator;
    }

    /** The private renderer, reached directly: every case must be exercised, valid descriptor or not. */
    private function render(RecurrenceViolationCode $code, array $context = []): string
    {
        $method = new ReflectionMethod(WorkflowScheduleRulesValidator::class, 'message');

        return $method->invoke($this->rules, new RecurrenceViolation('time.mode', $code, $context));
    }

    /**
     * EXHAUSTIVE over the grammar. A `match` with no default arm throws on an unhandled case, so a
     * code added to the shared layer and not rendered here fails this test by name rather than
     * reaching a user as a blank error.
     */
    public function test_every_violation_code_renders_a_sentence(): void
    {
        foreach (RecurrenceViolationCode::cases() as $code) {
            $message = $this->render($code, ['field' => 'minutes', 'special' => 'nth_weekday']);

            $this->assertNotSame('', trim($message), $code->value . ' renders an empty message');
            $this->assertStringEndsWith('.', $message, $code->value . ' does not render a sentence');
        }
    }

    /**
     * The wording itself, spot-checked against what this module said BEFORE the grammar moved down a
     * layer. These strings are what the assist path shows a user when it refuses a model's proposal,
     * so a silent rewording is a user-visible change, not a refactor.
     */
    public function test_the_wording_is_unchanged_by_the_extraction(): void
    {
        $this->assertSame(
            'The minutes field is required for this mode.',
            $this->render(RecurrenceViolationCode::MODE_FIELD_REQUIRED, ['field' => 'minutes']),
        );

        $this->assertSame(
            'The at list is required for this mode.',
            $this->render(RecurrenceViolationCode::MODE_LIST_REQUIRED, ['field' => 'at']),
        );

        $this->assertSame(
            'The window end must be after its start (a window cannot wrap midnight).',
            $this->render(RecurrenceViolationCode::WINDOW_TIMES_NOT_ASCENDING),
        );

        $this->assertSame(
            'The last_working_day rule requires explicit fire times (time.mode must be at).',
            $this->render(RecurrenceViolationCode::SPECIAL_REQUIRES_AT_TIME, ['special' => 'last_working_day']),
        );

        $this->assertSame(
            'The schedule has no occurrences — its rules and exclusions rule out every fire time.',
            $this->render(RecurrenceViolationCode::NO_OCCURRENCE),
        );
    }

    /**
     * END TO END through the module's own entry point: the standalone check the AI assist path runs.
     * It is the one seam that returns flat MESSAGES rather than keys, so it is where a broken
     * rendering would show up in production.
     */
    public function test_the_standalone_check_returns_the_rendered_messages(): void
    {
        $this->assertSame([], $this->rules->validate([
            'time' => ['mode' => 'at', 'at' => ['09:00']],
            'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
        ]));

        $this->assertContains(
            'The at list is required for this mode.',
            $this->rules->validate(['time' => ['mode' => 'at']]),
        );

        $this->assertContains(
            'The schedule has no occurrences — its rules and exclusions rule out every fire time.',
            $this->rules->validate([
                'time' => ['mode' => 'at', 'at' => ['09:00']],
                'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
                'exclusions' => ['weekdays' => [1]],
            ]),
        );
    }

    /** The emptiness guard is opt-out, and the preview path is the caller that opts out. */
    public function test_the_emptiness_guard_can_be_turned_off(): void
    {
        $unreachable = [
            'time' => ['mode' => 'at', 'at' => ['09:00']],
            'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
            'exclusions' => ['weekdays' => [1]],
        ];

        $this->assertSame([], $this->rules->validate($unreachable, checkEmpty: false));
    }

    /** A legacy `{ family, params }` block is judged by the v2 rules, as it was before. */
    public function test_a_legacy_block_is_upgraded_before_it_is_judged(): void
    {
        $this->assertSame([], $this->rules->validate([
            'family' => 'weekly',
            'params' => ['weekdays' => [1], 'time' => '09:00'],
        ]));
    }
}
