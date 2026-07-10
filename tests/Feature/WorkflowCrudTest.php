<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Forms\Models\Form;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** A schedule payload for the given family/params. */
    private function schedulePayload(array $schedule): array
    {
        return $this->validPayload([
            'trigger_type' => 'schedule',
            'trigger_config' => ['schedule' => $schedule],
        ]);
    }

    /**
     * Happy paths across the frequency families. Each valid params object must be accepted so
     * the family->rules derivation stays honest as the vocabulary grows.
     */
    public function test_schedule_families_accept_valid_params(): void
    {
        $user = User::factory()->create();

        $cases = [
            ['family' => 'every_n_minutes', 'params' => ['n' => 15]],
            ['family' => 'hourly', 'params' => []],
            ['family' => 'hourly_at', 'params' => ['minute' => 30]],
            ['family' => 'every_n_hours', 'params' => ['n' => 6, 'minute' => 15]],
            ['family' => 'daily', 'params' => ['time' => '09:30']],
            ['family' => 'twice_daily', 'params' => ['first_hour' => 9, 'second_hour' => 17]],
            ['family' => 'weekly', 'params' => ['weekdays' => [1], 'time' => '09:30']],
            ['family' => 'weekly', 'params' => ['weekdays' => [1, 3, 5], 'time' => '09:30']],
            ['family' => 'monthly', 'params' => ['day' => 15, 'time' => '09:00']],
            ['family' => 'twice_monthly', 'params' => ['first_day' => 1, 'second_day' => 15, 'time' => '09:00']],
            ['family' => 'last_day_of_month', 'params' => ['time' => '18:00']],
            ['family' => 'quarterly', 'params' => ['day' => 1, 'time' => '09:00']],
            ['family' => 'yearly', 'params' => ['month' => 12, 'day' => 25, 'time' => '09:00']],
            ['family' => 'every_n_months', 'params' => ['n' => 2, 'day' => 1, 'time' => '09:00']],
            ['family' => 'every_n_months', 'params' => ['n' => 6, 'day' => 15, 'time' => '09:00']],
            ['family' => 'nth_weekday_of_month', 'params' => ['ordinal' => 1, 'weekday' => 1, 'time' => '09:00']],
            ['family' => 'nth_weekday_of_month', 'params' => ['ordinal' => 5, 'weekday' => 1, 'time' => '09:00']],
            ['family' => 'last_weekday_of_month', 'params' => ['weekday' => 5, 'time' => '09:00']],
            ['family' => 'last_working_day_of_month', 'params' => ['time' => '17:00']],
            // tz is accepted when a valid timezone.
            ['family' => 'daily', 'params' => ['time' => '09:00'], 'tz' => 'Europe/Warsaw'],
        ];

        foreach ($cases as $schedule) {
            $this->actingAs($user)
                ->postJson('/api/workflows', $this->schedulePayload($schedule))
                ->assertCreated();
        }
    }

    public function test_schedule_every_n_minutes_requires_n_within_bounds(): void
    {
        $user = User::factory()->create();

        // Missing n => rejected.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['family' => 'every_n_minutes', 'params' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.n']);

        // n out of range (60 > max 59) => rejected.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['family' => 'every_n_minutes', 'params' => ['n' => 60]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.n']);
    }

    public function test_schedule_every_n_hours_enforces_its_tighter_n_bound(): void
    {
        // n shares a name with every_n_minutes (widest 1..59) but every_n_hours is 2..12; the
        // exact per-family bound must reject n=1 even though it is valid for every_n_minutes.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['family' => 'every_n_hours', 'params' => ['n' => 1]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.n']);

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['family' => 'every_n_hours', 'params' => ['n' => 13]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.n']);
    }

    public function test_schedule_rejects_params_foreign_to_the_family(): void
    {
        // A key outside the submitted family's OWN descriptor set is a 422 — descriptor-driven
        // consumers (FE builder, AI assist) get explicit feedback, never a silent drop.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'daily',
                'params' => ['time' => '09:00', 'weekday' => 1],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.weekday']);
    }

    public function test_schedule_weekly_requires_time_and_weekdays(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['family' => 'weekly', 'params' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'trigger_config.schedule.params.time',
                'trigger_config.schedule.params.weekdays',
            ]);
    }

    public function test_schedule_weekly_rejects_a_legacy_scalar_weekday_on_write(): void
    {
        $user = User::factory()->create();

        // The compiler tolerates a legacy scalar `weekday` for READ, but a NEW write must use the
        // `weekdays` list — a scalar `weekday` is a foreign param and `weekdays` is required-missing.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'weekly',
                'params' => ['weekday' => 1, 'time' => '09:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'trigger_config.schedule.params.weekday',
                'trigger_config.schedule.params.weekdays',
            ]);
    }

    public function test_schedule_weekly_rejects_an_empty_weekdays_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'weekly',
                'params' => ['weekdays' => [], 'time' => '09:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.weekdays']);
    }

    public function test_schedule_weekly_rejects_duplicate_weekdays(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'weekly',
                'params' => ['weekdays' => [1, 1], 'time' => '09:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.weekdays.0']);
    }

    public function test_schedule_weekly_rejects_a_weekday_out_of_range(): void
    {
        $user = User::factory()->create();

        // Element 7 is outside 0..6 — the `.*` element bound rejects it.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'weekly',
                'params' => ['weekdays' => [1, 7], 'time' => '09:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.weekdays.1']);
    }

    public function test_schedule_new_families_enforce_their_bounds(): void
    {
        $user = User::factory()->create();

        // every_n_months n is 2..6 (widest n is now 1..59 across families; the exact per-family
        // bound must still reject n=1 and n=7).
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'every_n_months', 'params' => ['n' => 1, 'day' => 1, 'time' => '09:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.n']);

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'every_n_months', 'params' => ['n' => 7, 'day' => 1, 'time' => '09:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.n']);

        // nth_weekday_of_month ordinal is 1..5 — ordinal 6 is out of range.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'nth_weekday_of_month', 'params' => ['ordinal' => 6, 'weekday' => 1, 'time' => '09:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.ordinal']);

        // last_weekday_of_month weekday is 0..6 — weekday 7 is out of range.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'last_weekday_of_month', 'params' => ['weekday' => 7, 'time' => '09:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.weekday']);
    }

    public function test_schedule_new_families_require_their_params(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['family' => 'every_n_months', 'params' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'trigger_config.schedule.params.n',
                'trigger_config.schedule.params.day',
                'trigger_config.schedule.params.time',
            ]);

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['family' => 'nth_weekday_of_month', 'params' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'trigger_config.schedule.params.ordinal',
                'trigger_config.schedule.params.weekday',
                'trigger_config.schedule.params.time',
            ]);

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['family' => 'last_working_day_of_month', 'params' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.time']);
    }

    public function test_schedule_daily_requires_time(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['family' => 'daily', 'params' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.time']);
    }

    public function test_schedule_twice_daily_requires_first_before_second(): void
    {
        $user = User::factory()->create();

        // first_hour >= second_hour => rejected on the second_hour key.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'twice_daily',
                'params' => ['first_hour' => 17, 'second_hour' => 9],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.second_hour']);

        // Equal hours are also invalid.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'twice_daily',
                'params' => ['first_hour' => 9, 'second_hour' => 9],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.second_hour']);
    }

    public function test_schedule_twice_monthly_requires_first_before_second(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'twice_monthly',
                'params' => ['first_day' => 20, 'second_day' => 5, 'time' => '09:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.params.second_day']);
    }

    public function test_schedule_rejects_an_invalid_timezone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'daily',
                'params' => ['time' => '09:00'],
                'tz' => 'Mars/Phobos',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.tz']);
    }

    // ---- times[] (multiple fire times) ---------------------------------------

    public function test_schedule_accepts_a_times_list_on_a_wall_clock_family(): void
    {
        $user = User::factory()->create();

        // daily with two distinct fire times replaces the single params.time.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'daily',
                'params' => [],
                'times' => ['08:00', '17:00'],
            ]))
            ->assertCreated();
    }

    public function test_schedule_rejects_duplicate_times(): void
    {
        $user = User::factory()->create();

        // Duplicated fire times are a "duplicate executions" mistake — rejected by `distinct`.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'daily',
                'params' => [],
                'times' => ['08:00', '08:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.times.0']);
    }

    public function test_schedule_rejects_times_with_a_single_time_param(): void
    {
        $user = User::factory()->create();

        // times[] and params.time both present is a mistake — provide one or the other.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'daily',
                'params' => ['time' => '09:00'],
                'times' => ['08:00', '17:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.times']);
    }

    public function test_schedule_rejects_times_on_a_family_without_a_time_param(): void
    {
        $user = User::factory()->create();

        // hourly has no `time` param, so a times[] list is not allowed for it.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'hourly',
                'params' => [],
                'times' => ['08:00', '17:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.times']);
    }

    public function test_schedule_rejects_more_than_six_times(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'daily',
                'params' => [],
                'times' => ['00:00', '01:00', '02:00', '03:00', '04:00', '05:00', '06:00'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.times']);
    }

    // ---- exclusions ----------------------------------------------------------

    public function test_schedule_accepts_valid_exclusions(): void
    {
        $user = User::factory()->create();

        // A daily schedule that skips weekends, August, and a couple of concrete dates.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'daily',
                'params' => ['time' => '09:00'],
                'exclusions' => [
                    'weekdays' => [0, 6],
                    'months' => [8],
                    'dates' => ['2026-12-24', '2026-12-25'],
                ],
            ]))
            ->assertCreated();
    }

    public function test_schedule_rejects_eleven_plus_one_excluded_months(): void
    {
        $user = User::factory()->create();

        // 12 excluded months would leave the schedule with no month to fire — structurally capped at 11.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'daily',
                'params' => ['time' => '09:00'],
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
                'family' => 'daily',
                'params' => ['time' => '09:00'],
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
                'family' => 'daily',
                'params' => ['time' => '09:00'],
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
                'family' => 'daily',
                'params' => ['time' => '09:00'],
                'exclusions' => ['years' => [2026]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.exclusions.years']);
    }

    public function test_schedule_rejects_an_empty_schedule_with_no_occurrences(): void
    {
        $user = User::factory()->create();

        // weekly on Monday that also excludes Mondays can never fire — rejected up front on the
        // exclusions key rather than persisting a schedule with no occurrences.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload([
                'family' => 'weekly',
                'params' => ['weekdays' => [1], 'time' => '09:00'],
                'exclusions' => ['weekdays' => [1]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.exclusions']);
    }

    public function test_schedule_rejects_an_unknown_family(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', $this->schedulePayload(['family' => 'fortnightly', 'params' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule.family']);
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

    public function test_cross_type_trigger_config_is_rejected(): void
    {
        $user = User::factory()->create();

        // A schedule block on a form_submitted trigger is nonsense => rejected as foreign.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_type' => 'form_submitted',
                'trigger_config' => ['schedule' => ['family' => 'daily', 'params' => ['time' => '09:00']]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_config.schedule']);

        // A form_id on a schedule trigger is nonsense => rejected as foreign.
        $this->actingAs($user)
            ->postJson('/api/workflows', $this->validPayload([
                'trigger_type' => 'schedule',
                'trigger_config' => [
                    'schedule' => ['family' => 'daily', 'params' => ['time' => '09:00']],
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
                'trigger_config' => ['schedule' => ['family' => 'daily', 'params' => ['time' => '09:00']]],
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
                'trigger_config' => ['schedule' => ['family' => 'daily', 'params' => ['time' => '09:00']]],
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

    public function test_status_change_forbidden_for_non_creator(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        $this->actingAs($other)
            ->patchJson("/api/workflows/{$workflow->id}/status", ['status' => 'active'])
            ->assertForbidden();
    }

    public function test_status_endpoint_rejects_invalid_status(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->patchJson("/api/workflows/{$workflow->id}/status", ['status' => 'bogus'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_can_show_workflow(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->getJson("/api/workflows/{$workflow->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $workflow->id);
    }

    public function test_can_list_workflows(): void
    {
        $user = User::factory()->create();
        Workflow::factory()->count(3)->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->getJson('/api/workflows')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.is_owner', true);
    }

    public function test_index_is_cursor_paginated(): void
    {
        $user = User::factory()->create();
        Workflow::factory()->count(10)->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->getJson('/api/workflows')
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonStructure(['data', 'links', 'meta' => ['next_cursor']]);
    }

    public function test_can_search_workflows(): void
    {
        $user = User::factory()->create();
        Workflow::factory()->create(['creator_id' => $user->id, 'name' => 'Alpha flow']);
        Workflow::factory()->create(['creator_id' => $user->id, 'name' => 'Beta flow']);

        $this->actingAs($user)
            ->getJson('/api/workflows?search=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha flow');
    }

    public function test_can_filter_by_status(): void
    {
        $user = User::factory()->create();
        Workflow::factory()->active()->create(['creator_id' => $user->id, 'name' => 'Live']);
        Workflow::factory()->inactive()->create(['creator_id' => $user->id, 'name' => 'Off']);

        $this->actingAs($user)
            ->getJson('/api/workflows?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Live');
    }

    public function test_can_soft_delete_and_restore_workflow(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $user->id]);

        $this->actingAs($user)
            ->deleteJson("/api/workflows/{$workflow->id}")
            ->assertOk();

        $this->assertSoftDeleted('workflows', ['id' => $workflow->id]);

        $this->actingAs($user)
            ->postJson("/api/workflows/{$workflow->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $workflow->id);

        $this->assertDatabaseHas('workflows', ['id' => $workflow->id, 'deleted_at' => null]);
    }

    public function test_cannot_delete_another_users_workflow(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);

        $this->actingAs($other)
            ->deleteJson("/api/workflows/{$workflow->id}")
            ->assertForbidden();
    }

    public function test_cannot_restore_another_users_workflow(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $workflow = Workflow::factory()->create(['creator_id' => $owner->id]);
        $workflow->delete();

        $this->actingAs($other)
            ->postJson("/api/workflows/{$workflow->id}/restore")
            ->assertForbidden();

        $this->assertSoftDeleted('workflows', ['id' => $workflow->id]);
    }

    public function test_workflows_are_isolated_by_active_workspace(): void
    {
        $user = User::factory()->create();
        $workspaceA = $this->workspaceFor($user);
        $workspaceB = $this->workspaceFor($user);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->postJson('/api/workflows', $this->validPayload(['name' => 'Flow A']))
            ->assertCreated();

        $this->assertDatabaseHas('workflows', ['name' => 'Flow A', 'workspace_id' => $workspaceA->id]);

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceA->id)
            ->getJson('/api/workflows')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Flow A');

        $this->actingAs($user)->withHeader('X-Workspace-Id', $workspaceB->id)
            ->getJson('/api/workflows')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_non_member_cannot_access_workspace_workflows(): void
    {
        $owner = User::factory()->create();
        $foreignWorkspace = Workspace::factory()->create(['owner_id' => $owner->id]);

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->withHeader('X-Workspace-Id', $foreignWorkspace->id)
            ->getJson('/api/workflows')
            ->assertForbidden();
    }

    public function test_guest_cannot_access_workflows(): void
    {
        $this->getJson('/api/workflows')->assertUnauthorized();
    }
}
