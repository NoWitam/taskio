<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Stale-claim reaper: recover bot runs stranded in `running` by a dead worker. Guards the
// async queue (a SIGKILL/OOM bypasses the job's failed() hook). Requires `schedule:run`
// on cron; harmless under a sync queue where runs can't strand.
Schedule::command('bots:reap-stale-runs')->everyFiveMinutes()->withoutOverlapping();

// Stale-claim reaper for WORKFLOW runs: recover runs stranded in `running` by a dead
// worker (same guard as the bot reaper — a SIGKILL/OOM bypasses the job's failed() hook).
Schedule::command('workflows:reap-stale-runs')->everyFiveMinutes()->withoutOverlapping();

// Schedule forward-sweep: fire schedule-triggered workflows whose next_due_at has arrived.
// The ONLY path that starts a schedule run. withoutOverlapping is the first concurrency
// guard; the per-workflow compare-and-swap claim in the sweep is the second (fires once even
// if two sweeps overlap). Requires `schedule:run` on cron.
Schedule::command('workflows:run-scheduled')->everyMinute()->withoutOverlapping();

// Disk housekeeping: delete uploads that were never attached or placed (an abandoned dropzone
// leaves a row + its bytes behind). Hourly is plenty — the retention window is measured in
// days, so this only has to run often enough that nothing piles up.
Schedule::command('disk:prune-temp-files')->hourly()->withoutOverlapping();
