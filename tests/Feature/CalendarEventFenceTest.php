<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Workflows\Enums\WorkflowStatus;
use App\Modules\Workflows\Enums\WorkflowStepType;
use App\Modules\Workflows\Enums\WorkflowTriggerType;
use App\Modules\Workflows\Models\Workflow;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workflows\Steps\CreateEventStep;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * THE FENCE, EXECUTED.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT THIS FILE IS FOR — read this before deleting anything in it
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * ADR-0051 D4 states the single most important rule of the Calendar module:
 *
 *     A CALENDAR EVENT IS AN ANNOTATION ON A TIMELINE. NOTHING EVER EXECUTES BECAUSE ONE EXISTS.
 *     No trigger reads `calendar_events`, no sweep scans it, no job wakes on it.
 *
 * Until this file existed, that rule was held by exactly two things: a paragraph in the ADR and a
 * paragraph in {@see CalendarEvent}'s docblock. The ADR admits it in its own Consequences section —
 * "enforced today by the model's own docblock and code review discipline, **not by an automated
 * guard**… a future author under deadline pressure could wire a trigger to the table without any test
 * failing."
 *
 * WHY THE RULE MATTERS, so that the next person to hit this test knows what they are arguing with
 * rather than merely how to make it green. The product already HAS a scheduler: the Workflows module's
 * `trigger_config` + cadence compiler, built across Etap 5.1 and R2, with its own timezone story, its
 * own claim/CAS semantics, its own slot-consumed doctrine and its own account of why a thing did not
 * run. The moment ANYTHING fires off a `calendar_events` row, the Calendar becomes a second scheduler
 * competing with the first — half a cadence vocabulary, half a timezone model, and two places to look
 * when something did not happen. The word "event" attracts precisely that reading; R4 Publishing will
 * be tempted to model "publish at 09:00" as "an event that fires", and it must not. A scheduled
 * publish is a TRIGGER (Workflows' domain); a calendar event about it is, at most, an annotation a
 * human or a step also wrote down.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS FORBIDDEN AND WHAT IS EXPLICITLY ALLOWED
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * FORBIDDEN: READING the events table from anywhere outside the Calendar module. That is what a
 * trigger would have to do, and there are only three ways to spell it — the table name, the model, or
 * the module's own read source. All three are refused by {@see test_nothing_outside_the_calendar_reads_the_events_table}.
 *
 * ALLOWED, and deliberately not caught: WRITING one. The `create_event` workflow step
 * ({@see CreateEventStep}) puts events on the grid through `CalendarEventDTO` + `CalendarEventService`,
 * and that direction is the correct one — Workflows names the Calendar, the Calendar names nobody. So
 * those two class names are NOT on the forbidden list, and {@see test_the_write_direction_is_still_allowed}
 * asserts the step still uses them, so nobody can "fix" this file by fencing off the legal write too.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * SHAPE OF THE GUARD — four independent doors, because there are four ways in
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *   1. STRUCTURAL   nothing outside the Calendar names the table / model / read source (byte scan).
 *   2. VOCABULARY   the trigger vocabulary is closed; "when an event exists" has no word for itself.
 *   3. BEHAVIOURAL  the real schedule sweep runs and never touches the table (SQL is captured).
 *   4. BEHAVIOURAL  writing an event dispatches nothing and starts nothing.
 *
 * Every scan here carries an ANTI-VACUITY assertion. A guard that silently scans nothing passes
 * forever and is worse than no guard at all: it converts an unprotected invariant into one everybody
 * believes is protected.
 */
class CalendarEventFenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The FOUR ways to READ the events table. Each is a full, unambiguous token — `CalendarEvent`
     * alone would also match `CalendarEventDTO` and `CalendarEventService`, which are the WRITE seam
     * and must stay reachable (see the class docblock).
     *
     * Matching is TOKEN-WISE ({@see names}), not plain substring, and that is what lets the alias and the
     * table name coexist: `calendar_event` is a prefix of `calendar_events`, so a substring scan would
     * make every mention of the table also a mention of the alias and force a carve-out that grants more
     * than it means.
     */
    private const READ_NEEDLES = [
        // The table itself: a raw query, a DB::table(), a migration-style read.
        'calendar_events',
        // The Eloquent model, namespace-qualified.
        'Calendar\\Models\\CalendarEvent',
        // The Calendar's own read source. Calling it from outside would be reading the table by proxy.
        'EventCalendarSource',
        // THE MORPH ALIAS. The shortest door of the four, and the one the first three left wide open:
        // `Relation::getMorphedModel('calendar_event')` hands back the model class without the file ever
        // naming the model, the table or the source — a reviewer's probe walked straight through and the
        // whole fence stayed green. The alias is registered in CalendarModuleServiceProvider, which needs
        // no carve-out because the scan skips the Calendar module wholesale.
        'calendar_event',
    ];

    /**
     * Directories that hold everything capable of firing: modules, console commands, jobs, listeners,
     * middleware, routes (a scheduled closure in `routes/console.php` is a trigger), and the bootstrap
     * where the schedule is registered.
     */
    private const SCANNED_ROOTS = ['app', 'routes', 'bootstrap'];

    /**
     * The narrow, reasoned carve-outs — per FILE and per NEEDLE, never per file alone.
     *
     * Allowing a whole file would mean a future `CalendarEvent::where(...)` inside an already-listed
     * file sails through; pinning the exact needle means each new way of naming the table has to be
     * argued for here, in writing, by whoever adds it.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED = [
        // A docblock CROSS-REFERENCE to the fence itself, pointing the reader at the model that states
        // it. Nothing executable. Allowed because a rule is best documented where it is most likely to
        // be broken — but only the model reference, never the table name.
        'app/modules/Workflows/Enums/WorkflowStepType.php' => ['Calendar\\Models\\CalendarEvent'],
        // The legal WRITER. It names the table only to state the width it clamps a title to; it holds
        // no query. Reading events HERE would still be harmless (a step is not a trigger — it runs
        // because a run reached it, never because a row exists), which is why the carve-out is safe as
        // well as narrow.
        'app/modules/Workflows/Steps/CreateEventStep.php' => ['calendar_events'],
    ];

    // ── 1. STRUCTURAL ────────────────────────────────────────────────────────────

    /**
     * No file outside `app/modules/Calendar` may name the events TABLE, the events MODEL, or the
     * Calendar's own READ SOURCE.
     *
     * The scan is LITERAL over the file bytes — the same instrument, and the same reasoning, as
     * {@see CalendarModuleBoundaryTest}: a comment or a docblock naming the model is exactly how a real
     * import begins, and it is the form a reviewer is most likely to wave through.
     *
     * IF YOU ARE HERE BECAUSE THIS TEST FAILED: the question is not "how do I add my file to the
     * allowlist". It is "does my code cause something to HAPPEN because a calendar row exists?" If yes,
     * what you are building is a workflow TRIGGER and belongs in `workflows.trigger_config` — the
     * Calendar can then show it through `workflow_schedule`, which is a first-class source. If no (you
     * genuinely only need to display or write events), say which needle and why, in this file, above
     * your entry.
     */
    public function test_nothing_outside_the_calendar_reads_the_events_table(): void
    {
        $calendarModule = $this->normalize(app_path('modules/Calendar'));

        $scanned = 0;
        $violations = [];

        foreach (self::SCANNED_ROOTS as $root) {
            $path = base_path($root);
            $this->assertDirectoryExists($path);

            /** @var iterable<\SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $absolute = $this->normalize($file->getPathname());

                // The Calendar owns the table; naming it there is the module doing its job.
                if (str_starts_with($absolute, $calendarModule . '/')) {
                    continue;
                }

                $scanned++;
                $relative = $this->relative($absolute);
                $source = (string) file_get_contents($file->getPathname());

                foreach (self::READ_NEEDLES as $needle) {
                    if (!$this->names($source, $needle)) {
                        continue;
                    }

                    if (in_array($needle, self::ALLOWED[$relative] ?? [], true)) {
                        continue;
                    }

                    $violations[] = $relative . ' names [' . $needle . ']';
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Nothing outside app/modules/Calendar may read calendar events (ADR-0051 D4: a calendar event is an '
            . 'annotation; NOTHING executes because one exists). If this is a trigger, it belongs in '
            . "workflows.trigger_config, not here. Offenders:\n  - " . implode("\n  - ", $violations)
        );

        // ANTI-VACUITY. A moved directory, a renamed root or a broken iterator would otherwise leave
        // this test scanning nothing and passing forever — the exact failure mode that makes a guard
        // worse than its absence. The floor is deliberately far below the real count (~600 files).
        $this->assertGreaterThan(
            200,
            $scanned,
            'the fence scan examined almost nothing — it is not looking where the code is'
        );
    }

    /**
     * The allowlist is not allowed to ROT. Every carve-out must still be a real file that still
     * contains the needle it was granted.
     *
     * Without this, deleting or renaming `CreateEventStep` leaves a permanent, invisible hole: the next
     * file to take that path inherits an exemption nobody granted it.
     */
    public function test_every_carve_out_is_still_real(): void
    {
        foreach (self::ALLOWED as $relative => $needles) {
            $path = base_path($relative);

            $this->assertFileExists(
                $path,
                "the fence allowlist names {$relative}, which no longer exists — remove the entry rather "
                . 'than leaving an exemption for whatever file takes that path next'
            );

            $source = (string) file_get_contents($path);

            foreach ($needles as $needle) {
                $this->assertTrue(
                    $this->names($source, $needle),
                    "{$relative} no longer names [{$needle}] — the carve-out is stale and must be deleted, "
                    . 'not kept "just in case"'
                );
            }
        }
    }

    /**
     * Whether a file NAMES a needle, as a whole token rather than as a run of characters.
     *
     * Two things are going on, and both are answers to real bypasses:
     *
     *   ESCAPING     a namespace inside a double-quoted string is written with DOUBLED backslashes, so
     *                the bytes spell `Calendar\\Models\\CalendarEvent` and a single-backslash needle
     *                never matched. Runs of backslashes are collapsed first, which makes the check
     *                stable under any depth of escaping.
     *   BOUNDARIES   `calendar_event` (the morph alias) is a prefix of `calendar_events` (the table). A
     *                substring test would report every mention of the table as a mention of the alias,
     *                and the fix for THAT would be a carve-out granting CreateEventStep the alias it
     *                does not use — an exemption wider than the fact it documents. Requiring a
     *                non-identifier character on both sides keeps each needle meaning itself.
     */
    private function names(string $source, string $needle): bool
    {
        $collapsed = (string) preg_replace('/\\\\+/', '\\', $source);

        return preg_match(
            '/(?<![A-Za-z0-9_])' . preg_quote($needle, '/') . '(?![A-Za-z0-9_])/',
            $collapsed,
        ) === 1;
    }

    // ── 2. VOCABULARY ────────────────────────────────────────────────────────────

    /**
     * "A workflow that runs when a calendar event happens" has no WORD for itself, and that is the
     * point: `trigger_type` is validated against {@see WorkflowTriggerType}, so the shortest path to
     * building one is adding a case to that enum — which lands here.
     *
     * This is the door the structural scan cannot cover on its own. An author could add
     * `case CALENDAR_EVENT = 'calendar_event';` and wire the reading side inside the Calendar module
     * (where the byte scan does not look), and every other test in the suite would stay green.
     *
     * IF YOU ARE HERE HAVING ADDED A THIRD TRIGGER: fine — triggers are allowed to grow. Update the
     * list. But if the case you added is about calendar EVENTS, read the class docblock first: the
     * answer is a schedule-triggered workflow with a `create_event` step, not a new trigger.
     */
    public function test_the_trigger_vocabulary_has_no_word_for_a_calendar_event(): void
    {
        $ids = WorkflowTriggerType::ids();
        sort($ids);

        $this->assertSame(['form_submitted', 'schedule'], $ids);

        foreach ($ids as $id) {
            $this->assertStringNotContainsString(
                'calendar',
                $id,
                'a trigger type must never be about calendar events (ADR-0051 D4)'
            );
            $this->assertStringNotContainsString('event', $id);
        }
    }

    /**
     * The enum is the real gate, not documentation: the write API refuses a trigger type it does not
     * know. Asserted because the previous test is only as strong as the enum actually being consulted.
     */
    public function test_the_write_api_refuses_a_calendar_trigger_type(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/workflows', [
                'name' => 'Fires when an event exists',
                'trigger_type' => 'calendar_event',
                'trigger_config' => [],
                'conditions' => [],
                'steps' => [
                    ['type' => 'create_task', 'key' => 'task', 'config' => ['title' => 'Nope']],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('trigger_type');
    }

    /**
     * The other half of the fence, so nobody closes it too far: `create_event` is a STEP, it WRITES,
     * and it must keep working. A guard that made the legal direction fail would be removed within a
     * week — and the fence would go with it.
     */
    public function test_the_write_direction_is_still_allowed(): void
    {
        $this->assertContains(WorkflowStepType::CREATE_EVENT, WorkflowStepType::cases());

        $source = (string) file_get_contents((string) (new ReflectionClass(CreateEventStep::class))->getFileName());

        $this->assertStringContainsString('Calendar\\DTOs\\CalendarEventDTO', $source);
        $this->assertStringContainsString('Calendar\\Services\\CalendarEventService', $source);
    }

    // ── 3. BEHAVIOURAL: the sweep ────────────────────────────────────────────────

    /**
     * THE REAL SCHEDULE SWEEP, RUN, WITH EVERY QUERY IT ISSUES CAPTURED.
     *
     * This is the assertion that does not care where the code lives. A trigger reading the table
     * through a service, a facade, a raw string built at run time, or a class the byte scan has never
     * heard of still has to issue SQL against `calendar_events` — and this sees it.
     *
     * The workspace is deliberately FULL of events on exactly the day the sweep runs: an all-day one,
     * a timed one, and one in the past. If anything ever grows an interest in them, this is the run in
     * which it would show.
     */
    public function test_the_schedule_sweep_never_queries_the_events_table(): void
    {
        [$owner] = $this->workspace();

        // Built through the factory's SHAPE states, never by setting columns — the same discipline the
        // DTO enforces on production writes.
        CalendarEvent::factory()->allDay(now()->toDateString())->create(['creator_id' => $owner->id]);
        CalendarEvent::factory()
            ->timed(now()->subMinutes(5)->toDateTimeString(), now()->addMinutes(25)->toDateTimeString())
            ->create(['creator_id' => $owner->id]);
        CalendarEvent::factory()
            ->timed(now()->subDays(3)->toDateTimeString())
            ->create(['creator_id' => $owner->id]);

        $this->assertSame(3, CalendarEvent::query()->count(), 'the fixtures must exist, or this run proves nothing');

        // A genuinely due schedule workflow, so the sweep does its whole job rather than returning
        // early on an empty candidate set. QUEUE_CONNECTION=sync drives the run to completion in
        // process, so the STEP LOOP's queries are captured here too.
        $workflow = Workflow::factory()->create([
            'creator_id' => $owner->id,
            'status' => WorkflowStatus::ACTIVE,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'at', 'at' => ['09:00']]]],
            'next_due_at' => now()->subMinute(),
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'task', 'config' => ['title' => 'Swept']],
            ],
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->artisan('workflows:run-scheduled')->assertSuccessful();

        // ANTI-VACUITY, both halves. A listener that captured nothing, or a sweep that fired nothing,
        // would make the real assertion below vacuously true.
        $this->assertNotEmpty($queries, 'no SQL was captured — the listener never ran, so this proves nothing');
        $this->assertSame(
            1,
            WorkflowRun::query()->where('workflow_id', $workflow->id)->count(),
            'the sweep must actually have fired a run, or it never reached the code this test is about'
        );

        $touched = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'calendar_events'),
        ));

        $this->assertSame(
            [],
            $touched,
            'The schedule sweep read `calendar_events`. Nothing may execute because a calendar event exists '
            . "(ADR-0051 D4). Offending SQL:\n  - " . implode("\n  - ", $touched)
        );
    }

    // ── 4. BEHAVIOURAL: the write ────────────────────────────────────────────────

    /**
     * Creating an event through the API dispatches NOTHING. No job, no run, no queued anything.
     *
     * The failure this catches is not a query — it is a listener or an observer bolted onto the model,
     * which is the second-easiest way to make a calendar row execute something and leaves no trace in
     * either the trigger enum or the sweep's SQL.
     */
    public function test_writing_an_event_dispatches_nothing_and_starts_nothing(): void
    {
        [$owner, $workspace] = $this->workspace();

        Bus::fake();

        foreach ([
            ['title' => 'Timed', 'all_day' => false, 'starts_at' => '2026-08-10T09:00:00Z'],
            ['title' => 'All day', 'all_day' => true, 'start_date' => '2026-08-12'],
        ] as $payload) {
            $this->actingAs($owner)
                ->withHeader('X-Workspace-Id', $workspace->id)
                ->postJson('/api/calendar/events', $payload)
                ->assertCreated();
        }

        // ANTI-VACUITY: the writes really happened.
        $this->assertSame(2, CalendarEvent::query()->count());

        Bus::assertNothingDispatched();

        $this->assertSame(
            0,
            WorkflowRun::query()->count(),
            'no run may exist because an event was written (ADR-0051 D4)'
        );
    }

    /**
     * And the sweep run AFTER those writes still fires nothing: the events are on today's grid, no
     * workflow is due, and the correct number of runs is zero. The mirror image of the sweep test —
     * there the events were ignored while something else fired; here nothing fires at all.
     */
    public function test_events_on_todays_grid_do_not_make_the_sweep_fire(): void
    {
        [$owner] = $this->workspace();

        CalendarEvent::factory()
            ->count(3)
            ->allDay(now()->toDateString())
            ->create(['creator_id' => $owner->id]);

        // An ACTIVE schedule workflow that is NOT due. If an event ever became a reason to fire, this
        // is the workflow it would fire, and the count below would be 1.
        Workflow::factory()->create([
            'creator_id' => $owner->id,
            'status' => WorkflowStatus::ACTIVE,
            'trigger_type' => WorkflowTriggerType::SCHEDULE->value,
            'trigger_config' => ['schedule' => ['time' => ['mode' => 'at', 'at' => ['09:00']]]],
            'next_due_at' => now()->addWeek(),
            'steps' => [
                ['type' => WorkflowStepType::CREATE_TASK->value, 'key' => 'task', 'config' => ['title' => 'Never']],
            ],
        ]);

        $this->assertSame(3, CalendarEvent::query()->count());

        $this->artisan('workflows:run-scheduled')->assertSuccessful();

        $this->assertSame(0, WorkflowRun::query()->count());
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: Workspace} */
    private function workspace(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->users()->attach($owner->id);

        app(TenantContext::class)->set($workspace);

        return [$owner, $workspace];
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function relative(string $absolutePath): string
    {
        $root = $this->normalize(base_path()) . '/';

        return str_starts_with($absolutePath, $root)
            ? substr($absolutePath, strlen($root))
            : $absolutePath;
    }
}
