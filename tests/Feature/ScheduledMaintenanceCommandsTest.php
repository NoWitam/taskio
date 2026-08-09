<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * EVERY housekeeping command this application ships is on the scheduler.
 *
 * Written because one was not. `knowledge:reap-draft-sessions` was built with a fourteen-day
 * abandonment window, documented as running on that cycle, and then never registered in
 * routes/console.php — so the cycle existed only on paper. Nothing failed: the command worked, its
 * tests passed, and abandoned drafting sessions simply accumulated forever, holding whatever material
 * users had pasted into them. That is the worst shape a bug can take — no error, no symptom, and the
 * thing not happening is invisible precisely because the rows it should have deleted are invisible too.
 *
 * A per-command assertion would have caught that one and missed the next one, so the pin is derived:
 * it enumerates the commands the APPLICATION registers (framework commands like `queue:prune-failed`
 * are the scheduler's own business, not ours), keeps the ones whose names say they are recurring
 * maintenance, and requires each to be scheduled. A new reaper is covered the moment it is named like
 * one.
 *
 * DELIBERATELY NOT COVERED: `knowledge:purge-subject`. It is operator-invoked, irreversible, and starts
 * with a human reading a dry run — scheduling it would be a bug of the opposite kind. It is excluded by
 * naming rather than by an exception list, which is why the patterns below are `reap`/`sweep`/`prune`
 * and not `purge`.
 */
class ScheduledMaintenanceCommandsTest extends TestCase
{
    /** What makes a command RECURRING MAINTENANCE rather than an operator's tool. */
    private const MAINTENANCE = ['reap', 'sweep', 'prune'];

    public function test_every_maintenance_command_is_scheduled(): void
    {
        $scheduled = $this->scheduledCommandNames();

        $this->assertNotEmpty($scheduled, 'the scheduler must have entries — otherwise this pin is vacuous');

        $unscheduled = array_values(array_filter(
            $this->maintenanceCommandNames(),
            static fn (string $name): bool => !in_array($name, $scheduled, true),
        ));

        $this->assertSame(
            [],
            $unscheduled,
            'these maintenance commands exist but never run: register them in routes/console.php',
        );
    }

    /** The specific one that was missing, named so a regression says what broke rather than how. */
    public function test_the_drafting_session_reaper_is_scheduled(): void
    {
        $this->assertContains('knowledge:reap-draft-sessions', $this->scheduledCommandNames());
    }

    /**
     * The application's own recurring-maintenance commands.
     *
     * Filtered to classes under `App\Modules` so the framework's own prunables (`queue:prune-failed`,
     * `sanctum:prune-expired`, `model:prune`) are not conscripted into a promise this application never
     * made about them.
     *
     * @return array<int, string>
     */
    private function maintenanceCommandNames(): array
    {
        $names = [];

        foreach (Artisan::all() as $name => $command) {
            if (!str_starts_with($command::class, 'App\\Modules\\')) {
                continue;
            }

            foreach (self::MAINTENANCE as $marker) {
                if (str_contains($name, $marker)) {
                    $names[] = $name;

                    break;
                }
            }
        }

        sort($names);

        return $names;
    }

    /**
     * The command NAMES the scheduler will run.
     *
     * A scheduled event's `command` is a whole shell line ("'php' 'artisan' foo:bar --opt"), so the
     * name is recovered by matching against the known command list rather than parsed out of it —
     * parsing would be a second thing to keep right, and it is the name we are asserting on anyway.
     *
     * @return array<int, string>
     */
    private function scheduledCommandNames(): array
    {
        $known = array_keys(Artisan::all());
        $names = [];

        foreach (app(Schedule::class)->events() as $event) {
            foreach ($known as $name) {
                if (str_contains((string) $event->command, "artisan' " . $name)
                    || str_contains((string) $event->command, 'artisan " . $name')
                    || str_contains((string) $event->command, ' ' . $name . ' ')
                    || str_ends_with((string) $event->command, ' ' . $name)) {
                    $names[] = $name;

                    break;
                }
            }
        }

        return array_values(array_unique($names));
    }
}
