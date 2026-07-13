<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Workflows\Enums\ScheduleLimits;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkflowCrudTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    /** A valid form_submitted workflow payload (any-form, no filters). */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Onboarding workflow',
            'description' => 'Runs when a form is submitted.',
            'trigger_type' => 'form_submitted',
            'trigger_config' => ['form_id' => null],
            'conditions' => [],
            'steps' => [
                ['type' => 'create_task', 'key' => 'make_task', 'config' => ['title' => 'Follow up']],
            ],
        ], $overrides);
    }

    public function test_can_create_workflow_created_inactive(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                // status in the body is IGNORED — a workflow is always created inactive.
                'status' => 'active',
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Onboarding workflow')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.trigger_type', 'form_submitted')
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.can_change_status', true)
            ->assertJsonPath('data.can_run', true);

        $this->assertDatabaseHas('workflows', [
            'name' => 'Onboarding workflow',
            'status' => 'inactive',
            'creator_id' => $user->id,
        ]);
    }

    public function test_name_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload(['name' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_at_least_one_step_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload(['steps' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps']);
    }

    public function test_step_keys_must_be_distinct(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'steps' => [
                    ['type' => 'create_task', 'key' => 'dup', 'config' => ['title' => 'A']],
                    ['type' => 'create_task', 'key' => 'dup', 'config' => ['title' => 'B']],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.key', 'steps.1.key']);
    }

    public function test_invalid_step_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'steps' => [['type' => 'nope', 'key' => 'k', 'config' => []]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.type']);
    }

    // ---- schedule v2 write-validation ({ time, day?, month? }) ----------------

    /** A schedule payload wrapping a v2 { time, day?, month? } descriptor. */
    private function schedulePayload(array $schedule): array
    {
        return $this->validPayload([
            'trigger_type' => 'schedule',
            'trigger_config' => ['schedule' => $schedule],
        ]);
    }

    /** A time.mode=at block for one or more HH:mm fire times. */
    private function at(string ...$times): array
    {
        return ['mode' => 'at', 'at' => array_values($times)];
    }

    /**
     * Happy paths across the compositional descriptor: every time mode, every day mode (including the
     * special rules) and every month mode must be accepted so the v2 write-validation stays honest.
     */
    public function test_schedule_v2_accepts_valid_descriptors(): void
    {
        $user = User::factory()->create();

        $cases = [
            // time axis.
            ['time' => $this->at('09:30')],
            ['time' => $this->at('08:00', '17:00')],
            ['time' => ['mode' => 'every_minutes', 'minutes' => 15]],
            ['time' => ['mode' => 'every_minutes', 'minutes' => 15, 'from' => '09:30', 'to' => '17:45']],
            ['time' => ['mode' => 'every_hours', 'hours' => 2]],
            ['time' => ['mode' => 'every_hours', 'hours' => 2, 'minute' => 15, 'from' => 9, 'to' => 17]],
            // day axis.
            ['time' => $this->at('09:00'), 'day' => ['mode' => 'every_day']],
            ['time' => $this->at('09:00'), 'day' => ['mode' => 'weekdays', 'weekdays' => [1, 3, 5]]],
            ['time' => $this->at('09:00'), 'day' => ['mode' => 'month_days', 'days' => [1, 15]]],
            ['time' => $this->at('09:00'), 'day' => ['mode' => 'every_n_days', 'n' => 2]],
            ['time' => $this->at('09:00'), 'day' => ['mode' => 'every_n_days', 'n' => 2, 'from' => 5, 'to' => 20]],
            ['time' => $this->at('09:00'), 'day' => ['mode' => 'special', 'special' => 'last_day']],
            ['time' => $this->at('09:00'), 'day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => 2, 'weekday' => 1]],
            ['time' => $this->at('09:00'), 'day' => ['mode' => 'special', 'special' => 'last_weekday', 'weekday' => 5]],
            ['time' => $this->at('17:00'), 'day' => ['mode' => 'special', 'special' => 'last_working_day']],
            // month axis.
            ['time' => $this->at('09:00'), 'month' => ['mode' => 'every_month']],
            ['time' => $this->at('09:00'), 'month' => ['mode' => 'months', 'months' => [1, 4, 7, 10]]],
            ['time' => $this->at('09:00'), 'month' => ['mode' => 'every_n_months', 'n' => 3]],
            ['time' => $this->at('09:00'), 'month' => ['mode' => 'every_n_months', 'n' => 3, 'from' => 1, 'to' => 12]],
            // tz + exclusions.
            ['time' => $this->at('09:00'), 'tz' => 'Europe/Warsaw'],
            ['time' => $this->at('09:00'), 'exclusions' => ['weekdays' => [0, 6], 'months' => [8], 'dates' => ['2026-12-24']]],
        ];

        foreach ($cases as $schedule) {
            $this->actingAs($user)
                ->postJson('/api/workflows', $this->schedulePayload($schedule))
                ->assertCreated();
        }
    }

    public function test_schedule_requires_a_time_axis(): void
    {
        $user = User::factory()->create();

        // The time axis is the only required one; omitting it is a 422.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['day' => ['mode' => 'weekdays', 'weekdays' => [1]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time']);
    }

    public function test_schedule_rejects_an_unknown_time_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => ['mode' => 'fortnightly']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.mode']);
    }

    public function test_schedule_time_at_requires_a_non_empty_distinct_bounded_list(): void
    {
        $user = User::factory()->create();

        // Missing at-list.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => ['mode' => 'at']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.at']);

        // Duplicate fire times.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => $this->at('08:00', '08:00')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.at.0']);

        // More than six fire times.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => $this->at('00:00', '01:00', '02:00', '03:00', '04:00', '05:00', '06:00')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.at']);
    }

    public function test_schedule_every_minutes_bounds_and_window(): void
    {
        $user = User::factory()->create();

        // minutes is required and 1..59.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => ['mode' => 'every_minutes']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.minutes']);

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => ['mode' => 'every_minutes', 'minutes' => 60]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.minutes']);

        // A window needs both bounds.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => ['mode' => 'every_minutes', 'minutes' => 15, 'from' => '09:00']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.to']);

        // from must be before to (no midnight wrap).
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => ['mode' => 'every_minutes', 'minutes' => 15, 'from' => '17:00', 'to' => '09:00']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.to']);
    }

    public function test_schedule_every_hours_bounds_and_window(): void
    {
        $user = User::factory()->create();

        // hours is required and 1..23.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => ['mode' => 'every_hours', 'hours' => 0]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.hours']);

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => ['mode' => 'every_hours', 'hours' => 24]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.hours']);

        // Hour window from must be before to.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => ['mode' => 'every_hours', 'hours' => 2, 'from' => 17, 'to' => 9]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.to']);
    }

    public function test_schedule_rejects_a_field_foreign_to_the_time_mode(): void
    {
        $user = User::factory()->create();

        // `minutes` is not a field of the `at` mode — rejected as foreign.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => ['mode' => 'at', 'at' => ['09:00'], 'minutes' => 15]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.minutes']);
    }

    public function test_schedule_day_present_without_a_mode_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => $this->at('09:00'), 'day' => ['weekdays' => [1]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.day.mode']);
    }

    public function test_schedule_day_weekdays_list_rules(): void
    {
        $user = User::factory()->create();

        $base = ['time' => $this->at('09:00')];

        // Empty list.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['day' => ['mode' => 'weekdays', 'weekdays' => []]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.day.weekdays']);

        // Duplicate.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['day' => ['mode' => 'weekdays', 'weekdays' => [1, 1]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.day.weekdays.0']);

        // Out of range (7 > 6).
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['day' => ['mode' => 'weekdays', 'weekdays' => [1, 7]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.day.weekdays.1']);
    }

    public function test_schedule_day_month_days_list_rules(): void
    {
        $user = User::factory()->create();

        $base = ['time' => $this->at('09:00')];

        // Day 0 is out of range (1..31).
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['day' => ['mode' => 'month_days', 'days' => [0]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.day.days.0']);

        // Day 32 is out of range.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['day' => ['mode' => 'month_days', 'days' => [32]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.day.days.0']);
    }

    public function test_schedule_day_every_n_days_requires_n_and_a_valid_window(): void
    {
        $user = User::factory()->create();

        $base = ['time' => $this->at('09:00')];

        // n required.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['day' => ['mode' => 'every_n_days']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.day.n']);

        // Window both-or-neither.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['day' => ['mode' => 'every_n_days', 'n' => 2, 'from' => 5]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.day.to']);

        // from < to.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['day' => ['mode' => 'every_n_days', 'n' => 2, 'from' => 20, 'to' => 5]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.day.to']);
    }

    public function test_schedule_day_special_requires_and_bounds_its_params(): void
    {
        $user = User::factory()->create();

        $base = ['time' => $this->at('09:00')];

        // nth_weekday needs ordinal + weekday.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['day' => ['mode' => 'special', 'special' => 'nth_weekday']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'trigger_config.schedule.day.ordinal',
                'trigger_config.schedule.day.weekday',
            ]);

        // ordinal is 1..5.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['day' => ['mode' => 'special', 'special' => 'nth_weekday', 'ordinal' => 6, 'weekday' => 1]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.day.ordinal']);

        // last_weekday weekday is 0..6.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['day' => ['mode' => 'special', 'special' => 'last_weekday', 'weekday' => 7]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.day.weekday']);
    }

    public function test_schedule_day_special_rejects_a_foreign_param(): void
    {
        $user = User::factory()->create();

        // last_day takes no ordinal — a foreign param is rejected.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => $this->at('09:00'), 'day' => ['mode' => 'special', 'special' => 'last_day', 'ordinal' => 2]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.day.ordinal']);
    }

    public function test_schedule_last_working_day_requires_time_mode_at(): void
    {
        $user = User::factory()->create();

        // last_working_day is a bespoke HH:mm cadence — it must not be combined with a minute/hour grid.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'time' => ['mode' => 'every_hours', 'hours' => 2],
                'day' => ['mode' => 'special', 'special' => 'last_working_day'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.time.mode']);
    }

    public function test_schedule_month_bounds_and_window(): void
    {
        $user = User::factory()->create();

        $base = ['time' => $this->at('09:00')];

        // months element 13 is out of range (1..12).
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['month' => ['mode' => 'months', 'months' => [13]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.month.months.0']);

        // every_n_months n is 1..12.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['month' => ['mode' => 'every_n_months', 'n' => 13]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.month.n']);

        // Window both-or-neither.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['month' => ['mode' => 'every_n_months', 'n' => 2, 'from' => 3]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.month.to']);

        // from < to.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($base + ['month' => ['mode' => 'every_n_months', 'n' => 2, 'from' => 11, 'to' => 3]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.month.to']);
    }

    public function test_schedule_rejects_an_invalid_timezone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['time' => $this->at('09:00'), 'tz' => 'Mars/Phobos']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.tz']);
    }

    // ---- exclusions ----------------------------------------------------------

    public function test_schedule_rejects_eleven_plus_one_excluded_months(): void
    {
        $user = User::factory()->create();

        // 12 excluded months would leave the schedule with no month to fire — structurally capped at 11.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'time' => $this->at('09:00'),
                'exclusions' => ['months' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.exclusions.months']);
    }

    public function test_schedule_rejects_all_seven_excluded_weekdays(): void
    {
        $user = User::factory()->create();

        // 7 excluded weekdays would leave no day to fire — structurally capped at 6.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'time' => $this->at('09:00'),
                'exclusions' => ['weekdays' => [0, 1, 2, 3, 4, 5, 6]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.exclusions.weekdays']);
    }

    public function test_schedule_rejects_a_malformed_excluded_date(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'time' => $this->at('09:00'),
                'exclusions' => ['dates' => ['24-12-2026']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.exclusions.dates.0']);
    }

    public function test_schedule_rejects_a_foreign_exclusion_key(): void
    {
        $user = User::factory()->create();

        // A key outside {months, weekdays, dates} is rejected as foreign.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'time' => $this->at('09:00'),
                'exclusions' => ['years' => [2026]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.exclusions.years']);
    }

    public function test_schedule_rejects_an_empty_schedule_with_no_occurrences(): void
    {
        $user = User::factory()->create();

        // weekly-on-Monday that also excludes Mondays can never fire — rejected up front on the
        // exclusions key rather than persisting a schedule with no occurrences.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'time' => $this->at('09:00'),
                'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
                'exclusions' => ['weekdays' => [1]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.exclusions']);
    }

    public function test_schedule_missing_schedule_block_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_type' => 'schedule',
                'trigger_config' => [],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule']);
    }

    // ---- 422 error-key GRANULARITY contract (the FE tab mapping, §4.5.11) -----
    //
    // The builder routes a server 422 onto the offending tab / the exceptions
    // disclosure by matching the error-key PREFIX `trigger_config.schedule.{time|
    // day|month|exclusions|tz}` (WorkflowScheduleBuilder.vue). These tests pin that
    // the write path really emits errors under those GRANULAR keys — one
    // representative violation per branch — so the mapping can never silently break
    // if the validator's error keys drift. Rules/bounds come straight from
    // WorkflowScheduleRulesValidator + ScheduleLimits (no invented fields).

    /** The five branch keys the FE builder maps onto tabs / the exceptions disclosure. */
    private const SCHEDULE_BRANCH_ERROR_KEYS = [
        'trigger_config.schedule.time.minutes',   // → Time tab
        'trigger_config.schedule.day.weekdays',   // → Day tab
        'trigger_config.schedule.month.n',        // → Month tab
        'trigger_config.schedule.exclusions.dates', // → exceptions disclosure
        'trigger_config.schedule.tz',             // → tz field
    ];

    /**
     * A descriptor that breaks EACH of the five FE-mapped branches at once, one
     * representative out-of-bounds violation apiece, so a single 422 carries all
     * five granular keys.
     */
    private function everyBranchInvalidSchedule(): array
    {
        return [
            'time' => ['mode' => 'every_minutes', 'minutes' => ScheduleLimits::EVERY_MINUTES_MAX + 1],
            'day' => ['mode' => 'weekdays', 'weekdays' => []],
            'month' => ['mode' => 'every_n_months', 'n' => ScheduleLimits::EVERY_N_MONTHS_MAX + 1],
            'exclusions' => ['dates' => $this->distinctDates(ScheduleLimits::EXCLUSIONS_DATES_MAX + 1)],
            'tz' => 'Mars/Phobos',
        ];
    }

    /** $count distinct valid Y-m-d dates (to overflow a structurally-bounded list). */
    private function distinctDates(int $count): array
    {
        return array_map(
            fn (int $i) => Carbon::create(2026, 1, 1)->addDays($i)->format('Y-m-d'),
            range(0, $count - 1),
        );
    }

    public function test_schedule_422_error_keys_are_granular_per_branch_on_store(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload($this->everyBranchInvalidSchedule()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(self::SCHEDULE_BRANCH_ERROR_KEYS);
    }

    public function test_schedule_422_error_keys_are_granular_per_branch_on_update(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        // Update shares Store's schedule rules — the FE tab-mapping contract must hold on PUT too.
        $this->actingAs($user)
            ->putJson("/api/workflows/{$workflow->id}", $this->schedulePayload($this->everyBranchInvalidSchedule()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(self::SCHEDULE_BRANCH_ERROR_KEYS);
    }

    public function test_cross_type_trigger_config_is_rejected(): void
    {
        $user = User::factory()->create();

        // A schedule block on a form_submitted trigger is nonsense => rejected as foreign.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_type' => 'form_submitted',
                'trigger_config' => ['schedule' => ['time' => $this->at('09:00')]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule']);

        // A form_id on a schedule trigger is nonsense => rejected as foreign.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_type' => 'schedule',
                'trigger_config' => [
                    'schedule' => ['time' => $this->at('09:00')],
                    'form_id' => '00000000-0000-0000-0000-000000000000',
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.form_id']);
    }

    public function test_form_submitted_accepts_a_null_form_id(): void
    {
        $user = User::factory()->create();

        // Happy path: form_submitted with a null form_id (fires for any form).
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_type' => 'form_submitted',
                'trigger_config' => ['form_id' => null],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.trigger_type', 'form_submitted');
    }

    public function test_form_submitted_accepts_a_same_workspace_form_id(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);

        $ownForm = Form::factory()->create([
            'creator_id' => $user->id,
            'workspace_id' => $workspaceA->id,
        ]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_type' => 'form_submitted',
                'trigger_config' => ['form_id' => $ownForm->id],
            ]))
            ->assertCreated();
    }

    public function test_form_submitted_rejects_a_foreign_workspace_form_id(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);

        $foreignForm = Form::factory()->create([
            'creator_id' => $user->id,
            'workspace_id' => $workspaceB->id,
        ]);

        // ScopedExists must reject a form id that lives in another workspace.
        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_type' => 'form_submitted',
                'trigger_config' => ['form_id' => $foreignForm->id],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.form_id']);
    }

    public function test_form_submitted_accepts_source_and_anonymous_filters(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_type' => 'form_submitted',
                'trigger_config' => ['source' => ['in' => ['manual', 'task']], 'anonymous' => true],
            ]))
            ->assertCreated();
    }

    public function test_form_submitted_rejects_an_invalid_source(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_type' => 'form_submitted',
                'trigger_config' => ['source' => ['in' => ['nope']]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.source.in.0']);
    }

    // ---- typed conditions write-validation ----------------------------------

    /** A form the conditions can attach to (conditions require a selected form). */
    private function conditionForm(User $user): Form
    {
        return Form::factory()->create(['creator_id' => $user->id]);
    }

    public function test_conditions_reject_an_invalid_operator(): void
    {
        $user = User::factory()->create();
        $form = $this->conditionForm($user);

        // `conditions.*.operator` is an enum rule — an unknown operator is rejected.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_config' => ['form_id' => $form->id],
                'conditions' => [
                    ['field' => 'fields.priority', 'field_type' => 'text', 'operator' => 'not_a_real_operator', 'value' => 'high'],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['conditions.0.operator']);
    }

    public function test_conditions_reject_an_operator_type_mismatch(): void
    {
        $user = User::factory()->create();
        $form = $this->conditionForm($user);

        // `gt` is a NUMBER operator — invalid on a text field.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_config' => ['form_id' => $form->id],
                'conditions' => [
                    ['field' => 'fields.priority', 'field_type' => 'text', 'operator' => 'gt', 'value' => '1'],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['conditions.0.operator']);
    }

    public function test_conditions_require_a_selected_form(): void
    {
        $user = User::factory()->create();

        // form_id is null while a condition is present — rejected (field conditions need a form).
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_config' => ['form_id' => null],
                'conditions' => [
                    ['field' => 'fields.priority', 'field_type' => 'text', 'operator' => 'equals', 'value' => 'high'],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.form_id']);
    }

    public function test_conditions_are_rejected_on_a_schedule_trigger(): void
    {
        $user = User::factory()->create();

        // schedule has no condition source in the MVP — any condition is rejected.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_type' => 'schedule',
                'trigger_config' => ['schedule' => ['time' => $this->at('09:00')]],
                'conditions' => [
                    ['field' => 'fields.priority', 'field_type' => 'text', 'operator' => 'equals', 'value' => 'high'],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['conditions']);
    }

    public function test_between_condition_requires_two_dates(): void
    {
        $user = User::factory()->create();
        $form = $this->conditionForm($user);

        // A `between` date condition needs a [from, to] pair.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_config' => ['form_id' => $form->id],
                'conditions' => [
                    ['field' => 'fields.due', 'field_type' => 'date', 'operator' => 'between', 'value' => ['2026-01-01']],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['conditions.0.value']);
    }

    public function test_conditions_accept_a_valid_typed_condition(): void
    {
        $user = User::factory()->create();
        $form = $this->conditionForm($user);

        // Happy path: a well-formed typed condition (field_type + valid operator + value).
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_config' => ['form_id' => $form->id],
                'conditions' => [
                    ['field' => 'fields.priority', 'field_type' => 'text', 'operator' => 'equals', 'value' => 'high'],
                ],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.conditions.0.field', 'fields.priority')
            ->assertJsonPath('data.conditions.0.field_type', 'text')
            ->assertJsonPath('data.conditions.0.operator', 'equals');
    }

    public function test_boolean_condition_needs_no_value(): void
    {
        $user = User::factory()->create();
        $form = $this->conditionForm($user);

        // is_true / is_false are value-less — omitting value is accepted.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_config' => ['form_id' => $form->id],
                'conditions' => [
                    ['field' => 'fields.agree', 'field_type' => 'boolean', 'operator' => 'is_true'],
                ],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.conditions.0.operator', 'is_true');
    }

    // ---- per-step-type config write-validation -------------------------------

    /** Build a payload with the given steps (form_submitted, no filters). */
    private function stepsPayload(array $steps): array
    {
        return $this->validPayload(['steps' => $steps]);
    }

    public function test_create_task_step_accepts_the_full_field_config(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create(['creator_id' => $user->id]);
        $pipeline = \App\Modules\Approvals\Models\ApprovalPipeline::factory()->create(['creator_id' => $user->id]);
        $label = \App\Modules\Labels\Models\Label::create(['name' => 'L']);
        $bot = \App\Modules\Bot\Models\Bot::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->stepsPayload([[
                'type' => 'create_task', 'key' => 'k', 'config' => [
                    'title' => 'Task',
                    'description' => 'Body',
                    'priority' => ['kind' => 'literal', 'value' => 'high'],
                    'deadline' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'submitted_at', 'type' => 'date']],
                    'labels' => [$label->id],
                    'assignee_type' => 'bot',
                    'assignee_id' => $bot->id,
                    'form_id' => $form->id,
                    'approval_pipeline_id' => $pipeline->id,
                ],
            ]]))
            ->assertCreated();
    }

    public function test_create_task_step_requires_a_title(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->stepsPayload([
                ['type' => 'create_task', 'key' => 'k', 'config' => ['description' => 'no title']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.title']);
    }

    public function test_create_task_step_rejects_an_invalid_priority_literal(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->stepsPayload([
                ['type' => 'create_task', 'key' => 'k', 'config' => [
                    'title' => 'T', 'priority' => ['kind' => 'literal', 'value' => 'nope'],
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.priority']);
    }

    public function test_create_task_step_rejects_a_malformed_variable_union(): void
    {
        $user = User::factory()->create();

        // A variable ref missing source/type is rejected on the ref sub-keys.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->stepsPayload([
                ['type' => 'create_task', 'key' => 'k', 'config' => [
                    'title' => 'T',
                    'deadline' => ['kind' => 'variable', 'ref' => ['path' => 'submitted_at']],
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'steps.0.config.deadline.ref.source',
                'steps.0.config.deadline.ref.type',
            ]);
    }

    public function test_create_task_step_requires_assignee_pair_together(): void
    {
        $user = User::factory()->create();

        // assignee_type without assignee_id is rejected (both-or-neither).
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->stepsPayload([
                ['type' => 'create_task', 'key' => 'k', 'config' => [
                    'title' => 'T', 'assignee_type' => 'user',
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.assignee_id']);
    }

    public function test_create_task_step_rejects_a_foreign_workspace_form_id(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);
        $foreignForm = Form::factory()->create(['creator_id' => $user->id, 'workspace_id' => $workspaceB->id]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->postJson('/api/workflows', $this->stepsPayload([
                ['type' => 'create_task', 'key' => 'k', 'config' => ['title' => 'T', 'form_id' => $foreignForm->id]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.form_id']);
    }

    public function test_create_task_step_rejects_an_unknown_config_key(): void
    {
        $user = User::factory()->create();

        // `guidelines` belongs to create_form_report, not create_task — foreign key rejected.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->stepsPayload([
                ['type' => 'create_task', 'key' => 'k', 'config' => ['title' => 'T', 'guidelines' => 'x']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.guidelines']);
    }

    public function test_create_form_report_step_accepts_a_valid_config(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->stepsPayload([[
                'type' => 'create_form_report', 'key' => 'report', 'config' => [
                    'form_id' => $form->id,
                    'name' => 'Digest',
                    'guidelines' => 'Trends',
                    'sources' => ['task', 'form'],
                    'submissions_from' => ['kind' => 'literal', 'value' => '2026-01-01'],
                    'submissions_to' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'submitted_at', 'type' => 'date']],
                ],
            ]]))
            ->assertCreated();
    }

    public function test_create_form_report_step_requires_form_id_and_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->stepsPayload([
                ['type' => 'create_form_report', 'key' => 'r', 'config' => []],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.form_id', 'steps.0.config.name']);
    }

    public function test_create_form_report_step_rejects_an_invalid_source(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->stepsPayload([[
                'type' => 'create_form_report', 'key' => 'r', 'config' => [
                    'form_id' => $form->id, 'name' => 'N', 'sources' => ['nope'],
                ],
            ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.sources.0']);
    }

    public function test_create_form_report_step_rejects_a_create_task_field(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create(['creator_id' => $user->id]);

        // `priority` belongs to create_task — nonsense on a report step, rejected as foreign.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->stepsPayload([[
                'type' => 'create_form_report', 'key' => 'r', 'config' => [
                    'form_id' => $form->id, 'name' => 'N', 'priority' => 'high',
                ],
            ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.0.config.priority']);
    }

    public function test_step_config_validation_targets_the_right_step_by_index(): void
    {
        $user = User::factory()->create();
        $form = Form::factory()->create(['creator_id' => $user->id]);

        // A valid create_task first, then a create_form_report missing its name — only the
        // second step errors, keyed by its index.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->stepsPayload([
                ['type' => 'create_task', 'key' => 'a', 'config' => ['title' => 'ok']],
                ['type' => 'create_form_report', 'key' => 'b', 'config' => ['form_id' => $form->id]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['steps.1.config.name'])
            ->assertJsonMissingValidationErrors(['steps.0.config.title']);
    }

    public function test_can_update_own_workflow(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->putJson("/api/workflows/{$workflow->id}", $this->validPayload(['name' => 'Renamed']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_cannot_update_another_users_workflow(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        $this->actingAs($other)
            ->putJson("/api/workflows/{$workflow->id}", $this->validPayload())
            ->assertForbidden();
    }

    public function test_status_ignored_on_update(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        // status in the update body must not change status — only the status endpoint can.
        $this->actingAs($user)
            ->putJson("/api/workflows/{$workflow->id}", $this->validPayload(['status' => 'active']))
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('workflows', ['id' => $workflow->id, 'status' => 'inactive']);
    }

    public function test_cross_type_trigger_config_is_rejected_on_update(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        // Update shares Store's rules — the cross-type guard must fire on PUT too.
        $this->actingAs($user)
            ->putJson("/api/workflows/{$workflow->id}", $this->validPayload([
                'trigger_type' => 'form_submitted',
                'trigger_config' => ['schedule' => ['time' => $this->at('09:00')]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule']);
    }

    public function test_status_change_via_patch_by_creator(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->patchJson("/api/workflows/{$workflow->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('workflows', ['id' => $workflow->id, 'status' => 'active']);
    }

    public function test_detail_resource_upgrades_a_legacy_schedule_to_v2_for_the_editor_seed(): void
    {
        $user = User::factory()->create();

        // A row written before the schedule rebuild still stores the legacy { family, params } shape.
        $workflow = Workflow::factory()->create([
            'creator_id' => $user->id,
            'trigger_type' => 'schedule',
            'trigger_config' => ['schedule' => ['family' => 'daily', 'params' => ['time' => '09:00']]],
        ]);

        // The detail Resource upgrades it to the v2 compositional descriptor (read-shim), so the FE
        // editor seeds from one shape — the legacy `family`/`params` keys are gone.
        $schedule = $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}")
            ->assertOk()
            ->assertJsonPath('data.trigger_config.schedule.time.mode', 'at')
            ->assertJsonPath('data.trigger_config.schedule.time.at', ['09:00'])
            ->json('data.trigger_config.schedule');

        $this->assertArrayNotHasKey('family', $schedule);
        $this->assertArrayNotHasKey('params', $schedule);
    }
}
