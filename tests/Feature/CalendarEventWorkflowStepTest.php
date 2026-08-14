<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Workflows\Enums\WorkflowRunState;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Services\WorkflowStepRunner;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R3 B4 — the `create_event` workflow step.
 *
 * Two things here are pinned rather than trusted, and both for the same reason: they work by
 * themselves, so they would stop working without anyone noticing.
 *
 *  1. CREATOR ATTRIBUTION. Nothing in the step sets a creator; HasCreator stamps the executing run
 *     because WorkflowStepRunner publishes it to WorkflowRunContext around the step loop. Automatic
 *     machinery is exactly the machinery that breaks silently.
 *  2. THE ALL-DAY DISCRIMINATOR SURVIVING THE ENGINE. The step resolves its date through the variable
 *     resolver, which coerces every DATE field to a full ISO instant — so an all-day event's day has to
 *     be re-printed from that, and a regression here would write instants into an all-day row.
 */
class CalendarEventWorkflowStepTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Named, never inherited. The suite reads the developer's `.env`, and every assertion below is
        // about WHOSE CLOCK a zone-less string is on — a test of that must not take its baseline from
        // whatever somebody last switched on by hand.
        config(['app.timezone' => 'UTC']);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id);

        app(TenantContext::class)->set($this->workspace);
    }

    /** Move the active workspace onto a zone with a real, non-zero offset. */
    private function workspaceTimezone(string $timezone): void
    {
        $this->workspace->forceFill(['timezone' => $timezone])->save();

        app(TenantContext::class)->set($this->workspace->refresh());
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $trigger  the run's trigger payload, reachable as `trigger.*`
     */
    private function runWorkflowWith(array $config, array $trigger = []): WorkflowRun
    {
        $workflow = Workflow::factory()->create([
            'creator_id' => $this->owner->id,
            'steps' => [
                ['type' => WorkflowStepType::CREATE_EVENT->value, 'key' => 'make_event', 'config' => $config],
            ],
        ]);

        $run = WorkflowRun::factory()->create([
            'workflow_id' => $workflow->id,
            'state' => WorkflowRunState::RUNNING,
            'context' => [],
            'trigger_payload' => $trigger,
        ]);

        app(WorkflowStepRunner::class)->run($run);

        return $run->refresh();
    }

    /**
     * The shape of a timed write, on a workspace with a REAL offset.
     *
     * The zone is not decoration here. This assertion used to run on a UTC workspace against literals
     * that named no zone, so `14:00` stored `14:00Z` and was green under BOTH readings of the string —
     * the workspace's clock and `config('app.timezone')`. It could not have failed if the step read the
     * wrong one, and the step did: `Carbon::parse()` with no zone reads the app default. On Europe/Warsaw
     * the two readings are two hours apart, so the test now has an opinion.
     *
     * The opinion is the module's: a zone-less wall time is the WORKSPACE's wall time, because that is
     * the clock the grid will draw it on ({@see \App\Modules\Calendar\Services\CalendarInstantResolver}).
     */
    public function test_a_run_creates_a_timed_event_and_publishes_its_outputs(): void
    {
        $this->workspaceTimezone('Europe/Warsaw');

        $run = $this->runWorkflowWith([
            'title' => 'Sprint review',
            'all_day' => false,
            'starts_at' => ['kind' => 'literal', 'value' => '2026-08-10 14:00:00'],
            'ends_at' => ['kind' => 'literal', 'value' => '2026-08-10 15:00:00'],
        ]);

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state, $run->error ?? '');

        $event = CalendarEvent::query()->firstOrFail();

        $this->assertSame('Sprint review', $event->title);
        $this->assertFalse($event->all_day);
        $this->assertNull($event->start_date);

        // 14:00 in Warsaw (CEST, +02:00) is 12:00Z — NOT 14:00Z, which is what reading the bare string in
        // the app timezone would have stored.
        $this->assertSame('2026-08-10 12:00:00', $event->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-10 13:00:00', $event->ends_at->utc()->format('Y-m-d H:i:s'));

        // The step's declared outputs, in the context under the step's own key, so a later step can
        // reference {{steps.make_event.event_id}}.
        $this->assertSame($event->id, data_get($run->context, 'steps.make_event.event_id'));
        $this->assertSame('Sprint review', data_get($run->context, 'steps.make_event.title'));
    }

    /**
     * THE INVARIANT THIS WHOLE GROUP EXISTS FOR: the step and the HTTP endpoint are two doors into the
     * same table, and the same text put through either must name the same MOMENT.
     *
     * It was not asserted anywhere before, and the two had drifted: the request layer had already been
     * hardened to read a zone-less instant on the workspace's clock, while the step still read it on
     * `config('app.timezone')`. Every existing step test ran on a UTC workspace, where the two readings
     * coincide, so nothing went red.
     *
     * Note what is NOT asserted: a particular UTC value. Pinning the equality rather than the number is
     * the point — a future change that moves both doors together is fine, and one that moves only one is
     * the defect.
     */
    public function test_the_step_and_the_http_endpoint_store_the_same_instant_for_the_same_text(): void
    {
        $this->workspaceTimezone('Europe/Warsaw');

        $wallTime = '2026-08-10 14:00:00';

        $this->runWorkflowWith([
            'title' => 'Through the step',
            'all_day' => false,
            'starts_at' => ['kind' => 'literal', 'value' => $wallTime],
        ]);

        $this->actingAs($this->owner)
            ->withHeader('X-Workspace-Id', $this->workspace->id)
            ->postJson('/api/calendar/events', [
                'title' => 'Through HTTP',
                'all_day' => false,
                'starts_at' => $wallTime,
            ])
            ->assertCreated();

        $byStep = CalendarEvent::query()->where('title', 'Through the step')->firstOrFail();
        $byHttp = CalendarEvent::query()->where('title', 'Through HTTP')->firstOrFail();

        $this->assertTrue(
            $byStep->starts_at->equalTo($byHttp->starts_at),
            'the create_event step and POST /calendar/events stored DIFFERENT instants for the same wall '
            . 'time: step ' . $byStep->starts_at->utc()->toIso8601String()
            . ' vs http ' . $byHttp->starts_at->utc()->toIso8601String()
        );
    }

    /**
     * The other half of the one rule: a value that NAMES its zone is taken exactly as given, and the
     * workspace's clock gets no say. An explicit offset is speech, not silence.
     */
    public function test_a_literal_that_names_its_own_zone_is_taken_as_given(): void
    {
        $this->workspaceTimezone('Europe/Warsaw');

        $run = $this->runWorkflowWith([
            'title' => 'Explicit',
            'all_day' => false,
            'starts_at' => ['kind' => 'literal', 'value' => '2026-08-10T14:00:00Z'],
        ]);

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state, $run->error ?? '');

        $this->assertSame(
            '2026-08-10 14:00:00',
            CalendarEvent::query()->firstOrFail()->starts_at->utc()->format('Y-m-d H:i:s')
        );
    }

    /**
     * The resolver coerces a DATE field to a full ISO instant even when the author wrote a bare day, so
     * this is where an all-day event could quietly acquire a time. The day must round-trip untouched.
     */
    public function test_a_run_creates_an_all_day_event_without_acquiring_a_time(): void
    {
        $run = $this->runWorkflowWith([
            'title' => 'Campaign launch',
            'all_day' => true,
            'start_date' => ['kind' => 'literal', 'value' => '2026-08-12'],
        ]);

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state, $run->error ?? '');

        $event = CalendarEvent::query()->firstOrFail();

        $this->assertTrue($event->all_day);
        $this->assertSame('2026-08-12', $event->startDateString());
        $this->assertNull($event->starts_at);
        $this->assertNull($event->ends_at);
    }

    /**
     * A bare day an author typed must round-trip on ANY workspace, including one WEST of UTC.
     *
     * This is the trap in deriving the day in the workspace's zone: the variable resolver coerces a bare
     * `2026-08-12` to `2026-08-12T00:00:00+00:00`, and converting THAT to New York gives the 11th. What
     * saves it is that the rule is applied to the text the author actually wrote — which named no zone,
     * so it is read as midnight in the workspace's own clock and prints as the day it says.
     */
    public function test_a_bare_day_round_trips_on_a_workspace_west_of_utc(): void
    {
        $this->workspaceTimezone('America/New_York');

        $this->runWorkflowWith([
            'title' => 'Campaign launch',
            'all_day' => true,
            'start_date' => ['kind' => 'literal', 'value' => '2026-08-12'],
        ]);

        $this->assertSame('2026-08-12', CalendarEvent::query()->firstOrFail()->startDateString());
    }

    /**
     * An all-day date fed from a run-time variable that carries a REAL instant lands on the day the
     * WORKSPACE is on at that moment — the same square the grid would draw the instant itself on.
     *
     * The docblock on `requireDay()` used to claim there was "no third answer" available. There is, and
     * the rest of the module already uses it: 23:30Z is the 13th in Warsaw, so an event derived from it
     * that printed the 12th sat one square behind everything else the same instant produced.
     */
    public function test_an_all_day_date_from_an_instant_lands_on_the_workspaces_day(): void
    {
        $this->workspaceTimezone('Europe/Warsaw');

        $run = $this->runWorkflowWith(
            [
                'title' => 'Late night launch',
                'all_day' => true,
                'start_date' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'trigger.when', 'type' => 'date']],
            ],
            ['when' => '2026-08-12T23:30:00Z'],
        );

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state, $run->error ?? '');
        $this->assertSame('2026-08-13', CalendarEvent::query()->firstOrFail()->startDateString());
    }

    /**
     * The attribution that works by itself. `creator_type` is the run's morph alias and `creator_id` is
     * the run — a SYSTEM record owned by nobody (ADR-0015), never the person who triggered the run.
     */
    public function test_an_event_created_by_a_run_is_attributed_to_that_run(): void
    {
        $run = $this->runWorkflowWith([
            'title' => 'Automated milestone',
            'all_day' => true,
            'start_date' => ['kind' => 'literal', 'value' => '2026-08-12'],
        ]);

        $event = CalendarEvent::query()->firstOrFail();

        $this->assertSame('workflow_run', $event->creator_type);
        $this->assertSame($run->id, $event->creator_id);

        // Owned by nobody: the human who triggered the run did not author the content.
        $this->assertNull($event->creatorUser());
        $this->assertFalse($event->isOwnedBy($this->owner));
    }

    /**
     * A title is the one field create_task hard-fails on, and an event adds a second: without a
     * resolvable date it has no square to sit on, so writing the row anyway would put an event in the
     * table that the read path can never select.
     */
    public function test_a_step_without_a_resolvable_date_fails_the_run_and_writes_nothing(): void
    {
        $run = $this->runWorkflowWith([
            'title' => 'Nowhere in time',
            'all_day' => false,
            'starts_at' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'trigger.missing', 'type' => 'date']],
        ]);

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(0, CalendarEvent::query()->count());
    }

    public function test_a_blank_title_fails_the_run(): void
    {
        $run = $this->runWorkflowWith([
            'title' => '   ',
            'all_day' => true,
            'start_date' => ['kind' => 'literal', 'value' => '2026-08-12'],
        ]);

        $this->assertSame(WorkflowRunState::FAILED, $run->state);
        $this->assertSame(0, CalendarEvent::query()->count());
    }

    /**
     * An end BEFORE the start is something only a run-time variable can produce, and it is soft: the
     * event keeps its start and loses only the part that made no sense. Failing the whole run over a
     * decoration would be a worse trade.
     */
    public function test_an_end_before_the_start_is_dropped_rather_than_failing_the_run(): void
    {
        $run = $this->runWorkflowWith([
            'title' => 'Backwards',
            'all_day' => false,
            'starts_at' => ['kind' => 'literal', 'value' => '2026-08-10 14:00:00'],
            'ends_at' => ['kind' => 'literal', 'value' => '2026-08-10 09:00:00'],
        ]);

        $this->assertSame(WorkflowRunState::COMPLETED, $run->state, $run->error ?? '');

        $event = CalendarEvent::query()->firstOrFail();

        $this->assertNotNull($event->starts_at);
        $this->assertNull($event->ends_at);
    }

    // ---- write-time validation of the definition -------------------------------

    /** @param array<string, mixed> $config */
    private function storeWorkflowWith(array $config)
    {
        return $this->actingAs($this->owner)
            ->withHeader('X-Workspace-Id', $this->workspace->id)
            ->postJson('/api/workflows', [
                'name' => 'Calendar automation',
                'trigger_type' => 'schedule',
                'trigger_config' => ['schedule' => ['time' => ['mode' => 'at', 'at' => ['09:00']]]],
                'steps' => [
                    ['type' => WorkflowStepType::CREATE_EVENT->value, 'key' => 'make_event', 'config' => $config],
                ],
            ]);
    }

    public function test_a_definition_can_be_saved_with_a_create_event_step(): void
    {
        $this->storeWorkflowWith([
            'title' => 'Weekly sync',
            'all_day' => false,
            'starts_at' => ['kind' => 'literal', 'value' => '2026-08-10 09:00:00'],
        ])->assertCreated();
    }

    /**
     * The forbidding half of the discriminator, at AUTHORING time — the only moment a person is still
     * looking at the mistake. A config carrying both shapes would otherwise save, and then silently
     * discard one of them on every single run.
     */
    public function test_a_definition_mixing_both_time_shapes_is_refused(): void
    {
        $this->storeWorkflowWith([
            'title' => 'Confused',
            'all_day' => true,
            'start_date' => ['kind' => 'literal', 'value' => '2026-08-12'],
            'starts_at' => ['kind' => 'literal', 'value' => '2026-08-12 09:00:00'],
        ])->assertStatus(422)->assertJsonValidationErrors('steps.0.config.starts_at');

        $this->storeWorkflowWith([
            'title' => 'Confused the other way',
            'all_day' => false,
            'start_date' => ['kind' => 'literal', 'value' => '2026-08-12'],
            'starts_at' => ['kind' => 'literal', 'value' => '2026-08-12 09:00:00'],
        ])->assertStatus(422)->assertJsonValidationErrors('steps.0.config.start_date');
    }

    public function test_a_definition_missing_the_date_its_shape_needs_is_refused(): void
    {
        $this->storeWorkflowWith(['title' => 'No day', 'all_day' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('steps.0.config.start_date');

        $this->storeWorkflowWith(['title' => 'No time', 'all_day' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('steps.0.config.starts_at');
    }

    /**
     * `all_day` decides which OTHER fields are required, so it cannot be a run-time value: a definition
     * would pass validation and still reach a branch with no date in it.
     */
    public function test_all_day_must_be_a_literal(): void
    {
        $this->storeWorkflowWith([
            'title' => 'Variable discriminator',
            'all_day' => ['kind' => 'variable', 'ref' => ['source' => 'trigger', 'path' => 'trigger.flag', 'type' => 'boolean']],
            'start_date' => ['kind' => 'literal', 'value' => '2026-08-12'],
        ])->assertStatus(422)->assertJsonValidationErrors('steps.0.config.all_day');
    }

    public function test_a_foreign_config_key_is_refused_on_a_create_event_step(): void
    {
        // The pointer is deliberately NOT part of the step's vocabulary — a run-created event is a
        // standalone annotation. A key outside the allow-list is a 422, exactly as on every other type.
        $this->storeWorkflowWith([
            'title' => 'With a subject',
            'all_day' => true,
            'start_date' => ['kind' => 'literal', 'value' => '2026-08-12'],
            'subject_type' => 'task',
        ])->assertStatus(422)->assertJsonValidationErrors('steps.0.config.subject_type');
    }

    /**
     * A colour is not part of this step's vocabulary at all — and the value used here is a valid
     * CalendarColor, deliberately, because the refusal is not about which colours exist. An event has no
     * meaning to colour by, so the grid gives every event the same constant; a step allowed to name one
     * could paint a run-created event red beside a genuinely failed run.
     */
    public function test_a_colour_is_not_part_of_the_step_vocabulary(): void
    {
        $this->storeWorkflowWith([
            'title' => 'Coloured',
            'all_day' => true,
            'start_date' => ['kind' => 'literal', 'value' => '2026-08-12'],
            'color' => 'info',
        ])->assertStatus(422)->assertJsonValidationErrors('steps.0.config.color');
    }
}
