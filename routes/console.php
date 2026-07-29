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

// Stale-edit reaper for async Disk AI image edits: fail edits stranded in queued/processing by a
// dead worker (a SIGKILL/OOM bypasses the job's failed() hook) and prune old terminal rows. Every
// five minutes matches the other reapers; harmless under a sync queue where edits can't strand.
Schedule::command('disk:reap-stale-ai-edits')->everyFiveMinutes()->withoutOverlapping();

// Draft reaper for per-user Disk file-edit autosaves: prune drafts (row + storage dir) past their
// 24h retention window, so an abandoned autosave never lingers with its base blobs. Every ten
// minutes — the retention window is measured in hours, so this only has to run often enough to keep
// nothing piling up.
Schedule::command('disk:reap-stale-drafts')->everyTenMinutes()->withoutOverlapping();

// Lifecycle reaper for generation SESSIONS: recover sessions stranded in `generating` by a dead worker
// (a SIGKILL/OOM bypasses the job's failed() hook), trash non-archived idle sessions, and purge old
// trashed ones (force-delete + produced-image blob GC). Every five minutes matches the other reapers so
// a stale run recovers quickly; the day/week/month retention windows make the trash/purge passes cheap
// no-ops most of the time. Archived sessions are exempt. Harmless under a sync queue.
Schedule::command('generator:reap-sessions')->everyFiveMinutes()->withoutOverlapping();
